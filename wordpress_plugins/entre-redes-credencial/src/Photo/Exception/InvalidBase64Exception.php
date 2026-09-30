<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/** Design D9: 400 `invalid_base64` — the value sent could not be base64-decoded. */
final class InvalidBase64Exception extends \RuntimeException {
}
