<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;

/**
 * Pure reader of a plaza's CADENA DE OCUPACIONES — zero DB, zero clock, zero
 * globals. Everything it needs is passed in: the plaza row, its ocupaciones
 * (in `PlazaRepository::listOcupaciones()`'s shape), and a single injected
 * callable that answers "how many resolved fechas have passed since fecha
 * X" (in production, a closure over
 * `Calendario\FechaRepository::countResolvedFechasSince()` with `$seasonId`
 * already bound — this class never sees a season id). Same design discipline
 * as Calendario\PlazosCalculator: fully deterministic in tests, nothing
 * hidden behind a global.
 *
 * THE MODEL: a plaza is occupied by a succession of players over time — the
 * titular, then maybe a suplente, then maybe another suplente, and so on —
 * each one a ROW in `cambios_ocupacion`, ordered by `fecha_desde_id`. The
 * chain has exactly one VIGENT link at a time (`fecha_hasta_id IS NULL`).
 *
 * WHY "EL CAMBIO DE CAMBIO" IS NOT A SPECIAL CASE HERE: a naive model treats
 * "a suplente occupying a plaza gets replaced by ANOTHER suplente" as a
 * different code path from "the titular returns" or "a suplente takes over
 * from the titular" — three `if` branches for what is really the same
 * event. Under the chain model, every one of those is identical: close the
 * vigent link, open a new one. `PlazaRepository::succeedOcupacion()` is
 * that single operation; nothing here or there asks "is this the second
 * change in a row?" because the chain has no notion of "first" vs
 * "subsequent" changes — only links.
 *
 * THE MÍNIMO IS A PISO, NOT A VENCIMIENTO: once an occupant has stayed 3
 * resolved fechas, the occupation does not expire — it renews by silence,
 * indefinitely, until something explicitly closes it. This class therefore
 * has NO method that decides "this occupation should end now"; it only
 * answers whether ENOUGH time has passed that closing it would be valid.
 * Nothing in this class or in PlazaRepository ever closes an ocupación on
 * its own initiative.
 *
 * THE PLAZA'S LIBERATION MOMENT IS DERIVED, NEVER STORED: a plaza has ONE
 * liberation fecha, governing BOTH when the titular may return AND when
 * every truncated ex-occupant (closed 'trunca', i.e. left before meeting the
 * mínimo) is unblocked — all of them, together, the instant the plaza
 * liberates. Storing that fecha would require rewriting it on every new
 * link (a later suplente's minimum pushes it forward again), which is
 * exactly the kind of stale-cache bug a derived value avoids by
 * construction. `isPlazaLiberable()` recomputes it fresh from the VIGENT
 * link's `fecha_desde_id` every time it is called — see that method's
 * docblock for why "vigent link" is the only one that matters for this
 * computation, regardless of how many prior links are 'trunca'.
 *
 * THIS CLASS DECIDES WHETHER A PERSON MAY RETURN TO PLAY FOR THEIR TEAM — so
 * it FAILS CLOSED. Every call to the injected callable goes through the
 * private countResolvedFechasSince() helper, which rejects a negative count
 * (impossible — the counter itself would be broken) and wraps ANY exception
 * the callable throws into Exception\FechaCountUnavailableException. The
 * plaza-liberation decision methods — isPlazaLiberable(), canTitularReturn(),
 * listExOcupantesBloqueados() — catch that exception and answer
 * conservatively (not liberable, titular cannot return, every ex-occupant
 * stays blocked) rather than let an uncountable fecha silently read as "0
 * missing" and liberate a plaza no one actually verified. See
 * isPlazaLiberable()'s docblock for the exact contract, and
 * Exception\FechaCountUnavailableException's docblock for why an INFLATED
 * count (the opposite failure — the callable lying that MORE fechas passed
 * than really did) is explicitly NOT this class's problem to detect.
 */
final class CadenaResolver {

    /** @var callable(int): int */
    private $countResolvedFechasSinceFn;

    /**
     * @param callable(int): int $countResolvedFechasSinceFn Given a
     *        `fecha_id`, returns how many RESOLVED fechas (see
     *        Calendario\FechaRepository::countResolvedFechasSince()) have
     *        passed since it, inclusive. In production this wraps that exact
     *        method with `$seasonId` already bound via closure; in tests, a
     *        stub.
     */
    public function __construct( callable $countResolvedFechasSinceFn ) {
        $this->countResolvedFechasSinceFn = $countResolvedFechasSinceFn;
    }

    /**
     * Whether a SPECIFIC ocupación (any link of the chain, vigent or
     * closed) has accumulated at least $minimo resolved fechas since it
     * started — the piso an occupant must clear before leaving no longer
     * counts as 'trunca'.
     *
     * @param array<string, mixed> $ocupacion One row shaped like
     *        `PlazaRepository::listOcupaciones()`'s output.
     *
     * @throws FechaCountUnavailableException When the injected callable
     *         cannot produce a trustworthy count for this ocupación's
     *         `fecha_desde_id` (it threw, or returned a negative count) —
     *         propagated as-is, NOT swallowed into `false` here. This method
     *         answers a single link's own history, not "may this plaza
     *         liberate" (that fail-closed boundary is isPlazaLiberable() and
     *         its callers), so a broken counter must surface loudly to
     *         whoever calls this directly rather than be silently guessed.
     */
    public function meetsMinimo( array $ocupacion, int $minimo = 3 ): bool {
        $fechaDesdeId = (int) $ocupacion['fecha_desde_id'];

        return $this->countResolvedFechasSince( $fechaDesdeId ) >= $minimo;
    }

    /**
     * How many resolved fechas are still missing before the plaza becomes
     * liberable — 0 when it already is. Derived from the VIGENT link only;
     * see class docblock for why closed 'trunca' links are irrelevant to
     * this count (their own mínimo, met or not, was already decided at the
     * moment they closed).
     *
     * @param array<int, array<string, mixed>> $ocupaciones The full chain,
     *        as returned by `PlazaRepository::listOcupaciones()`. MUST NOT be
     *        empty — see the `\InvalidArgumentException` below.
     *
     * @throws \InvalidArgumentException When $ocupaciones is empty. An empty
     *         chain is not a state a real plaza can ever be in —
     *         `PlazaRepository::openPlaza()` always creates the genesis
     *         ocupación in the same transaction as the plaza itself — so an
     *         empty array here means the caller passed the wrong plaza (or
     *         one that was never persisted correctly), never a legitimately
     *         liberable one. Before this check existed,
     *         `isPlazaLiberable( [] )` returned `true` VACUOUSLY (0 fechas
     *         missing from a count of nothing), which is exactly the kind of
     *         silent "yes" this class's fail-closed contract exists to
     *         prevent — a caller bug should surface loudly, not be read as
     *         "go ahead, liberate". This is deliberately a HARD failure, not
     *         one isPlazaLiberable() swallows into `false`: it signals a
     *         programming error in the caller's data assembly, not an
     *         external counting failure (see FechaCountUnavailableException
     *         for that distinct, fail-closed-to-`false` case).
     */
    public function countFechasUntilLiberacion( array $ocupaciones, int $minimo = 3 ): int {
        if ( empty( $ocupaciones ) ) {
            throw new \InvalidArgumentException(
                'CadenaResolver::countFechasUntilLiberacion(): an empty ocupaciones chain is not a valid plaza '
                . 'state — every plaza is created with a genesis ocupación by PlazaRepository::openPlaza(). '
                . 'Treating an empty chain as vacuously liberable would silently hide that bug.'
            );
        }

        $vigente = $this->vigente( $ocupaciones );

        if ( null === $vigente ) {
            // No vigent link at all — nothing is occupying the plaza, so
            // there is nothing left to wait for.
            return 0;
        }

        $resueltas = $this->countResolvedFechasSince( (int) $vigente['fecha_desde_id'] );

        return max( 0, $minimo - $resueltas );
    }

    /**
     * The plaza's single derived liberation condition: true once the VIGENT
     * link has cleared $minimo resolved fechas. This is intentionally
     * recomputed from scratch every call — see class docblock's "DERIVED,
     * NEVER STORED" section — so a caller must never cache this result
     * across a new link being appended to the chain.
     *
     * FAIL-CLOSED: when the injected callable cannot produce a trustworthy
     * count (it threw, or returned a negative value — see
     * countResolvedFechasSince()), this method catches
     * FechaCountUnavailableException and returns `false` — NEVER liberate a
     * plaza because a count could not be verified. An empty $ocupaciones
     * chain is a DIFFERENT failure (a caller bug, not a counting problem —
     * see countFechasUntilLiberacion()'s docblock) and is deliberately NOT
     * caught here: it propagates as `\InvalidArgumentException` instead of
     * being swallowed into `false`.
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     *
     * @throws \InvalidArgumentException When $ocupaciones is empty — see
     *         countFechasUntilLiberacion().
     */
    public function isPlazaLiberable( array $ocupaciones, int $minimo = 3 ): bool {
        try {
            return 0 === $this->countFechasUntilLiberacion( $ocupaciones, $minimo );
        } catch ( FechaCountUnavailableException $e ) {
            return false;
        }
    }

    /**
     * Whether the plaza's PERMANENT titular (`cambios_plaza.titular_player_id`)
     * may return right now — false when the titular already occupies it
     * (nothing to return FROM), otherwise exactly `isPlazaLiberable()`.
     *
     * FAIL-CLOSED: inherited by delegation — `isPlazaLiberable()` already
     * catches an unavailable/invalid count and returns `false`, so this
     * method never needs its own catch to return the conservative answer.
     *
     * @param array<string, mixed>             $plaza       As returned by
     *        `PlazaRepository::findPlaza()`.
     * @param array<int, array<string, mixed>> $ocupaciones
     */
    public function canTitularReturn( array $plaza, array $ocupaciones, int $minimo = 3 ): bool {
        $vigente = $this->vigente( $ocupaciones );

        if ( null === $vigente ) {
            return false;
        }

        if ( (int) $vigente['player_id'] === (int) $plaza['titular_player_id'] ) {
            return false;
        }

        return $this->isPlazaLiberable( $ocupaciones, $minimo );
    }

    /**
     * The player_id's that closed 'trunca' (left before meeting the mínimo)
     * and remain blocked from re-entering this plaza because it has not
     * liberated yet. See class docblock: liberation unblocks every one of
     * them AT ONCE — there is no per-player liberation moment, only the
     * plaza's single one.
     *
     * FAIL-CLOSED: inherited by delegation — when `isPlazaLiberable()`
     * cannot verify the count, it returns `false`, which routes this method
     * into the branch that keeps EVERY 'trunca' ex-occupant blocked. "We
     * could not verify the liberation count" therefore never unblocks
     * anyone; it behaves exactly like "the plaza is not liberable yet".
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<int, int>
     */
    public function listExOcupantesBloqueados( array $ocupaciones ): array {
        if ( $this->isPlazaLiberable( $ocupaciones ) ) {
            return [];
        }

        $bloqueados = [];

        foreach ( $ocupaciones as $ocupacion ) {
            if ( 'trunca' === ( $ocupacion['cerrada_por'] ?? null ) ) {
                $bloqueados[ (int) $ocupacion['player_id'] ] = true;
            }
        }

        return array_keys( $bloqueados );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * The ONLY place this class invokes the injected callable — every public
     * method that needs a resolved-fechas count goes through here, so the
     * fail-closed defense (negative-count rejection, exception wrapping) is
     * applied exactly once, never duplicated per call site.
     *
     * @throws FechaCountUnavailableException When the callable throws (the
     *         original exception is chained as $previous), or when it
     *         returns a negative count — impossible for a real "resolved
     *         fechas since X" answer, so a negative value can only mean the
     *         counter itself is broken. An INFLATED count is NOT detected
     *         here — see FechaCountUnavailableException's docblock for why
     *         that is out of this class's reach.
     */
    private function countResolvedFechasSince( int $fechaId ): int {
        try {
            $count = ( $this->countResolvedFechasSinceFn )( $fechaId );
        } catch ( \Throwable $e ) {
            throw new FechaCountUnavailableException(
                "the injected callable threw for fecha_id {$fechaId}: " . $e->getMessage(),
                $e
            );
        }

        if ( $count < 0 ) {
            throw new FechaCountUnavailableException(
                "the injected callable returned a negative count ({$count}) for fecha_id {$fechaId}"
            );
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<string, mixed>|null
     */
    private function vigente( array $ocupaciones ): ?array {
        foreach ( $ocupaciones as $ocupacion ) {
            if ( null === ( $ocupacion['fecha_hasta_id'] ?? null ) ) {
                return $ocupacion;
            }
        }

        return null;
    }
}
