<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Photo;

use EntreRedes\Credencial\Photo\Exception\EmptyBodyException;
use EntreRedes\Credencial\Photo\Exception\ImageTooLargeException;
use EntreRedes\Credencial\Photo\Exception\InvalidBase64Exception;
use EntreRedes\Credencial\Photo\UploadBodyReader;
use PHPUnit\Framework\TestCase;

/**
 * UploadBodyReader — design D7 (`{image_base64}` JSON body) + D9 (<= 3 MB
 * decoded; 400 `empty_body`, 400 `invalid_base64`, 413 `image_too_large`).
 */
class UploadBodyReaderTest extends TestCase {

    private UploadBodyReader $reader;

    protected function setUp(): void {
        $this->reader = new UploadBodyReader();
    }

    public function test_decodes_valid_base64_into_raw_bytes(): void {
        $decoded = $this->reader->read( base64_encode( 'hello-image-bytes' ) );

        $this->assertSame( 'hello-image-bytes', $decoded );
    }

    public function test_null_raises_empty_body(): void {
        $this->expectException( EmptyBodyException::class );
        $this->reader->read( null );
    }

    public function test_empty_string_raises_empty_body(): void {
        $this->expectException( EmptyBodyException::class );
        $this->reader->read( '' );
    }

    public function test_whitespace_only_raises_empty_body(): void {
        $this->expectException( EmptyBodyException::class );
        $this->reader->read( "   \n\t  " );
    }

    public function test_a_non_string_value_raises_empty_body(): void {
        $this->expectException( EmptyBodyException::class );
        $this->reader->read( 12345 );
    }

    public function test_malformed_base64_raises_invalid_base64(): void {
        $this->expectException( InvalidBase64Exception::class );
        // '@' and spaces are never valid strict-mode base64 characters.
        $this->reader->read( 'not@@valid base64!!' );
    }

    public function test_decoded_bytes_at_the_3mb_ceiling_are_accepted(): void {
        $bytes   = str_repeat( 'a', UploadBodyReader::MAX_DECODED_BYTES );
        $decoded = $this->reader->read( base64_encode( $bytes ) );

        $this->assertSame( UploadBodyReader::MAX_DECODED_BYTES, strlen( $decoded ) );
    }

    public function test_decoded_bytes_over_3mb_raise_image_too_large(): void {
        $bytes = str_repeat( 'a', UploadBodyReader::MAX_DECODED_BYTES + 1 );

        $this->expectException( ImageTooLargeException::class );
        $this->reader->read( base64_encode( $bytes ) );
    }
}
