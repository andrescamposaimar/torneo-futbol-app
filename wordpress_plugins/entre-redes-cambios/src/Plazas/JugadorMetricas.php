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
    private ?string $caracter;

    private function __construct( ?Puntaje $puntaje, bool $esPadre, ?string $caracter ) {
        $this->puntaje  = $puntaje;
        $this->esPadre  = $esPadre;
        $this->caracter = $caracter;
    }

    /**
     * @param string|null $caracter The raw ACF `caracter` value (e.g. `Padre
     *        Alumno`), for display only. Independent of $esPadre — callers
     *        that only decide padre/non-padre keep passing two arguments.
     */
    public static function desde( ?Puntaje $puntaje, bool $esPadre, ?string $caracter = null ): self {
        return new self( $puntaje, $esPadre, self::normalizarCaracter( $caracter ) );
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
        return new self( null, false, null );
    }

    public function puntaje(): ?Puntaje {
        return $this->puntaje;
    }

    public function esPadre(): bool {
        return $this->esPadre;
    }

    /**
     * The player's carácter exactly as the ACF field holds it (`Padre
     * Alumno`, `Padre Ex-Alumno`, `Invitado`, `Personal Colegio`, …), trimmed
     * and otherwise untouched — for DISPLAY to the committee, which decides on
     * the real category, not on the padre/non-padre binary `esPadre()` reduces
     * it to. Null when the field is absent or blank: never a guessed value.
     *
     * Nothing may derive a rule from this string — rules read `esPadre()`,
     * which has its own, deliberately conservative, classification.
     */
    public function caracter(): ?string {
        return $this->caracter;
    }

    private static function normalizarCaracter( ?string $caracter ): ?string {
        if ( null === $caracter ) {
            return null;
        }

        $caracter = trim( $caracter );

        return '' === $caracter ? null : $caracter;
    }
}
