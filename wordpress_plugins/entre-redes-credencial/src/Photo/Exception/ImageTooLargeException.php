<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/** Design D9: 413 `image_too_large` — decoded bytes exceed UploadBodyReader::MAX_DECODED_BYTES (3 MB). */
final class ImageTooLargeException extends \RuntimeException {
}
