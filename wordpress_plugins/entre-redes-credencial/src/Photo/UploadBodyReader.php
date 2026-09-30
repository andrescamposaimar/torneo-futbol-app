<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo;

use EntreRedes\Credencial\Photo\Exception\EmptyBodyException;
use EntreRedes\Credencial\Photo\Exception\ImageTooLargeException;
use EntreRedes\Credencial\Photo\Exception\InvalidBase64Exception;

/**
 * First stage of the upload pipeline (design D7/D9): turns the raw
 * `image_base64` request value into decoded bytes, or throws the exact
 * client-visible error design D9 names — nothing here inspects the bytes as
 * an image yet (that is PhotoValidator's job).
 */
final class UploadBodyReader {

    /** Design D9: "<= 3 MB decoded". */
    public const MAX_DECODED_BYTES = 3 * 1024 * 1024;

    /**
     * @param mixed $rawImageBase64 Whatever `$request->get_param('image_base64')`
     *        returned — untyped on purpose, since a caller sending no field
     *        at all, `null`, or a non-string value must all be treated the
     *        same as "nothing was sent".
     *
     * @throws EmptyBodyException When nothing (or only whitespace) was sent.
     * @throws InvalidBase64Exception When the value is not valid base64.
     * @throws ImageTooLargeException When the decoded bytes exceed
     *         MAX_DECODED_BYTES.
     */
    public function read( mixed $rawImageBase64 ): string {
        if ( ! is_string( $rawImageBase64 ) || '' === trim( $rawImageBase64 ) ) {
            throw new EmptyBodyException();
        }

        // Strict mode: any character outside the base64 alphabet makes this
        // return false rather than silently skipping it — design D9 wants a
        // precise `invalid_base64`, not a garbled decode.
        $decoded = base64_decode( $rawImageBase64, true );

        if ( false === $decoded ) {
            throw new InvalidBase64Exception();
        }

        if ( strlen( $decoded ) > self::MAX_DECODED_BYTES ) {
            throw new ImageTooLargeException();
        }

        return $decoded;
    }
}
