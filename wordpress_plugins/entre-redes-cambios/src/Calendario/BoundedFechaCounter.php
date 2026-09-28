<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;

/**
 * THE single implementation of "a resolved-fechas counter that refuses to
 * lie upward" — extracted out of Dictamen\DictamenContextAssembler's own
 * private `boundedCountResolvedFechasSinceFn()` / `countTotalResolvedFechas()`
 * so BOTH consumers that need this exact guarantee share ONE implementation,
 * never two independently written copies that could drift apart.
 *
 * *** WHY THIS EXISTS: THE SAME "TWO IMPLEMENTATIONS MUST NEVER DISAGREE"
 * PROBLEM AS Plazas\CandidatosResolver *** `Dictamen\DictamenContextAssembler`
 * feeds the dictamen engine a `countResolvedFechasSinceFn` bounded by the
 * season's own total of resolved fechas — see this class's docblock section
 * "THE SANITY CAP" below for what "bounded" means and why an inflated
 * counter must fail loud rather than silently misbehave. Before this
 * extraction, `Rest\PlazasController::listarCandidatos()` built its OWN
 * closure straight from `FechaRepository::countResolvedFechasSince()`, with
 * NO cap and NO exception path — so the captain's candidatos screen could
 * show a candidate as "viable" exactly where the dictamen engine, fed the
 * SAME corrupted counter through the bounded version, would refuse. That is
 * the screen-disagrees-with-engine failure `Plazas\CandidatosResolver`'s own
 * class docblock already warns about, for the exact same reason. Both
 * consumers now call this ONE class.
 *
 * *** THE SANITY CAP ***
 * `Plazas\CadenaResolver`'s own class docblock is explicit that an INFLATED
 * resolved-fechas count (the callable lying UPWARD) is NOT something that
 * class can detect on its own — it has no season total to compare against.
 * "Bounded is the caller's responsibility", and this class is that caller:
 * `boundedCountResolvedFechasSinceFn()` wraps
 * `FechaRepository::countResolvedFechasSince()` with the season's own total
 * of resolved fechas and throws `Plazas\Exception\FechaCountUnavailableException`
 * the instant a count exceeds that total — an answer that is, by
 * construction, impossible. `CadenaResolver` (and `Reglas\EntranteNoBloqueado`,
 * which calls the injected callable directly for its `tope_tres_fechas`
 * policy) already translate that exception into a fail-closed answer; this
 * wrapper is what makes that translation reachable at all.
 */
final class BoundedFechaCounter {

    private FechaRepository $fechaRepository;
    private EventLog $eventLog;

    public function __construct( FechaRepository $fechaRepository, EventLog $eventLog ) {
        $this->fechaRepository = $fechaRepository;
        $this->eventLog        = $eventLog;
    }

    /**
     * @return callable(int): int A counter bounded to $seasonId, exactly the
     *         contract DictamenContext::countResolvedFechasSinceFn() /
     *         CadenaResolver's constructor parameter / BloqueoReemplazoEvaluator
     *         expect.
     */
    public function boundedCountResolvedFechasSinceFn( int $seasonId ): callable {
        $totalResueltas = $this->countTotalResolvedFechas( $seasonId );

        return function ( int $fechaId ) use ( $seasonId, $totalResueltas ): int {
            $count = $this->fechaRepository->countResolvedFechasSince( $seasonId, $fechaId );

            if ( $count > $totalResueltas ) {
                $this->eventLog->record( 'contador.fechas_resueltas_inflado', [
                    'season_id'       => $seasonId,
                    'fecha_id'        => $fechaId,
                    'count'           => $count,
                    'total_resueltas' => $totalResueltas,
                ] );

                throw new FechaCountUnavailableException(
                    sprintf(
                        "countResolvedFechasSince() returned %d for fecha_id %d, more than the season's own "
                            . 'total of %d resolved fechas — impossible, the counter itself must be broken.',
                        $count,
                        $fechaId,
                        $totalResueltas
                    )
                );
            }

            return $count;
        };
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function countTotalResolvedFechas( int $seasonId ): int {
        $fechas = $this->fechaRepository->listBySeason( $seasonId );

        return count( array_filter(
            $fechas,
            static fn ( array $f ): bool => in_array( (string) ( $f['estado'] ?? '' ), [ 'jugada', 'dirimida' ], true )
        ) );
    }
}
