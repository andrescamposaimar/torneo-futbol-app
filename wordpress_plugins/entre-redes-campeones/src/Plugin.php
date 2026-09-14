<?php

declare(strict_types=1);

namespace EntreRedes\Campeones;

/**
 * Main plugin class — composition root, wires all hooks.
 *
 * No cron. This plugin has no src/Cron/ directory, and boot() registers no
 * cron-related hook and calls no wp_schedule_event() — matching, linking and
 * re-validation are synchronous or explicitly human-triggered (design §1).
 * Pinned by tests/PluginNoCronTest.php.
 *
 * Manual constructor injection, no container — mirrors
 * entre-redes-prode/src/Plugin.php's shape (static boot(), $booted guard).
 */
final class Plugin {

    private static bool $booted = false;

    /**
     * Called on `plugins_loaded` (priority 10).
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        // REST API routes (HistoryController, PlayerTitlesController) are
        // wired here starting with slice 7a. Nothing to register yet.

        // Admin menu (TitlesPage, ReviewQueuePage, ImportPage, ...) is wired
        // here starting with slice 3. Nothing to register yet.

        load_plugin_textdomain(
            'entre-redes-campeones',
            false,
            dirname( plugin_basename( ENTRE_REDES_CAMPEONES_FILE ) ) . '/languages'
        );
    }
}
