<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Capitania;

use EntreRedes\Cambios\Auth\ProdeSessionGateway;
use EntreRedes\Cambios\Auth\TokenVerifier;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Capitania\Exception\NotCaptainException;
use EntreRedes\Cambios\Capitania\Exception\SessionRevokedException;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Tests\Support\IssuesProdeTokens;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for CapitanAuthorizer, composing a real RS256-signed
 * token, a `prode_users` fixture table (see ProdeSessionGatewayTest for why
 * this plugin has to create that fixture itself), and the real
 * cambios_capitan schema.
 */
class CapitanAuthorizerTest extends TestCase {

    use IssuesProdeTokens;

    private const SEASON_ID = 359;
    private const TEAM_A    = 100;
    private const TEAM_B    = 200;
    private const PLAYER_ID = 777;
    private const PRODE_USER_ID = 42;

    private string $privateKeyPem;
    private string $publicKeyPem;
    private CapitanAuthorizer $authorizer;
    private CapitanRepository $capitanRepository;

    protected function setUp(): void {
        $resource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $resource, $privateKeyPem );
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem  = openssl_pkey_get_details( $resource )['key'];

        global $wpdb;

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );
        $wpdb->query(
            "CREATE TABLE {$wpdb->prefix}prode_users (
                id INTEGER PRIMARY KEY,
                session_version INTEGER NOT NULL
            )"
        );
        $wpdb->insert( $wpdb->prefix . 'prode_users', [ 'id' => self::PRODE_USER_ID, 'session_version' => 3 ] );

        InitialSchema::up();
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );

        $this->capitanRepository = new CapitanRepository( $wpdb, new InMemoryEventLog() );
        $this->capitanRepository->designateCapitan( self::SEASON_ID, self::TEAM_A, self::PLAYER_ID, null, '2026-09-01 10:00:00' );

        $this->authorizer = new CapitanAuthorizer(
            new TokenVerifier( $this->publicKeyPem ),
            new ProdeSessionGateway( $wpdb ),
            $this->capitanRepository
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prode_users" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_capitan" );
    }

    public function test_happy_path_returns_the_claims(): void {
        $jwt = $this->issueToken();

        $claims = $this->authorizer->authorize( $jwt, self::SEASON_ID, self::TEAM_A, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertSame( self::PLAYER_ID, $claims['player_id'] );
    }

    public function test_rejects_an_invalid_signature(): void {
        $otherResource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $otherResource, $otherPrivateKeyPem );

        $jwt = $this->issueToken( [], $otherPrivateKeyPem );

        $this->expectException( InvalidTokenException::class );
        $this->authorizer->authorize( $jwt, self::SEASON_ID, self::TEAM_A, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_rejects_a_revoked_session(): void {
        // Simulates SessionManager::revokeAllSessions(): the live counter
        // moved on, but the presented token still carries the old sv=3 —
        // exactly the case ProdeSessionGateway exists to catch.
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'prode_users', [ 'session_version' => 4 ], [ 'id' => self::PRODE_USER_ID ] );

        $jwt = $this->issueToken();

        $this->expectException( SessionRevokedException::class );
        $this->authorizer->authorize( $jwt, self::SEASON_ID, self::TEAM_A, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_rejects_a_player_who_is_not_captain_of_any_team(): void {
        $jwt = $this->issueToken( [ 'player_id' => 999999 ] );

        $this->expectException( NotCaptainException::class );
        $this->authorizer->authorize( $jwt, self::SEASON_ID, self::TEAM_A, self::utc( '2026-09-26 12:05:00' ) );
    }

    /**
     * THE filoso case: this player genuinely captains TEAM_A, but the caller
     * is asking about TEAM_B. Being a valid captain of some team must never
     * be enough — the authorization is scoped to the exact team requested.
     */
    public function test_rejects_the_captain_of_team_a_acting_on_team_b(): void {
        $this->capitanRepository->designateCapitan( self::SEASON_ID, self::TEAM_B, 888888, null, '2026-09-01 10:00:00' );

        $jwt = $this->issueToken(); // still carries PLAYER_ID, captain of TEAM_A only

        $this->expectException( NotCaptainException::class );
        $this->authorizer->authorize( $jwt, self::SEASON_ID, self::TEAM_B, self::utc( '2026-09-26 12:05:00' ) );
    }

    // -------------------------------------------------------------------------
    // verifyIdentity() — establishes WHO, never WHAT they may touch (FIX 1)
    // -------------------------------------------------------------------------

    public function test_verify_identity_returns_the_claims_for_a_valid_non_revoked_token(): void {
        $jwt = $this->issueToken();

        $claims = $this->authorizer->verifyIdentity( $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertSame( self::PLAYER_ID, $claims['player_id'] );
    }

    public function test_verify_identity_rejects_an_invalid_signature(): void {
        $otherResource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $otherResource, $otherPrivateKeyPem );

        $jwt = $this->issueToken( [], $otherPrivateKeyPem );

        $this->expectException( InvalidTokenException::class );
        $this->authorizer->verifyIdentity( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_verify_identity_rejects_a_revoked_session(): void {
        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'prode_users', [ 'session_version' => 4 ], [ 'id' => self::PRODE_USER_ID ] );

        $jwt = $this->issueToken();

        $this->expectException( SessionRevokedException::class );
        $this->authorizer->verifyIdentity( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    /**
     * THE method's own point: it establishes identity WITHOUT checking any
     * captaincy — a player who captains nothing at all still verifies fine.
     */
    public function test_verify_identity_succeeds_for_a_player_who_captains_nothing(): void {
        $jwt = $this->issueToken( [ 'player_id' => 999999 ] );

        $claims = $this->authorizer->verifyIdentity( $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertSame( 999999, $claims['player_id'] );
    }
}
