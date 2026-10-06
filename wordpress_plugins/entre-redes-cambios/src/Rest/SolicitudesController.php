<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Rest;

use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\AuthorizationDeniedException;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;

/**
 * REST controller for the CAPTAIN-facing solicitud endpoints:
 *
 *   POST /entre-redes/v1/cambios/solicitudes — create a solicitud, dictaminate
 *        it immediately with DictamenPipeline, and hand the dictamen back so
 *        the captain sees BEFORE anyone approves anything whether their
 *        request clears every rule, and if not, exactly why.
 *   GET  /entre-redes/v1/cambios/solicitudes  — every solicitud the captain's
 *        team has made, with its estado, its ORIGINAL dictamen snapshot, and
 *        (added for the "Mis Solicitudes" sale/entra display — see
 *        shapeLado()'s own docblock) who leaves and who comes in.
 *
 * The process owner's tray (approve/reject/publish the lote) is a later
 * slice's job — see this plugin's task brief for slice 4d. Nothing here ever
 * calls SolicitudRepository::aprobar() / ::rechazar() / ::publicarLote().
 *
 * *** AUTHORIZATION RUNS FIRST, ALWAYS ***
 * Every handler below calls authorizeCapitan() (see
 * Rest\HandlesCapitanAuthorization) BEFORE touching SolicitudRepository or
 * DictamenPipeline — an unauthorized caller never reaches either, and the
 * 403 response is IDENTICAL regardless of which of the three
 * AuthorizationDeniedException subtypes fired (see that trait's docblock).
 * The REAL reason is logged via EventLog before the generic response is
 * built.
 *
 * *** A DICTAMEN THAT DOES NOT PROCEDE IS NOT AN ERROR ***
 * crear() below stores — and returns — a Dictamen exactly as
 * SolicitudRepository::crear() itself is documented to: it does NOT gate on
 * `$dictamen->procede()`. A rejecting dictamen is still a SUCCESSFUL 200
 * response, carrying the motivos the captain needs to understand why; only
 * an actual \Throwable (an assembler failure, a persistence failure) is
 * turned into the generic 500 — see HandlesCapitanAuthorization's own
 * docblock.
 */
class SolicitudesController {

    use HandlesCapitanAuthorization;

    private CapitanAuthorizer $authorizer;
    private SolicitudRepository $solicitudRepository;
    private DictamenPipeline $dictamenPipeline;
    private EventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private JugadorMetricasReader $jugadorMetricasReader;

    /** @var callable(): int */
    private $clockFn;

    /**
     * This PAGE's (i.e. this ONE `listar()` call's) player_id => JugadorMetricas,
     * resolved ONCE via `JugadorMetricasReader::resolveMuchos()` right before
     * `shapeSolicitudRow()` runs for every row, and read back inside
     * `shapeLado()` — same batching discipline as
     * `Rest\PlazasController::$posicionesPorJugador`. Reset at the start of
     * every `listar()` call.
     *
     * @var array<int, \EntreRedes\Cambios\Plazas\JugadorMetricas>
     */
    private array $metricasPorJugador = [];

    /**
     * @param PlazaRepository $plazaRepository Needed to resolve the plaza's
     *        `titular_player_id` for a `regreso` row's "entra" side — see
     *        `shapeLado()`'s own docblock for why a `regreso` derives this
     *        from the plaza, never from a stored `entrante_player_id` (which
     *        is always NULL for that `tipo`).
     * @param JugadorMetricasReader $jugadorMetricasReader Resolves puntaje
     *        for every "sale"/"entra" player on the page, batched — see
     *        `listar()`'s own docblock, "BATCHED, NEVER ONE QUERY PER ROW".
     * @param callable(): int|null $clockFn Returns the current instant as a
     *        Unix epoch. Defaults to `time()` — every production call site
     *        gets the real clock without having to say so. Tests inject a
     *        fixed instant instead, so a fixture like "3 days from now" never
     *        again has to be computed relative to whatever day the suite
     *        actually runs (see SolicitudesControllerTest's class docblock
     *        for the flakiness that discipline replaces).
     */
    public function __construct(
        CapitanAuthorizer $authorizer,
        SolicitudRepository $solicitudRepository,
        DictamenPipeline $dictamenPipeline,
        EventLog $eventLog,
        PlazaRepository $plazaRepository,
        JugadorMetricasReader $jugadorMetricasReader,
        ?callable $clockFn = null
    ) {
        $this->authorizer            = $authorizer;
        $this->solicitudRepository   = $solicitudRepository;
        $this->dictamenPipeline      = $dictamenPipeline;
        $this->eventLog              = $eventLog;
        $this->plazaRepository       = $plazaRepository;
        $this->jugadorMetricasReader = $jugadorMetricasReader;
        $this->clockFn               = $clockFn ?? static fn (): int => time();
    }

    public function register_routes(): void {
        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/solicitudes',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'crear' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            RestController::API_NAMESPACE,
            '/' . RestController::BASE . '/solicitudes',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'listar' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    /**
     * POST /entre-redes/v1/cambios/solicitudes
     *
     * Body: { season_id, team_id, plaza_id, tipo: 'sustitucion'|'regreso',
     *         fecha_id, entrante_player_id? } — entrante_player_id is
     *         REQUIRED for 'sustitucion' and ignored for 'regreso' (see
     *         Dictamen\SolicitudDeCambio's class docblock: who returns is
     *         never a choice this request makes).
     *
     * Response 200: { id, estado: 'pendiente', dictamen: { procede, motivos,
     *         fechas_faltantes_liberacion } } — for EVERY dictamen, favorable
     *         or not. A dictamen that does not `procede()` is deliberately
     *         NOT a 4xx — see class docblock: it is a fact the captain needs
     *         to see, not a failure of the request itself.
     */
    public function crear( \WP_REST_Request $request ): \WP_REST_Response {
        $seasonId = (int) $request->get_param( 'season_id' );
        $teamId   = (int) $request->get_param( 'team_id' );

        if ( $seasonId <= 0 || $teamId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'season_id y team_id son obligatorios y deben ser mayores a 0.'
            );
        }

        // A SINGLE instant for the whole request — authorization, the
        // solicitud's own epoch, and its persisted DATETIME representation
        // all derive from this ONE value. See class docblock and
        // Rest\HandlesCapitanAuthorization::authorizeCapitan()'s own
        // docblock for why nothing here calls time() a second time.
        $ahora = ( $this->clockFn )();

        try {
            $claims = $this->authorizeCapitan( $this->authorizer, $request, $seasonId, $teamId, $ahora );
        } catch ( AuthorizationDeniedException $e ) {
            $this->eventLog->record( 'rest.autorizacion_denegada', [
                'endpoint'  => 'POST /cambios/solicitudes',
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada( $e );
        }

        $plazaId          = (int) $request->get_param( 'plaza_id' );
        $fechaId          = (int) $request->get_param( 'fecha_id' );
        $tipo             = (string) $request->get_param( 'tipo' );
        $entrantePlayerId = $request->get_param( 'entrante_player_id' );

        if ( $plazaId <= 0 || $fechaId <= 0 ) {
            return $this->respuestaSolicitudInvalida(
                'campos_invalidos',
                'plaza_id y fecha_id son obligatorios y deben ser mayores a 0.'
            );
        }

        if ( ! in_array( $tipo, [ SolicitudDeCambio::TIPO_SUSTITUCION, SolicitudDeCambio::TIPO_REGRESO ], true ) ) {
            return $this->respuestaSolicitudInvalida(
                'tipo_invalido',
                "tipo debe ser 'sustitucion' o 'regreso'."
            );
        }

        if ( SolicitudDeCambio::TIPO_SUSTITUCION === $tipo
            && ( null === $entrantePlayerId || (int) $entrantePlayerId <= 0 )
        ) {
            return $this->respuestaSolicitudInvalida(
                'entrante_requerido',
                "entrante_player_id es obligatorio para tipo 'sustitucion'."
            );
        }

        try {
            $solicitud = SolicitudDeCambio::TIPO_SUSTITUCION === $tipo
                ? SolicitudDeCambio::sustitucion( $seasonId, $teamId, $plazaId, (int) $entrantePlayerId, $fechaId, $ahora )
                : SolicitudDeCambio::regreso( $seasonId, $teamId, $plazaId, $fechaId, $ahora );

            $dictamen = $this->dictamenPipeline->evaluate( $solicitud );

            $solicitadaPor = (int) ( $claims['player_id'] ?? 0 );

            // The SAME instant as $ahora above, in the OTHER representation
            // this row persists — `solicitada_at` (DATETIME, UTC — see
            // README) and `solicitud_instante_epoch` (Unix epoch) must
            // describe one moment, never two clock reads three hours apart.
            // `current_time('mysql')` would have handed out the site's LOCAL
            // civil time here while `$ahora` stayed UTC-epoch — see class
            // docblock.
            $ahoraDb = gmdate( 'Y-m-d H:i:s', $ahora );

            $id = $this->solicitudRepository->crear( $solicitud, $solicitadaPor, $dictamen, $ahoraDb );

            return new \WP_REST_Response(
                [
                    'id'       => $id,
                    'estado'   => EstadoSolicitud::PENDIENTE,
                    'dictamen' => $this->shapeDictamen( $dictamen ),
                ],
                200
            );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.solicitud_crear_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'plaza_id'  => $plazaId,
                'fecha_id'  => $fechaId,
                'tipo'      => $tipo,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }

    /**
     * GET /entre-redes/v1/cambios/solicitudes?season_id=..&team_id=..
     *
     * Response 200: { solicitudes: [ { id, plaza_id, tipo,
     *         entrante_player_id, fecha_id, estado, solicitada_at,
     *         resuelta_at, nota, dictamen: { procede, motivos },
     *         sale: { player_id, nombre, puntaje },
     *         entra: { player_id, nombre, puntaje } }, ... ] } — every
     *         solicitud the team has ever made, in ANY estado (see
     *         SolicitudRepository::listByEquipo()'s own docblock for why
     *         this is NOT the same subset listPendientes()/listAprobadas()
     *         expose to the process owner).
     *
     * `dictamen` here is always the ORIGINAL snapshot (`dictamen_original`)
     * — what was true the moment the captain made the request — never
     * `dictamen_aplicado`, which only exists once a solicitud is
     * `publicada` and is out of scope for this endpoint.
     *
     * *** `sale` / `entra` — RESOLVED HERE, PER `tipo`, SO THE APP NEVER HAS
     * TO KNOW THE RULE ***
     * - `sustitucion`: `sale` is the solicitud's own stored
     *   `saliente_player_id` (the plaza's vigent occupant AT THE MOMENT the
     *   solicitud was created — see `Migrations\InitialSchema::
     *   sqlCambiosSolicitud()`'s own docblock); `entra` is the stored
     *   `entrante_player_id`.
     * - `regreso`: `entrante_player_id` is always NULL (see
     *   `Dictamen\SolicitudDeCambio`'s class docblock — who returns is never
     *   a choice this request makes). `entra` is instead the plaza's
     *   PERMANENT `titular_player_id` (`Plazas\PlazaRepository::
     *   listPlazasByEquipo()`, resolved once for the whole team below —
     *   never a per-row `findPlaza()` call); `sale` is the SAME stored
     *   `saliente_player_id` as a `sustitucion` — the suplente the titular
     *   would be displacing.
     *
     * *** BATCHED, NEVER ONE QUERY PER ROW ***
     * Every player id this response needs a name or a puntaje for (every
     * `sale`/`entra` across every row) is collected FIRST, then resolved in
     * exactly one `primePlayerTitles()` call (warms `get_the_title()`'s cache
     * for the whole page) and one `JugadorMetricasReader::resolveMuchos()`
     * call — mirroring `Rest\PlazasController::listar()`'s own discipline for
     * `titular_player_id`/`ocupante_player_id`. `listPlazasByEquipo()` itself
     * is also called exactly ONCE for the whole team, never once per
     * `regreso` row.
     *
     * *** DEGRADING, NEVER FABRICATING *** See `shapeLado()`'s own docblock:
     * a `player_id` this endpoint cannot resolve (e.g. a `saliente_player_id`
     * that predates the column) becomes a `sale`/`entra` object that is
     * ENTIRELY null — never a guessed id — and a puntaje that cannot be
     * resolved is `null`, never a fabricated `0`.
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
                'endpoint'  => 'GET /cambios/solicitudes',
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
            ] );

            return $this->respuestaNoAutorizada( $e );
        }

        try {
            $rows = $this->solicitudRepository->listByEquipo( $seasonId, $teamId );

            if ( empty( $rows ) ) {
                return new \WP_REST_Response( [ 'solicitudes' => [] ], 200 );
            }

            // titular_player_id per plaza — resolved ONCE for the whole team
            // (never once per `regreso` row) — see this method's own
            // docblock, "BATCHED, NEVER ONE QUERY PER ROW".
            $titularPorPlaza = [];
            foreach ( $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId ) as $plaza ) {
                $titularPorPlaza[ (int) $plaza['id'] ] = (int) $plaza['titular_player_id'];
            }

            // Both sides of EVERY row, resolved from the row itself (never a
            // second read per row) — see shapeLado()'s own docblock for how
            // each tipo derives its pair.
            $salePorSolicitud  = [];
            $entraPorSolicitud = [];
            $playerIds         = [];

            foreach ( $rows as $row ) {
                $id = (int) $row['id'];

                $saleId = null !== $row['saliente_player_id'] ? (int) $row['saliente_player_id'] : null;

                $entraId = SolicitudDeCambio::TIPO_SUSTITUCION === $row['tipo']
                    ? ( null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null )
                    : ( $titularPorPlaza[ (int) $row['plaza_id'] ] ?? null );

                $salePorSolicitud[ $id ]  = $saleId;
                $entraPorSolicitud[ $id ] = $entraId;

                if ( null !== $saleId ) {
                    $playerIds[] = $saleId;
                }
                if ( null !== $entraId ) {
                    $playerIds[] = $entraId;
                }
            }

            $playerIds = array_values( array_unique( $playerIds ) );

            $this->primePlayerTitles( $playerIds );
            $this->metricasPorJugador = $this->jugadorMetricasReader->resolveMuchos( $playerIds );

            $solicitudes = array_map(
                fn ( array $row ): array => $this->shapeSolicitudRow(
                    $row,
                    $salePorSolicitud[ (int) $row['id'] ],
                    $entraPorSolicitud[ (int) $row['id'] ]
                ),
                $rows
            );

            return new \WP_REST_Response( [ 'solicitudes' => $solicitudes ], 200 );
        } catch ( \Throwable $e ) {
            $this->eventLog->record( 'rest.solicitudes_listar_fallida', [
                'season_id' => $seasonId,
                'team_id'   => $teamId,
                'excepcion' => get_class( $e ),
                'mensaje'   => $e->getMessage(),
            ] );

            return $this->respuestaErrorInterno();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{procede: bool, motivos: array<int, array{codigo: string, mensaje: string, datos: array<string, mixed>}>, fechas_faltantes_liberacion: int|null} */
    private function shapeDictamen( Dictamen $dictamen ): array {
        return [
            'procede' => $dictamen->procede(),
            'motivos' => array_map(
                static fn ( Motivo $motivo ): array => [
                    'codigo'  => $motivo->codigo(),
                    'mensaje' => $motivo->mensaje(),
                    'datos'   => $motivo->datos(),
                ],
                $dictamen->motivos()
            ),
            'fechas_faltantes_liberacion' => $dictamen->fechasFaltantesParaLiberacion(),
        ];
    }

    /**
     * @param array<string, mixed> $row As returned by
     *        SolicitudRepository::listByEquipo().
     * @param int|null $salePlayerId As resolved by listar() — see that
     *        method's own docblock.
     * @param int|null $entraPlayerId As resolved by listar() — see that
     *        method's own docblock.
     * @return array<string, mixed>
     */
    private function shapeSolicitudRow( array $row, ?int $salePlayerId, ?int $entraPlayerId ): array {
        $snapshot = DictamenSnapshot::fromJson( (string) $row['dictamen_original'] );

        return [
            'id'                 => (int) $row['id'],
            'plaza_id'           => (int) $row['plaza_id'],
            'tipo'               => (string) $row['tipo'],
            'entrante_player_id' => null !== $row['entrante_player_id'] ? (int) $row['entrante_player_id'] : null,
            'fecha_id'           => (int) $row['fecha_id'],
            'estado'             => (string) $row['estado'],
            'solicitada_at'      => (string) $row['solicitada_at'],
            'resuelta_at'        => $row['resuelta_at'],
            'nota'               => $row['nota'],
            'dictamen'           => [
                'procede' => $snapshot->procede(),
                'motivos' => $snapshot->motivos(),
            ],
            'sale'               => $this->shapeLado( $salePlayerId ),
            'entra'              => $this->shapeLado( $entraPlayerId ),
        ];
    }

    /**
     * Shapes ONE side ("sale" or "entra") of a solicitud row into
     * `{ player_id, nombre, puntaje }`.
     *
     * *** `$playerId === null` MEANS "NOT RECORDED" — THE WHOLE OBJECT
     * DEGRADES, NOTHING IS GUESSED ***
     * This happens for a `saliente_player_id` that predates the 0.1.11
     * column (see `Migrations\InitialSchema::sqlCambiosSolicitud()`'s own
     * docblock) or, in principle, a `regreso` whose plaza was not found in
     * `listar()`'s `titularPorPlaza` map. There is no id to attach a name or
     * a puntaje to, so every field is null — the app renders this as "not
     * recorded", never as a blank or a zero that could be mistaken for a
     * real fact (see this plugin's own task brief: "absent data rendered as
     * fact" is the exact failure mode this must avoid).
     *
     * *** `nombre` FALLS BACK TO "Jugador #<id>", NEVER NULL, ONCE AN ID IS
     * KNOWN *** Same discipline as `Rest\PlazasController::nombreJugador()`:
     * an unresolved post title is not "no name", it is "we have the id but
     * not yet a cached title" — an honest placeholder tied to the real id,
     * not a fabricated one.
     *
     * `puntaje` stays genuinely nullable — same contract as
     * `Rest\PlazasController::shapeCandidato()`'s own `puntaje` field — so
     * the app can render a name with NO brackets at all when it is unknown,
     * rather than fabricating a `[0]` or printing an empty `[]`/`[-]`.
     *
     * @return array{player_id: int|null, nombre: string|null, puntaje: float|null}
     */
    private function shapeLado( ?int $playerId ): array {
        if ( null === $playerId ) {
            return [
                'player_id' => null,
                'nombre'    => null,
                'puntaje'   => null,
            ];
        }

        $metricas = $this->metricasPorJugador[ $playerId ] ?? null;

        return [
            'player_id' => $playerId,
            'nombre'    => $this->nombreJugador( $playerId ),
            'puntaje'   => null !== $metricas && null !== $metricas->puntaje()
                ? $metricas->puntaje()->toDecimal()
                : null,
        ];
    }

    /**
     * Bulk-primes WordPress's post object cache for every id in
     * $playerIds, so the get_the_title() calls nombreJugador() makes right
     * after this hit cache instead of issuing one fresh query PER PLAYER —
     * identical to `Rest\PlazasController::primePlayerTitles()` (duplicated
     * here rather than shared: this controller has no common base class with
     * that one, and the method is a two-line wrapper around a WordPress
     * core function).
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
     *         not exist) — see `shapeLado()`'s own docblock for why this is
     *         an honest placeholder, not a fabrication. Same fallback
     *         discipline as `Rest\PlazasController::nombreJugador()`.
     */
    private function nombreJugador( int $playerId ): string {
        $titulo = trim( (string) get_the_title( $playerId ) );

        return '' !== $titulo ? $titulo : 'Jugador #' . $playerId;
    }
}
