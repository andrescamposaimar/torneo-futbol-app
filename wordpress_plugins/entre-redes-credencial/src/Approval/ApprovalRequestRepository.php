<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Approval;

use EntreRedes\Credencial\Approval\Exception\AlreadyPendingException;
use EntreRedes\Credencial\Approval\Exception\ApprovalPersistenceException;
use EntreRedes\Credencial\Db\DbErrors;
use EntreRedes\Credencial\Observability\EventLog;
use EntreRedes\Credencial\Support\OpensTransactions;

/**
 * Owns `credencial_approval_request` + `credencial_approval_blob` (design
 * D8/D10). The table is generic — `type` distinguishes request kinds so
 * future stages can add other types to the SAME table without a schema
 * change (see Migrations\InitialSchema::sqlCredencialApprovalRequest()).
 * Slice 2a only ever writes TYPE_PHOTO.
 *
 * `createPendingPhotoRequest()` does NOT pre-check "is there already a
 * pending request" before inserting: the `pending_key` generated column +
 * UNIQUE index (InitialSchema::ensurePendingKeyIndex(), real MySQL only —
 * see that method's own docblock for why it is a no-op under the SQLite test
 * shim) is the actual enforcement, closing the TOCTOU race a
 * SELECT-then-INSERT guard alone cannot close. This method's only job is to
 * translate that DB-level rejection into AlreadyPendingException.
 */
final class ApprovalRequestRepository {
    use OpensTransactions;

    /** Design D10: this generic table's only request kind in Stage 1. */
    public const TYPE_PHOTO = 'photo';

    /**
     * The photo request carries no extra payload data of its own — every
     * fact about it (player, requester, timestamps, the pending photo bytes)
     * already lives in a dedicated column. Stored as a valid, empty JSON
     * object so a future request type sharing this table can rely on the
     * column always parsing as JSON, never as an empty string.
     */
    private const EMPTY_PAYLOAD = '{}';

    private \wpdb $wpdb;
    private EventLog $eventLog;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * Inserts a pending photo request and its blob (design D8: "Re-encoded
     * JPEG in credencial_approval_blob") in one transaction — both rows
     * exist, or neither does.
     *
     * @throws AlreadyPendingException When a pending photo request already
     *         exists for this player.
     * @throws ApprovalPersistenceException On any other DB failure (request
     *         insert or blob insert).
     */
    public function createPendingPhotoRequest( int $playerId, int $requestedByUserId, string $photoBinary, int $now ): int {
        $this->beginTransaction( __FUNCTION__, [ 'player_id' => $playerId ] );

        try {
            $nowSql = gmdate( 'Y-m-d H:i:s', $now );

            $ok = $this->wpdb->insert(
                $this->wpdb->prefix . 'credencial_approval_request',
                [
                    'type'             => self::TYPE_PHOTO,
                    'target_player_id' => $playerId,
                    'requested_by'     => $requestedByUserId,
                    'payload'          => self::EMPTY_PAYLOAD,
                    'status'           => 'pending',
                    'created_at'       => $nowSql,
                ]
            );

            if ( false === $ok ) {
                $error = (string) $this->wpdb->last_error;

                if ( DbErrors::isDuplicateKey( $error ) ) {
                    throw new AlreadyPendingException( $playerId );
                }

                throw new ApprovalPersistenceException(
                    "Could not insert a pending photo request for player {$playerId}: {$error}"
                );
            }

            $requestId = (int) $this->wpdb->insert_id;

            $ok = $this->wpdb->insert(
                $this->wpdb->prefix . 'credencial_approval_blob',
                [
                    'request_id'   => $requestId,
                    'photo_binary' => $photoBinary,
                    'created_at'   => $nowSql,
                ]
            );

            if ( false === $ok ) {
                throw new ApprovalPersistenceException(
                    "Could not insert the pending photo blob for request {$requestId}: " . (string) $this->wpdb->last_error
                );
            }

            $this->commitTransaction( __FUNCTION__, [ 'player_id' => $playerId, 'request_id' => $requestId ] );

            return $requestId;
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e, [ 'player_id' => $playerId ] );

            // AlreadyPendingException is an expected business conflict, not a
            // failure worth an event — same discipline as CredencialController
            // not logging every 401.
            if ( ! $e instanceof AlreadyPendingException ) {
                $this->eventLog->record( 'approval.request_persistence_failed', [
                    'player_id' => $playerId,
                    'exception' => get_class( $e ),
                    'message'   => $e->getMessage(),
                ] );
            }

            throw $e;
        }
    }

    /**
     * The newest approved PHOTO request for this player, or null if none was
     * ever approved. Type-scoped (design D11/D12 rev 8): the generic table
     * is shared with future stages, so an approved request of another type
     * must never shadow or be published as a photo.
     *
     * @return array{id:int, attachment_id:?int, reviewed_at:?string}|null
     */
    public function findNewestApprovedPhotoRequest( int $playerId ): ?array {
        $p   = $this->wpdb->prefix;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, attachment_id, reviewed_at FROM {$p}credencial_approval_request
                  WHERE target_player_id = %d AND status = 'approved' AND type = %s
                  ORDER BY reviewed_at DESC, id DESC LIMIT 1",
                $playerId,
                self::TYPE_PHOTO
            )
        );

        if ( null === $row ) {
            return null;
        }

        return [
            'id'            => (int) $row['id'],
            'attachment_id' => null === $row['attachment_id'] ? null : (int) $row['attachment_id'],
            'reviewed_at'   => $row['reviewed_at'] ?? null,
        ];
    }

    /**
     * Design D11/D12 `isUnpublished` predicate, scoped to the photo type
     * (rev 8): true only when the newest approved photo request for this
     * player exists AND its `attachment_id` differs from the player's LIVE
     * thumbnail attachment id — resolved by the CALLER, since this
     * repository has no WordPress media knowledge of its own (that belongs
     * to a later slice's ApprovalReviewService / WpMediaWriter).
     */
    public function isUnpublished( int $playerId, ?int $liveThumbnailAttachmentId ): bool {
        $newest = $this->findNewestApprovedPhotoRequest( $playerId );

        if ( null === $newest ) {
            return false;
        }

        return $newest['attachment_id'] !== $liveThumbnailAttachmentId;
    }

    /**
     * Count of $type requests created for this player since $sinceEpoch —
     * backs Rest\PhotoUploadController's upload rate limit (design D9: "5
     * uploads/24h (429)").
     */
    public function countRequestsSince( int $playerId, string $type, int $sinceEpoch ): int {
        $p        = $this->wpdb->prefix;
        $sinceSql = gmdate( 'Y-m-d H:i:s', $sinceEpoch );

        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}credencial_approval_request
                  WHERE target_player_id = %d AND type = %s AND created_at >= %s",
                $playerId,
                $type,
                $sinceSql
            )
        );
    }

    // -------------------------------------------------------------------------
    // Slice 2b: design D11 approve/reject.
    // -------------------------------------------------------------------------

    /**
     * @return array{id:int, type:string, target_player_id:?int, requested_by:int, status:string, attachment_id:?int, review_note:?string, reviewed_by:?int, reviewed_at:?string, created_at:string}|null
     */
    public function findById( int $id ): ?array {
        $p   = $this->wpdb->prefix;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$p}credencial_approval_request WHERE id = %d", $id )
        );

        if ( null === $row ) {
            return null;
        }

        return [
            'id'               => (int) $row['id'],
            'type'             => (string) $row['type'],
            'target_player_id' => null === $row['target_player_id'] ? null : (int) $row['target_player_id'],
            'requested_by'     => (int) $row['requested_by'],
            'status'           => (string) $row['status'],
            'attachment_id'    => null === $row['attachment_id'] ? null : (int) $row['attachment_id'],
            'review_note'      => $row['review_note'] ?? null,
            'reviewed_by'      => null === ( $row['reviewed_by'] ?? null ) ? null : (int) $row['reviewed_by'],
            'reviewed_at'      => $row['reviewed_at'] ?? null,
            'created_at'       => (string) $row['created_at'],
        ];
    }

    /**
     * Design D11 step (e), the CLAIM: `UPDATE ... SET status='approved',
     * attachment_id=?, reviewed_by=?, reviewed_at=NOW() WHERE id=? AND
     * status='pending'`. Returns true only when exactly this call moved the
     * row from pending to approved — false means a concurrent run already
     * decided it (approved OR rejected); the caller re-reads to find out
     * which (design: "0 rows -> re-read the request").
     */
    public function claimApproval( int $id, int $reviewerUserId, int $attachmentId, int $now ): bool {
        $p      = $this->wpdb->prefix;
        $nowSql = gmdate( 'Y-m-d H:i:s', $now );

        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$p}credencial_approval_request
                    SET status = 'approved', attachment_id = %d, reviewed_by = %d, reviewed_at = %s
                  WHERE id = %d AND status = 'pending'",
                $attachmentId,
                $reviewerUserId,
                $nowSql,
                $id
            )
        );

        if ( false === $affected ) {
            $this->eventLog->record( 'approve.claim_query_failed', [ 'request_id' => $id, 'db_error' => $this->wpdb->last_error ] );

            throw new ApprovalPersistenceException( "Could not run the approval claim for request {$id}." );
        }

        return $affected > 0;
    }

    /**
     * Publish-tail step (f).2: re-points an already-approved row at a
     * different attachment (e.g. its original attachment vanished and had to
     * be re-created). Unconditional by design — unlike claimApproval(), there
     * is no pending/approved race to guard here; only the tail itself calls
     * this, one player-keyed run at a time.
     */
    public function setAttachmentId( int $id, int $attachmentId ): void {
        $p = $this->wpdb->prefix;

        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$p}credencial_approval_request SET attachment_id = %d WHERE id = %d",
                $attachmentId,
                $id
            )
        );
    }

    /**
     * Design D11 reject: `UPDATE ... SET status='rejected', reviewed_by,
     * reviewed_at, review_note WHERE id=? AND status='pending'`. Returns
     * false on 0 rows ("already decided" — a no-op, design: "Nothing else is
     * touched").
     */
    public function rejectPending( int $id, int $reviewerUserId, ?string $note, int $now ): bool {
        $p      = $this->wpdb->prefix;
        $nowSql = gmdate( 'Y-m-d H:i:s', $now );

        $affected = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$p}credencial_approval_request
                    SET status = 'rejected', reviewed_by = %d, reviewed_at = %s, review_note = %s
                  WHERE id = %d AND status = 'pending'",
                $reviewerUserId,
                $nowSql,
                $note ?? '',
                $id
            )
        );

        if ( false === $affected ) {
            $this->eventLog->record( 'reject.query_failed', [ 'request_id' => $id, 'db_error' => $this->wpdb->last_error ] );

            throw new ApprovalPersistenceException( "Could not run the reject for request {$id}." );
        }

        return $affected > 0;
    }

    /** The pending photo bytes for $requestId (design D8), or null once purged. */
    public function getBlobBinary( int $requestId ): ?string {
        $p   = $this->wpdb->prefix;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT photo_binary FROM {$p}credencial_approval_blob WHERE request_id = %d", $requestId )
        );

        return null === $row ? null : (string) $row['photo_binary'];
    }

    /**
     * Design D11 step (g): purges the blob once a request is decided (or
     * already published). A purge failure is logged, never thrown — losing a
     * blob AFTER the decision it backed is recorded does not undo that
     * decision (design: "A purge failure is logged").
     */
    public function deleteBlob( int $requestId ): void {
        $p = $this->wpdb->prefix;

        $ok = $this->wpdb->query(
            $this->wpdb->prepare( "DELETE FROM {$p}credencial_approval_blob WHERE request_id = %d", $requestId )
        );

        if ( false === $ok ) {
            $this->eventLog->record( 'approve.blob_purge_failed', [ 'request_id' => $requestId, 'db_error' => $this->wpdb->last_error ] );
        }
    }

    /**
     * Design D12: every pending request of $type, oldest first — the "Fotos
     * pendientes" admin page's Pending rows.
     *
     * @return array<int, array{id:int, target_player_id:?int, requested_by:int, created_at:string}>
     */
    public function findPendingByType( string $type ): array {
        $p    = $this->wpdb->prefix;
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, target_player_id, requested_by, created_at FROM {$p}credencial_approval_request
                  WHERE type = %s AND status = 'pending' ORDER BY created_at ASC",
                $type
            )
        );

        return array_map(
            static fn( array $row ): array => [
                'id'               => (int) $row['id'],
                'target_player_id' => null === $row['target_player_id'] ? null : (int) $row['target_player_id'],
                'requested_by'     => (int) $row['requested_by'],
                'created_at'       => (string) $row['created_at'],
            ],
            $rows
        );
    }

    /**
     * Distinct player ids with at least one approved $type request — the
     * Admin\PendingPhotosPage "Approved, not yet published" list iterates
     * these and asks isUnpublished() (a media-aware caller check, design
     * D11/D12 rev 8) per player, rather than trying to express that
     * cross-table check in one query here.
     *
     * @return int[]
     */
    public function findApprovedPlayerIds( string $type ): array {
        $p    = $this->wpdb->prefix;
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT DISTINCT target_player_id FROM {$p}credencial_approval_request
                  WHERE type = %s AND status = 'approved'",
                $type
            )
        );

        return array_map( static fn( array $row ): int => (int) $row['target_player_id'], $rows );
    }

    /**
     * Every $type request that still has a blob row — backs
     * Approval\ApprovalReviewService::sweepBlobs() (design D11 step (g): "On
     * every Fotos pendientes load, a sweep deletes blobs of requests that are
     * neither pending nor isUnpublished"). The sweep itself decides which of
     * these to purge; this method only reports what still has bytes to purge.
     *
     * @return array<int, array{id:int, status:string, target_player_id:?int}>
     */
    public function findRequestIdsWithBlob( string $type ): array {
        $p    = $this->wpdb->prefix;
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT r.id AS id, r.status AS status, r.target_player_id AS target_player_id
                   FROM {$p}credencial_approval_request r
                   INNER JOIN {$p}credencial_approval_blob b ON b.request_id = r.id
                  WHERE r.type = %s",
                $type
            )
        );

        return array_map(
            static fn( array $row ): array => [
                'id'               => (int) $row['id'],
                'status'           => (string) $row['status'],
                'target_player_id' => null === $row['target_player_id'] ? null : (int) $row['target_player_id'],
            ],
            $rows
        );
    }
}
