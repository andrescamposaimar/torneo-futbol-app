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
use EntreRedes\Cambios\Notificaciones\CapitanEmailResolver;
use EntreRedes\Cambios\Notificaciones\SolicitudNotifier;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\JugadorMetricasReader;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
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

        // postmeta is NOT one of this plugin's tables, so InitialSchema does
        // not touch it and it survives between tests — a puntaje seeded by
        // one test would silently give a player a score in the next, which
        // is exactly how the "sin puntaje" case passed for the wrong reason.
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT,
                meta_value TEXT
            )"
        );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}postmeta" );

        $wp_test_post_titles = [
            self::TEAM_ID => 'SUIZA',
            777           => 'Titular Perez',
            888           => 'Suplente Gomez',
            999           => 'Otro Lopez',
            222           => 'Capitan Suarez',
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
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'cambios_settings', 'postmeta' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $this->borrarTablasDeProde();

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

        $notifier = $this->notifier( static function ( string $para, string $asunto, string $cuerpo, array $headers ): bool {
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

        $this->notifier( static fn ( string $para, string $asunto, string $cuerpo, array $headers ): bool => false )
            ->notificarCreada( $id );

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

    public function test_el_cuerpo_nombra_equipo_movimiento_y_link(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( 'SUIZA', $cuerpo );
        $this->assertStringContainsString( 'Titular Perez', $cuerpo );
        $this->assertStringContainsString( 'Suplente Gomez', $cuerpo );
        $this->assertStringContainsString( 'page=cambios-bandeja', $cuerpo, 'The link is what turns an alert into an action.' );
    }

    public function test_el_cuerpo_nombra_al_capitan_que_pidio(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );

        // A captain who is NEITHER of the two players in the movement: with
        // the default 777 the name would already be on the mail as the
        // saliente, and the assertion would pass without the new line.
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreada( $id );

        // The role in parentheses, so the name is not mistaken for another
        // player in the movement — which is exactly what it looks like when
        // the captain happens to be one of them.
        $this->assertStringContainsString( 'Pedido por Capitan Suarez (Capitán)', $this->enviados[0]['cuerpo'] );
    }

    public function test_una_solicitud_cargada_desde_el_admin_lo_dice_en_el_mail_de_la_comision(): void {
        global $wp_test_users;
        $wp_test_users = [ 42 => 'Fernando Comision' ];

        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222, 42 );

        $this->notifier()->notificarCreada( $id );

        $this->assertStringContainsString( 'Cargada desde el panel por Fernando Comision', $this->enviados[0]['cuerpo'] );

        $wp_test_users = [];
    }

    public function test_una_solicitud_del_capitan_no_lleva_la_linea_de_carga_desde_el_admin(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreada( $id );

        $this->assertStringNotContainsString( 'Cargada desde el panel', $this->enviados[0]['cuerpo'] );
    }

    public function test_sin_capitan_resoluble_la_linea_no_aparece(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );

        // 0 is what the column holds if the token carried no player id.
        $id = $this->crearSustitucion( null, 0 );

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringNotContainsString( 'Pedido por', $cuerpo );
        $this->assertStringNotContainsString( 'Jugador #0', $cuerpo );
    }

    public function test_va_como_html(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertContains(
            'Content-Type: text/html; charset=UTF-8',
            $this->enviados[0]['headers'],
            'Without the header the reader gets the markup as literal text — worse than the plain text this replaced.'
        );
    }

    /**
     * Removed on the process owner's request: an alert that arrives already
     * recommending an answer frames the decision before he has looked at it.
     * The tray still shows it, next to the buttons that act on it.
     */
    public function test_el_cuerpo_no_lleva_la_accion_sugerida(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );

        $id = $this->crearSustitucion( Dictamen::from( [
            new Motivo( 'puntaje_fuera_del_techo', 'El puntaje supera el techo de la plaza.', [] ),
        ] ) );

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringNotContainsString( 'Acción sugerida', $cuerpo );
        $this->assertStringNotContainsString( 'aprobar', $cuerpo );
        $this->assertStringNotContainsString( 'rechazar', $cuerpo );
        $this->assertStringNotContainsString(
            'El puntaje supera el techo de la plaza.',
            $cuerpo,
            'Without the verdict the motivos are objections with nothing to object to.'
        );
    }

    public function test_el_cuerpo_lleva_el_puntaje_de_cada_jugador(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $this->seedPuntaje( 777, 4.5 );
        $this->seedPuntaje( 888, 3.0 );

        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( '4.5 ptos', $cuerpo );
        $this->assertStringContainsString( '3 ptos', $cuerpo, 'A whole number reads as "3 ptos", not "3.0 ptos".' );
    }

    public function test_un_jugador_sin_puntaje_lo_dice_en_vez_de_dejar_un_hueco(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $this->seedPuntaje( 777, 4.5 );
        // 888 deliberately has none.

        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $this->assertStringContainsString(
            'sin puntaje',
            $this->enviados[0]['cuerpo'],
            'A blank cell reads as a rendering glitch; "sin puntaje" is a fact he can act on.'
        );
    }

    public function test_el_cuerpo_nombra_el_torneo_y_la_fecha_de_ese_torneo(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        // `numero_en_torneo` restarts at 1 on every torneo, so it is never
        // shown without the label that gives it meaning.
        $this->assertStringContainsString( 'Apertura · fecha 1', $this->enviados[0]['cuerpo'] );
    }

    public function test_si_no_se_puede_leer_la_fecha_cae_al_id_global(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        // The row disappears between creation and the alert.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_fecha" );

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( 'Fecha 1', $cuerpo, 'A poorer heading beats no alert.' );
        $this->assertCount( 1, $this->enviados );
    }

    public function test_el_asunto_nombra_al_equipo_y_al_jugador_que_SALE(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        // The one being taken out (777), NOT the one coming in (888): that
        // is the plaza the process owner is being asked about, and the name
        // he already has in his head.
        //
        // assertSame on the whole string, not contains: `contains` would
        // pass on a subject that also happened to carry the team.
        $this->assertSame(
            'Cambios: Nueva solicitud de SUIZA por Titular Perez',
            $this->enviados[0]['asunto']
        );
        $this->assertStringNotContainsString( 'Suplente Gomez', $this->enviados[0]['asunto'] );
    }

    /**
     * Two requests from one team on one day must not share a subject — the
     * whole reason the player was added to it.
     */
    public function test_dos_pedidos_del_mismo_equipo_no_comparten_asunto(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $this->seedFecha( 1 );

        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 999, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        foreach ( [ [ $plazaA, 888 ], [ $plazaB, 111 ] ] as [ $plaza, $entrante ] ) {
            $this->notifier()->notificarCreada(
                $this->solicitudRepository->crear(
                    SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plaza, $entrante, 1, time() ),
                    777,
                    Dictamen::from( [] ),
                    '2026-05-27 10:00:00'
                )
            );
        }

        $this->assertCount( 2, $this->enviados );
        $this->assertNotSame( $this->enviados[0]['asunto'], $this->enviados[1]['asunto'] );
    }

    /**
     * A `regreso` has no entrante: the plaza's own titular is who comes
     * back, and he is what the request is about.
     */
    public function test_un_regreso_tiene_su_propio_asunto_y_explica_quien_se_libera(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::regreso( self::SEASON_ID, self::TEAM_ID, $plazaId, 4, time() ),
            777,
            Dictamen::from( [] ),
            '2026-04-01 10:00:00'
        );

        $this->notifier()->notificarCreada( $id );

        $this->assertSame(
            'Cambios: Confirmación de Regreso de Titular Perez',
            $this->enviados[0]['asunto'],
            'A regreso is not a request for a new player — it confirms one the team already has.'
        );
        $this->assertStringNotContainsString( 'Nueva solicitud', $this->enviados[0]['asunto'] );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString(
            'El capitán pide el regreso de Titular Perez a su plaza.',
            $cuerpo,
            'The table alone does not say which direction a regreso runs.'
        );
        $this->assertStringContainsString( 'Se libera', $cuerpo );
        $this->assertStringContainsString(
            'Suplente Gomez',
            $cuerpo,
            'The suplente giving the plaza back is who the process owner needs to know frees up.'
        );
    }

    public function test_el_boton_dice_gestionar(): void {
        $this->configurar( true, 'fernando@ejemplo.com' );
        $id = $this->crearSustitucion();

        $this->notifier()->notificarCreada( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( '>Gestionar</a>', $cuerpo );
        $this->assertStringContainsString( 'page=cambios-bandeja', $cuerpo, 'The label changed; where it points did not.' );
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

        // The arco leg moves a player the team ALREADY has; the only one
        // joining from outside is the field entrante, so he is the ask.
        // The GOALKEEPER stepping out of the arco (777), which is the whole
        // subject of the move — not the field titular who replaces him, and
        // not the external player joining underneath (999).
        $this->assertSame(
            'Cambios: Nueva solicitud de SUIZA por Titular Perez',
            $this->enviados[0]['asunto']
        );
        $this->assertStringNotContainsString( 'Otro Lopez', $this->enviados[0]['asunto'] );

        $cuerpo = $this->enviados[0]['cuerpo'];
        $this->assertStringContainsString( 'Arco — sale', $cuerpo );
        $this->assertStringContainsString( 'Arco — entra', $cuerpo );
        $this->assertStringContainsString( 'Campo — entra', $cuerpo );
        $this->assertStringContainsString( 'Otro Lopez', $cuerpo, 'The field movement names who comes in.' );
    }

    // -------------------------------------------------------------------------
    // Los tres mails al capitán
    // -------------------------------------------------------------------------

    private function configurarCapitan( bool $activa ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}cambios_settings SET setting_value = %s WHERE setting_key = 'notificacion_capitan_activa'",
                $activa ? '1' : '0'
            )
        );
    }

    public function test_apagado_el_capitan_no_recibe_nada(): void {
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreadaAlCapitan( $id );

        $this->assertSame( [], $this->enviados, 'OFF by default.' );
    }

    public function test_el_acuse_llega_al_mail_del_capitan(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreadaAlCapitan( $id );

        $this->assertCount( 1, $this->enviados );
        $this->assertSame( 'capitan@ejemplo.com', $this->enviados[0]['para'] );
        $this->assertSame( 'Cambios: Recibimos tu pedido por Titular Perez', $this->enviados[0]['asunto'] );

        $cuerpo = $this->enviados[0]['cuerpo'];
        $this->assertStringContainsString( 'Recibimos tu pedido.', $cuerpo );
        $this->assertStringContainsString( 'Te avisamos por mail cuando haya una decisión.', $cuerpo );
    }

    public function test_el_mail_de_aprobacion_avisa_que_NO_es_definitivo(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->solicitudRepository->aprobar( $id, 1, 'Dale', '2026-05-28 10:00:00', 'La comision' );
        $this->notifier()->notificarDecision( $id, EstadoSolicitud::APROBADA );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertSame( 'Cambios: Aprobaron tu pedido por Titular Perez', $this->enviados[0]['asunto'] );
        $this->assertStringContainsString( 'La comisión aprobó tu pedido.', $cuerpo );

        // THE point of this mail: a captain who reads "aprobado" and fields
        // the player before the lote is published has broken the rule
        // believing he followed it.
        $this->assertStringContainsString( 'Todavía no es definitivo.', $cuerpo );
        $this->assertStringContainsString( 'el jugador no puede jugar', $cuerpo );
        $this->assertStringContainsString( 'Dale', $cuerpo, "The comisión's note is the only explanation he gets." );
    }

    public function test_el_mail_de_rechazo_lleva_la_nota_y_nada_mas(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );

        $id = $this->crearSustitucion(
            Dictamen::from( [ new Motivo( 'puntaje_fuera_del_techo', 'El puntaje supera el techo de la plaza.', [] ) ] ),
            222
        );

        $this->solicitudRepository->rechazar( $id, 1, 'No da el puntaje', '2026-05-28 10:00:00', 'La comision' );
        $this->notifier()->notificarDecision( $id, EstadoSolicitud::RECHAZADA );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertSame( 'Cambios: Rechazaron tu pedido por Titular Perez', $this->enviados[0]['asunto'] );
        $this->assertStringContainsString( 'La comisión rechazó tu pedido.', $cuerpo );
        $this->assertStringContainsString( 'No da el puntaje', $cuerpo );

        // NOT the dictamen's objections: if the comisión rejected for a
        // reason of their own, printing the ruleset's beside it tells the
        // captain a reason that was not the real one.
        $this->assertStringNotContainsString( 'El puntaje supera el techo de la plaza.', $cuerpo );
    }

    public function test_sin_nota_lo_dice_en_vez_de_inventar_una_razon(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->solicitudRepository->rechazar( $id, 1, null, '2026-05-28 10:00:00', 'La comision' );
        $this->notifier()->notificarDecision( $id, EstadoSolicitud::RECHAZADA );

        $this->assertStringContainsString( 'La comisión no dejó un comentario.', $this->enviados[0]['cuerpo'] );
    }

    /** wp-admin is not somewhere a captain can go. */
    public function test_el_mail_del_capitan_no_lleva_el_link_al_admin(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreadaAlCapitan( $id );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringNotContainsString( 'cambios-bandeja', $cuerpo );
        $this->assertStringNotContainsString( 'Gestionar', $cuerpo );
        $this->assertStringContainsString( 'Mis pedidos', $cuerpo );
    }

    public function test_un_capitan_sin_mail_no_rompe_y_deja_rastro(): void {
        $this->configurarCapitan( true );
        // No prode account linked for 222.
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCreadaAlCapitan( $id );

        $this->assertSame( [], $this->enviados );
        $this->assertTrue( $this->eventLog->has( 'notificacion.capitan_sin_email_fallida' ) );
    }

    public function test_una_asociacion_borrada_no_es_una_direccion(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );

        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}prode_associations SET deleted_at = '2026-09-01 00:00:00'" );

        $id = $this->crearSustitucion( null, 222 );
        $this->notifier()->notificarCreadaAlCapitan( $id );

        $this->assertSame(
            [],
            $this->enviados,
            'An association the user removed is not a way to reach them any more.'
        );
    }

    public function test_con_dos_cuentas_gana_la_del_ultimo_ingreso(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'vieja@ejemplo.com', '2026-01-01 10:00:00' );
        $this->seedCapitanEmail( 222, 'nueva@ejemplo.com', '2026-10-10 10:00:00' );

        $id = $this->crearSustitucion( null, 222 );
        $this->notifier()->notificarCreadaAlCapitan( $id );

        $this->assertSame(
            'nueva@ejemplo.com',
            $this->enviados[0]['para'],
            'The account they actually read is the one they last signed in with.'
        );
    }

    /**
     * `publicada` and `anulada` have no copy written for them. Mailing
     * something improvised is how a captain gets told what nobody meant to
     * say.
     */
    public function test_un_estado_sin_texto_escrito_no_manda_nada(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        foreach ( [ EstadoSolicitud::PUBLICADA, EstadoSolicitud::ANULADA, EstadoSolicitud::PENDIENTE, 'inventado' ] as $estado ) {
            $this->notifier()->notificarDecision( $id, $estado );
        }

        $this->assertSame( [], $this->enviados );

        // No mail is only half of it: WITHOUT the guard these fall through
        // to a missing template, blow up, and get swallowed by the catch —
        // same silence, but every one of them logged as a failure. Over a
        // Friday lote that is a stream of fake errors in the one durable
        // slot this host has for real ones.
        $this->assertFalse(
            $this->eventLog->has( 'notificacion.capitan_fallida' ),
            'An estado with no copy written is a SKIP, not a failure.'
        );
    }

    public function test_el_mail_de_cancelacion_confirma_y_dice_que_la_plaza_quedo_libre(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->solicitudRepository->anular( $id, 222, null, '2026-05-28 10:00:00', 'Capitán Capitan Suarez' );
        $this->notifier()->notificarCancelada( $id );

        $this->assertSame( 'Cambios: Cancelamos tu pedido por Titular Perez', $this->enviados[0]['asunto'] );

        $cuerpo = $this->enviados[0]['cuerpo'];

        $this->assertStringContainsString( 'Cancelaste tu pedido.', $cuerpo );

        // The useful half. An open request holds its plaza, so "you can ask
        // again" is the whole reason he cancelled.
        $this->assertStringContainsString( 'La plaza quedó libre', $cuerpo );
    }

    /**
     * `anulada` is reached by two different roads and they need opposite
     * words. Routing both through notificarDecision() would tell a captain
     * "cancelaste tu pedido" about something the comisión did TO him.
     */
    public function test_la_cancelacion_no_viaja_por_notificarDecision(): void {
        $this->configurarCapitan( true );
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarDecision( $id, EstadoSolicitud::ANULADA );

        $this->assertSame(
            [],
            $this->enviados,
            'An annulment the comisión performed must not be announced as the captain cancelling.'
        );
    }

    public function test_apagado_no_confirma_la_cancelacion(): void {
        $this->seedCapitanEmail( 222, 'capitan@ejemplo.com' );
        $id = $this->crearSustitucion( null, 222 );

        $this->notifier()->notificarCancelada( $id );

        $this->assertSame( [], $this->enviados );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function notifier( ?callable $mailFn = null ): SolicitudNotifier {
        global $wpdb;

        return new SolicitudNotifier(
            $this->settings,
            $this->solicitudRepository,
            $this->plazaRepository,
            new FechaRepository( $wpdb, new InMemoryEventLog() ),
            new JugadorMetricasReader( $wpdb, $this->eventLog ),
            $this->eventLog,
            $mailFn ?? function ( string $para, string $asunto, string $cuerpo, array $headers ): bool {
                $this->enviados[] = [ 'para' => $para, 'asunto' => $asunto, 'cuerpo' => $cuerpo, 'headers' => $headers ];

                return true;
            },
            new CapitanEmailResolver( $wpdb, $this->eventLog )
        );
    }

    /**
     * prode_associations + prode_users belong to entre-redes-prode, not to
     * this plugin, so InitialSchema does not create them. The shim's SQLite
     * needs them to exist before the resolver can read anything.
     */
    private function seedCapitanEmail( int $playerId, string $email, ?string $lastLogin = '2026-10-01 10:00:00' ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}prode_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT,
                last_login_at TEXT,
                deleted_at TEXT
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$p}prode_associations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                player_id INTEGER NOT NULL,
                deleted_at TEXT
            )"
        );

        $wpdb->insert( $p . 'prode_users', [ 'email' => $email, 'last_login_at' => $lastLogin, 'deleted_at' => null ] );
        $userId = (int) $wpdb->insert_id;

        $wpdb->insert( $p . 'prode_associations', [ 'user_id' => $userId, 'player_id' => $playerId, 'deleted_at' => null ] );
    }

    private function borrarTablasDeProde(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        foreach ( [ 'prode_associations', 'prode_users' ] as $t ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$p}{$t}" );
        }
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

    private function crearSustitucion( ?Dictamen $dictamen = null, int $solicitadaPor = 777, ?int $creadaPorWpUser = null ): int {
        $this->seedFecha( 1 );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        return $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 888, 1, time() ),
            $solicitadaPor,
            $dictamen ?? Dictamen::from( [] ),
            '2026-05-27 10:00:00',
            $creadaPorWpUser
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
