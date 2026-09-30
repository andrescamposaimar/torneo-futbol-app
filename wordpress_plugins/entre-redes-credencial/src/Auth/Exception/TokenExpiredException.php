<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * The token's `exp` claim is at or before the injected $now.
 */
class TokenExpiredException extends TokenVerificationException {
}
