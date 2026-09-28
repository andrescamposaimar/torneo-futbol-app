<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * The two facts this feature reads off a player's `sp_metrics` postmeta:
 * their puntaje (may be unresolvable — see Puntaje's own docblock and
 * JugadorMetricasReader's, "NEVER DEFAULTS TO 0") and whether they are a
 * "padre" — see JugadorMetricasReader::esPadreDesdeCaracter() for exactly how
 * that is decided from the free-text `caracter` field.
 *
 * A plain value object, not an array, so every reader of "is this player a
 * padre" goes through the SAME classification instead of each call site
 * re-deriving its own reading of `caracter`.
 */
final class JugadorMetricas {

    private ?Puntaje $puntaje;
    private bool $esPadre;

    private function __construct( ?Puntaje $puntaje, bool $esPadre ) {
        $this->puntaje = $puntaje;
        $this->esPadre = $esPadre;
    }

    public static function desde( ?Puntaje $puntaje, bool $esPadre ): self {
        return new self( $puntaje, $esPadre );
    }

    /**
     * No `sp_metrics` row at all, or one that decoded to nothing usable —
     * never a padre, never a resolvable puntaje. Distinct from "the row
     * exists but omits `caracter`", which is ALSO `esPadre() === false` (see
     * JugadorMetricasReader), so this factory is just a readable shorthand
     * for that same outcome, not a third state.
     */
    public static function vacio(): self {
        return new self( null, false );
    }

    public function puntaje(): ?Puntaje {
        return $this->puntaje;
    }

    public function esPadre(): bool {
        return $this->esPadre;
    }
}
