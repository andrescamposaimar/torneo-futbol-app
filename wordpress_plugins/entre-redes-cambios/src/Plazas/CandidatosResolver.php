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
 * *** paraSeccion() IS A SEPARATE, WIDER ENTRY POINT — paraPlaza() ITSELF DID
 * NOT CHANGE *** A later slice gave the captain's screen two MORE
 * populations to choose from — the real "lista de espera" pseudo-team
 * (`Plazas\ListaEsperaResolver`) and the whole padrón (every OTHER published
 * `sp_player`, season-registered or not) — see `paraSeccion()`'s own
 * docblock. Both reuse the EXACT SAME viability check as `paraPlaza()`
 * (`evaluarCandidatos()`, shared by both), but neither touches
 * `playerIdsRegistradosEnTemporada()` or `paraPlaza()`'s own season-scoped
 * population: `Reglas\PrioridadDePadresRespetada` (via `contarPadresViables()`,
 * which only ever calls `paraPlaza()`) therefore keeps counting viable
 * padres over the SAME season-registered pool it always has, never the
 * widened one — a deliberate choice, not an oversight; see `paraSeccion()`'s
 * own docblock, "WHY THIS DOES NOT FEED contarPadresViables()", for the
 * reasoning.
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
 * *** COST: THE CEILING FILTER RUNS BEFORE THE N+1, NEVER AFTER ***
 * Each candidate who clears the techo costs 2 extra queries (vigencia
 * elsewhere, trunca closures elsewhere) on top of the batched roster +
 * metrics queries — evaluarCandidatos() below partitions every candidate by
 * techo FIRST, from the already-batched `JugadorMetricasReader::resolveMuchos()`
 * read (no extra query), and runs those 2 queries ONLY for whoever survives
 * that partition. A candidate with no resolvable puntaje, or a puntaje over
 * the plaza's techo, costs nothing beyond their share of the one batched
 * metrics read — see evaluarCandidatos()'s own docblock, and
 * CandidatosResolverTest's query-count assertions for the measured effect at
 * a realistic ceiling. This is still NOT optimized further than that (the 2
 * queries for whoever DOES clear the techo are still one pair per
 * candidate, not batched the way the metrics read is) — a future slice can
 * batch those too, the same way JugadorMetricasReader::resolveMuchos()
 * already batches the metrics read, if the remaining cost ever matters in
 * practice.
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
        $plazaId  = (int) $plaza['id'];
        $seasonId = (int) $plaza['season_id'];
        $techo    = Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] );

        $vigente          = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $ocupanteActualId = null !== $vigente ? (int) $vigente['player_id'] : null;

        $candidatoIds = array_values( array_filter(
            $this->playerIdsRegistradosEnTemporada( $seasonId ),
            static fn ( int $playerId ): bool => $playerId !== $ocupanteActualId
        ) );

        $metricas = $this->metricasReader->resolveMuchos( $candidatoIds );

        return $this->evaluarCandidatos( $candidatoIds, $metricas, $techo, $plazaId, $seasonId, $politica, $countResolvedFechasSinceFn );
    }

    /**
     * How many candidates for $plaza are BOTH padres AND viable — exactly
     * what Reglas\PrioridadDePadresRespetada needs to know, and the number
     * its Motivo reports to the captain.
     *
     * Always counts over paraPlaza()'s SEASON-SCOPED pool, never
     * paraSeccion()'s widened one — see class docblock, "paraSeccion() IS A
     * SEPARATE, WIDER ENTRY POINT".
     */
    public function contarPadresViables( array $plaza, BloqueoReemplazoPolicy $politica, callable $countResolvedFechasSinceFn ): int {
        $candidatos = $this->paraPlaza( $plaza, $politica, $countResolvedFechasSinceFn );

        return count( array_filter(
            $candidatos,
            static fn ( CandidatoEstado $c ): bool => $c->esPadre() && $c->viable()
        ) );
    }

    /**
     * Every candidate for $plaza drawn from ONE of the two screen sections
     * `Rest\PlazasController::listarCandidatos()` lets a captain choose
     * between — see `CandidatosSeccion`'s own docblock for the two values,
     * and class docblock, "paraSeccion() IS A SEPARATE, WIDER ENTRY POINT",
     * for why this is additive rather than a change to `paraPlaza()` itself.
     *
     *   - `CandidatosSeccion::LISTA_ESPERA` — every published `sp_player` on
     *     $listaEsperaTeamId (see `Plazas\ListaEsperaResolver`), i.e. the
     *     people who actually signed up to come in as a cambio.
     *   - `CandidatosSeccion::PADRON_COMPLETO` — every OTHER published
     *     `sp_player`, regardless of season registration — the whole
     *     padrón minus $listaEsperaTeamId. This is the WIDER population the
     *     process owner asked for: a previously-registered, already-rated
     *     player may legitimately come in even without being on this
     *     year's `sp_season` list, which `paraPlaza()`'s own population
     *     query would otherwise silently exclude.
     *
     * Both branches share the EXACT SAME viability check as `paraPlaza()`
     * (`evaluarCandidatos()`), including the plaza's own incumbent
     * exclusion and the ceiling-before-N+1 discipline — see that method's
     * own docblock. Neither branch is scoped by $seasonId the way
     * `playerIdsRegistradosEnTemporada()` is; `evaluarCandidatos()` still
     * passes $seasonId through to the per-candidate "occupying another
     * plaza THIS season" / "trunca closure THIS season" checks, which is
     * correct regardless of population: a padrón-wide candidate who holds no
     * `cambios_ocupacion` row this season simply clears both checks
     * trivially, exactly as a season-registered candidate with no conflict
     * does today.
     *
     * *** WHY THIS DOES NOT FEED contarPadresViables() ***
     * `Reglas\PrioridadDePadresRespetada` only ever calls `contarPadresViables()`,
     * which only ever calls `paraPlaza()` — never this method. Silently
     * widening the padres-priority rule's own pool to match this screen's
     * new sections was a real option this slice considered and deliberately
     * rejected: the rule is OFF by default today (see
     * Calendario\Settings::prioridadPadresActiva()), and nobody has made the
     * explicit business decision that "a padre anywhere in the whole padrón,
     * not just this year's registered roster" is the pool that rule should
     * reason about once it is turned on. Keeping `paraPlaza()` — and
     * therefore `contarPadresViables()` — untouched means enabling the rule
     * today behaves EXACTLY as it already does; widening it later is a
     * one-line change in `contarPadresViables()` once that decision is
     * actually made, not something this screen-presentation slice should
     * decide on the rule's behalf.
     *
     * @param array<string, mixed> $plaza As returned by
     *        PlazaRepository::findPlaza() — MUST carry `id`, `season_id`
     *        and `puntaje_techo`, same as paraPlaza().
     * @param string $seccion One of CandidatosSeccion::todas().
     * @param int $listaEsperaTeamId As resolved by Plazas\ListaEsperaResolver
     *        — the caller resolves this ONCE and hands it in, so a captain
     *        requesting either section never pays for resolving it twice.
     * @return CandidatoEstado[]
     * @throws \InvalidArgumentException When $seccion is not one of
     *         CandidatosSeccion::todas().
     */
    public function paraSeccion(
        array $plaza,
        string $seccion,
        int $listaEsperaTeamId,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): array {
        if ( ! CandidatosSeccion::esValida( $seccion ) ) {
            throw new \InvalidArgumentException(
                "CandidatosResolver::paraSeccion(): '{$seccion}' is not a valid seccion. "
                . 'Valid values are: ' . implode( ', ', CandidatosSeccion::todas() ) . '.'
            );
        }

        $plazaId  = (int) $plaza['id'];
        $seasonId = (int) $plaza['season_id'];
        $techo    = Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] );

        $vigente          = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $ocupanteActualId = null !== $vigente ? (int) $vigente['player_id'] : null;

        $playerIds = CandidatosSeccion::LISTA_ESPERA === $seccion
            ? $this->playerIdsListaDeEspera( $listaEsperaTeamId )
            : $this->playerIdsPadronCompleto( $listaEsperaTeamId );

        $candidatoIds = array_values( array_filter(
            $playerIds,
            static fn ( int $playerId ): bool => $playerId !== $ocupanteActualId
        ) );

        $metricas = $this->metricasReader->resolveMuchos( $candidatoIds );

        return $this->evaluarCandidatos( $candidatoIds, $metricas, $techo, $plazaId, $seasonId, $politica, $countResolvedFechasSinceFn );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Shared by `paraPlaza()` and `paraSeccion()` — the only place either
     * population is turned into viability verdicts, so the two screen
     * sections and the padres-priority rule can never silently disagree on
     * what "viable" means (see class docblock, "WHY THIS MUST BE THE ONLY
     * IMPLEMENTATION").
     *
     * *** PHASE 1: THE CEILING FILTER, FROM METRICS ALREADY IN HAND *** —
     * $metricas was already resolved by ONE batched
     * `JugadorMetricasReader::resolveMuchos()` call before this method runs;
     * reading `puntaje()`/`techo->allows()` off it costs no extra query.
     * Every candidate with no resolvable puntaje, or a puntaje over $techo,
     * is finalized HERE, before either of the two per-candidate queries in
     * phase 2 ever runs for them — see class docblock, "COST: THE CEILING
     * FILTER RUNS BEFORE THE N+1, NEVER AFTER".
     *
     * *** PHASE 2: THE N+1, ONLY FOR WHOEVER CLEARED THE CEILING *** — only
     * candidates whose puntaje survived phase 1 ever reach
     * `evaluarViabilidadDentroDelTecho()`, which is where
     * `PlazaRepository::listOcupacionesVigentesDeJugador()` /
     * `::listPlazasConCierreTruncadoDeJugador()` actually run.
     *
     * @param array<int, int> $candidatoIds
     * @param array<int, JugadorMetricas> $metricas Keyed by player_id, as
     *        returned by JugadorMetricasReader::resolveMuchos( $candidatoIds ) —
     *        MUST be total over $candidatoIds.
     * @return CandidatoEstado[] In the SAME order as $candidatoIds.
     */
    private function evaluarCandidatos(
        array $candidatoIds,
        array $metricas,
        Puntaje $techo,
        int $plazaId,
        int $seasonId,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): array {
        $dentroDelTecho = [];
        $resuelto       = [];

        foreach ( $candidatoIds as $playerId ) {
            $metrica = $metricas[ $playerId ];
            $puntaje = $metrica->puntaje();

            if ( null === $puntaje ) {
                $resuelto[ $playerId ] = new CandidatoEstado( $playerId, $metrica->esPadre(), null, false, 'puntaje_indeterminado' );
                continue;
            }

            if ( ! $techo->allows( $puntaje ) ) {
                $resuelto[ $playerId ] = new CandidatoEstado( $playerId, $metrica->esPadre(), $puntaje, false, 'puntaje_excede_techo' );
                continue;
            }

            $dentroDelTecho[] = $playerId;
        }

        foreach ( $dentroDelTecho as $playerId ) {
            $resuelto[ $playerId ] = $this->evaluarViabilidadDentroDelTecho(
                $playerId,
                $metricas[ $playerId ],
                $plazaId,
                $seasonId,
                $politica,
                $countResolvedFechasSinceFn
            );
        }

        return array_map( static fn ( int $playerId ): CandidatoEstado => $resuelto[ $playerId ], $candidatoIds );
    }

    /**
     * The 2-query viability check (phase 2 of evaluarCandidatos()) — ONLY
     * ever called for a candidate whose puntaje already cleared $techo, so
     * `$metricas->puntaje()` here is guaranteed non-null and within techo;
     * this method does not re-check either.
     */
    private function evaluarViabilidadDentroDelTecho(
        int $playerId,
        JugadorMetricas $metricas,
        int $plazaId,
        int $seasonId,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn
    ): CandidatoEstado {
        $puntaje = $metricas->puntaje();

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

    /**
     * Every PUBLISHED `sp_player` post on $teamId (the "lista de espera"
     * team — see Plazas\ListaEsperaResolver) — the EXACT same taxonomy-join
     * shape as playerIdsRegistradosEnTemporada(), but joined against
     * `sp_team` instead of `sp_season`: SportsPress assigns a player to a
     * team the same way it assigns a player to a season, via a taxonomy term
     * relationship, never a postmeta row or a separate bridge table.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** Same
     * reasoning as playerIdsRegistradosEnTemporada() — a failed read here
     * would render Rest\PlazasController's "Lista de Espera" section as an
     * empty list, indistinguishable from "nobody signed up" (see
     * Plazas\ListaEsperaResolver's own class docblock for the same concern
     * one level up, at team-id resolution rather than membership).
     *
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsListaDeEspera( int $teamId ): array {
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
                    AND tt.taxonomy = 'sp_team'
                    AND tt.term_id = %d
                  ORDER BY posts.ID ASC",
                $teamId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsListaDeEspera', [ 'team_id' => $teamId ] );

        return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
    }

    /**
     * Every PUBLISHED `sp_player` post EXCEPT those on $excludeTeamId (the
     * "lista de espera" team) — the whole padrón minus the real waiting
     * list, regardless of `sp_season` registration. This is the ONE query in
     * this class with NO season scoping at all: the process owner's explicit
     * ask (see this slice's task brief) is that a previously-registered,
     * already-rated player may come in even without being on THIS year's
     * `sp_season` list, which is exactly the restriction
     * playerIdsRegistradosEnTemporada() applies and this query deliberately
     * does not.
     *
     * The exclusion is a single `NOT IN` subquery against the same
     * taxonomy-join shape playerIdsListaDeEspera() uses directly, rather
     * than fetching that list in PHP and filtering here — one query, no
     * second round trip, and no risk of the two lists drifting if either
     * query's WHERE clause is ever edited without the other.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** Same
     * reasoning as playerIdsListaDeEspera() — a failed read here would
     * render "Padrón Completo" as an empty list instead of failing loud.
     *
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsPadronCompleto( int $excludeTeamId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT posts.ID AS id
                   FROM {$p}posts posts
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND posts.ID NOT IN (
                        SELECT tr.object_id
                          FROM {$p}term_relationships tr
                          INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                         WHERE tt.taxonomy = 'sp_team'
                           AND tt.term_id = %d
                    )
                  ORDER BY posts.ID ASC",
                $excludeTeamId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsPadronCompleto', [ 'exclude_team_id' => $excludeTeamId ] );

        return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
    }
}
