<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth;

use EntreRedes\Credencial\Auth\Exception\TokenExpiredException;
use EntreRedes\Credencial\Auth\Exception\TokenMalformedException;
use EntreRedes\Credencial\Auth\Exception\TokenNotYetValidException;
use EntreRedes\Credencial\Auth\Exception\TokenSignatureInvalidException;
use EntreRedes\Credencial\Auth\Exception\TokenWrongTypeException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Verifies an access token issued by entre-redes-prode's Auth\JwtService,
 * WITHOUT depending on any class from that plugin — copied from
 * entre-redes-cambios/src/Auth/TokenVerifier.php per design D1 ("Copy
 * TokenVerifier + ProdeSessionGateway ... into CredencialAuthorizer").
 *
 * The only things shared between this plugin and prode are a value (the
 * RS256 public key PEM, stored in `wp_options['prode_rsa_public_key']`) and,
 * for revocation, a table (`prode_users.session_version`, handled separately
 * by ProdeSessionGateway, never by this class).
 *
 * Both the public key and the verification instant are INJECTED — this
 * class never calls get_option() or reads the system clock — so it is fully
 * testable with a throwaway RSA keypair and a fixed $now, no WordPress
 * bootstrap required.
 *
 * DELIBERATE SCOPE CUT, not an oversight: verify() does NOT validate `iss`
 * or `aud`. Today there is a single tenant, so an `aud` mismatch cannot
 * occur in practice; if this ever becomes multi-tenant, this decision must
 * be revisited before it becomes a real gap.
 *
 * NEVER validates the `sv` (session_version) claim — that comparison needs
 * live data from `prode_users`, which this class has no access to by
 * design. See Auth\CredencialAuthorizer for how TokenVerifier and
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
     * @param int $nowTimestamp Current instant as a Unix epoch — an epoch,
     *        deliberately, not a formatted string: `exp` and `iat` in a JWT
     *        ARE epochs, so comparing epoch to epoch has no timezone to get
     *        wrong. Callers inside WordPress pass time() or
     *        current_time('timestamp', true).
     *
     * @return array<string, mixed> Decoded claims: iss, aud, sub, typ, sv,
     *         player_id, iat, exp.
     *
     * @throws TokenMalformedException When the string is not a well-formed,
     *         decodable JWS.
     * @throws TokenSignatureInvalidException When the signature does not
     *         verify against the injected public key.
     * @throws TokenExpiredException When `exp` is at or before $now.
     * @throws TokenNotYetValidException When `nbf` is in the future.
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
            throw new TokenNotYetValidException( 'Access token is not yet valid (nbf in the future).', 0, $e );
        } catch ( \Throwable $e ) {
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
