<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token is not a well-formed, decodable JWS at all — wrong number of
 * segments, invalid base64/JSON, or an unsupported algorithm. Distinct from
 * TokenSignatureInvalidException: this is "the envelope itself is broken",
 * not "a valid envelope with the wrong signature". Also distinct from
 * TokenNotYetValidException: a future `nbf` is a well-formed, correctly
 * signed token — it is simply not valid yet, which is not the same failure
 * as an envelope that could never be validated.
 */
class TokenMalformedException extends TokenVerificationException {
}
