<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/**
 * The bytes passed PhotoValidator's header-only check
 * (`getimagesizefromstring()`) but GD could not actually decode them into an
 * image (corrupt body past a valid header), or could not re-encode the
 * result. Rest\PhotoUploadController maps this to the same 400
 * `invalid_image` a header-check failure gets — from the caller's point of
 * view both mean "this was not a usable image".
 */
final class ReencodeFailedException extends \RuntimeException {
}
