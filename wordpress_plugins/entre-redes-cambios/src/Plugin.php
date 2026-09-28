<?php

declare(strict_types=1);

namespace EntreRedes\Cambios;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
 *
 * Slice 4d scope: registers the CAPTAIN-facing REST endpoints
 * (Rest\SolicitudesController, Rest\PlazasController) on `rest_api_init`,
 * with manual constructor injection — no container — mirroring exactly how
 * entre-redes-prode's own Plugin::boot() wires its `/prode/*` routes. The
 * process owner's tray (approve/reject/publish the lote) is a later slice's
 * job; nothing here constructs or exposes it.
 *
 * Every service built inside the `rest_api_init` closure is built there and
 * ONLY there — never at the top level of boot() — so a request that never
 * hits the REST API (a cron run, a WP-CLI command) never pays for
 * constructing TokenVerifier, DictamenPipeline, or any of the repositories
 * these endpoints need.
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

            $dictamenContextAssembler = new Dictamen\DictamenContextAssembler(
                $plazaRepository,
                $fechaRepository,
                $settings,
                $wpdb,
                $eventLog
            );

            // CC5b — which of the two BloqueoReemplazoPolicy readings applies
            // — is still pending confirmation from the process owner (see
            // that class's own docblock). Injected explicitly and VISIBLY
            // here, rather than left to DictamenPipeline's own silent
            // default, so the day the process owner answers CC5b, changing
            // the policy is a one-line, reviewable edit in THIS file — never
            // a hidden default someone has to go find first.
            $bloqueoReemplazoPolicy = Dictamen\BloqueoReemplazoPolicy::topeTresFechas();

            $dictamenPipeline = new Dictamen\DictamenPipeline( $dictamenContextAssembler, $eventLog, $bloqueoReemplazoPolicy );

            $solicitudRepository = new Solicitudes\SolicitudRepository(
                $wpdb,
                $plazaRepository,
                $dictamenPipeline,
                $eventLog
            );

            $solicitudesController = new Rest\SolicitudesController(
                $capitanAuthorizer,
                $solicitudRepository,
                $dictamenPipeline,
                $eventLog
            );

            $plazasController = new Rest\PlazasController(
                $capitanAuthorizer,
                $plazaRepository,
                $fechaRepository,
                $eventLog
            );

            ( new Rest\RestController( $solicitudesController, $plazasController ) )->register_routes();
        } );

        load_plugin_textdomain(
            'entre-redes-cambios',
            false,
            dirname( plugin_basename( ENTRE_REDES_CAMBIOS_FILE ) ) . '/languages'
        );
    }
}
