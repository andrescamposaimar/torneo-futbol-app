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

        // Schema upgrades must land on a plain zip replace, not only on a
        // click of "Activate" — see MigrationRunner::runIfOutdated()'s own
        // docblock for the incident this guards against.
        Migrations\MigrationRunner::runIfOutdated( new Observability\WpEventLog() );

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
            $settings        = new Calendario\Settings( $wpdb );
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
                $prioridadPadresActiva
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
                $jugadorMetricasReader
            );

            $candidatosResolver = new Plazas\CandidatosResolver( $wpdb, $plazaRepository, $eventLog );
            $listaEsperaResolver = new Plazas\ListaEsperaResolver( $wpdb, $settings, $eventLog );

            $plazasController = new Rest\PlazasController(
                $capitanAuthorizer,
                $plazaRepository,
                $fechaRepository,
                $eventLog,
                $candidatosResolver,
                $bloqueoReemplazoPolicy,
                null,
                $listaEsperaResolver
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
            add_action( 'admin_menu', static function (): void {
                global $wpdb;

                $eventLog        = new Observability\WpEventLog();
                $authorizer      = new Admin\ProcessOwnerAuthorizer();
                $plazaRepository = new Plazas\PlazaRepository( $wpdb, $eventLog );
                $fechaRepository = new Calendario\FechaRepository( $wpdb, $eventLog );
                $settings        = new Calendario\Settings( $wpdb );

                $bloqueoReemplazoPolicy = Dictamen\BloqueoReemplazoPolicy::topeTresFechas();
                $prioridadPadresActiva  = $settings->prioridadPadresActiva();

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
                    $prioridadPadresActiva
                );

                $solicitudRepository = new Solicitudes\SolicitudRepository(
                    $wpdb,
                    $plazaRepository,
                    $dictamenPipeline,
                    $eventLog
                );

                $bandejaPage = new Admin\BandejaPage( $authorizer, $solicitudRepository, $plazaRepository, $settings, $eventLog );

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
}
