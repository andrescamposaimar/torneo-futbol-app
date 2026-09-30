<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\ReencodeFailedException;

/**
 * Real PhotoReencoder — design D9: "exif rotate, fit 1080, JPEG q85". Runs
 * AFTER PhotoValidator's header-only check and MemoryGuard's pre-decode
 * estimate, so a failure here means the body past a valid-looking header was
 * actually corrupt (GD's own full decode is stricter than a header read).
 *
 * No imagedestroy() anywhere in this class: GD images have been plain,
 * refcounted/GC'd `GdImage` objects since PHP 8.0 — imagedestroy() is a
 * documented no-op since then, and PHP 8.5 emits a deprecation notice for
 * calling it at all.
 *
 * Rotation directions in correctOrientation() were verified empirically
 * against this codebase's own PHP/GD build (`imagerotate()`'s angle sign is
 * NOT self-evident from its own docs alone) — see
 * tests/Photo/GdPhotoReencoderTest.php's own docblock for the exact
 * pixel-position proof this mapping is built from.
 */
final class GdPhotoReencoder implements PhotoReencoder {

    /** Design D9: "fit 1080". */
    private const FIT_MAX_DIMENSION = 1080;

    /** Design D9: "JPEG q85". */
    private const JPEG_QUALITY = 85;

    public function reencode( string $decodedBytes ): string {
        $image = @imagecreatefromstring( $decodedBytes );

        if ( false === $image ) {
            throw new ReencodeFailedException();
        }

        $orientation = self::readExifOrientation( $decodedBytes );
        $image       = self::correctOrientation( $image, $orientation );
        $image       = self::fit( $image, self::FIT_MAX_DIMENSION );

        ob_start();
        $ok    = imagejpeg( $image, null, self::JPEG_QUALITY );
        $bytes = ob_get_clean();

        if ( false === $ok || false === $bytes || '' === $bytes ) {
            throw new ReencodeFailedException();
        }

        return $bytes;
    }

    /**
     * Reads the EXIF `Orientation` tag directly from the decoded bytes, or 1
     * ("normal", no rotation needed) when there is none — a PNG, a JPEG with
     * no EXIF block at all, or a host without ext-exif.
     */
    private static function readExifOrientation( string $bytes ): int {
        if ( ! function_exists( 'exif_read_data' ) ) {
            return 1;
        }

        $stream = fopen( 'php://temp', 'r+' );
        fwrite( $stream, $bytes );
        rewind( $stream );
        $exif = @exif_read_data( $stream, null, false, false );
        fclose( $stream );

        if ( false === $exif || ! isset( $exif['Orientation'] ) ) {
            return 1;
        }

        return (int) $exif['Orientation'];
    }

    /**
     * `imagerotate()`'s angle is ANTICLOCKWISE for a positive value
     * (documented, but easy to get backwards) — empirically confirmed
     * against this build: `imagerotate($im, -90, 0)` moves a top-left pixel
     * to top-right (a visual 90 CW rotation); `imagerotate($im, 90, 0)`
     * moves it to middle-left (a visual 90 CCW rotation).
     *
     * Only the three ROTATE orientations (3, 6, 8) are handled — the four
     * MIRROR orientations (2, 4, 5, 7) are a legacy EXIF corner case real
     * cameras/phones essentially never produce, and design D9 only ever
     * says "exif rotate". An orientation this method does not recognize
     * (including the mirror ones) is treated as "no rotation needed",
     * which is a strictly better outcome than guessing wrong.
     */
    private static function correctOrientation( \GdImage $image, int $orientation ): \GdImage {
        return match ( $orientation ) {
            3       => imagerotate( $image, 180, 0 ),
            6       => imagerotate( $image, -90, 0 ),
            8       => imagerotate( $image, 90, 0 ),
            default => $image,
        };
    }

    /** Downscales so the longest side is at most $maxDimension; never upscales. */
    private static function fit( \GdImage $image, int $maxDimension ): \GdImage {
        $width  = imagesx( $image );
        $height = imagesy( $image );
        $longestSide = max( $width, $height );

        if ( $longestSide <= $maxDimension ) {
            return $image;
        }

        $scale     = $maxDimension / $longestSide;
        $newWidth  = max( 1, (int) round( $width * $scale ) );
        $newHeight = max( 1, (int) round( $height * $scale ) );

        $resized = imagecreatetruecolor( $newWidth, $newHeight );
        imagecopyresampled( $resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height );

        return $resized;
    }
}
