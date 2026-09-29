<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Credencial\Exception;

/**
 * Thrown by IssuanceRepository::resolve() when the `credencial_issuance` row
 * for a player cannot be produced — design D4: "No row = 500 +
 * issuance.resolve_failed". CredencialController catches this and returns a
 * 500; the event is already recorded by IssuanceRepository before it throws.
 */
final class IssuanceResolutionFailedException extends \RuntimeException {
}
