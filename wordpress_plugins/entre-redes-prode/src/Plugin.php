<?php

declare(strict_types=1);

namespace EntreRedes\Prode;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
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

        // 1. Dependency guard — must run at priority 11 so the entre-redes
        //    plugin has had a chance to declare itself at priority 10.
        add_action( 'plugins_loaded', [ DependencyCheck::class, 'ensureActive' ], 11 );

        // 2. REST API routes.
        add_action( 'rest_api_init', function () {
            $controller = new Rest\RestController();
            $controller->register_routes();
        } );

        // 3. Admin menu (only in wp-admin context).
        if ( is_admin() ) {
            add_action( 'admin_menu', [ Admin\AdminMenu::class, 'register' ] );
        }

        // 4. Cron action handlers (registered here; scheduled at activation).
        add_action( 'prode_evaluate_matches_cron',      [ Cron\EvaluatorCron::class, 'run' ] );
        add_action( 'prode_recompute_rankings_cron',    [ Cron\RankingCron::class, 'run' ] );
        add_action( 'prode_notify_lock_approaching_cron', [ Cron\NotificationCron::class, 'runLockApproaching' ] );
        add_action( 'prode_create_new_fecha_cron',      [ Cron\FechaCreationCron::class, 'run' ] );

        // 5. Load text domain for i18n.
        load_plugin_textdomain(
            'entre-redes-prode',
            false,
            dirname( plugin_basename( ENTRE_REDES_PRODE_FILE ) ) . '/languages'
        );
    }
}
