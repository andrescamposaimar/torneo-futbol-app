<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Auth;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Auth\JwtService;
use EntreRedes\Prode\Auth\SessionManager;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for the session_version revocation guarantee (ADR-P003).
 *
 * Scenario covered:
 *   1. User logs in with sv=1 → token issued
 *   2. Token validates through AuthMiddleware (PASS)
 *   3. revokeAllSessions() → DB sv becomes 2
 *   4. SAME token (sv=1 claim) is now rejected with `session_revoked` + 401
 *   5. New token issued with sv=2 validates again (PASS)
 *
 * Also covers the missing/malformed Authorization header path.
 *
 * Requires PHP 8.0+ with OpenSSL; runs against the SQLite WP shim.
 */
class AuthMiddlewareTest extends TestCase {

    private JwtService $jwt;
    private SessionManager $session;
    private AuthMiddleware $middleware;

    protected function setUp(): void {
        $this->resetOptions();
        $this->provisionKeys();

        \EntreRedes\Prode\Migrations\InitialSchema::up();
        $this->seedTestUser();

        // Reset user state — earlier tests in the suite may have mutated it.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'prode_users',
            [ 'session_version' => 1, 'deleted_at' => null ],
            [ 'id' => 1 ]
        );

        $this->jwt        = new JwtService();
        $this->session    = new SessionManager();
        $this->middleware = new AuthMiddleware( $this->jwt, $this->session );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_refresh_tokens" );
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_valid_token_passes_middleware(): void {
        $token   = $this->jwt->issueAccessToken( 1, 1, 100 );
        $request = $this->buildRequestWithToken( $token );

        $result = $this->middleware->requireAuth( $request );

        $this->assertTrue( $result );
    }

    // -------------------------------------------------------------------------
    // Session_version revocation — the core security guarantee
    // -------------------------------------------------------------------------

    public function test_session_revocation_rejects_stale_token(): void {
        // 1. Issue token with sv=1.
        $token   = $this->jwt->issueAccessToken( 1, 1, 100 );
        $request = $this->buildRequestWithToken( $token );

        // 2. First validation passes.
        $first = $this->middleware->requireAuth( $request );
        $this->assertTrue( $first );

        // 3. Admin unlink (or user account-deletion) bumps session_version.
        $this->session->revokeAllSessions( 1 );
        $this->assertSame( 2, $this->session->getUserSessionVersion( 1 ) );

        // 4. SAME token (still carrying sv=1) is now rejected.
        $second = $this->middleware->requireAuth( $request );
        $this->assertInstanceOf( \WP_Error::class, $second );
        $this->assertSame( 'session_revoked', $second->code );
        $this->assertSame( 401, $second->data['status'] );
    }

    public function test_new_token_after_revocation_passes(): void {
        // Pre-condition: revoke once so sv jumps to 2.
        $this->session->revokeAllSessions( 1 );
        $new_sv = $this->session->getUserSessionVersion( 1 );
        $this->assertSame( 2, $new_sv );

        // Re-issue a token with the new sv.
        $new_token = $this->jwt->issueAccessToken( 1, $new_sv, 100 );
        $request   = $this->buildRequestWithToken( $new_token );

        $this->assertTrue( $this->middleware->requireAuth( $request ) );
    }

    // -------------------------------------------------------------------------
    // Missing / malformed headers
    // -------------------------------------------------------------------------

    public function test_missing_authorization_header_rejected(): void {
        $request = new \WP_REST_Request();

        $result = $this->middleware->requireAuth( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_missing', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    public function test_non_bearer_authorization_header_rejected(): void {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Basic dXNlcjpwYXNz' );

        $result = $this->middleware->requireAuth( $request );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_missing', $result->code );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildRequestWithToken( string $token ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer ' . $token );
        return $request;
    }

    private function resetOptions(): void {
        global $wp_test_options;
        $wp_test_options = [];
    }

    private function provisionKeys(): void {
        if ( ! function_exists( 'openssl_pkey_new' ) ) {
            $this->markTestSkipped( 'OpenSSL extension required for JWT tests.' );
        }

        $key = openssl_pkey_new( [
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );

        openssl_pkey_export( $key, $private_pem );
        $details    = openssl_pkey_get_details( $key );
        $public_pem = $details['key'];

        $salt       = wp_salt( 'auth' );
        $obfuscated = '';
        $len        = strlen( $salt );
        for ( $i = 0, $l = strlen( $private_pem ); $i < $l; $i++ ) {
            $obfuscated .= $private_pem[ $i ] ^ $salt[ $i % $len ];
        }

        update_option( 'prode_rsa_private_key', base64_encode( $obfuscated ) );
        update_option( 'prode_rsa_public_key', $public_pem );
        update_option( 'prode_rsa_key_id', 'test-kid-1' );
        update_option( 'prode_audit_dni_pepper', bin2hex( random_bytes( 16 ) ) );
    }

    private function seedTestUser(): void {
        global $wpdb;

        $exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}prode_users WHERE id = 1"
        );
        if ( $exists > 0 ) {
            return;
        }

        $wpdb->insert(
            $wpdb->prefix . 'prode_users',
            [
                'id'              => 1,
                'tenant_id'       => 'marianista',
                'dni'             => '12345678',
                'email'           => 'test@example.com',
                'provider'        => 'google',
                'provider_id'     => 'google_sub_test',
                'display_name'    => 'Test User',
                'session_version' => 1,
                'created_at'      => current_time( 'mysql' ),
            ]
        );
    }
}
