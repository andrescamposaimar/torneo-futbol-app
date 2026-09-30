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
