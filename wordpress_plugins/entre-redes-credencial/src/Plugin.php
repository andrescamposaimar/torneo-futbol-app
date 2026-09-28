<?php

declare(strict_types=1);

namespace EntreRedes\Credencial;

/**
 * Main plugin class — wires all hooks and bootstraps subsystems.
 *
 * Slice 1a scope: only the cache-deny filter is wired here. No REST route
 * exists yet (that is slice 1b's GET /credencial and slice 2a's POST
 * /credencial/foto) — CredencialAuthorizer, TokenVerifier, ProdeSessionGateway
 * and the migrations all run, and are unit-tested, but nothing is registered
 * on `rest_api_init` until a later slice actually needs to build a
 * controller from them. Every service a future slice needs will be built
 * inside its own `rest_api_init` closure, and ONLY there — never at the top
 * level of boot() — mirroring exactly how entre-redes-cambios's own
 * Plugin::boot() defers construction to the hook closures.
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
        // against. Registered here, on every request, even before any
        // /credencial/ route exists, so the FIRST route a later slice adds
        // is protected from day one — nobody has to remember to wire this
        // filter again when slice 1b lands.
        add_filter( 'rest_post_dispatch', [ self::class, 'denyCredencialResponseCaching' ], 10, 3 );

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
