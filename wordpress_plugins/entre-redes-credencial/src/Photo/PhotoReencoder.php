<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\ReencodeFailedException;

/**
 * Last stage of the upload pipeline (design D9): "Then exif rotate, fit
 * 1080, JPEG q85" — the ONLY place the pending photo's bytes are actually
 * decoded into a full image and re-encoded before being stored (design D8).
 *
 * Deliberately an interface, injected into Rest\PhotoUploadController like
 * every other collaborator in this codebase — so a decode/re-encode failure
 * can be exercised at the controller level with a fake implementation,
 * without needing to craft a real corrupt-image fixture for every test that
 * only cares about the controller's own error mapping. GdPhotoReencoder is
 * the real implementation.
 */
interface PhotoReencoder {

    /**
     * @param string $decodedBytes Bytes that already passed
     *        PhotoValidator::validate() (a valid JPEG/PNG header) — this
     *        method may still fail if the body past that header is corrupt.
     *
     * @return string Re-encoded JPEG bytes: EXIF-corrected orientation,
     *         fit to a max side of 1080px, quality 85, metadata stripped
     *         (GD's own re-encode never carries EXIF/ICC forward).
     *
     * @throws ReencodeFailedException When the bytes cannot actually be
     *         decoded or re-encoded.
     */
    public function reencode( string $decodedBytes ): string;
}
