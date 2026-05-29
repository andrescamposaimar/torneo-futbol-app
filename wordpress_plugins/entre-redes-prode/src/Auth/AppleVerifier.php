<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

use stdClass;

/**
 * Verifies an Apple identity_token received from the Flutter app.
 *
 * Verification steps (per Apple OIDC spec / Sign in with Apple docs):
 *  1. Fetch Apple's JWKS from https://appleid.apple.com/auth/keys
 *     (cached in WP transients for 1 hour).
 *  2. Decode the token header to get `kid`.
 *  3. Find the matching JWK. On kid-miss: refresh the cache ONCE and retry.
 *     This is the R13 mitigation — Apple rotates its keys periodically, and a
 *     cached JWKS may be stale at the moment of rotation.
 *  4. Verify the RS256 signature.
 *  5. Validate claims: iss (https://appleid.apple.com), aud, exp.
 *  6. Return decoded payload. Caller extracts `sub` (Apple's stable user ID),
 *     `email` (only on first login; may be null on subsequent logins), etc.
 *
 * Note on Apple email: Apple only returns the user's email on the FIRST
 * authorization. The Flutter app should persist the email from the native
 * Sign in with Apple credential and pass it separately if needed. The
 * identity_token payload always includes `sub`.
 */
class AppleVerifier {

    private const JWKS_URL       = 'https://appleid.apple.com/auth/keys';
    private const JWKS_TRANSIENT  = 'prode_apple_jwks';
    private const JWKS_TTL        = HOUR_IN_SECONDS;
    private const VALID_ISSUER    = 'https://appleid.apple.com';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Verifies an Apple identity token and returns its decoded claims.
     *
     * @param string $identity_token Raw identity token from Sign in with Apple SDK.
     * @return stdClass Decoded JWT claims (sub, email, aud, iss, exp, iat).
     * @throws \InvalidArgumentException On any verification failure.
     */
    public function verify( string $identity_token ): stdClass {
        // 1. Decode header to get kid.
        $header = $this->decodeHeader( $identity_token );
        $kid    = $header->kid ?? '';

        // 2. Fetch JWKS (cached).
        $jwks = $this->fetchJwks();

        // 3. Locate JWK by kid — with refresh-on-miss (R13 mitigation).
        $jwk = $this->findJwk( $jwks, $kid );
        if ( null === $jwk ) {
            // Clear cache and refresh once; Apple may have rotated keys.
            delete_transient( self::JWKS_TRANSIENT );
            $jwks = $this->fetchJwks( true );
            $jwk  = $this->findJwk( $jwks, $kid );
            if ( null === $jwk ) {
                throw new \InvalidArgumentException( 'invalid_provider_token' );
            }
        }

        // 4. Build PEM and verify.
        $public_pem = $this->jwkToPem( $jwk );
        return $this->decodeAndValidate( $identity_token, $public_pem );
    }

    // -------------------------------------------------------------------------
    // JWKS handling
    // -------------------------------------------------------------------------

    /**
     * Returns cached JWKS or fetches from Apple.
     *
     * @param bool $force_refresh Bypass transient cache.
     * @return array<mixed> Array of JWK objects.
     * @throws \InvalidArgumentException If the remote fetch fails.
     */
    private function fetchJwks( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::JWKS_TRANSIENT );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $response = wp_remote_get( self::JWKS_URL, [
            'timeout'    => 10,
            'user-agent' => 'EntreRedesProde/' . ENTRE_REDES_PRODE_VERSION,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== (int) $code ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        set_transient( self::JWKS_TRANSIENT, $data['keys'], self::JWKS_TTL );
        return $data['keys'];
    }

    /**
     * Finds a JWK entry by kid.
     *
     * @param array<mixed> $jwks
     * @param string $kid
     * @return array<string,string>|null
     */
    private function findJwk( array $jwks, string $kid ): ?array {
        foreach ( $jwks as $key ) {
            if ( is_array( $key ) && ( $key['kid'] ?? '' ) === $kid ) {
                return $key;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // JWT decoding & validation
    // -------------------------------------------------------------------------

    /**
     * Decodes the JWT header without signature verification.
     *
     * @param string $token
     * @return stdClass
     * @throws \InvalidArgumentException
     */
    private function decodeHeader( string $token ): stdClass {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $header = json_decode( $this->base64UrlDecode( $parts[0] ) );
        if ( ! $header instanceof stdClass ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        return $header;
    }

    /**
     * Verifies the signature and validates standard claims.
     *
     * @param string $token
     * @param string $public_pem RSA public key PEM
     * @return stdClass Decoded payload.
     * @throws \InvalidArgumentException
     */
    private function decodeAndValidate( string $token, string $public_pem ): stdClass {
        $parts = explode( '.', $token );
        if ( count( $parts ) !== 3 ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        [ $header_b64, $payload_b64, $sig_b64 ] = $parts;

        // Verify RS256 signature.
        $signature = $this->base64UrlDecode( $sig_b64 );
        $data      = $header_b64 . '.' . $payload_b64;
        $key       = openssl_pkey_get_public( $public_pem );

        if ( ! $key ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $result = openssl_verify( $data, $signature, $key, OPENSSL_ALGO_SHA256 );
        if ( 1 !== $result ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Decode payload.
        $payload = json_decode( $this->base64UrlDecode( $payload_b64 ) );
        if ( ! $payload instanceof stdClass ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Validate issuer.
        if ( ( $payload->iss ?? '' ) !== self::VALID_ISSUER ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Validate expiry.
        $now = time();
        if ( ( $payload->exp ?? 0 ) < $now ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Validate audience (Apple's `aud` is the app bundle ID / service ID).
        $expected_aud = $this->getAppleAudience();
        $token_aud    = $payload->aud ?? '';
        if ( is_array( $token_aud ) ) {
            if ( ! in_array( $expected_aud, $token_aud, true ) ) {
                throw new \InvalidArgumentException( 'invalid_provider_token' );
            }
        } elseif ( $token_aud !== $expected_aud ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        return $payload;
    }

    // -------------------------------------------------------------------------
    // JWK → PEM (same algorithm as GoogleVerifier)
    // -------------------------------------------------------------------------

    /**
     * @param array<string,string> $jwk
     * @return string PEM public key
     * @throws \InvalidArgumentException
     */
    private function jwkToPem( array $jwk ): string {
        if ( ! isset( $jwk['n'], $jwk['e'] ) ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $n = $this->base64UrlDecode( $jwk['n'] );
        $e = $this->base64UrlDecode( $jwk['e'] );

        $modulus         = $this->encodeAsn1Integer( $n );
        $exponent        = $this->encodeAsn1Integer( $e );
        $sequence        = $this->encodeAsn1Sequence( $modulus . $exponent );
        $bit_string      = "\x00" . $sequence;
        $bit_string_asn1 = $this->encodeAsn1BitString( $bit_string );

        $rsa_oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $alg_seq = $this->encodeAsn1Sequence( $rsa_oid );
        $spki    = $this->encodeAsn1Sequence( $alg_seq . $bit_string_asn1 );

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split( base64_encode( $spki ), 64, "\n" )
             . "-----END PUBLIC KEY-----\n";
    }

    private function encodeAsn1Integer( string $value ): string {
        if ( ord( $value[0] ) > 0x7f ) {
            $value = "\x00" . $value;
        }
        return "\x02" . $this->encodeLength( strlen( $value ) ) . $value;
    }

    private function encodeAsn1Sequence( string $content ): string {
        return "\x30" . $this->encodeLength( strlen( $content ) ) . $content;
    }

    private function encodeAsn1BitString( string $content ): string {
        return "\x03" . $this->encodeLength( strlen( $content ) ) . $content;
    }

    private function encodeLength( int $length ): string {
        if ( $length < 128 ) {
            return chr( $length );
        }
        $len_bytes = '';
        $tmp       = $length;
        while ( $tmp > 0 ) {
            $len_bytes = chr( $tmp & 0xff ) . $len_bytes;
            $tmp >>= 8;
        }
        return chr( 0x80 | strlen( $len_bytes ) ) . $len_bytes;
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * Returns the expected Apple audience (app bundle ID or service ID).
     *
     * Priority:
     *  1. PRODE_APPLE_AUDIENCE constant (set in wp-config.php)
     *  2. prode_apple_audience WP option (set in admin settings)
     *
     * For iOS native SIWA the audience is the app bundle ID (e.g. "com.entreredes.app").
     * For web/Android the audience is the Services ID (e.g. "com.entreredes.app.web").
     *
     * @throws \InvalidArgumentException If not configured.
     */
    private function getAppleAudience(): string {
        if ( defined( 'PRODE_APPLE_AUDIENCE' ) ) {
            return (string) PRODE_APPLE_AUDIENCE;
        }

        $id = (string) get_option( 'prode_apple_audience', '' );
        if ( '' === $id ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        return $id;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function base64UrlDecode( string $data ): string {
        $padded = str_pad(
            strtr( $data, '-_', '+/' ),
            strlen( $data ) + ( 4 - strlen( $data ) % 4 ) % 4,
            '='
        );
        $decoded = base64_decode( $padded, true );
        if ( false === $decoded ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }
        return $decoded;
    }
}
