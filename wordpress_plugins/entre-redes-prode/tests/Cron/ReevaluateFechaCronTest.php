<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Cron;

use EntreRedes\Prode\Cron\ReevaluateFechaCron;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReevaluateFechaCron::run() (ADR-G7-1).
 *
 * run() builds its FechaEvaluator with the production rest_do_request()
 * dispatcher, exactly like EvaluatorCron — and, like EvaluatorCron, is not
 * exercised end-to-end here because rest_do_request() has no shim (it needs a
 * real WP REST server). What IS testable under the shim is every branch of
 * evaluateFecha() that returns BEFORE reaching the dispatcher (FechaEvaluator
 * Step 4): a missing fecha, and a fecha with no engaged participants. Both
 * prove run() delegates to evaluateFecha() with the given id and fires
 * 'prode_reevaluate_fecha_ran' — without ever touching the network seam.
 */
class ReevaluateFechaCronTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $GLOBALS['_prode_test_actions'] = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_scores" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_predictions" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
    }

    public function test_run_on_nonexistent_fecha_fires_ran_hook_without_fatal(): void {
        // evaluateFecha() returns its empty summary at Step 1 (fecha row not
        // found) — this never reaches the dispatcher, so it is safe to call
        // run() directly under the shim (no rest_do_request() available).
        ReevaluateFechaCron::run( 999999 );

        $this->assertSame( 1, did_action( 'prode_reevaluate_fecha_ran' ) );
    }

    public function test_run_delegates_to_evaluate_fecha_for_the_given_id(): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'prode_fechas',
            [
                'tenant_id'  => 'test_tenant',
                'season_id'  => 359,
                'locked_at'  => '2026-05-30 10:00:00',
                'state'      => 'evaluated',
                'created_at' => '2026-05-28 00:00:00',
            ]
        );
        $fechaId = (int) $wpdb->insert_id;

        $wpdb->insert(
            $wpdb->prefix . 'prode_fecha_matches',
            [
                'fecha_id'      => $fechaId,
                'match_id'      => 555,
                'match_kickoff' => '2026-05-30 10:00:00',
                'real_score_home' => 2,
                'real_score_away' => 1,
                'is_final'        => 1,
            ]
        );

        // No predictions seeded → participants is empty. evaluateFecha() takes
        // the "no engaged users" branch (Step 3), which fires
        // 'prode_recompute_rankings_cron' and returns BEFORE Step 4's dispatcher
        // call — proving evaluateFecha() actually ran for fechaId, still
        // without needing rest_do_request().
        ReevaluateFechaCron::run( $fechaId );

        $this->assertSame(
            1,
            did_action( 'prode_recompute_rankings_cron' ),
            'evaluateFecha() must have run far enough to reach the no-participants branch for this fecha.'
        );
        $this->assertSame( 1, did_action( 'prode_reevaluate_fecha_ran' ) );
    }
}
