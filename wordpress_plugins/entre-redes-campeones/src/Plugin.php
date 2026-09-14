<?php

declare(strict_types=1);

namespace EntreRedes\Campeones;

use EntreRedes\Campeones\Admin\AdminMenu;
use EntreRedes\Campeones\Admin\TitleEditorPage;
use EntreRedes\Campeones\Admin\TitlesPage;
use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Linking\WpPlayerDirectory;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;

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

        // Admin menu — Titles list + hidden title editor (slice 3).
        // ReviewQueuePage (slice 4) and ImportPage (slice 6) extend this
        // closure in their own slices.
        if ( is_admin() ) {
            add_action( 'admin_menu', static function (): void {
                global $wpdb;

                $titles    = new TitleRepository( $wpdb );
                $squads    = new SquadRepository( $wpdb );
                $directory = new WpPlayerDirectory( $wpdb );
                $resolver  = new LinkResolver( $directory );
                $writer    = new LinkWriteService( $squads, $directory );

                $titlesPage = new TitlesPage(
                    $titles,
                    new TitleDeletionService( $wpdb, $titles, $squads ),
                    new RevalidationService( $titles, $squads, $resolver, $writer )
                );
                $editorPage = new TitleEditorPage( $titles, $squads, $resolver, $writer );

                ( new AdminMenu( $titlesPage, $editorPage ) )->register();
            } );
        }

        load_plugin_textdomain(
            'entre-redes-campeones',
            false,
            dirname( plugin_basename( ENTRE_REDES_CAMPEONES_FILE ) ) . '/languages'
        );
    }
}
