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
 *        team has made, with its estado and its ORIGINAL dictamen snapshot.
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

    /** @var callable(): int */
    private $clockFn;

    /**
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
        ?callable $clockFn = null
    ) {
        $this->authorizer          = $authorizer;
        $this->solicitudRepository = $solicitudRepository;
        $this->dictamenPipeline    = $dictamenPipeline;
        $this->eventLog            = $eventLog;
        $this->clockFn             = $clockFn ?? static fn (): int => time();
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
     *         resuelta_at, nota, dictamen: { procede, motivos } }, ... ] } —
     *         every solicitud the team has ever made, in ANY estado (see
     *         SolicitudRepository::listByEquipo()'s own docblock for why
     *         this is NOT the same subset listPendientes()/listAprobadas()
     *         expose to the process owner).
     *
     * `dictamen` here is always the ORIGINAL snapshot (`dictamen_original`)
     * — what was true the moment the captain made the request — never
     * `dictamen_aplicado`, which only exists once a solicitud is
     * `publicada` and is out of scope for this endpoint.
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

            return new \WP_REST_Response(
                [ 'solicitudes' => array_map( [ $this, 'shapeSolicitudRow' ], $rows ) ],
                200
            );
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
     * @return array<string, mixed>
     */
    private function shapeSolicitudRow( array $row ): array {
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
        ];
    }
}
