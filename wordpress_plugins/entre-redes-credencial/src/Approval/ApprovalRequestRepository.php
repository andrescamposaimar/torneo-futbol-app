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
}
