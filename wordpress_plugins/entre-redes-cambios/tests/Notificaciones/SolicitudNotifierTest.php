<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Notificaciones;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\Motivo;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Notificaciones\SolicitudNotifier;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * The send function is INJECTED in every test here. Against the real
 * wp_mail() these tests would prove nothing: the shim has no mailer, so
 * every call would quietly succeed and every assertion would pass for the
 * wrong reason.
 */
class SolicitudNotifierTest extends TestCase {

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    private PlazaRepository $plazaRepository;
    private SolicitudRepository $solicitudRepository;
    private InMemoryEventLog $eventLog;
    private Settings $settings;

    /** @var array<int, array{para: string, asunto: string, cuerpo: string}> */
    private array $enviados = [];

    protected function setUp(): void {
        global $wpdb, $wp_test_post_titles;

        InitialSchema::up();
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_settings' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        InitialSchema::up();

        $wp_test_post_titles = [
            self::TEAM_ID => 'SUIZA',
            777           => 'Titular Perez',
            888           => 'Suplente Gomez',
            999           => 'Otro Lopez',
        ];

        $this->eventLog        = new InMemoryEventLog();
        $this->plazaRepository = new PlazaRepository( $wpdb, $this->eventLog );
        $this->settings        = new Settings( $wpdb, $this->eventLog );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            new FechaRepository( $wpdb, new InMemoryEventLog() ),
            $this->settings,
            $wpdb,
            $this->eventLog
        );

        $this->solicitudRepository = new SolicitudRepository(
            $wpdb,
            $this->plazaRepository,
            new DictamenPipeline( $assembler, $this->eventLog ),
            $this->eventLog
        );

        $this->enviados = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_settings' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        unset( $GLOBALS['wp_test_post_titles'], $GLOBALS['_cambios_test_mails'] );
        $GLOBALS['wp_test_position_terms'] = [];
    }

    // -------------------------------------------------------------------------
    // The switch
    // -------------------------------------------------------------------------

    public function test_apagado_no_manda_nada(): void {
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertSame( [], $this->enviados, 'OFF by default — nothing must leave.' );
    }

    /**
     * The switch on its OWN, with a perfectly good address configured.
     * Without this, deleting the switch check entirely still sends nothing
     * — the empty-address guard below it catches the default case — so the
     * test above passes while the switch does nothing.
     */
    public function test_apagado_no_manda_nada_aunque_haya_destino(): void {
        $this->configurar( false, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertSame( [], $this->enviados, 'The switch must decide, not the presence of an address.' );
        $this->assertFalse( $this->eventLog->has( 'notificacion.sin_destino_fallida' ) );
    }

    public function test_prendido_manda_un_mail_al_destino_configurado(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertCount( 1, $this->enviados );
        $this->assertSame( 'fernando@ejemplo.com', $this->enviados[0]['para'] );
        $this->assertTrue( $this->eventLog->has( 'notificacion.enviada' ) );
    }

    public function test_prendido_sin_destino_no_manda_y_deja_rastro(): void {
        // Only reachable by editing the row by hand — the settings screen
        // refuses this combination — but a mail to nowhere must leave a
        // record of why none arrived.
        $this->configurar( true, '' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertSame( [], $this->enviados );
        $this->assertTrue( $this->eventLog->has( 'notificacion.sin_destino_fallida' ) );
    }

    // -------------------------------------------------------------------------
    // A mail failure must never cost the request
    // -------------------------------------------------------------------------

    public function test_si_el_envio_explota_no_propaga_y_lo_registra(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $notifier = $this->notifier( static function (): bool {
            throw new \RuntimeException( 'SMTP timeout' );
        } );

        // The assertion IS that this line completes: the solicitud is
        // already stored, and throwing here would tell a captain their
        // request failed when it did not.
        $notifier->notificarCreada( $id );

        $this->assertTrue( $this->eventLog->has( 'notificacion.fallida' ) );
    }

    public function test_si_el_envio_devuelve_false_lo_registra_como_fallido(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier( static fn (): bool => false )->notificarCreada( $id );

        $this->assertTrue( $this->eventLog->has( 'notificacion.envio_fallido' ) );
        $this->assertFalse( $this->eventLog->has( 'notificacion.enviada' ) );
    }

    public function test_una_solicitud_inexistente_no_explota(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );

        $this->notifier()->notificarCreada( 424242 );

        $this->assertSame( [], $this->enviados );
        $this->assertTrue( $this->eventLog->has( 'notificacion.solicitud_inexistente_fallida' ) );
    }

    // -------------------------------------------------------------------------
    // What the mail says — it must be triageable without opening the tray
    // -------------------------------------------------------------------------

    public function test_el_cuerpo_nombra_equipo_movimiento_fecha_y_accion_sugerida(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( 'SUIZA', $cuerpo );
        $this->assertStringContainsString( 'Sale Titular Perez, entra Suplente Gomez', $cuerpo );
        $this->assertStringContainsString( 'Fecha: 1', $cuerpo );
        $this->assertStringContainsString( 'Acción sugerida', $cuerpo );
        $this->assertStringContainsString( 'page=cambios-bandeja', $cuerpo, 'The link is what turns an alert into an action.' );
    }

    public function test_el_asunto_lleva_el_numero_y_el_equipo(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertStringContainsString( '#' . $id, $this->enviados[0]['asunto'] );
        $this->assertStringContainsString( 'SUIZA', $this->enviados[0]['asunto'] );
    }

    public function test_un_dictamen_que_no_procede_lleva_sus_motivos(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );

        $id = $this->crearSustitucion( Dictamen::from( [
            new Motivo( 'puntaje_fuera_del_techo', 'El puntaje supera el techo de la plaza.', [] ),
        ] ) );

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( 'Acción sugerida: rechazar.', $cuerpo );
        $this->assertStringContainsString( 'El puntaje supera el techo de la plaza.', $cuerpo );
    }

    /**
     * A grouped reasignación is ONE request with two movements, so it is ONE
     * email describing both — never two that read as two separate requests.
     */
    public function test_un_pedido_agrupado_manda_un_solo_mail_con_los_dos_movimientos(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $this->seedFecha( 1 );

        // es_arco is DERIVED from the titular's sp_position at openPlaza
        // time — term 3 is the goalkeeper, same convention as
        // SolicitudRepositoryReasignacionArqueroTest.
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 777 => [ 3 ] ];
        $plazaArco              = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 4.0 ), 1, '2026-03-01 00:00:00' );

        $wp_test_position_terms = [];
        $plazaCampo             = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 888, Puntaje::fromDecimal( 4.0 ), 1, '2026-03-01 00:00:00' );

        $id = $this->solicitudRepository->crearReasignacionArquero(
            self::SEASON_ID,
            self::TEAM_ID,
            $plazaArco,
            888,
            $plazaCampo,
            999,
            1,
            time(),
            777,
            '2026-05-27 10:00:00'
        );

        $this->notifier()->notificarCreada( $id );

        $this->assertCount( 1, $this->enviados, 'One request, one email.' );

        $cuerpo = $this->enviados[0]['cuerpo'];
        $this->assertStringContainsString( 'Arco —', $cuerpo );
        $this->assertStringContainsString( 'Campo —', $cuerpo );
        $this->assertStringContainsString( 'Otro Lopez', $cuerpo, 'The field movement names who comes in.' );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function notifier( ?callable $mailFn = null ): SolicitudNotifier {
        return new SolicitudNotifier(
            $this->settings,
            $this->solicitudRepository,
            $this->plazaRepository,
            $this->eventLog,
            $mailFn ?? function ( string $para, string $asunto, string $cuerpo ): bool {
                $this->enviados[] = [ 'para' => $para, 'asunto' => $asunto, 'cuerpo' => $cuerpo ];

                return true;
            }
        );
    }

    private function configurar( bool $activa, string $destino ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}cambios_settings SET setting_value = %s WHERE setting_key = 'notificacion_nueva_activa'",
                $activa ? '1' : '0'
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$p}cambios_settings SET setting_value = %s WHERE setting_key = 'notificacion_nueva_destino'",
                $destino
            )
        );
    }

    private function crearSustitucion( ?Dictamen $dictamen = null ): int {
        $this->seedFecha( 1 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        return $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 888, 1, time() ),
            777,
            $dictamen ?? Dictamen::from( [] ),
            '2026-05-27 10:00:00'
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
                'play_date'          => '2026-03-01',
                'play_date_original' => '2026-03-01',
                'estado'             => 'programada',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }
}
