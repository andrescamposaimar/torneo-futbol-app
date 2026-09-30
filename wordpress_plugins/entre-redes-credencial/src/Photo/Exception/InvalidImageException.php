<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/**
 * Design D9: 400 `invalid_image` — the decoded bytes are not a JPEG/PNG per
 * `finfo` + `getimagesizefromstring()` (PhotoValidator), or GD could not
 * actually decode them despite passing that header check
 * (Photo\GdPhotoReencoder).
 */
final class InvalidImageException extends \RuntimeException {
}
