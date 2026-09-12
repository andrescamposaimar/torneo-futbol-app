<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Linking;

/**
 * The four link-state values (REC-5, LINK-4..6). Stored as VARCHAR, not an
 * ENUM (design §2) — validity is enforced here, in PHP, where it is
 * testable.
 */
final class LinkState {

    public const AUTO          = 'auto';
    public const AMBIGUO       = 'ambiguo';
    public const SIN_CANDIDATO = 'sin_candidato';
    public const MANUAL        = 'manual';

    private function __construct() {
        // Not instantiable — a namespace for constants only.
    }
}
