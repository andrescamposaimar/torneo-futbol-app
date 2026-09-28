<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Dictamen\BloqueoReemplazoEvaluator;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\ChecksReads;

/**
 * THE single source of truth for "who could occupy this plaza, and are they
 * actually viable" — built for the prioridad-de-padres feature (see
 * Dictamen\Reglas\PrioridadDePadresRespetada), but consumed by BOTH ends that
 * need this exact answer: that rule (to know whether a viable padre exists)
 * and the captain-facing candidatos endpoint (Rest\PlazasController). Neither
 * may compute its own version of "viable" — see this class's own docblock
 * further down, "WHY THIS MUST BE THE ONLY IMPLEMENTATION", for what goes
 * wrong if they ever disagree.
 *
 * *** WHERE THE CANDIDATE POOL COMES FROM ***
 * There is no `cambios_*` table listing "candidates for a plaza" — the real
 * "lista de espera" is a manually curated process entirely outside this
 * plugin (a monthly window, consolidated by a different committee member,
 * into a JSON file this plugin never reads — deliberately out of v1 scope).
 * What this class treats as "every candidate" is every `sp_player` post
 * registered in the plaza's OWN season (`sp_season` taxonomy term = the
 * plaza's `season_id` — confirmed to be the same id space as
 * Calendario\Settings::seasonId()'s own docblock: "the SportsPress season
 * term id"), excluding the plaza's own current vigent occupant (the
 * incumbent is not a candidate to replace themself).
 *
 * This is a deliberately BROADER population than the true waiting list — it
 * includes every OTHER team's titulares and suplentes too. That is fine and
 * not a bug: a player who currently occupies ANY vigent plaza this season is
 * exactly what the "occupying another plaza" viability check below excludes,
 * so the population that survives every filter converges on "players not
 * currently tied to a plaza" — the actual candidate pool — without this
 * class having to consult the separate lista-de-espera process at all.
 *
 * *** EXPLICITLY OUT OF SCOPE (v1) ***
 * The "categoría de inscripción" gate (titular / lista de espera / no
 * inscripto — a "no inscripto" is never eligible at all) and the "ventana
 * mensual" gate (a candidate offered mid-month does not enter the pool until
 * the 1st) are BOTH real rules the product owner described, and both are
 * DELIBERATELY not modeled here — the product owner scoped the v1 of this
 * whole cambios feature to the WEEKLY cambios cycle only, leaving the
 * monthly lista-de-espera cycle (owned by a different stakeholder) for
 * later. Implementing either gate now would mean inventing a data source
 * this plugin does not have. If a future slice adds them, this is the one
 * place to add the extra filter — every consumer already goes through here.
 *
 * *** WHAT "VIABLE" MEANS *** puntaje resolvable AND within the plaza's
 * techo, AND not currently occupying a DIFFERENT vigent plaza this season,
 * AND not blocked by a trunca closure elsewhere under the given
 * BloqueoReemplazoPolicy — the exact three checks
 * Reglas\PuntajeDentroDelTecho / Reglas\EntranteDisponible /
 * Reglas\EntranteNoBloqueado already apply to a solicitud's NAMED entrante,
 * generalized here over every candidate. Deliberately reuses
 * PlazaRepository's existing, already-tested per-player queries
 * (listOcupacionesVigentesDeJugador(), listPlazasConCierreTruncadoDeJugador())
 * and Dictamen\BloqueoReemplazoEvaluator rather than re-deriving either — see
 * "WHY THIS MUST BE THE ONLY IMPLEMENTATION" below.
 *
 * *** WHY THIS MUST BE THE ONLY IMPLEMENTATION ***
 * If the captain's candidatos screen computed viability on its own (or a
 * future client re-derived it from raw plaza data), it could show a
 * candidate as available that the dictamen engine then rejects — the exact
 * failure mode this feature's own task brief calls out as WORSE than not
 * having the rule at all: a captain who trusted the screen gets a rejection
 * for a request the system itself said was fine. `Reglas\
 * PrioridadDePadresRespetada` and the candidatos endpoint both call THIS
 * class and nothing else for "is X viable".
 *
 * *** COST: THIS IS N+1 BY DESIGN, AND THAT IS WHY THE POLICY DEFAULTS OFF
 * *** Each candidate costs 2 extra queries (vigencia elsewhere, trunca
 * closures elsewhere) on top of the batched roster + metrics queries — for a
 * few hundred season-registered players, that is a few hundred queries. This
 * is deliberately NOT optimized into a single batched query in this v1: the
 * policy this class exists for is OFF by default precisely so this cost is
 * never paid unless a process owner explicitly turns it on (see
 * Dictamen\DictamenContextAssembler's own gating), and this endpoint is a
 * captain looking at ONE plaza, not a hot path. A future slice can batch
 * these two queries the same way JugadorMetricasReader::resolveMuchos()
 * already batches the metrics read, if the cost ever matters in practice.
 */
/**
 * Not `final` — mocked as a collaborator by Rest\PlazasControllerTest,
 * exactly like PlazaRepository / FechaRepository / CapitanAuthorizer /
 * SolicitudRepository (none of which are `final` either, for the same
 * reason: PHPUnit's `createMock()` cannot double a `final` class).
 */
class CandidatosResolver {

    use ChecksReads;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private EventLog $eventLog;
    private JugadorMetricasReader $metricasReader;
    private BloqueoReemplazoEvaluator $bloqueoEvaluator;

    /**
     * @param EventLog $eventLog MANDATORY, no null-object fallback — same
     *        discipline as every other class in this plugin that reads
     *        directly against `$wpdb` (PlazaRepository, FechaRepository,
     *        SolicitudRepository, CapitanRepository,
     *        DictamenContextAssembler): this class has nowhere to record a
     *        failed read without it. See Support\ChecksReads for what it is
     *        used for here.
     */
    public function __construct(
        \wpdb $wpdb,
        PlazaRepository $plazaRepository,
        EventLog $eventLog,
        ?JugadorMetricasReader $metricasReader = null,
        ?BloqueoReemplazoEvaluator $bloqueoEvaluator = null
    ) {
        $this->wpdb             = $wpdb;
        $this->plazaRepository  = $plazaRepository;
        $this->eventLog         = $eventLog;
        $this->metricasReader   = $metricasReader ?? new JugadorMetricasReader( $wpdb, $eventLog );
        $this->bloqueoEvaluator = $bloqueoEvaluator ?? new BloqueoReemplazoEvaluator();
    }

    /**
     * Every candidate for $plaza, each with its own viability verdict.
     *
     * @param array<string, mixed> $plaza As returned by
     *        PlazaRepository::findPlaza() — MUST carry `id`,
     *        `season_id` and `puntaje_techo`.
     * @param callable(int): int  $countResolvedFechasSinceFn Same contract as
     *        DictamenContext::countResolvedFechasSinceFn() — bounded to
     *        $plaza['season_id'] by the caller (see
     *        Calendario\BoundedFechaCounter::boundedCountResolvedFechasSinceFn()
     *        for the production wiring, shared by
     *        Dictamen\DictamenContextAssembler and Rest\PlazasController).
     * @return CandidatoEstado[]
     */
    public function paraPlaza( array $plaza, BloqueoReemplazoPolicy $politica, callable $countResolvedFechasSinceFn ): array {
        $plazaId   = (int) $plaza['id'];
        $seasonId  = (int) $plaza['season_id'];
        $techo     = Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] );

        $vigente          = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $ocupanteActualId = null !== $vigente ? (int) $vigente['player_id'] : null;

        $candidatoIds = array_values( array_filter(
            $this->playerIdsRegistradosEnTemporada( $seasonId ),
            static fn ( int $playerId ): bool => $playerId !== $ocupanteActualId
        ) );

        $metricas = $this->metricasReader->resolveMuchos( $candidatoIds );

        return array_map(
            fn ( int $playerId ): CandidatoEstado => $this->evaluarCandidato(
                $playerId,
                $metricas[ $playerId ],
                $techo,
                $plazaId,
                $seasonId,
                $politica,
                $countResolvedFechasSinceFn
            ),
            $candidatoIds
        );
    }

    /**
     * How many candidates for $plaza are BOTH padres AND viable — exactly
     * what Reglas\PrioridadDePadresRespetada needs to know, and the number
     * its Motivo reports to the captain.
     */
    public function contarPadresViables( array $plaza, BloqueoReemplazoPolicy $politica, callable $countResolvedFechasSinceFn ): int {
        $candidatos = $this->paraPlaza( $plaza, $politica, $countResolvedFechasSinceFn );

        return count( array_filter(
            $candidatos,
            static fn ( CandidatoEstado $c ): bool => $c->esPadre() && $c->viable()
        ) );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function evaluarCandidato(
        int $playerId,
        JugadorMetricas $metricas,
        Puntaje $techo,
        int $plazaId,
        int $seasonId,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): CandidatoEstado {
        $puntaje = $metricas->puntaje();

        if ( null === $puntaje ) {
            return new CandidatoEstado( $playerId, $metricas->esPadre(), null, false, 'puntaje_indeterminado' );
        }

        if ( ! $techo->allows( $puntaje ) ) {
            return new CandidatoEstado( $playerId, $metricas->esPadre(), $puntaje, false, 'puntaje_excede_techo' );
        }

        $ocupacionesEnOtrasPlazas = $this->plazaRepository->listOcupacionesVigentesDeJugador( $seasonId, $playerId, $plazaId );

        if ( ! empty( $ocupacionesEnOtrasPlazas ) ) {
            return new CandidatoEstado( $playerId, $metricas->esPadre(), $puntaje, false, 'ocupa_otra_plaza_vigente' );
        }

        $plazasConCierreTruncado = array_values( array_filter(
            $this->plazaRepository->listPlazasConCierreTruncadoDeJugador( $seasonId, $playerId ),
            static function ( array $chain ) use ( $plazaId ): bool {
                $chainPlazaId = isset( $chain[0]['plaza_id'] ) ? (int) $chain[0]['plaza_id'] : null;

                return $chainPlazaId !== $plazaId;
            }
        ) );

        if ( $this->bloqueoEvaluator->isBlockedEnAlguna( $playerId, $plazasConCierreTruncado, $politica, $countResolvedFechasSinceFn ) ) {
            return new CandidatoEstado( $playerId, $metricas->esPadre(), $puntaje, false, 'bloqueado_por_cierre_truncado' );
        }

        return new CandidatoEstado( $playerId, $metricas->esPadre(), $puntaje, true, null );
    }

    /**
     * Every `sp_player` post registered (via the `sp_season` taxonomy) in
     * $seasonId — raw SQL against WordPress core tables, never `WP_Query`,
     * consistent with every other read in this plugin (see PlazaRepository's
     * class docblock): `WP_Query`/`tax_query` has no equivalent in the SQLite
     * test shim this plugin's whole suite relies on.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** This
     * result feeds `paraPlaza()`'s whole candidate pool, and from there
     * `contarPadresViables()` — the exact count
     * `Dictamen\Reglas\PrioridadDePadresRespetada` gates on. A failed read
     * misread as "zero candidates" would make that count `0`, which the rule
     * reads as "no viable padre exists" — turning a database failure into a
     * silent APPROVAL of a non-padre entrante. See Support\ChecksReads.
     *
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsRegistradosEnTemporada( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id
                   FROM {$p}posts posts
                   INNER JOIN {$p}term_relationships tr ON tr.object_id = posts.ID
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND tt.taxonomy = 'sp_season'
                    AND tt.term_id = %d
                  ORDER BY posts.ID ASC",
                $seasonId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsRegistradosEnTemporada', [ 'season_id' => $seasonId ] );

        return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
    }
}
