<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * Base class for every rejection CapitanAuthorizer::authorize() can throw.
 *
 * The message is intentionally the SAME generic text on every concrete
 * subclass (see each one's constructor) — an invalid token, a revoked
 * session, and "not captain of this team" must be indistinguishable to
 * whatever surfaces this externally (e.g. an HTTP 403 body), so that
 * response can never be used to enumerate which of the three conditions
 * failed. The EXCEPTION TYPE still distinguishes the reason, precisely so
 * the server side CAN log it — catch a concrete subclass, not this message,
 * when the distinction matters.
 */
abstract class AuthorizationDeniedException extends \RuntimeException {

    private const GENERIC_MESSAGE = 'Not authorized to act as captain for this team and season.';

    public function __construct( ?\Throwable $previous = null ) {
        parent::__construct( self::GENERIC_MESSAGE, 0, $previous );
    }
}
