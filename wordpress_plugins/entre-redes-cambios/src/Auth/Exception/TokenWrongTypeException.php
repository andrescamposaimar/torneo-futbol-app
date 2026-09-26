<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token verified (signature + expiry are fine) but its `typ` claim is
 * not 'prode_access' — e.g. a 'prode_intent' token (see prode's JwtService)
 * presented where an access token was expected. Rejecting this by type,
 * not by re-checking the raw string, is what stops an intent token from
 * ever being reused as an access token.
 */
class TokenWrongTypeException extends TokenVerificationException {
}
