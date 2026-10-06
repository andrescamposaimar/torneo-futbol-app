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
 * *** AN UNRESOLVABLE PUNTAJE IS NOT A CANDIDATE AT ALL — IN buscarPaginado()
 * ONLY ***
 * `buscarPaginado()` — the only entry point the captain-facing candidatos
 * screen actually calls (`Rest\PlazasController::listarCandidatos()`) —
 * excludes a candidate with no resolvable puntaje from the POPULATION
 * itself, before `$total` is computed and before pagination slices a page
 * (see that method's own docblock for exactly where this runs). The
 * business reasoning: without a puntaje there is nothing to compare against
 * the plaza's techo, so such a player is not a candidate at all, in EITHER
 * screen section ("Lista de Espera" or "Padrón Completo") — not merely a
 * non-viable one that `?incluir_no_viables=1` can still surface. This
 * exclusion is unconditional — it does not depend on `?incluir_no_viables`,
 * which only ever controls whether a candidate who IS in the population but
 * fails some OTHER check (techo, occupying another plaza, blocked by a
 * trunca closure) is still shown.
 *
 * *** THE CEILING IS A POPULATION FILTER, NOT A PER-PAGE VERDICT — IN
 * buscarPaginado() ONLY *** A candidate whose puntaje exceeds the plaza's
 * techo is, for the exact same "not a candidate at all in THIS method"
 * reason as the unrated exclusion above, ALSO excluded from the POPULATION
 * itself — before `$total` is computed and before `array_slice()` takes a
 * page (see `buscarPaginado()`'s own docblock for exactly where). The
 * product reading: the app's puntaje chips already render every value above
 * the plaza's techo disabled and greyed
 * (`cambios_solicitar_screen.dart`'s own docblock, "THE PUNTAJE CHIPS TEACH
 * THE CEILING, THEY NEVER HIDE IT") — an over-ceiling candidate can never be
 * selected, so the list itself should only ever hold candidates who could
 * be.
 *
 * *** THE PRODUCTION INCIDENT THIS FIXES *** Before this change, the techo
 * was enforced only AFTER a page was already sliced
 * (`partitionPorTecho()`, called from within this method on the PAGE's own
 * ids — see "WHY PAGINATION HAPPENS HERE, BEFORE THE N+1, NOT AFTER" below).
 * `buscarPaginado()`'s own sort puts the HIGHEST puntajes FIRST
 * (`puntaje DESC, …` — "THE SORT KEY" below), so a population with enough
 * over-ceiling candidates to fill an entire page on their own made this
 * method return that page EMPTY while `$total` still reported the whole
 * (over-ceiling candidates included) population — a captain who had not yet
 * narrowed `$puntajesFiltro` via a chip would see "no candidates" on a plaza
 * the server itself said had hundreds. Confirmed in production at a 4.5
 * techo: 31 published players rated 5.0 alone filled 1.55 pages at
 * `per_page = 20`, so page 1 was entirely players the old post-slice filter
 * then stripped to nothing. Moving the check here is free: it reads the SAME
 * batched `$metricas` the unrated-candidate filter above already reads — no
 * extra query.
 *
 * *** `$total` IS THEREFORE VERY SLIGHTLY OPTIMISTIC — BOUNDED, AND BY DESIGN
 * *** Both population-level exclusions above (unrated, over-ceiling) run
 * BEFORE phase 2's per-candidate viability queries
 * (`evaluarViabilidadDentroDelTecho()` — occupying another plaza, blocked by
 * a trunca closure elsewhere) ever run, and phase 2 only ever runs for the
 * page actually requested, never the whole filtered population (see "WHY
 * PAGINATION HAPPENS HERE, BEFORE THE N+1, NOT AFTER" below — computing a
 * population-wide phase-2 verdict would mean paying the EXACT N+1 cost this
 * method exists to avoid, for every page, not just the one requested). So
 * `$total` counts "candidates within the ceiling with a resolvable puntaje",
 * not "candidates who would also clear phase 2" — a small overcount whenever
 * phase 2 would have rejected one of them. This is a materially SMALLER and
 * more even-handed overcount than the one this change removes: a phase-2
 * non-viable (a captain's own teammate occupying two plazas, a trunca
 * closure elsewhere) is a small minority scattered across the WHOLE
 * population, never concentrated at the top of the sort the way over-ceiling
 * candidates are — it can shrink a page somewhat, but it can never again
 * empty an entire page the way the ceiling did. A caller that needs the
 * population-wide VIABLE count (phase 1 AND phase 2) has
 * `contarPadresViables()`, which pays that cost deliberately over
 * `paraPlaza()`'s own unpaginated pool.
 *
 * *** `?incluir_no_viables=1` NO LONGER SURFACES AN OVER-CEILING CANDIDATE —
 * IT NEVER CAN AGAIN *** Before this change, `?incluir_no_viables=1` could
 * show a `motivo: 'puntaje_excede_techo'` row on the requested page (see
 * `Rest\PlazasController::listarCandidatos()`'s own docblock). That motivo is
 * now UNREACHABLE from this method: an over-ceiling candidate is gone from
 * the population before `partitionPorTecho()` (below) ever runs on the
 * page's own ids, so there is nothing left for `?incluir_no_viables=1` to
 * opt back into for THIS specific reason. It still does something coherent —
 * it still surfaces a candidate the PAGE's own phase 2 rejected
 * (`ocupa_otra_plaza_vigente` / `bloqueado_por_cierre_truncado`), exactly as
 * before — see `Rest\PlazasController::listarCandidatos()`'s own updated
 * docblock for the committee-facing wording of this.
 *
 * `paraPlaza()` / `paraSeccion()` are DELIBERATELY NOT changed by either
 * population-level exclusion above — they still report an unrated candidate
 * as a non-viable `CandidatoEstado` with `motivoNoViable = 'puntaje_indeterminado'`,
 * and an over-ceiling candidate with `motivoNoViable = 'puntaje_excede_techo'`
 * (both via `partitionPorTecho()` below), exactly as before this change.
 * Both still feed `contarPadresViables()` (`Reglas\PrioridadDePadresRespetada`'s
 * own pool), where excluding either from the population would make no
 * observable difference — neither was ever viable, so neither was ever
 * counted there either — and `paraPlaza()` / `paraSeccion()` have their own
 * documented callers and invariants (see "WHY THIS MUST BE THE ONLY
 * IMPLEMENTATION" below) this change has no reason to touch.
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

    /**
     * Max player ids per `nombresPorJugador()` query — same chunking
     * discipline, and the same value, as
     * `JugadorMetricasReader::ID_CHUNK_SIZE` (see that class's own class
     * docblock, "fetchLatestMetaValuesFor() CHUNKS $playerIds, NEVER ONE
     * UNBOUNDED `IN (...)`", for the full reasoning this reuses verbatim).
     */
    private const ID_CHUNK_SIZE = 200;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private EventLog $eventLog;
    private JugadorMetricasReader $metricasReader;
    private BloqueoReemplazoEvaluator $bloqueoEvaluator;
    private PosicionResolver $posicionResolver;

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
        ?BloqueoReemplazoEvaluator $bloqueoEvaluator = null,
        ?PosicionResolver $posicionResolver = null
    ) {
        $this->wpdb             = $wpdb;
        $this->plazaRepository  = $plazaRepository;
        $this->eventLog         = $eventLog;
        $this->metricasReader   = $metricasReader ?? new JugadorMetricasReader( $wpdb, $eventLog );
        $this->bloqueoEvaluator = $bloqueoEvaluator ?? new BloqueoReemplazoEvaluator();
        $this->posicionResolver = $posicionResolver ?? new PosicionResolver( $eventLog );
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

    /**
     * Paginated variant of `paraPlaza()`/`paraSeccion()`, built for
     * `Rest\PlazasController::listarCandidatos()`'s own `?page=`/`?per_page=`
     * params — see that method's own docblock. NEVER used by
     * `contarPadresViables()` / `Reglas\PrioridadDePadresRespetada`, which
     * need the FULL, unpaginated pool (see class docblock, "WHY THIS MUST BE
     * THE ONLY IMPLEMENTATION" — that invariant is unaffected: this method is
     * an ADDITIONAL entry point, not a replacement for the other two).
     *
     * *** WHY PAGINATION HAPPENS HERE, BEFORE THE N+1, NOT AFTER ***
     * Slicing an already-fully-evaluated `CandidatoEstado[]` would still pay
     * the WHOLE population's N+1 cost this method exists to avoid (see class
     * docblock, "COST: THE CEILING FILTER RUNS BEFORE THE N+1, NEVER AFTER").
     * This method reorders the work instead:
     *   1. Resolve the population's ids (one query) and their metrics (one
     *      batched query) — same cost as `paraPlaza()`/`paraSeccion()`,
     *      regardless of how many pages exist.
     *   2. Exclude every candidate with no resolvable puntaje from the
     *      POPULATION itself — see class docblock, "AN UNRESOLVABLE PUNTAJE
     *      IS NOT A CANDIDATE AT ALL — IN buscarPaginado() ONLY". This runs
     *      FIRST, from the same batched metrics read, before either
     *      `$puntajesFiltro` or `$search` narrow the population further, and
     *      well before `$total` is computed — an unrated player must never
     *      count towards how many pages exist.
     *   2b. Exclude every candidate whose puntaje exceeds the plaza's techo
     *      from the POPULATION itself too — see class docblock, "THE CEILING
     *      IS A POPULATION FILTER, NOT A PER-PAGE VERDICT". Runs immediately
     *      after step 2 (so every puntaje here is guaranteed resolvable),
     *      from the SAME batched metrics read, for the SAME reason: step 3
     *      below's sort puts the highest puntajes first, so without this
     *      step an over-ceiling candidate could fill an entire page and never
     *      even reach `$puntajesFiltro`/pagination — see class docblock, "THE
     *      PRODUCTION INCIDENT THIS FIXES", for the exact failure this step
     *      exists to prevent.
     *   3. Apply, in PHP, every remaining CHEAP filter available from that
     *      batched metrics read alone: the caller's own `$puntajesFiltro`
     *      (exact puntaje match — e.g. the app's puntaje chips), narrowing
     *      the POPULATION itself (a non-matching candidate is excluded
     *      entirely, never just marked non-viable — an unrated OR
     *      over-ceiling candidate is already gone by steps 2/2b, so this step
     *      never has one left to consider). `$search`, when given, is pushed
     *      into the POPULATION query itself (see
     *      `playerIdsRegistradosEnTemporada()` et al.'s own docblocks for why
     *      `post_title LIKE`, not a second `get_the_title()` pass, is what
     *      keeps this consistent with how `nombreJugador()` resolves a name).
     *   4. Slice EXACTLY the requested page out of that filtered,
     *      player_id-ascending list — see "WHY player_id, NEVER puntaje, IS
     *      THE SORT KEY" below.
     *   5. Run the techo partition AND the per-candidate viability queries
     *      (phase 2, `evaluarViabilidadDentroDelTecho()`) ONLY for the
     *      page's own ids — never the whole filtered population. Before this
     *      method existed, that N+1 ran for every candidate who cleared the
     *      techo (`evaluarCandidatos()`, still used by `paraPlaza()` /
     *      `paraSeccion()`); here it runs for at most `$perPage` of them.
     *      Because steps 2/2b already removed every unrated AND every
     *      over-ceiling candidate, NEITHER the `puntaje_indeterminado` NOR the
     *      `puntaje_excede_techo` branch of `partitionPorTecho()` below is
     *      ever reached from this method anymore — see that method's own
     *      docblock. `partitionPorTecho()` still runs here purely as a
     *      defensive no-op partition (every id it sees is already within
     *      techo and rated) rather than being removed from this call site —
     *      keeping ONE shared implementation of "partition by techo" is the
     *      same discipline as the rest of this class (see "WHY THIS MUST BE
     *      THE ONLY IMPLEMENTATION").
     *
     * *** THE SORT KEY: puntaje DESC, THEN nombre ASC, THEN player_id ASC ***
     * The captain's screen ("Pedir cambio") must show the highest-rated,
     * then alphabetically-first candidates first — `player_id ASC` alone (an
     * earlier version of this method's sort key, and this docblock) was
     * never meant to be the PRESENTATION order, only a total order safe for
     * pagination; sorting by puntaje was deliberately rejected at the time
     * for exactly the reason below, which remains true in isolation:
     *
     *   puntaje is not a SQL column at all (see class docblock, "WHERE THE
     *   CANDIDATE POOL COMES FROM" and `JugadorMetricasReader`'s own class
     *   docblock) and only has 9 possible discrete values (`Puntaje`'s own
     *   class docblock) — ties are the NORM, not the exception, across a
     *   population that can run into the hundreds. Ordering by puntaje
     *   ALONE would be unstable across two consecutive page requests
     *   (nothing guarantees two ties resolve in the same relative order
     *   twice), silently duplicating some candidates across pages and
     *   skipping others entirely.
     *
     * That objection is about puntaje ALONE, not about puntaje as the FIRST
     * key of a longer, total tuple. Pagination only needs *a* total order —
     * any number of unstable keys at the front are made stable the instant a
     * UNIQUE key follows them in the tuple, because no two rows can ever tie
     * on every key at once. `player_id` is unique, so appending it LAST
     * (`puntaje DESC, nombre ASC, player_id ASC`) is exactly as total and
     * exactly as safe for pagination as `player_id ASC` alone ever was — see
     * `ordenarCandidatos()`'s own docblock for the comparator, and
     * `CandidatosResolverTest::test_buscar_paginado_pages_through_the_whole_list_exactly_once_with_no_duplicates()`
     * for the test that would catch a non-total key (it seeds a population
     * spanning several puntajes and names specifically to exercise this).
     * `nombre` sits between the two because it is the natural secondary
     * reading of "ordered by puntaje" a captain expects ("then
     * alphabetically"), not because it adds anything to totality that
     * `player_id` alone would not already provide.
     *
     * @param array<string, mixed> $plaza As returned by
     *        PlazaRepository::findPlaza() — same contract as paraPlaza().
     * @param string|null $seccion `null` keeps paraPlaza()'s season-registered
     *        population; one of CandidatosSeccion::todas() selects
     *        paraSeccion()'s wider population — same two choices
     *        Rest\PlazasController::listarCandidatos() already exposes via
     *        `?seccion=`.
     * @param int|null $listaEsperaTeamId Required (and resolved ONCE by the
     *        caller, see paraSeccion()'s own docblock) when $seccion is not
     *        null; ignored otherwise.
     * @param int $page 1-based. Values below 1 are clamped up to 1 — an
     *        out-of-range page (beyond the last one) is never an error, it
     *        simply yields an empty `candidatos` via PHP's own
     *        `array_slice()` semantics (an offset past the end is `[]`).
     * @param int $perPage Clamped to >= 1 by this method; an upper bound is
     *        the CALLER's responsibility (Rest\PlazasController enforces the
     *        hard cap a request may ask for).
     * @param string $search Matched against `post_title`, case-insensitively
     *        — see `playerIdsRegistradosEnTemporada()` et al.
     * @param array<int, float> $puntajesFiltro Decimal puntaje values to
     *        keep (exact match) — empty means no filter beyond the plaza's
     *        own techo. A candidate whose puntaje is unresolvable, or exceeds
     *        the plaza's techo, is already excluded from the population
     *        unconditionally (see class docblock, "AN UNRESOLVABLE PUNTAJE IS
     *        NOT A CANDIDATE AT ALL — IN buscarPaginado() ONLY" and "THE
     *        CEILING IS A POPULATION FILTER, NOT A PER-PAGE VERDICT"), so
     *        this filter never has either left to consider regardless of
     *        whether $puntajesFiltro is empty.
     * @return array{candidatos: CandidatoEstado[], total: int} `total` is the
     *         size of the population AFTER the unrated/over-ceiling
     *         exclusions AND `$puntajesFiltro`/`$search`, but BEFORE
     *         pagination — i.e. "how many pages exist", not "how many of THIS
     *         page are viable" (phase 2 viability — occupying another plaza,
     *         a trunca closure elsewhere — is only ever resolved for the page
     *         actually requested, see point 5 above) and NOT "how many of the
     *         population would also clear phase 2" — see class docblock,
     *         "`$total` IS THEREFORE VERY SLIGHTLY OPTIMISTIC — BOUNDED, AND
     *         BY DESIGN", for exactly what that means and why the bound is
     *         small. A caller that needs the population-wide VIABLE count
     *         (phase 1 AND phase 2) has `contarPadresViables()`.
     * @throws \InvalidArgumentException When $seccion is given but is not one
     *         of CandidatosSeccion::todas().
     */
    public function buscarPaginado(
        array $plaza,
        ?string $seccion,
        ?int $listaEsperaTeamId,
        BloqueoReemplazoPolicy $politica,
        callable $countResolvedFechasSinceFn,
        int $page,
        int $perPage,
        string $search = '',
        array $puntajesFiltro = []
    ): array {
        if ( null !== $seccion && ! CandidatosSeccion::esValida( $seccion ) ) {
            throw new \InvalidArgumentException(
                "CandidatosResolver::buscarPaginado(): '{$seccion}' is not a valid seccion. "
                . 'Valid values are: ' . implode( ', ', CandidatosSeccion::todas() ) . '.'
            );
        }

        $plazaId  = (int) $plaza['id'];
        $seasonId = (int) $plaza['season_id'];
        $techo    = Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] );

        $vigente          = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $ocupanteActualId = null !== $vigente ? (int) $vigente['player_id'] : null;

        $playerIds = match ( true ) {
            null === $seccion                          => $this->playerIdsRegistradosEnTemporada( $seasonId, $search ),
            CandidatosSeccion::LISTA_ESPERA === $seccion => $this->playerIdsListaDeEspera( (int) $listaEsperaTeamId, $search ),
            default                                      => $this->playerIdsPadronCompleto( (int) $listaEsperaTeamId, $search ),
        };

        $candidatoIds = array_values( array_filter(
            $playerIds,
            static fn ( int $playerId ): bool => $playerId !== $ocupanteActualId
        ) );

        $metricas = $this->metricasReader->resolveMuchos( $candidatoIds );

        // A candidate with no resolvable puntaje is not a candidate at all
        // in this method — excluded from the POPULATION itself, not merely
        // marked non-viable — see class docblock, "AN UNRESOLVABLE PUNTAJE
        // IS NOT A CANDIDATE AT ALL — IN buscarPaginado() ONLY". This runs
        // FIRST, from the metrics already batched above (no extra query),
        // and unconditionally (unlike $puntajesFiltro below, this does not
        // depend on any caller-supplied filter) — before $puntajesFiltro,
        // before $total is computed, and before array_slice() takes a page.
        $candidatoIds = array_values( array_filter(
            $candidatoIds,
            static fn ( int $playerId ): bool => null !== $metricas[ $playerId ]->puntaje()
        ) );

        // A candidate whose puntaje exceeds the plaza's techo is likewise not
        // a candidate at all in this method — excluded from the POPULATION
        // itself, not merely a per-page non-viable verdict — see class
        // docblock, "THE CEILING IS A POPULATION FILTER, NOT A PER-PAGE
        // VERDICT". Runs immediately after the unrated-candidate filter above
        // (so every puntaje() here is guaranteed non-null — Puntaje::allows()
        // takes a Puntaje, never null), from the SAME metrics already batched
        // above (no extra query), and unconditionally — before
        // $puntajesFiltro, before $total is computed, and before
        // array_slice() takes a page. Without this, buscarPaginado()'s own
        // `puntaje DESC` sort (see "THE SORT KEY" below) could fill an entire
        // page with over-ceiling candidates and return it EMPTY after the
        // per-page techo partition below stripped every one of them — see
        // class docblock, "THE PRODUCTION INCIDENT THIS FIXES", for exactly
        // that failure.
        $candidatoIds = array_values( array_filter(
            $candidatoIds,
            static fn ( int $playerId ): bool => $techo->allows( $metricas[ $playerId ]->puntaje() )
        ) );

        // A goalkeeper is likewise excluded from the POPULATION itself when
        // THIS plaza is NOT the goalkeeper's plaza — the hiding-from-the-list
        // half of Dictamen\Reglas\ArqueroNoOcupaPlazaDeCampo's rule (see that
        // class's own docblock for why this is a convenience, never a
        // substitute for the dictamen's own enforcement). "Is THIS plaza the
        // goalkeeper's plaza" is answered by the plaza's own STORED
        // `es_arco` flag (0.1.13 — see Migrations\InitialSchema's own class
        // docblock), never re-derived from the titular's CURRENT
        // `sp_position`, so resolving it costs no query at all, let alone a
        // batched one. Only the CANDIDATES' own positions still need a live
        // lookup — ONE batched `PosicionResolver::resolverParaIds()` call
        // over the whole remaining population — never one call per
        // candidate, the same chunking discipline
        // `JugadorMetricasReader::fetchLatestMetaValuesFor()` already
        // applies to this exact population. That lookup is also SKIPPED
        // entirely when the plaza IS the goalkeeper's own (no candidate
        // needs excluding), so a goal plaza's own candidate list pays no
        // extra cost either. Runs BEFORE $total is computed and BEFORE
        // array_slice() takes a page: a filter that ran AFTER pagination
        // would produce short or empty pages the app reads as "no candidates
        // available" — the exact bug the ceiling filter above was fixed for
        // today.
        // *** A FAILED RESOLUTION MUST ABORT THE WHOLE REQUEST, NEVER ADMIT A
        // GOALKEEPER INTO A FIELD PLAZA'S LIST (0.1.14) *** PosicionResolver::resolverParaIds()
        // now throws rather than silently returning SIN_POSICION for every
        // candidate — see that class's own docblock. This method does NOT
        // catch it: the old silent-degradation behavior would have made
        // esPosicionDeArquero() false for every candidate regardless of their
        // REAL position, which is exactly "could not resolve" misread as
        // "confirmed, not a goalkeeper" — a read failure silently becoming a
        // permission, the one thing this whole fix exists to prevent. Letting
        // this propagate reaches Rest\PlazasController::listarCandidatos()'s
        // own `catch (\Throwable)`, which logs `rest.plazas_candidatos_fallida`
        // and answers a loud 500 — never a 200 with a goalkeeper quietly
        // included.
        $plazaEsDelArquero = (bool) ( $plaza['es_arco'] ?? false );

        if ( ! $plazaEsDelArquero ) {
            $posicionesArquero = $this->posicionResolver->resolverParaIds( $candidatoIds );

            $candidatoIds = array_values( array_filter(
                $candidatoIds,
                static fn ( int $playerId ): bool => ! PosicionResolver::esPosicionDeArquero( $posicionesArquero[ $playerId ] )
            ) );
        }

        // $puntajesFiltro narrows the POPULATION itself one step further —
        // the SAME kind of population narrowing as the unrated/over-ceiling
        // exclusions just above, only caller-supplied rather than
        // unconditional — so it must run BEFORE $total is computed, exactly
        // like $search already did at the SQL level.
        $puntajesFiltroHalfPoints = array_map(
            static fn ( float $p ): int => (int) round( $p * 2 ),
            $puntajesFiltro
        );

        if ( ! empty( $puntajesFiltroHalfPoints ) ) {
            $candidatoIds = array_values( array_filter(
                $candidatoIds,
                static function ( int $playerId ) use ( $metricas, $puntajesFiltroHalfPoints ): bool {
                    $puntaje = $metricas[ $playerId ]->puntaje();

                    return null !== $puntaje && in_array( $puntaje->halfPoints(), $puntajesFiltroHalfPoints, true );
                }
            ) );
        }

        // Sorted over the FULL filtered population, BEFORE $total is counted
        // and BEFORE array_slice() takes the page — see this method's own
        // docblock, "WHY PAGINATION HAPPENS HERE, BEFORE THE N+1, NOT AFTER"
        // and "THE SORT KEY", for why the order must be global rather than
        // per page. $nombresPorJugador is ONE batched (chunked) query over
        // exactly this filtered population — never one query per candidate,
        // same discipline as the $metricas read above (see
        // nombresPorJugador()'s own docblock).
        $nombresPorJugador = $this->nombresPorJugador( $candidatoIds );
        $candidatoIds      = $this->ordenarCandidatos( $candidatoIds, $metricas, $nombresPorJugador );

        $total = count( $candidatoIds );

        $page    = max( 1, $page );
        $perPage = max( 1, $perPage );
        $pageIds = array_slice( $candidatoIds, ( $page - 1 ) * $perPage, $perPage );

        if ( empty( $pageIds ) ) {
            return [ 'candidatos' => [], 'total' => $total ];
        }

        $pageMetricas = array_intersect_key( $metricas, array_flip( $pageIds ) );

        [ 'resuelto' => $resuelto, 'dentroDelTecho' => $dentroDelTecho ] =
            $this->partitionPorTecho( $pageIds, $pageMetricas, $techo );

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

        $candidatos = array_map( static fn ( int $playerId ): CandidatoEstado => $resuelto[ $playerId ], $pageIds );

        return [ 'candidatos' => $candidatos, 'total' => $total ];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Shared by `evaluarCandidatos()` and `buscarPaginado()` — the only place
     * either partitions candidates by the techo ceiling, so the two code
     * paths can never silently compute that partition differently (see class
     * docblock, "WHY THIS MUST BE THE ONLY IMPLEMENTATION", same discipline
     * applied one level down).
     *
     * *** THE CEILING FILTER, FROM METRICS ALREADY IN HAND *** — $metricas
     * was already resolved by ONE batched
     * `JugadorMetricasReader::resolveMuchos()` call before this method runs;
     * reading `puntaje()`/`techo->allows()` off it costs no extra query.
     * Every candidate with no resolvable puntaje, or a puntaje over $techo,
     * is finalized HERE, before either of the two per-candidate queries
     * `evaluarViabilidadDentroDelTecho()` runs ever runs for them — see class
     * docblock, "COST: THE CEILING FILTER RUNS BEFORE THE N+1, NEVER AFTER".
     *
     * *** BOTH BRANCHES ARE STILL LIVE — JUST NOT FROM buscarPaginado()
     * ANYMORE *** `buscarPaginado()` now excludes every unrated AND every
     * over-ceiling candidate from its own population BEFORE this method ever
     * runs (see class docblock, "AN UNRESOLVABLE PUNTAJE IS NOT A CANDIDATE AT
     * ALL — IN buscarPaginado() ONLY" and "THE CEILING IS A POPULATION
     * FILTER, NOT A PER-PAGE VERDICT"), so `$candidatoIds` arriving from THAT
     * caller never contains either anymore — both the `puntaje_indeterminado`
     * AND the `puntaje_excede_techo` branches below are unreachable on that
     * path (this method still runs there, as a no-op partition over an
     * already-filtered `$candidatoIds` — see `buscarPaginado()`'s own
     * docblock, step 5, for why it stays rather than being removed from that
     * call site). NEITHER branch is dead code: `evaluarCandidatos()` below is
     * also called by `paraPlaza()` and `paraSeccion()`, which stay unfiltered
     * by design (same docblocks) and still route an unrated OR over-ceiling
     * candidate through these exact branches —
     * `CandidatosResolverTest`'s `paraPlaza()`-level tests
     * (`test_a_candidate_with_no_resolvable_puntaje_is_not_viable()`,
     * `test_a_candidate_with_stored_puntaje_zero_is_indeterminado_not_an_exception()`,
     * `test_ceiling_filter_runs_before_the_per_candidate_viability_queries()`)
     * keep covering both. Do not remove either branch on the assumption that
     * nothing reaches it.
     *
     * @param array<int, int> $candidatoIds
     * @param array<int, JugadorMetricas> $metricas Keyed by player_id — MUST
     *        be total over $candidatoIds (a subset of the full batched read
     *        is fine, e.g. `buscarPaginado()`'s own page-only slice).
     * @return array{resuelto: array<int, CandidatoEstado>, dentroDelTecho: array<int, int>}
     *         `resuelto` holds a FINAL CandidatoEstado for every candidate
     *         whose puntaje is unresolvable or exceeds $techo — nothing
     *         further is ever computed for them. `dentroDelTecho` lists, in
     *         the SAME relative order as $candidatoIds, every id that still
     *         needs the per-candidate viability queries.
     */
    private function partitionPorTecho( array $candidatoIds, array $metricas, Puntaje $techo ): array {
        $resuelto       = [];
        $dentroDelTecho = [];

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

        return [ 'resuelto' => $resuelto, 'dentroDelTecho' => $dentroDelTecho ];
    }

    /**
     * Shared by `paraPlaza()` and `paraSeccion()` — the only place either
     * population is turned into viability verdicts, so the two screen
     * sections and the padres-priority rule can never silently disagree on
     * what "viable" means (see class docblock, "WHY THIS MUST BE THE ONLY
     * IMPLEMENTATION").
     *
     * *** PHASE 1: THE CEILING FILTER *** — delegated to partitionPorTecho(),
     * from metrics already batched by the caller; no extra query. See that
     * method's own docblock.
     *
     * *** PHASE 2: THE N+1, ONLY FOR WHOEVER CLEARED THE CEILING *** — only
     * candidates whose puntaje survived phase 1 ever reach
     * `evaluarViabilidadDentroDelTecho()`, which is where
     * `PlazaRepository::listOcupacionesVigentesDeJugador()` /
     * `::listPlazasConCierreTruncadoDeJugador()` actually run. Unlike
     * `buscarPaginado()`, this runs for EVERY candidate who clears the
     * techo — correct here, since `paraPlaza()`/`paraSeccion()` must report
     * the FULL, unpaginated pool (see class docblock).
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
        [ 'resuelto' => $resuelto, 'dentroDelTecho' => $dentroDelTecho ] =
            $this->partitionPorTecho( $candidatoIds, $metricas, $techo );

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
     * Sorts $candidatoIds into `buscarPaginado()`'s presentation order —
     * `puntaje DESC, nombre ASC, player_id ASC` — see that method's own
     * docblock, "THE SORT KEY", for why this tuple is both the order a
     * captain expects and a TOTAL order safe for pagination.
     *
     * *** UNRATED CANDIDATES NO LONGER REACH THIS METHOD AT ALL — THERE IS NO
     * "UNRATED SORTS LAST" CASE ANYMORE *** An earlier version of this method
     * sorted an unrated candidate (`puntaje === null`) to the bottom with a
     * dedicated `-1` sentinel. That branch is GONE, not merely untested: an
     * unrated candidate is no longer a candidate at all in `buscarPaginado()`
     * — its ONLY caller — which now excludes one from the population before
     * `ordenarCandidatos()` ever runs (see class docblock, "AN UNRESOLVABLE
     * PUNTAJE IS NOT A CANDIDATE AT ALL — IN buscarPaginado() ONLY"). Every
     * `$playerId` this method receives is therefore GUARANTEED to have a
     * resolvable puntaje; `puntajeHalfPointsParaOrden()` below enforces that
     * guarantee by throwing rather than silently reintroducing a sentinel if
     * it is ever violated — the same "fail loud, never silently degrade"
     * discipline this class already applies to a failed read (see class
     * docblock, "WHY THIS MUST BE THE ONLY IMPLEMENTATION" and
     * `playerIdsRegistradosEnTemporada()`'s own docblock). See
     * `CandidatosResolverTest::test_buscar_paginado_orders_candidates_with_no_puntaje_last()`,
     * which now asserts the unrated candidate is ABSENT, not sorted last.
     *
     * @param array<int, int> $candidatoIds MUST already exclude every
     *        candidate with no resolvable puntaje — see
     *        `puntajeHalfPointsParaOrden()`'s own docblock for what happens
     *        if that precondition is violated.
     * @param array<int, JugadorMetricas> $metricas Keyed by player_id, as
     *        resolved by `resolveMuchos()` — MUST be total over
     *        $candidatoIds.
     * @param array<int, string> $nombresPorJugador As returned by
     *        `nombresPorJugador( $candidatoIds )` — need not carry a row for
     *        EVERY id (a player with no title at all is simply absent, same
     *        contract as that method's own return type); `nombreParaOrden()`
     *        supplies the same fallback `Rest\PlazasController::nombreJugador()`
     *        uses for a missing/blank title, so the sort order never
     *        disagrees with what the screen renders for that candidate.
     * @return array<int, int> $candidatoIds, re-ordered.
     * @throws \LogicException Via `puntajeHalfPointsParaOrden()`, when
     *         $candidatoIds carries a player_id whose puntaje is not
     *         resolvable — see that method's own docblock.
     */
    private function ordenarCandidatos( array $candidatoIds, array $metricas, array $nombresPorJugador ): array {
        usort(
            $candidatoIds,
            function ( int $a, int $b ) use ( $metricas, $nombresPorJugador ): int {
                $rangoA = $this->puntajeHalfPointsParaOrden( $a, $metricas );
                $rangoB = $this->puntajeHalfPointsParaOrden( $b, $metricas );

                if ( $rangoA !== $rangoB ) {
                    return $rangoB <=> $rangoA; // descending puntaje
                }

                $nombreCmp = self::claveOrdenNombre( $this->nombreParaOrden( $a, $nombresPorJugador ) )
                    <=> self::claveOrdenNombre( $this->nombreParaOrden( $b, $nombresPorJugador ) );

                if ( 0 !== $nombreCmp ) {
                    return $nombreCmp;
                }

                return $a <=> $b; // the TOTAL-order tiebreaker — see docblock above
            }
        );

        return $candidatoIds;
    }

    /**
     * $metricas[ $playerId ]->puntaje()->halfPoints() — guarded by an
     * explicit precondition check rather than called inline, so a violation
     * fails loud with a clear message instead of a bare "call to a member
     * function halfPoints() on null" fatal error. See `ordenarCandidatos()`'s
     * own docblock, "UNRATED CANDIDATES NO LONGER REACH THIS METHOD AT ALL",
     * for why this should never actually throw in production:
     * `buscarPaginado()` — `ordenarCandidatos()`'s only caller — already
     * excludes every unrated candidate from `$candidatoIds` before sorting.
     *
     * @throws \LogicException When $playerId's puntaje is not resolvable —
     *         this means the precondition above was violated upstream, not
     *         that this method has anything sensible to sort that candidate
     *         by.
     */
    private function puntajeHalfPointsParaOrden( int $playerId, array $metricas ): int {
        $puntaje = $metricas[ $playerId ]->puntaje();

        if ( null === $puntaje ) {
            throw new \LogicException(
                "CandidatosResolver::ordenarCandidatos(): player_id {$playerId} has no resolvable puntaje. "
                . "buscarPaginado(), this method's only caller, MUST exclude every unrated candidate from "
                . 'the population before sorting — see ordenarCandidatos()\'s own docblock. Reaching this '
                . 'means that precondition was violated upstream.'
            );
        }

        return $puntaje->halfPoints();
    }

    /**
     * The exact same display-name fallback `Rest\PlazasController::nombreJugador()`
     * applies ("Jugador #<id>" for a blank/missing title) — reused here so a
     * candidate's SORT position never disagrees with the NAME the screen
     * actually renders for them. Deliberately NOT a call to that method
     * directly: this class has no dependency on `Rest\PlazasController` (nor
     * on WordPress's `get_the_title()`, which this class avoids everywhere
     * else — see class docblock, "WHERE THE CANDIDATE POOL COMES FROM")  —
     * the fallback string is simply duplicated, the same way
     * `escapeLikeTerm()`'s `ESCAPE '!'` choice is documented once and relied
     * on by three call sites rather than factored into a shared constant
     * neither side is coupled to.
     */
    private function nombreParaOrden( int $playerId, array $nombresPorJugador ): string {
        $titulo = trim( (string) ( $nombresPorJugador[ $playerId ] ?? '' ) );

        return '' !== $titulo ? $titulo : 'Jugador #' . $playerId;
    }

    /**
     * Folds $nombre into a comparison key that sorts Spanish names the way a
     * Spanish reader expects: lower-cased, with the accented vowels and `ñ`
     * mapped to their unaccented base letter, so `Pérez` sorts alongside
     * `Perez`/`Petrov` rather than after every unaccented name (a naive
     * byte-wise `<=>` on raw UTF-8 puts every accented letter after `z`,
     * because its continuation bytes are numerically > ASCII `z`).
     *
     * *** WHY A HAND-ROLLED FOLD, NOT `Collator`/`iconv()` ***
     * PHP's `Collator` (ext-intl) gives the most linguistically correct
     * answer, but is an OPTIONAL extension this plugin does not otherwise
     * require (see composer.json) — depending on it here would make this
     * endpoint's sort order silently diverge between a host that has it and
     * one that does not, which is worse than a slightly cruder fold that
     * behaves IDENTICALLY everywhere. `iconv( 'UTF-8', 'ASCII//TRANSLIT',
     * … )` has the same problem one level down: its transliteration table is
     * supplied by the SYSTEM's iconv implementation (glibc vs. macOS/BSD vs.
     * musl), which is not guaranteed consistent between this suite's dev/CI
     * machine and the production host. The explicit map below has exactly
     * one behaviour, everywhere, forever — the same reasoning
     * `escapeLikeTerm()` already applies to `ESCAPE '!'` one concept over
     * (an engine-agnostic choice over a technically-correct but
     * environment-dependent one). `mb_strtolower( …, 'UTF-8' )` IS used (it
     * is bundled with PHP's `mbstring`, which WordPress itself requires —
     * https://wordpress.org/about/requirements/ — so this plugin already
     * runs nowhere that lacks it) purely for correct multi-byte
     * case-folding; the accent map below runs on its (already lower-cased)
     * output.
     *
     * This is NOT full Spanish dictionary collation (`ñ` folds to `n`
     * instead of sorting between `n` and `o`) — see
     * `CandidatosResolverTest::test_buscar_paginado_sorts_accented_spanish_names_as_expected()`
     * for exactly what this guarantees: `Pérez`/`Rodríguez`/`Gómez` land
     * where an unaccented reading of the same name would, not a byte-order
     * reading. That is the concrete bug this fold exists to fix; a full RAE
     * collation is out of scope without taking the ext-intl dependency above.
     */
    private static function claveOrdenNombre( string $nombre ): string {
        static $mapaAcentos = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr( mb_strtolower( $nombre, 'UTF-8' ), $mapaAcentos );
    }

    /**
     * Every `post_title`, keyed by player_id, for $playerIds — the batched
     * (chunked) read `ordenarCandidatos()` needs to sort the FULL filtered
     * population by name before `buscarPaginado()` slices out a page (see
     * that method's own docblock, "THE SORT KEY"). ONE query per
     * `self::ID_CHUNK_SIZE`-sized chunk of $playerIds, never one query per
     * candidate — same chunking shape, and the same reasoning, as
     * `JugadorMetricasReader::fetchLatestMetaValuesFor()` (see that method's
     * own docblock, which this mirrors rather than re-derives).
     *
     * @param array<int, int> $playerIds
     * @return array<int, string> Keyed by player_id — ONLY ids that resolve
     *         to an actual row are present (mirrors
     *         `fetchLatestMetaValuesFor()`'s own contract); a candidate
     *         absent here is handled by `nombreParaOrden()`'s own fallback,
     *         never by this method.
     * @throws \RuntimeException When the query fails at the wpdb level, for
     *         ANY chunk — results already merged from earlier, successful
     *         chunks are discarded along with the exception, same discipline
     *         as `fetchLatestMetaValuesFor()` (see "READ FAILURES MUST NEVER
     *         READ AS 'NOBODY HAS METRICS'" on that class — the equivalent
     *         failure here would silently mis-sort the whole page instead).
     */
    private function nombresPorJugador( array $playerIds ): array {
        if ( empty( $playerIds ) ) {
            return [];
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $nombres = [];

        foreach ( array_chunk( $playerIds, self::ID_CHUNK_SIZE ) as $chunk ) {
            $placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID AS id, post_title AS nombre
                       FROM {$p}posts
                      WHERE ID IN ({$placeholders})",
                    $chunk
                ),
                ARRAY_A
            );

            $this->assertReadSucceeded( $rows, 'nombresPorJugador', [ 'player_ids_count' => count( $chunk ) ] );

            foreach ( $rows as $row ) {
                $nombres[ (int) $row['id'] ] = (string) $row['nombre'];
            }
        }

        return $nombres;
    }

    /**
     * Escapes a free-text search term for safe use inside a `LIKE '%…%'`
     * pattern whose `ESCAPE` character is `'!'` — shared by
     * `playerIdsRegistradosEnTemporada()`, `playerIdsListaDeEspera()` and
     * `playerIdsPadronCompleto()`, the only three places this plugin builds a
     * `LIKE` pattern from captain-supplied input.
     *
     * *** WHY NOT `$wpdb->esc_like()` *** It is hardcoded to escape with a
     * backslash (`addcslashes( $text, '_%\\' )`), which is exactly the
     * character that must NOT be the escape character here — see "WHY NOT A
     * BACKSLASH" below. This method escapes the SAME three characters
     * `esc_like()` does (`%`, `_`, and the escape character itself), just
     * with `!` standing in for `\`.
     *
     * *** WHY NOT A BACKSLASH *** A previous version of these three query
     * methods used `ESCAPE '\\'` — which, in MySQL, is not merely "a
     * backslash as the escape character" in the abstract: MySQL's own
     * string-literal parser ALSO treats a backslash as an escape character
     * (true unless `NO_BACKSLASH_ESCAPES` is set — see
     * https://dev.mysql.com/doc/refman/8.4/en/string-literals.html), and
     * `\'` is one of its documented escape sequences, producing a literal
     * `'` rather than closing the string. So the SQL text `ESCAPE '\''`
     * never closes that string literal at all — MySQL keeps scanning for an
     * unescaped closing quote into the rest of the query, which is a syntax
     * error in production. This is confirmed against MySQL's own
     * documentation (string-literals.html and the `LIKE` / `ESCAPE` section
     * of string-comparison-functions.html), not merely asserted.
     *
     * This plugin's whole test suite runs against the SQLite shim in
     * `tests/wp-shim.php`, and SQLite does NOT give backslash any lexical
     * meaning inside a string literal — it closes `'\''`'s string at the
     * first `'`, reads a lone backslash as the one-character `ESCAPE` value,
     * and happily evaluates the pattern. That is exactly why this plugin's
     * 654 PHPUnit tests passed while the equivalent query failed on
     * production MySQL: the two engines disagree about where that string
     * literal ends, and nothing in this suite can exercise MySQL's parser to
     * catch that divergence (see CandidatosResolverTest's SQL-pinning test,
     * whose own docblock repeats this limitation).
     *
     * *** WHY `!` *** It has no lexical meaning inside a MySQL or SQLite
     * string literal (unlike `\`), and no meaning as a `LIKE` wildcard in
     * either engine (only `%` and `_` are wildcards) — confirmed against
     * MySQL's documentation above and, empirically, against this plugin's
     * own SQLite shim (`sqlite3 :memory: "SELECT 'a!b' LIKE '%!!b' ESCAPE
     * '!'"` → `1`). Any other character outside `%`, `_`, quote and
     * backslash would work the same way; `!` is simply the one this plugin
     * standardizes on so there is exactly one answer everywhere this
     * pattern appears.
     *
     * @return string $term with `!`, `%` and `_` each prefixed by `!` — the
     *         escape character is escaped FIRST, so a term that itself
     *         contains `!` is never double-escaped by the later `%`/`_`
     *         passes.
     */
    private static function escapeLikeTerm( string $term ): string {
        $escaped = str_replace( '!', '!!', $term );
        $escaped = str_replace( '%', '!%', $escaped );
        $escaped = str_replace( '_', '!_', $escaped );

        return $escaped;
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
     * @param string $search When non-empty, additionally requires
     *        `post_title LIKE '%$search%'` — pushed into THIS query rather
     *        than resolved via a second `get_the_title()` pass over the
     *        whole population (see `buscarPaginado()`'s own docblock, point
     *        2, for why). `post_title` is the EXACT same column
     *        `nombreJugador()`'s `get_the_title()` ultimately reads for an
     *        `sp_player` post — this plugin applies no title filters that
     *        would make the two diverge — so a player can never match this
     *        search yet display a different resolved name. Matching is
     *        whatever case/accent sensitivity the underlying SQL engine's
     *        `LIKE` gives (case-insensitive for both this plugin's SQLite
     *        test shim and MySQL's default collation). The term is escaped
     *        with `escapeLikeTerm()` (NOT `$wpdb->esc_like()`, which is
     *        hardcoded to a backslash escape — see that method's own
     *        docblock for why that cannot be used here) before being
     *        wrapped in `%…%`, so a literal `%` or `_` typed by the captain
     *        matches itself instead of acting as a wildcard.
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsRegistradosEnTemporada( int $seasonId, string $search = '' ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $filtroBusqueda = '';
        $params         = [ $seasonId ];

        if ( '' !== $search ) {
            // ESCAPE '!' — NEVER a backslash: MySQL's own string-literal
            // parser treats a backslash before the closing quote as an
            // escaped quote, so `ESCAPE '\'` never actually closes the
            // string literal and breaks the query on real MySQL. The SQLite
            // shim backing this suite does not share that lexical rule, so
            // it accepted the broken clause — see escapeLikeTerm()'s own
            // docblock for the full explanation (and citations) of why `!`
            // is the escape character used everywhere in this class.
            $filtroBusqueda = " AND posts.post_title LIKE %s ESCAPE '!'";
            $params[]       = '%' . self::escapeLikeTerm( $search ) . '%';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id
                   FROM {$p}posts posts
                   INNER JOIN {$p}term_relationships tr ON tr.object_id = posts.ID
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND tt.taxonomy = 'sp_season'
                    AND tt.term_id = %d{$filtroBusqueda}
                  ORDER BY posts.ID ASC",
                $params
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsRegistradosEnTemporada', [ 'season_id' => $seasonId, 'search' => $search ] );

        return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
    }

    /**
     * Every PUBLISHED `sp_player` post on $teamId (the "lista de espera"
     * team — see Plazas\ListaEsperaResolver) — NOT a taxonomy-join: unlike
     * `sp_season`, SportsPress does not expose team membership as a
     * taxonomy term relationship. A player's team is stored as ordinary
     * `postmeta`, `meta_key = 'sp_team'`, `meta_value` = the team's
     * `sp_team` post id (confirmed against the working sibling plugin,
     * entre-redes-api, which filters players by team the same way via a
     * `meta_query` on that exact key). There is no `sp_team` taxonomy at
     * all — `GET /wp-json/wp/v2/taxonomies` on production lists `sp_league`,
     * `sp_position`, `sp_role`, `sp_season`, `sp_venue`, and nothing else.
     * A player can carry more than one `sp_team` postmeta row (historical
     * teams), hence `SELECT DISTINCT`.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** Same
     * reasoning as playerIdsRegistradosEnTemporada() — a failed read here
     * would render Rest\PlazasController's "Lista de Espera" section as an
     * empty list, indistinguishable from "nobody signed up" (see
     * Plazas\ListaEsperaResolver's own class docblock for the same concern
     * one level up, at team-id resolution rather than membership).
     *
     * @param string $search See playerIdsRegistradosEnTemporada()'s own
     *        docblock for the exact matching semantics — identical here.
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsListaDeEspera( int $teamId, string $search = '' ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $filtroBusqueda = '';
        $params         = [ (string) $teamId ];

        if ( '' !== $search ) {
            // ESCAPE '!' — NEVER a backslash: MySQL's own string-literal
            // parser treats a backslash before the closing quote as an
            // escaped quote, so `ESCAPE '\'` never actually closes the
            // string literal and breaks the query on real MySQL. The SQLite
            // shim backing this suite does not share that lexical rule, so
            // it accepted the broken clause — see escapeLikeTerm()'s own
            // docblock for the full explanation (and citations) of why `!`
            // is the escape character used everywhere in this class.
            $filtroBusqueda = " AND posts.post_title LIKE %s ESCAPE '!'";
            $params[]       = '%' . self::escapeLikeTerm( $search ) . '%';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id
                   FROM {$p}posts posts
                   INNER JOIN {$p}postmeta pm ON pm.post_id = posts.ID
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND pm.meta_key = 'sp_team'
                    AND pm.meta_value = %s{$filtroBusqueda}
                  ORDER BY posts.ID ASC",
                $params
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsListaDeEspera', [ 'team_id' => $teamId, 'search' => $search ] );

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
     * postmeta shape playerIdsListaDeEspera() uses directly — team
     * membership is `postmeta`, `meta_key = 'sp_team'`, never a taxonomy
     * (see playerIdsListaDeEspera()'s own docblock for the full evidence) —
     * rather than fetching that list in PHP and filtering here — one query,
     * no second round trip, and no risk of the two lists drifting if either
     * query's WHERE clause is ever edited without the other.
     *
     * *** MUST THROW, NEVER SILENTLY RETURN [] ON A QUERY FAILURE *** Same
     * reasoning as playerIdsListaDeEspera() — a failed read here would
     * render "Padrón Completo" as an empty list instead of failing loud.
     *
     * @param string $search See playerIdsRegistradosEnTemporada()'s own
     *        docblock for the exact matching semantics — identical here.
     * @return array<int, int>
     * @throws \RuntimeException When the query fails at the wpdb level.
     */
    private function playerIdsPadronCompleto( int $excludeTeamId, string $search = '' ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $filtroBusqueda = '';
        $params         = [ (string) $excludeTeamId ];

        if ( '' !== $search ) {
            // ESCAPE '!' — NEVER a backslash: MySQL's own string-literal
            // parser treats a backslash before the closing quote as an
            // escaped quote, so `ESCAPE '\'` never actually closes the
            // string literal and breaks the query on real MySQL. The SQLite
            // shim backing this suite does not share that lexical rule, so
            // it accepted the broken clause — see escapeLikeTerm()'s own
            // docblock for the full explanation (and citations) of why `!`
            // is the escape character used everywhere in this class.
            $filtroBusqueda = " AND posts.post_title LIKE %s ESCAPE '!'";
            $params[]       = '%' . self::escapeLikeTerm( $search ) . '%';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT posts.ID AS id
                   FROM {$p}posts posts
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND posts.ID NOT IN (
                        SELECT pm.post_id
                          FROM {$p}postmeta pm
                         WHERE pm.meta_key = 'sp_team'
                           AND pm.meta_value = %s
                    ){$filtroBusqueda}
                  ORDER BY posts.ID ASC",
                $params
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsPadronCompleto', [ 'exclude_team_id' => $excludeTeamId, 'search' => $search ] );

        return array_map( static fn ( array $row ): int => (int) $row['id'], $rows );
    }
}
