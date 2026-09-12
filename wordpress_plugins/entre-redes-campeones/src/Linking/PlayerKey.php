<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The single key NameParser::keyFor() derives for a name — never a
 * generated variant, never a set (ADR-C1).
 */
final class PlayerKey {

    public function __construct(
        public readonly string $surname,
        public readonly string $initial
    ) {
    }
}
