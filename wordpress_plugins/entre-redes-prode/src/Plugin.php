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

        // 2. REST API routes — wire auth services and register all /prode/* routes.
        add_action( 'rest_api_init', function () {
            global $wpdb;

            $jwt           = new Auth\JwtService();
            $google        = new Auth\GoogleVerifier();
            $apple         = new Auth\AppleVerifier();
            $dni_matcher   = new Auth\DniMatcher();
            $session       = new Auth\SessionManager();
            $audit         = new Audit\AuditLogger();
            $hasher        = new Audit\DniHasher();
            $middleware    = new Auth\AuthMiddleware( $jwt, $session );

            $auth_endpoints = new Rest\AuthEndpoints(
                $jwt,
                $google,
                $apple,
                $dni_matcher,
                $session,
                $audit
            );

            $account_controller = new Account\AccountController(
                $middleware,
                $session,
                $audit,
                $hasher
            );

            $fecha_controller = new Rest\FechaController(
                new Fecha\FechaRepository( $wpdb ),
                new Fecha\FechaResolver(),
                new Fecha\LockComputer(),
                new Fecha\Settings( $wpdb ),
                $middleware
            );

            // Swap permission_callback to optionalAuth so the route is
            // forward-compatible with G2 user_predictions (ADR-G0-5).
            // The FechaController register_routes() is called by RestController.

            $controller = new Rest\RestController( $auth_endpoints, $account_controller, $fecha_controller );
            $controller->register_routes();
        } );

        // 3. WP-CLI commands — guarded so the command class is only loaded in CLI context.
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            global $wpdb;

            $seed_settings     = new Fecha\Settings( $wpdb );
            $seed_lock         = new Fecha\LockComputer();
            $seed_repo         = new Fecha\FechaRepository( $wpdb );
            $seed_resolver     = new Fecha\FechaResolver();
            $seed_resolver_fn  = fn() => $seed_resolver->resolveNext( $seed_settings->fechaWindowDays() );

            \WP_CLI::add_command(
                'prode seed-fecha',
                new Fecha\SeedFechaCommand( $seed_settings, $seed_lock, $seed_repo, $seed_resolver_fn )
            );
        }

        // 4. Admin menu (only in wp-admin context).
        if ( is_admin() ) {
            add_action( 'admin_menu', [ Admin\AdminMenu::class, 'register' ] );
        }

        // 5. Cron action handlers (registered here; scheduled at activation).
        add_action( 'prode_evaluate_matches_cron',      [ Cron\EvaluatorCron::class, 'run' ] );
        // prode_recompute_rankings_cron is event-driven (fired on-demand by EvaluatorCron
        // after match evaluations land), NOT on a fixed schedule — per design.
        add_action( 'prode_recompute_rankings_cron',    [ Cron\RankingCron::class, 'run' ] );
        add_action( 'prode_notify_lock_approaching_cron', [ Cron\NotificationCron::class, 'runLockApproaching' ] );
        add_action( 'prode_create_new_fecha_cron',      [ Cron\FechaCreationCron::class, 'run' ] );

        // 6. Load text domain for i18n.
        load_plugin_textdomain(
            'entre-redes-prode',
            false,
            dirname( plugin_basename( ENTRE_REDES_PRODE_FILE ) ) . '/languages'
        );
    }
}
