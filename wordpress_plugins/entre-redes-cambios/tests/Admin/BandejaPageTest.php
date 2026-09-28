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
        $settings              = new Settings( $wpdb );

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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );
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
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 00:00:00' );

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
        $settings  = new Settings( $wpdb );
        $assembler = new DictamenContextAssembler( $this->plazaRepository, $fechaRepo, $settings, $wpdb, $eventLog );
        $pipeline  = new DictamenPipeline( $assembler, $eventLog );

        return $pipeline->evaluate( $solicitud );
    }
}
