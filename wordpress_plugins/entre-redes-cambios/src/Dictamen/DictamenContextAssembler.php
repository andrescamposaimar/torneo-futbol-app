<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Dictamen;

use EntreRedes\Cambios\Calendario\BoundedFechaCounter;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\PlazosCalculator;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\PosicionResolver;

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
 * *** listOcupaciones() NOW THROWS TOO (read-failure audit) ***
 * The plaza's OWN chain (`DictamenContext::ocupaciones()`, read via
 * `PlazaRepository::listOcupaciones()`) used to be exempt from the
 * throw-on-failure discipline the two NEW repository queries below follow:
 * an empty `ocupaciones()` chain does NOT read as "no conflict" anywhere in
 * this ruleset — `DictamenContext::vigente()` returns null for an empty
 * chain, and `Reglas\PlazaConOcupacionVigente` treats "no vigent link" as
 * its own REJECTING motivo (`plaza_sin_ocupacion_vigente`) — so a silently
 * failed fetch already failed CLOSED by construction of the existing
 * ruleset. That reasoning was correct but incomplete: it covered the
 * dictamen engine, but not `Rest\PlazasController::listar()`, which reads
 * this SAME method's empty-on-failure result as the FACT "this plaza has no
 * current occupant" for a captain's roster screen. `PlazaRepository::listOcupaciones()`
 * now throws on a wpdb-level failure — see that method's own docblock — which
 * this assembler's `assemble()` lets propagate exactly like the two queries
 * below: `DictamenPipeline::evaluate()` catches it, logs `dictamen.fallido`,
 * and re-throws, so the failure reaches an honest 500, not a 200 rejection
 * with the wrong Motivo.
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
 * "Bounded is the caller's responsibility". `Calendario\BoundedFechaCounter`
 * is that caller — extracted out of what used to be this assembler's own
 * private `boundedCountResolvedFechasSinceFn()` / `countTotalResolvedFechas()`
 * so `Rest\PlazasController::listarCandidatos()` shares the EXACT same
 * bounded counter instead of building its own unbounded one (see that
 * collaborator's own class docblock, "WHY THIS EXISTS", for the
 * screen-disagrees-with-engine failure that drift would otherwise cause).
 * This assembler delegates to it, never re-deriving the cap itself.
 *
 * *** ARQUERO/PLAZA POSITION RESOLUTION — PLAZA READS A STORED FLAG; ONLY THE
 * ENTRANTE IS RESOLVED LIVE (CHANGED IN 0.1.13) ***
 * `DictamenContext::entranteEsArquero()` / `::plazaEsDelArquero()` feed
 * `Reglas\ArqueroNoOcupaPlazaDeCampo` — see that rule's own class docblock
 * for the two deliberately-distinct position predicates this used to read
 * off `Plazas\PosicionResolver`. Before 0.1.13, `plazaEsDelArquero()` was
 * DERIVED on every call from the plaza's titular's CURRENT `sp_position` —
 * which meant fixing a data-entry error on that position in WordPress could
 * silently flip which plaza was "the goal" out from under an
 * already-in-flight solicitud. `cambios_plaza.es_arco` (see
 * `Migrations\InitialSchema`'s own class docblock) is now the stored,
 * write-time-derived fact for that question — `resolverPosicionesArquero()`
 * below reads `$plaza['es_arco']` directly, no query, no `PosicionResolver`
 * call at all for the plaza's own side. Only `entranteEsArquero()` still
 * needs a LIVE lookup (the ENTRANTE is a candidate, not a stored record —
 * there is nothing to read a flag off), so the batched
 * `PosicionResolver::resolverParaIds()` call this assembler still makes now
 * covers AT MOST ONE id (the entrante's), never the plaza's titular's.
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
    private BoundedFechaCounter $boundedFechaCounter;
    private PosicionResolver $posicionResolver;

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
     * @param PosicionResolver|null $posicionResolver Defaults to a plain
     *        instance — overridable in tests, same pattern as every other
     *        optional collaborator in this class.
     */
    public function __construct(
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        Settings $settings,
        \wpdb $wpdb,
        EventLog $eventLog,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        ?CandidatosResolver $candidatosResolver = null,
        ?JugadorMetricasReader $metricasReader = null,
        ?PosicionResolver $posicionResolver = null
    ) {
        $this->plazaRepository     = $plazaRepository;
        $this->fechaRepository     = $fechaRepository;
        $this->settings            = $settings;
        $this->wpdb                = $wpdb;
        $this->eventLog            = $eventLog;
        $this->politicaCC5b        = $politicaCC5b ?? BloqueoReemplazoPolicy::topeTresFechas();
        $this->metricasReader      = $metricasReader ?? new JugadorMetricasReader( $wpdb, $eventLog );
        $this->candidatosResolver  = $candidatosResolver ?? new CandidatosResolver( $wpdb, $plazaRepository, $eventLog, $this->metricasReader );
        $this->boundedFechaCounter = new BoundedFechaCounter( $fechaRepository, $eventLog );
        $this->posicionResolver    = $posicionResolver ?? new PosicionResolver( $eventLog );
    }

    /**
     * @param bool $exencionArco Forwarded verbatim into the resulting
     *        `DictamenContext::exencionArco()` — see that accessor's own
     *        docblock. `false` for every ordinary caller; `true` ONLY for
     *        movement 1 of a grouped goalkeeper reassignment, passed by
     *        `Dictamen\DictamenPipeline::evaluateGrupo()` when
     *        `Calendario\Settings::exencionArcoActiva()` is on. This
     *        assembler does not read that setting itself, nor does it know
     *        anything about "grouped requests" — it only threads through a
     *        caller-supplied fact, the same pattern as every other
     *        constructor-injected policy in this class.
     * @throws \RuntimeException When the plaza or the fecha named by
     *         $solicitud does not exist, or when any underlying query fails
     *         — see class docblock.
     */
    public function assemble( SolicitudDeCambio $solicitud, bool $exencionArco = false ): DictamenContext {
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

        $countResolvedFechasSinceFn = $this->boundedFechaCounter->boundedCountResolvedFechasSinceFn( $solicitud->seasonId() );

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

        [ 'entranteEsArquero' => $entranteEsArquero, 'plazaEsDelArquero' => $plazaEsDelArquero ] =
            $this->resolverPosicionesArquero( $plaza, $entrantePlayerId );

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
            $padresViablesParaLaPlaza,
            $entranteEsArquero,
            $plazaEsDelArquero,
            $exencionArco
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * `plazaEsDelArquero` reads the plaza's own STORED `es_arco` flag — no
     * query, no derivation — while `entranteEsArquero` still needs a LIVE
     * `PosicionResolver::resolverParaIds()` lookup (at most one id: the
     * entrante's). See this class's own docblock, "ARQUERO/PLAZA POSITION
     * RESOLUTION", for why these two can no longer share one batched call:
     * the plaza's side is not a position lookup at all anymore.
     *
     * *** A FAILED ENTRANTE RESOLUTION MUST ABORT THE WHOLE DICTAMEN, NEVER
     * READ AS "NOT A GOALKEEPER" (0.1.14) *** `PosicionResolver::resolverParaIds()`
     * now throws rather than silently degrading — see that class's own
     * docblock. This method does NOT catch it: it propagates out of
     * `assemble()`, and `Dictamen\DictamenPipeline::evaluate()` already
     * catches `\Throwable` from `assemble()`, logs `dictamen.fallido`, and
     * RE-THROWS (see that method's own docblock) — so this reaches an honest
     * 500, never a dictamen that silently treated an unresolvable entrante as
     * "definitely not a goalkeeper" and let `Reglas\ArqueroNoOcupaPlazaDeCampo`
     * wrongly approve a request it should have blocked.
     *
     * @param array<string, mixed> $plaza MUST carry `es_arco` (as persisted
     *        by `Plazas\PlazaRepository::doOpenPlaza()` / backfilled by
     *        `Migrations\MigrationRunner::backfillEsArco()`).
     * @return array{entranteEsArquero: bool, plazaEsDelArquero: bool}
     * @throws \RuntimeException When PosicionResolver::resolverParaIds()
     *         could not resolve the entrante's position — see above.
     */
    private function resolverPosicionesArquero( array $plaza, ?int $entrantePlayerId ): array {
        $plazaEsDelArquero = (bool) ( $plaza['es_arco'] ?? false );

        if ( null === $entrantePlayerId ) {
            return [ 'entranteEsArquero' => false, 'plazaEsDelArquero' => $plazaEsDelArquero ];
        }

        $posiciones = $this->posicionResolver->resolverParaIds( [ $entrantePlayerId ] );

        return [
            'entranteEsArquero' => PosicionResolver::esPosicionDeArquero( $posiciones[ $entrantePlayerId ] ),
            'plazaEsDelArquero' => $plazaEsDelArquero,
        ];
    }

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
}
