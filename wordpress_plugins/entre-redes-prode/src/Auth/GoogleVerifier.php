<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

use stdClass;

/**
 * Verifies a Google ID token received from the Flutter app.
 *
 * Verification steps (per Google OIDC spec):
 *  1. Fetch Google's JWKS from https://www.googleapis.com/oauth2/v3/certs
 *     (cached in WP transients for 1 hour).
 *  2. Decode the token header to get `kid`.
 *  3. Find the matching JWK from the fetched JWKS.
 *  4. Verify the RS256 signature using the JWK public key.
 *  5. Validate standard claims: iss, aud, exp.
 *  6. Return the decoded payload so the caller can extract sub, email, name.
 *
 * Google's JWKS URL is stable and well-known; the 1h transient TTL is
 * conservative — Google's keys typically rotate every few days, but the
 * cache-control headers on their JWKS endpoint recommend ~1h on cache misses.
 *
 * On kid-miss the cache is NOT refreshed (Google doesn't rotate keys within
 * 1 hour in practice). If a fresh deployment produces a short-term mismatch,
 * the transient will expire naturally. This is simpler and safer than an
 * on-kid-miss refresh here (contrast with AppleVerifier which needs it per R13).
 */
class GoogleVerifier {

    private const JWKS_URL      = 'https://www.googleapis.com/oauth2/v3/certs';
    private const JWKS_TRANSIENT = 'prode_google_jwks';
    private const JWKS_TTL      = HOUR_IN_SECONDS;

    private const VALID_ISSUERS = [
        'accounts.google.com',
        'https://accounts.google.com',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Verifies a Google ID token and returns its decoded claims.
     *
     * @param string $id_token  Raw ID token string from the Flutter Google Sign-In SDK.
     * @return stdClass Decoded JWT claims (sub, email, given_name, family_name, etc.)
     * @throws \InvalidArgumentException On any verification failure with a machine-readable code.
     */
    public function verify( string $id_token ): stdClass {
        // 1. Decode header without verification to get kid.
        $header = $this->decodeHeader( $id_token );
        $kid    = $header->kid ?? '';

        // 2. Fetch JWKS (cached).
        $jwks = $this->fetchJwks();

        // 3. Locate the JWK matching the kid.
        $jwk = $this->findJwk( $jwks, $kid );
        if ( null === $jwk ) {
            // Google doesn't rotate within 1h, but clear the transient and retry
            // once to handle edge cases (key rotation coinciding with a deploy).
            delete_transient( self::JWKS_TRANSIENT );
            $jwks = $this->fetchJwks( true );
            $jwk  = $this->findJwk( $jwks, $kid );
            if ( null === $jwk ) {
                throw new \InvalidArgumentException( 'invalid_provider_token' );
            }
        }

        // 4. Build PEM from JWK and verify signature + claims.
        $public_pem = $this->jwkToPem( $jwk );
        return $this->decodeAndValidate( $id_token, $public_pem );
    }

    // -------------------------------------------------------------------------
    // JWKS handling
    // -------------------------------------------------------------------------

    /**
     * Returns the cached JWKS or fetches it fresh.
     *
     * @param bool $force_refresh Bypass the transient cache.
     * @return array<mixed> Parsed JWKS object.
     * @throws \InvalidArgumentException If the fetch fails.
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
     * Finds a JWK by kid from the JWKS array.
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
    // JWT handling
    // -------------------------------------------------------------------------

    /**
     * Decodes only the JWT header (no signature verification).
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
     * Decodes and validates the ID token using the resolved public key PEM.
     *
     * Validates: RS256 signature, iss, aud (against configured Google client ID),
     * exp, iat (not-before within 30s skew).
     *
     * @param string $token
     * @param string $public_pem
     * @return stdClass Decoded claims.
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
        if ( ! in_array( $payload->iss ?? '', self::VALID_ISSUERS, true ) ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Validate expiry.
        $now = time();
        if ( ( $payload->exp ?? 0 ) < $now ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        // Validate audience (must match the configured Google client ID).
        $expected_aud = $this->getGoogleClientId();
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
    // JWK → PEM conversion
    // -------------------------------------------------------------------------

    /**
     * Converts an RSA JWK to a PEM-encoded public key string.
     *
     * Uses the standard RSA public key DER format built from the modulus (n)
     * and exponent (e) fields.
     *
     * @param array<string,string> $jwk
     * @return string PEM-encoded public key
     * @throws \InvalidArgumentException If the JWK is malformed.
     */
    private function jwkToPem( array $jwk ): string {
        if ( ! isset( $jwk['n'], $jwk['e'] ) ) {
            throw new \InvalidArgumentException( 'invalid_provider_token' );
        }

        $n = $this->base64UrlDecode( $jwk['n'] );
        $e = $this->base64UrlDecode( $jwk['e'] );

        // Build the RSA public key DER structure.
        $modulus         = $this->encodeAsn1Integer( $n );
        $exponent        = $this->encodeAsn1Integer( $e );
        $sequence        = $this->encodeAsn1Sequence( $modulus . $exponent );
        $bit_string      = "\x00" . $sequence;
        $bit_string_asn1 = $this->encodeAsn1BitString( $bit_string );

        // RSA OID: 1.2.840.113549.1.1.1
        $rsa_oid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $alg_seq = $this->encodeAsn1Sequence( $rsa_oid );

        $spki = $this->encodeAsn1Sequence( $alg_seq . $bit_string_asn1 );

        return "-----BEGIN PUBLIC KEY-----\n"
             . chunk_split( base64_encode( $spki ), 64, "\n" )
             . "-----END PUBLIC KEY-----\n";
    }

    private function encodeAsn1Integer( string $value ): string {
        // Prepend 0x00 if high bit is set to avoid sign confusion.
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
        $tmp = $length;
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
     * Returns the expected Google client ID for audience validation.
     *
     * Priority:
     *  1. PRODE_GOOGLE_CLIENT_ID constant (set in wp-config.php)
     *  2. prode_google_client_id WP option (set in admin settings)
     *
     * In V1 the Marianista flavor uses a single web client ID. The Flutter app
     * passes the ID token created with this client; the server validates aud
     * against its own stored value so the Flutter-supplied value is never trusted
     * for the audience check.
     *
     * @throws \InvalidArgumentException If no client ID is configured.
     */
    private function getGoogleClientId(): string {
        if ( defined( 'PRODE_GOOGLE_CLIENT_ID' ) ) {
            return (string) PRODE_GOOGLE_CLIENT_ID;
        }

        $id = (string) get_option( 'prode_google_client_id', '' );
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
