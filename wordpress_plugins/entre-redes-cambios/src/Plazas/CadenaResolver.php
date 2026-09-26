<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

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
 * construction. `plazaLiberable()` recomputes it fresh from the VIGENT
 * link's `fecha_desde_id` every time it is called — see that method's
 * docblock for why "vigent link" is the only one that matters for this
 * computation, regardless of how many prior links are 'trunca'.
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
     */
    public function cumpleMinimo( array $ocupacion, int $minimo = 3 ): bool {
        $fechaDesdeId = (int) $ocupacion['fecha_desde_id'];

        return ( $this->countResolvedFechasSinceFn )( $fechaDesdeId ) >= $minimo;
    }

    /**
     * How many resolved fechas are still missing before the plaza becomes
     * liberable — 0 when it already is. Derived from the VIGENT link only;
     * see class docblock for why closed 'trunca' links are irrelevant to
     * this count (their own mínimo, met or not, was already decided at the
     * moment they closed).
     *
     * @param array<int, array<string, mixed>> $ocupaciones The full chain,
     *        as returned by `PlazaRepository::listOcupaciones()`.
     */
    public function fechasFaltantesParaLiberar( array $ocupaciones, int $minimo = 3 ): int {
        $vigente = $this->vigente( $ocupaciones );

        if ( null === $vigente ) {
            // No vigent link at all — nothing is occupying the plaza, so
            // there is nothing left to wait for.
            return 0;
        }

        $resueltas = ( $this->countResolvedFechasSinceFn )( (int) $vigente['fecha_desde_id'] );

        return max( 0, $minimo - $resueltas );
    }

    /**
     * The plaza's single derived liberation condition: true once the VIGENT
     * link has cleared $minimo resolved fechas. This is intentionally
     * recomputed from scratch every call — see class docblock's "DERIVED,
     * NEVER STORED" section — so a caller must never cache this result
     * across a new link being appended to the chain.
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     */
    public function plazaLiberable( array $ocupaciones, int $minimo = 3 ): bool {
        return 0 === $this->fechasFaltantesParaLiberar( $ocupaciones, $minimo );
    }

    /**
     * Whether the plaza's PERMANENT titular (`cambios_plaza.titular_player_id`)
     * may return right now — false when the titular already occupies it
     * (nothing to return FROM), otherwise exactly `plazaLiberable()`.
     *
     * @param array<string, mixed>             $plaza       As returned by
     *        `PlazaRepository::findPlaza()`.
     * @param array<int, array<string, mixed>> $ocupaciones
     */
    public function titularPuedeVolver( array $plaza, array $ocupaciones, int $minimo = 3 ): bool {
        $vigente = $this->vigente( $ocupaciones );

        if ( null === $vigente ) {
            return false;
        }

        if ( (int) $vigente['player_id'] === (int) $plaza['titular_player_id'] ) {
            return false;
        }

        return $this->plazaLiberable( $ocupaciones, $minimo );
    }

    /**
     * The player_id's that closed 'trunca' (left before meeting the mínimo)
     * and remain blocked from re-entering this plaza because it has not
     * liberated yet. See class docblock: liberation unblocks every one of
     * them AT ONCE — there is no per-player liberation moment, only the
     * plaza's single one.
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array<int, int>
     */
    public function exOcupantesBloqueados( array $ocupaciones ): array {
        if ( $this->plazaLiberable( $ocupaciones ) ) {
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
