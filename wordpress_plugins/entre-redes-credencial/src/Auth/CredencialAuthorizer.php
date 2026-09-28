<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth;

use EntreRedes\Credencial\Auth\Exception\TokenExpiredException;
use EntreRedes\Credencial\Auth\Exception\TokenVerificationException;

/**
 * The single entry point every credencial-facing endpoint calls to answer
 * one question: "is the bearer of this Authorization header a live prode
 * session, and if so, which (user_id, player_id) does it belong to?" —
 * design D1.
 *
 * UNLIKE entre-redes-cambios's Capitania\CapitanAuthorizer, this class does
 * NOT collapse every rejection into one generic response: there is no
 * team-scoped secret to protect here, only "is this session valid" — the
 * exact same contract entre-redes-prode's own Auth\AuthMiddleware already
 * exposes to its own endpoints (`token_missing`, `token_expired`,
 * `token_invalid`, `session_revoked`, each with HTTP 401). The app is
 * expected to branch on `code` (design D1: "App branches on `code`; fail
 * closed"), so this authorizer reuses those exact codes rather than
 * inventing new ones a client would have to special-case.
 *
 * TokenVerifier and ProdeSessionGateway are both injected, never
 * constructed internally — same rule as every other class in this slice.
 */
class CredencialAuthorizer {

    private TokenVerifier $tokenVerifier;
    private ProdeSessionGateway $sessionGateway;

    public function __construct( TokenVerifier $tokenVerifier, ProdeSessionGateway $sessionGateway ) {
        $this->tokenVerifier  = $tokenVerifier;
        $this->sessionGateway = $sessionGateway;
    }

    /**
     * @param string $authorizationHeader The raw `Authorization` header
     *        value (e.g. `Bearer <jwt>`), or an empty string when absent.
     * @param int $nowTimestamp Current instant as a Unix epoch — see
     *        TokenVerifier::verify() for why this is an epoch, never a
     *        formatted datetime.
     *
     * @return array{user_id: int, player_id: int}|\WP_Error The verified
     *         (user_id, player_id) pair on success, or a WP_Error carrying
     *         one of `token_missing`, `token_expired`, `token_invalid`, or
     *         `session_revoked`, each with `data.status = 401`.
     */
    public function authorize( string $authorizationHeader, int $nowTimestamp ) {
        $jwt = self::extractBearerToken( $authorizationHeader );

        if ( '' === $jwt ) {
            return new \WP_Error(
                'token_missing',
                'Authorization header with Bearer token is required.',
                [ 'status' => 401 ]
            );
        }

        try {
            $claims = $this->tokenVerifier->verify( $jwt, $nowTimestamp );
        } catch ( TokenExpiredException $e ) {
            return new \WP_Error( 'token_expired', 'Access token has expired.', [ 'status' => 401 ] );
        } catch ( TokenVerificationException $e ) {
            return new \WP_Error( 'token_invalid', 'Access token is invalid.', [ 'status' => 401 ] );
        }

        $userId         = (int) ( $claims['sub'] ?? 0 );
        $sessionVersion = (int) ( $claims['sv'] ?? -1 );
        $playerId       = (int) ( $claims['player_id'] ?? 0 );

        if ( ! $this->sessionGateway->isSessionCurrent( $userId, $sessionVersion ) ) {
            return new \WP_Error( 'session_revoked', 'Session has been revoked.', [ 'status' => 401 ] );
        }

        return [ 'user_id' => $userId, 'player_id' => $playerId ];
    }

    private static function extractBearerToken( string $header ): string {
        if ( ! str_starts_with( $header, 'Bearer ' ) ) {
            return '';
        }

        return substr( $header, strlen( 'Bearer ' ) );
    }
}
