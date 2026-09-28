<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * The two facts this feature needs about a player, read from TWO independent
 * postmeta sources — see JugadorMetricasReader's class docblock for exactly
 * where each one lives:
 *   - their puntaje, from the `sp_metrics` postmeta blob (may be
 *     unresolvable — see Puntaje's own docblock and JugadorMetricasReader's,
 *     "NEVER DEFAULTS TO 0");
 *   - whether they are a "padre", from the dedicated ACF `caracter` field —
 *     see JugadorMetricasReader::esPadreDesdeCaracter() for exactly how that
 *     is decided from its value.
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
     * No `sp_metrics` row and no ACF `caracter` row at all, or either one
     * that decoded/read to nothing usable — never a padre, never a
     * resolvable puntaje. Distinct from "the `caracter` row exists but is
     * blank", which is ALSO `esPadre() === false` (see
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
