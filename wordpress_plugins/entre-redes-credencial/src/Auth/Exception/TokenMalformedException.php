<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * The token is not a well-formed, decodable JWS at all — wrong number of
 * segments, invalid base64/JSON, or an unsupported algorithm.
 */
class TokenMalformedException extends TokenVerificationException {
}
