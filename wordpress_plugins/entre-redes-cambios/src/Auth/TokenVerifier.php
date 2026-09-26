<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth;

use EntreRedes\Cambios\Auth\Exception\TokenExpiredException;
use EntreRedes\Cambios\Auth\Exception\TokenMalformedException;
use EntreRedes\Cambios\Auth\Exception\TokenSignatureInvalidException;
use EntreRedes\Cambios\Auth\Exception\TokenWrongTypeException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Verifies an access token issued by entre-redes-prode's Auth\JwtService,
 * WITHOUT depending on any class from that plugin. The only things shared
 * between the two plugins are a value (the RS256 public key PEM, stored in
 * `wp_options['prode_rsa_public_key']` in plain text — no JWKS) and, for
 * revocation, a table (`prode_users.session_version`, handled separately by
 * ProdeSessionGateway, never by this class).
 *
 * Both the public key and the verification instant are INJECTED — this
 * class never calls get_option() or reads the system clock — so it is fully
 * testable with a throwaway RSA keypair and a fixed $now, no WordPress
 * bootstrap required.
 *
 * DELIBERATE SCOPE CUT, not an oversight: verify() does NOT validate `iss`
 * or `aud`. Replicating prode's audience check would require reading
 * `prode_settings.tenant_id` (or the PRODE_TENANT_ID constant) — a strictly
 * bigger, more fragile cross-plugin coupling than the one already accepted
 * for `session_version` (see ProdeSessionGateway's docblock for why that one
 * was accepted). Today there is a single tenant, so an `aud` mismatch cannot
 * occur in practice; if this ever becomes multi-tenant, this decision must
 * be revisited before it becomes a real gap.
 *
 * NEVER validates the `sv` (session_version) claim — that comparison needs
 * live data from `prode_users`, which this class has no access to by design.
 * See Capitania\CapitanAuthorizer for how TokenVerifier and
 * ProdeSessionGateway are composed into one authorization decision.
 */
class TokenVerifier {

    private const ALG          = 'RS256';
    private const TYPE_ACCESS  = 'prode_access';

    private string $publicKeyPem;

    public function __construct( string $publicKeyPem ) {
        $this->publicKeyPem = $publicKeyPem;
    }

    /**
     * Verifies signature, expiry, and token type; returns the decoded claims
     * on success.
     *
     * @param string $jwt The compact JWS string (header.payload.signature).
     * @param int $nowTimestamp Current instant as a Unix epoch. An epoch,
     *        deliberately, not a formatted string: `exp` and `iat` in a JWT
     *        ARE epochs, so comparing epoch to epoch has no timezone to get
     *        wrong. The previous signature took 'Y-m-d H:i:s' and parsed it as
     *        UTC — which meant that passing current_time('mysql'), the local
     *        time helper every other class in this plugin uses, moved "now"
     *        three hours into the past and accepted tokens that had expired up
     *        to twelve times their 900-second lifetime. Nothing would have
     *        broken visibly; the tokens would simply have outlived their TTL.
     *        Callers inside WordPress pass time() or current_time('timestamp', true).
     *
     * @return array<string, mixed> Decoded claims: iss, aud, sub, typ, sv,
     *         player_id, iat, exp.
     *
     * @throws TokenMalformedException When the string is not a well-formed,
     *         decodable JWS (wrong segment count, invalid base64/JSON,
     *         unsupported algorithm, or a future `nbf`).
     * @throws TokenSignatureInvalidException When the signature does not
     *         verify against the injected public key.
     * @throws TokenExpiredException When `exp` is at or before $now.
     * @throws TokenWrongTypeException When `typ` is not 'prode_access'.
     */
    public function verify( string $jwt, int $nowTimestamp ): array {

        // firebase/php-jwt reads "now" from this static hook instead of the
        // system clock when it is set — this is what makes verify() testable
        // against an arbitrary instant. Always reset it in `finally` so a
        // test-injected clock never leaks into an unrelated later call.
        JWT::$timestamp = $nowTimestamp;

        try {
            $decoded = JWT::decode( $jwt, new Key( $this->publicKeyPem, self::ALG ) );
        } catch ( ExpiredException $e ) {
            throw new TokenExpiredException( 'Access token has expired.', 0, $e );
        } catch ( SignatureInvalidException $e ) {
            throw new TokenSignatureInvalidException( 'Access token signature is invalid.', 0, $e );
        } catch ( BeforeValidException $e ) {
            throw new TokenMalformedException( 'Access token is not yet valid (nbf/iat in the future).', 0, $e );
        } catch ( \Throwable $e ) {
            // Every other failure firebase/php-jwt raises for a broken JWS
            // (wrong segment count, invalid base64/JSON, unsupported
            // algorithm, empty key, etc.) surfaces as \UnexpectedValueException
            // or \InvalidArgumentException — neither is specific to "wrong
            // signature" or "expired", so they land here as "malformed".
            throw new TokenMalformedException( 'Access token could not be decoded.', 0, $e );
        } finally {
            JWT::$timestamp = null;
        }

        $claims = (array) $decoded;

        if ( self::TYPE_ACCESS !== ( $claims['typ'] ?? null ) ) {
            throw new TokenWrongTypeException(
                "Expected typ 'prode_access', got '" . (string) ( $claims['typ'] ?? '' ) . "'."
            );
        }

        return $claims;
    }
}
