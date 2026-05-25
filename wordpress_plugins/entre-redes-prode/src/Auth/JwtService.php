<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;
use stdClass;

/**
 * RS256 JWT issuer and verifier for the Prode plugin.
 *
 * Issues two distinct token types:
 *   1. access_token  — 15-minute lifetime, carries user session claims.
 *   2. intent_token  — 5-minute lifetime, bridges /auth/google|apple → /auth/dni.
 *                      The `typ` claim is "prode_intent" to prevent reuse as an
 *                      access token; all authed endpoints reject intent tokens.
 *   3. refresh_token — Opaque UUID-based token stored in the DB; this class only
 *                      handles the JWT access/intent tokens. Refresh tokens are
 *                      managed by SessionManager.
 *
 * Claims (access token):
 *   iss  — plugin issuer (site_url/wp-json/entre-redes/v1/prode)
 *   aud  — PRODE_TENANT_ID
 *   sub  — prode_users.id (string, per JWT spec)
 *   typ  — "prode_access"
 *   sv   — session_version INT (revocation anchor)
 *   iat  — issued-at timestamp
 *   exp  — expiry timestamp
 *   kid  — key id (used in header; not a standard body claim, but included for
 *           programmatic inspection without header parsing)
 *
 * Claims (intent token):
 *   iss, aud, iat, exp as above
 *   typ       — "prode_intent"
 *   provider  — "google" | "apple"
 *   pid       — provider_id (the OAuth sub claim)
 *   email     — user email from provider
 *   name_first, name_last — display hints
 */
class JwtService {

    private const ACCESS_TTL  = 900;          // 15 minutes in seconds
    private const INTENT_TTL  = 300;          // 5 minutes in seconds
    private const ALG         = 'RS256';
    private const TYPE_ACCESS = 'prode_access';
    private const TYPE_INTENT = 'prode_intent';

    // -------------------------------------------------------------------------
    // Token issuance
    // -------------------------------------------------------------------------

    /**
     * Issues a 15-minute access token for an authenticated Prode user.
     *
     * @param int $user_id         prode_users.id
     * @param int $session_version prode_users.session_version
     * @return string Signed JWT
     * @throws \RuntimeException If the private key is not provisioned or signing fails.
     */
    public function issueAccessToken( int $user_id, int $session_version ): string {
        $now = time();

        $payload = [
            'iss' => $this->issuer(),
            'aud' => $this->audience(),
            'sub' => (string) $user_id,
            'typ' => self::TYPE_ACCESS,
            'sv'  => $session_version,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
        ];

        return $this->sign( $payload );
    }

    /**
     * Issues a 5-minute intent token to bridge the SSO step and the DNI step.
     *
     * This token is deliberately type-distinct ('prode_intent') so it cannot
     * be presented to authed endpoints as a valid access token.
     *
     * @param string $provider   "google" | "apple"
     * @param string $provider_id The OAuth sub claim
     * @param string $email
     * @param string $name_first
     * @param string $name_last
     * @return string Signed JWT
     */
    public function issueIntentToken(
        string $provider,
        string $provider_id,
        string $email,
        string $name_first,
        string $name_last
    ): string {
        $now = time();

        $payload = [
            'iss'        => $this->issuer(),
            'aud'        => $this->audience(),
            'typ'        => self::TYPE_INTENT,
            'provider'   => $provider,
            'pid'        => $provider_id,
            'email'      => $email,
            'name_first' => $name_first,
            'name_last'  => $name_last,
            'iat'        => $now,
            'exp'        => $now + self::INTENT_TTL,
        ];

        return $this->sign( $payload );
    }

    // -------------------------------------------------------------------------
    // Token verification
    // -------------------------------------------------------------------------

    /**
     * Verifies and decodes an access token.
     *
     * Validates: signature, expiry, issuer, audience, and type claim.
     *
     * @param string $token
     * @return stdClass Decoded payload
     * @throws \InvalidArgumentException If token is invalid, expired, wrong type, etc.
     */
    public function verifyAccessToken( string $token ): stdClass {
        $decoded = $this->decode( $token );

        if ( ( $decoded->typ ?? '' ) !== self::TYPE_ACCESS ) {
            throw new \InvalidArgumentException( 'token_wrong_type' );
        }

        return $decoded;
    }

    /**
     * Verifies and decodes an intent token.
     *
     * @param string $token
     * @return stdClass Decoded payload
     * @throws \InvalidArgumentException If token is invalid, expired, or wrong type.
     */
    public function verifyIntentToken( string $token ): stdClass {
        $decoded = $this->decode( $token );

        if ( ( $decoded->typ ?? '' ) !== self::TYPE_INTENT ) {
            throw new \InvalidArgumentException( 'invalid_intent_token' );
        }

        return $decoded;
    }

    // -------------------------------------------------------------------------
    // Key management
    // -------------------------------------------------------------------------

    /**
     * Returns the private key PEM after de-obfuscating the XOR+base64 storage.
     *
     * @throws \RuntimeException If key has not been provisioned.
     */
    private function getPrivateKey(): string {
        $encoded = get_option( 'prode_rsa_private_key', '' );
        if ( '' === $encoded ) {
            throw new \RuntimeException( 'keys_not_provisioned' );
        }

        $obfuscated = base64_decode( $encoded, true );
        if ( false === $obfuscated ) {
            throw new \RuntimeException( 'private_key_decode_error' );
        }

        return $this->xorWithSalt( $obfuscated );
    }

    /**
     * Returns the public key PEM.
     *
     * @throws \RuntimeException If key has not been provisioned.
     */
    private function getPublicKey(): string {
        $pem = (string) get_option( 'prode_rsa_public_key', '' );
        if ( '' === $pem ) {
            throw new \RuntimeException( 'keys_not_provisioned' );
        }
        return $pem;
    }

    /**
     * Returns the active key ID (kid).
     */
    public function getKeyId(): string {
        return (string) get_option( 'prode_rsa_key_id', '' );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Signs a payload array with the stored RS256 private key.
     *
     * @param array<string, mixed> $payload
     * @return string Signed JWT string
     */
    private function sign( array $payload ): string {
        $private_key = $this->getPrivateKey();
        $kid         = $this->getKeyId();

        // firebase/php-jwt v6 accepts a key resource or PEM string.
        return JWT::encode(
            $payload,
            $private_key,
            self::ALG,
            $kid
        );
    }

    /**
     * Decodes and verifies a JWT string.
     *
     * Validates: signature (RS256), expiry, not-before.
     * Audience and issuer checks are done post-decode (firebase/php-jwt v6
     * requires explicit aud check when multiple audiences are involved).
     *
     * @param string $token
     * @return stdClass Decoded payload
     * @throws \InvalidArgumentException On any verification failure.
     */
    private function decode( string $token ): stdClass {
        $public_pem = $this->getPublicKey();
        $kid        = $this->getKeyId();

        try {
            // firebase/php-jwt v6: use Key wrapper for algorithm binding.
            $decoded = JWT::decode(
                $token,
                new Key( $public_pem, self::ALG )
            );
        } catch ( ExpiredException $e ) {
            throw new \InvalidArgumentException( 'token_expired', 0, $e );
        } catch ( SignatureInvalidException $e ) {
            throw new \InvalidArgumentException( 'token_invalid', 0, $e );
        } catch ( BeforeValidException $e ) {
            throw new \InvalidArgumentException( 'token_not_yet_valid', 0, $e );
        } catch ( \Exception $e ) {
            throw new \InvalidArgumentException( 'token_invalid', 0, $e );
        }

        // Explicit issuer check.
        if ( ( $decoded->iss ?? '' ) !== $this->issuer() ) {
            throw new \InvalidArgumentException( 'token_invalid_issuer' );
        }

        // Explicit audience check.
        $aud = $decoded->aud ?? '';
        if ( is_array( $aud ) ) {
            $aud = $aud[0] ?? '';
        }
        if ( $aud !== $this->audience() ) {
            throw new \InvalidArgumentException( 'token_invalid_audience' );
        }

        return $decoded;
    }

    /**
     * Canonical issuer string: the plugin's REST namespace root.
     */
    private function issuer(): string {
        return rtrim( get_site_url(), '/' ) . '/wp-json/entre-redes/v1/prode';
    }

    /**
     * Audience = the tenant ID defined in wp-config.php or stored in settings.
     */
    private function audience(): string {
        if ( defined( 'PRODE_TENANT_ID' ) ) {
            return (string) PRODE_TENANT_ID;
        }

        global $wpdb;
        return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}prode_settings WHERE setting_key = %s",
                'tenant_id'
            )
        );
    }

    /**
     * XOR-obfuscation using wp_salt('auth') — must mirror MigrationRunner::xorWithSalt().
     */
    private function xorWithSalt( string $data ): string {
        $salt   = wp_salt( 'auth' );
        $result = '';
        $len    = strlen( $salt );
        for ( $i = 0, $iMax = strlen( $data ); $i < $iMax; $i++ ) {
            $result .= $data[ $i ] ^ $salt[ $i % $len ];
        }
        return $result;
    }
}
