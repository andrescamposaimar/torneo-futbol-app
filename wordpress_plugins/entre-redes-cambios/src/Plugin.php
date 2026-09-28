<?php

declare(strict_types=1);

namespace EntreRedes\Cambios;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
 *
 * Slice 0 scope: this is a "pure function, zero UI" slice. boot() intentionally
 * does NOT register REST routes, admin menus, or cron jobs — those consumers
 * (the calendar admin screen, the solicitud/regreso REST endpoints, the daily
 * seeding cron) arrive in later slices, once the domain logic here (calendar
 * derivation, plazo computation, state machine) has been validated. Keeping
 * boot() empty of side effects also means activating this plugin today is
 * inert beyond running migrations — safe to ship ahead of the rest of the
 * feature.
 */
final class Plugin {

    private static bool $booted = false;

    /**
     * Called on `plugins_loaded` (priority 10). Idempotent — repeated calls
     * within the same request are a no-op.
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // Nothing else to wire in slice 0. See class docblock above.
        load_plugin_textdomain(
            'entre-redes-cambios',
            false,
            dirname( plugin_basename( ENTRE_REDES_CAMBIOS_FILE ) ) . '/languages'
        );
    }
}
