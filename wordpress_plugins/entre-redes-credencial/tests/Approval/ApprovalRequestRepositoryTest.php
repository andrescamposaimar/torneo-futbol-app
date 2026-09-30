<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Approval;

use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Approval\Exception\AlreadyPendingException;
use EntreRedes\Credencial\Approval\Exception\ApprovalPersistenceException;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Tests\Support\FaultInjectingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * ApprovalRequestRepository — design D8/D10: insert pending + blob in one
 * transaction; `isUnpublished()` / newest-approved-photo query, scoped to
 * TYPE_PHOTO (design D11/D12 rev 8: "The type filter is required because the
 * generic table is shared with future stages").
 */
class ApprovalRequestRepositoryTest extends TestCase {

    private const NOW = 1_800_000_000;

    private ApprovalRequestRepository $repo;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        $this->eventLog = new InMemoryEventLog();
        $this->repo     = new ApprovalRequestRepository( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );
    }

    public function test_creates_a_pending_request_and_its_blob(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'binary-jpeg-bytes', self::NOW );

        $this->assertGreaterThan( 0, $requestId );

        global $wpdb;
        $row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}credencial_approval_request WHERE id = {$requestId}" );
        $this->assertSame( ApprovalRequestRepository::TYPE_PHOTO, $row['type'] );
        $this->assertSame( 7, (int) $row['target_player_id'] );
        $this->assertSame( 100, (int) $row['requested_by'] );
        $this->assertSame( 'pending', $row['status'] );

        $blob = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}credencial_approval_blob WHERE request_id = {$requestId}" );
        $this->assertSame( 'binary-jpeg-bytes', $blob['photo_binary'] );
    }

    public function test_type_photo_constant_is_photo(): void {
        $this->assertSame( 'photo', ApprovalRequestRepository::TYPE_PHOTO );
    }

    public function test_a_duplicate_key_failure_throws_already_pending(): void {
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
        $wpdb->failNextQueryMatching(
            '/INSERT INTO wp_credencial_approval_request/i',
            "Duplicate entry 'photo:7' for key 'uq_pending_key'"
        );

        $repo = new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() );

        $this->expectException( AlreadyPendingException::class );
        $repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
    }

    public function test_a_duplicate_key_failure_does_not_record_a_persistence_failure_event(): void {
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
        $wpdb->failNextQueryMatching(
            '/INSERT INTO wp_credencial_approval_request/i',
            "Duplicate entry 'photo:7' for key 'uq_pending_key'"
        );

        $eventLog = new InMemoryEventLog();
        $repo     = new ApprovalRequestRepository( $wpdb, $eventLog );

        try {
            $repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        } catch ( AlreadyPendingException $e ) {
            // expected
        }

        $this->assertFalse( $eventLog->has( 'approval.request_persistence_failed' ) );
    }

    public function test_a_non_duplicate_db_error_on_the_request_insert_throws_persistence_exception_and_logs(): void {
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
        $wpdb->failNextQueryMatching(
            '/INSERT INTO wp_credencial_approval_request/i',
            'Deadlock found; try restarting transaction'
        );

        $eventLog = new InMemoryEventLog();
        $repo     = new ApprovalRequestRepository( $wpdb, $eventLog );

        $this->expectException( ApprovalPersistenceException::class );

        try {
            $repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        } finally {
            $this->assertTrue( $eventLog->has( 'approval.request_persistence_failed' ) );
        }
    }

    public function test_a_blob_insert_failure_rolls_back_the_request_row_too(): void {
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
        $wpdb->failNextQueryMatching(
            '/INSERT INTO wp_credencial_approval_blob/i',
            'disk I/O error'
        );

        $repo = new ApprovalRequestRepository( $wpdb, new InMemoryEventLog() );

        try {
            $repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
            $this->fail( 'expected ApprovalPersistenceException' );
        } catch ( ApprovalPersistenceException $e ) {
            // expected
        }

        $count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM wp_credencial_approval_request' );
        $this->assertSame( 0, $count, 'the request row must not survive a rolled-back transaction' );
    }

    public function test_isUnpublished_is_false_when_no_request_was_ever_approved(): void {
        $this->assertFalse( $this->repo->isUnpublished( 7, null ) );
    }

    public function test_isUnpublished_is_true_when_the_newest_approved_request_differs_from_the_live_thumbnail(): void {
        global $wpdb;
        $requestId = $this->approvedRequest( 7, 55, self::NOW );

        $this->assertTrue( $this->repo->isUnpublished( 7, 999 ) );
        $this->assertFalse( $this->repo->isUnpublished( 7, 55 ) );
    }

    public function test_findNewestApprovedPhotoRequest_ignores_other_types(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
            'type'             => 'some_future_type',
            'target_player_id' => 7,
            'requested_by'     => 100,
            'payload'          => '{}',
            'status'           => 'approved',
            'attachment_id'    => 42,
            'reviewed_at'      => gmdate( 'Y-m-d H:i:s', self::NOW ),
            'created_at'       => gmdate( 'Y-m-d H:i:s', self::NOW ),
        ] );

        $this->assertNull( $this->repo->findNewestApprovedPhotoRequest( 7 ) );
    }

    public function test_findNewestApprovedPhotoRequest_picks_the_newest_by_reviewed_at(): void {
        $this->approvedRequest( 7, 10, self::NOW );
        $newest = $this->approvedRequest( 7, 20, self::NOW + 3600 );

        $found = $this->repo->findNewestApprovedPhotoRequest( 7 );
        $this->assertSame( $newest, $found['id'] );
        $this->assertSame( 20, $found['attachment_id'] );
    }

    public function test_countRequestsSince_counts_only_requests_within_the_window(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
            'type' => ApprovalRequestRepository::TYPE_PHOTO, 'target_player_id' => 7,
            'requested_by' => 100, 'payload' => '{}', 'status' => 'rejected',
            'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - 100 ),
        ] );
        $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
            'type' => ApprovalRequestRepository::TYPE_PHOTO, 'target_player_id' => 7,
            'requested_by' => 100, 'payload' => '{}', 'status' => 'rejected',
            'created_at' => gmdate( 'Y-m-d H:i:s', self::NOW - ( 25 * 3600 ) ), // outside the 24h window
        ] );

        $count = $this->repo->countRequestsSince( 7, ApprovalRequestRepository::TYPE_PHOTO, self::NOW - 86400 );
        $this->assertSame( 1, $count );
    }

    // -------------------------------------------------------------------------
    // Slice 2b additions: findById, claim/reject, blob access, admin listing.
    // -------------------------------------------------------------------------

    public function test_findById_returns_the_full_row(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );

        $row = $this->repo->findById( $requestId );

        $this->assertSame( $requestId, $row['id'] );
        $this->assertSame( 7, $row['target_player_id'] );
        $this->assertSame( 'pending', $row['status'] );
        $this->assertNull( $row['attachment_id'] );
    }

    public function test_findById_returns_null_for_an_unknown_id(): void {
        $this->assertNull( $this->repo->findById( 999999 ) );
    }

    public function test_claimApproval_succeeds_exactly_once_for_a_pending_request(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );

        $first = $this->repo->claimApproval( $requestId, 1, 55, self::NOW );
        $this->assertTrue( $first );

        $row = $this->repo->findById( $requestId );
        $this->assertSame( 'approved', $row['status'] );
        $this->assertSame( 55, $row['attachment_id'] );
        $this->assertSame( 1, $row['reviewed_by'] );

        // A second claim on the now-approved row must affect 0 rows.
        $second = $this->repo->claimApproval( $requestId, 1, 999, self::NOW );
        $this->assertFalse( $second );

        // The first claim's attachment_id must survive — the "0 rows" claim
        // above must NOT have silently overwritten it.
        $this->assertSame( 55, $this->repo->findById( $requestId )['attachment_id'] );
    }

    public function test_claimApproval_returns_false_for_an_already_rejected_request(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        $this->repo->rejectPending( $requestId, 1, null, self::NOW );

        $this->assertFalse( $this->repo->claimApproval( $requestId, 1, 55, self::NOW ) );
    }

    public function test_setAttachmentId_updates_an_already_approved_row(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        $this->repo->claimApproval( $requestId, 1, 55, self::NOW );

        $this->repo->setAttachmentId( $requestId, 77 );

        $this->assertSame( 77, $this->repo->findById( $requestId )['attachment_id'] );
    }

    public function test_rejectPending_succeeds_once_and_is_a_noop_on_retry(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );

        $first = $this->repo->rejectPending( $requestId, 1, 'no se ve la cara', self::NOW );
        $this->assertTrue( $first );

        $row = $this->repo->findById( $requestId );
        $this->assertSame( 'rejected', $row['status'] );
        $this->assertSame( 'no se ve la cara', $row['review_note'] );

        $second = $this->repo->rejectPending( $requestId, 1, 'otro motivo', self::NOW );
        $this->assertFalse( $second );
        $this->assertSame( 'no se ve la cara', $this->repo->findById( $requestId )['review_note'], 'a 0-row reject must not touch the already-decided row' );
    }

    public function test_rejectPending_returns_false_for_an_already_approved_request(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        $this->repo->claimApproval( $requestId, 1, 55, self::NOW );

        $this->assertFalse( $this->repo->rejectPending( $requestId, 1, null, self::NOW ) );
    }

    public function test_blob_binary_roundtrips_and_can_be_deleted(): void {
        $requestId = $this->repo->createPendingPhotoRequest( 7, 100, 'the-bytes', self::NOW );

        $this->assertSame( 'the-bytes', $this->repo->getBlobBinary( $requestId ) );

        $this->repo->deleteBlob( $requestId );

        $this->assertNull( $this->repo->getBlobBinary( $requestId ) );
    }

    public function test_getBlobBinary_is_null_for_an_unknown_request(): void {
        $this->assertNull( $this->repo->getBlobBinary( 999999 ) );
    }

    public function test_findPendingByType_lists_only_pending_rows_of_that_type(): void {
        $pendingId = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        $approvedId = $this->repo->createPendingPhotoRequest( 8, 100, 'bytes', self::NOW );
        $this->repo->claimApproval( $approvedId, 1, 55, self::NOW );

        $rows = $this->repo->findPendingByType( ApprovalRequestRepository::TYPE_PHOTO );

        $this->assertCount( 1, $rows );
        $this->assertSame( $pendingId, $rows[0]['id'] );
    }

    public function test_findRequestIdsWithBlob_only_returns_requests_that_still_have_a_blob_row(): void {
        $withBlob    = $this->repo->createPendingPhotoRequest( 7, 100, 'bytes', self::NOW );
        $purgedBlob  = $this->repo->createPendingPhotoRequest( 8, 100, 'bytes', self::NOW );
        $this->repo->deleteBlob( $purgedBlob );

        $rows = $this->repo->findRequestIdsWithBlob( ApprovalRequestRepository::TYPE_PHOTO );
        $ids  = array_column( $rows, 'id' );

        $this->assertContains( $withBlob, $ids );
        $this->assertNotContains( $purgedBlob, $ids );
    }

    public function test_findApprovedPlayerIds_lists_each_player_once(): void {
        $this->approvedRequest( 7, 55, self::NOW );
        $this->approvedRequest( 7, 56, self::NOW + 10 ); // same player, newer approval.
        $this->approvedRequest( 8, 60, self::NOW );

        $ids = $this->repo->findApprovedPlayerIds( ApprovalRequestRepository::TYPE_PHOTO );
        sort( $ids );

        $this->assertSame( [ 7, 8 ], $ids );
    }

    /** Inserts an already-approved photo request directly (bypassing the pending flow) and returns its id. */
    private function approvedRequest( int $playerId, int $attachmentId, int $reviewedAtEpoch ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'credencial_approval_request', [
            'type'             => ApprovalRequestRepository::TYPE_PHOTO,
            'target_player_id' => $playerId,
            'requested_by'     => 100,
            'payload'          => '{}',
            'status'           => 'approved',
            'attachment_id'    => $attachmentId,
            'reviewed_at'      => gmdate( 'Y-m-d H:i:s', $reviewedAtEpoch ),
            'created_at'       => gmdate( 'Y-m-d H:i:s', $reviewedAtEpoch ),
        ] );

        return (int) $wpdb->insert_id;
    }
}
