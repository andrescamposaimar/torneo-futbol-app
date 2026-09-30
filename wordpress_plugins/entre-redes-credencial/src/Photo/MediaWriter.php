<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\MediaWriteException;

/**
 * Every WordPress media/post operation design D11's approve/reject pipeline
 * needs, behind one seam — so Approval\ApprovalReviewService can be tested
 * against a scriptable fake (tests/Support/FakeMediaWriter.php) that fails at
 * any single step, exactly the way tests/Support/FaultInjectingWpdb.php lets
 * repository tests fail any single DB call. WpMediaWriter is the real
 * implementation, exercised on its own against the shim's WordPress media
 * functions (tests/Photo/WpMediaWriterTest.php) — same split as
 * Photo\PhotoReencoder / Photo\GdPhotoReencoder in slice 2a.
 *
 * "Attachment" below always means a WordPress attachment post id.
 */
interface MediaWriter {

    /**
     * Attachment ids currently tagged with meta `_credencial_request_id =
     * $requestId` (design D11 step (b)/(c)). Order is NOT guaranteed — the
     * caller (ApprovalReviewService) sorts and keeps the lowest id (step (c):
     * "keep the LOWEST id ... The lowest id is never deleted by any run, so
     * concurrent runs converge").
     *
     * @return int[]
     */
    public function findAttachmentsTaggedWithRequest( int $requestId ): array;

    /**
     * Design D11 step (b): uploads $binary as a NEW, unlinked attachment with
     * a random 128-bit hex filename (never player-derived), tagged with meta
     * `_credencial_request_id = $requestId`. Also records the sha256 of
     * $binary as attachment meta `_credencial_sha256` (this plugin's own
     * source of truth for "what does the live thumbnail's sha compare
     * against", read back via getAttachmentSha256() — never re-derived from
     * the approval blob, which may already be purged by the time the publish
     * tail retries).
     *
     * @throws MediaWriteException On any failure. If the attachment insert
     *         fails after the file was already written, the file is deleted
     *         before throwing (design: "A non-fatal insert failure deletes
     *         the file just written").
     */
    public function createAttachment( string $binary, int $requestId ): int;

    /** `wp_delete_attachment($attachmentId, true)` — force, skip trash. */
    public function deleteAttachment( int $attachmentId ): void;

    /** True when $attachmentId still resolves to a real attachment post. */
    public function attachmentExists( int $attachmentId ): bool;

    /**
     * Design D11 step (d): generates and saves attachment metadata for
     * $attachmentId ONLY if it is currently missing. A no-op when metadata
     * already exists — every retry of the same attachment must stay cheap.
     */
    public function ensureMetadataGenerated( int $attachmentId ): void;

    /** The player's LIVE featured image attachment id, or null. */
    public function getFeaturedImageId( int $playerId ): ?int;

    /** `set_post_thumbnail($playerId, $attachmentId)` (design D11 step (f).3). */
    public function setFeaturedImage( int $playerId, int $attachmentId ): void;

    /** The sha256 recorded on $attachmentId at createAttachment() time, or null. */
    public function getAttachmentSha256( int $attachmentId ): ?string;

    /** The player's own `_credencial_sha256` meta (design D11 step (f).4), or null. */
    public function getPlayerSha256Meta( int $playerId ): ?string;

    public function setPlayerSha256Meta( int $playerId, string $sha256 ): void;
}
