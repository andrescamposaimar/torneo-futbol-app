<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Calendario\BoundedFechaCounter;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException;
use EntreRedes\Cambios\Dictamen\BloqueoReemplazoPolicy;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\CadenaResolver;
use EntreRedes\Cambios\Plazas\CandidatoEstado;
use EntreRedes\Cambios\Plazas\CandidatosResolver;
use EntreRedes\Cambios\Plazas\CandidatosSeccion;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\ListaEsperaResolver;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\PosicionResolver;
use EntreRedes\Cambios\Plazas\Puntaje;

/**
 * REST controller for the CAPTAIN-facing plantel status endpoint:
 *
 *   GET /entre-redes/v1/cambios/plazas — every plaza of the captain's team,
 *       who occupies it TODAY, and how many resolved fechas are still
 *       missing before it liberates (via Plazas\CadenaResolver). This is the
 *       screen that keeps a captain from requesting a change that is
 *       impossible from the start (e.g. a regreso against a plaza that has
 *       not liberated yet).
 *
 * *** AUTHORIZATION RUNS FIRST, ALWAYS *** — see
 * Rest\HandlesCapitanAuthorization's own docblock; same discipline as
 * Rest\SolicitudesController.
 *
 * *** THIS IS A READ. ONE KIND OF PER-PLAZA FAILURE DOES NOT FAIL THE WHOLE
 * RESPONSE — A READ THAT CANNOT EVEN LOAD THE PLAZA'S DATA DOES. ***
 * `Plazas\CadenaResolver::countFechasUntilLiberacion()` can throw
 * `FechaCountUnavailableException` for one specific plaza (a broken counter,
 * an unreachable fecha) while every other plaza in the same team is
 * perfectly fine to compute. Letting that one exception bubble up to
 * `listar()`'s generic `\Throwable` catch would turn ONE plaza's problem into
 * a 500 for the captain's ENTIRE roster — eleven plazas hidden because one
 * could not be counted, when this specific failure carries no risk of
 * authorizing anything: there is nothing to protect by refusing to show the
 * other ten. `resolveFechasFaltantes()` therefore catches THAT exception per
 * plaza, logs it, and degrades that ONE row to `fechas_faltantes_liberacion:
 * null` plus an explicit `fechas_faltantes_liberacion_indeterminado: true`
 * marker — never a silently wrong `0`.
 *
 * A failed READ of the roster or chain data itself
 * (`Plazas\PlazaRepository::listPlazasByEquipo()` / `listOcupaciones()`) is
 * NOT given this same per-row tolerance: both now throw on a wpdb-level
 * failure (see their own docblocks — a prior version of this class's
 * docblock claimed `listOcupaciones()` read a failure as "no rows"; that is
 * no longer true), and `listar()`'s outer `\Throwable` catch turns that into
 * a 500 for the WHOLE response, logged as `rest.plazas_listar_fallida`. This
 * is a deliberate, coarser failure mode than the per-row degradation above:
 * a plaza whose OWN chain cannot be read at all has no honest partial
 * answer to show for `ocupante_player_id` — showing `null` there would be
 * indistinguishable from "this plaza genuinely has no occupant", which
 * `PlazaRepository::openPlaza()`'s invariant says can never happen. Failing
 * the whole request is the honest choice; degrading only that one row would
 * require inventing a NEW "indeterminado" marker for `ocupante_player_id`
 * this class does not have today.
 *
 * *** THIS TOLERANCE DOES NOT EXTEND TO WRITES *** — creating a solicitud,
 * or publishing the Friday lote, still fails closed and loud on the exact
 * same exception (see `Dictamen\Reglas\RegresoSoloConMinimoCumplido` and
 * `Plazas\CadenaResolver::isPlazaLiberable()`, both of which answer
 * conservatively rather than guess). Only a READ that cannot change any
 * roster state gets this per-row degradation; nobody should copy this
 * pattern into a write path.
 */
class PlazasController {

    use HandlesCapitanAuthorization;

    private CapitanAuthorizer $authorizer;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private EventLog $eventLog;
    private CandidatosResolver $candidatosResolver;
    private BloqueoReemplazoPolicy $politicaCC5b;
    private ?ListaEsperaResolver $listaEsperaResolver;

    /** @var callable(): int */
    private $clockFn;

    /** @var callable(int): (string|false) */
    private $fotoResolverFn;

    /** @var callable(array<int, int>): array<int, string> */
    private $posicionResolverFn;

    /**
     * Player ids whose photo resolution THREW during the response currently
     * being built — reset at the start of every `listarCandidatos()` call,
     * drained (and recorded as ONE summarized event, never one event per
     * row) right after `shapeCandidato()` has run for the whole page — see
     * `fotoJugador()`'s own docblock.
     *
     * @var array<int, int>
     */
    private array $fotoResolverFailures = [];

    /**
     * This PAGE's player_id => main position name, resolved ONCE (batched,
     * never per row — see `Plazas\PosicionResolver`'s own docblock) right
     * before `shapeCandidato()` runs for every candidate in the page, and
     * read back inside it. Reset at the start of every `listarCandidatos()`
     * call, same lifecycle as `$fotoResolverFailures` above.
     *
     * @var array<int, string>
     */
    private array $posicionesPorJugador = [];

    /** Default `?per_page=` when the request omits it — see listarCandidatos(). */
    private const DEFAULT_PER_PAGE = 20;

    /**
     * Hard upper bound on `?per_page=` — see listarCandidatos()'s own
     * docblock, "PAGINATION": a caller cannot opt back into "the whole
     * population in one response", the exact cost this slice's task brief
     * set out to remove.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * @param CandidatosResolver           $candidatosResolver THE single
     *        source of truth for the candidatos endpoint — see that class's
     *        own docblock. Required, not defaulted: this controller has no
     *        `\wpdb` of its own to build one internally, so whoever wires
     *        this together (`Plugin::boot()`) must construct it explicitly.
     * @param BloqueoReemplazoPolicy|null  $politicaCC5b The SAME policy value
     *        `Plugin::boot()` hands to `Dictamen\DictamenPipeline` — the
     *        candidatos endpoint must judge "bloqueado" under the exact
     *        policy the dictamen engine itself applies, or a candidate this
     *        endpoint calls viable could still be rejected when actually
     *        requested. Null keeps the same default as
     *        `Reglas\EntranteNoBloqueado` (`topeTresFechas()`).
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch, for the authorization check — see
     *        Rest\SolicitudesController's constructor docblock for why this
     *        is injectable rather than a direct `time()` call. Defaults to
     *        the real clock.
     * @param ListaEsperaResolver|null $listaEsperaResolver Resolves the
     *        "lista de espera" team id for `?seccion=lista_espera` /
     *        `?seccion=padron_completo` requests — see
     *        `listarCandidatos()`'s own docblock, "THE TWO SCREEN SECTIONS".
     *        Nullable PURELY for backward compatibility with callers (and
     *        existing tests) constructed before these two sections existed,
     *        which never pass a `seccion` param and therefore never need
     *        this collaborator; `Plugin::boot()` always wires a real one.
     *        `null` here is NOT a usable production configuration — see
     *        `listarCandidatos()` for what happens when `seccion` is
     *        requested against a controller instance that was not wired
     *        with one (a loud failure, never a silent empty section).
     * @param callable(int): (string|false)|null $fotoResolverFn Resolves one
     *        candidate's photo URL — see `fotoJugador()`'s own docblock for
     *        why this is injectable rather than a direct
     *        `get_the_post_thumbnail_url()` call. Defaults to exactly that
     *        call, at the SAME `'medium'` size `entre-redes-api`'s own
     *        `/jugadores` endpoint already serves.
     * @param callable(array<int, int>): array<int, string>|null $posicionResolverFn
     *        Resolves a WHOLE page of candidate ids to their main `sp_position`
     *        name in one batched call — see `Plazas\PosicionResolver`'s own
     *        class docblock for why this is injectable rather than a direct
     *        `wp_get_object_terms()` call (this plugin's SQLite test shim has
     *        no taxonomy equivalent, same reasoning as `$fotoResolverFn`
     *        above). Defaults to `(new PosicionResolver())->resolverParaIds()`.
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog,
        CandidatosResolver $candidatosResolver,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        ?callable $clockFn = null,
        ?ListaEsperaResolver $listaEsperaResolver = null,
        ?callable $fotoResolverFn = null,
        ?callable $posicionResolverFn = null
    ) {
        $this->authorizer          = $authorizer;
        $this->plazaRepository     = $plazaRepository;
        $this->fechaRepository     = $fechaRepository;
        $this->eventLog            = $eventLog;
        $this->candidatosResolver  = $candidatosResolver;
        $this->politicaCC5b        = $politicaCC5b ?? BloqueoReemplazoPolicy::topeTresFechas();
        $this->clockFn             = $clockFn ?? static fn (): int => time();
        $this->listaEsperaResolver = $listaEsperaResolver;
        $this->fotoResolverFn      = $fotoResolverFn ?? static fn ( int $playerId ) => get_the_post_thumbnail_url( $playerId, 'medium' );
        $this->posicionResolverFn  = $posicionResolverFn ?? static fn ( array $playerIds ): array => ( new PosicionResolver() )->resolverParaIds( $playerIds );
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/plazas',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listar' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/plazas/candidatos',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listarCandidatos' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * GET /entre-redes/v1/cambios/plazas?season_id=..&team_id=..
     *
     * Response 200: { plazas: [ { plaza_id, titular_player_id,
     *         ocupante_player_id, es_titular_el_ocupante, cerrada,
     *         puntaje_techo, fechas_faltantes_liberacion,
     *         fechas_faltantes_liberacion_indeterminado }, ... ] }
     *
     * `puntaje_techo` is the plaza's own ceiling, as a decimal (e.g. `2.5`)
     * — added so the captain's "Pedir cambio" screen can DISPLAY the
     * constraint the candidatos endpoint already enforces, never compute
     * eligibility against it itself (see `listarCandidatos()`'s own
     * docblock, "FILTERING HAPPENS HERE, NEVER IN CandidatosResolver", for
     * the same discipline applied to viability).
     *
     * `ocupante_player_id` is null only when the plaza somehow has no vigent
     * ocupación (should not happen once PlazaRepository::openPlaza() has
     * run, but this endpoint does not assume it — see
     * PlazaRepository::findOcupacionVigente()'s own docblock). A plaza whose
     * ocupaciones chain could not even be READ never reaches this shape at
     * all — PlazaRepository::listOcupaciones() throws on a wpdb-level
     * failure, which aborts the WHOLE response (see this class's docblock,
     * "THIS IS A READ") rather than rendering as a fabricated `null`.
     *
     * `fechas_faltantes_liberacion` is null, with `fechas_faltantes_
     * liberacion_indeterminado: true`, when the chain WAS read but the
     * liberation count itself could not be trusted (CadenaResolver's own
     * injected counter threw `FechaCountUnavailableException`) — see this
     * class's docblock, "THIS IS A READ", for why that ONE failure degrades
     * only THIS plaza's row instead of failing the whole response.
     */
    public function listar( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = (int) $request->get_param( 'season_id' );
        $teamId   = (int) $request->get_param( 'team_id' );

        if ( $seasonId <= 0 || $teamId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'season_id y team_id son obligatorios y deben ser mayores a 0.'
            );
        }

        try {
            $this->authorizeCapitan( $this->authorizer, $request, $seasonId, $teamId, ( $this->clockFn )() );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'GET /cambios/plazas',
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada( $e );
        }

        try {
            $plazas = $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId );

            // Bound to THIS season — see Plazas\CadenaResolver's class
            // docblock for why the callable is injected rather than read
            // from a global clock — and routed through
            // Calendario\BoundedFechaCounter, exactly like
            // listarCandidatos() below.
            //
            // The cap is not decoration here. `fechas_faltantes_liberacion`
            // is what tells a capitan whether the titular may come back yet,
            // and an INFLATED count makes that number too SMALL — the screen
            // would read "0 faltantes, pedilo" for a plaza the dictamen
            // engine (whose own path is bounded, and fails closed) will then
            // reject. CadenaResolver cannot notice an inflated count on its
            // own, so without this wrapper nothing in this path ever would.
            //
            // A thrown FechaCountUnavailableException does not fail the
            // response: resolveFechasFaltantes() below already degrades that
            // single plaza to `indeterminado`, which is the honest answer
            // when the count cannot be trusted — see "A SINGLE PLAZA'S
            // CALCULATION FAILING DOES NOT FAIL THE WHOLE RESPONSE".
            $boundedFechaCounter        = new BoundedFechaCounter( $this->fechaRepository, $this->eventLog );
            $countResolvedFechasSinceFn = $boundedFechaCounter->boundedCountResolvedFechasSinceFn( $seasonId );

            $cadenaResolver = new CadenaResolver( $countResolvedFechasSinceFn );

            // Ocupaciones/vigente are resolved ONCE per plaza here, BEFORE
            // any name is looked up, so every player id this response will
            // need a name for — titular AND ocupante — is known up front and
            // handed to primePlayerTitles() in a single batch, instead of
            // shapePlaza() resolving each plaza's own ocupante id one at a
            // time inside its own per-row loop. See primePlayerTitles()'s
            // docblock for why this matters more on listarCandidatos() below,
            // but the same discipline is kept here for consistency.
            $ocupacionesPorPlaza = [];
            $vigentePorPlaza     = [];
            $playerIds           = [];

            foreach ( $plazas as $plaza ) {
                $plazaId     = (int) $plaza['id'];
                $ocupaciones = $this->plazaRepository->listOcupaciones( $plazaId );
                $vigente     = $this->vigente( $ocupaciones );

                $ocupacionesPorPlaza[ $plazaId ] = $ocupaciones;
                $vigentePorPlaza[ $plazaId ]     = $vigente;

                $playerIds[] = (int) $plaza['titular_player_id'];
                if ( null !== $vigente ) {
                    $playerIds[] = (int) $vigente['player_id'];
                }
            }

            $this->primePlayerTitles( array_values( array_unique( $playerIds ) ) );

            $resultado = array_map(
                fn ( array $plaza ): array => $this->shapePlaza(
                    $plaza,
                    $cadenaResolver,
                    $ocupacionesPorPlaza[ (int) $plaza['id'] ],
                    $vigentePorPlaza[ (int) $plaza['id'] ]
                ),
                $plazas
            );

            return new \WP_REST_Response( [ 'plazas' => $resultado ], 200 );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.plazas_listar_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }

    /**
     * GET /entre-redes/v1/cambios/plazas/candidatos?season_id=..&team_id=..&plaza_id=..[&seccion=lista_espera|padron_completo][&incluir_no_viables=1][&search=..][&puntajes[]=..][&page=..][&per_page=..]
     *
     * Response 200 (header `X-WP-Total: <int>`, see "PAGINATION" below):
     * { candidatos: [ { player_id, nombre, es_padre, puntaje, viable, motivo,
     *         foto_url, posicion }, ... ] }
     *
     * `posicion` is the candidate's main `sp_position` NAME (e.g. `Arquero`,
     * `Sin Posicion`), resolved for the whole page in ONE batched call — see
     * `Plazas\PosicionResolver`'s own class docblock for exactly how "main"
     * is chosen (mirroring `entre-redes-api`'s own `/jugadores` endpoint so
     * this screen never disagrees with the Players/Team screens about the
     * same player).
     *
     * THE single endpoint the captain's screen calls to know who is
     * available for a plaza AND why someone is not — backed entirely by
     * Plazas\CandidatosResolver, the same source
     * Dictamen\Reglas\PrioridadDePadresRespetada consults, so this screen can
     * never show a candidate as viable that the dictamen engine would then
     * reject — see that class's own docblock, "WHY THIS MUST BE THE ONLY
     * IMPLEMENTATION". The injected resolved-fechas counter is bounded by
     * Calendario\BoundedFechaCounter — the SAME collaborator
     * Dictamen\DictamenContextAssembler uses — so an inflated counter makes
     * THIS endpoint fail closed (caught below, logged as
     * `rest.plazas_candidatos_fallida`) exactly like it would make the
     * dictamen engine refuse, instead of this screen showing an optimistic
     * list the engine would then reject.
     *
     * *** PAGINATION — `?page=`/`?per_page=`, ALWAYS ON ***
     * This endpoint used to return its WHOLE candidate population in one
     * response — for `?seccion=padron_completo` that is roughly a thousand
     * rows, most of which then paid the per-candidate viability queries
     * (`Plazas\CandidatosResolver`'s own "COST" docblock) for nothing a
     * single screen could ever show at once. It now ALWAYS paginates, via
     * `Plazas\CandidatosResolver::buscarPaginado()` — see that method's own
     * docblock for exactly how pagination is ordered BEFORE the N+1, never
     * after. `?page` defaults to 1, is clamped to >= 1. `?per_page` defaults
     * to self::DEFAULT_PER_PAGE and is clamped to
     * `[1, self::MAX_PER_PAGE]` — the upper bound exists specifically so a
     * caller cannot opt back into "the whole population in one response".
     * An out-of-range `?page` (beyond the last one) is never a 400 — it is
     * simply an empty `candidatos: []`, same as `buscarPaginado()`'s own
     * `array_slice()` semantics.
     *
     * The total population size (after `?search=`/`?puntajes[]=`, BEFORE
     * pagination — see `buscarPaginado()`'s own docblock for exactly what
     * this counts) is reported via the `X-WP-Total` response header — the
     * SAME convention `entre-redes-api`'s own `/jugadores` endpoint already
     * uses and this app's `ApiService.getJugadoresRaw()` already reads, kept
     * deliberately consistent rather than inventing a third pagination
     * shape. The app decides whether more pages remain from this header and
     * `page`/`per_page` math, NEVER from how many items a given page
     * returned — see "FILTERING HAPPENS HERE, NEVER IN CandidatosResolver"
     * below for why a page can legitimately return fewer than `per_page`
     * items while pages still remain.
     *
     * *** THE TWO SCREEN SECTIONS — `?seccion=` ***
     * `?seccion=lista_espera` and `?seccion=padron_completo` select ONE of
     * the two WIDER populations `Plazas\CandidatosResolver::buscarPaginado()`
     * exposes (see that method's own docblock) — the people who actually
     * signed up, and everyone else in the padrón, respectively. Resolving
     * which `sp_team` post IS "lista de espera" for $seasonId
     * (`Plazas\ListaEsperaResolver::resolve()`) can itself fail when neither
     * an operator override nor the dynamic slug lookup produces an answer;
     * that failure is NOT caught separately here — it propagates into this
     * method's own `\Throwable` catch below, which fails the WHOLE response
     * (a logged 500), never a silently empty `candidatos: []`. See
     * `Plazas\ListaEsperaResolver`'s own class docblock for why an empty
     * list would be the wrong failure mode for this specific endpoint.
     *
     * `?seccion` is OPTIONAL and purely ADDITIVE: omitting it keeps the
     * EXACT pre-existing population (`Plazas\CandidatosResolver`'s
     * season-registered pool) for any caller — including the committee's
     * own tooling — written before these two sections existed. Pagination
     * now applies to EVERY call regardless of `?seccion`.
     *
     * *** FILTERING HAPPENS HERE, NEVER IN CandidatosResolver ***
     * `paraPlaza()`/`paraSeccion()` stay the single, unfiltered, UNPAGINATED
     * sources of truth (see CandidatosResolver's own docblock) —
     * `Reglas\PrioridadDePadresRespetada` needs that FULL pool to count
     * viable padres, so those two methods must never change to accommodate
     * this endpoint's own presentation needs; `buscarPaginado()` is an
     * ADDITIVE third entry point this endpoint alone calls. Instead:
     *   - By DEFAULT, only VIABLE candidates among the PAGE are returned — a
     *     captain cannot act on a non-viable one. Because viability is only
     *     ever computed for the requested page (see "PAGINATION" above), a
     *     page can legitimately come back with FEWER than `per_page` items
     *     even while `X-WP-Total` says more pages remain — exactly like any
     *     other filtered list page can.
     *   - `?incluir_no_viables=1` opts back into the page's FULL list,
     *     `viable` and `motivo` intact — for the committee's own tooling,
     *     which may want to see WHY someone was excluded. Applies
     *     identically whether `?seccion` was given or not.
     *   - `?search=<text>` and `?puntajes[]=<decimal>` (repeatable, e.g.
     *     `puntajes[]=3&puntajes[]=4.5`) narrow the POPULATION itself,
     *     BEFORE pagination — see `buscarPaginado()`'s own docblock for why
     *     that ordering matters and exactly how each is matched.
     */
    public function listarCandidatos( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = (int) $request->get_param( 'season_id' );
        $teamId   = (int) $request->get_param( 'team_id' );
        $plazaId  = (int) $request->get_param( 'plaza_id' );

        if ( $seasonId <= 0 || $teamId <= 0 || $plazaId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'season_id, team_id y plaza_id son obligatorios y deben ser mayores a 0.'
            );
        }

        $seccion = trim( (string) ( $request->get_param( 'seccion' ) ?? '' ) );

        if ( '' !== $seccion && ! CandidatosSeccion::esValida( $seccion ) ) {
            return $this->respuestaSolicitudInvalida(
                'seccion_invalida',
                "seccion '{$seccion}' no es valida. Valores aceptados: " . implode( ', ', CandidatosSeccion::todas() ) . '.'
            );
        }

        try {
            $this->authorizeCapitan( $this->authorizer, $request, $seasonId, $teamId, ( $this->clockFn )() );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'GET /cambios/plazas/candidatos',
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'plaza_id'  => $plazaId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada( $e );
        }

        try {
            $plaza = $this->plazaRepository->findPlaza( $plazaId );

            if ( null === $plaza || (int) $plaza['season_id'] !== $seasonId || (int) $plaza['team_id'] !== $teamId ) {
                return $this->respuestaSolicitudInvalida(
                    'plaza_no_encontrada',
                    'La plaza indicada no existe o no pertenece a este equipo y temporada.'
                );
            }

            $boundedFechaCounter        = new BoundedFechaCounter( $this->fechaRepository, $this->eventLog );
            $countResolvedFechasSinceFn = $boundedFechaCounter->boundedCountResolvedFechasSinceFn( $seasonId );

            $seccionParaResolver = '' !== $seccion ? $seccion : null;
            $listaEsperaTeamId   = null;

            if ( null !== $seccionParaResolver ) {
                if ( null === $this->listaEsperaResolver ) {
                    throw new \RuntimeException(
                        'PlazasController::listarCandidatos(): a seccion was requested but this controller '
                        . 'instance was not wired with a ListaEsperaResolver — see the constructor docblock.'
                    );
                }

                $listaEsperaTeamId = $this->listaEsperaResolver->resolve( $seasonId );
            }

            $page    = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
            $perPage = $request->get_param( 'per_page' );
            $perPage = null !== $perPage ? (int) $perPage : self::DEFAULT_PER_PAGE;
            $perPage = max( 1, min( $perPage, self::MAX_PER_PAGE ) );

            $search = trim( (string) ( $request->get_param( 'search' ) ?? '' ) );

            $puntajesFiltro = array_values( array_filter(
                array_map(
                    static fn ( $valor ): ?float => is_numeric( $valor ) ? (float) $valor : null,
                    (array) ( $request->get_param( 'puntajes' ) ?? [] )
                ),
                static fn ( ?float $v ): bool => null !== $v
            ) );

            $resultado  = $this->candidatosResolver->buscarPaginado(
                $plaza,
                $seccionParaResolver,
                $listaEsperaTeamId,
                $this->politicaCC5b,
                $countResolvedFechasSinceFn,
                $page,
                $perPage,
                $search,
                $puntajesFiltro
            );
            $candidatos = $resultado['candidatos'];
            $total      = $resultado['total'];

            $incluirNoViables = '1' === (string) $request->get_param( 'incluir_no_viables' );

            if ( ! $incluirNoViables ) {
                $candidatos = array_values( array_filter(
                    $candidatos,
                    static fn ( CandidatoEstado $c ): bool => $c->viable()
                ) );
            }

            // Names/photos/posiciones are all resolved for exactly the PAGE
            // this response returns — never the whole population — see
            // primePlayerTitles()'s own docblock, fotoJugador()'s, and
            // Plazas\PosicionResolver's own class docblock. Posición is
            // resolved in ONE batched call for the whole page here (never
            // one call per candidate inside shapeCandidato() itself), the
            // exact N+1 shape this endpoint already removed for the name and
            // the photo.
            $pageIds = array_map(
                static fn ( CandidatoEstado $c ): int => $c->playerId(),
                $candidatos
            );
            $this->primePlayerTitles( $pageIds );
            $this->posicionesPorJugador = ( $this->posicionResolverFn )( $pageIds );

            $this->fotoResolverFailures = [];
            $candidatosShape            = array_map( [ $this, 'shapeCandidato' ], $candidatos );

            // One candidate's photo resolver throwing must degrade ONLY that
            // row's foto_url to null (see fotoJugador()), never the whole
            // response — but it is still worth knowing about, so it is
            // recorded here as a SINGLE summarized event for the whole page
            // rather than one event per failing row, which could flood the
            // log if every photo in a page failed at once.
            if ( [] !== $this->fotoResolverFailures ) {
                $this->eventLog->record( 'rest.foto_jugador_fallida', [
                    'season_id'  => $seasonId,
                    'team_id'    => $teamId,
                    'plaza_id'   => $plazaId,
                    'count'      => count( $this->fotoResolverFailures ),
                    'player_ids' => $this->fotoResolverFailures,
                ] );
            }

            $response = new \WP_REST_Response(
                [ 'candidatos' => $candidatosShape ],
                200
            );
            $response->header( 'X-WP-Total', (string) $total );

            return $response;
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.plazas_candidatos_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'plaza_id'  => $plazaId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function shapeCandidato( CandidatoEstado $c ): array {
        return [
            'player_id' => $c->playerId(),
            'nombre'    => $this->nombreJugador( $c->playerId() ),
            'es_padre'  => $c->esPadre(),
            'puntaje'   => null !== $c->puntaje() ? $c->puntaje()->toDecimal() : null,
            'viable'    => $c->viable(),
            'motivo'    => $c->motivoNoViable(),
            'foto_url'  => $this->fotoJugador( $c->playerId() ),
            'posicion'  => $this->posicionesPorJugador[ $c->playerId() ] ?? PosicionResolver::SIN_POSICION,
        ];
    }

    /**
     * The candidate's photo — the WordPress featured image of the
     * `sp_player` post, at the SAME `'medium'` size `entre-redes-api`'s own
     * `/jugadores` endpoint already serves (see that plugin's
     * `entre_redes_get_jugadores()`, `get_the_post_thumbnail_url( $post->ID,
     * 'medium' )`) — so "Pedir cambio" and "Mi Plantel"/"Jugadores" never
     * show two different pictures of the same player.
     *
     * Resolved via the injected `$fotoResolverFn`, never a direct
     * `get_the_post_thumbnail_url()` call — this plugin's whole test suite
     * runs against an in-memory SQLite shim with no real WordPress media
     * library behind it (see this class's own class docblock, and every
     * other collaborator in this plugin that reads WordPress state through
     * an injected seam rather than a bare global function call). Injecting
     * the resolution function is what lets a test exercise this method's own
     * null-coalescing below without a real attachment.
     *
     * Called ONLY after `primePlayerTitles()` has already warmed the post
     * object cache for exactly this response's PAGE of candidates — never
     * the whole population (see `listarCandidatos()`'s own docblock,
     * "PAGINATION") — the SAME batching discipline `nombreJugador()` already
     * relies on for the name, applied here to the photo instead.
     *
     * *** A THROWING RESOLVER DEGRADES ONLY THIS ROW, NEVER THE RESPONSE ***
     * `$fotoResolverFn` is called inside its OWN `try`/`catch`: one bad
     * attachment (a corrupt thumbnail, a resolver that hits a transient
     * storage failure, …) must not turn the whole candidatos page into a 500
     * — `shapeCandidato()` runs inside `listarCandidatos()`'s single
     * top-level `try`, whose `catch` aborts the ENTIRE response, so a
     * propagated exception here would do exactly that. The failing
     * `$playerId` is recorded into `$this->fotoResolverFailures` instead of
     * logged immediately — `listarCandidatos()` emits ONE summarized event
     * for the whole page after `shapeCandidato()` has run for every
     * candidate, never one event per failing row.
     *
     * @return string|null `null` when the player has no featured image (the
     *         callable returns `false` or an empty string) OR when the
     *         callable throws — the app falls back to its person icon for
     *         that candidate either way, never a broken image (see
     *         `CambiosCandidatoCard`'s own fallback).
     */
    private function fotoJugador( int $playerId ): ?string {
        try {
            $foto = ( $this->fotoResolverFn )( $playerId );
        } catch ( \Throwable $e ) {
            $this->fotoResolverFailures[] = $playerId;

            return null;
        }

        return is_string( $foto ) && '' !== $foto ? $foto : null;
    }

    /**
     * @param array<string, mixed> $plaza As returned by
     *        PlazaRepository::listPlazasByEquipo().
     * @param array<int, array<string, mixed>> $ocupaciones As returned by
     *        PlazaRepository::listOcupaciones( $plaza['id'] ) — resolved by
     *        the caller, once for every plaza, BEFORE primePlayerTitles()
     *        runs (see listar()).
     * @param array<string, mixed>|null $vigente The vigent ocupación within
     *        $ocupaciones, or null — also resolved by the caller.
     * @return array<string, mixed>
     */
    private function shapePlaza( array $plaza, CadenaResolver $cadenaResolver, array $ocupaciones, ?array $vigente ): array {
        $plazaId = (int) $plaza['id'];

        [ $fechasFaltantes, $indeterminado ] = $this->resolveFechasFaltantes( $plazaId, $ocupaciones, $cadenaResolver );

        return [
            'plaza_id'                                   => $plazaId,
            'titular_player_id'                           => (int) $plaza['titular_player_id'],
            'titular_nombre'                              => $this->nombreJugador( (int) $plaza['titular_player_id'] ),
            'ocupante_player_id'                          => null !== $vigente ? (int) $vigente['player_id'] : null,
            'ocupante_nombre'                              => null !== $vigente ? $this->nombreJugador( (int) $vigente['player_id'] ) : null,
            'es_titular_el_ocupante'                      => null !== $vigente
                && (int) $vigente['player_id'] === (int) $plaza['titular_player_id'],
            'cerrada'                                      => null !== $plaza['closed_at'],
            'puntaje_techo'                                => Puntaje::fromHalfPoints( (int) $plaza['puntaje_techo'] )->toDecimal(),
            'fechas_faltantes_liberacion'                  => $fechasFaltantes,
            'fechas_faltantes_liberacion_indeterminado'    => $indeterminado,
        ];
    }

    /**
     * Bulk-primes WordPress's post object cache for every id in
     * $playerIds, so the get_the_title() calls nombreJugador() makes right
     * after this — one per plaza/candidato — hit cache instead of issuing
     * one fresh query PER PLAYER. Mirrors the fetch-then-resolve shape
     * `entre-redes-api`'s own `/goleadores` handler uses (`get_posts()`
     * with `post__in`), without that handler's `update_meta_cache()` call —
     * this class never reads player postmeta, only the title, so there is
     * nothing else worth priming.
     *
     * listarCandidatos() is the endpoint this actually matters for: a
     * season's candidate pool can run into the hundreds (see
     * Plazas\CandidatosResolver's own class docblock, "COST: THIS IS N+1 BY
     * DESIGN") — resolving each one's name with an unprimed get_the_title()
     * would add one more uncached query per candidate on top of that.
     *
     * @param array<int, int> $playerIds
     */
    private function primePlayerTitles( array $playerIds ): void {
        if ( empty( $playerIds ) ) {
            return;
        }

        get_posts( [
            'post_type'      => 'sp_player',
            'post__in'       => $playerIds,
            'posts_per_page' => -1,
        ] );
    }

    /**
     * @return string The trimmed post title for $playerId, or
     *         "Jugador #<id>" when it comes back empty (or the post does
     *         not exist) — a screen must never render a blank name for a
     *         player. Same fallback discipline as
     *         Admin\BandejaPage::nombrePost().
     */
    private function nombreJugador( int $playerId ): string {
        $titulo = trim( (string) get_the_title( $playerId ) );

        return '' !== $titulo ? $titulo : 'Jugador #' . $playerId;
    }

    /**
     * The per-plaza degradation this class's docblock describes ("ONE KIND
     * OF PER-PLAZA FAILURE DOES NOT FAIL THE WHOLE RESPONSE"). An empty
     * $ocupaciones chain reaching THIS method is no longer a "read failed"
     * case — PlazaRepository::listOcupaciones() throws before `listar()`
     * ever builds this array, which aborts the whole response instead (see
     * that method's own docblock). An empty chain here would mean a plaza
     * was genuinely persisted with none, which
     * PlazaRepository::openPlaza()'s invariant says should never happen;
     * this branch is kept as a defensive fallback, not a documented normal
     * case, and returns the same honest "unknown" rather than guessing. A
     * THROWN FechaCountUnavailableException is the real per-row failure this
     * method exists to degrade: something WAS attempted and could not be
     * trusted, so it is logged here — once, with the plaza id — before
     * degrading, exactly like every other failure path in this plugin logs
     * before answering conservatively.
     *
     * @param array<int, array<string, mixed>> $ocupaciones
     * @return array{0: int|null, 1: bool} [fechas_faltantes_liberacion,
     *         fechas_faltantes_liberacion_indeterminado]
     */
    private function resolveFechasFaltantes( int $plazaId, array $ocupaciones, CadenaResolver $cadenaResolver ): array {
        if ( empty( $ocupaciones ) ) {
            return [ null, false ];
        }

        try {
            return [ $cadenaResolver->countFechasUntilLiberacion( $ocupaciones ), false ];
        } catch ( FechaCountUnavailableException $e ) {
            $this->eventLog->record( 'rest.plaza_fechas_faltantes_no_calculable', [
                'plaza_id'  => $plazaId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return [ null, true ];
        }
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
