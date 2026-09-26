<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token's signature does not verify against the injected public key —
 * either it was signed with a different key entirely, or it was tampered
 * with after signing.
 */
class TokenSignatureInvalidException extends TokenVerificationException {
}
