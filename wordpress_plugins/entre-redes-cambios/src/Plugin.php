<?php

declare(strict_types=1);

namespace EntreRedes\Cambios;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
 *
 * Slice 4d scope: registers the CAPTAIN-facing REST endpoints
 * (Rest\SolicitudesController, Rest\PlazasController) on `rest_api_init`,
 * with manual constructor injection — no container — mirroring exactly how
 * entre-redes-prode's own Plugin::boot() wires its `/prode/*` routes.
 *
 * Slice 4e scope: the PROCESS OWNER's admin bandeja (Admin\BandejaPage,
 * Admin\AdminMenu) on `admin_menu`, only `if ( is_admin() )` — exactly the
 * same guard entre-redes-prode's own Plugin::boot() uses for its admin
 * screens, so a REST request or a cron run never pays for constructing
 * anything this closure builds.
 *
 * Every service built inside the `rest_api_init` / `admin_menu` closures is
 * built there and ONLY there — never at the top level of boot() — so a
 * request that never hits that surface never pays for constructing
 * TokenVerifier, DictamenPipeline, or any of the repositories these
 * endpoints need.
 *
 * *** THE MIGRATION CALL RUNS ON `init` PRIORITY 11, NEVER DIRECTLY FROM
 * `boot()` (0.1.14) *** `boot()` itself is still called on `plugins_loaded`
 * (see entre-redes-cambios.php) — only `Migrations\MigrationRunner::runIfOutdated()`
 * moved, to an `init`-priority-11 callback THIS method registers. This is the
 * fix for a real production incident: SportsPress registers its `sp_position`
 * taxonomy on `init` priority 10 (`includes/class-sp-post-types.php`,
 * `SP_Post_Types::register_taxonomies()`) — strictly AFTER `plugins_loaded`,
 * which fires before `init` on every WordPress request. Calling the migration
 * directly from `boot()` therefore ran it BEFORE `sp_position` existed, so
 * `wp_get_object_terms()` returned a `WP_Error` for every call the 0.1.13
 * `es_arco` backfill made — silently read, at the time, as "nobody has a
 * position" (see `Plazas\PosicionResolver`'s own docblock for that half of
 * the fix) and written as `es_arco = 0` for all 330 live plazas. Priority 11
 * (strictly after SportsPress's own priority 10 on the SAME `init` hook)
 * guarantees the taxonomy is registered by the time this callback runs,
 * regardless of plugin load order (`init` callbacks run in priority order
 * within the same hook, independent of which plugin's `plugins_loaded`
 * callback registered them).
 *
 * *** EVERYTHING ELSE boot() WIRES WAS VERIFIED UNAFFECTED BY THIS MOVE ***
 * The `rest_api_init` closure below and the `admin_menu` closure further down
 * both register on hooks WordPress fires AFTER `init` on every request type
 * that reaches them (a REST request, wp-admin) — core fires `init`, once,
 * during its own bootstrap (`wp-settings.php`) for every request type this
 * plugin runs under, including REST requests, wp-admin, and `wp-cron.php`
 * (see the cron registration further down in this method for why that last
 * one matters), and only fires `rest_api_init` / `admin_menu` afterwards.
 * Neither closure depends on the migration having already run — both build
 * their own repositories/controllers fresh, independent of schema version —
 * so moving ONLY the migration call changes nothing about when routes or the
 * admin menu become available. No other line in `boot()` needed to move.
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

        // Every /entre-redes/v1/cambios/ response is caller-specific: each
        // one is scoped to ONE captain's season/team and carries that team's
        // roster, its pending requests and the committee's notes. See
        // denyCambiosResponseCaching()'s own docblock for the production
        // incident this defends against. Registered here, on every request,
        // BEFORE the route closure below, so the routes are protected from
        // the moment they exist.
        add_filter( 'rest_post_dispatch', [ self::class, 'denyCambiosResponseCaching' ], 10, 3 );

        // Schema upgrades must land on a plain zip replace, not only on a
        // click of "Activate" — see MigrationRunner::runIfOutdated()'s own
        // docblock for the incident this guards against.
        //
        // Deferred to `init` priority 11 — see this class's own docblock,
        // "THE MIGRATION CALL RUNS ON init PRIORITY 11" — rather than called
        // directly here on `plugins_loaded`, so SportsPress's `sp_position`
        // taxonomy (registered on `init` priority 10) always exists by the
        // time this runs.
        add_action( 'init', static function (): void {
            Migrations\MigrationRunner::runIfOutdated( new Observability\WpEventLog() );
        }, 11 );

        add_action( 'rest_api_init', static function (): void {
            global $wpdb;

            $eventLog = new Observability\WpEventLog();

            // Captaincy authorization — see Capitania\CapitanAuthorizer's own
            // docblock for why these three collaborators (token verification,
            // prode session revocation, the captaincy itself) are composed
            // there rather than re-derived at every call site.
            $tokenVerifier     = new Auth\TokenVerifier( (string) get_option( 'prode_rsa_public_key', '' ) );
            $sessionGateway    = new Auth\ProdeSessionGateway( $wpdb );
            $capitanRepository = new Capitania\CapitanRepository( $wpdb, $eventLog );
            $capitanAuthorizer = new Capitania\CapitanAuthorizer( $tokenVerifier, $sessionGateway, $capitanRepository );

            $fechaRepository = new Calendario\FechaRepository( $wpdb, $eventLog );
            $settings        = new Calendario\Settings( $wpdb, $eventLog );
            $plazaRepository = new Plazas\PlazaRepository( $wpdb, $eventLog );

            // CC5b — which of the two BloqueoReemplazoPolicy readings applies
            // — is still pending confirmation from the process owner (see
            // that class's own docblock). Injected explicitly and VISIBLY
            // here, rather than left to DictamenPipeline's own silent
            // default, so the day the process owner answers CC5b, changing
            // the policy is a one-line, reviewable edit in THIS file — never
            // a hidden default someone has to go find first.
            $bloqueoReemplazoPolicy = Dictamen\BloqueoReemplazoPolicy::topeTresFechas();

            // "Prioridad de padres" (see Reglas\PrioridadDePadresRespetada) is
            // a REAL, persisted setting (unlike CC5b above) — OFF by default
            // (Migrations\InitialSchema::SEED_DEFAULTS). Read HERE, once, and
            // threaded explicitly into both the assembler (so it only pays
            // for Plazas\CandidatosResolver's query when actually on) and the
            // pipeline/factory (so the rule itself only fires when on) —
            // never re-read independently by either, which would risk the
            // two silently disagreeing.
            $prioridadPadresActiva = $settings->prioridadPadresActiva();

            // "La exención del arco" (see Dictamen\Reglas\PuntajeDentroDelTecho's
            // own docblock and Solicitudes\SolicitudRepository's class
            // docblock, "GROUPED REQUESTS") is a REAL, persisted setting,
            // default ON — unlike $prioridadPadresActiva above, read once
            // here and threaded into the pipeline so DictamenPipeline::
            // evaluateGrupo() never has to read Settings itself.
            $exencionArcoActiva = $settings->exencionArcoActiva();

            $dictamenContextAssembler = new Dictamen\DictamenContextAssembler(
                $plazaRepository,
                $fechaRepository,
                $settings,
                $wpdb,
                $eventLog,
                $bloqueoReemplazoPolicy
            );

            $dictamenPipeline = new Dictamen\DictamenPipeline(
                $dictamenContextAssembler,
                $eventLog,
                $bloqueoReemplazoPolicy,
                $prioridadPadresActiva,
                $exencionArcoActiva
            );

            $solicitudRepository = new Solicitudes\SolicitudRepository(
                $wpdb,
                $plazaRepository,
                $dictamenPipeline,
                $eventLog
            );

            $jugadorMetricasReader = new Plazas\JugadorMetricasReader( $wpdb, $eventLog );

            $solicitudesController = new Rest\SolicitudesController(
                $capitanAuthorizer,
                $solicitudRepository,
                $dictamenPipeline,
                $eventLog,
                $plazaRepository,
                $jugadorMetricasReader,
                null,
                new Solicitudes\Lock\MysqlCreationLock( $wpdb, $eventLog )
            );

            // ONE reader shared by the candidate filter and the roster
            // endpoint (and, built from the same $wpdb inside
            // DictamenContextAssembler, the dictamen itself) — "open
            // solicitud" must mean the same thing to all three; see
            // Solicitudes\SolicitudesAbiertasReader's own docblock.
            $solicitudesAbiertasReader = new Solicitudes\SolicitudesAbiertasReader( $wpdb, $eventLog );

            $candidatosResolver = new Plazas\CandidatosResolver(
                $wpdb,
                $plazaRepository,
                $eventLog,
                null,
                null,
                null,
                $solicitudesAbiertasReader
            );
            $listaEsperaResolver = new Plazas\ListaEsperaResolver( $wpdb, $settings, $eventLog );

            $plazasController = new Rest\PlazasController(
                $capitanAuthorizer,
                $plazaRepository,
                $fechaRepository,
                $eventLog,
                $candidatosResolver,
                $bloqueoReemplazoPolicy,
                null,
                $listaEsperaResolver,
                null,
                null,
                $solicitudesAbiertasReader
            );

            $capitanController = new Rest\CapitanController(
                $capitanAuthorizer,
                $capitanRepository,
                $settings,
                $eventLog
            );

            $fechaController = new Rest\FechaController(
                $capitanAuthorizer,
                $fechaRepository,
                $settings,
                $eventLog
            );

            ( new Rest\RestController( $solicitudesController, $plazasController, $capitanController, $fechaController ) )->register_routes();
        } );

        // Process owner's admin bandeja — only in wp-admin context, same
        // guard as entre-redes-prode's own Plugin::boot(). Built here, and
        // ONLY here, for the same reason as the rest_api_init closure above.
        if ( is_admin() ) {
            // Renders Migrations\MigrationRunner::checkEsArcoInvariant()'s
            // most recently PERSISTED verdict, if any — registered
            // UNCONDITIONALLY here, on every admin request, rather than from
            // inside that one-time migration run itself. See
            // renderEsArcoInvariantNotice()'s own docblock for the
            // production incident this fixes: a notice registered only from
            // inside `run()` (which executes at most once per version bump)
            // never rendered at all when that one request was not an admin
            // page load.
            add_action( 'admin_notices', [ Migrations\MigrationRunner::class, 'renderEsArcoInvariantNotice' ] );

            add_action( 'admin_menu', static function (): void {
                global $wpdb;

                $eventLog        = new Observability\WpEventLog();
                $authorizer      = new Admin\ProcessOwnerAuthorizer();
                $plazaRepository = new Plazas\PlazaRepository( $wpdb, $eventLog );
                $fechaRepository = new Calendario\FechaRepository( $wpdb, $eventLog );
                $settings        = new Calendario\Settings( $wpdb, $eventLog );

                $bloqueoReemplazoPolicy = Dictamen\BloqueoReemplazoPolicy::topeTresFechas();
                $prioridadPadresActiva  = $settings->prioridadPadresActiva();
                $exencionArcoActiva     = $settings->exencionArcoActiva();

                $dictamenContextAssembler = new Dictamen\DictamenContextAssembler(
                    $plazaRepository,
                    $fechaRepository,
                    $settings,
                    $wpdb,
                    $eventLog,
                    $bloqueoReemplazoPolicy
                );

                $dictamenPipeline = new Dictamen\DictamenPipeline(
                    $dictamenContextAssembler,
                    $eventLog,
                    $bloqueoReemplazoPolicy,
                    $prioridadPadresActiva,
                    $exencionArcoActiva
                );

                $solicitudRepository = new Solicitudes\SolicitudRepository(
                    $wpdb,
                    $plazaRepository,
                    $dictamenPipeline,
                    $eventLog
                );

                $bandejaPage = new Admin\BandejaPage(
                    $authorizer,
                    $solicitudRepository,
                    $plazaRepository,
                    $settings,
                    $eventLog,
                    new Plazas\JugadorMetricasReader( $wpdb, $eventLog )
                );

                ( new Admin\AdminMenu( $bandejaPage ) )->register();
            } );
        }

        // Daily calendar-seeding cron (see Calendario\Cron\SeedCalendarioCron's
        // own class docblock for why this is a schedule and never a
        // save_post listener, and for how its overlap lock works). Registered
        // on every request — cheap, and required so WP-Cron's own request to
        // wp-cron.php (which also boots this plugin via plugins_loaded) can
        // resolve the hook.
        //
        // scheduleCrons() itself runs from the plugin's activation hook (see
        // entre-redes-cambios.php); the wp_next_scheduled() guard below is a
        // safety net for the "plugin files overwritten without going through
        // WordPress's activate flow" case, where no activation hook fires —
        // mirrors entre-redes-prode's own safety net in this exact spot.
        add_action( Calendario\Cron\SeedCalendarioCron::HOOK, [ Calendario\Cron\SeedCalendarioCron::class, 'run' ] );

        if ( false === wp_next_scheduled( Calendario\Cron\SeedCalendarioCron::HOOK ) ) {
            Calendario\Cron\SeedCalendarioCron::schedule();
        }

        load_plugin_textdomain(
            'entre-redes-cambios',
            false,
            dirname( plugin_basename( ENTRE_REDES_CAMBIOS_FILE ) ) . '/languages'
        );
    }

    /**
     * Mark every /entre-redes/v1/cambios/ REST response as uncacheable.
     * Copied and adapted from entre-redes-prode's own
     * Plugin::denyProdeResponseCaching() and entre-redes-credencial's
     * Plugin::denyCredencialResponseCaching() — the mechanism and the
     * reasoning are identical in all three, only the route prefix differs.
     *
     * *** THE INCIDENT, TWICE *** The host runs an nginx proxy cache in
     * front of WordPress that stores GET responses to /wp-json/ keyed by URL
     * ALONE: its `Vary` is `Origin,Accept-Encoding,User-Agent`, it never
     * consults the `Authorization` header, and WordPress sends no
     * `Cache-Control` of its own on these routes. Measured TTL is ~60s.
     * In 2026-09 that served one parent's prode predictions to every other
     * parent for the length of a TTL. In 2026-10 the same cache made
     * "Mis pedidos" omit the request the captain had just filed: the POST
     * created the row, but the GET immediately after was answered from a
     * copy stored BEFORE that row existed, and pull-to-refresh — a request
     * to the identical URL — kept hitting the same stored copy.
     *
     * That second symptom is the mild one. The severe one is that two
     * captains of the SAME team share a URL, so without this header one
     * captain's roster and pending requests can be served to the other, and
     * a response produced for an authenticated caller can be handed to a
     * request carrying no credentials at all.
     *
     * This nginx DOES honour the origin's `Cache-Control` (verified during
     * the 2026-09 remediation: the application-level fix alone was enough,
     * no hosting ticket and no purge), which is why the defence lives here
     * rather than in a server config this repository does not own.
     *
     * Registered on `rest_post_dispatch`. Responses outside the cambios
     * namespace are returned untouched, so the public read-only endpoints
     * stay cacheable.
     *
     * `Vary: Authorization` is appended rather than replaced: WordPress
     * already sets `Vary: Origin` for CORS, and WP_HTTP_Response::header()
     * with $replace = false concatenates instead of overwriting.
     *
     * @param \WP_HTTP_Response|mixed $response Result to send to the client.
     * @param \WP_REST_Server|mixed   $server   Server instance (unused).
     * @param \WP_REST_Request|mixed  $request  Request used to generate the response.
     * @return \WP_HTTP_Response|mixed
     */
    public static function denyCambiosResponseCaching( $response, $server, $request ) {
        // Duck-typed on purpose: rest_post_dispatch is documented to pass a
        // WP_HTTP_Response, but anything carrying header() and get_route()
        // is enough here, and it keeps the filter testable against a shim
        // that does not model WordPress's response class hierarchy.
        if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
            return $response;
        }

        if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
            return $response;
        }

        if ( ! str_starts_with( (string) $request->get_route(), '/' . Rest\RestController::API_NAMESPACE . '/' . Rest\RestController::BASE . '/' ) ) {
            return $response;
        }

        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Vary', 'Authorization', false );

        return $response;
    }
}
