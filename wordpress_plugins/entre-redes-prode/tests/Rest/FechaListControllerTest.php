<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Predictions\PredictionRepository;
use EntreRedes\Prode\Rest\FechaListController;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for:
 *   GET /prode/fechas        — FechaListController::listFechas()
 *   GET /prode/fecha/{id}    — FechaListController::getFechaById()
 *
 * Mirrors FechaControllerTest conventions:
 *   - SQLite shim for FechaRepository.
 *   - Stub FechaResolver that returns canned team-name items.
 *   - LockComputer is real (deriveState is deterministic from seeded locked_at).
 *   - PRODE_TENANT_ID = 'test_tenant' (defined in bootstrap.php).
 */
class FechaListControllerTest extends TestCase {

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
     * Build a stub FechaResolver whose enrichMatches merges the given teamMap.
     *
     * @param array<int, array{home_team: string, away_team: string, liga?: string, escudo_local?: string|null, escudo_visitante?: string|null}> $teamMap
     */
    private function makeResolver( array $teamMap = [] ): FechaResolver {
        return new FechaResolver( static function () use ( $teamMap ): array {
            $items = [];
            foreach ( $teamMap as $matchId => $names ) {
                $item = [
                    'id'               => $matchId,
                    'fecha'            => '2026-05-30',
                    'hora'             => '13:45',
                    'equipo_local'     => $names['home_team'],
                    'equipo_visitante' => $names['away_team'],
                    'goles_local'      => null,
                    'goles_visitante'  => null,
                ];
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
    }

    private function makeController(
        array $teamMap = [],
        ?PredictionRepository $predRepo = null
    ): FechaListController {
        return new FechaListController(
            $this->repo,
            $this->makeResolver( $teamMap ),
            new LockComputer(),
            $this->settings,
            null,
            $predRepo
        );
    }

    /**
     * Seed a basic open fecha with two matches. Returns fecha_id.
     */
    private function seedOpenFecha(
        int $seasonId = 359,
        string $lockedAt = '2099-12-31 23:59:00',
        array $matchIds = [ 10, 11 ]
    ): int {
        $matches = array_map( static fn( int $id ): array => [
            'match_id' => $id,
            'kickoff'  => '2026-05-30 13:45',
            'home_team' => 'H',
            'away_team' => 'A',
        ], $matchIds );

        return $this->repo->upsertFecha( 'test_tenant', $seasonId, $lockedAt, $matches );
    }

    // -------------------------------------------------------------------------
    // GET /prode/fechas — listFechas
    // -------------------------------------------------------------------------

    public function test_list_fechas_returns_empty_array_when_no_fechas(): void {
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $response = $controller->listFechas( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertArrayHasKey( 'fechas', $body );
        $this->assertSame( [], $body['fechas'] );
    }

    public function test_list_fechas_defaults_to_active_fecha_season(): void {
        // Seed fechas in season 359 (the active/settings season).
        $this->seedOpenFecha( 359 );

        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        // No season_id param → should use active fecha's season (359).

        $response = $controller->listFechas( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $body['fechas'] );
        $this->assertSame( 359, $body['fechas'][0]['season_id'] );
    }

    public function test_list_fechas_with_explicit_season_id(): void {
        // Seed two seasons.
        $this->seedOpenFecha( 359 );
        $this->seedOpenFecha( 400 );

        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'season_id', 400 );

        $response = $controller->listFechas( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $body['fechas'] );
        $this->assertSame( 400, $body['fechas'][0]['season_id'] );
    }

    public function test_list_fechas_fallback_to_max_season_when_no_active_fecha(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // No open/locked fecha — only an evaluated one in season 500, and one in 300.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 300,
            'locked_at'  => '2020-01-01 00:00:00',
            'state'      => 'evaluated',
            'created_at' => '2020-01-01 00:00:00',
        ] );
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 500,
            'locked_at'  => '2021-01-01 00:00:00',
            'state'      => 'evaluated',
            'created_at' => '2021-01-01 00:00:00',
        ] );

        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        // No season_id, no active fecha → must fall back to MAX(season_id) = 500.

        $response = $controller->listFechas( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $body['fechas'] );
        $this->assertSame( 500, $body['fechas'][0]['season_id'] );
    }

    public function test_list_fechas_item_has_required_shape(): void {
        $fechaId    = $this->seedOpenFecha();
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );

        $body = $controller->listFechas( $request )->get_data();
        $item = $body['fechas'][0];

        $this->assertArrayHasKey( 'fecha_id', $item );
        $this->assertArrayHasKey( 'season_id', $item );
        $this->assertArrayHasKey( 'state', $item );
        $this->assertArrayHasKey( 'locked_at', $item );
        $this->assertArrayHasKey( 'match_count', $item );

        $this->assertSame( $fechaId, $item['fecha_id'] );
        $this->assertSame( 359, $item['season_id'] );
        $this->assertSame( 2, $item['match_count'] );
    }

    public function test_list_fechas_item_does_not_contain_full_match_array(): void {
        // The list endpoint returns lightweight items — no 'matches' key.
        $this->seedOpenFecha();
        $body = $this->makeController()->listFechas( new \WP_REST_Request( 'GET', '' ) )->get_data();
        $item = $body['fechas'][0];

        $this->assertArrayNotHasKey( 'matches', $item );
        $this->assertArrayNotHasKey( 'user_predictions', $item );
    }

    public function test_list_fechas_ordered_by_locked_at_asc(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Insert two fechas for the settings season out of order.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2099-06-15 13:00:00',
            'state'      => 'open',
            'created_at' => '2026-04-01 00:00:00',
        ] );
        $idFuture = (int) $wpdb->insert_id;

        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'  => 'test_tenant',
            'season_id'  => 359,
            'locked_at'  => '2026-05-01 13:00:00',
            'state'      => 'open',
            'created_at' => '2026-04-15 00:00:00',
        ] );
        $idPast = (int) $wpdb->insert_id;

        $body = $this->makeController()->listFechas( new \WP_REST_Request( 'GET', '' ) )->get_data();
        $ids  = array_column( $body['fechas'], 'fecha_id' );

        // locked_at ASC: May (idPast) before Jun (idFuture).
        $this->assertSame( [ $idPast, $idFuture ], $ids );
    }

    // -------------------------------------------------------------------------
    // GET /prode/fecha/{id} — getFechaById
    // -------------------------------------------------------------------------

    public function test_get_fecha_by_id_returns_404_for_missing_fecha(): void {
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', 9999 );

        $response = $controller->getFechaById( $request );

        $this->assertSame( 404, $response->get_status() );
        $this->assertArrayHasKey( 'error', $response->get_data() );
    }

    public function test_get_fecha_by_id_returns_full_contract_shape(): void {
        $fechaId    = $this->seedOpenFecha();
        $controller = $this->makeController( [
            10 => [ 'home_team' => 'Alpha FC', 'away_team' => 'Beta SC' ],
            11 => [ 'home_team' => 'Gamma CF', 'away_team' => 'Delta FC' ],
        ] );
        $request = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', $fechaId );

        $response = $controller->getFechaById( $request );
        $body     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $fechaId, $body['fecha_id'] );
        $this->assertSame( 359, $body['season_id'] );
        $this->assertArrayHasKey( 'state', $body );
        $this->assertArrayHasKey( 'locked_at', $body );
        $this->assertArrayHasKey( 'matches', $body );
        $this->assertArrayHasKey( 'user_predictions', $body );
        $this->assertCount( 2, $body['matches'] );
    }

    public function test_get_fecha_by_id_match_objects_identical_shape_to_fecha_activa(): void {
        // The match shape must mirror FechaController exactly:
        // match_id, home_team, away_team, kickoff, zona, home_escudo, away_escudo.
        $fechaId = $this->seedOpenFecha( 359, '2099-12-31 23:59:00', [ 10 ] );

        $teamMap = [
            10 => [
                'home_team'        => 'Marianista',
                'away_team'        => 'Rival',
                'liga'             => 'Zona A',
                'escudo_local'     => 'https://example.com/esc1.png',
                'escudo_visitante' => 'https://example.com/esc2.png',
            ],
        ];

        $controller = $this->makeController( $teamMap );
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', $fechaId );

        $body  = $controller->getFechaById( $request )->get_data();
        $match = $body['matches'][0];

        $this->assertArrayHasKey( 'match_id', $match );
        $this->assertArrayHasKey( 'home_team', $match );
        $this->assertArrayHasKey( 'away_team', $match );
        $this->assertArrayHasKey( 'kickoff', $match );
        $this->assertArrayHasKey( 'zona', $match );
        $this->assertArrayHasKey( 'home_escudo', $match );
        $this->assertArrayHasKey( 'away_escudo', $match );

        $this->assertSame( 'Marianista', $match['home_team'] );
        $this->assertSame( 'Zona A', $match['zona'] );
        $this->assertSame( 'https://example.com/esc1.png', $match['home_escudo'] );
        $this->assertSame( 'https://example.com/esc2.png', $match['away_escudo'] );
    }

    public function test_get_fecha_by_id_returns_user_predictions_for_authed_user(): void {
        $fechaId = $this->seedOpenFecha();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'prode_predictions', [
            'user_id'            => 1,
            'fecha_id'           => $fechaId,
            'match_id'           => 10,
            'result'             => '1',
            'score_home'         => 3,
            'score_away'         => 0,
            'created_at'         => '2026-01-01 00:00:00',
            'updated_at'         => '2026-01-01 00:00:00',
            'locked_at_snapshot' => '2099-12-31 23:59:00',
        ] );

        $predRepo   = new PredictionRepository( $wpdb );
        $controller = $this->makeController( [], $predRepo );
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', $fechaId );
        $request->set_param( '_prode_user', [ 'id' => 1, 'session_version' => 1 ] );

        $body = $controller->getFechaById( $request )->get_data();

        $this->assertCount( 1, $body['user_predictions'] );
        $this->assertSame( 10, $body['user_predictions'][0]['match_id'] );
        $this->assertSame( 3, $body['user_predictions'][0]['score_home'] );
    }

    public function test_get_fecha_by_id_returns_empty_user_predictions_for_anonymous(): void {
        $fechaId    = $this->seedOpenFecha();
        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', $fechaId );

        $body = $controller->getFechaById( $request )->get_data();

        $this->assertSame( [], $body['user_predictions'] );
    }

    public function test_get_fecha_by_id_works_for_evaluated_fecha(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Evaluated fechas are NOT returned by findActiveFecha, but getFechaById should work.
        $wpdb->insert( $p . 'prode_fechas', [
            'tenant_id'    => 'test_tenant',
            'season_id'    => 359,
            'locked_at'    => '2026-01-01 00:00:00',
            'state'        => 'evaluated',
            'created_at'   => '2025-12-01 00:00:00',
            'evaluated_at' => '2026-01-02 00:00:00',
        ] );
        $fechaId = (int) $wpdb->insert_id;

        $controller = $this->makeController();
        $request    = new \WP_REST_Request( 'GET', '' );
        $request->set_param( 'id', $fechaId );

        $response = $controller->getFechaById( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'evaluated', $response->get_data()['state'] );
    }

    // -------------------------------------------------------------------------
    // Regression guard: fecha-activa output unchanged after G6-b extraction
    // -------------------------------------------------------------------------

    public function test_fecha_activa_shape_unchanged_regression(): void {
        // Verify FechaController::getActiveFecha still returns the original shape
        // even after the shared shapeMatch() helper was introduced in G6-b.
        $fechaId = $this->seedOpenFecha( 359, '2099-12-31 23:59:00', [ 10, 11 ] );

        $resolver   = $this->makeResolver( [
            10 => [ 'home_team' => 'Alpha', 'away_team' => 'Beta', 'liga' => 'Zona A' ],
            11 => [ 'home_team' => 'Gamma', 'away_team' => 'Delta' ],
        ] );
        $controller = new \EntreRedes\Prode\Rest\FechaController(
            $this->repo,
            $resolver,
            new LockComputer(),
            $this->settings,
            null,
            null
        );
        $request = new \WP_REST_Request( 'GET', '' );
        $body    = $controller->getActiveFecha( $request )->get_data();

        $this->assertSame( $fechaId, $body['fecha_id'] );
        $this->assertSame( 359, $body['season_id'] );
        $this->assertArrayHasKey( 'state', $body );
        $this->assertArrayHasKey( 'locked_at', $body );
        $this->assertArrayHasKey( 'matches', $body );
        $this->assertArrayHasKey( 'user_predictions', $body );
        $this->assertCount( 2, $body['matches'] );

        // Check match shape keys.
        $match = $body['matches'][0];
        $this->assertArrayHasKey( 'match_id', $match );
        $this->assertArrayHasKey( 'home_team', $match );
        $this->assertArrayHasKey( 'away_team', $match );
        $this->assertArrayHasKey( 'kickoff', $match );
        $this->assertArrayHasKey( 'zona', $match );
        $this->assertArrayHasKey( 'home_escudo', $match );
        $this->assertArrayHasKey( 'away_escudo', $match );
    }
}
