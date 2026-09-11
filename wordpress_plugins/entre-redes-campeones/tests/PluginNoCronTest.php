<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests;

use EntreRedes\Campeones\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * Pins the deliberate absence of any cron layer (design §1): this plugin has
 * no src/Cron/ directory, and Plugin::boot() registers no cron-related hook
 * and never calls wp_schedule_event(). Matching, linking and re-validation
 * are synchronous or explicitly human-triggered, never scheduled.
 */
class PluginNoCronTest extends TestCase {

    public function test_no_cron_directory_exists(): void {
        $this->assertDirectoryDoesNotExist(
            dirname( __DIR__ ) . '/src/Cron',
            'entre-redes-campeones must not have a src/Cron/ directory — this feature has no cron layer by design.'
        );
    }

    public function test_boot_registers_no_cron_related_hook(): void {
        $GLOBALS['_campeones_test_action_callbacks'] = [];

        // Reset the booted guard via reflection so this test can call boot()
        // fresh regardless of test execution order.
        $reflection = new \ReflectionProperty( Plugin::class, 'booted' );
        $reflection->setValue( null, false );

        Plugin::boot();

        $registered = array_keys( $GLOBALS['_campeones_test_action_callbacks'] ?? [] );

        if ( empty( $registered ) ) {
            // Slice 1 registers no hooks at all yet (REST/admin wiring lands
            // in slices 3 and 7a) — assert that directly rather than letting
            // an empty loop silently report zero assertions.
            $this->assertSame( [], $registered );
        }

        foreach ( $registered as $hook ) {
            $this->assertStringNotContainsStringIgnoringCase(
                'cron',
                $hook,
                "Plugin::boot() must not register any cron-related hook. Found: {$hook}"
            );
        }
    }

    public function test_wp_schedule_event_is_never_called(): void {
        // wp_schedule_event is deliberately NOT stubbed in tests/wp-shim.php —
        // this plugin never calls it. If any code path in Plugin::boot()
        // reached it, this would be a fatal "call to undefined function"
        // rather than a silent no-op, which is exactly the point: any
        // regression here is loud, not quiet.
        $this->assertFalse(
            function_exists( 'wp_schedule_event' ),
            'wp_schedule_event must not be defined in the test shim — this plugin has no code path that calls it.'
        );
    }
}
