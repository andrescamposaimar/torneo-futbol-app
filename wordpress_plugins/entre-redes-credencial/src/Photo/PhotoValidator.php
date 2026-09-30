<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\ImageDimensionsException;
use EntreRedes\Credencial\Photo\Exception\InvalidImageException;

/**
 * Second stage of the upload pipeline (design D9): reads the image's HEADER
 * only — `finfo` (byte-sniffed mime) + `getimagesizefromstring()` (mime +
 * dimensions) — BEFORE any full GD decode is attempted. This is the cheap
 * check that rejects "not an image at all" and "wrong dimensions" without
 * ever paying for a full decode of a file that will just be rejected anyway;
 * MemoryGuard and PhotoReencoder only ever see bytes that already passed
 * this gate.
 */
final class PhotoValidator {

    /** @var string[] */
    private const ALLOWED_MIMES = [ 'image/jpeg', 'image/png' ];

    private const MIN_WIDTH        = 300;
    private const MIN_HEIGHT       = 300;
    private const MAX_SIDE         = 6000;
    private const MAX_MEGAPIXELS   = 12_000_000;

    /**
     * @return array{width:int, height:int, mime:string}
     *
     * @throws InvalidImageException When the bytes are not a JPEG/PNG per
     *         either check.
     * @throws ImageDimensionsException When width/height fall outside
     *         [300x300, max side 6000, max 12 MP].
     */
    public function validate( string $decodedBytes ): array {
        $info = @getimagesizefromstring( $decodedBytes );

        if ( false === $info ) {
            throw new InvalidImageException();
        }

        $mime = (string) ( $info['mime'] ?? '' );

        if ( ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
            throw new InvalidImageException();
        }

        // Cross-check against the bytes themselves, not just the header
        // getimagesizefromstring() already parsed — design D9: "finfo +
        // getimagesizefromstring". A mismatch (e.g. a renamed/crafted file
        // whose declared header disagrees with its actual content) is
        // treated the same as any other invalid image.
        $finfo        = new \finfo( FILEINFO_MIME_TYPE );
        $detectedMime = $finfo->buffer( $decodedBytes );

        if ( ! in_array( $detectedMime, self::ALLOWED_MIMES, true ) ) {
            throw new InvalidImageException();
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if ( $width < self::MIN_WIDTH || $height < self::MIN_HEIGHT ) {
            throw new ImageDimensionsException();
        }

        if ( $width > self::MAX_SIDE || $height > self::MAX_SIDE ) {
            throw new ImageDimensionsException();
        }

        if ( $width * $height > self::MAX_MEGAPIXELS ) {
            throw new ImageDimensionsException();
        }

        return [
            'width'  => $width,
            'height' => $height,
            'mime'   => $mime,
        ];
    }
}
