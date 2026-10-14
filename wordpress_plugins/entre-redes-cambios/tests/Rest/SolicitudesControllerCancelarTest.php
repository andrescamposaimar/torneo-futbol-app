<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Rest;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanAuthorizer;
use EntreRedes\Cambios\Capitania\Exception\NotCaptainException;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Rest\SolicitudesController;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use EntreRedes\Cambios\Tests\Support\InMemoryCreationLock;
use PHPUnit\Framework\TestCase;

/**
 * `POST /cambios/solicitudes/{id}/cancelar`.
 *
 * The two that matter most are the negative ones. An open request locks its
 * plaza and its incoming player, so an endpoint that cancels the WRONG
 * request would unlock somebody else's — and one that distinguishes "not
 * found" from "not yours" is a probe for other teams' request ids.
 */
class SolicitudesControllerCancelarTest extends TestCase {

    private const SEASON_ID  = 359;
    private const TEAM_ID    = 100;
    private const OTHER_TEAM = 200;

    private InMemoryEventLog $eventLog;
    private PlazaRepository $plazaRepository;
    private SolicitudRepository $solicitudRepository;
    private SolicitudesController $controller;
    private int $fixedNow;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_post_titles;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wp_test_post_titles = [ 777 => 'Capitan Suarez' ];

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $fechaRepository       = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings              = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler( $this->plazaRepository, $fechaRepository, $settings, $wpdb, $this->eventLog );
        $pipeline  = new DictamenPipeline( $assembler, $this->eventLog );

        $this->solicitudRepository = new SolicitudRepository( $wpdb, $this->plazaRepository, $pipeline, $this->eventLog );

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willReturn( [ 'player_id' => 777 ] );

        $this->fixedNow   = ( new \DateTimeImmutable( '2026-05-20 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->controller = new SolicitudesController(
            $authorizer,
            $this->solicitudRepository,
            $pipeline,
            $this->eventLog,
            $this->plazaRepository,
            new JugadorMetricasReader( $wpdb, $this->eventLog ),
            fn (): int => $this->fixedNow,
            new InMemoryCreationLock()
        );

        $this->seedFecha( 1 );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        unset( $GLOBALS['wp_test_post_titles'] );
    }

    // -------------------------------------------------------------------------
    // The happy path, and what it frees
    // -------------------------------------------------------------------------

    public function test_cancela_una_pendiente_propia(): void {
        $id = $this->crearPendiente();

        $respuesta = $this->cancelar( $id );

        $this->assertSame( 200, $respuesta->get_status() );
        $this->assertSame( EstadoSolicitud::ANULADA, $respuesta->get_data()['estado'] );
        $this->assertSame( EstadoSolicitud::ANULADA, $this->solicitudRepository->findSolicitud( $id )['estado'] );
    }

    /**
     * THE point of the feature: an open request locks its plaza and its
     * incoming player, so until this existed a captain who picked the wrong
     * name could not file the right one.
     */
    public function test_cancelar_libera_la_plaza_para_un_pedido_nuevo(): void {
        $plazaId = $this->abrirPlaza( self::TEAM_ID, 777 );
        $id      = $this->crearPendiente( $plazaId, 888 );

        $this->cancelar( $id );

        // The same plaza again, with the name he meant.
        $segundo = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 999, 1, $this->fixedNow ),
            777,
            Dictamen::from( [] ),
            '2026-05-20 12:00:00'
        );

        $this->assertGreaterThan( 0, $segundo, 'A cancelled request must not keep holding the plaza.' );
    }

    public function test_el_rastro_dice_que_lo_cancelo_el_capitan(): void {
        $id = $this->crearPendiente();

        $this->cancelar( $id );

        $decisiones = $this->solicitudRepository->listDecisiones( $id );
        $ultima     = end( $decisiones );

        $this->assertSame( EstadoSolicitud::ANULADA, $ultima['accion'] );
        $this->assertSame(
            'Capitán Capitan Suarez',
            $ultima['decidida_por_nombre'],
            'Nobody reading the histórico later should mistake this for a decision the comisión took.'
        );
    }

    // -------------------------------------------------------------------------
    // What it must refuse
    // -------------------------------------------------------------------------

    /**
     * An approved request is the comisión's to undo — the tray has its own
     * button. A captain cancelling one would also silently change what
     * Friday's lote contains.
     */
    public function test_no_cancela_una_aprobada(): void {
        $id = $this->crearPendiente();
        $this->solicitudRepository->aprobar( $id, 1, null, '2026-05-20 12:00:00', 'La comision' );

        $respuesta = $this->cancelar( $id );

        $this->assertSame( 409, $respuesta->get_status() );
        $this->assertSame( 'solicitud_ya_resuelta', $respuesta->get_data()['code'] );
        $this->assertSame( EstadoSolicitud::APROBADA, $this->solicitudRepository->findSolicitud( $id )['estado'] );
    }

    public function test_no_cancela_una_ya_rechazada(): void {
        $id = $this->crearPendiente();
        $this->solicitudRepository->rechazar( $id, 1, null, '2026-05-20 12:00:00', 'La comision' );

        $this->assertSame( 409, $this->cancelar( $id )->get_status() );
    }

    /**
     * An id belonging to another team must answer EXACTLY like an id that
     * does not exist. Telling them apart turns this endpoint into a probe
     * for other teams' request ids.
     */
    public function test_no_cancela_la_de_otro_equipo_y_responde_igual_que_si_no_existiera(): void {
        $plazaAjena = $this->abrirPlaza( self::OTHER_TEAM, 555 );

        $ajena = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::OTHER_TEAM, $plazaAjena, 666, 1, $this->fixedNow ),
            555,
            Dictamen::from( [] ),
            '2026-05-20 12:00:00'
        );

        $deOtroEquipo = $this->cancelar( $ajena );
        $inexistente  = $this->cancelar( 424242 );

        $this->assertSame( 404, $deOtroEquipo->get_status() );
        $this->assertSame( $inexistente->get_status(), $deOtroEquipo->get_status() );
        $this->assertSame( $inexistente->get_data()['code'], $deOtroEquipo->get_data()['code'] );
        $this->assertSame( $inexistente->get_data()['message'], $deOtroEquipo->get_data()['message'] );

        $this->assertSame(
            EstadoSolicitud::PENDIENTE,
            $this->solicitudRepository->findSolicitud( $ajena )['estado'],
            'And above all: it must still be standing.'
        );
    }

    public function test_sin_autorizacion_no_cancela(): void {
        global $wpdb;

        $id = $this->crearPendiente();

        $authorizer = $this->createMock( CapitanAuthorizer::class );
        $authorizer->method( 'authorize' )->willThrowException( new NotCaptainException() );

        $controller = new SolicitudesController(
            $authorizer,
            $this->solicitudRepository,
            new DictamenPipeline(
                new DictamenContextAssembler(
                    $this->plazaRepository,
                    new FechaRepository( $wpdb, new InMemoryEventLog() ),
                    new Settings( $wpdb, $this->eventLog ),
                    $wpdb,
                    $this->eventLog
                ),
                $this->eventLog
            ),
            $this->eventLog,
            $this->plazaRepository,
            new JugadorMetricasReader( $wpdb, $this->eventLog ),
            fn (): int => $this->fixedNow,
            new InMemoryCreationLock()
        );

        $respuesta = $controller->cancelar( $this->request( $id ) );

        $this->assertGreaterThanOrEqual( 400, $respuesta->get_status() );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $this->solicitudRepository->findSolicitud( $id )['estado'] );
        $this->assertTrue( $this->eventLog->has( 'rest.autorizacion_denegada' ) );
    }

    public function test_campos_invalidos(): void {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer a-valid-jwt' );
        $request->set_param( 'season_id', self::SEASON_ID );
        $request->set_param( 'team_id', 0 );
        $request->set_param( 'id', 1 );

        $this->assertSame( 400, $this->controller->cancelar( $request )->get_status() );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function cancelar( int $id ): \WP_REST_Response {
        return $this->controller->cancelar( $this->request( $id ) );
    }

    private function request( int $id ): \WP_REST_Request {
        $request = new \WP_REST_Request();
        $request->set_header( 'authorization', 'Bearer a-valid-jwt' );
        $request->set_param( 'season_id', self::SEASON_ID );
        $request->set_param( 'team_id', self::TEAM_ID );
        $request->set_param( 'id', $id );

        return $request;
    }

    private function crearPendiente( ?int $plazaId = null, int $entrante = 888 ): int {
        $plazaId = $plazaId ?? $this->abrirPlaza( self::TEAM_ID, 777 );

        return $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, $entrante, 1, $this->fixedNow ),
            777,
            Dictamen::from( [] ),
            '2026-05-20 12:00:00'
        );
    }

    private function abrirPlaza( int $teamId, int $titular ): int {
        return $this->plazaRepository->openPlaza(
            self::SEASON_ID,
            $teamId,
            $titular,
            Puntaje::fromDecimal( 3.0 ),
            1,
            '2026-01-01 00:00:00'
        );
    }

    private function seedFecha( int $fechaId ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => self::SEASON_ID,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => '2026-05-23',
                'play_date_original' => '2026-05-23',
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }
}
