<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Rest\RankingController;
use EntreRedes\Prode\Scoring\RankingComputer;
use EntreRedes\Prode\Scoring\RankingRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for GET /prode/ranking (RankingController::getRanking).
 *
 * Mirrors PredictionControllerTest request-helper style:
 *   - makeRequest()       → anonymous (no _prode_user param).
 *   - makeAuthedRequest() → sets _prode_user['id'] to simulate a logged-in user.
 *
 * is_me is detected from the _prode_user['id'] request param set by optionalAuth
 * middleware in production; here we inject it directly (same approach as FechaController tests).
 *
 * Tenant: PRODE_TENANT_ID = 'test_tenant' (set by bootstrap.php).
 * Default season: prode_settings.prode_season_id = 359 (seeded by InitialSchema).
 *
 * Spec coverage: EP-01..11, CC-01..02.
 */
class RankingControllerTest extends TestCase {

    private RankingController  $controller;
    private RankingRepository  $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $this->repo       = new RankingRepository( $wpdb );
        $computer         = new RankingComputer();
        $settings         = new Settings( $wpdb );
        $this->controller = new RankingController( $this->repo, $computer, $settings );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );
    }

    // -------------------------------------------------------------------------
    // Seeding helpers
    // -------------------------------------------------------------------------

    private function seedUser( int $userId, string $displayName = '' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'           => $userId,
                'tenant_id'    => 'test_tenant',
                'dni'          => "dni_{$userId}",
                'provider'     => 'google',
                'provider_id'  => "gid_{$userId}",
                'display_name' => $displayName !== '' ? $displayName : "User {$userId}",
                'created_at'   => '2026-01-01 00:00:00',
            ]
        );
    }

    /**
     * Seed a prode_fechas row. Returns the fecha_id.
     */
    private function seedFecha( string $state, int $seasonId = 359 ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'    => 'test_tenant',
                'season_id'    => $seasonId,
                'locked_at'    => '2026-05-30 10:00:00',
                'state'        => $state,
                'created_at'   => '2026-05-28 00:00:00',
                'evaluated_at' => $state === 'evaluated' ? '2026-05-31 00:00:00' : null,
            ]
        );
        return (int) $wpdb->insert_id;
    }

    /**
     * Seed a prode_scores row.
     */
    private function seedScore( int $fechaId, int $userId, int $matchId, int $points, string $method = 'result_only' ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => $points,
                'evaluation_method' => $method,
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Request helpers
    // -------------------------------------------------------------------------

    private function makeRequest( array $params = [] ): \WP_REST_Request {
        $req = new \WP_REST_Request( 'GET', '' );
        foreach ( $params as $key => $val ) {
            $req->set_param( $key, $val );
        }
        return $req;
    }

    private function makeAuthedRequest( int $userId, array $params = [] ): \WP_REST_Request {
        $req = $this->makeRequest( $params );
        $req->set_param( '_prode_user', [ 'id' => $userId ] );
        return $req;
    }

    // -------------------------------------------------------------------------
    // EP-01 — Season default view, 200, ranked rows
    // -------------------------------------------------------------------------

    public function test_season_view_returns_200_with_ranked_rows(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedUser( 3 );

        // u1=10pts/2ec, u2=8pts/1ec, u3=8pts/1ec (tie on pts+ec)
        $this->seedScore( $fechaId, 1, 101, 3, 'exact_score' );
        $this->seedScore( $fechaId, 1, 102, 3, 'exact_score' );
        $this->seedScore( $fechaId, 1, 103, 1, 'result_only' );
        // u1 total = 7 pts, ec=2... let me use cleaner values
        // Actually: seed direct points by 1 score row each
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );

        $this->seedScore( $fechaId, 1, 101, 10, 'exact_score' ); // 10pts, 1 exact
        $this->seedScore( $fechaId, 1, 102, 0, 'exact_score' );  // +0pts, +1 exact → total 10pts,ec=2
        $this->seedScore( $fechaId, 2, 103, 8, 'exact_score' );  // 8pts, ec=1
        $this->seedScore( $fechaId, 3, 104, 8, 'exact_score' );  // 8pts, ec=1 → tied with u2

        $response = $this->controller->getRanking( $this->makeRequest() );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'items', $data );
        $this->assertArrayHasKey( 'total', $data );
        $this->assertArrayHasKey( 'page', $data );
        $this->assertArrayHasKey( 'per_page', $data );
        $this->assertSame( 3, $data['total'] );
        $this->assertSame( 1, $data['page'] );
        $this->assertSame( 50, $data['per_page'] );

        // All is_me=false for anonymous.
        foreach ( $data['items'] as $item ) {
            $this->assertFalse( $item['is_me'] );
        }
    }

    // -------------------------------------------------------------------------
    // EP-02 — Per-fecha view via fecha_id
    // -------------------------------------------------------------------------

    public function test_per_fecha_view_returns_cached_rows(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1 );
        $this->seedUser( 2 );

        $this->seedScore( $fechaId, 1, 101, 3, 'exact_score' );
        $this->seedScore( $fechaId, 2, 102, 1, 'result_only' );

        // Populate cache via cron-like upsert.
        $now    = '2026-06-01 00:00:00';
        $ranked = [
            [ 'user_id' => 1, 'total_points' => 3, 'rank' => 1, 'exact_count' => 1 ],
            [ 'user_id' => 2, 'total_points' => 1, 'rank' => 2, 'exact_count' => 0 ],
        ];
        $this->repo->upsertFechaCache( $fechaId, $ranked, $now );

        $response = $this->controller->getRanking( $this->makeRequest( [ 'fecha_id' => $fechaId ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 2, $data['total'] );
        $this->assertCount( 2, $data['items'] );

        // exact_count is present.
        foreach ( $data['items'] as $item ) {
            $this->assertArrayHasKey( 'exact_count', $item );
        }
    }

    // -------------------------------------------------------------------------
    // EP-03 — Anonymous returns 200, not 401
    // -------------------------------------------------------------------------

    public function test_anonymous_returns_200_not_401(): void {
        $this->seedFecha( 'evaluated', 359 );

        $response = $this->controller->getRanking( $this->makeRequest() );

        $this->assertSame( 200, $response->get_status() );
    }

    // -------------------------------------------------------------------------
    // EP-04 — Authenticated caller flagged is_me
    // -------------------------------------------------------------------------

    public function test_authed_caller_has_is_me_true_on_own_row(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedUser( 3 );

        $this->seedScore( $fechaId, 1, 101, 5, 'result_only' );
        $this->seedScore( $fechaId, 2, 102, 3, 'result_only' );
        $this->seedScore( $fechaId, 3, 103, 1, 'result_only' );

        $response = $this->controller->getRanking( $this->makeAuthedRequest( 3 ) );
        $data     = $response->get_data();

        $byUser = [];
        foreach ( $data['items'] as $item ) {
            $byUser[ (int) $item['user_id'] ] = $item;
        }

        $this->assertTrue( $byUser[3]['is_me'] );
        $this->assertFalse( $byUser[1]['is_me'] );
        $this->assertFalse( $byUser[2]['is_me'] );
    }

    // -------------------------------------------------------------------------
    // EP-05 — Pagination: page 1 vs page 2, ranks absolute
    // -------------------------------------------------------------------------

    public function test_pagination_returns_correct_slice_and_absolute_ranks(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        for ( $i = 1; $i <= 5; $i++ ) {
            $this->seedUser( $i );
            $this->seedScore( $fechaId, $i, 100 + $i, 6 - $i, 'result_only' ); // 5,4,3,2,1 pts
        }

        $page1 = $this->controller->getRanking( $this->makeRequest( [ 'page' => 1, 'per_page' => 2 ] ) );
        $page2 = $this->controller->getRanking( $this->makeRequest( [ 'page' => 2, 'per_page' => 2 ] ) );

        $data1 = $page1->get_data();
        $data2 = $page2->get_data();

        $this->assertSame( 200, $page1->get_status() );
        $this->assertSame( 200, $page2->get_status() );

        $this->assertSame( 5, $data1['total'] );
        $this->assertSame( 5, $data2['total'] );
        $this->assertCount( 2, $data1['items'] );
        $this->assertCount( 2, $data2['items'] );

        // Absolute ranks: page 1 has ranks 1,2; page 2 has ranks 3,4.
        $this->assertSame( 1, $data1['items'][0]['rank'] );
        $this->assertSame( 2, $data1['items'][1]['rank'] );
        $this->assertSame( 3, $data2['items'][0]['rank'] );
        $this->assertSame( 4, $data2['items'][1]['rank'] );
    }

    // -------------------------------------------------------------------------
    // EP-06 — Empty season returns 200 empty
    // -------------------------------------------------------------------------

    public function test_empty_season_returns_200_empty(): void {
        $response = $this->controller->getRanking( $this->makeRequest( [ 'temporada' => 99 ] ) );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( [], $data['items'] );
        $this->assertSame( 0, $data['total'] );
    }

    // -------------------------------------------------------------------------
    // EP-07 — Invalid page (non-numeric) → 400
    // -------------------------------------------------------------------------

    public function test_non_numeric_page_returns_400(): void {
        $response = $this->controller->getRanking( $this->makeRequest( [ 'page' => 'abc' ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    // -------------------------------------------------------------------------
    // EP-08 — per_page over max → 400 (MAX_PER_PAGE = 100)
    // -------------------------------------------------------------------------

    public function test_per_page_over_100_returns_400(): void {
        $response = $this->controller->getRanking( $this->makeRequest( [ 'per_page' => 101 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    // -------------------------------------------------------------------------
    // EP-09 — page < 1 → 400
    // -------------------------------------------------------------------------

    public function test_page_less_than_1_returns_400(): void {
        $response = $this->controller->getRanking( $this->makeRequest( [ 'page' => 0 ] ) );
        $this->assertSame( 400, $response->get_status() );
    }

    // -------------------------------------------------------------------------
    // EP-10 — display_name present in every row (including empty string)
    // -------------------------------------------------------------------------

    public function test_display_name_present_in_every_row(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1, 'Alice' );
        $this->seedUser( 2, '' ); // empty display_name

        $this->seedScore( $fechaId, 1, 101, 5, 'result_only' );
        $this->seedScore( $fechaId, 2, 102, 3, 'result_only' );

        $response = $this->controller->getRanking( $this->makeRequest() );
        $data     = $response->get_data();

        $byUser = [];
        foreach ( $data['items'] as $item ) {
            $byUser[ (int) $item['user_id'] ] = $item;
        }

        $this->assertArrayHasKey( 'display_name', $byUser[1] );
        $this->assertSame( 'Alice', $byUser[1]['display_name'] );
        $this->assertArrayHasKey( 'display_name', $byUser[2] );
        $this->assertIsString( $byUser[2]['display_name'] ); // must be string (not null, not absent)
    }

    // -------------------------------------------------------------------------
    // EP-11 — Tiebreak order observable in season response
    // -------------------------------------------------------------------------

    public function test_tiebreak_higher_exact_count_ranks_higher(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1 );
        $this->seedUser( 2 );

        // u1: 8pts, 2 exact_score matches. u2: 8pts, 1 exact_score match.
        $this->seedScore( $fechaId, 1, 101, 3, 'exact_score' );
        $this->seedScore( $fechaId, 1, 102, 3, 'exact_score' );
        $this->seedScore( $fechaId, 1, 103, 2, 'result_only' );
        $this->seedScore( $fechaId, 2, 104, 3, 'exact_score' );
        $this->seedScore( $fechaId, 2, 105, 5, 'result_only' );

        $response = $this->controller->getRanking( $this->makeRequest() );
        $data     = $response->get_data();

        $byUser = [];
        foreach ( $data['items'] as $item ) {
            $byUser[ (int) $item['user_id'] ] = $item;
        }

        // Both have 8pts; u1 has ec=2, u2 has ec=1 → u1 ranks higher.
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 2, $byUser[2]['rank'] );
    }

    // -------------------------------------------------------------------------
    // CC-01 — Stored cache rank equals freshly computed rank for tie scenario
    // -------------------------------------------------------------------------

    public function test_stored_cache_rank_matches_freshly_computed_rank(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1 );
        $this->seedUser( 2 );
        $this->seedUser( 3 );

        // Seed scores: u1 & u2 tie, u3 lower.
        $this->seedScore( $fechaId, 1, 101, 8, 'exact_score' );
        $this->seedScore( $fechaId, 2, 102, 8, 'exact_score' );
        $this->seedScore( $fechaId, 3, 103, 5, 'result_only' );

        // Write cache via RankingCron-like path (simulate what cron does).
        $rows   = $this->repo->aggregateByFecha( $fechaId );
        $computer = new RankingComputer();
        $ranked = $computer->assignRanks( $rows );
        $this->repo->upsertFechaCache( $fechaId, $ranked, '2026-06-01 00:00:00' );

        // Per-fecha response (reads cache).
        $response = $this->controller->getRanking( $this->makeRequest( [ 'fecha_id' => $fechaId ] ) );
        $data     = $response->get_data();

        $byUser = [];
        foreach ( $data['items'] as $item ) {
            $byUser[ (int) $item['user_id'] ] = $item;
        }

        // Freshly computed ranks: u1→1, u2→1, u3→3.
        $this->assertSame( 1, $byUser[1]['rank'] );
        $this->assertSame( 1, $byUser[2]['rank'] );
        $this->assertSame( 3, $byUser[3]['rank'] );
    }

    // -------------------------------------------------------------------------
    // CC-02 — Season view tiebreak matches per-fecha tiebreak (user_id ASC)
    // -------------------------------------------------------------------------

    public function test_season_and_per_fecha_both_apply_user_id_asc_tiebreak(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 5 );
        $this->seedUser( 3 );

        // Same pts, same exact_count → user_id ASC → u3 ranks above u5.
        $this->seedScore( $fechaId, 5, 101, 5, 'exact_score' );
        $this->seedScore( $fechaId, 3, 102, 5, 'exact_score' );

        // Season view.
        $seasonResponse = $this->controller->getRanking( $this->makeRequest() );
        $seasonData     = $seasonResponse->get_data();

        $this->assertSame( 1, $seasonData['items'][0]['rank'] );
        $this->assertSame( 1, $seasonData['items'][1]['rank'] );
        // u3 (lower user_id) appears first.
        $this->assertSame( 3, (int) $seasonData['items'][0]['user_id'] );
        $this->assertSame( 5, (int) $seasonData['items'][1]['user_id'] );

        // Per-fecha cache view.
        $rows   = $this->repo->aggregateByFecha( $fechaId );
        $computer = new RankingComputer();
        $ranked = $computer->assignRanks( $rows );
        $this->repo->upsertFechaCache( $fechaId, $ranked, '2026-06-01 00:00:00' );

        $cacheResponse = $this->controller->getRanking( $this->makeRequest( [ 'fecha_id' => $fechaId ] ) );
        $cacheData     = $cacheResponse->get_data();

        // Both rank=1; u3 first (ORDER BY rank ASC, user_id ASC in findFechaCache).
        $this->assertSame( 3, (int) $cacheData['items'][0]['user_id'] );
        $this->assertSame( 5, (int) $cacheData['items'][1]['user_id'] );
    }

    // -------------------------------------------------------------------------
    // Row shape: all required fields present
    // -------------------------------------------------------------------------

    public function test_row_shape_has_all_required_fields(): void {
        $fechaId = $this->seedFecha( 'evaluated', 359 );
        $this->seedUser( 1, 'Alice' );
        $this->seedScore( $fechaId, 1, 101, 3, 'exact_score' );

        $response = $this->controller->getRanking( $this->makeRequest() );
        $data     = $response->get_data();

        $this->assertNotEmpty( $data['items'] );
        $item = $data['items'][0];

        $this->assertArrayHasKey( 'user_id', $item );
        $this->assertArrayHasKey( 'display_name', $item );
        $this->assertArrayHasKey( 'total_points', $item );
        $this->assertArrayHasKey( 'rank', $item );
        $this->assertArrayHasKey( 'exact_count', $item );
        $this->assertArrayHasKey( 'is_me', $item );

        $this->assertIsInt( $item['user_id'] );
        $this->assertIsString( $item['display_name'] );
        $this->assertIsInt( $item['total_points'] );
        $this->assertIsInt( $item['rank'] );
        $this->assertIsInt( $item['exact_count'] );
        $this->assertIsBool( $item['is_me'] );
    }

    // -------------------------------------------------------------------------
    // Non-numeric fecha_id → 400
    // -------------------------------------------------------------------------

    public function test_non_numeric_fecha_id_returns_400(): void {
        $response = $this->controller->getRanking( $this->makeRequest( [ 'fecha_id' => 'foo' ] ) );
        $this->assertSame( 400, $response->get_status() );
    }
}
