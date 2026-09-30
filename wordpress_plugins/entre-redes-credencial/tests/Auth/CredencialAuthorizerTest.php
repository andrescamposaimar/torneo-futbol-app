<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Auth;

use EntreRedes\Credencial\Auth\CredencialAuthorizer;
use EntreRedes\Credencial\Auth\ProdeSessionGateway;
use EntreRedes\Credencial\Auth\TokenVerifier;
use EntreRedes\Credencial\Tests\Support\IssuesProdeTokens;
use PHPUnit\Framework\TestCase;

/**
 * CredencialAuthorizer composes TokenVerifier + ProdeSessionGateway into a
 * single (user_id, player_id) result, or a WP_Error with the SAME client
 * codes entre-redes-prode's own AuthMiddleware uses (token_missing,
 * token_expired, token_invalid, session_revoked) — see design D1: "App
 * branches on `code`; fail closed". Unlike Capitania\CapitanAuthorizer in
 * entre-redes-cambios, this authorizer does NOT need to hide WHICH reason
 * failed: there is no team-scoped secret to protect here, only "is this a
 * live prode session" — exactly AuthMiddleware's own contract.
 */
class CredencialAuthorizerTest extends TestCase {

    use IssuesProdeTokens;

    private string $privateKeyPem;
    private string $publicKeyPem;
    private CredencialAuthorizer $authorizer;

    protected function setUp(): void {
        $resource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $resource, $privateKeyPem );
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem  = openssl_pkey_get_details( $resource )['key'];

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DROP TABLE IF EXISTS {$p}prode_users" );
        $wpdb->query(
            "CREATE TABLE {$p}prode_users (
                id INTEGER PRIMARY KEY,
                session_version INTEGER NOT NULL
            )"
        );
        $wpdb->insert( $p . 'prode_users', [ 'id' => 42, 'session_version' => 3 ] );

        $this->authorizer = new CredencialAuthorizer(
            new TokenVerifier( $this->publicKeyPem ),
            new ProdeSessionGateway( $wpdb )
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );
    }

    public function test_valid_bearer_header_returns_user_id_and_player_id(): void {
        $jwt    = $this->issueToken();
        $result = $this->authorizer->authorize( 'Bearer ' . $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertIsArray( $result );
        $this->assertSame( 42, $result['user_id'] );
        $this->assertSame( 777, $result['player_id'] );
    }

    public function test_missing_authorization_header_returns_token_missing(): void {
        $result = $this->authorizer->authorize( '', self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_missing', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    public function test_non_bearer_header_returns_token_missing(): void {
        $result = $this->authorizer->authorize( 'Basic dXNlcjpwYXNz', self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_missing', $result->code );
    }

    public function test_expired_token_returns_token_expired(): void {
        $jwt    = $this->issueToken();
        $result = $this->authorizer->authorize( 'Bearer ' . $jwt, self::utc( '2026-09-26 12:15:01' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_expired', $result->code );
        $this->assertSame( 401, $result->data['status'] );
    }

    public function test_malformed_token_returns_token_invalid(): void {
        $result = $this->authorizer->authorize( 'Bearer not-a-jwt', self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_invalid', $result->code );
    }

    public function test_wrong_token_type_returns_token_invalid(): void {
        $jwt    = $this->issueToken( [ 'typ' => 'prode_intent' ] );
        $result = $this->authorizer->authorize( 'Bearer ' . $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'token_invalid', $result->code );
    }

    public function test_revoked_session_returns_session_revoked(): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'prode_users', [ 'session_version' => 4 ], [ 'id' => 42 ] );

        $jwt    = $this->issueToken();
        $result = $this->authorizer->authorize( 'Bearer ' . $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'session_revoked', $result->code );
    }

    public function test_deleted_prode_user_returns_session_revoked(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );

        $jwt    = $this->issueToken();
        $result = $this->authorizer->authorize( 'Bearer ' . $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'session_revoked', $result->code );
    }
}
