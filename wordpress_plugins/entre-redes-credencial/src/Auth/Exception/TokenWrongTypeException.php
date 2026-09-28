<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * The token verified (signature + expiry are fine) but its `typ` claim is
 * not 'prode_access'.
 */
class TokenWrongTypeException extends TokenVerificationException {
}
