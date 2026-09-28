<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * The token is well-formed and its signature verifies, but its `nbf` claim
 * is in the future.
 */
class TokenNotYetValidException extends TokenVerificationException {
}
