<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Auth;

use EntreRedes\Cambios\Auth\Exception\TokenExpiredException;
use EntreRedes\Cambios\Auth\Exception\TokenMalformedException;
use EntreRedes\Cambios\Auth\Exception\TokenSignatureInvalidException;
use EntreRedes\Cambios\Auth\Exception\TokenWrongTypeException;
use EntreRedes\Cambios\Auth\TokenVerifier;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/**
 * TokenVerifier is deliberately WordPress-free (public key and clock are
 * both injected), so these tests sign real RS256 tokens with a throwaway
 * keypair generated fresh in setUp() — never a hardcoded key — mirroring
 * what entre-redes-prode's JwtService actually produces.
 */
class TokenVerifierTest extends TestCase {

    /**
     * A fixed instant as a Unix epoch, which is what TokenVerifier takes.
     *
     * The signature uses an epoch on purpose: `exp` in a JWT is an epoch, so
     * comparing epoch to epoch leaves no timezone to misread. Tests spell the
     * instant out in UTC here only for readability.
     */
    private static function utc( string $utcDatetime ): int {
        return ( new \DateTimeImmutable( $utcDatetime, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    private string $privateKeyPem;
    private string $publicKeyPem;

    protected function setUp(): void {
        $resource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        $this->assertNotFalse( $resource, 'openssl_pkey_new() failed — is the openssl extension loaded?' );

        openssl_pkey_export( $resource, $privateKeyPem );
        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem  = openssl_pkey_get_details( $resource )['key'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueToken( array $overrides = [], ?string $signingKey = null ): string {
        $payload = array_merge(
            [
                'iss'       => 'http://example.com/wp-json/entre-redes/v1/prode',
                'aud'       => 'tenant-1',
                'sub'       => '42',
                'typ'       => 'prode_access',
                'sv'        => 3,
                'player_id' => 777,
                'iat'       => strtotime( '2026-09-26 12:00:00 UTC' ),
                'exp'       => strtotime( '2026-09-26 12:15:00 UTC' ),
            ],
            $overrides
        );

        return JWT::encode( $payload, $signingKey ?? $this->privateKeyPem, 'RS256', 'test-kid' );
    }

    public function test_valid_token_returns_claims(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $claims = $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );

        $this->assertSame( '42', $claims['sub'] );
        $this->assertSame( 3, $claims['sv'] );
        $this->assertSame( 777, $claims['player_id'] );
        $this->assertSame( 'prode_access', $claims['typ'] );
    }

    public function test_signature_signed_with_another_key_is_rejected(): void {
        $otherKeyResource = openssl_pkey_new( [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ] );
        openssl_pkey_export( $otherKeyResource, $otherPrivateKeyPem );

        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken( [], $otherPrivateKeyPem );

        $this->expectException( TokenSignatureInvalidException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_expired_token_is_rejected_according_to_injected_now(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $this->expectException( TokenExpiredException::class );
        // One second past the token's exp claim.
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:15:01' ) );
    }

    public function test_token_valid_at_the_exact_exp_boundary_is_still_rejected(): void {
        // firebase/php-jwt rejects when timestamp >= exp, matching JwtService's
        // own "15 minutes exactly" contract — this pins that boundary.
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $this->expectException( TokenExpiredException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:15:00' ) );
    }

    public function test_wrong_typ_is_rejected(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken( [ 'typ' => 'prode_intent' ] );

        $this->expectException( TokenWrongTypeException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_corrupt_token_is_rejected_as_malformed(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );

        $this->expectException( TokenMalformedException::class );
        $verifier->verify( 'this-is-not-a-jwt-at-all', self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_a_valid_looking_token_with_a_tampered_signature_segment_is_rejected(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $parts        = explode( '.', $jwt );
        $parts[2]     = strrev( $parts[2] ); // mangle the signature, keep header/payload valid base64
        $tamperedJwt  = implode( '.', $parts );

        $this->expectException( TokenSignatureInvalidException::class );
        $verifier->verify( $tamperedJwt, self::utc( '2026-09-26 12:05:00' ) );
    }
}
