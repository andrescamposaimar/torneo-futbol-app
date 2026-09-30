<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\MediaWriteException;

/**
 * Real MediaWriter — talks to WordPress media/post functions directly, no
 * container, mirroring every other "one real implementation behind an
 * interface" class in this plugin (Photo\GdPhotoReencoder,
 * Player\EntreRedesApiTeamResolver).
 *
 * `_credencial_request_id` (meta, design D11 step (b)) tags an unlinked
 * attachment with the approval request that created it — the ONLY way
 * Approval\ApprovalReviewService finds it again across retries.
 */
final class WpMediaWriter implements MediaWriter {

    private const META_REQUEST_ID = '_credencial_request_id';

    public function findAttachmentsTaggedWithRequest( int $requestId ): array {
        $ids = get_posts( [
            'post_type'      => 'attachment',
            'meta_key'       => self::META_REQUEST_ID,
            'meta_value'     => $requestId,
            'post_status'    => 'inherit',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ] );

        return array_map( 'intval', $ids );
    }

    public function createAttachment( string $binary, int $requestId ): int {
        try {
            $extension = $this->guessExtension( $binary );
            $filename  = bin2hex( random_bytes( 16 ) ) . '.' . $extension; // 128 bits, design D11 step (b).
        } catch ( \Exception $e ) {
            throw new MediaWriteException( 'Could not generate a random filename: ' . $e->getMessage() );
        }

        $uploaded = wp_upload_bits( $filename, null, $binary );

        if ( ! empty( $uploaded['error'] ) ) {
            throw new MediaWriteException( 'wp_upload_bits failed: ' . (string) $uploaded['error'] );
        }

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => (string) ( $uploaded['type'] ?? 'image/jpeg' ),
                'post_status'    => 'inherit',
                'post_title'     => $filename,
            ],
            $uploaded['file']
        );

        if ( is_wp_error( $attachmentId ) ) {
            // Design D11 step (b): "A non-fatal insert failure deletes the
            // file just written."
            $this->deleteUploadedFile( (string) $uploaded['file'] );

            throw new MediaWriteException(
                'wp_insert_attachment failed: ' . $attachmentId->get_error_message()
            );
        }

        update_post_meta( $attachmentId, self::META_REQUEST_ID, $requestId );

        return $attachmentId;
    }

    public function deleteAttachment( int $attachmentId ): void {
        wp_delete_attachment( $attachmentId, true );
    }

    public function attachmentExists( int $attachmentId ): bool {
        return null !== get_post( $attachmentId );
    }

    public function ensureMetadataGenerated( int $attachmentId ): void {
        if ( false !== wp_get_attachment_metadata( $attachmentId ) ) {
            return;
        }

        // wp_generate_attachment_metadata() lives in wp-admin/includes/image.php,
        // which is NOT part of the standard bootstrap this admin page runs
        // under (unlike wp_upload_bits()/wp_insert_attachment(), which live in
        // wp-includes/ and are always available) — same class of guard already
        // used for WP_List_Table (Admin\PendingPhotosPage) and dbDelta()
        // (Migrations\InitialSchema).
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $file     = get_attached_file( $attachmentId );
        $metadata = wp_generate_attachment_metadata( $attachmentId, false === $file ? '' : $file );
        wp_update_attachment_metadata( $attachmentId, $metadata );
    }

    public function getFeaturedImageId( int $playerId ): ?int {
        $id = get_post_thumbnail_id( $playerId );

        return false === $id || 0 === $id ? null : (int) $id;
    }

    public function setFeaturedImage( int $playerId, int $attachmentId ): void {
        set_post_thumbnail( $playerId, $attachmentId );
    }

    private function deleteUploadedFile( string $file ): void {
        if ( '' === $file ) {
            return;
        }

        wp_delete_file( $file );
    }

    /** finfo over the raw bytes — the same check PhotoValidator already trusts (design D9). */
    private function guessExtension( string $binary ): string {
        $finfo = new \finfo( FILEINFO_MIME_TYPE );
        $mime  = $finfo->buffer( $binary );

        return 'image/png' === $mime ? 'png' : 'jpg';
    }
}
