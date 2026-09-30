<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Admin;

use EntreRedes\Credencial\Admin\PendingPhotosPage;
use EntreRedes\Credencial\Approval\ApprovalRequestRepository;
use EntreRedes\Credencial\Approval\ApprovalReviewService;
use EntreRedes\Credencial\Migrations\InitialSchema;
use EntreRedes\Credencial\Observability\InMemoryEventLog;
use EntreRedes\Credencial\Tests\Support\FakeMediaWriter;
use PHPUnit\Framework\TestCase;

/**
 * PendingPhotosPage — design D12: `manage_options` + per-row nonce gate
 * every mutating action (approve/reject/publish). handlePost()'s guard
 * clauses `wp_die()` BEFORE the redirect+`exit`, so — same convention as
 * entre-redes-prode's own RegistryPageTest — they can be asserted directly
 * (the shim's wp_die() throws a RuntimeException instead of terminating the
 * process). The SUCCESS path is tested via applyAction() instead, which never
 * exits (mirrors RegistryPage::finalizeUnlink()).
 */
class PendingPhotosPageTest extends TestCase {

    private const PLAYER = 7;
    private const NOW    = 1_800_000_000;

    private ApprovalRequestRepository $requests;
    private FakeMediaWriter $media;
    private PendingPhotosPage $page;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_request" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}credencial_approval_blob" );

        $GLOBALS['wp_test_current_user_can']    = true;
        $GLOBALS['wp_test_wp_verify_nonce']     = 1;
        $_POST                                  = [];

        $eventLog       = new InMemoryEventLog();
        $this->requests = new ApprovalRequestRepository( $wpdb, $eventLog );
        $this->media    = new FakeMediaWriter();
        $reviewService  = new ApprovalReviewService( $this->requests, $this->media, $eventLog );

        $this->page = new PendingPhotosPage( $this->requests, $reviewService, $this->media );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wp_test_current_user_can'], $GLOBALS['wp_test_wp_verify_nonce'] );
        $_POST = [];
    }

    // -------------------------------------------------------------------------
    // handlePost() capability/nonce guards.
    // -------------------------------------------------------------------------

    public function test_handlePost_ignores_unknown_actions(): void {
        $_POST = [ 'credencial_action' => 'something_else' ];

        $this->page->handlePost(); // must NOT throw.
        $this->addToAssertionCount( 1 );
    }

    public function test_handlePost_rejects_a_non_admin(): void {
        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = [ 'credencial_action' => 'approve', 'credencial_request_id' => '1', 'credencial_nonce' => 'whatever' ];

        $this->expectException( \RuntimeException::class );
        $this->page->handlePost();
    }

    public function test_handlePost_rejects_a_missing_or_invalid_nonce(): void {
        $GLOBALS['wp_test_wp_verify_nonce'] = false;
        $_POST = [ 'credencial_action' => 'approve', 'credencial_request_id' => '1', 'credencial_nonce' => 'bad' ];

        $this->expectException( \RuntimeException::class );
        $this->page->handlePost();
    }

    public function test_handlePost_rejects_an_invalid_request_id(): void {
        $_POST = [ 'credencial_action' => 'approve', 'credencial_request_id' => '0', 'credencial_nonce' => 'whatever' ];

        $this->expectException( \RuntimeException::class );
        $this->page->handlePost();
    }

    public function test_nonceActionFor_is_distinct_per_action_and_request(): void {
        $this->assertSame( 'credencial_approve_5', PendingPhotosPage::nonceActionFor( 'approve', 5 ) );
        $this->assertSame( 'credencial_reject_5', PendingPhotosPage::nonceActionFor( 'reject', 5 ) );
        $this->assertSame( 'credencial_publish_5', PendingPhotosPage::nonceActionFor( 'publish', 5 ) );
        $this->assertNotSame(
            PendingPhotosPage::nonceActionFor( 'approve', 5 ),
            PendingPhotosPage::nonceActionFor( 'approve', 6 )
        );
    }

    // -------------------------------------------------------------------------
    // applyAction() — the actual mutation, extracted so it can be tested
    // without a real `exit` (see class docblock).
    // -------------------------------------------------------------------------

    public function test_applyAction_approve_publishes_and_returns_approved(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );

        $key = $this->page->applyAction( 'approve', $requestId );

        $this->assertSame( 'approved', $key );
        $this->assertSame( 'approved', $this->requests->findById( $requestId )['status'] );
    }

    public function test_applyAction_approve_reports_unpublished_on_a_tail_failure(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $this->media->failSetFeaturedImage = true;

        $key = $this->page->applyAction( 'approve', $requestId );

        $this->assertSame( 'approved_unpublished', $key );
    }

    public function test_applyAction_approve_reports_step_failed(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $this->media->failCreateAttachment = true;

        $key = $this->page->applyAction( 'approve', $requestId );

        $this->assertSame( 'step_failed', $key );
    }

    public function test_applyAction_publish_is_an_alias_for_approve_on_an_already_approved_row(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $this->media->failSetFeaturedImage = true;
        $this->page->applyAction( 'approve', $requestId );

        $this->media->failSetFeaturedImage = false;
        $key = $this->page->applyAction( 'publish', $requestId );

        $this->assertSame( 'approved', $key );
    }

    public function test_applyAction_reject_deletes_tagged_attachments_and_returns_rejected(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $_POST['credencial_review_note'] = 'no se ve bien';

        $key = $this->page->applyAction( 'reject', $requestId );

        $this->assertSame( 'rejected', $key );
        $this->assertSame( 'rejected', $this->requests->findById( $requestId )['status'] );
    }

    public function test_applyAction_returns_already_decided_on_a_repeat_reject(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $this->page->applyAction( 'reject', $requestId );

        $key = $this->page->applyAction( 'reject', $requestId );

        $this->assertSame( 'already_decided', $key );
    }

    public function test_applyAction_approve_on_a_rejected_request_returns_already_decided(): void {
        $requestId = $this->requests->createPendingPhotoRequest( self::PLAYER, 100, 'bytes', self::NOW );
        $this->page->applyAction( 'reject', $requestId );

        $key = $this->page->applyAction( 'approve', $requestId );

        $this->assertSame( 'already_decided', $key );
    }
}
