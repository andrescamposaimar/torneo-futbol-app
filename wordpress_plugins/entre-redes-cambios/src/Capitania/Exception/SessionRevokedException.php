<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Capitania\Exception;

/**
 * The JWT itself verified fine, but ProdeSessionGateway::isSessionCurrent()
 * says the session behind it is no longer current — this is exactly the
 * revocation-bypass gap ProdeSessionGateway exists to close (see its
 * docblock): signature + expiry alone would have accepted this token.
 */
class SessionRevokedException extends AuthorizationDeniedException {
}
