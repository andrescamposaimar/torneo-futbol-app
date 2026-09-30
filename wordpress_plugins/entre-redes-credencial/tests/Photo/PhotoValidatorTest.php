<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Photo;

use EntreRedes\Credencial\Photo\Exception\ImageDimensionsException;
use EntreRedes\Credencial\Photo\Exception\InvalidImageException;
use EntreRedes\Credencial\Photo\PhotoValidator;
use PHPUnit\Framework\TestCase;

/**
 * PhotoValidator — design D9: `finfo` + `getimagesizefromstring()` BEFORE
 * decode (400 `invalid_image`), then dimension checks (422
 * `image_dimensions`: min 300x300, max side 6000, max 12 MP).
 */
class PhotoValidatorTest extends TestCase {

    private PhotoValidator $validator;

    protected function setUp(): void {
        if ( ! extension_loaded( 'gd' ) ) {
            $this->markTestSkipped( 'ext-gd is required to generate JPEG/PNG fixtures.' );
        }

        $this->validator = new PhotoValidator();
    }

    /**
     * No imagedestroy() here on purpose: GD images have been plain,
     * refcounted/GC'd `GdImage` objects since PHP 8.0 — imagedestroy() is a
     * documented no-op since then and PHP 8.5 emits a deprecation notice for
     * calling it at all.
     */
    private function jpeg( int $width, int $height ): string {
        $image = imagecreatetruecolor( $width, $height );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 120, 120, 120 ) );
        ob_start();
        imagejpeg( $image, null, 90 );

        return (string) ob_get_clean();
    }

    private function png( int $width, int $height ): string {
        $image = imagecreatetruecolor( $width, $height );
        imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 50, 50 ) );
        ob_start();
        imagepng( $image );

        return (string) ob_get_clean();
    }

    public function test_accepts_a_valid_jpeg_within_bounds_and_reports_its_dimensions(): void {
        $info = $this->validator->validate( $this->jpeg( 400, 300 ) );

        $this->assertSame( 400, $info['width'] );
        $this->assertSame( 300, $info['height'] );
        $this->assertSame( 'image/jpeg', $info['mime'] );
    }

    public function test_accepts_a_valid_png_within_bounds(): void {
        $info = $this->validator->validate( $this->png( 320, 320 ) );

        $this->assertSame( 'image/png', $info['mime'] );
    }

    public function test_rejects_bytes_that_are_not_an_image_at_all(): void {
        $this->expectException( InvalidImageException::class );
        $this->validator->validate( 'this is definitely not an image' );
    }

    public function test_rejects_an_empty_string(): void {
        $this->expectException( InvalidImageException::class );
        $this->validator->validate( '' );
    }

    public function test_rejects_a_gif_even_though_getimagesizefromstring_can_read_it(): void {
        // GD always produces GIF/JPEG/PNG here; a 1x1 GIF is a compact way to
        // exercise "a real, readable image header that is still not an
        // allowed mime" without needing a JPEG/PNG-only fixture generator.
        $gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

        $this->expectException( InvalidImageException::class );
        $this->validator->validate( $gif );
    }

    public function test_rejects_an_image_narrower_than_the_300px_minimum(): void {
        $this->expectException( ImageDimensionsException::class );
        $this->validator->validate( $this->jpeg( 299, 400 ) );
    }

    public function test_rejects_an_image_shorter_than_the_300px_minimum(): void {
        $this->expectException( ImageDimensionsException::class );
        $this->validator->validate( $this->jpeg( 400, 299 ) );
    }

    public function test_accepts_an_image_exactly_at_the_300px_minimum(): void {
        $info = $this->validator->validate( $this->jpeg( 300, 300 ) );
        $this->assertSame( 300, $info['width'] );
    }

    public function test_rejects_an_image_whose_side_exceeds_6000px(): void {
        $this->expectException( ImageDimensionsException::class );
        $this->validator->validate( $this->jpeg( 6001, 400 ) );
    }

    public function test_rejects_an_image_over_12_megapixels_even_with_sides_under_6000(): void {
        // 3500 x 3500 = 12.25 MP > 12 MP ceiling, both sides comfortably under 6000.
        $this->expectException( ImageDimensionsException::class );
        $this->validator->validate( $this->jpeg( 3500, 3500 ) );
    }
}
