<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Auth;

use EntreRedes\Credencial\Auth\Exception\TokenExpiredException;
use EntreRedes\Credencial\Auth\Exception\TokenMalformedException;
use EntreRedes\Credencial\Auth\Exception\TokenNotYetValidException;
use EntreRedes\Credencial\Auth\Exception\TokenSignatureInvalidException;
use EntreRedes\Credencial\Auth\Exception\TokenVerificationException;
use EntreRedes\Credencial\Auth\Exception\TokenWrongTypeException;
use EntreRedes\Credencial\Auth\TokenVerifier;
use EntreRedes\Credencial\Tests\Support\IssuesProdeTokens;
use PHPUnit\Framework\TestCase;

/**
 * Copied and adapted from entre-redes-cambios/tests/Auth/TokenVerifierTest.php.
 * TokenVerifier is deliberately WordPress-free (public key and clock are
 * both injected), so these tests sign real RS256 tokens with a throwaway
 * keypair generated fresh in setUp().
 */
class TokenVerifierTest extends TestCase {

    use IssuesProdeTokens;

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
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:15:01' ) );
    }

    public function test_token_valid_at_the_exact_exp_boundary_is_still_rejected(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $this->expectException( TokenExpiredException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:15:00' ) );
    }

    public function test_token_valid_one_second_before_the_exp_boundary_is_accepted(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken();

        $claims = $verifier->verify( $jwt, self::utc( '2026-09-26 12:14:59' ) );

        $this->assertSame( 777, $claims['player_id'] );
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
        $parts[2]     = strrev( $parts[2] );
        $tamperedJwt  = implode( '.', $parts );

        $this->expectException( TokenSignatureInvalidException::class );
        $verifier->verify( $tamperedJwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_a_token_with_a_future_nbf_is_rejected_as_not_yet_valid(): void {
        $verifier = new TokenVerifier( $this->publicKeyPem );
        $jwt       = $this->issueToken( [ 'nbf' => strtotime( '2026-09-26 13:00:00 UTC' ) ] );

        $this->expectException( TokenNotYetValidException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_an_empty_public_key_fails_closed(): void {
        $verifier = new TokenVerifier( '' );
        $jwt       = $this->issueToken();

        $this->expectException( TokenVerificationException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }

    public function test_a_corrupt_public_key_fails_closed(): void {
        $corruptPem = "-----BEGIN PUBLIC KEY-----\n" . base64_encode( 'not actually a key' ) . "\n-----END PUBLIC KEY-----";

        $verifier = new TokenVerifier( $corruptPem );
        $jwt       = $this->issueToken();

        $this->expectException( TokenVerificationException::class );
        $verifier->verify( $jwt, self::utc( '2026-09-26 12:05:00' ) );
    }
}
