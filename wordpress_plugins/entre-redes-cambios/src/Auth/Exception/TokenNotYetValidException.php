<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token is well-formed and its signature verifies, but its `nbf` claim
 * is in the future — firebase/php-jwt's BeforeValidException. Distinct from
 * TokenMalformedException: the envelope is NOT broken here — this is a
 * validly signed token that simply is not valid YET, not a token that could
 * never be validated at all.
 */
class TokenNotYetValidException extends TokenVerificationException {
}
