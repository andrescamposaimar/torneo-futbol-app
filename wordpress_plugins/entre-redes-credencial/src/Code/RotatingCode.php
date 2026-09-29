<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Code;

/**
 * The rotating liveness code (spec "Rotating Liveness Code"; design D4).
 *
 * Two stateless, pure functions — neither touches `$wpdb`, a clock, or
 * WordPress at all, so both sides of the shared contract (this class, and
 * slice 3a's Flutter client) can be tested against the identical vectors:
 *
 *   - seedFor(): the SERVER-ONLY derivation of `code_seed` from the
 *     `credencial_code_secret` option and the credential's own id. Only
 *     Credencial\CredencialService ever calls this — the client receives the
 *     resulting seed over GET and never sees the secret.
 *   - code(): the TOTP-SHA256 the client computes locally, every 30s, from
 *     that cached seed — RFC 6238 with SHA-256 instead of SHA-1, 6 digits,
 *     step = 30s. This is the ONLY piece of this class the app's Dart port
 *     (rotating_code.dart) must reproduce byte-for-byte.
 */
final class RotatingCode {

    /** Public so CredencialService can echo these into the GET response's `code{alg,step,digits}` without duplicating the constants. */
    public const ALG    = 'SHA256';
    public const STEP   = 30;
    public const DIGITS = 6;

    /**
     * Derives `code_seed`: `b64url(HMAC-SHA256($secret, "code-seed|v1|" . $credentialId))`.
     * The version tag ("v1") is baked into the HMAC message, not appended to
     * the output, so a future derivation scheme can never collide with this
     * one even if both were (mistakenly) computed from the same secret.
     */
    public static function seedFor( string $secret, string $credentialId ): string {
        $mac = hash_hmac( 'sha256', 'code-seed|v1|' . $credentialId, $secret, true );

        return self::base64UrlEncode( $mac );
    }

    /**
     * TOTP-SHA256 at 30s/6 digits (RFC 6238 dynamic truncation, generalised
     * to a 32-byte HMAC-SHA256 digest instead of RFC 4226's 20-byte SHA-1
     * one). $seed is the base64url string returned by seedFor() / cached by
     * the client — decoded back to raw bytes to use as the HMAC key.
     */
    public static function code( string $seed, int $timestamp ): string {
        $key     = self::base64UrlDecode( $seed );
        $counter = intdiv( $timestamp, self::STEP );
        $hmac    = hash_hmac( 'sha256', pack( 'J', $counter ), $key, true );

        $offset = ord( $hmac[ strlen( $hmac ) - 1 ] ) & 0x0f;
        $binary = ( ( ord( $hmac[ $offset ] ) & 0x7f ) << 24 )
            | ( ( ord( $hmac[ $offset + 1 ] ) & 0xff ) << 16 )
            | ( ( ord( $hmac[ $offset + 2 ] ) & 0xff ) << 8 )
            | ( ord( $hmac[ $offset + 3 ] ) & 0xff );

        $otp = $binary % ( 10 ** self::DIGITS );

        return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
    }

    private static function base64UrlEncode( string $raw ): string {
        return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
    }

    private static function base64UrlDecode( string $encoded ): string {
        $padded = str_pad( $encoded, (int) ( 4 * ceil( strlen( $encoded ) / 4 ) ), '=' );

        return (string) base64_decode( strtr( $padded, '-_', '+/' ), true );
    }
}
