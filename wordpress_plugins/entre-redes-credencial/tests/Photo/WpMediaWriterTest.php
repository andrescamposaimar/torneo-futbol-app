<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Photo;

use EntreRedes\Credencial\Photo\Exception\MediaWriteException;
use EntreRedes\Credencial\Photo\WpMediaWriter;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * WpMediaWriter — the real MediaWriter, exercised against the shim's
 * WordPress media functions (see tests/wp-shim.php's "credencial-plugin
 * addition, slice 2b" media block). Approval\ApprovalReviewServiceTest uses a
 * scriptable Tests\Support\FakeMediaWriter instead — this file only proves
 * THIS class talks to WordPress correctly, same split as GdPhotoReencoderTest
 * vs PhotoUploadControllerTest in slice 2a.
 */
class WpMediaWriterTest extends TestCase {

    private WpMediaWriter $writer;

    protected function setUp(): void {
        $GLOBALS['_prode_test_attachments']      = [];
        $GLOBALS['wp_test_postmeta']             = [];
        $GLOBALS['wp_test_attachment_metadata']  = [];
        $GLOBALS['wp_test_post_thumbnail_ids']   = [];
        $GLOBALS['wp_test_post_thumbnail_urls']  = [];
        $GLOBALS['wp_test_upload_bits_fails']    = false;
        $GLOBALS['wp_test_insert_attachment_fails'] = false;
        $GLOBALS['_prode_test_uploaded_files']   = [];
        $GLOBALS['_prode_test_next_attachment_id'] = 0;
        $GLOBALS['wp_test_posts']                = [];

        $this->writer = new WpMediaWriter();
    }

    public function test_createAttachment_returns_a_new_id_tagged_with_the_request_and_its_sha256(): void {
        $id = $this->writer->createAttachment( 'jpeg-bytes', 42 );

        $this->assertGreaterThan( 0, $id );
        $this->assertSame( [ $id ], $this->writer->findAttachmentsTaggedWithRequest( 42 ) );
        $this->assertSame( hash( 'sha256', 'jpeg-bytes' ), $this->writer->getAttachmentSha256( $id ) );
    }

    public function test_createAttachment_uses_a_random_128bit_hex_filename_not_derived_from_the_request(): void {
        $id   = $this->writer->createAttachment( 'jpeg-bytes', 42 );
        $file = $GLOBALS['_prode_test_attachments'][ $id ]['file'];

        // 128 bits == 32 hex chars, plus an extension.
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}\.jpe?g$/', basename( $file ) );
    }

    public function test_two_uploads_for_the_same_request_get_different_filenames(): void {
        $idA = $this->writer->createAttachment( 'jpeg-bytes', 42 );
        $idB = $this->writer->createAttachment( 'jpeg-bytes', 42 );

        $this->assertNotSame(
            $GLOBALS['_prode_test_attachments'][ $idA ]['file'],
            $GLOBALS['_prode_test_attachments'][ $idB ]['file']
        );
    }

    public function test_createAttachment_deletes_the_file_when_the_insert_fails(): void {
        $GLOBALS['wp_test_insert_attachment_fails'] = true;

        try {
            $this->writer->createAttachment( 'jpeg-bytes', 42 );
            $this->fail( 'expected MediaWriteException' );
        } catch ( MediaWriteException $e ) {
            // expected
        }

        $this->assertSame( [], $GLOBALS['_prode_test_uploaded_files'] ?? [], 'the file written by wp_upload_bits must be deleted on a failed insert' );
    }

    public function test_createAttachment_throws_when_the_upload_itself_fails(): void {
        $GLOBALS['wp_test_upload_bits_fails'] = true;

        $this->expectException( MediaWriteException::class );
        $this->writer->createAttachment( 'jpeg-bytes', 42 );
    }

    public function test_findAttachmentsTaggedWithRequest_is_empty_for_an_unknown_request(): void {
        $this->assertSame( [], $this->writer->findAttachmentsTaggedWithRequest( 999 ) );
    }

    public function test_deleteAttachment_removes_it_and_attachmentExists_becomes_false(): void {
        $id = $this->writer->createAttachment( 'jpeg-bytes', 42 );
        $this->assertTrue( $this->writer->attachmentExists( $id ) );

        $this->writer->deleteAttachment( $id );

        $this->assertFalse( $this->writer->attachmentExists( $id ) );
        $this->assertSame( [], $this->writer->findAttachmentsTaggedWithRequest( 42 ) );
    }

    public function test_attachmentExists_is_false_for_an_id_that_was_never_created(): void {
        $this->assertFalse( $this->writer->attachmentExists( 12345 ) );
    }

    public function test_ensureMetadataGenerated_generates_once_and_is_a_noop_after(): void {
        $id = $this->writer->createAttachment( 'jpeg-bytes', 42 );
        $this->assertArrayNotHasKey( $id, $GLOBALS['wp_test_attachment_metadata'] );

        $this->writer->ensureMetadataGenerated( $id );
        $this->assertArrayHasKey( $id, $GLOBALS['wp_test_attachment_metadata'] );

        $GLOBALS['wp_test_attachment_metadata'][ $id ] = [ 'marker' => 'kept' ];
        $this->writer->ensureMetadataGenerated( $id );

        $this->assertSame( [ 'marker' => 'kept' ], $GLOBALS['wp_test_attachment_metadata'][ $id ], 'must not regenerate metadata that already exists' );
    }

    public function test_getFeaturedImageId_is_null_when_the_player_has_none(): void {
        $this->assertNull( $this->writer->getFeaturedImageId( 7 ) );
    }

    public function test_setFeaturedImage_makes_getFeaturedImageId_and_has_post_thumbnail_agree(): void {
        $id = $this->writer->createAttachment( 'jpeg-bytes', 42 );

        $this->writer->setFeaturedImage( 7, $id );

        $this->assertSame( $id, $this->writer->getFeaturedImageId( 7 ) );
        $this->assertTrue( has_post_thumbnail( 7 ) );
    }

    /**
     * CRITICAL regression (verify-report 2b/2c gate): wp_generate_attachment_metadata()
     * lives in wp-admin/includes/image.php, which is NOT autoloaded by the
     * standard bootstrap — tests/wp-shim.php deliberately does NOT pre-define
     * this function, so this test only passes because
     * WpMediaWriter::ensureMetadataGenerated() actually requires that file
     * itself. Runs in its own process so the function table starts empty
     * regardless of what earlier tests in this suite already triggered.
     */
    #[RunInSeparateProcess]
    public function test_ensureMetadataGenerated_only_works_because_it_loads_wp_admin_includes_image_php(): void {
        $this->assertFalse(
            function_exists( 'wp_generate_attachment_metadata' ),
            'precondition: the shim must not pre-define this function — only requiring wp-admin/includes/image.php may'
        );

        $writer = new WpMediaWriter();
        $id     = $writer->createAttachment( 'jpeg-bytes', 42 );
        $writer->ensureMetadataGenerated( $id );

        $this->assertTrue(
            function_exists( 'wp_generate_attachment_metadata' ),
            'ensureMetadataGenerated() must require wp-admin/includes/image.php to make the function available'
        );
        $this->assertArrayHasKey(
            $id,
            $GLOBALS['wp_test_attachment_metadata'],
            'metadata must actually be generated and stored, not silently skipped'
        );
    }

    public function test_player_sha256_meta_roundtrips(): void {
        $this->assertNull( $this->writer->getPlayerSha256Meta( 7 ) );

        $this->writer->setPlayerSha256Meta( 7, 'abc123' );

        $this->assertSame( 'abc123', $this->writer->getPlayerSha256Meta( 7 ) );
    }
}
