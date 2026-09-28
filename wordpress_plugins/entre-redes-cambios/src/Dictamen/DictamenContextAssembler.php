<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;

/**
 * Builds a `DictamenContext` from the real database for a given
 * `SolicitudDeCambio` — the piece slice 3's README explicitly left out
 * ("Assembling DictamenContext from the real database ... is slice 4's
 * job"). Nothing here decides whether a solicitud is favorable; this class
 * only loads the facts `DictamenEngine`'s rules need, zero business logic.
 *
 * *** THE CONTRACT THAT GOVERNS THIS WHOLE CLASS ***
 * Every query this class runs (directly, or via the repositories it calls)
 * MUST throw on failure, never silently produce an empty collection. See
 * `Plazas\PlazaRepository`'s class docblock, "READ FAILURES MUST NEVER READ
 * AS 'NO ROWS'", and README's "Contracts for slice 4" point 1: an empty
 * `entranteOcupacionesEnOtrasPlazas()` or `entrantePlazasConCierreTruncado()`
 * reads to `Reglas\EntranteDisponible` / `Reglas\EntranteNoBloqueado` as
 * "confirmado, sin conflicto" — a query that fails silently would become a
 * silent approval, not a rejection. If the plaza or the fecha named by the
 * solicitud does not exist at all, this class throws rather than assembling
 * a half-built context: a `Regla` can never distinguish "no conflict" from
 * "I could not look".
 *
 * *** WHY listOcupaciones() ITSELF WAS NOT CHANGED ***
 * The plaza's OWN chain (`DictamenContext::ocupaciones()`, read via
 * `PlazaRepository::listOcupaciones()`, unchanged from slice 2) is not
 * covered by the same throw-on-failure discipline as the two NEW repository
 * queries this assembler also calls. That is deliberate, not an oversight:
 * an empty `ocupaciones()` chain does NOT read as "no conflict" anywhere in
 * this ruleset — `DictamenContext::vigente()` returns null for an empty
 * chain, and `Reglas\PlazaConOcupacionVigente` treats "no vigent link" as
 * its own REJECTING motivo (`plaza_sin_ocupacion_vigente`). A silently
 * failed fetch of the plaza's own chain therefore already fails CLOSED by
 * construction of the existing ruleset, unlike the two new queries this
 * class was specifically written to guard.
 *
 * *** THE PLAZOS FRAME IS UTC, ON PURPOSE ***
 * `Calendario\PlazosCalculator::computeUtc()` is used here, NEVER
 * `compute()` — `SolicitudDeCambio::instanteEpoch()` is an absolute Unix
 * epoch, and `Reglas\SolicitudEnPlazo` compares it (via `gmdate()`) against
 * `DictamenContext::plazosUtc()` in the UTC frame. Passing `compute()`'s
 * civil strings here would silently reintroduce the exact timezone bug
 * `PlazosCalculator`'s own class docblock warns about — the fecha's
 * `play_date` is loaded once, via `FechaRepository::findById()`, and fed to
 * `computeUtc()` together with `Settings::plazosOffsets()` and
 * `Settings::timezone()`.
 *
 * *** PADRES VIABLES ES POLÍTICA-DEPENDIENTE ***
 * `DictamenContext::padresViablesParaLaPlaza()` is not a plain fact like
 * `ocupaciones()` — whether a given OTHER candidate is "viable" depends on
 * `BloqueoReemplazoPolicy` (CC5b), exactly like `Reglas\EntranteNoBloqueado`'s
 * own verdict for the named entrante does. `DictamenContext` itself stays
 * policy-agnostic for every OTHER field (a Regla decides how to read
 * `entrantePlazasConCierreTruncado()`), but this one count cannot be handed
 * over raw — `Plazas\CandidatosResolver::contarPadresViables()` needs a
 * policy to even compute it. This assembler is therefore constructed with
 * the SAME `$politicaCC5b` value `Plugin::boot()` also hands to
 * `DictamenPipeline` (see that class's own docblock), so the count baked
 * into the context and the policy `Reglas\EntranteNoBloqueado` separately
 * applies to the NAMED entrante can never silently disagree.
 *
 * *** NO QUERY WHEN THE POLICY IS OFF, OR WHEN IT WOULD BE WASTED ***
 * `Plazas\CandidatosResolver::contarPadresViables()` is, by its own class
 * docblock, an N+1 query over the plaza's whole season roster — this
 * assembler only ever calls it when `Settings::prioridadPadresActiva()` is
 * `true` AND the entrante is NOT already a padre (an entrante who already is
 * a padre can never trigger `Reglas\PrioridadDePadresRespetada`, so counting
 * padres for them would be work nobody reads). With the policy at its
 * default (OFF), `DictamenContext::padresViablesParaLaPlaza()` stays `0`
 * without a single extra query.
 *
 * *** THE SANITY CAP ON THE INJECTED COUNTER ***
 * `Plazas\CadenaResolver`'s own class docblock is explicit that an INFLATED
 * resolved-fechas count (the callable lying UPWARD) is NOT something that
 * class can detect on its own — it has no season total to compare against.
 * "Bounded is the caller's responsibility", and this assembler is that
 * caller: `boundedCountResolvedFechasSinceFn()` wraps
 * `FechaRepository::countResolvedFechasSince()` with the season's own total
 * of resolved fechas (`countTotalResolvedFechas()`) and throws
 * `Plazas\Exception\FechaCountUnavailableException` the instant a count
 * exceeds that total — an answer that is, by construction, impossible.
 * `CadenaResolver` (and `Reglas\EntranteNoBloqueado`, which calls the
 * injected callable directly for its `tope_tres_fechas` policy) already
 * translate that exception into a fail-closed answer; this wrapper is what
 * makes that translation reachable at all.
 */
final class DictamenContextAssembler {

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private Settings $settings;
    private \wpdb $wpdb;
    private EventLog $eventLog;
    private JugadorMetricasReader $metricasReader;
    private CandidatosResolver $candidatosResolver;
    private BloqueoReemplazoPolicy $politicaCC5b;

    /**
     * @param BloqueoReemplazoPolicy|null $politicaCC5b The SAME policy value
     *        `Plugin::boot()` also hands to `DictamenPipeline` /
     *        `DictamenEngineFactory::create()` — see this class's docblock,
     *        "PADRES VIABLES ES POLÍTICA-DEPENDIENTE". Null keeps
     *        Reglas\EntranteNoBloqueado's own default
     *        (`BloqueoReemplazoPolicy::topeTresFechas()`), exactly like that
     *        rule's constructor.
     * @param CandidatosResolver|null $candidatosResolver Defaults to a plain
     *        instance built from $plazaRepository/$wpdb — overridable in
     *        tests, same pattern as every other optional collaborator in
     *        this class.
     */
    public function __construct(
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        Settings $settings,
        \wpdb $wpdb,
        EventLog $eventLog,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        ?CandidatosResolver $candidatosResolver = null,
        ?JugadorMetricasReader $metricasReader = null
    ) {
        $this->plazaRepository    = $plazaRepository;
        $this->fechaRepository    = $fechaRepository;
        $this->settings           = $settings;
        $this->wpdb               = $wpdb;
        $this->eventLog           = $eventLog;
        $this->politicaCC5b       = $politicaCC5b ?? BloqueoReemplazoPolicy::topeTresFechas();
        $this->metricasReader     = $metricasReader ?? new JugadorMetricasReader( $wpdb );
        $this->candidatosResolver = $candidatosResolver ?? new CandidatosResolver( $wpdb, $plazaRepository, $this->metricasReader );
    }

    /**
     * @throws \RuntimeException When the plaza or the fecha named by
     *         $solicitud does not exist, or when any underlying query fails
     *         — see class docblock.
     */
    public function assemble( SolicitudDeCambio $solicitud ): DictamenContext {
        $plaza = $this->plazaRepository->findPlaza( $solicitud->plazaId() );

        if ( null === $plaza ) {
            $this->eventLog->record( 'contexto.fallido', [
                'operacion' => 'assemble',
                'motivo'    => 'la plaza no existe',
                'plaza_id'  => $solicitud->plazaId(),
                'season_id' => $solicitud->seasonId(),
            ] );

            throw new \RuntimeException(
                "DictamenContextAssembler::assemble(): plaza {$solicitud->plazaId()} does not exist — "
                . 'refusing to assemble a half-built context.'
            );
        }

        $fecha = $this->fechaRepository->findById( $solicitud->fechaId() );

        if ( null === $fecha ) {
            $this->eventLog->record( 'contexto.fallido', [
                'operacion' => 'assemble',
                'motivo'    => 'la fecha no existe',
                'fecha_id'  => $solicitud->fechaId(),
                'season_id' => $solicitud->seasonId(),
            ] );

            throw new \RuntimeException(
                "DictamenContextAssembler::assemble(): fecha {$solicitud->fechaId()} does not exist — "
                . 'refusing to assemble a half-built context.'
            );
        }

        $ocupaciones = $this->plazaRepository->listOcupaciones( $solicitud->plazaId() );

        $countResolvedFechasSinceFn = $this->boundedCountResolvedFechasSinceFn( $solicitud->seasonId() );

        $entrantePlayerId                 = $solicitud->entrantePlayerId();
        $entrantePuntaje                  = null;
        $entranteEsPadre                  = false;
        $entranteOcupacionesEnOtrasPlazas = [];
        $entrantePlazasConCierreTruncado  = [];
        $padresViablesParaLaPlaza         = 0;

        if ( null !== $entrantePlayerId ) {
            $metricas        = $this->metricasReader->resolve( $entrantePlayerId );
            $entrantePuntaje = $metricas->puntaje();
            $entranteEsPadre = $metricas->esPadre();

            if ( null === $entrantePuntaje ) {
                $this->eventLog->record( 'entrante.puntaje_no_encontrado', [
                    'player_id' => $entrantePlayerId,
                ] );
            }

            $entranteOcupacionesEnOtrasPlazas = $this->plazaRepository->listOcupacionesVigentesDeJugador(
                $solicitud->seasonId(),
                $entrantePlayerId,
                $solicitud->plazaId()
            );

            $entrantePlazasConCierreTruncado = $this->otherPlazasOnly(
                $this->plazaRepository->listPlazasConCierreTruncadoDeJugador( $solicitud->seasonId(), $entrantePlayerId ),
                $solicitud->plazaId()
            );

            // Never gasta el query costoso de CandidatosResolver a menos que
            // la política esté encendida Y el entrante realmente la necesite
            // — un entrante que YA es padre nunca dispara
            // Reglas\PrioridadDePadresRespetada, así que contar padres
            // viables para él sería trabajo tirado. See class docblock,
            // "PADRES VIABLES ES POLÍTICA-DEPENDIENTE".
            if ( $this->settings->prioridadPadresActiva() && ! $entranteEsPadre ) {
                $padresViablesParaLaPlaza = $this->candidatosResolver->contarPadresViables(
                    $plaza,
                    $this->politicaCC5b,
                    $countResolvedFechasSinceFn
                );
            }
        }

        $plazosUtc = PlazosCalculator::computeUtc(
            (string) $fecha['play_date'],
            $this->settings->plazosOffsets(),
            $this->settings->timezone()
        );

        return new DictamenContext(
            $solicitud,
            $plaza,
            $ocupaciones,
            $entrantePuntaje,
            $entranteOcupacionesEnOtrasPlazas,
            $entrantePlazasConCierreTruncado,
            $plazosUtc,
            $countResolvedFechasSinceFn,
            $entranteEsPadre,
            $padresViablesParaLaPlaza
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * `PlazaRepository::listPlazasConCierreTruncadoDeJugador()` has no
     * "excluding this plaza" parameter of its own (unlike
     * `listOcupacionesVigentesDeJugador()`) — see that method's docblock.
     * `Reglas\EntranteNoBloqueado` only cares about OTHER plazas (the
     * entrante's own trunca history on the CURRENT plaza, if any, is already
     * covered by `Reglas\RegresoSoloConMinimoCumplido` /
     * `Reglas\PlazaConOcupacionVigente` reading `DictamenContext::ocupaciones()`
     * directly), so this filters the current plaza's own chain out here,
     * once, rather than teaching the repository method a parameter only
     * this one caller needs.
     *
     * @param array<int, array<int, array<string, mixed>>> $chains
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function otherPlazasOnly( array $chains, int $currentPlazaId ): array {
        return array_values( array_filter(
            $chains,
            static function ( array $chain ) use ( $currentPlazaId ): bool {
                $chainPlazaId = isset( $chain[0]['plaza_id'] ) ? (int) $chain[0]['plaza_id'] : null;

                return $chainPlazaId !== $currentPlazaId;
            }
        ) );
    }

    /**
     * See class docblock, "THE SANITY CAP ON THE INJECTED COUNTER".
     */
    private function boundedCountResolvedFechasSinceFn( int $seasonId ): callable {
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

    private function countTotalResolvedFechas( int $seasonId ): int {
        $fechas = $this->fechaRepository->listBySeason( $seasonId );

        return count( array_filter(
            $fechas,
            static fn ( array $f ): bool => in_array( (string) ( $f['estado'] ?? '' ), [ 'jugada', 'dirimida' ], true )
        ) );
    }
}
