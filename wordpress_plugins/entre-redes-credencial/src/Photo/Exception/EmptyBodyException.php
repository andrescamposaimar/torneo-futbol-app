<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/** Design D9: 400 `empty_body` — no `image_base64` was sent, or it was blank. */
final class EmptyBodyException extends \RuntimeException {
}
