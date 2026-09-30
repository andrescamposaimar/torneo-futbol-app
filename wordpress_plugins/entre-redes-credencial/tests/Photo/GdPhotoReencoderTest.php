<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Photo;

use EntreRedes\Credencial\Photo\Exception\ReencodeFailedException;
use EntreRedes\Credencial\Photo\GdPhotoReencoder;
use PHPUnit\Framework\TestCase;

/**
 * GdPhotoReencoder — design D9: "exif rotate, fit 1080, JPEG q85". No
 * imagedestroy() anywhere in this file or in GdPhotoReencoder itself: GD
 * images have been plain refcounted/GC'd `GdImage` objects since PHP 8.0,
 * and PHP 8.5 emits a deprecation notice for calling imagedestroy() at all.
 *
 * Orientation directions (correctOrientation()) were verified empirically
 * against this codebase's own PHP/GD build before writing these
 * assertions. The orientation fixtures use a 50x50 RED block in the
 * top-left corner of a 200x300 black canvas — NOT a single marker pixel:
 * `reencode()` runs the bytes through JPEG compression TWICE (the fixture's
 * own encode, then reencode()'s own re-encode), and a single pixel that
 * survives one JPEG pass cleanly gets visibly smeared by the second one on
 * an image this small — verified empirically before choosing a block large
 * enough to survive both passes with a plain "is this comfortably red"
 * check, sampled well inside the block, away from any compression-blurred
 * edge.
 */
class GdPhotoReencoderTest extends TestCase {

    private GdPhotoReencoder $reencoder;

    protected function setUp(): void {
        if ( ! extension_loaded( 'gd' ) ) {
            $this->markTestSkipped( 'ext-gd is required.' );
        }

        $this->reencoder = new GdPhotoReencoder();
    }

    /** A 200x300 black canvas with a 50x50 RED block in the top-left corner. */
    private function blockJpeg( int $width, int $height ): string {
        $image = imagecreatetruecolor( $width, $height );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 0, 0, 0 ) );
        imagefilledrectangle( $image, 0, 0, 49, 49, imagecolorallocate( $image, 255, 0, 0 ) );
        ob_start();
        imagejpeg( $image, null, 95 );

        return (string) ob_get_clean();
    }

    private function isRedAt( string $jpegBytes, int $x, int $y ): bool {
        $image = imagecreatefromstring( $jpegBytes );
        $c     = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );

        return $c['red'] > 200 && $c['green'] < 50 && $c['blue'] < 50;
    }

    private function dimensionsOf( string $jpegBytes ): array {
        $image = imagecreatefromstring( $jpegBytes );

        return [ imagesx( $image ), imagesy( $image ) ];
    }

    /**
     * Splices a hand-built EXIF APP1 segment (TIFF header + one IFD0 entry:
     * tag 0x0112 Orientation, type SHORT, value $orientation) right after
     * the JPEG's own SOI marker — the standard place a real camera/phone
     * puts it, and the only thing PhotoReencoder reads from EXIF.
     */
    private function withExifOrientation( string $jpegBytes, int $orientation ): string {
        $tiff = "\x49\x49\x2A\x00\x08\x00\x00\x00" // "II*\0" + offset 8 to IFD0 (little-endian)
            . "\x01\x00"                             // IFD0: 1 entry
            . "\x12\x01\x03\x00\x01\x00\x00\x00"      // tag 0x0112, type SHORT, count 1
            . pack( 'v', $orientation ) . "\x00\x00"  // value (2 bytes) + 2 bytes padding
            . "\x00\x00\x00\x00";                     // next IFD offset = 0

        $app1 = "\xFF\xE1" . pack( 'n', 2 + 6 + strlen( $tiff ) ) . "Exif\x00\x00" . $tiff;

        // Drop the original 2-byte SOI, prepend a fresh SOI + our APP1.
        return "\xFF\xD8" . $app1 . substr( $jpegBytes, 2 );
    }

    public function test_output_is_a_valid_jpeg_regardless_of_input_format(): void {
        $image = imagecreatetruecolor( 400, 400 );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 10, 20, 30 ) );
        ob_start();
        imagepng( $image );
        $pngBytes = (string) ob_get_clean();

        $reencoded = $this->reencoder->reencode( $pngBytes );

        $info = getimagesizefromstring( $reencoded );
        $this->assertSame( 'image/jpeg', $info['mime'] );
    }

    public function test_an_image_smaller_than_1080_is_not_upscaled(): void {
        $jpeg      = $this->blockJpeg( 400, 300 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        $this->assertSame( [ 400, 300 ], $this->dimensionsOf( $reencoded ) );
    }

    public function test_an_image_larger_than_1080_is_fit_to_1080_on_its_longest_side(): void {
        $jpeg      = $this->blockJpeg( 2160, 1080 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        $this->assertSame( [ 1080, 540 ], $this->dimensionsOf( $reencoded ) );
    }

    public function test_bytes_that_fail_full_decode_throw_reencode_failed(): void {
        $this->expectException( ReencodeFailedException::class );
        // Not an image at all — imagecreatefromstring() itself fails.
        $this->reencoder->reencode( 'not an image, not even close' );
    }

    public function test_orientation_1_leaves_the_image_unrotated(): void {
        $jpeg      = $this->withExifOrientation( $this->blockJpeg( 200, 300 ), 1 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        $this->assertSame( [ 200, 300 ], $this->dimensionsOf( $reencoded ) );
        $this->assertTrue( $this->isRedAt( $reencoded, 25, 25 ) );
    }

    public function test_orientation_3_rotates_180_degrees(): void {
        $jpeg      = $this->withExifOrientation( $this->blockJpeg( 200, 300 ), 3 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        // 180 degrees: dimensions unchanged, the block moves to the opposite corner.
        $this->assertSame( [ 200, 300 ], $this->dimensionsOf( $reencoded ) );
        $this->assertTrue( $this->isRedAt( $reencoded, 175, 275 ) );
        $this->assertFalse( $this->isRedAt( $reencoded, 25, 25 ) );
    }

    public function test_orientation_6_rotates_90_degrees_clockwise(): void {
        $jpeg      = $this->withExifOrientation( $this->blockJpeg( 200, 300 ), 6 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        // Empirically verified: a 90 CW rotation of a 200x300 image swaps the
        // dimensions to 300x200 and moves a top-left block to the top-right.
        $this->assertSame( [ 300, 200 ], $this->dimensionsOf( $reencoded ) );
        $this->assertTrue( $this->isRedAt( $reencoded, 275, 25 ) );
    }

    public function test_orientation_8_rotates_90_degrees_counterclockwise(): void {
        $jpeg      = $this->withExifOrientation( $this->blockJpeg( 200, 300 ), 8 );
        $reencoded = $this->reencoder->reencode( $jpeg );

        // Empirically verified: a 90 CCW rotation of a 200x300 image swaps the
        // dimensions to 300x200 and moves a top-left block to the bottom-left.
        $this->assertSame( [ 300, 200 ], $this->dimensionsOf( $reencoded ) );
        $this->assertTrue( $this->isRedAt( $reencoded, 25, 175 ) );
    }

    public function test_an_unknown_orientation_value_is_treated_as_no_rotation(): void {
        $jpeg      = $this->withExifOrientation( $this->blockJpeg( 200, 300 ), 2 ); // "flip", not handled
        $reencoded = $this->reencoder->reencode( $jpeg );

        $this->assertSame( [ 200, 300 ], $this->dimensionsOf( $reencoded ) );
        $this->assertTrue( $this->isRedAt( $reencoded, 25, 25 ) );
    }
}
