<?php

declare(strict_types=1);

namespace EntreRedes\Credencial;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
 *
 * Registers every /entre-redes/v1/credencial/* route on `rest_api_init`,
 * with manual constructor injection, no container, mirroring exactly how
 * entre-redes-cambios's own Plugin::boot() wires its `/cambios/*` routes: GET
 * /credencial/credencial (Rest\CredencialController, slice 1b) and POST
 * /credencial/foto (Rest\PhotoUploadController, slice 2a).
 *
 * Every service this closure needs is built here and ONLY here — never at
 * the top level of boot() — so a request that never hits the REST surface
 * (a cron run, an admin page load) never pays for constructing
 * TokenVerifier, PlayerReader, or IssuanceRepository.
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

        // Every /entre-redes/v1/credencial/ response is caller-specific
        // (photo, DNI, rotating code seed) — see design D2 and
        // denyCredencialResponseCaching()'s own docblock for the production
        // incident (entre-redes-prode, 2026-09-07) this pattern defends
        // against. Registered here, on every request, BEFORE the route
        // closure below, so the route is protected from the moment it exists.
        add_filter( 'rest_post_dispatch', [ self::class, 'denyCredencialResponseCaching' ], 10, 3 );

        add_action( 'rest_api_init', static function (): void {
            global $wpdb;

            $eventLog = new Observability\WpEventLog();

            // Same auth composition as entre-redes-cambios's
            // Capitania\CapitanAuthorizer — see Auth\CredencialAuthorizer's
            // own docblock (design D1) for why this plugin copies rather than
            // depends on prode's classes.
            $tokenVerifier  = new Auth\TokenVerifier( (string) get_option( 'prode_rsa_public_key', '' ) );
            $sessionGateway = new Auth\ProdeSessionGateway( $wpdb );
            $authorizer     = new Auth\CredencialAuthorizer( $tokenVerifier, $sessionGateway );

            $playerReader              = new Player\PlayerReader();
            $teamResolver              = new Player\EntreRedesApiTeamResolver();
            $issuanceRepository        = new Credencial\IssuanceRepository( $wpdb, $eventLog );
            $approvalRequestRepository = new Approval\ApprovalRequestRepository( $wpdb, $eventLog );

            // credencial_code_secret is generated once, on activation — see
            // Migrations\MigrationRunner::generateCodeSecret(). Read here,
            // never cached across requests, exactly like prode_rsa_public_key
            // above.
            $codeSecret = (string) get_option( 'credencial_code_secret', '' );

            $credencialService = new Credencial\CredencialService(
                $playerReader,
                $teamResolver,
                $issuanceRepository,
                $approvalRequestRepository,
                $codeSecret
            );

            $credencialController = new Rest\CredencialController( $authorizer, $credencialService, $eventLog );

            $photoUploadController = new Rest\PhotoUploadController(
                $authorizer,
                $playerReader,
                new Photo\UploadBodyReader(),
                new Photo\PhotoValidator(),
                new Photo\MemoryGuard(),
                new Photo\GdPhotoReencoder(),
                $approvalRequestRepository,
                $eventLog
            );

            ( new Rest\RestController( $credencialController, $photoUploadController ) )->register_routes();
        } );

        // "Credenciales > Fotos pendientes" (design D12) — only in wp-admin,
        // same is_admin() gate as entre-redes-prode/entre-redes-cambios's own
        // Plugin::boot(), so a plain front-end or REST request never pays for
        // constructing the admin page or its collaborators.
        if ( is_admin() ) {
            add_action( 'admin_menu', static function (): void {
                global $wpdb;

                $eventLog      = new Observability\WpEventLog();
                $requests      = new Approval\ApprovalRequestRepository( $wpdb, $eventLog );
                $media         = new Photo\WpMediaWriter();
                $reviewService = new Approval\ApprovalReviewService( $requests, $media, $eventLog );
                $pendingPhotos = new Admin\PendingPhotosPage( $requests, $reviewService, $media );

                ( new Admin\AdminMenu( $pendingPhotos ) )->register();
            } );
        }

        load_plugin_textdomain(
            'entre-redes-credencial',
            false,
            dirname( plugin_basename( ENTRE_REDES_CREDENCIAL_FILE ) ) . '/languages'
        );
    }

    /**
     * Mark every /entre-redes/v1/credencial/ REST response as uncacheable.
     * Copied and adapted from entre-redes-prode's own
     * Plugin::denyProdeResponseCaching() — see that method's docblock for
     * the full incident writeup; the mechanism and the reasoning are
     * identical here, only the route prefix differs.
     *
     * Registered on `rest_post_dispatch`. Responses outside the credencial
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
    public static function denyCredencialResponseCaching( $response, $server, $request ) {
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

        if ( ! str_starts_with( (string) $request->get_route(), '/entre-redes/v1/credencial/' ) ) {
            return $response;
        }

        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
        $response->header( 'Pragma', 'no-cache' );
        $response->header( 'Vary', 'Authorization', false );

        return $response;
    }
}
