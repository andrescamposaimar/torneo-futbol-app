<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * The JWT itself did not verify — bad signature, expired, wrong `typ`, or
 * malformed. See the wrapped TokenVerificationException subclass (via
 * getPrevious()) for which one, server-side only.
 */
class InvalidTokenException extends AuthorizationDeniedException {
}
