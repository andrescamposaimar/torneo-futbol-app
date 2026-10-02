<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Support;

use EntreRedes\Credencial\Photo\Exception\MediaWriteException;
use EntreRedes\Credencial\Photo\MediaWriter;

/**
 * In-memory, scriptable MediaWriter — lets
 * Approval\ApprovalReviewServiceTest fail any single media step ((b) create,
 * (d) metadata, (f).3 set featured image) without touching real WordPress
 * media functions, exactly the role tests/Support/FaultInjectingWpdb.php
 * plays for DB-level failures ((e), the claim). Both fakes are combined in
 * the same test to reproduce design D11's per-step failure matrix.
 *
 * seedAttachment() exists ONLY for tests that model a concurrent run's
 * already-existing attachment (e.g. a claim race where the persisted
 * attachment_id differs from this run's local survivor) — it deliberately
 * does NOT tag the seeded attachment with the request id being tested, so
 * findAttachmentsTaggedWithRequest() does not pick it up as if this run had
 * created it itself.
 */
final class FakeMediaWriter implements MediaWriter {

    /** @var array<int, array{requestId: int, metadataGenerated: bool}> */
    private array $attachments = [];

    private int $nextId = 1;

    /** @var array<int, int> playerId => attachmentId */
    private array $featured = [];

    /** @var array<int, int> requestId => times createAttachment() was called for it */
    private array $createCalls = [];

    public bool $failCreateAttachment  = false;
    public bool $failEnsureMetadata    = false;
    public bool $failSetFeaturedImage  = false;

    public function findAttachmentsTaggedWithRequest( int $requestId ): array {
        $ids = [];
        foreach ( $this->attachments as $id => $attachment ) {
            if ( $attachment['requestId'] === $requestId ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function createAttachment( string $binary, int $requestId ): int {
        if ( $this->failCreateAttachment ) {
            throw new MediaWriteException( 'fake: createAttachment failed' );
        }

        $this->createCalls[ $requestId ] = ( $this->createCalls[ $requestId ] ?? 0 ) + 1;

        $id                       = $this->nextId++;
        $this->attachments[ $id ] = [ 'requestId' => $requestId, 'metadataGenerated' => false ];

        return $id;
    }

    public function deleteAttachment( int $attachmentId ): void {
        unset( $this->attachments[ $attachmentId ] );
    }

    public function attachmentExists( int $attachmentId ): bool {
        return isset( $this->attachments[ $attachmentId ] );
    }

    public function ensureMetadataGenerated( int $attachmentId ): void {
        if ( $this->failEnsureMetadata ) {
            throw new MediaWriteException( 'fake: ensureMetadataGenerated failed' );
        }

        if ( isset( $this->attachments[ $attachmentId ] ) ) {
            $this->attachments[ $attachmentId ]['metadataGenerated'] = true;
        }
    }

    public function getFeaturedImageId( int $playerId ): ?int {
        return $this->featured[ $playerId ] ?? null;
    }

    public function setFeaturedImage( int $playerId, int $attachmentId ): void {
        if ( $this->failSetFeaturedImage ) {
            throw new MediaWriteException( 'fake: setFeaturedImage failed' );
        }

        $this->featured[ $playerId ] = $attachmentId;
    }

    // -------------------------------------------------------------------------
    // Test-only helpers (not part of the MediaWriter contract).
    // -------------------------------------------------------------------------

    /** Registers an attachment as already existing, WITHOUT tagging it to any request — see class docblock. */
    public function seedAttachment( int $attachmentId ): void {
        $this->attachments[ $attachmentId ] = [ 'requestId' => -1, 'metadataGenerated' => true ];
        if ( $attachmentId >= $this->nextId ) {
            $this->nextId = $attachmentId + 1;
        }
    }

    public function createAttachmentCallCount( int $requestId ): int {
        return $this->createCalls[ $requestId ] ?? 0;
    }

    public function metadataWasGenerated( int $attachmentId ): bool {
        return $this->attachments[ $attachmentId ]['metadataGenerated'] ?? false;
    }
}
