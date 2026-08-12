<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

/**
 * Registers all /entre-redes/v1/prode/* REST routes.
 *
 * PR-01 scope: healthcheck + JWKS endpoints.
 * PR-02 scope: auth endpoints (google, apple, dni, refresh) wired here.
 * PR-03 scope: account deletion endpoint wired here.
 * Game, admin, and push endpoints from PR-04+ are added in later PRs.
 */
class RestController {

    private const NAMESPACE = 'entre-redes/v1';
    private const BASE      = 'prode';

    private ?\EntreRedes\Prode\Rest\AuthEndpoints         $auth_endpoints;
    private ?\EntreRedes\Prode\Account\AccountController  $account_controller;
    private ?\EntreRedes\Prode\Rest\FechaController       $fecha_controller;
    private ?\EntreRedes\Prode\Rest\FechaListController   $fecha_list_controller;
    private ?\EntreRedes\Prode\Rest\PredictionController  $prediction_controller;
    private ?\EntreRedes\Prode\Rest\EvaluationController  $evaluation_controller;
    private ?\EntreRedes\Prode\Rest\RankingController     $ranking_controller;
    private ?\EntreRedes\Prode\Rest\PredictionHistoryController $prediction_history_controller;
    private ?\EntreRedes\Prode\Rest\PopularesController      $populares_controller;

    public function __construct(
        ?\EntreRedes\Prode\Rest\AuthEndpoints $auth_endpoints = null,
        ?\EntreRedes\Prode\Account\AccountController $account_controller = null,
        ?\EntreRedes\Prode\Rest\FechaController $fecha_controller = null,
        ?\EntreRedes\Prode\Rest\PredictionController $prediction_controller = null,
        ?\EntreRedes\Prode\Rest\EvaluationController $evaluation_controller = null,
        ?\EntreRedes\Prode\Rest\RankingController $ranking_controller = null,
        ?\EntreRedes\Prode\Rest\FechaListController $fecha_list_controller = null,
        ?\EntreRedes\Prode\Rest\PredictionHistoryController $prediction_history_controller = null,
        ?\EntreRedes\Prode\Rest\PopularesController $populares_controller = null
    ) {
        $this->auth_endpoints        = $auth_endpoints;
        $this->account_controller    = $account_controller;
        $this->fecha_controller      = $fecha_controller;
        $this->fecha_list_controller = $fecha_list_controller;
        $this->prediction_controller = $prediction_controller;
        $this->evaluation_controller = $evaluation_controller;
        $this->ranking_controller    = $ranking_controller;
        $this->prediction_history_controller = $prediction_history_controller;
        $this->populares_controller  = $populares_controller;
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
        // Skipped when the Composer dependencies are absent: every route here
        // issues a signed token, so without firebase/php-jwt and ramsey/uuid
        // they can only fail. A 404 the operator can see beats a fatal the user
        // sees. (Undefined — e.g. in unit tests, which load classes directly
        // rather than through the plugin bootstrap — counts as available.)
        $deps_ok = ! defined( 'ENTRE_REDES_PRODE_DEPS_OK' ) || ENTRE_REDES_PRODE_DEPS_OK;

        if ( null !== $this->auth_endpoints && $deps_ok ) {
            $this->auth_endpoints->register_routes();
        }

        // Account endpoints (PR-03): DELETE /prode/account.
        if ( null !== $this->account_controller ) {
            $this->account_controller->register_routes();
        }

        // Fecha endpoints (PR-G0-C): GET /prode/fecha-activa.
        if ( null !== $this->fecha_controller ) {
            $this->fecha_controller->register_routes();
        }

        // Prediction endpoints (PR-G2-A2): POST /prode/prediccion.
        if ( null !== $this->prediction_controller ) {
            $this->prediction_controller->register_routes();
        }

        // Evaluation endpoints (PR-G3-C): POST /prode/evaluar-fecha.
        if ( null !== $this->evaluation_controller ) {
            $this->evaluation_controller->register_routes();
        }

        // Ranking endpoints (PR-G4-C): GET /prode/ranking.
        if ( null !== $this->ranking_controller ) {
            $this->ranking_controller->register_routes();
        }

        // Fecha list endpoints (PR-G6-B): GET /prode/fechas, GET /prode/fecha/{id}.
        if ( null !== $this->fecha_list_controller ) {
            $this->fecha_list_controller->register_routes();
        }

        // Prediction history endpoint: GET /prode/predicciones (paginated past predictions).
        if ( null !== $this->prediction_history_controller ) {
            $this->prediction_history_controller->register_routes();
        }

        // Populares endpoint: GET /prode/populares (prediction split for one match).
        if ( null !== $this->populares_controller ) {
            $this->populares_controller->register_routes();
        }
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /wp-json/entre-redes/v1/prode/healthcheck
     *
     * Returns:
     *   { status: "ok"|"degraded", plugin: "entre-redes-prode", version: "...",
     *     tenant_id: "...", deps: "ok"|"missing", signing: "ok"|"fail" }
     *
     * `signing` is the load-bearing field: it round-trips a real throwaway JWT
     * through the private and public keys, which is the exact chain every login
     * depends on. A healthcheck that only proves "PHP ran and the route exists"
     * reports ok while sign-in is completely dead — that happened, and it cost a
     * full afternoon of diagnosis. When signing fails the endpoint answers 503
     * so uptime monitors treat it as the outage it is (nothing in the mobile app
     * reads this route, so the status code is free to be honest).
     */
    public function healthcheck( \WP_REST_Request $request ): \WP_REST_Response {
        $deps_ok = ! defined( 'ENTRE_REDES_PRODE_DEPS_OK' ) || ENTRE_REDES_PRODE_DEPS_OK;

        $signing_ok = false;
        try {
            ( new \EntreRedes\Prode\Auth\JwtService() )->selfTest();
            $signing_ok = true;
        } catch ( \Throwable $e ) {
            // Detail goes to the server log only — the reason names internal
            // key/config state and this endpoint is public and unauthenticated.
            error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                sprintf(
                    '[entre-redes-prode] healthcheck signing self-test failed: %s: %s',
                    get_class( $e ),
                    $e->getMessage()
                )
            );
        }

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

        $healthy = $deps_ok && $signing_ok;

        return new \WP_REST_Response(
            [
                'status'    => $healthy ? 'ok' : 'degraded',
                'plugin'    => 'entre-redes-prode',
                'version'   => ENTRE_REDES_PRODE_VERSION,
                'tenant_id' => $tenant_id,
                'deps'      => $deps_ok ? 'ok' : 'missing',
                'signing'   => $signing_ok ? 'ok' : 'fail',
            ],
            $healthy ? 200 : 503
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
