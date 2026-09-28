<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Auth\Exception;

/**
 * Base class for every rejection TokenVerifier::verify() can throw. Catch
 * this type when the caller only needs "the token did not verify, for some
 * reason" (e.g. a generic HTTP 401); catch a concrete subclass when the
 * reason itself needs to be distinguished (e.g. for server-side logging —
 * see Capitania\CapitanAuthorizer, which relies on the exception TYPE, never
 * the message, to tell its three rejection reasons apart).
 */
abstract class TokenVerificationException extends \RuntimeException {
}
