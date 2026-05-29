<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

use stdClass;

/**
 * Authentication middleware for Prode REST endpoints.
 *
 * Provides a reusable permission_callback and a token-extraction helper
 * for use across all authenticated /prode/* endpoints.
 *
 * Session_version validation (ADR-P003):
 *   Every authenticated request:
 *     1. Decodes the JWT access token.
 *     2. Loads the prode_users row.
 *     3. Compares JWT `sv` claim against prode_users.session_version.
 *     4. If mismatch → 401 session_revoked.
 *
 * The decoded token and the loaded user row are attached to the WP_REST_Request
 * as private attributes so endpoint handlers don't need to re-fetch them.
 *
 * Usage in endpoint registration:
 *
 *   'permission_callback' => [ $this->middleware, 'requireAuth' ]
 *
 * Then in the handler:
 *
 *   $user    = $request->get_param( '_prode_user' );  // array from prode_users
 *   $decoded = $request->get_param( '_prode_token' ); // stdClass JWT claims
 */
class AuthMiddleware {

    private JwtService $jwt;
    private SessionManager $session;

    public function __construct( JwtService $jwt, SessionManager $session ) {
        $this->jwt     = $jwt;
        $this->session = $session;
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    /**
     * Verifies the Bearer access token on the request.
     *
     * Returns true if the token is valid and session_version matches.
     * Returns a WP_Error with the appropriate HTTP status otherwise.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public function requireAuth( \WP_REST_Request $request ) {
        $result = $this->validateToken( $request );

        if ( $result instanceof \WP_Error ) {
            return $result;
        }

        // Attach decoded token and user to the request for handlers.
        $request->set_param( '_prode_token', $result['decoded'] );
        $request->set_param( '_prode_user', $result['user'] );

        return true;
    }

    /**
     * Optional auth: validates the token if present, but does NOT reject the
     * request if no token is provided. Used for endpoints that show different
     * data to authenticated vs anonymous users.
     *
     * @param \WP_REST_Request $request
     * @return true Always returns true; token check result is attached as params.
     */
    public function optionalAuth( \WP_REST_Request $request ): bool {
        $auth_header = $request->get_header( 'authorization' );
        if ( empty( $auth_header ) || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
            return true; // Anonymous request — allowed.
        }

        $result = $this->validateToken( $request );
        if ( ! is_wp_error( $result ) ) {
            $request->set_param( '_prode_token', $result['decoded'] );
            $request->set_param( '_prode_user', $result['user'] );
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Internal validation
    // -------------------------------------------------------------------------

    /**
     * Extracts and validates the Bearer token from the Authorization header.
     *
     * @param \WP_REST_Request $request
     * @return array{decoded: stdClass, user: array<string,mixed>}|\WP_Error
     */
    private function validateToken( \WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'authorization' );
        if ( empty( $auth_header ) || ! str_starts_with( $auth_header, 'Bearer ' ) ) {
            return new \WP_Error(
                'token_missing',
                'Authorization header with Bearer token is required.',
                [ 'status' => 401 ]
            );
        }

        $token = substr( $auth_header, 7 );

        try {
            $decoded = $this->jwt->verifyAccessToken( $token );
        } catch ( \InvalidArgumentException $e ) {
            $code = $e->getMessage();
            // Map internal error codes to client-facing codes.
            $client_code = match ( $code ) {
                'token_expired'  => 'token_expired',
                default          => 'token_invalid',
            };
            return new \WP_Error(
                $client_code,
                'Invalid or expired access token.',
                [ 'status' => 401 ]
            );
        }

        $user_id = (int) ( $decoded->sub ?? 0 );
        if ( 0 === $user_id ) {
            return new \WP_Error( 'token_invalid', 'Token sub claim is missing.', [ 'status' => 401 ] );
        }

        // Load user and validate session_version (ADR-P003).
        $user = $this->session->getUser( $user_id );
        if ( null === $user ) {
            return new \WP_Error( 'session_revoked', 'User account not found or deleted.', [ 'status' => 401 ] );
        }

        $token_sv = (int) ( $decoded->sv ?? -1 );
        if ( $token_sv !== (int) $user['session_version'] ) {
            return new \WP_Error( 'session_revoked', 'Session has been revoked.', [ 'status' => 401 ] );
        }

        return [ 'decoded' => $decoded, 'user' => $user ];
    }
}
