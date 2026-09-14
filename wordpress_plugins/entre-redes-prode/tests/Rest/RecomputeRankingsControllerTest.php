<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Rest;

use EntreRedes\Prode\Cron\RankingCron;
use EntreRedes\Prode\Migrations\InitialSchema;
use EntreRedes\Prode\Rest\RecomputeRankingsController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for POST /prode/recompute-rankings (RecomputeRankingsController::handleRecompute).
 *
 * Capability check is injected as a closure (ADR-G3-4) for the same reason as
 * EvaluationControllerTest: the test shim has no current_user_can() /
 * wp_set_current_user().
 *
 * RankingCron::run() is bound to 'prode_recompute_rankings_cron' manually in
 * setUp() — mirroring exactly what Plugin::boot() does in production — rather
 * than booting the whole plugin, so the handler's do_action() call actually
 * runs the cron and fires the counters hook it depends on.
 *
 * Spec coverage: ADR-G8-1.
 */
class RecomputeRankingsControllerTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        $GLOBALS['_prode_test_actions']              = [];
        $GLOBALS['_prode_test_registered_routes']    = [];
        unset( $GLOBALS['_prode_test_action_callbacks']['prode_recompute_rankings_cron'] );
        unset( $GLOBALS['_prode_test_action_registrations']['prode_recompute_rankings_cron'] );
        unset( $GLOBALS['_prode_test_action_callbacks']['prode_ranking_cron_ran'] );
        unset( $GLOBALS['_prode_test_action_registrations']['prode_ranking_cron_ran'] );

        // Mirrors Plugin::boot()'s add_action( 'prode_recompute_rankings_cron', [ Cron\RankingCron::class, 'run' ] ).
        add_action( 'prode_recompute_rankings_cron', [ RankingCron::class, 'run' ] );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_ranking_fecha_cache" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_users" );

        unset( $GLOBALS['_prode_test_action_callbacks']['prode_recompute_rankings_cron'] );
        unset( $GLOBALS['_prode_test_action_registrations']['prode_recompute_rankings_cron'] );
        unset( $GLOBALS['_prode_test_action_callbacks']['prode_ranking_cron_ran'] );
        unset( $GLOBALS['_prode_test_action_registrations']['prode_ranking_cron_ran'] );
    }

    // -------------------------------------------------------------------------
    // Helpers — DB seeding (mirrors RankingCronTest)
    // -------------------------------------------------------------------------

    private function seedUser( int $userId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'           => $userId,
                'tenant_id'    => 'test_tenant',
                'dni'          => "dni_{$userId}",
                'provider'     => 'google',
                'provider_id'  => "gid_{$userId}",
                'display_name' => "User {$userId}",
                'created_at'   => '2026-01-01 00:00:00',
            ]
        );
    }

    private function seedFecha( string $state ): int {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'    => 'test_tenant',
                'season_id'    => 359,
                'locked_at'    => '2026-05-30 10:00:00',
                'state'        => $state,
                'created_at'   => '2026-05-28 00:00:00',
                'evaluated_at' => $state === 'evaluated' ? '2026-05-31 00:00:00' : null,
            ]
        );
        return (int) $wpdb->insert_id;
    }

    private function seedScore( int $fechaId, int $userId, int $matchId, int $points = 1 ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => $points,
                'evaluation_method' => 'result_only',
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    private function seedUnscoredMatch( int $fechaId, int $userId, int $matchId ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'prode_scores',
            [
                'user_id'           => $userId,
                'fecha_id'          => $fechaId,
                'match_id'          => $matchId,
                'prediction_id'     => null,
                'points'            => 0,
                'evaluation_method' => 'no_match_score',
                'evaluated_at'      => '2026-06-01 00:00:00',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers — controller/request factory
    // -------------------------------------------------------------------------

    private function buildController( callable $capCheck ): RecomputeRankingsController {
        return new RecomputeRankingsController( $capCheck );
    }

    private function buildRequest(): \WP_REST_Request {
        return new \WP_REST_Request( 'POST', '/entre-redes/v1/prode/recompute-rankings' );
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    /** Route is registered at prode/recompute-rankings with CREATABLE. */
    public function test_route_is_registered_with_creatable_method(): void {
        $controller = $this->buildController( fn() => true );
        $controller->register_routes();

        $matches = array_filter(
            $GLOBALS['_prode_test_registered_routes'],
            static fn( array $r ) => $r['namespace'] === 'entre-redes/v1' && $r['route'] === '/prode/recompute-rankings'
        );

        $this->assertNotEmpty( $matches, 'Expected a registered route for /prode/recompute-rankings.' );
        $route = array_values( $matches )[0];
        $this->assertSame( \WP_REST_Server::CREATABLE, $route['args']['methods'] );
        $this->assertSame( [ $controller, 'handleRecompute' ], $route['args']['callback'] );
        $this->assertSame( [ $controller, 'checkPermission' ], $route['args']['permission_callback'] );
    }

    // -------------------------------------------------------------------------
    // checkPermission()
    // -------------------------------------------------------------------------

    /** checkPermission() returns a 401 WP_Error with code 'unauthorized' when the capability check fails. */
    public function test_check_permission_returns_401_wp_error_when_capability_check_fails(): void {
        $controller = $this->buildController( fn() => false );

        $result = $controller->checkPermission();

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'unauthorized', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    /** checkPermission() returns true when the capability check passes. */
    public function test_check_permission_returns_true_when_capability_check_passes(): void {
        $controller = $this->buildController( fn() => true );

        $result = $controller->checkPermission();

        $this->assertTrue( $result );
    }

    // -------------------------------------------------------------------------
    // Auth gate on the handler itself (mirrors EvaluationControllerTest)
    // -------------------------------------------------------------------------

    public function test_handle_recompute_returns_401_when_capability_check_fails(): void {
        $controller = $this->buildController( fn() => false );

        $response = $controller->handleRecompute( $this->buildRequest() );

        $this->assertSame( 401, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'unauthorized', $data['code'] );
        $this->assertSame( 401, $data['data']['status'] );
        // Auth gate must short-circuit before ever firing the cron.
        $this->assertSame( 0, did_action( 'prode_recompute_rankings_cron' ) );
    }

    // -------------------------------------------------------------------------
    // Handler fires prode_recompute_rankings_cron
    // -------------------------------------------------------------------------

    public function test_handle_recompute_fires_recompute_rankings_cron(): void {
        $controller = $this->buildController( fn() => true );

        $controller->handleRecompute( $this->buildRequest() );

        $this->assertSame( 1, did_action( 'prode_recompute_rankings_cron' ) );
    }

    // -------------------------------------------------------------------------
    // Handler returns counters captured from prode_ranking_cron_ran
    // -------------------------------------------------------------------------

    public function test_handle_recompute_returns_counters_from_ranking_cron_ran(): void {
        $processedId = $this->seedFecha( 'evaluated' );
        $unscoredId  = $this->seedFecha( 'evaluated' );
        $emptyId     = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedScore( $processedId, 1, 101, 3 );
        $this->seedUnscoredMatch( $unscoredId, 1, 201 );
        // $emptyId: no prode_scores rows at all → aggregateByFecha() empty.

        $controller = $this->buildController( fn() => true );

        $response = $controller->handleRecompute( $this->buildRequest() );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'ok', $data['status'] );
        $this->assertSame( 1, $data['fechas_processed'] );
        $this->assertSame( 1, $data['skipped_unscored'] );
        $this->assertSame( 1, $data['skipped_empty'] );
        $this->assertArrayHasKey( 'computed_at', $data );
        $this->assertIsString( $data['computed_at'] );
    }

    // -------------------------------------------------------------------------
    // Temporary listener cleanup: two calls in one request must not double
    // count or leave a dangling listener on prode_ranking_cron_ran.
    // -------------------------------------------------------------------------

    public function test_handle_recompute_twice_does_not_stack_listeners_or_double_count(): void {
        $fechaId = $this->seedFecha( 'evaluated' );
        $this->seedUser( 1 );
        $this->seedScore( $fechaId, 1, 101, 3 );

        $controller = $this->buildController( fn() => true );

        $response1 = $controller->handleRecompute( $this->buildRequest() );
        $this->assertSame(
            0,
            count( $GLOBALS['_prode_test_action_registrations']['prode_ranking_cron_ran'] ?? [] ),
            'Temporary listener must be removed after the first call.'
        );

        $response2 = $controller->handleRecompute( $this->buildRequest() );
        $this->assertSame(
            0,
            count( $GLOBALS['_prode_test_action_registrations']['prode_ranking_cron_ran'] ?? [] ),
            'Temporary listener must be removed after the second call too (no stacking).'
        );

        $data1 = $response1->get_data();
        $data2 = $response2->get_data();

        // RankingCron is idempotent (upsert) — a re-run of the same evaluated
        // fecha produces the same counters both times, never a doubled count.
        $this->assertSame( 1, $data1['fechas_processed'] );
        $this->assertSame( 1, $data2['fechas_processed'] );
        $this->assertSame( 2, did_action( 'prode_recompute_rankings_cron' ) );
    }
}
