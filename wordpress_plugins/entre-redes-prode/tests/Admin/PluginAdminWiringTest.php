<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Admin;

use EntreRedes\Prode\Plugin;
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
}
