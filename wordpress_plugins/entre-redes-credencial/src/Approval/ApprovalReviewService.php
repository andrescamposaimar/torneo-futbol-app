<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Approval;

use EntreRedes\Credencial\Observability\EventLog;
use EntreRedes\Credencial\Photo\MediaWriter;

/**
 * Design D11: approve() and reject() for a photo approval request, plus
 * sweepBlobs() (step (g), run on every "Fotos pendientes" page load —
 * Admin\PendingPhotosPage).
 *
 * Every step is idempotent and forward-retried (decision `credencial/design-simplificacion`:
 * "each step checks if already done; on failure the comisión clicks Approve
 * again" — no reverse saga, no compensation). "Finish publishing" an
 * approved-but-unpublished request is NOT a separate operation: it is calling
 * approve() again on the SAME request id — step (a) below sees `status =
 * 'approved'` and skips straight to the publish tail (f), re-running ONLY
 * that.
 *
 * The publish tail is keyed by PLAYER, not by request: it always publishes
 * whichever approved photo request is newest for that player
 * (isUnpublished()'s own predicate), so an older request's late retry can
 * never overwrite a newer approval (design: "newer approval wins").
 */
final class ApprovalReviewService {

    public const RESULT_PUBLISHED       = 'published';
    public const RESULT_UNPUBLISHED     = 'unpublished';
    public const RESULT_ALREADY_REJECTED = 'already_rejected';
    public const RESULT_STEP_FAILED     = 'step_failed';
    public const RESULT_NOT_FOUND       = 'not_found';

    private const MAX_TAIL_PASSES = 3;

    public function __construct(
        private ApprovalRequestRepository $requests,
        private MediaWriter $media,
        private EventLog $eventLog
    ) {}

    /**
     * @return self::RESULT_* One of this class's RESULT_ constants.
     */
    public function approve( int $requestId, int $reviewerUserId, int $now ): string {
        $request = $this->requests->findById( $requestId );

        if ( null === $request ) {
            return self::RESULT_NOT_FOUND;
        }

        if ( 'rejected' === $request['status'] ) {
            return self::RESULT_ALREADY_REJECTED;
        }

        $playerId = (int) $request['target_player_id'];

        if ( 'pending' === $request['status'] ) {
            $outcome = $this->claimPendingRequest( $requestId, $playerId, $reviewerUserId, $now );

            if ( null === $outcome ) {
                return self::RESULT_STEP_FAILED;
            }

            if ( self::RESULT_ALREADY_REJECTED === $outcome ) {
                return self::RESULT_ALREADY_REJECTED;
            }
        }

        // The request is now (or already was) approved — run the idempotent
        // publish tail for this player.
        $tailOk = $this->runPublishTail( $playerId, self::MAX_TAIL_PASSES );

        if ( $tailOk ) {
            // Only purge once published: a tail failure may still need this
            // request's own blob to recreate its attachment on retry (design
            // step (f).2 — Admin\PendingPhotosPage::sweepBlobs() is the
            // backstop for any blob a publish tail no longer needs).
            $this->purgeBlob( $requestId );
        }

        return $tailOk ? self::RESULT_PUBLISHED : self::RESULT_UNPUBLISHED;
    }

    /**
     * @return string|null null = stayed pending (step failed);
     *         self::RESULT_ALREADY_REJECTED = a concurrent run rejected it;
     *         'claimed' = approved (either by us or a concurrent run).
     */
    private function claimPendingRequest( int $requestId, int $playerId, int $reviewerUserId, int $now ): ?string {
        $survivor = $this->resolveOrCreateAttachment( $requestId, $playerId );

        if ( null === $survivor ) {
            return null;
        }

        try {
            $claimed = $this->requests->claimApproval( $requestId, $reviewerUserId, $survivor, $now );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'approve.step_failed', [
                'request_id' => $requestId,
                'step'       => 'claim',
                'exception'  => get_class( $e ),
                'message'    => $e->getMessage(),
            ] );

            return null;
        }

        if ( $claimed ) {
            return 'claimed';
        }

        // 0 rows: a concurrent run already decided this SAME request.
        $fresh = $this->requests->findById( $requestId );

        if ( null === $fresh || 'rejected' === $fresh['status'] ) {
            $this->deleteAttachmentsTaggedWith( $requestId );
            return self::RESULT_ALREADY_REJECTED;
        }

        // Concurrent approve. If its persisted attachment differs from our
        // local survivor, our survivor is an orphan — delete it (rev 7).
        $persisted = $fresh['attachment_id'];
        if ( null !== $persisted && $persisted !== $survivor ) {
            $this->media->deleteAttachment( $survivor );
        }

        return 'claimed';
    }

    /**
     * Design D11 reject: conditional UPDATE gates everything (0 rows ->
     * no-op, "already decided"). 1 row -> delete only the attachments tagged
     * with THIS request id (never the live thumbnail — a pending request is
     * never published) and purge its blob.
     */
    public function reject( int $requestId, int $reviewerUserId, ?string $note, int $now ): bool {
        $applied = $this->requests->rejectPending( $requestId, $reviewerUserId, $note, $now );

        if ( ! $applied ) {
            return false;
        }

        $this->deleteAttachmentsTaggedWith( $requestId );
        $this->purgeBlob( $requestId );

        return true;
    }

    /**
     * Design D11 step (g), second half: "On every Fotos pendientes load, a
     * sweep deletes blobs of requests that are neither pending nor
     * isUnpublished". Pending and approved-but-unpublished blobs are kept —
     * the publish tail may still need them (design step (f).2: "re-run (b)-(d)
     * from L's blob" when the attachment vanished).
     */
    public function sweepBlobs(): void {
        foreach ( $this->requests->findRequestIdsWithBlob( ApprovalRequestRepository::TYPE_PHOTO ) as $row ) {
            if ( 'pending' === $row['status'] ) {
                continue;
            }

            if ( 'approved' === $row['status'] ) {
                $playerId = (int) $row['target_player_id'];
                $liveThumb = $this->media->getFeaturedImageId( $playerId );

                if ( $this->requests->isUnpublished( $playerId, $liveThumb ) ) {
                    $newest = $this->requests->findNewestApprovedPhotoRequest( $playerId );
                    if ( null !== $newest && $newest['id'] === $row['id'] ) {
                        continue; // THIS row is the one still unpublished — keep its blob.
                    }
                }
            }

            // rejected, or approved-but-superseded, or approved-and-published.
            $this->purgeBlob( $row['id'] );
        }
    }

    // -------------------------------------------------------------------------
    // Design D11 steps (b)-(d): find-or-create, dedupe, metadata.
    // -------------------------------------------------------------------------

    private function resolveOrCreateAttachment( int $requestId, int $playerId ): ?int {
        try {
            $tagged = $this->media->findAttachmentsTaggedWithRequest( $requestId );

            if ( empty( $tagged ) ) {
                $binary = $this->requests->getBlobBinary( $requestId );

                if ( null === $binary ) {
                    $this->eventLog->record( 'approve.step_failed', [
                        'request_id' => $requestId,
                        'step'       => 'missing_blob',
                    ] );
                    return null;
                }

                $this->media->createAttachment( $binary, $requestId );
            }

            // Design D11 step (c): re-query the tagged attachments (rather
            // than reusing the set captured before create()) so a concurrent
            // run's own create — landing between our find and our create
            // above — is already visible to the dedupe below, not just to a
            // later run's own pass.
            $tagged = $this->media->findAttachmentsTaggedWithRequest( $requestId );

            sort( $tagged );
            $survivor = $tagged[0];

            foreach ( array_slice( $tagged, 1 ) as $duplicateId ) {
                $this->media->deleteAttachment( $duplicateId );
            }

            $this->media->ensureMetadataGenerated( $survivor );

            return $survivor;
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'approve.step_failed', [
                'request_id' => $requestId,
                'player_id'  => $playerId,
                'step'       => 'resolve_attachment',
                'exception'  => get_class( $e ),
                'message'    => $e->getMessage(),
            ] );

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Design D11 step (f): the publish tail.
    // -------------------------------------------------------------------------

    private function runPublishTail( int $playerId, int $passesLeft ): bool {
        if ( $passesLeft <= 0 ) {
            $this->eventLog->record( 'approve.publish_failed', [ 'player_id' => $playerId, 'step' => 'max_passes_exceeded' ] );
            return false;
        }

        $newest = $this->requests->findNewestApprovedPhotoRequest( $playerId );

        if ( null === $newest ) {
            return true; // Nothing approved for this player — no-op, not a failure.
        }

        $liveThumb = $this->media->getFeaturedImageId( $playerId );

        if ( $newest['attachment_id'] === $liveThumb ) {
            return true; // Already published.
        }

        $attachmentId = $newest['attachment_id'];

        if ( null === $attachmentId || ! $this->media->attachmentExists( $attachmentId ) ) {
            $attachmentId = $this->resolveOrCreateAttachment( $newest['id'], $playerId );

            if ( null === $attachmentId ) {
                $this->eventLog->record( 'approve.publish_failed', [
                    'player_id'  => $playerId,
                    'request_id' => $newest['id'],
                    'step'       => 'resolve_attachment_in_tail',
                ] );
                return false;
            }

            $this->requests->setAttachmentId( $newest['id'], $attachmentId );
        }

        try {
            $this->media->setFeaturedImage( $playerId, $attachmentId );

            do_action( 'save_post_sp_player', $playerId );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'approve.publish_failed', [
                'player_id'  => $playerId,
                'request_id' => $newest['id'],
                'step'       => 'tail',
                'exception'  => get_class( $e ),
                'message'    => $e->getMessage(),
            ] );
            return false;
        }

        // Re-read: a newer approval may have landed WHILE this tail ran.
        $recheck = $this->requests->findNewestApprovedPhotoRequest( $playerId );
        if ( null !== $recheck && $recheck['id'] !== $newest['id'] ) {
            return $this->runPublishTail( $playerId, $passesLeft - 1 );
        }

        return true;
    }

    private function deleteAttachmentsTaggedWith( int $requestId ): void {
        foreach ( $this->media->findAttachmentsTaggedWithRequest( $requestId ) as $attachmentId ) {
            $this->media->deleteAttachment( $attachmentId );
        }
    }

    private function purgeBlob( int $requestId ): void {
        $this->requests->deleteBlob( $requestId );
    }
}
