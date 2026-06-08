<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\FechaController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for GET /prode/fecha-activa (FechaController::getActiveFecha).
 *
 * Uses the SQLite shim for FechaRepository and stub FechaResolver enrichMatches.
 * LockComputer is injected with a controlled "now" string via the non-static
 * deriveState call — the controller passes current_time('mysql') as now; we
 * manipulate the seeded locked_at to control open vs locked state.
 *
 * PRODE_TENANT_ID is 'test_tenant' (defined in bootstrap.php).
 */
class FechaControllerTest extends TestCase {

    private FechaRepository $repo;
    private Settings        $settings;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo     = new FechaRepository( $wpdb );
        $this->settings = new Settings( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up(); // restore seeds
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a FechaController with a stub enrichMatches resolver.
     *
     * @param array<int, array{home_team: string, away_team: string, zona?: string, home_escudo?: string|null, away_escudo?: string|null}> $enrichedTeamMap
     *        Team-name (and optional enrichment) map — keyed by match_id.
     * @param PredictionRepository|null $predRepo  Optional prediction repo for G2 tests.
     */
    private function makeController( array $enrichedTeamMap = [], ?PredictionRepository $predRepo = null ): FechaController {
        $lockComputer = new LockComputer();

        // Stub resolver: enrichMatches merges $enrichedTeamMap by match_id.
        $resolver = new FechaResolver( static function () use ( $enrichedTeamMap ): array {
            // Build a fake items array so enrichMatches() can build its team map.
            $items = [];
            foreach ( $enrichedTeamMap as $matchId => $names ) {
                $item = [
                    'id'                => $matchId,
                    'fecha'             => '2026-05-30',
                    'hora'              => '13:45',
                    'equipo_local'      => $names['home_team'],
                    'equipo_visitante'  => $names['away_team'],
                    'goles_local'       => null,
                    'goles_visitante'   => null,
                ];
                // Forward G6-a fields when present in the map entry.
                if ( array_key_exists( 'liga', $names ) ) {
                    $item['liga'] = $names['liga'];
                }
                if ( array_key_exists( 'escudo_local', $names ) ) {
                    $item['escudo_local'] = $names['escudo_local'];
                }
                if ( array_key_exists( 'escudo_visitante', $names ) ) {
                    $item['escudo_visitante'] = $names['escudo_visitante'];
                }
                $items[] = $item;
            }
            return $items;
        } );

        return new FechaController(
            $this->repo,
            $resolver,
            $lockComputer,
            $this->settings,
            null,
            $predRepo
        );
    }

    /**
     * Seed a fecha + matches and return the fecha_id.
     * locked_at is far in the future so state defaults to 'open'.
     */
    private function seedOpenFecha( string $lockedAt = '2099-12-31 23:59:00' ): int {
        return $this->repo->upsertFecha(
            'test_tenant',
            359,
            $lockedAt,
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
                [ 'match_id' => 11, 'kickoff' => '2026-05-30 15:10', 'home_team' => 'C', 'away_team' => 'D' ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_returns_404_when_no_active_fecha(): void {
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $response = $controller->getActiveFecha( $request );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'no_active_fecha', $response->get_data()['error'] );
    }

    public function test_returns_200_with_full_contract_shape(): void {
        $enrichedTeamMap = [
            10 => [ 'home_team' => 'Team Alpha', 'away_team' => 'Team Beta' ],
            11 => [ 'home_team' => 'Team Gamma', 'away_team' => 'Team Delta' ],
        ];

        $fechaId    = $this->seedOpenFecha();
        $controller = $this->makeController( $enrichedTeamMap );
        $request    = new \WP_REST_Request( 'GET', '' );

        $response = $controller->getActiveFecha( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $fechaId, $body['fecha_id'] );
        $this->assertSame( 359, $body['season_id'] );
        $this->assertSame( '2099-12-31 23:59:00', $body['locked_at'] );
        $this->assertArrayHasKey( 'state', $body );
        $this->assertIsArray( $body['matches'] );
        $this->assertCount( 2, $body['matches'] );
        $this->assertSame( [], $body['user_predictions'] );
    }

    public function test_state_is_open_before_locked_at(): void {
        // locked_at far in future → state must be 'open'.
        $this->seedOpenFecha( '2099-12-31 23:59:00' );
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( 'open', $body['state'] );
    }

    public function test_state_is_locked_when_locked_at_in_past(): void {
        // locked_at in the past → state must be 'locked'.
        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2000-01-01 00:00:00', // far in the past
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
            ]
        );
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( 'locked', $body['state'] );
    }

    public function test_team_names_prefer_persisted_snapshot_over_live(): void {
        // v0.5.2: the persisted snapshot is authoritative. When a row carries a
        // non-empty home_team, live enrichment must NOT override it — this is what
        // keeps a played fecha (dropped from /partidos-programados) intact.
        $liveTeamMap = [
            10 => [ 'home_team' => 'Live Home Name', 'away_team' => 'Live Away Name' ],
            11 => [ 'home_team' => 'Live Home 2',    'away_team' => 'Live Away 2' ],
        ];

        $this->seedOpenFecha(); // snapshot: 10 => A/B, 11 => C/D
        $controller = $this->makeController( $liveTeamMap );
        $request    = new \WP_REST_Request( 'GET', '' );

        $body    = $controller->getActiveFecha( $request )->get_data();
        $matches = $body['matches'];

        $matchById = [];
        foreach ( $matches as $m ) {
            $matchById[ $m['match_id'] ] = $m;
        }

        $this->assertSame( 'A', $matchById[10]['home_team'] );
        $this->assertSame( 'B', $matchById[10]['away_team'] );
        $this->assertSame( 'C', $matchById[11]['home_team'] );
        $this->assertSame( 'D', $matchById[11]['away_team'] );
    }

    public function test_team_names_fall_back_to_live_when_snapshot_empty(): void {
        // Rows seeded before v0.5.2 have an empty snapshot. enrichMatches must
        // fill them from the live payload (legacy behavior for old fechas).
        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => '', 'away_team' => '' ],
            ]
        );
        $controller = $this->makeController( [
            10 => [ 'home_team' => 'Live Home Name', 'away_team' => 'Live Away Name' ],
        ] );
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( 'Live Home Name', $body['matches'][0]['home_team'] );
        $this->assertSame( 'Live Away Name', $body['matches'][0]['away_team'] );
    }

    public function test_unknown_match_id_falls_back_to_empty_strings(): void {
        // Seed a fecha with match_id=99, but enriched map has no entry for 99.
        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            [
                [ 'match_id' => 99, 'kickoff' => '2026-05-30 13:45', 'home_team' => '', 'away_team' => '' ],
            ]
        );
        // Empty team map → no entries → fallback to empty strings.
        $controller = $this->makeController( [] );
        $request    = new \WP_REST_Request( 'GET', '' );

        $body    = $controller->getActiveFecha( $request )->get_data();
        $matches = $body['matches'];

        $this->assertCount( 1, $matches );
        $this->assertSame( '', $matches[0]['home_team'] );
        $this->assertSame( '', $matches[0]['away_team'] );
    }

    public function test_user_predictions_always_empty_in_g0(): void {
        $this->seedOpenFecha();
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( [], $body['user_predictions'] );
    }

    public function test_matches_have_required_keys(): void {
        $this->seedOpenFecha();
        $controller = $this->makeController( [
            10 => [ 'home_team' => 'H', 'away_team' => 'A' ],
            11 => [ 'home_team' => 'H2', 'away_team' => 'A2' ],
        ] );
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        foreach ( $body['matches'] as $match ) {
            $this->assertArrayHasKey( 'match_id', $match );
            $this->assertArrayHasKey( 'home_team', $match );
            $this->assertArrayHasKey( 'away_team', $match );
            $this->assertArrayHasKey( 'kickoff', $match );
        }
    }

    // -------------------------------------------------------------------------
    // A2-4 RED — user_predictions population when PredictionRepository injected
    // -------------------------------------------------------------------------

    public function test_anonymous_get_returns_empty_user_predictions(): void {
        // No _prode_user on request → user_predictions must be [].
        $this->seedOpenFecha();
        global $wpdb;
        $predRepo   = new PredictionRepository( $wpdb );
        $controller = $this->makeController( [], $predRepo );
        $request    = new \WP_REST_Request( 'GET', '' );
        // No _prode_user set on request.

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( [], $body['user_predictions'] );
    }

    public function test_authenticated_get_populates_user_predictions(): void {
        // Seed a fecha and a prediction row for user 1, then assert it comes back.
        $fechaId = $this->seedOpenFecha();

        global $wpdb;
        // Insert a prediction row directly for user_id=1, match_id=10.
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => 1,
                'fecha_id'           => $fechaId,
                'match_id'           => 10,
                'result'             => '1',
                'score_home'         => 2,
                'score_away'         => 1,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
                'locked_at_snapshot' => '2099-12-31 23:59:00',
            ]
        );

        $predRepo   = new PredictionRepository( $wpdb );
        $controller = $this->makeController( [], $predRepo );
        $request    = new \WP_REST_Request( 'GET', '' );
        // Attach user as requireAuth would.
        $request->set_param( '_prode_user', [ 'id' => 1, 'session_version' => 1 ] );

        $body        = $controller->getActiveFecha( $request )->get_data();
        $predictions = $body['user_predictions'];

        $this->assertCount( 1, $predictions );
        $this->assertSame( 10, (int) $predictions[0]['match_id'] );
        $this->assertSame( 2, (int) $predictions[0]['score_home'] );
        $this->assertSame( 1, (int) $predictions[0]['score_away'] );
    }

    public function test_anonymous_path_unchanged_when_no_pred_repo_injected(): void {
        // Regression guard: when no PredictionRepository is injected (null),
        // the anonymous path continues to return user_predictions = [].
        $this->seedOpenFecha();
        // makeController without predRepo (null by default).
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( [], $body['user_predictions'] );
    }

    // -------------------------------------------------------------------------
    // G6-a: getActiveFecha — zona + escudos in match objects
    // -------------------------------------------------------------------------

    public function test_match_objects_contain_zona_and_escudos(): void {
        // v0.5.2: zona + escudos are snapshotted at seed time and surfaced verbatim.
        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            [
                [
                    'match_id'    => 10,
                    'kickoff'     => '2026-05-30 13:45',
                    'home_team'   => 'Marianista FC',
                    'away_team'   => 'Rival United',
                    'zona'        => '2026 - Apertura Zona A',
                    'home_escudo' => 'https://example.com/escudo-marianista.png',
                    'away_escudo' => 'https://example.com/escudo-rival.png',
                ],
                [
                    'match_id'    => 11,
                    'kickoff'     => '2026-05-30 15:10',
                    'home_team'   => 'Eagles SC',
                    'away_team'   => 'Lions CF',
                    'zona'        => '2026 - Apertura Zona B',
                    'home_escudo' => 'https://example.com/escudo-eagles.png',
                    'away_escudo' => 'https://example.com/escudo-lions.png',
                ],
            ]
        );
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body    = $controller->getActiveFecha( $request )->get_data();
        $matches = $body['matches'];

        $matchById = [];
        foreach ( $matches as $m ) {
            $matchById[ $m['match_id'] ] = $m;
        }

        // Match 10 — assert new fields present and correct.
        $this->assertArrayHasKey( 'zona', $matchById[10] );
        $this->assertArrayHasKey( 'home_escudo', $matchById[10] );
        $this->assertArrayHasKey( 'away_escudo', $matchById[10] );
        $this->assertSame( '2026 - Apertura Zona A', $matchById[10]['zona'] );
        $this->assertSame( 'https://example.com/escudo-marianista.png', $matchById[10]['home_escudo'] );
        $this->assertSame( 'https://example.com/escudo-rival.png', $matchById[10]['away_escudo'] );

        // Match 11
        $this->assertSame( '2026 - Apertura Zona B', $matchById[11]['zona'] );
        $this->assertSame( 'https://example.com/escudo-eagles.png', $matchById[11]['home_escudo'] );
        $this->assertSame( 'https://example.com/escudo-lions.png', $matchById[11]['away_escudo'] );
    }

    public function test_match_objects_original_keys_are_still_present(): void {
        // Additive contract: new G6-a fields must NOT remove existing fields.
        $enrichedTeamMap = [
            10 => [
                'home_team'         => 'Team Alpha',
                'away_team'         => 'Team Beta',
                'liga'              => 'Zona A',
                'escudo_local'      => 'https://example.com/a.png',
                'escudo_visitante'  => 'https://example.com/b.png',
            ],
        ];

        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
            ]
        );
        $controller = $this->makeController( $enrichedTeamMap );
        $request    = new \WP_REST_Request( 'GET', '' );

        $body  = $controller->getActiveFecha( $request )->get_data();
        $match = $body['matches'][0];

        // Original keys must still exist.
        $this->assertArrayHasKey( 'match_id', $match );
        $this->assertArrayHasKey( 'home_team', $match );
        $this->assertArrayHasKey( 'away_team', $match );
        $this->assertArrayHasKey( 'kickoff', $match );

        // New G6-a keys must also exist.
        $this->assertArrayHasKey( 'zona', $match );
        $this->assertArrayHasKey( 'home_escudo', $match );
        $this->assertArrayHasKey( 'away_escudo', $match );
    }

    public function test_match_objects_escudos_are_null_when_upstream_omits_them(): void {
        // When upstream does NOT send escudo fields, match objects should have null escudos
        // and empty-string zona — no crash.
        $enrichedTeamMap = [
            10 => [
                'home_team' => 'Team Alpha',
                'away_team' => 'Team Beta',
                // no liga / escudo_local / escudo_visitante
            ],
        ];

        $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2099-12-31 23:59:00',
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
            ]
        );
        $controller = $this->makeController( $enrichedTeamMap );
        $request    = new \WP_REST_Request( 'GET', '' );

        $body  = $controller->getActiveFecha( $request )->get_data();
        $match = $body['matches'][0];

        $this->assertSame( '', $match['zona'] );
        $this->assertNull( $match['home_escudo'] );
        $this->assertNull( $match['away_escudo'] );
    }

    // -------------------------------------------------------------------------
    // G6-c: populares gate — open → null, locked/evaluated → populated
    // -------------------------------------------------------------------------

    /**
     * Helper: insert a prediction row directly into the DB.
     */
    private function insertPrediction( int $userId, int $fechaId, int $matchId, string $result ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_predictions',
            [
                'user_id'            => $userId,
                'fecha_id'           => $fechaId,
                'match_id'           => $matchId,
                'result'             => $result,
                'score_home'         => 1,
                'score_away'         => 0,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
                'locked_at_snapshot' => '2026-01-01 00:00:00',
            ]
        );
    }

    public function test_matches_include_populares_key_with_null_when_state_open(): void {
        // State OPEN → populares must be null even if predictions exist (gate).
        global $wpdb;
        $predRepo = new PredictionRepository( $wpdb );
        $fechaId  = $this->seedOpenFecha( '2099-12-31 23:59:00' ); // future → open

        // Seed predictions for match 10 in this open fecha.
        $this->insertPrediction( 1, $fechaId, 10, '1' );
        $this->insertPrediction( 2, $fechaId, 10, 'X' );

        $controller = $this->makeController( [
            10 => [ 'home_team' => 'A', 'away_team' => 'B' ],
            11 => [ 'home_team' => 'C', 'away_team' => 'D' ],
        ], $predRepo );
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        // Every match must have populares === null when state is open.
        foreach ( $body['matches'] as $match ) {
            $this->assertArrayHasKey( 'populares', $match );
            $this->assertNull( $match['populares'] );
        }
    }

    public function test_matches_have_populares_null_when_no_pred_repo_injected(): void {
        // Regression: when predRepo is null (controller built without it),
        // populares must still appear as null — no fatal error.
        $this->seedOpenFecha( '2000-01-01 00:00:00' ); // past → locked
        $controller = $this->makeController( [
            10 => [ 'home_team' => 'A', 'away_team' => 'B' ],
            11 => [ 'home_team' => 'C', 'away_team' => 'D' ],
        ] ); // no predRepo

        $body = $this->makeController()->getActiveFecha( new \WP_REST_Request( 'GET', '' ) )->get_data();

        foreach ( $body['matches'] as $match ) {
            $this->assertArrayHasKey( 'populares', $match );
            $this->assertNull( $match['populares'] );
        }
    }

    public function test_populares_populated_when_state_locked_and_predictions_exist(): void {
        // State LOCKED (locked_at in the past) → populares populated for matches with predictions.
        global $wpdb;
        $predRepo = new PredictionRepository( $wpdb );

        $fechaId = $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2000-01-01 00:00:00', // past → locked
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
                [ 'match_id' => 11, 'kickoff' => '2026-05-30 15:10', 'home_team' => 'C', 'away_team' => 'D' ],
            ]
        );

        // 2×'1', 1×'X' for match 10 → '1'=66.7%, 'X'=33.3%, '2'=0.0%.
        $this->insertPrediction( 1, $fechaId, 10, '1' );
        $this->insertPrediction( 2, $fechaId, 10, '1' );
        $this->insertPrediction( 3, $fechaId, 10, 'X' );
        // match 11: no predictions.

        $controller = $this->makeController( [
            10 => [ 'home_team' => 'A', 'away_team' => 'B' ],
            11 => [ 'home_team' => 'C', 'away_team' => 'D' ],
        ], $predRepo );
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();
        $this->assertSame( 'locked', $body['state'] );

        $matchById = [];
        foreach ( $body['matches'] as $m ) {
            $matchById[ $m['match_id'] ] = $m;
        }

        // Match 10: populated.
        $this->assertNotNull( $matchById[10]['populares'] );
        $this->assertSame( 66.7, $matchById[10]['populares']['1'] );
        $this->assertSame( 33.3, $matchById[10]['populares']['X'] );
        $this->assertSame( 0.0, $matchById[10]['populares']['2'] );

        // Match 11: no predictions → null.
        $this->assertNull( $matchById[11]['populares'] );
    }

    public function test_anonymous_gets_populares_when_locked_it_is_aggregate_not_user_specific(): void {
        // Anonymous users ALSO get populares when locked — it's aggregate data.
        global $wpdb;
        $predRepo = new PredictionRepository( $wpdb );

        $fechaId = $this->repo->upsertFecha(
            'test_tenant',
            359,
            '2000-01-01 00:00:00', // past → locked
            [
                [ 'match_id' => 10, 'kickoff' => '2026-05-30 13:45', 'home_team' => 'A', 'away_team' => 'B' ],
            ]
        );
        $this->insertPrediction( 1, $fechaId, 10, '2' );

        $controller = $this->makeController( [
            10 => [ 'home_team' => 'A', 'away_team' => 'B' ],
        ], $predRepo );
        // Anonymous — no _prode_user set.
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( [], $body['user_predictions'] );
        $this->assertNotNull( $body['matches'][0]['populares'] );
        $this->assertSame( 100.0, $body['matches'][0]['populares']['2'] );
    }

    public function test_regression_fecha_activa_shape_now_includes_populares_null_key(): void {
        // G6-c CONTRACT CHANGE: populares is always present (null when open).
        // This regression test replaces the prior implicit absence — it now MUST be null.
        $this->seedOpenFecha();
        $controller = $this->makeController( [
            10 => [ 'home_team' => 'Alpha', 'away_team' => 'Beta' ],
            11 => [ 'home_team' => 'Gamma', 'away_team' => 'Delta' ],
        ] );
        $request = new \WP_REST_Request( 'GET', '' );

        $body = $controller->getActiveFecha( $request )->get_data();

        foreach ( $body['matches'] as $match ) {
            $this->assertArrayHasKey( 'populares', $match );
            $this->assertNull( $match['populares'] );
        }
    }
}
