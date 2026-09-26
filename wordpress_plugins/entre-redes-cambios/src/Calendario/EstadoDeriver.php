<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Pure derivation of a fecha's DEFAULT state from its partidos and the clock.
 *
 * Zero globals, zero DB access, zero calls to time() / current_time(). Both
 * "now" and the partidos are injected — mirrors PlazosCalculator's discipline.
 *
 * This class returns ONLY 'jugada' or 'programada' — never 'dirimida' or
 * 'suspendida'. That is not a gap waiting to be filled; each of those two has
 * its own reason, and both are worth knowing before anyone "fixes" this.
 *
 * 'dirimida' (a committee ruling, typically 3-0 for a no-show): the ruled
 * result IS loaded into SportsPress like any other result — it has to be, or
 * the standings would be wrong, since SportsPress computes the table from the
 * events. So a ruled fecha arrives here with every partido carrying a result
 * and derives to 'jugada'. And because the business rule counts RESOLVED
 * fechas — countFechasResueltasDesdeFecha() matches estado IN ('jugada',
 * 'dirimida') — a ruled fecha counts correctly without anybody marking
 * anything. 'dirimida' is an informational label, not an input the counter
 * depends on. Do NOT build a manual-entry flow for it believing the count is
 * broken without one.
 *
 * 'suspendida' (postponed): this one IS observable, just not from here. When a
 * jornada is postponed, someone on the comision edits the date of all of its
 * partidos in WordPress, so the fecha's partidos simply move to a new day.
 * FechaRepository::upsertFecha() detects that (same match_ids, later
 * play_date) and bumps veces_postergada. This class only sees one snapshot of
 * the partidos, never the history, so it cannot and should not try. A
 * postponed fecha derives to 'programada', which is the correct answer for the
 * counter: it must not count until it is actually resolved.
 *
 * setEstadoManual() remains available for a human to override either value,
 * and estado_origen makes that override survive every reseed.
 */
final class EstadoDeriver {

    /**
     * Derive the default state for a fecha.
     *
     * Three cases in the spec collapse into two return values on purpose:
     *   1. Every partido has a result             -> 'jugada'.
     *   2. Not all played yet, play_date not past  -> 'programada' (still to come).
     *   3. Not all played yet, play_date is past   -> 'programada' (NOT
     *      'suspendida' — a missing result after kickoff is not proof of a
     *      suspension; it might just be pending data entry, a rain delay
     *      being played out in stoppage time, etc. Inventing 'suspendida' here
     *      would put a human judgment call under the derivation pipeline's
     *      control, which is exactly what this class must never do).
     *
     * Cases 2 and 3 are kept as separate branches below (rather than merged
     * into a single early return) so the "past due but still not suspended"
     * case is visibly a deliberate decision at the call site, not an
     * accidental fallthrough.
     *
     * @param array<int, array{tiene_resultado: bool|int}> $partidos The
     *        fecha's partidos, each carrying at least `tiene_resultado`. An
     *        empty list is treated as "not all played" (case 2/3), never as
     *        vacuously 'jugada'.
     * @param string $playDate The fecha's play_date, 'Y-m-d'.
     * @param string $now      Current datetime, 'Y-m-d H:i:s' — injected.
     * @return string 'jugada' | 'programada' — NEVER 'dirimida' or 'suspendida'.
     */
    public static function derive( array $partidos, string $playDate, string $now ): string {
        if ( self::allPlayed( $partidos ) ) {
            return 'jugada';
        }

        $playDateHasPassed = $now >= ( $playDate . ' 00:00:00' );

        if ( ! $playDateHasPassed ) {
            // Case 2: still upcoming.
            return 'programada';
        }

        // Case 3: kickoff has passed but results are incomplete. This is
        // deliberately NOT 'suspendida' — see class docblock.
        return 'programada';
    }

    /**
     * @param array<int, array{tiene_resultado: bool|int}> $partidos
     */
    private static function allPlayed( array $partidos ): bool {
        if ( empty( $partidos ) ) {
            return false;
        }

        foreach ( $partidos as $partido ) {
            if ( empty( $partido['tiene_resultado'] ) ) {
                return false;
            }
        }

        return true;
    }
}
