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
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
use EntreRedes\Cambios\Plazas\PlazaRepository;

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
 * *** THIS IS A READ. A SINGLE PLAZA'S CALCULATION FAILING DOES NOT FAIL
 * THE WHOLE RESPONSE. *** `Plazas\CadenaResolver::countFechasUntilLiberacion()`
 * can throw `FechaCountUnavailableException` for one specific plaza (a
 * broken counter, an unreachable fecha) while every other plaza in the same
 * team is perfectly fine to compute. Letting that one exception bubble up
 * to `listar()`'s generic `\Throwable` catch would turn ONE plaza's problem
 * into a 500 for the captain's ENTIRE roster — eleven plazas hidden because
 * one could not be counted, when a READ carries no risk of authorizing
 * anything: there is nothing to protect by refusing to show the other ten.
 * `shapePlaza()` therefore catches that exception per plaza, logs it, and
 * degrades that ONE row to `fechas_faltantes_liberacion: null` plus an
 * explicit `fechas_faltantes_liberacion_indeterminado: true` marker — never
 * a silently wrong `0`.
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

    /** @var callable(): int */
    private $clockFn;

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
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog,
        CandidatosResolver $candidatosResolver,
        ?BloqueoReemplazoPolicy $politicaCC5b = null,
        ?callable $clockFn = null
    ) {
        $this->authorizer         = $authorizer;
        $this->plazaRepository    = $plazaRepository;
        $this->fechaRepository    = $fechaRepository;
        $this->eventLog           = $eventLog;
        $this->candidatosResolver = $candidatosResolver;
        $this->politicaCC5b       = $politicaCC5b ?? BloqueoReemplazoPolicy::topeTresFechas();
        $this->clockFn            = $clockFn ?? static fn (): int => time();
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
     * Response 200: { plazas: [ { plaza_id, tipo, titular_player_id,
     *         ocupante_player_id, es_titular_el_ocupante, cerrada,
     *         fechas_faltantes_liberacion,
     *         fechas_faltantes_liberacion_indeterminado }, ... ] }
     *
     * `ocupante_player_id` is null only when the plaza somehow has no vigent
     * ocupación (should not happen once PlazaRepository::openPlaza() has
     * run, but this endpoint does not assume it — see
     * PlazaRepository::findOcupacionVigente()'s own docblock).
     *
     * `fechas_faltantes_liberacion` is null in TWO distinct cases, both
     * still an honest "unknown", never a fabricated 0 — `fechas_faltantes_
     * liberacion_indeterminado` tells them apart:
     *   - `indeterminado: false` — the plaza's own ocupaciones chain could
     *     not be READ (PlazaRepository::listOcupaciones() reads a wpdb-level
     *     failure as "no rows" rather than throwing — see that method's
     *     docblock, "WHY listOcupaciones() ITSELF WAS NOT CHANGED").
     *   - `indeterminado: true` — the chain WAS read, but the liberation
     *     count itself could not be trusted (CadenaResolver's own injected
     *     counter threw `FechaCountUnavailableException`) — see this class's
     *     docblock, "THIS IS A READ", for why that failure degrades only
     *     THIS plaza's row instead of failing the whole response.
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
     * GET /entre-redes/v1/cambios/plazas/candidatos?season_id=..&team_id=..&plaza_id=..[&incluir_no_viables=1][&search=..]
     *
     * Response 200: { candidatos: [ { player_id, nombre, es_padre, puntaje,
     *         viable, motivo }, ... ] }
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
     * *** FILTERING HAPPENS HERE, NEVER IN CandidatosResolver ***
     * `paraPlaza()` stays the single, unfiltered source of truth (see its
     * own docblock) — `Reglas\PrioridadDePadresRespetada` needs that FULL
     * pool to count viable padres, so `CandidatosResolver` itself must never
     * change to accommodate this endpoint's own presentation needs. Instead:
     *   - By DEFAULT, only VIABLE candidates are returned — a captain cannot
     *     act on a non-viable one, and a season's full candidate pool can run
     *     into the hundreds (see CandidatosResolver's own docblock, "COST:
     *     THIS IS N+1 BY DESIGN"), most of it not actionable.
     *   - `?incluir_no_viables=1` opts back into the FULL list, `viable` and
     *     `motivo` intact — for the committee's own tooling, which may want
     *     to see WHY someone was excluded.
     *   - `?search=<text>` narrows whatever set the two rules above already
     *     produced to names containing $text, case-insensitively.
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

            $candidatos = $this->candidatosResolver->paraPlaza( $plaza, $this->politicaCC5b, $countResolvedFechasSinceFn );

            $incluirNoViables = '1' === (string) $request->get_param( 'incluir_no_viables' );

            $candidatos = array_values( array_filter(
                $candidatos,
                static fn ( CandidatoEstado $c ): bool => $incluirNoViables || $c->viable()
            ) );

            // Names are primed for exactly the set that survived the
            // viable/incluir_no_viables filter above — the only ids this
            // response could still need, whether to search against or to
            // finally shape. See primePlayerTitles()'s own docblock.
            $this->primePlayerTitles( array_map(
                static fn ( CandidatoEstado $c ): int => $c->playerId(),
                $candidatos
            ) );

            $search = trim( (string) ( $request->get_param( 'search' ) ?? '' ) );

            if ( '' !== $search ) {
                $candidatos = array_values( array_filter(
                    $candidatos,
                    fn ( CandidatoEstado $c ): bool => false !== mb_stripos( $this->nombreJugador( $c->playerId() ), $search )
                ) );
            }

            return new \WP_REST_Response(
                [ 'candidatos' => array_map( [ $this, 'shapeCandidato' ], $candidatos ) ],
                200
            );
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
        ];
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
            'tipo'                                        => (string) $plaza['tipo'],
            'titular_player_id'                           => (int) $plaza['titular_player_id'],
            'titular_nombre'                              => $this->nombreJugador( (int) $plaza['titular_player_id'] ),
            'ocupante_player_id'                          => null !== $vigente ? (int) $vigente['player_id'] : null,
            'ocupante_nombre'                              => null !== $vigente ? $this->nombreJugador( (int) $vigente['player_id'] ) : null,
            'es_titular_el_ocupante'                      => null !== $vigente
                && (int) $vigente['player_id'] === (int) $plaza['titular_player_id'],
            'cerrada'                                      => null !== $plaza['closed_at'],
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
     * The per-plaza degradation this class's docblock describes
     * ("A SINGLE PLAZA'S CALCULATION FAILING DOES NOT FAIL THE WHOLE
     * RESPONSE"). An empty $ocupaciones chain is the pre-existing "no rows
     * read" case (PlazaRepository::listOcupaciones()'s own docblock) — still
     * an honest `null`, not this method's concern to log, since nothing was
     * even attempted. A THROWN FechaCountUnavailableException is different:
     * something WAS attempted and could not be trusted, so it is logged here
     * — once, with the plaza id — before degrading, exactly like every other
     * failure path in this plugin logs before answering conservatively.
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
