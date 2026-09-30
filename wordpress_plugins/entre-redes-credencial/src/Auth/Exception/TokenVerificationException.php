<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Auth\Exception;

/**
 * Base class for every rejection TokenVerifier::verify() can throw. Copied
 * from entre-redes-cambios/src/Auth/Exception/TokenVerificationException.php.
 */
abstract class TokenVerificationException extends \RuntimeException {
}
