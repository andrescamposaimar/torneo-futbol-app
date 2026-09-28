<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * The token's signature does not verify against the injected public key.
 */
class TokenSignatureInvalidException extends TokenVerificationException {
}
