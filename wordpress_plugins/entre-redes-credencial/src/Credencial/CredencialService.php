<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Code\RotatingCode;
use EntreRedes\Credencial\Player\PlayerReader;
use EntreRedes\Credencial\Player\TeamResolver;

/**
 * The single orchestrator behind GET /credencial/credencial — composes
 * Player\PlayerReader (eligibility + display facts, design D14),
 * Player\TeamResolver (the team/list badge, design D15), IssuanceRepository
 * (the stable id, design D4), Approval\ApprovalRequestRepository (the
 * `photo_request` field, design Interfaces) and Code\RotatingCode (the
 * liveness code seed) into exactly one of the four states CredencialState
 * models.
 *
 * ORDER MATTERS, and mirrors the spec's own requirement order:
 *   1. Eligibility Determination — no matching sp_player => not_a_player;
 *      blocked estado => blocked. Neither of these ever touches the issuance
 *      table, the photo gate, or `photo_request`: a blocked/nonexistent
 *      player has no "current" credential to rotate or serve, and must never
 *      leak whether a photo is under review (design: "blocked and
 *      not_a_player: null — do not leak").
 *   2. Approved Photo Gate — no featured image (no thumbnail attachment id,
 *      or no resolvable URL) => no_photo, but STILL reports `photo_request`
 *      (a first upload can be pending or rejected while no photo has ever
 *      been approved — spec "First upload"). Checked BEFORE issuance
 *      resolution, so a player who has never had a photo approved never gets
 *      an issuance row minted for them prematurely.
 *   3. Only once both gates pass does this class mint/rotate the issuance
 *      row (keyed by the thumbnail's attachment id — design D4, rev 9) and
 *      assemble the full payload (design Interfaces section).
 *
 * `photo_request` resolution (engram 1589: this repository existed since
 * slice 2a/2b but was never wired back into the GET — "deferred to a later
 * slice" comments are cables left unplugged):
 *   - a pending PHOTO request for this player wins outright ({status:
 *     pending}), whether the state ends up `no_photo` (first upload) or
 *     `active` (replacement upload — the old approved photo keeps backing
 *     the credential, design "Replacement upload while an approved photo
 *     exists");
 *   - otherwise, the newest REJECTED photo request is reported ONLY if it is
 *     newer (by `reviewed_at`, the same "decision time" axis
 *     findNewestApprovedPhotoRequest() already orders by) than the newest
 *     APPROVED photo request, or if no photo request was ever approved.  A
 *     rejection superseded by a later approval is stale and must not be
 *     reported (design: "An approved-but-unpublished request is not
 *     reported; the card shows the currently published photo" — the same
 *     "only the newest decision matters" principle governs `photo_request`).
 *
 * The photo rendition size (design D5c) is 'large': WordPress caps a 'large'
 * rendition at 1024px on the longest edge and returns the original URL when
 * the original is smaller, so the photo is never upscaled server-side. The
 * card renders the photo as its dominant element (design D-UI), so a lower
 * rendition is visibly soft. Centralized in one constant so revisiting it
 * later is still a one-line change.
 */
final class CredencialService {

    /** Design D5: "Reissue on every GET with expires_at = now+365d." */
    private const CREDENTIAL_TTL_SECONDS = 365 * 24 * 60 * 60;

    /** Design D5c: 'large' (<=1024px, original when smaller — never upscaled). */
    private const PHOTO_SIZE = 'large';

    public function __construct(
        private readonly PlayerReader $playerReader,
        private readonly TeamResolver $teamResolver,
        private readonly IssuanceRepository $issuanceRepository,
        private readonly ApprovalRequestRepository $approvalRequestRepository,
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

        $thumbnailId = get_post_thumbnail_id( $playerId );
        $photoUrl    = get_the_post_thumbnail_url( $playerId, self::PHOTO_SIZE );

        if ( false === $thumbnailId || $thumbnailId <= 0 || false === $photoUrl || '' === $photoUrl ) {
            return CredencialState::noPhoto( $this->resolvePhotoRequest( $playerId ) );
        }

        $issuance = $this->issuanceRepository->resolve( $playerId, $liveUserId, (int) $thumbnailId, $now );

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
            'photo'      => [ 'id' => (int) $thumbnailId, 'url' => $photoUrl ],
            'code_seed'  => RotatingCode::seedFor( $this->codeSecret, $issuance['credential_id'] ),
            'code'       => [
                'alg'    => RotatingCode::ALG,
                'step'   => RotatingCode::STEP,
                'digits' => RotatingCode::DIGITS,
            ],
        ];

        return CredencialState::active( $credential, $this->resolvePhotoRequest( $playerId ) );
    }

    /**
     * See this class's own docblock for the resolution order. Only ever
     * called once eligibility passed (never for blocked/not_a_player).
     *
     * @return array{id:int, status:string, created_at:string}|null
     */
    private function resolvePhotoRequest( int $playerId ): ?array {
        $pending = $this->approvalRequestRepository->findPendingPhotoRequest( $playerId );

        if ( null !== $pending ) {
            return [
                'id'         => $pending['id'],
                'status'     => 'pending',
                'created_at' => $pending['created_at'],
            ];
        }

        $rejected = $this->approvalRequestRepository->findNewestRejectedPhotoRequest( $playerId );

        if ( null === $rejected ) {
            return null;
        }

        $approved = $this->approvalRequestRepository->findNewestApprovedPhotoRequest( $playerId );

        $rejectedIsStale = null !== $approved
            && null !== $approved['reviewed_at']
            && ( $rejected['reviewed_at'] ?? '' ) <= $approved['reviewed_at'];

        if ( $rejectedIsStale ) {
            return null;
        }

        return [
            'id'         => $rejected['id'],
            'status'     => 'rejected',
            'created_at' => $rejected['created_at'],
        ];
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
}
