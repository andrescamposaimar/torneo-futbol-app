<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

/**
 * Registers all /entre-redes/v1/prode/* REST routes.
 *
 * PR-01 scope: healthcheck + JWKS endpoints.
 * PR-02 scope: auth endpoints (google, apple, dni, refresh) wired here.
 * Auth, game, and account endpoints from PR-03+ are added in later PRs.
 */
class RestController {

    private const NAMESPACE = 'entre-redes/v1';
    private const BASE      = 'prode';

    private ?\EntreRedes\Prode\Rest\AuthEndpoints $auth_endpoints;

    public function __construct( ?\EntreRedes\Prode\Rest\AuthEndpoints $auth_endpoints = null ) {
        $this->auth_endpoints = $auth_endpoints;
    }

    public function register_routes(): void {
        // Health check — no auth required. Proves the plugin is alive and
        // tenant configuration is correct.
        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/healthcheck',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'healthcheck' ],
                'permission_callback' => '__return_true',
            ]
        );

        // JWKS endpoint — public key for RS256 token verification.
        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/.well-known/jwks.json',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'jwks' ],
                'permission_callback' => '__return_true',
            ]
        );

        // Auth endpoints (PR-02): google, apple, dni, refresh.
        if ( null !== $this->auth_endpoints ) {
            $this->auth_endpoints->register_routes();
        }
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /wp-json/entre-redes/v1/prode/healthcheck
     *
     * Returns:
     *   { status: "ok", plugin: "entre-redes-prode", version: "0.1.0", tenant_id: "..." }
     */
    public function healthcheck( \WP_REST_Request $request ): \WP_REST_Response {
        $tenant_id = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';

        // Double-check: also read from settings table in case wp-config was
        // modified after activation.
        if ( '' === $tenant_id ) {
            global $wpdb;
            $tenant_id = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "SELECT setting_value FROM {$wpdb->prefix}prode_settings WHERE setting_key = %s",
                    'tenant_id'
                )
            );
        }

        return new \WP_REST_Response(
            [
                'status'    => 'ok',
                'plugin'    => 'entre-redes-prode',
                'version'   => ENTRE_REDES_PRODE_VERSION,
                'tenant_id' => $tenant_id,
            ],
            200
        );
    }

    /**
     * GET /wp-json/entre-redes/v1/prode/.well-known/jwks.json
     *
     * Returns the RS256 public key in JWK format so that future verifiers
     * (and operators running curl smoke tests) can confirm key provisioning.
     */
    public function jwks( \WP_REST_Request $request ): \WP_REST_Response {
        $public_pem = get_option( 'prode_rsa_public_key', '' );
        $kid        = get_option( 'prode_rsa_key_id', '' );

        if ( '' === $public_pem ) {
            return new \WP_REST_Response(
                [
                    'error'   => 'keys_not_provisioned',
                    'message' => 'RSA key pair not generated yet. Please deactivate and reactivate the plugin.',
                ],
                503
            );
        }

        $jwk = self::pemToJwk( $public_pem, $kid );

        return new \WP_REST_Response(
            [ 'keys' => [ $jwk ] ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Converts a PEM-encoded RSA public key to a JWK array.
     *
     * Returns the minimal set of JWK fields required for RS256 verification:
     * kty, use, alg, kid, n (modulus), e (exponent).
     */
    private static function pemToJwk( string $pem, string $kid ): array {
        if ( ! function_exists( 'openssl_pkey_get_public' ) ) {
            return [ 'error' => 'openssl_not_available' ];
        }

        $key = openssl_pkey_get_public( $pem );
        if ( ! $key ) {
            return [ 'error' => 'invalid_public_key' ];
        }

        $details = openssl_pkey_get_details( $key );
        if ( ! isset( $details['rsa'] ) ) {
            return [ 'error' => 'not_rsa_key' ];
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => self::base64UrlEncode( $details['rsa']['n'] ),
            'e'   => self::base64UrlEncode( $details['rsa']['e'] ),
        ];
    }

    private static function base64UrlEncode( string $data ): string {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }
}
