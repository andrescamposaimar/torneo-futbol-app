<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Rest;

use EntreRedes\Prode\Auth\JwtService;
use EntreRedes\Prode\Auth\GoogleVerifier;
use EntreRedes\Prode\Auth\AppleVerifier;
use EntreRedes\Prode\Auth\DniMatcher;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Audit\AuditLogger;

/**
 * Registers and handles the four Prode auth REST endpoints:
 *
 *   POST /entre-redes/v1/prode/auth/google
 *   POST /entre-redes/v1/prode/auth/apple
 *   POST /entre-redes/v1/prode/auth/dni
 *   POST /entre-redes/v1/prode/auth/refresh
 *
 * All responses follow the shape:
 *   Success: JSON body per endpoint contract (spec §3.1 – §3.4)
 *   Error:   { "error": "<code>", "message": "<human>", "details": {...}? }
 *            with appropriate HTTP status code (per ADR-P012).
 */
class AuthEndpoints {

    private const NAMESPACE = 'entre-redes/v1';

    private JwtService     $jwt;
    private GoogleVerifier $google;
    private AppleVerifier  $apple;
    private DniMatcher     $dni_matcher;
    private SessionManager $session;
    private AuditLogger    $audit;

    public function __construct(
        JwtService $jwt,
        GoogleVerifier $google,
        AppleVerifier $apple,
        DniMatcher $dni_matcher,
        SessionManager $session,
        AuditLogger $audit
    ) {
        $this->jwt         = $jwt;
        $this->google      = $google;
        $this->apple       = $apple;
        $this->dni_matcher = $dni_matcher;
        $this->session     = $session;
        $this->audit       = $audit;
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/auth/google',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleGoogle' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id_token' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/prode/auth/apple',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleApple' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'identity_token' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/prode/auth/dni',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleDni' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'intent_token' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                    'dni' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/prode/auth/refresh',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'handleRefresh' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'refresh_token' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * POST /prode/auth/google
     *
     * Verifies a Google ID token. Returns either:
     *   - step: "dni_confirmation" + intent_token  (new user)
     *   - step: "authenticated" + access_token + refresh_token  (returning user)
     */
    public function handleGoogle( \WP_REST_Request $request ): \WP_REST_Response {
        $id_token = (string) $request->get_param( 'id_token' );

        try {
            $claims = $this->google->verify( $id_token );
        } catch ( \InvalidArgumentException $e ) {
            return $this->errorResponse( 'invalid_provider_token', 'Google ID token verification failed.', 401 );
        }

        $provider_id = (string) ( $claims->sub ?? '' );
        $email       = (string) ( $claims->email ?? '' );
        $name_first  = (string) ( $claims->given_name ?? '' );
        $name_last   = (string) ( $claims->family_name ?? '' );

        return $this->handleProviderClaims( 'google', $provider_id, $email, $name_first, $name_last );
    }

    /**
     * POST /prode/auth/apple
     *
     * Verifies an Apple identity token. Same response shape as /auth/google.
     */
    public function handleApple( \WP_REST_Request $request ): \WP_REST_Response {
        $identity_token = (string) $request->get_param( 'identity_token' );

        try {
            $claims = $this->apple->verify( $identity_token );
        } catch ( \InvalidArgumentException $e ) {
            return $this->errorResponse( 'invalid_provider_token', 'Apple identity token verification failed.', 401 );
        }

        $provider_id = (string) ( $claims->sub ?? '' );
        $email       = (string) ( $claims->email ?? '' );
        // Apple may omit given_name / family_name after first login. Extract from
        // `name` claim if present; otherwise use empty strings (caller persists
        // the name from the native credential).
        $name_first  = (string) ( $claims->given_name ?? '' );
        $name_last   = (string) ( $claims->family_name ?? '' );

        return $this->handleProviderClaims( 'apple', $provider_id, $email, $name_first, $name_last );
    }

    /**
     * POST /prode/auth/dni
     *
     * Validates the intent_token from step 1, cross-checks the DNI against
     * the roster, creates the user+association if new, and returns final tokens.
     */
    public function handleDni( \WP_REST_Request $request ): \WP_REST_Response {
        $intent_token = (string) $request->get_param( 'intent_token' );
        $dni          = trim( (string) $request->get_param( 'dni' ) );

        // 1. Verify intent token.
        try {
            $intent = $this->jwt->verifyIntentToken( $intent_token );
        } catch ( \InvalidArgumentException $e ) {
            return $this->errorResponse( 'invalid_intent_token', 'Intent token is invalid or expired.', 401 );
        }

        $provider    = (string) ( $intent->provider ?? '' );
        $provider_id = (string) ( $intent->pid ?? '' );
        $email       = (string) ( $intent->email ?? '' );
        $name_first  = (string) ( $intent->name_first ?? '' );
        $name_last   = (string) ( $intent->name_last ?? '' );
        $display_name = trim( $name_first . ' ' . $name_last );
        if ( '' === $display_name ) {
            $display_name = $email;
        }

        // 2. Check for conflicting association (same DNI, different provider).
        $conflicting_provider = $this->session->findConflictingAssociation( $dni, $provider, $provider_id );
        if ( null !== $conflicting_provider ) {
            $this->audit->logAssociationRejectedAlreadyAssociated(
                $provider,
                $provider_id,
                $dni,
                $conflicting_provider
            );
            return $this->errorResponse(
                'dni_already_associated',
                'This DNI is already linked to a different account.',
                409,
                [ 'other_provider' => $conflicting_provider ]
            );
        }

        // 3. Cross-check DNI against the entre-redes roster.
        $player = $this->dni_matcher->findByDni( $dni );
        if ( null === $player ) {
            $this->audit->logAssociationRejectedDniNotFound( $provider, $provider_id, $dni );
            return $this->errorResponse(
                'dni_not_in_roster',
                'The provided DNI was not found in the player roster.',
                422
            );
        }

        // 4. Create user + association.
        try {
            $result = $this->session->createUserWithAssociation(
                $provider,
                $provider_id,
                $dni,
                $email,
                $display_name,
                $player['player_id']
            );
        } catch ( \InvalidArgumentException $e ) {
            // The uq_tenant_active_dni index caught a concurrent same-DNI race
            // that slipped past the step-2 pre-check. Return the same 409 the
            // pre-check would have returned. (No audit row here: the conflicting
            // provider isn't known on this path, and we won't log a misleading
            // value — the friendly pre-check covers the common case.)
            if ( 'dni_already_associated' === $e->getMessage() ) {
                return $this->errorResponse(
                    'dni_already_associated',
                    'This DNI is already linked to a different account.',
                    409
                );
            }
            return $this->errorResponse( 'server_error', 'Account creation failed.', 500 );
        } catch ( \RuntimeException $e ) {
            return $this->errorResponse( 'server_error', 'Account creation failed.', 500 );
        }

        $user_id         = $result['user_id'];
        $session_version = $result['session_version'];

        // 5. Log successful association.
        $this->audit->logAssociationCreated(
            $provider,
            $provider_id,
            $dni,
            $user_id,
            $player['player_id'],
            $player['player_name']
        );

        // 6. Issue tokens.
        $device_label  = $this->extractDeviceLabel( $request );
        $access_token  = $this->jwt->issueAccessToken( $user_id, $session_version, (int) $player['player_id'] );
        $refresh_token = $this->session->issueRefreshToken( $user_id, $device_label );

        return new \WP_REST_Response(
            [
                'step'          => 'authenticated',
                'access_token'  => $access_token,
                'refresh_token' => $refresh_token,
                'user'          => [
                    'user_id'         => $user_id,
                    'player_id'       => $player['player_id'],
                    'name'            => $display_name,
                    'session_version' => $session_version,
                ],
            ],
            200
        );
    }

    /**
     * POST /prode/auth/refresh
     *
     * Rotates the refresh token. Returns a new access_token + new refresh_token.
     */
    public function handleRefresh( \WP_REST_Request $request ): \WP_REST_Response {
        $plain_token = (string) $request->get_param( 'refresh_token' );

        try {
            $rotation = $this->session->rotateRefreshToken( $plain_token );
        } catch ( \InvalidArgumentException $e ) {
            $code = $e->getMessage();
            return $this->errorResponse( $code, 'Refresh token is invalid or expired.', 401 );
        }

        $user_id = $rotation['user_id'];
        $user    = $this->session->getUser( $user_id );

        if ( null === $user ) {
            return $this->errorResponse( 'session_revoked', 'User account not found.', 401 );
        }

        $session_version = (int) $user['session_version'];

        // Validate session_version consistency (catches the race where an admin
        // unlink happened between the refresh token lookup and now).
        $current_sv = $this->session->getUserSessionVersion( $user_id );
        if ( null === $current_sv || $current_sv !== $session_version ) {
            return $this->errorResponse( 'session_revoked', 'Session has been revoked.', 401 );
        }

        // Update last_login_at.
        $this->session->touchLastLogin( $user_id );

        // Load the active association to get player_id (needed for JWT claim per FR-Auth-05).
        $assoc     = $this->session->getActiveAssociation( $user_id );
        $player_id = $assoc ? (int) $assoc['player_id'] : 0;

        $access_token = $this->jwt->issueAccessToken( $user_id, $session_version, $player_id );
        $new_refresh  = $rotation['token'];

        return new \WP_REST_Response(
            [
                'step'          => 'authenticated',
                'access_token'  => $access_token,
                'refresh_token' => $new_refresh,
                'user'          => [
                    'user_id'         => $user_id,
                    'player_id'       => $player_id,
                    'name'            => (string) ( $user['display_name'] ?? '' ),
                    'session_version' => $session_version,
                ],
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Shared provider flow
    // -------------------------------------------------------------------------

    /**
     * Shared logic for /auth/google and /auth/apple after token verification.
     *
     * - If an active association exists for (provider, provider_id): return tokens.
     * - If no association: return intent_token for DNI confirmation step.
     *
     * @param string $provider    "google" | "apple"
     * @param string $provider_id OAuth sub claim
     * @param string $email
     * @param string $name_first
     * @param string $name_last
     * @return \WP_REST_Response
     */
    private function handleProviderClaims(
        string $provider,
        string $provider_id,
        string $email,
        string $name_first,
        string $name_last
    ): \WP_REST_Response {
        // Check for an existing active association.
        $existing = $this->session->findByProviderAssociation( $provider, $provider_id );

        if ( null !== $existing ) {
            // Returning user — issue tokens directly.
            $user_id         = (int) $existing['user_id'];
            $session_version = (int) $existing['session_version'];

            $this->session->touchLastLogin( $user_id );

            $player_id     = (int) ( $existing['player_id'] ?? 0 );
            $access_token  = $this->jwt->issueAccessToken( $user_id, $session_version, $player_id );
            $refresh_token = $this->session->issueRefreshToken( $user_id );

            return new \WP_REST_Response(
                [
                    'step'          => 'authenticated',
                    'access_token'  => $access_token,
                    'refresh_token' => $refresh_token,
                    'user'          => [
                        'user_id'         => $user_id,
                        'player_id'       => (int) ( $existing['player_id'] ?? 0 ),
                        'name'            => (string) ( $existing['display_name'] ?? '' ),
                        'session_version' => $session_version,
                    ],
                ],
                200
            );
        }

        // New user — issue intent token for DNI confirmation.
        $intent_token = $this->jwt->issueIntentToken(
            $provider,
            $provider_id,
            $email,
            $name_first,
            $name_last
        );

        return new \WP_REST_Response(
            [
                'step'         => 'dni_confirmation',
                'intent_token' => $intent_token,
                'profile'      => [
                    'name_first' => $name_first,
                    'name_last'  => $name_last,
                    'email'      => $email,
                ],
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts a human-readable device label from the User-Agent header.
     * Used as a hint for refresh token records.
     */
    private function extractDeviceLabel( \WP_REST_Request $request ): string {
        $ua = (string) ( $request->get_header( 'user-agent' ) ?? '' );
        return substr( $ua, 0, 120 );
    }

    /**
     * Returns a uniform JSON error response.
     *
     * @param string               $error   Machine-readable error code.
     * @param string               $message Human-readable message.
     * @param int                  $status  HTTP status code.
     * @param array<string, mixed> $details Optional additional details.
     * @return \WP_REST_Response
     */
    private function errorResponse(
        string $error,
        string $message,
        int $status,
        array $details = []
    ): \WP_REST_Response {
        $body = [ 'error' => $error, 'message' => $message ];
        if ( ! empty( $details ) ) {
            $body['details'] = $details;
        }
        return new \WP_REST_Response( $body, $status );
    }
}
