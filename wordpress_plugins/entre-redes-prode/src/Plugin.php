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
                $middleware,
                new Audit\AuditLogger()
            );

            // G3: admin endpoint for manual fecha evaluation (ADR-G3-4).
            $cap_check = static fn() => current_user_can( 'manage_options' );
            $evaluation_controller = new Rest\EvaluationController( $fecha_evaluator, $cap_check );

            // G4: ranking endpoint (PR-G4-C).
            $ranking_repo       = new Scoring\RankingRepository( $wpdb );
            $ranking_computer   = new Scoring\RankingComputer();
            $roster_resolver    = new Scoring\WpRosterResolver( $ranking_repo );
            $ranking_controller = new Rest\RankingController(
                $ranking_repo,
                $ranking_computer,
                new Fecha\Settings( $wpdb ),
                $middleware,
                $roster_resolver
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

            // Prediction history endpoint: GET /prode/predicciones (paginated "Anteriores" list).
            $prediction_history_controller = new Rest\PredictionHistoryController(
                $pred_repo,
                $middleware
            );

            // Populares endpoint: GET /prode/populares (prediction split for one match).
            $populares_controller = new Rest\PopularesController( $pred_repo );

            // Recompute rankings endpoint (ADR-G8-1): POST /prode/recompute-rankings.
            // Replaces the temporary mu-plugin workaround for forcing a rebuild of
            // the ranking cache. Scope is global (every evaluated fecha of the
            // tenant), not per-fecha — see RecomputeRankingsController's docblock.
            $recompute_rankings_controller = new Rest\RecomputeRankingsController( $cap_check );

            $controller = new Rest\RestController(
                $auth_endpoints,
                $account_controller,
                $fecha_controller,
                $prediction_controller,
                $evaluation_controller,
                $ranking_controller,
                $fecha_list_controller,
                $prediction_history_controller,
                $populares_controller,
                $recompute_rankings_controller
            );
            $controller->register_routes();
        } );

        // 2b. Never let a shared HTTP cache store a /prode/ response.
        //
        //     Every /prode/ payload is caller-specific: GET /prode/fecha-activa and
        //     GET /prode/fecha/{id} embed the caller's own user_predictions, /prode/ranking
        //     embeds their `me` row, and /prode/auth/* returns their tokens. Callers
        //     authenticate with a Bearer token and send no WordPress session cookie, so a
        //     URL-keyed reverse proxy that only bypasses on that cookie treats every
        //     request as anonymous — and serves one user's predictions to everyone else
        //     for the lifetime of the cache entry. Observed in production 2026-09-07:
        //     an invalid Bearer token returned 200 with `x-cache-status: HIT` instead of 401.
        //
        //     The response header is the half we control from the plugin: it travels with
        //     the code and survives a hosting, proxy or CDN change. Vary is declared too so
        //     a cache that does key on request headers splits per token instead of ignoring
        //     it. The upstream proxy must still be configured to honour this — the plugin
        //     cannot force it, which is why the header is a floor and not the whole fix.
        add_filter( 'rest_post_dispatch', [ self::class, 'denyProdeResponseCaching' ], 10, 3 );

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

                $settingsRepo    = new Admin\SettingsRepository( $wpdb );
                $registryRepo    = new Admin\RegistryRepository( $wpdb );
                $auditLogRepo    = new Admin\AuditLogRepository( $wpdb );
                $auditLogger     = new Audit\AuditLogger();
                $hasher          = new Audit\DniHasher();
                $sessionManager  = new Auth\SessionManager();

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

                // Backfills home_team/away_team snapshots on fecha-match rows that
                // still have empty names (seeded before v0.5.2). Uses the same
                // production dispatcher as the daily cron so team names are resolved
                // from the /partidos endpoint on demand.
                $backfillService  = new Fecha\BackfillMatchMetaService( $wpdb, Cron\BackfillMatchMetaCron::defaultDispatcher() );

                $predRepo        = new Predictions\PredictionRepository( $wpdb );
                $predictionsPage = new Admin\PredictionsPage( $predRepo, $registryRepo, new Fecha\FechaResolver() );

                $settingsPage = new Admin\SettingsPage( $settingsRepo, $seedService, $repairService, $backfillService );
                $registryPage = new Admin\RegistryPage( $registryRepo, $auditLogger, $hasher, $sessionManager );
                $auditLogPage = new Admin\AuditLogPage( $auditLogRepo );

                $adminMenu = new Admin\AdminMenu( $settingsPage, $registryPage, $auditLogPage, $predictionsPage );
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

        // Result-change self-heal (ADR-G7-1): repair an already-evaluated fecha
        // when an operator corrects a played match's score in SportsPress.
        //
        // MUST bind to `save_post` at priority 20, NOT `save_post_sp_event` —
        // see ResultChangeListener's class docblock for why (SportsPress writes
        // its score meta boxes on `save_post` priority 1, and `save_post_sp_event`
        // fires BEFORE the generic `save_post`, so no priority there can ever
        // observe the write). 2 accepted args for the defensive ($post_id, $post)
        // signature.
        add_action( 'save_post', [ Sync\ResultChangeListener::class, 'onSavePost' ], 20, 2 );

        // ResultChangeListener schedules this single event (30s delay) instead
        // of calling the evaluator inline, so the save_post request returns fast
        // and several quick corrections to the same fecha collapse into one
        // repair pass (WordPress dedupes identical (hook, args) schedules within
        // a 10-minute window).
        add_action( Cron\ReevaluateFechaCron::HOOK, [ Cron\ReevaluateFechaCron::class, 'run' ], 10, 1 );

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

    /**
     * Mark every /entre-redes/v1/prode/ REST response as uncacheable.
     *
     * Registered on `rest_post_dispatch`. Responses outside the prode namespace are
     * returned untouched, so the public read-only endpoints stay cacheable.
     *
     * `Vary: Authorization` is appended rather than replaced: WordPress already sets
     * `Vary: Origin` for CORS, and WP_HTTP_Response::header() with $replace = false
     * concatenates instead of overwriting.
     *
     * @param \WP_HTTP_Response|mixed $response Result to send to the client.
     * @param \WP_REST_Server|mixed   $server   Server instance (unused).
     * @param \WP_REST_Request|mixed  $request  Request used to generate the response.
     * @return \WP_HTTP_Response|mixed
     */
    public static function denyProdeResponseCaching( $response, $server, $request ) {
        // Duck-typed on purpose: rest_post_dispatch is documented to pass a
        // WP_HTTP_Response, but anything carrying header() and get_route() is enough
        // here — and it keeps the filter testable against a shim that does not model
        // WordPress's response class hierarchy.
        if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
            return $response;
        }

        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
            return $response;
        }

        if ( ! str_starts_with( (string) $request->get_route(), '/entre-redes/v1/prode/' ) ) {
            return $response;
        }

        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Vary', 'Authorization', false );

        return $response;
    }
}
