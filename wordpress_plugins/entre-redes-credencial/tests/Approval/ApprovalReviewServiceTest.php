<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Approval;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Approval\ApprovalReviewService;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Tests\Support\FakeMediaWriter;
use EntreRedes\Credencial\Tests\Support\FaultInjectingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * Design D11: approve()/reject()/sweepBlobs(). Every mandatory scenario from
 * the design's own Testing Strategy list is exercised here. Media-level
 * failures ((b) create, (d) metadata, (f).3 set featured image) go through
 * Tests\Support\FakeMediaWriter; DB-level failures/races ((e) the claim) go
 * through a second, separately-schema'd tests/Support/FaultInjectingWpdb.php
 * instance (same pattern ApprovalRequestRepositoryTest's own duplicate-key
 * tests already use) — same split the design's own approve algorithm draws
 * between "media" and "the claim".
 */
class ApprovalReviewServiceTest extends TestCase {

    private const NOW    = 1_800_000_000;
    private const PLAYER = 7;

    private ApprovalRequestRepository $requests;
    private FakeMediaWriter $media;
    private InMemoryEventLog $eventLog;
    private ApprovalReviewService $service;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        // save_post_sp_player listeners must not leak between tests — the
        // shim's add_action()/do_action() globals are process-wide.
        unset( $GLOBALS['_prode_test_action_callbacks']['save_post_sp_player'] );
        unset( $GLOBALS['_prode_test_actions']['save_post_sp_player'] );

        $this->eventLog = new InMemoryEventLog();
        $this->requests = new ApprovalRequestRepository( $wpdb, $this->eventLog );
        $this->media    = new FakeMediaWriter();
        $this->service  = new ApprovalReviewService( $this->requests, $this->media, $this->eventLog );
    }

    /**
     * setUp() only clears hooks before the next test of THIS class. The
     * shim's add_action() registry is process-wide, so a
     * `save_post_sp_player` listener left by this class's last test (the
     * race simulations register one) would fire inside whatever test class
     * runs next and calls do_action() — e.g. PendingPhotosPageTest — and
     * insert phantom approval rows there.
     */
    protected function tearDown(): void {
        unset( $GLOBALS['_prode_test_action_callbacks']['save_post_sp_player'] );
        unset( $GLOBALS['_prode_test_actions']['save_post_sp_player'] );
    }

    /**
     * @return array{0: FaultInjectingWpdb, 1: ApprovalRequestRepository}
     */
    private function faultyRepository(): array {
        $wpdb = new FaultInjectingWpdb();
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_approval_request (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT, target_player_id INTEGER, requested_by INTEGER,
                payload TEXT, status TEXT, attachment_id INTEGER,
                review_note TEXT, reviewed_by INTEGER, reviewed_at TEXT, created_at TEXT
            )'
        );
        $wpdb->getPdo()->exec(
            'CREATE TABLE wp_credencial_approval_blob (
                request_id INTEGER PRIMARY KEY, photo_binary BLOB, created_at TEXT
            )'
        );

        return [ $wpdb, new ApprovalRequestRepository( $wpdb, $this->eventLog ) ];
    }

    // -------------------------------------------------------------------------
    // Happy path.
    // -------------------------------------------------------------------------

    public function test_approve_a_pending_request_publishes_it(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result );

        $attachmentId = $this->requests->findById( $requestId )['attachment_id'];
        $this->assertNotNull( $attachmentId );
        $this->assertSame( $attachmentId, $this->media->getFeaturedImageId( self::PLAYER ) );
        $this->assertTrue( $this->media->metadataWasGenerated( $attachmentId ) );
    }

    public function test_approve_fires_the_cache_invalidation_hook(): void {
        $fired = false;
        add_action( 'save_post_sp_player', function () use ( &$fired ) {
            $fired = true;
        } );

        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );
        $this->service->approve( $requestId, 1, self::NOW );

        $this->assertTrue( $fired );
    }

    public function test_approve_purges_the_blob_once_published(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $this->service->approve( $requestId, 1, self::NOW );

        $this->assertNull( $this->requests->getBlobBinary( $requestId ) );
    }

    // -------------------------------------------------------------------------
    // Failures at (b)-(e) leave the request pending, nothing public.
    // -------------------------------------------------------------------------

    public function test_a_failure_creating_the_attachment_leaves_the_request_pending(): void {
        $this->media->failCreateAttachment = true;
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_STEP_FAILED, $result );
        $this->assertSame( 'pending', $this->requests->findById( $requestId )['status'] );
        $this->assertNull( $this->media->getFeaturedImageId( self::PLAYER ) );
        $this->assertNotNull( $this->requests->getBlobBinary( $requestId ), 'the blob must survive an unresolved failure for a later retry' );
    }

    public function test_a_metadata_failure_leaves_the_request_pending(): void {
        $this->media->failEnsureMetadata = true;
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_STEP_FAILED, $result );
        $this->assertSame( 'pending', $this->requests->findById( $requestId )['status'] );
    }

    public function test_a_claim_db_failure_leaves_the_request_pending(): void {
        [ $faulty, $faultyRequests ] = $this->faultyRepository();
        $requestId = $faultyRequests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $faulty->failNextQueryMatching(
            '/SET status = .approved./i',
            'Deadlock found; try restarting transaction'
        );

        $service = new ApprovalReviewService( $faultyRequests, $this->media, $this->eventLog );
        $result  = $service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_STEP_FAILED, $result );
        $this->assertSame( 'pending', $faultyRequests->findById( $requestId )['status'] );
    }

    // -------------------------------------------------------------------------
    // Retry converges to the lowest attachment id.
    // -------------------------------------------------------------------------

    public function test_retry_converges_to_the_lowest_tagged_attachment_id(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        // Simulate two earlier partial runs that each created (and tagged)
        // their own unlinked attachment for the SAME request, without either
        // completing the claim — e.g. two admins double-clicking Approve.
        $low  = $this->media->createAttachment( 'photo-bytes', $requestId );
        $high = $this->media->createAttachment( 'photo-bytes', $requestId );
        $this->assertLessThan( $high, $low );

        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result );
        $this->assertSame( $low, $this->requests->findById( $requestId )['attachment_id'] );
        $this->assertFalse( $this->media->attachmentExists( $high ), 'the duplicate must be deleted' );
        $this->assertSame( $low, $this->media->getFeaturedImageId( self::PLAYER ) );
    }

    // -------------------------------------------------------------------------
    // Concurrency at the claim (step e).
    // -------------------------------------------------------------------------

    public function test_a_concurrent_approve_with_a_different_persisted_attachment_deletes_the_local_survivor(): void {
        [ $faulty, $faultyRequests ] = $this->faultyRepository();
        $requestId = $faultyRequests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        // The "other" concurrent run's attachment already exists in the
        // media library (untagged here on purpose — see FakeMediaWriter's
        // docblock) and its claim commits first, right before OUR claim runs.
        $this->media->seedAttachment( 999 );

        $faulty->runSqlBeforeNextQueryMatching(
            '/SET status = .approved./i',
            "UPDATE wp_credencial_approval_request SET status='approved', attachment_id=999, reviewed_by=2, reviewed_at='" . gmdate( 'Y-m-d H:i:s', self::NOW ) . "' WHERE id={$requestId}"
        );

        $service = new ApprovalReviewService( $faultyRequests, $this->media, $this->eventLog );
        $result  = $service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result );
        $this->assertSame( 999, $faultyRequests->findById( $requestId )['attachment_id'] );
        $this->assertSame( 999, $this->media->getFeaturedImageId( self::PLAYER ) );

        // The local survivor this run created for itself before losing the
        // race must not remain as an orphan.
        $stillTagged = $this->media->findAttachmentsTaggedWithRequest( $requestId );
        $this->assertSame( [], array_diff( $stillTagged, [ 999 ] ), 'no orphan attachment besides the persisted one may remain' );
    }

    public function test_a_concurrent_reject_during_approve_deletes_the_tagged_attachments(): void {
        [ $faulty, $faultyRequests ] = $this->faultyRepository();
        $requestId = $faultyRequests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $faulty->runSqlBeforeNextQueryMatching(
            '/SET status = .approved./i',
            "UPDATE wp_credencial_approval_request SET status='rejected', reviewed_by=2, reviewed_at='" . gmdate( 'Y-m-d H:i:s', self::NOW ) . "' WHERE id={$requestId}"
        );

        $service = new ApprovalReviewService( $faultyRequests, $this->media, $this->eventLog );
        $result  = $service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_ALREADY_REJECTED, $result );
        $this->assertSame( [], $this->media->findAttachmentsTaggedWithRequest( $requestId ) );
        $this->assertNull( $this->media->getFeaturedImageId( self::PLAYER ) );
    }

    // -------------------------------------------------------------------------
    // Tail failure -> approved-but-unpublished -> Finish publishing.
    // -------------------------------------------------------------------------

    public function test_a_tail_failure_leaves_approved_and_unpublished_then_finish_publishing_completes_without_recreating_media(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );

        $this->media->failSetFeaturedImage = true;
        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_UNPUBLISHED, $result );
        $this->assertSame( 'approved', $this->requests->findById( $requestId )['status'] );
        $this->assertNull( $this->media->getFeaturedImageId( self::PLAYER ) );
        $this->assertTrue( $this->requests->isUnpublished( self::PLAYER, null ) );
        $this->assertSame( 1, $this->media->createAttachmentCallCount( $requestId ) );

        // Finish publishing == approve() again on the same (now approved) request.
        $this->media->failSetFeaturedImage = false;
        $result2 = $this->service->approve( $requestId, 1, self::NOW + 60 );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result2 );
        $this->assertSame( 1, $this->media->createAttachmentCallCount( $requestId ), 'finishing publishing must not recreate media' );
        $attachmentId = $this->requests->findById( $requestId )['attachment_id'];
        $this->assertSame( $attachmentId, $this->media->getFeaturedImageId( self::PLAYER ) );
    }

    public function test_a_cache_hook_exception_counts_as_a_tail_failure_and_is_retried_later(): void {
        add_action( 'save_post_sp_player', function () {
            throw new \RuntimeException( 'cache invalidation blew up' );
        } );

        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );
        $result    = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_UNPUBLISHED, $result );
        $this->assertSame( 'approved', $this->requests->findById( $requestId )['status'] );

        unset( $GLOBALS['_prode_test_action_callbacks']['save_post_sp_player'] );
        $result2 = $this->service->approve( $requestId, 1, self::NOW + 60 );
        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result2 );
    }

    // -------------------------------------------------------------------------
    // Newer approval wins; superseded approval is never actionable.
    // -------------------------------------------------------------------------

    public function test_newer_approval_wins_when_an_older_tail_runs_late(): void {
        $requestA = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'a-bytes', self::NOW );
        $requestB = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'b-bytes', self::NOW + 10 );

        // A's claim succeeds (simulating its tail not having run yet) — but
        // B is approved AND published first.
        $survivorA = $this->media->createAttachment( 'a-bytes', $requestA );
        $this->requests->claimApproval( $requestA, 1, $survivorA, self::NOW );

        $resultB = $this->service->approve( $requestB, 1, self::NOW + 20 );
        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $resultB );
        $attachmentB = $this->requests->findById( $requestB )['attachment_id'];

        // A's tail now runs late (e.g. a retried "Finish publishing" on A).
        $resultA = $this->service->approve( $requestA, 1, self::NOW + 30 );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $resultA );
        $this->assertSame( $attachmentB, $this->media->getFeaturedImageId( self::PLAYER ), "A's late tail must not overwrite B's photo" );
    }

    public function test_superseded_approval_is_never_actionable(): void {
        $requestA = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'a-bytes', self::NOW );
        $this->service->approve( $requestA, 1, self::NOW );

        $requestB = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'b-bytes', self::NOW + 10 );
        $this->service->approve( $requestB, 1, self::NOW + 10 );

        $this->assertFalse( $this->requests->isUnpublished( self::PLAYER, $this->media->getFeaturedImageId( self::PLAYER ) ) );

        // A late click on A must be a harmless no-op tail run.
        $attachmentBefore = $this->media->getFeaturedImageId( self::PLAYER );
        $result = $this->service->approve( $requestA, 1, self::NOW + 20 );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result );
        $this->assertSame( $attachmentBefore, $this->media->getFeaturedImageId( self::PLAYER ) );
    }

    // -------------------------------------------------------------------------
    // Stale-write race fixed by the re-read loop (design D11 step (f),
    // mandated by the Testing Strategy — verify-report 2b/2c gate CRITICAL #2).
    // -------------------------------------------------------------------------

    /**
     * A newer approval for the SAME player lands WHILE the tail is running —
     * specifically, right after its own do_action('save_post_sp_player')
     * cache-invalidation call and before the tail's own post-action re-read.
     * The tail must detect this via its re-read and recurse to publish the
     * newer request, never leaving the stale one as the live thumbnail.
     */
    public function test_a_newer_approval_landing_mid_tail_is_published_instead_of_the_stale_one(): void {
        $requestA = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'a-bytes', self::NOW );

        $requestB = null;
        add_action( 'save_post_sp_player', function () use ( &$requestB ) {
            if ( null !== $requestB ) {
                return; // Only inject the race once — see the "gives up" test below for the unbounded case.
            }

            $requestB  = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'b-bytes', self::NOW + 10 );
            $survivorB = $this->media->createAttachment( 'b-bytes', $requestB );
            $this->requests->claimApproval( $requestB, 2, $survivorB, self::NOW + 10 );
        } );

        $result = $this->service->approve( $requestA, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_PUBLISHED, $result );
        $attachmentB = $this->requests->findById( $requestB )['attachment_id'];
        $this->assertNotNull( $attachmentB );
        $this->assertSame(
            $attachmentB,
            $this->media->getFeaturedImageId( self::PLAYER ),
            'the re-read loop must publish the request that became newest mid-tail, not the stale one the tail started with'
        );
    }

    /**
     * An adversarial version of the same race: a newer approval lands on
     * EVERY pass, forever. The loop must give up after MAX_TAIL_PASSES (3),
     * report the tail as failed (approved-but-unpublished), and must not
     * purge the original request's blob.
     */
    public function test_the_publish_tail_gives_up_after_three_passes_when_the_race_never_settles(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'a-bytes', self::NOW );

        $racesFired = 0;
        add_action( 'save_post_sp_player', function () use ( &$racesFired ) {
            $racesFired++;
            $raceRequestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, "race-{$racesFired}-bytes", self::NOW + $racesFired );
            $survivor      = $this->media->createAttachment( "race-{$racesFired}-bytes", $raceRequestId );
            $this->requests->claimApproval( $raceRequestId, 2, $survivor, self::NOW + $racesFired );
        } );

        $result = $this->service->approve( $requestId, 1, self::NOW );

        $this->assertSame( ApprovalReviewService::RESULT_UNPUBLISHED, $result );
        $this->assertSame( 'approved', $this->requests->findById( $requestId )['status'] );
        $this->assertNotNull( $this->requests->getBlobBinary( $requestId ), 'a tail that never converges must not purge its own blob' );
        $this->assertSame( 3, $racesFired, 'MAX_TAIL_PASSES = 3: the hook must fire exactly once per pass, never more' );
    }

    // -------------------------------------------------------------------------
    // Reject.
    // -------------------------------------------------------------------------

    public function test_reject_a_pending_request_deletes_tagged_attachments_and_purges_the_blob(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );
        // Simulate a leftover attachment from a previous failed approve attempt.
        $this->media->createAttachment( 'photo-bytes', $requestId );

        $applied = $this->service->reject( $requestId, 1, 'no se ve bien', self::NOW );

        $this->assertTrue( $applied );
        $this->assertSame( 'rejected', $this->requests->findById( $requestId )['status'] );
        $this->assertSame( [], $this->media->findAttachmentsTaggedWithRequest( $requestId ) );
        $this->assertNull( $this->requests->getBlobBinary( $requestId ) );
    }

    public function test_reject_is_a_noop_when_already_decided(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );
        $this->service->approve( $requestId, 1, self::NOW );

        $applied = $this->service->reject( $requestId, 1, 'demasiado tarde', self::NOW + 10 );

        $this->assertFalse( $applied );
        $this->assertSame( 'approved', $this->requests->findById( $requestId )['status'] );
    }

    public function test_approve_on_an_already_rejected_request_is_a_noop(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'photo-bytes', self::NOW );
        $this->service->reject( $requestId, 1, null, self::NOW );

        $result = $this->service->approve( $requestId, 1, self::NOW + 10 );

        $this->assertSame( ApprovalReviewService::RESULT_ALREADY_REJECTED, $result );
        $this->assertNull( $this->media->getFeaturedImageId( self::PLAYER ) );
    }

    // -------------------------------------------------------------------------
    // sweepBlobs().
    // -------------------------------------------------------------------------

    public function test_sweepBlobs_keeps_pending_and_unpublished_blobs_but_purges_the_rest(): void {
        $pendingId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'p-bytes', self::NOW );

        $rejectedId = $this->requests->createPendingPhotoRequest( 8, 100, 'r-bytes', self::NOW );
        $this->requests->rejectPending( $rejectedId, 1, null, self::NOW );
        // Reinsert its blob directly to simulate a straggler the sweep must catch.
        // rejectPending() called directly on the repository (not via
        // service->reject()) deliberately leaves the blob in place — this
        // reproduces a "straggler" rejected row exactly as it would exist if
        // a purge failed earlier, without needing to fake a DB error to get
        // there.
        $this->assertNotNull( $this->requests->getBlobBinary( $rejectedId ) );

        $publishedId = $this->requests->createPendingPhotoRequest( 9, 100, 'pub-bytes', self::NOW );
        $this->service->approve( $publishedId, 1, self::NOW );

        $unpublishedId = $this->requests->createPendingPhotoRequest( 10, 100, 'unpub-bytes', self::NOW );
        $this->media->failSetFeaturedImage = true;
        $this->service->approve( $unpublishedId, 1, self::NOW );
        $this->media->failSetFeaturedImage = false;

        $this->service->sweepBlobs();

        $this->assertNotNull( $this->requests->getBlobBinary( $pendingId ), 'pending must be kept' );
        $this->assertNotNull( $this->requests->getBlobBinary( $unpublishedId ), 'approved-but-unpublished must be kept' );
        $this->assertNull( $this->requests->getBlobBinary( $rejectedId ), 'rejected straggler must be purged' );
        $this->assertNull( $this->requests->getBlobBinary( $publishedId ), 'already published must already be purged (and stay purged)' );
    }
}
