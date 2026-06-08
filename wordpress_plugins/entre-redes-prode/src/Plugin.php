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

            $fecha_repo  = new Fecha\FechaRepository( $wpdb );
            $pred_repo   = new Predictions\PredictionRepository( $wpdb );

            // G3: score repository and fecha evaluator (ADR-G3-1).
            $score_repo         = new Scoring\ScoreRepository( $wpdb );
            $results_dispatcher = static fn( \WP_REST_Request $req ) => rest_do_request( $req );
            $fecha_evaluator    = new Scoring\FechaEvaluator( $score_repo, $pred_repo, $fecha_repo, $results_dispatcher );

            $fecha_controller = new Rest\FechaController(
                $fecha_repo,
                new Fecha\FechaResolver(),
                new Fecha\LockComputer(),
                new Fecha\Settings( $wpdb ),
                $middleware,
                $pred_repo
            );

            $prediction_controller = new Rest\PredictionController(
                $pred_repo,
                $fecha_repo,
                $middleware
            );

            // G3: admin endpoint for manual fecha evaluation (ADR-G3-4).
            $cap_check = static fn() => current_user_can( 'manage_options' );
            $evaluation_controller = new Rest\EvaluationController( $fecha_evaluator, $cap_check );

            // G4: ranking endpoint (PR-G4-C).
            $ranking_repo       = new Scoring\RankingRepository( $wpdb );
            $ranking_computer   = new Scoring\RankingComputer();
            $ranking_controller = new Rest\RankingController(
                $ranking_repo,
                $ranking_computer,
                new Fecha\Settings( $wpdb ),
                $middleware
            );

            // G6-b: multi-fecha navigation endpoints (PR-G6-B).
            $fecha_list_controller = new Rest\FechaListController(
                $fecha_repo,
                new Fecha\FechaResolver(),
                new Fecha\LockComputer(),
                new Fecha\Settings( $wpdb ),
                $middleware,
                $pred_repo
            );

            $controller = new Rest\RestController(
                $auth_endpoints,
                $account_controller,
                $fecha_controller,
                $prediction_controller,
                $evaluation_controller,
                $ranking_controller,
                $fecha_list_controller
            );
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
            add_action( 'admin_menu', static function () {
                global $wpdb;

                $settingsRepo = new Admin\SettingsRepository( $wpdb );
                $registryRepo = new Admin\RegistryRepository( $wpdb );
                $auditLogRepo = new Admin\AuditLogRepository( $wpdb );
                $auditLogger  = new Audit\AuditLogger();
                $hasher       = new Audit\DniHasher();

                $adminSettings    = new Fecha\Settings( $wpdb );
                $adminLock        = new Fecha\LockComputer();
                $adminFechaRepo   = new Fecha\FechaRepository( $wpdb );
                $adminResolver    = new Fecha\FechaResolver();
                $adminResolverFn  = fn() => $adminResolver->resolveNext( $adminSettings->fechaWindowDays() );
                $seedService      = new Fecha\SeedFechaService( $adminSettings, $adminLock, $adminFechaRepo, $adminResolverFn );

                // Resolves a player's full name (sp_player post title) from the
                // roster by player_id; used to backfill display_names stored as
                // the SSO email (e.g. Apple @privaterelay.appleid.com addresses).
                $playerNameByIdFn = static function ( int $playerId ) use ( $wpdb ): ?string {
                    $title = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_title FROM {$wpdb->posts}
                              WHERE ID = %d AND post_type = 'sp_player' AND post_status = 'publish'
                              LIMIT 1",
                            $playerId
                        )
                    );
                    return is_string( $title ) ? $title : null;
                };
                $repairService    = new Admin\RepairDisplayNamesService( $wpdb, $playerNameByIdFn );

                $settingsPage = new Admin\SettingsPage( $settingsRepo, $seedService, $repairService );
                $registryPage = new Admin\RegistryPage( $registryRepo, $auditLogger, $hasher );
                $auditLogPage = new Admin\AuditLogPage( $auditLogRepo );

                $adminMenu = new Admin\AdminMenu( $settingsPage, $registryPage, $auditLogPage );
                $adminMenu->register();
            } );
        }

        // 5. Cron action handlers (registered here; scheduled at activation).
        //
        // Custom recurrence intervals MUST be registered on every request (not
        // just at activation inside MigrationRunner::scheduleCrons), otherwise
        // WP-Cron cannot resolve 'every_5_minutes' / 'every_15_minutes' when it
        // reschedules the recurring events at runtime — the events silently fail
        // to re-fire. Guard each with isset() so this composes with the
        // activation-time registration.
        add_filter( 'cron_schedules', static function ( array $schedules ): array {
            if ( ! isset( $schedules['every_5_minutes'] ) ) {
                $schedules['every_5_minutes'] = [
                    'interval' => 5 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 5 minutes', 'entre-redes-prode' ),
                ];
            }
            if ( ! isset( $schedules['every_15_minutes'] ) ) {
                $schedules['every_15_minutes'] = [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'display'  => __( 'Every 15 minutes', 'entre-redes-prode' ),
                ];
            }
            return $schedules;
        } );

        add_action( 'prode_evaluate_matches_cron',      [ Cron\EvaluatorCron::class, 'run' ] );
        // prode_recompute_rankings_cron is event-driven (fired on-demand by EvaluatorCron
        // after match evaluations land), NOT on a fixed schedule — per design.
        add_action( 'prode_recompute_rankings_cron',    [ Cron\RankingCron::class, 'run' ] );
        add_action( 'prode_notify_lock_approaching_cron', [ Cron\NotificationCron::class, 'runLockApproaching' ] );
        add_action( 'prode_create_new_fecha_cron',      [ Cron\FechaCreationCron::class, 'run' ] );
        // Daily backfill of team-meta snapshots for fecha-match rows that still
        // have none (legacy rows seeded before v0.5.2). Idempotent no-op once filled.
        add_action( Cron\BackfillMatchMetaCron::HOOK,    [ Cron\BackfillMatchMetaCron::class, 'run' ] );

        // Safety net: (re)schedule the crons on any normal request where the
        // primary evaluation event is missing. MigrationRunner::run() only fires
        // from the activation hook, so a plugin updated by file overwrite (which
        // does NOT trigger activation) would otherwise never schedule its crons.
        // The wp_next_scheduled() guard makes this a cheap no-op once events
        // exist; scheduleCrons() is itself idempotent per hook.
        if ( ! wp_next_scheduled( 'prode_evaluate_matches_cron' ) ) {
            Migrations\MigrationRunner::scheduleCrons();
        }

        // 6. Load text domain for i18n.
        load_plugin_textdomain(
            'entre-redes-prode',
            false,
            dirname( plugin_basename( ENTRE_REDES_PRODE_FILE ) ) . '/languages'
        );
    }
}
