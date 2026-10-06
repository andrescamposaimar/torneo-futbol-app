<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for BandejaPage — built against REAL repositories (same
 * style as SolicitudRepositoryTest), so "aprobar doesn't touch ocupacion" and
 * "publicar el lote applies changes" are proven against the real dictamen
 * pipeline and PlazaRepository, not a stub.
 *
 * *** WHY handleAprobar() / handleRechazar() / handlePublicarLote() ARE
 * NEVER CALLED DIRECTLY HERE *** — they end in `wp_safe_redirect()` + `exit`
 * (the PRG pattern; see BandejaPage's own class docblock). A real `exit`
 * would kill the PHPUnit process mid-suite. Every test below instead either:
 *   (a) calls `handlePost()` with an input shape that CANNOT reach the
 *       exiting branch (an unauthorized request dies via `wp_die()` — which
 *       THROWS in this shim, it does not `exit` — before dispatch; or an
 *       authorized request with an unrecognized action falls through the
 *       dispatcher's `match()` default arm and returns normally), or
 *   (b) invokes a private, non-exiting core method directly via
 *       `ReflectionMethod::invoke()` — the same pattern entre-redes-prode's
 *       RegistryPageTest uses for `finalizeUnlink()`.
 */
class BandejaPageTest extends TestCase {

    private const SEASON_ID = 359;

    private PlazaRepository $plazaRepository;
    private InMemoryEventLog $eventLog;
    private SolicitudRepository $solicitudRepository;
    private BandejaPage $page;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$p}postmeta" );

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $fechaRepository       = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings              = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $fechaRepository,
            $settings,
            $wpdb,
            $this->eventLog
        );

        $pipeline                  = new DictamenPipeline( $assembler, $this->eventLog );
        $this->solicitudRepository = new SolicitudRepository( $wpdb, $this->plazaRepository, $pipeline, $this->eventLog );

        $authorizer = new ProcessOwnerAuthorizer();

        $this->page = new BandejaPage(
            $authorizer,
            $this->solicitudRepository,
            $this->plazaRepository,
            $settings,
            $this->eventLog,
            static fn (): int => strtotime( '2026-05-27 11:00:00' )
        );

        unset(
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['wp_test_wp_verify_nonce'],
            $GLOBALS['wp_test_current_user_display_name'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        unset(
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['wp_test_wp_verify_nonce'],
            $GLOBALS['wp_test_current_user_display_name'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
    }

    private function invoke( string $method, array $args = [] ): mixed {
        $ref = new \ReflectionMethod( BandejaPage::class, $method );

        return $ref->invoke( $this->page, ...$args );
    }

    // -------------------------------------------------------------------------
    // Permission — both directions (see task brief point 2: the shim used to
    // make this unprovable in either direction).
    // -------------------------------------------------------------------------

    public function test_handlePost_sin_capacidad_rechaza_y_no_toca_el_repositorio(): void {
        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = [ 'cambios_action' => 'aprobar', 'solicitud_id' => (string) $id ];

        try {
            $this->page->handlePost();
            $this->fail( 'Expected handlePost() to reject an unauthorized user.' );
        } catch ( \RuntimeException $e ) {
            // Expected — wp_die() throws in this shim.
        }

        $row = $this->solicitudRepository->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::PENDIENTE, $row['estado'], 'The repository must never be touched when authorization fails.' );
        $this->assertTrue( $this->eventLog->has( 'admin.autorizacion_denegada' ) );
    }

    public function test_handlePost_con_capacidad_pasa_el_gate_de_permiso(): void {
        $GLOBALS['wp_test_current_user_can'] = [ ProcessOwnerAuthorizer::CAPABILITY => true ];
        // An unrecognized action falls through the dispatcher's default arm
        // and returns normally — proving the permission gate was PASSED
        // without ever reaching an action that ends in exit.
        $_POST = [ 'cambios_action' => 'noop_para_test' ];

        $this->page->handlePost();

        $this->assertFalse( $this->eventLog->has( 'admin.autorizacion_denegada' ) );
    }

    // -------------------------------------------------------------------------
    // Nonce — both directions.
    // -------------------------------------------------------------------------

    public function test_nonce_invalido_rechaza(): void {
        $GLOBALS['wp_test_check_admin_referer'] = false;

        $this->expectException( \RuntimeException::class );

        $this->invoke( 'verificarNonceOMorir', [ 'cambios_aprobar', 'cambios_aprobar_nonce' ] );
    }

    public function test_nonce_valido_no_rechaza(): void {
        $GLOBALS['wp_test_check_admin_referer'] = true;

        // No exception — the assertion IS that this line completes.
        $this->invoke( 'verificarNonceOMorir', [ 'cambios_aprobar', 'cambios_aprobar_nonce' ] );

        $this->addToAssertionCount( 1 );
    }

    // -------------------------------------------------------------------------
    // Aprobar — does not touch ocupacion.
    // -------------------------------------------------------------------------

    public function test_ejecutarAprobar_cambia_el_estado_sin_tocar_ninguna_ocupacion(): void {
        $this->seedFecha( 1 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $antes   = $this->plazaRepository->findOcupacionVigente( $plazaId );

        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        $GLOBALS['wp_test_current_user_display_name'] = 'Ana Pérez';

        $resultado = $this->invoke( 'ejecutarAprobar', [ $id, 'ok' ] );

        $this->assertTrue( $resultado['ok'] );

        $row = $this->solicitudRepository->findSolicitud( $id );
        $this->assertSame( EstadoSolicitud::APROBADA, $row['estado'] );

        $despues = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( (int) $antes['id'], (int) $despues['id'] );
        $this->assertSame( (int) $antes['player_id'], (int) $despues['player_id'] );
        $this->assertCount( 1, $this->plazaRepository->listOcupaciones( $plazaId ) );

        $decisiones = $this->solicitudRepository->listDecisiones( $id );
        $this->assertCount( 1, $decisiones );
        $this->assertSame( EstadoSolicitud::APROBADA, $decisiones[0]['accion'] );
        $this->assertSame( 'Ana Pérez', $decisiones[0]['decidida_por_nombre'] );
    }

    // -------------------------------------------------------------------------
    // Publicar el lote — applies changes; an abort shows the culprit_id.
    // -------------------------------------------------------------------------

    public function test_ejecutarPublicarLote_aplica_los_cambios(): void {
        $this->seedFecha( 1 );
        $this->seedFecha( 5 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $this->instanteEnPlazo() );
        $dictamen  = $this->evaluatePipeline( $solicitud );
        $id        = $this->solicitudRepository->crear( $solicitud, 777, $dictamen, '2026-05-27 10:00:00' );
        $this->solicitudRepository->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $resultado = $this->invoke( 'ejecutarPublicarLote', [ [ $id ], true, 42, 'Proceso Owner Test' ] );

        $this->assertFalse( $resultado['abortado'] );
        $this->assertSame( [ $id ], $resultado['publicadas'] );

        $vigente = $this->plazaRepository->findOcupacionVigente( $plazaId );
        $this->assertSame( 888, (int) $vigente['player_id'], 'The lote must actually apply the ocupacion change.' );

        $decisiones = $this->solicitudRepository->listDecisiones( $id );
        $this->assertSame( [ EstadoSolicitud::APROBADA, EstadoSolicitud::PUBLICADA ], array_column( $decisiones, 'accion' ) );
    }

    public function test_ejecutarPublicarLote_sin_confirmacion_no_aplica_nada(): void {
        $this->seedFecha( 1 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );
        $this->solicitudRepository->aprobar( $id, 42, null, '2026-05-27 11:00:00', 'Proceso Owner Test' );

        $resultado = $this->invoke( 'ejecutarPublicarLote', [ [ $id ], false, 42, 'Proceso Owner Test' ] );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertFalse( $resultado['confirmado'] );
        $this->assertNull( $resultado['culprit_id'] );

        $this->assertSame( EstadoSolicitud::APROBADA, $this->solicitudRepository->findSolicitud( $id )['estado'] );
        $this->assertCount( 1, $this->solicitudRepository->listDecisiones( $id ), 'No "publicada" decision should exist without explicit confirmation.' );
    }

    public function test_ejecutarPublicarLote_que_aborta_expone_el_culprit_id(): void {
        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 1, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );
        // Deliberately left `pendiente` — never approved — so publicarLote()
        // rejects the transition and aborts with THIS id as the culprit.

        $resultado = $this->invoke( 'ejecutarPublicarLote', [ [ $id ], true, 42, 'Proceso Owner Test' ] );

        $this->assertTrue( $resultado['abortado'] );
        $this->assertSame( $id, $resultado['culprit_id'] );

        $mensaje = $this->invoke( 'culpritMensaje', [ $resultado ] );
        $this->assertStringContainsString( '#' . $id, $mensaje );
        $this->assertStringContainsString( $resultado['motivo'], $mensaje );
    }

    public function test_culpritMensaje_es_null_cuando_el_lote_no_aborto(): void {
        $resultado = [
            'publicadas'    => [ 1 ],
            'no_publicadas' => [],
            'abortado'      => false,
            'motivo'        => null,
            'divergencias'  => [],
            'culprit_id'    => null,
        ];

        $this->assertNull( $this->invoke( 'culpritMensaje', [ $resultado ] ) );
    }

    // -------------------------------------------------------------------------
    // shapeSolicitud() — grouped goalkeeper reassignment (0.1.15)
    // -------------------------------------------------------------------------

    /**
     * The tray must show BOTH movements of a grouped request clearly enough
     * that the process owner can judge the whole move, not half of it — see
     * BandejaPage's class docblock reference to the committee's tray in the
     * task brief. `shapeSolicitud()` is the data this test pins;
     * `renderFilaSolicitud()` only decides how to print it.
     */
    public function test_shape_solicitud_expone_ambos_movimientos_de_una_reasignacion_de_arquero(): void {
        $this->seedFecha( 1 );
        $this->seedFecha( 5 );

        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ];
        $plazaArcoId            = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-03-01 00:00:00' );

        $wp_test_position_terms = [];
        $plazaCampoId           = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $wp_test_position_terms = [];

        $this->seedPuntaje( 222, 3.0 );
        $this->seedPuntaje( 333, 2.5 );

        $id = $this->solicitudRepository->crearReasignacionArquero(
            self::SEASON_ID, 100, $plazaArcoId, 222, $plazaCampoId, 333, 5, $this->instanteEnPlazo(), 777, '2026-05-27 10:00:00'
        );

        $row = $this->solicitudRepository->findSolicitud( $id );

        $ref = new \ReflectionMethod( BandejaPage::class, 'shapeSolicitud' );
        $shaped = $ref->invoke( $this->page, $row );

        $this->assertTrue( $shaped['es_grupo'] );
        $this->assertSame( 111, $shaped['quien_sale_id'], 'Movement 1: the current goalkeeper leaves.' );
        $this->assertSame( 222, $shaped['quien_entra_id'], 'Movement 1: the field titular enters the goal.' );
        $this->assertSame( 222, $shaped['quien_sale_campo_id'], 'Movement 2: the SAME titular leaves the field plaza.' );
        $this->assertSame( 333, $shaped['quien_entra_campo_id'], 'Movement 2: the outside player enters it.' );

        $wp_test_position_terms = [];
    }

    public function test_shape_solicitud_no_marca_es_grupo_para_una_sustitucion_ordinaria(): void {
        $this->seedFecha( 1 );
        $this->seedFecha( 5 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->seedPuntaje( 888, 2.5 );

        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazaId, 888, 5, $this->instanteEnPlazo() );
        $id        = $this->solicitudRepository->crear( $solicitud, 777, $this->evaluatePipeline( $solicitud ), '2026-05-27 10:00:00' );

        $row    = $this->solicitudRepository->findSolicitud( $id );
        $ref    = new \ReflectionMethod( BandejaPage::class, 'shapeSolicitud' );
        $shaped = $ref->invoke( $this->page, $row );

        $this->assertFalse( $shaped['es_grupo'] );
        $this->assertNull( $shaped['quien_entra_campo_id'] );
    }

    /**
     * THE point of the whole fix (see Dictamen\DictamenPipeline's class
     * docblock, "EACH MOTIVO IS TAGGED WITH WHICH LEG PRODUCED IT"): a
     * rejected GROUPED request's tray must show which movement each motivo
     * belongs to, not a single pooled list. `fuera_de_plazo` is deliberately
     * the SAME codigo on both legs here (both movements share one
     * `$instanteEpoch`) — a naive "pool everything" render would print it
     * twice with no way to tell them apart; this pins that the "Arco" group
     * and the "Campo" group each get their OWN copy.
     */
    public function test_render_fila_solicitud_agrupa_los_motivos_de_un_grupo_rechazado_por_movimiento(): void {
        $this->seedFecha( 1 );
        $this->seedFecha( 5 );

        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ];
        $plazaArcoId            = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-03-01 00:00:00' );

        $wp_test_position_terms = [];
        $plazaCampoId           = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $wp_test_position_terms = [];

        $this->seedPuntaje( 222, 3.0 );
        $this->seedPuntaje( 333, 2.5 );

        // WAY before the fecha's apertura window — both legs share this
        // instant, so BOTH independently fail SolicitudEnPlazo with the
        // SAME codigo, 'fuera_de_plazo'.
        $instanteFueraDePlazo = strtotime( '2026-01-01 00:00:00' );

        $id = $this->solicitudRepository->crearReasignacionArquero(
            self::SEASON_ID, 100, $plazaArcoId, 222, $plazaCampoId, 333, 5, $instanteFueraDePlazo, 777, '2026-05-27 10:00:00'
        );

        $row    = $this->solicitudRepository->findSolicitud( $id );
        $shaped = ( new \ReflectionMethod( BandejaPage::class, 'shapeSolicitud' ) )->invoke( $this->page, $row );

        $this->assertFalse( $shaped['dictamen_procede'] );

        $codigos = array_map( static fn ( $m ) => $m['codigo'], $shaped['dictamen_motivos'] );
        $this->assertSame( [ 'fuera_de_plazo', 'fuera_de_plazo' ], $codigos, 'Both legs must independently reject on the same codigo.' );

        ob_start();
        ( new \ReflectionMethod( BandejaPage::class, 'renderFilaSolicitud' ) )->invoke( $this->page, $shaped, 'http://example.test/admin.php', false );
        $html = (string) ob_get_clean();

        // Looking for the exact `<strong>Arco</strong>` / `<strong>Campo</strong>`
        // GROUP HEADINGS (etiquetaMovimiento()'s markup) — NOT the plain-text
        // "Arco — Sale: ... Entra: ..." movement summary every grouped
        // request already renders regardless of this fix, which would make
        // a bare substr( 'Arco' ) search pass even with no grouping at all.
        $posArco  = strpos( $html, '<strong>Arco</strong>' );
        $posCampo = strpos( $html, '<strong>Campo</strong>' );

        $posicionesLi = [];
        $offset       = 0;
        while ( false !== ( $pos = strpos( $html, 'fuera de plazo', $offset ) ) ) {
            $posicionesLi[] = $pos;
            $offset         = $pos + 1;
        }

        $this->assertNotFalse( $posArco, 'The tray must show an "Arco" GROUP HEADING (<strong>Arco</strong>), not just the movement summary.' );
        $this->assertNotFalse( $posCampo, 'The tray must show a "Campo" GROUP HEADING (<strong>Campo</strong>), not just the movement summary.' );
        $this->assertSame( 1, substr_count( $html, '<strong>Arco</strong>' ), 'Exactly one Arco group heading — the motivos must not be pooled.' );
        $this->assertSame( 1, substr_count( $html, '<strong>Campo</strong>' ), 'Exactly one Campo group heading — the motivos must not be pooled.' );
        $this->assertStringNotContainsString( 'Otros motivos', $html, 'Every motivo here IS attributed — none should fall into the "no attribution" catch-all group.' );
        $this->assertCount( 2, $posicionesLi, 'Both legs\' fuera_de_plazo motivo must be rendered, once each.' );
        $this->assertLessThan( $posicionesLi[0], $posArco, 'The Arco heading must come before its own motivo.' );
        $this->assertLessThan( $posicionesLi[1], $posCampo, 'The Campo heading must come before its own motivo.' );
        $this->assertLessThan( $posCampo, $posArco, 'Arco is rendered before Campo.' );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function seedFecha( int $fechaId ): void {
        global $wpdb;

        $playDate = 1 === $fechaId ? '2026-05-16' : '2026-05-30';

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => self::SEASON_ID,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    private function seedPuntaje( int $playerId, float $puntaje ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'postmeta',
            [
                'post_id'    => $playerId,
                'meta_key'   => 'sp_metrics',
                'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ),
            ]
        );
    }

    private function instanteEnPlazo(): int {
        // Comfortably inside every plazo window for a fecha played 2026-05-30
        // — mirrors SolicitudRepositoryTest::instanteEnPlazo() at a fixed
        // instant, since this test file does not depend on Settings/plazos
        // precision the way that suite does.
        return strtotime( '2026-05-25 12:00:00' );
    }

    private function evaluatePipeline( SolicitudDeCambio $solicitud ): Dictamen {
        global $wpdb;

        $eventLog  = new InMemoryEventLog();
        $fechaRepo = new FechaRepository( $wpdb, $eventLog );
        $settings  = new Settings( $wpdb, $eventLog );
        $assembler = new DictamenContextAssembler( $this->plazaRepository, $fechaRepo, $settings, $wpdb, $eventLog );
        $pipeline  = new DictamenPipeline( $assembler, $eventLog );

        return $pipeline->evaluate( $solicitud );
    }
}
