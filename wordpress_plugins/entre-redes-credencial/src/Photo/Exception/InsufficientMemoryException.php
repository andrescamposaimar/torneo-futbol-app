<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Photo\Exception;

/**
 * Design D9: 500 `insufficient_memory` — the estimated memory a full GD
 * decode of these dimensions would need does not fit under `memory_limit`,
 * checked BEFORE attempting the decode (MemoryGuard).
 */
final class InsufficientMemoryException extends \RuntimeException {
}
