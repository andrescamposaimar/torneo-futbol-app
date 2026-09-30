<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * Base class for every rejection CapitanAuthorizer::authorize() can throw.
 *
 * The message is intentionally the SAME generic text on every concrete
 * subclass (see each one's constructor) — this exception's own ->getMessage()
 * never surfaces to a caller (see Rest\HandlesCapitanAuthorization, which
 * builds its OWN caller-facing `message` and never reads this one), so
 * there is nothing to distinguish here. The HTTP layer built on top of these
 * exceptions is a DIFFERENT decision — see
 * Rest\HandlesCapitanAuthorization::respuestaNoAutorizada()'s own docblock
 * for why its status code and machine-readable `code` DO now vary by
 * exception type, while its human-readable `message` stays the one generic
 * text this class's docblock describes. The EXCEPTION TYPE still
 * distinguishes the reason, precisely so the server side CAN log it and the
 * HTTP layer CAN answer precisely — catch a concrete subclass, not this
 * message, when the distinction matters.
 */
abstract class AuthorizationDeniedException extends \RuntimeException {

    private const GENERIC_MESSAGE = 'Not authorized to act as captain for this team and season.';

    public function __construct( ?\Throwable $previous = null ) {
        parent::__construct( self::GENERIC_MESSAGE, 0, $previous );
    }
}
