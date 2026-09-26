<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * The token is not a well-formed, decodable JWS at all — wrong number of
 * segments, invalid base64/JSON, an unsupported algorithm, or a `nbf` set in
 * the future (firebase/php-jwt's BeforeValidException). Distinct from
 * TokenSignatureInvalidException: this is "the envelope itself is broken",
 * not "a valid envelope with the wrong signature".
 */
class TokenMalformedException extends TokenVerificationException {
}
