<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial;

use EntreRedes\Credencial\Code\RotatingCode;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Player\TeamResolver;

/**
 * The single orchestrator behind GET /credencial/credencial — composes
 * Player\PlayerReader (eligibility + display facts, design D14),
 * Player\TeamResolver (the team/list badge, design D15), IssuanceRepository
 * (the stable id, design D4) and Code\RotatingCode (the liveness code seed)
 * into exactly one of the four states CredencialState models.
 *
 * ORDER MATTERS, and mirrors the spec's own requirement order:
 *   1. Eligibility Determination — no matching sp_player => not_a_player;
 *      blocked estado => blocked. Neither of these ever touches the issuance
 *      table or the photo gate: a blocked/nonexistent player has no
 *      "current" credential to rotate or serve.
 *   2. Approved Photo Gate — no featured image => no_photo. Checked BEFORE
 *      issuance resolution, so a player who has never had a photo approved
 *      never gets an issuance row minted for them prematurely.
 *   3. Only once both gates pass does this class mint/rotate the issuance
 *      row and assemble the full payload (design Interfaces section).
 *
 * `photo_request` in the GET response is ALWAYS null in this slice — the
 * approval-request pipeline (Approval\ApprovalRequestRepository) does not
 * exist yet (that is slice 2a/2b); this class has nothing to report there
 * until then.
 *
 * The photo rendition size ('medium') is a DELIBERATE, NOT-YET-FINAL choice
 * — design's own Open Questions list "Photo rendition for the face check
 * (medium vs large)" as still open. Centralized in one constant so revisiting
 * it later is a one-line change.
 */
final class CredencialService {

    /** Design D5: "Reissue on every GET with expires_at = now+365d." */
    private const CREDENTIAL_TTL_SECONDS = 365 * 24 * 60 * 60;

    /** See this class's own docblock — open question, not yet finalized. */
    private const PHOTO_SIZE = 'medium';

    public function __construct(
        private readonly PlayerReader $playerReader,
        private readonly TeamResolver $teamResolver,
        private readonly IssuanceRepository $issuanceRepository,
        private readonly string $codeSecret
    ) {
    }

    public function resolve( int $playerId, int $liveUserId, int $now ): CredencialState {
        $player = $this->playerReader->resolve( $playerId, $now );

        if ( null === $player ) {
            return CredencialState::notAPlayer();
        }

        if ( $player->isBlocked() ) {
            return CredencialState::blocked();
        }

        $photoUrl = get_the_post_thumbnail_url( $playerId, self::PHOTO_SIZE );

        if ( false === $photoUrl || '' === $photoUrl ) {
            return CredencialState::noPhoto();
        }

        $liveSha  = self::nullIfEmpty( (string) get_post_meta( $playerId, '_credencial_sha256', true ) );
        $issuance = $this->issuanceRepository->resolve( $playerId, $liveUserId, $liveSha, $now );

        $team = self::shapeTeam( $this->teamResolver->resolve( $playerId ) );

        $credential = [
            'id'         => $issuance['credential_id'],
            'issued_at'  => $issuance['minted_at'],
            'expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::CREDENTIAL_TTL_SECONDS ),
            'player_id'  => $playerId,
            'full_name'  => $player->fullName(),
            'dni'        => $player->dni(),
            'birth_date' => $player->birthDateOrNull(),
            'caracter'   => $player->caracterOrNull(),
            'team'       => $team,
            'photo'      => [ 'url' => $photoUrl, 'sha256' => $liveSha ],
            'code_seed'  => RotatingCode::seedFor( $this->codeSecret, $issuance['credential_id'] ),
            'code'       => [
                'alg'    => RotatingCode::ALG,
                'step'   => RotatingCode::STEP,
                'digits' => RotatingCode::DIGITS,
            ],
        ];

        return CredencialState::active( $credential );
    }

    /**
     * @param array{id:int, name:string}|null $team
     * @return array{id:int, name:string, kind:string}|null
     */
    private static function shapeTeam( ?array $team ): ?array {
        if ( null === $team ) {
            return null;
        }

        return [
            'id'   => $team['id'],
            'name' => $team['name'],
            'kind' => TeamKind::fromName( $team['name'] ),
        ];
    }

    private static function nullIfEmpty( string $value ): ?string {
        return '' === $value ? null : $value;
    }
}
