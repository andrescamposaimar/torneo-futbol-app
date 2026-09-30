<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial;

use EntreRedes\Credencial\Credencial\Exception\IssuanceResolutionFailedException;
use EntreRedes\Credencial\Observability\EventLog;

/**
 * Owns `credencial_issuance` — the ONE table that gives a credential a
 * stable id across every GET (design D4). "Stable" is the whole point: an id
 * that changed on every revalidation would defeat the rotating liveness code
 * (RotatingCode derives its seed from this id) and would make an offline
 * cached credential impossible to reconcile against a later online refresh.
 *
 * resolve() is called on EVERY GET (CredencialService, a later class in this
 * same slice) with the CALLER'S live facts — $liveUserId (from the verified
 * JWT) and $livePhotoAttachmentId (the WordPress attachment id backing the
 * currently published featured image; only ever called after the photo gate,
 * so this is always > 0) — and returns the row that is true RIGHT NOW,
 * rotating the id only when one of those two facts no longer matches what
 * was persisted last time. Every other combination (estado flipping
 * blocked<->active, caracter changing, a new team, the featured image's URL
 * or rendition changing without the underlying attachment changing) leaves
 * the id untouched — those are not fraud levers, so D4 does not rotate on
 * them.
 *
 * The photo is identified by its attachment id, its real identity in
 * WordPress: it changes exactly when the face changes (a new upload
 * approval, or the comisión replacing the featured image directly), and
 * never on its own for a URL or rendition change. `0` means "legacy row,
 * live id unknown" — migration 0.2.0 gives pre-existing rows that default,
 * so each one rotates exactly once on its next GET.
 *
 * $now IS INJECTED AS AN EPOCH, never read from the system clock — same
 * discipline as Player\PlayerReader and Code\RotatingCode in this same
 * slice (see project note "Epoch, no string": an epoch has no timezone to
 * get wrong, formatted local-time strings do).
 */
final class IssuanceRepository {

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * @return array{player_id:int, credential_id:string, user_id:int, photo_attachment_id:int, minted_at:string, updated_at:string}
     *
     * @throws IssuanceResolutionFailedException When the row cannot be
     *         ensured to exist, or vanishes between the ensure-step and the
     *         SELECT that must follow it (design D4: "No row = 500 +
     *         issuance.resolve_failed").
     */
    public function resolve( int $playerId, int $liveUserId, int $livePhotoAttachmentId, int $now ): array {
        $this->ensureRowExists( $playerId, $liveUserId, $livePhotoAttachmentId, $now );

        $row = $this->selectRow( $playerId );

        if ( null === $row ) {
            $this->eventLog->record( 'issuance.resolve_failed', [
                'player_id' => $playerId,
                'step'      => 'select_after_ensure',
            ] );

            throw new IssuanceResolutionFailedException(
                "No credencial_issuance row found for player {$playerId} right after ensuring it exists."
            );
        }

        if ( ! $this->needsRotation( $row, $liveUserId, $livePhotoAttachmentId ) ) {
            return $row;
        }

        return $this->rotate( $playerId, $row, $liveUserId, $livePhotoAttachmentId, $now );
    }

    /**
     * Design D4: `INSERT ... ON DUPLICATE KEY UPDATE player_id = player_id`
     * — a deliberate no-op update, used ONLY to make the statement succeed
     * (rather than error) when the row already exists. On a genuine INSERT
     * (first GET for this player) the row is created with today's live
     * values already in place; on a duplicate, this step touches nothing —
     * rotation (if needed) is decided and applied separately, below.
     */
    private function ensureRowExists( int $playerId, int $liveUserId, int $livePhotoAttachmentId, int $now ): void {
        $p      = $this->wpdb->prefix;
        $nowSql = gmdate( 'Y-m-d H:i:s', $now );
        $newId  = wp_generate_uuid4();

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$p}credencial_issuance
                    (player_id, credential_id, user_id, photo_attachment_id, minted_at, updated_at)
                 VALUES (%d, %s, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE player_id = player_id",
                $playerId,
                $newId,
                $liveUserId,
                $livePhotoAttachmentId,
                $nowSql,
                $nowSql
            )
        );

        if ( false === $result ) {
            $this->eventLog->record( 'issuance.resolve_failed', [
                'player_id' => $playerId,
                'step'      => 'ensure_row',
                'db_error'  => $this->wpdb->last_error,
            ] );

            throw new IssuanceResolutionFailedException(
                "Could not ensure a credencial_issuance row for player {$playerId}."
            );
        }
    }

    /**
     * Design D4's rotation trigger: the bound user changed, OR the approved
     * photo's attachment id changed — nothing else.
     */
    private function needsRotation( array $row, int $liveUserId, int $livePhotoAttachmentId ): bool {
        if ( (int) $row['user_id'] !== $liveUserId ) {
            return true;
        }

        return (int) $row['photo_attachment_id'] !== $livePhotoAttachmentId;
    }

    /**
     * Design D4's rotation statement, exactly:
     * `UPDATE ... SET credential_id=?new, user_id=?live, photo_attachment_id=?live,
     * updated_at=NOW() WHERE player_id=? AND credential_id=?old`, ALWAYS
     * followed by a re-SELECT — a concurrent winner's UPDATE may have already
     * changed credential_id, making OUR update match 0 rows; the re-SELECT
     * is what makes us return the winner's id instead of throwing or
     * fabricating an answer.
     */
    private function rotate( int $playerId, array $row, int $liveUserId, int $livePhotoAttachmentId, int $now ): array {
        $p      = $this->wpdb->prefix;
        $nowSql = gmdate( 'Y-m-d H:i:s', $now );
        $newId  = wp_generate_uuid4();

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$p}credencial_issuance
                    SET credential_id = %s, user_id = %d, photo_attachment_id = %d, updated_at = %s
                  WHERE player_id = %d AND credential_id = %s",
                $newId,
                $liveUserId,
                $livePhotoAttachmentId,
                $nowSql,
                $playerId,
                $row['credential_id']
            )
        );

        $final = $this->selectRow( $playerId );

        if ( null === $final ) {
            $this->eventLog->record( 'issuance.resolve_failed', [
                'player_id' => $playerId,
                'step'      => 'select_after_rotate',
            ] );

            throw new IssuanceResolutionFailedException(
                "No credencial_issuance row found for player {$playerId} after rotation."
            );
        }

        return $final;
    }

    /**
     * @return array{player_id:int, credential_id:string, user_id:int, photo_attachment_id:int, minted_at:string, updated_at:string}|null
     */
    private function selectRow( int $playerId ): ?array {
        $p   = $this->wpdb->prefix;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$p}credencial_issuance WHERE player_id = %d", $playerId ),
            ARRAY_A
        );

        if ( null === $row ) {
            return null;
        }

        return [
            'player_id'           => (int) $row['player_id'],
            'credential_id'       => (string) $row['credential_id'],
            'user_id'             => (int) $row['user_id'],
            'photo_attachment_id' => (int) ( $row['photo_attachment_id'] ?? 0 ),
            'minted_at'           => (string) $row['minted_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
    }
}
