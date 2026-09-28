<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token's `exp` claim is at or before the injected $now — evaluated
 * against the instant TokenVerifier::verify() was called WITH, never the
 * system clock. See that method's docblock for the timezone gotcha this
 * comparison depends on getting right.
 */
class TokenExpiredException extends TokenVerificationException {
}
