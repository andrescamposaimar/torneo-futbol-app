<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

/**
 * A puntaje (score) is one of exactly 9 discrete values: 1, 1.5, 2, 2.5, 3,
 * 3.5, 4, 4.5, 5 — never an arbitrary decimal. This class is the ONLY
 * representation the rest of the "cambios" feature should hold in memory or
 * persist: everywhere else, a puntaje is this value object, or the plain
 * integer `halfPoints()` produces, never a bare `float`.
 *
 * WHY ×2 AND NOT float: `cambios_plaza.puntaje_techo` stores the puntaje as
 * an integer 2..10 (the value doubled), not as a MySQL DECIMAL/FLOAT. Two
 * independent problems make float the wrong choice here:
 *
 *   1. Puntajes travel through this codebase as strings formatted for a
 *      Spanish-locale audience, where the decimal separator is a COMMA, not
 *      a period — "2,5" rather than "2.5". PHP's `(float)` cast is always
 *      locale-INDEPENDENT and always expects '.'; casting "2,5" directly
 *      stops parsing at the comma and silently yields `2.0`, not `2.5`. A
 *      puntaje of 2,5 misread as 2 would then wrongly pass a techo of 2 that
 *      it should have failed. `fromDecimal()` normalizes the separator
 *      before casting specifically to close this gap.
 *   2. Even once correctly parsed, comparing two floats for a boundary
 *      condition (`$entrante <= $techo`) is exactly the kind of comparison
 *      that must NEVER depend on incidental float representation: any value
 *      that reaches the comparison through a different code path — a JSON
 *      round-trip, a different arithmetic derivation, a different parse —
 *      is not guaranteed to be bit-identical to a literal `2.5` even when
 *      both conceptually mean the same puntaje. `2.5 <= 2.5` reads as an
 *      obvious truth, but it is only reliably true when both sides are the
 *      SAME representation; the moment one side takes a different route to
 *      "2.5", the comparison becomes a bet on float internals instead of a
 *      business rule. Representing the value as the integer `halfPoints()`
 *      (2..10) removes the bet entirely: integer equality and comparison are
 *      exact, always, regardless of how the value was produced.
 *
 * `techoEfectivo()` collapses the "regla del 2,5" into the SAME expression as
 * every other techo: `MAX(puntaje_techo, 5)` in half-points terms (5
 * half-points == 2.5 points). A techo below 2,5 is never enforced as a hard
 * ceiling below 2,5 — 2,5 is the floor for what any techo admits, snapshotted
 * ceiling or not.
 */
final class Puntaje {

    /**
     * The only 9 half-points values a Puntaje may ever hold: 2..10 inclusive,
     * with NO gaps — every integer in this closed range corresponds to
     * exactly one of the 9 discrete decimal values (1 through 5, in 0.5
     * steps). That is what makes the bounds check in fromHalfPoints() a
     * complete validation on its own: no allow-list of individual values is
     * needed, only the range.
     */
    private const MIN_HALF_POINTS = 2;
    private const MAX_HALF_POINTS = 10;

    /**
     * The floor every techo effectively admits, in half-points — 2.5 points,
     * i.e. the "regla del 2,5". See class docblock.
     */
    private const TECHO_MINIMO_HALF_POINTS = 5;

    private int $halfPoints;

    private function __construct( int $halfPoints ) {
        $this->halfPoints = $halfPoints;
    }

    /**
     * @throws \InvalidArgumentException When $x2 falls outside 2..10.
     */
    public static function fromHalfPoints( int $x2 ): self {
        if ( $x2 < self::MIN_HALF_POINTS || $x2 > self::MAX_HALF_POINTS ) {
            throw new \InvalidArgumentException(
                "Puntaje::fromHalfPoints(): {$x2} is out of range. "
                . 'Valid half-points values are ' . self::MIN_HALF_POINTS . '..' . self::MAX_HALF_POINTS
                . ' (i.e. puntajes 1..5 in steps of 0.5).'
            );
        }

        return new self( $x2 );
    }

    /**
     * Accepts either a native float/int or a string, and — critically — a
     * string using a COMMA as the decimal separator ("2,5"), the format this
     * value most often arrives in from a Spanish-locale source. See class
     * docblock for why a naive `(float)` cast on such a string is a silent
     * data-corruption bug, not just a cosmetic one.
     *
     * @throws \InvalidArgumentException When $decimal does not resolve to one
     *         of the 9 valid discrete values (e.g. 2.3).
     */
    public static function fromDecimal( float|string $decimal ): self {
        $normalized = is_string( $decimal ) ? str_replace( ',', '.', $decimal ) : $decimal;
        $value      = (float) $normalized;

        $halfPointsFloat = $value * 2;
        $halfPointsInt   = (int) round( $halfPointsFloat );

        // Guard against a value that is not actually one of the 9 discrete
        // puntajes (e.g. 2.3 -> 4.6 half-points, nowhere near an integer).
        if ( abs( $halfPointsFloat - $halfPointsInt ) > 1e-6 ) {
            throw new \InvalidArgumentException(
                "Puntaje::fromDecimal(): '{$decimal}' is not one of the 9 valid puntajes "
                . '(1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5).'
            );
        }

        return self::fromHalfPoints( $halfPointsInt );
    }

    public function halfPoints(): int {
        return $this->halfPoints;
    }

    public function toDecimal(): float {
        return $this->halfPoints / 2;
    }

    /**
     * The effective ceiling this puntaje enforces as a techo — never below
     * 2.5 points (see class docblock, "regla del 2,5").
     */
    public function techoEfectivo(): self {
        return self::fromHalfPoints( max( $this->halfPoints, self::TECHO_MINIMO_HALF_POINTS ) );
    }

    /**
     * Whether $entrante is admitted under this puntaje acting as a techo —
     * i.e. $entrante does not exceed this techo's effective ceiling. Compares
     * half-points (exact integers), never decimals — see class docblock.
     */
    public function allows( Puntaje $entrante ): bool {
        return $entrante->halfPoints() <= $this->techoEfectivo()->halfPoints();
    }

    public function __toString(): string {
        $formatted = sprintf( '%.1f', $this->toDecimal() );

        return rtrim( rtrim( $formatted, '0' ), '.' );
    }
}
