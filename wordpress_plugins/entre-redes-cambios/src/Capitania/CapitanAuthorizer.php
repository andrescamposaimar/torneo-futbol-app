<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania;

use EntreRedes\Cambios\Auth\Exception\TokenVerificationException;
use EntreRedes\Cambios\Auth\ProdeSessionGateway;
use EntreRedes\Cambios\Auth\TokenVerifier;
use EntreRedes\Cambios\Capitania\Exception\InvalidTokenException;
use EntreRedes\Cambios\Capitania\Exception\NotCaptainException;
use EntreRedes\Cambios\Capitania\Exception\SessionRevokedException;

/**
 * The single entry point later slices of this feature should call to answer
 * one question: "is the person holding this JWT allowed to act as captain of
 * this team, this season?" It composes the three pieces slice 1 introduces —
 * TokenVerifier (signature/expiry/type), ProdeSessionGateway (revocation),
 * and CapitanRepository (the captaincy itself) — so no future slice needs to
 * re-derive the order in which those three checks must happen, or re-learn
 * why each one exists.
 *
 * All three dependencies are injected, none are constructed internally —
 * same rule as every other class in this slice, and what makes this class
 * testable without a WordPress bootstrap beyond a real $wpdb (for the two
 * gateways) and a throwaway RSA keypair (for the token).
 */
class CapitanAuthorizer {

    private TokenVerifier $tokenVerifier;
    private ProdeSessionGateway $sessionGateway;
    private CapitanRepository $capitanRepository;

    public function __construct(
        TokenVerifier $tokenVerifier,
        ProdeSessionGateway $sessionGateway,
        CapitanRepository $capitanRepository
    ) {
        $this->tokenVerifier     = $tokenVerifier;
        $this->sessionGateway    = $sessionGateway;
        $this->capitanRepository = $capitanRepository;
    }

    /**
     * @param string $jwt      The prode access token presented by the caller.
     * @param int    $seasonId The `sp_season` term id being operated on.
     * @param int    $teamId   The `sp_team` post id being operated on.
     * @param int $nowTimestamp Current instant as a Unix epoch — see
     *        TokenVerifier::verify() for why this is an epoch and not a
     *        formatted datetime. Nothing here writes to the database, so no
     *        SQL-shaped "now" is needed.
     *
     * @return array<string, mixed> The verified JWT claims, exactly as
     *         TokenVerifier::verify() returns them, when authorization
     *         succeeds.
     *
     * @throws InvalidTokenException When the JWT itself fails to verify
     *         (bad signature, expired, wrong type, or malformed). The
     *         rejected TokenVerificationException is available via
     *         getPrevious() for server-side logging only.
     * @throws SessionRevokedException When the JWT verifies but
     *         ProdeSessionGateway says its session is no longer current.
     * @throws NotCaptainException When the token's `player_id` is not the
     *         vigent captain of ($seasonId, $teamId) — including when that
     *         player captains a DIFFERENT team.
     *
     * Every rejection above carries the SAME generic exception message (see
     * AuthorizationDeniedException) — only the exception TYPE distinguishes
     * the reason, so a caller that turns this into an HTTP response can
     * never leak which of the three conditions failed to the client, while
     * still being able to log the real reason server-side.
     */
    public function authorize( string $jwt, int $seasonId, int $teamId, int $nowTimestamp ): array {
        try {
            $claims = $this->tokenVerifier->verify( $jwt, $nowTimestamp );
        } catch ( TokenVerificationException $e ) {
            throw new InvalidTokenException( $e );
        }

        $prodeUserId     = (int) ( $claims['sub'] ?? 0 );
        $sessionVersion  = (int) ( $claims['sv'] ?? -1 );
        $playerId        = (int) ( $claims['player_id'] ?? 0 );

        if ( ! $this->sessionGateway->isSessionCurrent( $prodeUserId, $sessionVersion ) ) {
            throw new SessionRevokedException();
        }

        if ( ! $this->capitanRepository->isCapitanVigente( $seasonId, $teamId, $playerId ) ) {
            throw new NotCaptainException();
        }

        return $claims;
    }
}
