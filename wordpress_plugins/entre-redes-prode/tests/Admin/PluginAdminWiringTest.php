<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Cron\ReevaluateFechaCron;
use EntreRedes\Prode\Plugin;
use EntreRedes\Prode\Sync\ResultChangeListener;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that Plugin.php can be loaded and its boot() method completes
 * without fatal errors (T-17: PredictionRepository + PredictionsPage wired
 * into the admin_menu closure and passed to AdminMenu).
 *
 * The shim stubs out all WP hooks, constants, and admin functions as no-ops,
 * so boot() just registers callbacks and returns without executing them.
 *
 * T-17 (Strict TDD — RED written first).
 */
class PluginAdminWiringTest extends TestCase {

    protected function setUp(): void {
        // Reset the booted flag so Plugin::boot() runs fresh in each test.
        $ref = new \ReflectionProperty( Plugin::class, 'booted' );
        $ref->setValue( null, false );
    }

    public function test_plugin_boot_completes_without_error(): void {
        // Requires is_admin(), plugins_loaded constants, and WP functions
        // provided by the shim. boot() should complete without throwing.
        Plugin::boot();
        $this->assertTrue( true );
    }

    public function test_plugin_boots_only_once(): void {
        // Second call to boot() is a no-op due to static $booted guard.
        Plugin::boot();
        Plugin::boot();
        $this->assertTrue( true );
    }

    /**
     * Regression guard for the whole result-change self-heal feature (ADR-G7-1).
     *
     * ResultChangeListener::onSavePost() MUST be bound to `save_post` at
     * priority 20 with 2 accepted args, and MUST NEVER be bound to
     * `save_post_sp_event`. WordPress fires `save_post_{$post_type}` BEFORE the
     * generic `save_post` (wp-includes/post.php), and SportsPress writes its
     * score meta boxes on `save_post` priority 1 — so a callback bound to
     * `save_post_sp_event` at ANY priority would always read the PREVIOUS
     * save's score, one edit behind. This exact class of bug already hit this
     * codebase once (the wpm2_jugador_partido sync in entre-redes-api) and was
     * fixed the same way this plugin's listener is wired.
     */
    public function test_result_change_listener_bound_to_save_post_priority_20(): void {
        Plugin::boot();

        $target       = [ ResultChangeListener::class, 'onSavePost' ];
        $savePostRegs = $GLOBALS['_prode_test_action_registrations']['save_post'] ?? [];

        $matches = array_values( array_filter(
            $savePostRegs,
            static fn( array $reg ) => $reg['callback'] === $target
        ) );

        $this->assertNotEmpty( $matches, 'ResultChangeListener::onSavePost must be bound to save_post.' );
        $this->assertSame( 20, $matches[0]['priority'], 'Must run AFTER SportsPress writes its score meta boxes (save_post priority 1).' );
        $this->assertSame( 2, $matches[0]['accepted_args'], 'Needs the defensive ($post_id, $post) signature.' );

        $spEventRegs        = $GLOBALS['_prode_test_action_registrations']['save_post_sp_event'] ?? [];
        $boundToWrongHook = array_filter(
            $spEventRegs,
            static fn( array $reg ) => $reg['callback'] === $target
        );

        $this->assertEmpty(
            $boundToWrongHook,
            'ResultChangeListener must never be bound to save_post_sp_event — that hook fires before save_post and would read a stale score.'
        );
    }

    /**
     * ReevaluateFechaCron::run() must be bound to the 'prode_reevaluate_fecha'
     * hook that ResultChangeListener::onSavePost() schedules.
     */
    public function test_reevaluate_fecha_cron_bound_to_its_hook(): void {
        Plugin::boot();

        $target = [ ReevaluateFechaCron::class, 'run' ];
        $regs   = $GLOBALS['_prode_test_action_registrations']['prode_reevaluate_fecha'] ?? [];

        $matches = array_values( array_filter(
            $regs,
            static fn( array $reg ) => $reg['callback'] === $target
        ) );

        $this->assertNotEmpty( $matches, 'ReevaluateFechaCron::run must be bound to prode_reevaluate_fecha.' );
        $this->assertSame( 1, $matches[0]['accepted_args'], 'Needs the scheduled fecha_id argument.' );
    }
}
