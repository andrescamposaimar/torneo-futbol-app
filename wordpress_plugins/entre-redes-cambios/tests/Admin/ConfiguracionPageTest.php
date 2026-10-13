<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\AdminMenu;
use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Admin\ConfiguracionPage;
use EntreRedes\Cambios\Admin\HistoricoPage;
use EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Calendario\SettingsWriter;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ConfiguracionPage against the REAL Settings reader
 * and SettingsWriter, because the only thing worth proving here is a
 * round-trip: what the form writes must be exactly what the ruleset reads
 * back. A stubbed writer would prove the form talks to a stub.
 *
 * `handlePost()` is never called directly — it ends in `wp_safe_redirect()` +
 * `exit`, the PRG pattern every admin page here follows, and a real `exit`
 * would kill the PHPUnit process. Tests invoke the non-exiting core
 * (`ejecutarGuardar()`) through reflection instead, the same pattern
 * BandejaPageTest uses.
 */
class ConfiguracionPageTest extends TestCase {

    private ConfiguracionPage $page;
    private Settings $settings;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        global $wpdb;

        InitialSchema::up();

        // Each test must start from the documented production calendar, not
        // from whatever the previous one wrote. The seeding inside up() is an
        // INSERT IGNORE, so clearing the rows first is what lets a second
        // up() put the defaults back.
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );
        InitialSchema::up();

        $this->eventLog = new InMemoryEventLog();
        $this->settings = new Settings( $wpdb, $this->eventLog );

        $this->page = new ConfiguracionPage(
            new ProcessOwnerAuthorizer(),
            $this->settings,
            new SettingsWriter( $wpdb, $this->eventLog ),
            $this->eventLog,
            static fn (): int => strtotime( '2026-10-13 12:00:00' )
        );

        unset(
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_settings" );

        unset(
            $GLOBALS['wp_test_current_user_can'],
            $GLOBALS['wp_test_check_admin_referer'],
            $GLOBALS['_prode_test_transients']
        );
        $_POST = [];
    }

    // -------------------------------------------------------------------------
    // Permission and nonce — both directions
    // -------------------------------------------------------------------------

    public function test_sin_capacidad_no_guarda_nada(): void {
        $antes = $this->settings->aperturaSolicitudesOffset();

        $GLOBALS['wp_test_current_user_can'] = false;
        $_POST = [ 'cambios_config_action' => 'guardar', 'dias_apertura_solicitudes' => '-20' ];

        try {
            $this->page->handlePost();
            $this->fail( 'Expected handlePost() to reject an unauthorized user.' );
        } catch ( \RuntimeException $e ) {
            // wp_die() throws in this shim.
        }

        $this->assertSame(
            $antes,
            $this->settings->aperturaSolicitudesOffset(),
            'An unauthorized POST must never reach the writer.'
        );
        $this->assertTrue( $this->eventLog->has( 'admin.autorizacion_denegada' ) );
    }

    public function test_nonce_invalido_no_guarda_nada(): void {
        $antes = $this->settings->aperturaSolicitudesOffset();

        $GLOBALS['wp_test_current_user_can']    = [ ProcessOwnerAuthorizer::CAPABILITY => true ];
        $GLOBALS['wp_test_check_admin_referer'] = false;
        $_POST = $this->formularioValido( [ 'dias_apertura_solicitudes' => '-20' ] );
        $_POST['cambios_config_action'] = 'guardar';

        try {
            $this->page->handlePost();
            $this->fail( 'Expected a bad nonce to stop the save.' );
        } catch ( \RuntimeException $e ) {
            // check_admin_referer() calls wp_die(), which throws here.
        }

        $this->assertSame( $antes, $this->settings->aperturaSolicitudesOffset() );
    }

    // -------------------------------------------------------------------------
    // The round-trip
    // -------------------------------------------------------------------------

    public function test_guardar_los_plazos_los_deja_leibles_por_el_lector(): void {
        $resultado = $this->guardar( [
            'dias_apertura_solicitudes' => '-8',
            'hora_apertura_solicitudes' => '06:30:00',
        ] );

        $this->assertTrue( $resultado['ok'] );
        $this->assertSame( [], $resultado['errores'] );
        $this->assertSame(
            [ 'days' => -8, 'time' => '06:30:00' ],
            $this->settings->aperturaSolicitudesOffset(),
            'What the form writes must be exactly what the ruleset reads back.'
        );
    }

    public function test_guardar_escribe_los_cuatro_plazos(): void {
        $this->guardar( [
            'dias_apertura_solicitudes' => '-10',
            'dias_cierre_regresos'      => '-7',
            'dias_cierre_solicitudes'   => '-5',
            'dias_publicacion'          => '-3',
        ] );

        $this->assertSame( -10, $this->settings->aperturaSolicitudesOffset()['days'] );
        $this->assertSame( -7, $this->settings->cierreRegresosOffset()['days'] );
        $this->assertSame( -5, $this->settings->cierreSolicitudesOffset()['days'] );
        $this->assertSame( -3, $this->settings->publicacionOffset()['days'] );
    }

    public function test_la_exencion_del_arco_viaja_en_las_dos_direcciones(): void {
        $this->assertTrue( $this->settings->exencionArcoActiva(), 'Seeded ON.' );

        // Unchecked means ABSENT from $_POST — the ordinary HTML story, and
        // the direction a naive isset() reading gets wrong.
        $this->guardar( [], [ 'exencion_arco_activa' ] );
        $this->assertFalse( $this->settings->exencionArcoActiva() );

        $this->guardar( [] );
        $this->assertTrue( $this->settings->exencionArcoActiva() );
    }

    public function test_la_prioridad_de_padres_viaja_en_las_dos_direcciones(): void {
        $this->assertFalse( $this->settings->prioridadPadresActiva(), 'Seeded OFF — the mirror of the exención.' );

        $this->guardar( [ 'prioridad_padres_activa' => '1' ] );
        $this->assertTrue( $this->settings->prioridadPadresActiva() );

        $this->guardar( [] );
        $this->assertFalse( $this->settings->prioridadPadresActiva() );
    }

    // -------------------------------------------------------------------------
    // The configurable mínimo
    // -------------------------------------------------------------------------

    public function test_el_minimo_de_fechas_viaja_al_lector(): void {
        $this->assertSame( 3, $this->settings->minimoFechasCambio(), 'The reglamento\'s standing value.' );

        $resultado = $this->guardar( [ 'minimo_fechas_cambio' => '5' ] );

        $this->assertTrue( $resultado['ok'] );
        $this->assertSame( 5, $this->settings->minimoFechasCambio() );
    }

    public function test_el_minimo_puede_bajar_a_uno(): void {
        $this->guardar( [ 'minimo_fechas_cambio' => '1' ] );

        $this->assertSame( 1, $this->settings->minimoFechasCambio() );
    }

    public function test_un_minimo_fuera_de_rango_se_rechaza(): void {
        // 0 would void the rule entirely — a substitute returnable the same
        // fecha they arrived — and is far likelier to be a typo than a
        // decision.
        foreach ( [ '0', '-1', '11', 'tres', '' ] as $valor ) {
            $resultado = $this->guardar( [ 'minimo_fechas_cambio' => $valor ] );

            $this->assertFalse( $resultado['ok'], "minimo='{$valor}' must be refused." );
            $this->assertSame( 3, $this->settings->minimoFechasCambio(), 'And nothing must have been written.' );
        }
    }

    public function test_un_minimo_invalido_no_deja_pasar_a_los_plazos(): void {
        $antes = $this->settings->aperturaSolicitudesOffset();

        $resultado = $this->guardar( [
            'dias_apertura_solicitudes' => '-9',   // perfectly fine
            'minimo_fechas_cambio'      => '0',    // refused
        ] );

        $this->assertFalse( $resultado['ok'] );
        $this->assertSame(
            $antes,
            $this->settings->aperturaSolicitudesOffset(),
            'The mínimo rides on the same all-or-nothing save as the plazos.'
        );
    }

    // -------------------------------------------------------------------------
    // The captain-facing kill switch
    // -------------------------------------------------------------------------

    public function test_el_interruptor_de_la_app_viaja_al_lector(): void {
        $this->assertTrue( $this->settings->cambiosHabilitados(), 'Open by default.' );

        $this->guardar( [ 'mensaje_deshabilitado' => 'Hablá con la comisión.' ], [ 'cambios_habilitados' ] );

        $this->assertFalse( $this->settings->cambiosHabilitados() );
        $this->assertSame( 'Hablá con la comisión.', $this->settings->mensajeDeshabilitado() );

        $this->guardar( [] );
        $this->assertTrue( $this->settings->cambiosHabilitados() );
    }

    public function test_apagar_sin_mensaje_se_rechaza(): void {
        // Closing the feature with nothing to show would leave captains
        // staring at a blank screen — the exact thing the switch exists to
        // avoid.
        $resultado = $this->guardar( [ 'mensaje_deshabilitado' => '   ' ], [ 'cambios_habilitados' ] );

        $this->assertFalse( $resultado['ok'] );
        $this->assertTrue( $this->settings->cambiosHabilitados(), 'And nothing must have been written.' );
    }

    public function test_un_mensaje_vacio_con_los_cambios_prendidos_es_valido(): void {
        $resultado = $this->guardar( [ 'mensaje_deshabilitado' => '' ] );

        $this->assertTrue( $resultado['ok'], 'An empty message harms nobody while the feature is open.' );
    }

    public function test_un_mensaje_demasiado_largo_se_rechaza(): void {
        $resultado = $this->guardar( [ 'mensaje_deshabilitado' => str_repeat( 'a', 301 ) ] );

        $this->assertFalse( $resultado['ok'] );
    }

    public function test_el_mensaje_se_limpia_de_etiquetas(): void {
        $this->guardar( [ 'mensaje_deshabilitado' => 'Hablá con <b>la comisión</b>.' ] );

        $this->assertSame(
            'Hablá con la comisión.',
            $this->settings->mensajeDeshabilitado(),
            'This is the one string the app renders as written, so it is cleaned at the only door it comes in through.'
        );
    }

    public function test_un_mensaje_guardado_vacio_cae_al_sembrado(): void {
        // Belt and braces: even if a row somehow ends up empty, a captain
        // must not face a blank screen with no explanation.
        global $wpdb;
        $wpdb->query( "UPDATE {$wpdb->prefix}cambios_settings SET setting_value = '' WHERE setting_key = 'mensaje_deshabilitado'" );

        $this->assertStringContainsString( 'Comisión Directiva', $this->settings->mensajeDeshabilitado() );
    }

    // -------------------------------------------------------------------------
    // The new-request alert
    // -------------------------------------------------------------------------

    public function test_el_aviso_por_mail_viaja_al_lector(): void {
        $this->assertFalse( $this->settings->notificacionNuevaSolicitudActiva(), 'OFF by default.' );
        $this->assertSame( '', $this->settings->notificacionNuevaSolicitudDestino() );

        $resultado = $this->guardar( [
            'notificacion_nueva_activa'  => '1',
            'notificacion_nueva_destino' => 'fernando@ejemplo.com',
        ] );

        $this->assertTrue( $resultado['ok'] );
        $this->assertTrue( $this->settings->notificacionNuevaSolicitudActiva() );
        $this->assertSame( 'fernando@ejemplo.com', $this->settings->notificacionNuevaSolicitudDestino() );
    }

    public function test_prender_el_aviso_sin_direccion_se_rechaza(): void {
        // An alert with nowhere to go produces silence that looks exactly
        // like "nobody asked for a change" — the failure it exists to end.
        $resultado = $this->guardar( [ 'notificacion_nueva_activa' => '1', 'notificacion_nueva_destino' => '  ' ] );

        $this->assertFalse( $resultado['ok'] );
        $this->assertFalse( $this->settings->notificacionNuevaSolicitudActiva() );
    }

    public function test_una_direccion_invalida_se_rechaza(): void {
        foreach ( [ 'no-es-un-mail', 'arroba@', '@dominio.com', 'con espacio@x.com' ] as $valor ) {
            $resultado = $this->guardar( [
                'notificacion_nueva_activa'  => '1',
                'notificacion_nueva_destino' => $valor,
            ] );

            $this->assertFalse( $resultado['ok'], "'{$valor}' must be refused." );
            $this->assertSame( '', $this->settings->notificacionNuevaSolicitudDestino(), 'And nothing must have been written.' );
        }
    }

    public function test_se_puede_apagar_el_aviso_dejando_la_direccion(): void {
        $this->guardar( [
            'notificacion_nueva_activa'  => '1',
            'notificacion_nueva_destino' => 'fernando@ejemplo.com',
        ] );

        // Unchecked, address kept: turning the alert off must not force the
        // operator to retype the address to turn it back on.
        $resultado = $this->guardar( [ 'notificacion_nueva_destino' => 'fernando@ejemplo.com' ] );

        $this->assertTrue( $resultado['ok'] );
        $this->assertFalse( $this->settings->notificacionNuevaSolicitudActiva() );
        $this->assertSame( 'fernando@ejemplo.com', $this->settings->notificacionNuevaSolicitudDestino() );
    }

    // -------------------------------------------------------------------------
    // The invariant: a refused save writes NOTHING
    // -------------------------------------------------------------------------

    public function test_un_orden_cruzado_se_rechaza_y_no_escribe_ni_una_fila(): void {
        $antes = [
            'apertura'    => $this->settings->aperturaSolicitudesOffset(),
            'regresos'    => $this->settings->cierreRegresosOffset(),
            'cierre'      => $this->settings->cierreSolicitudesOffset(),
            'publicacion' => $this->settings->publicacionOffset(),
        ];

        // Apertura AFTER the cierre: the window would never open.
        $resultado = $this->guardar( [ 'dias_apertura_solicitudes' => '-1' ] );

        $this->assertFalse( $resultado['ok'] );
        $this->assertNotEmpty( $resultado['errores'] );
        $this->assertSame( [], $resultado['escritas'], 'A refused save must write nothing at all.' );

        $this->assertSame( $antes['apertura'], $this->settings->aperturaSolicitudesOffset() );
        $this->assertSame( $antes['regresos'], $this->settings->cierreRegresosOffset() );
        $this->assertSame( $antes['cierre'], $this->settings->cierreSolicitudesOffset() );
        $this->assertSame( $antes['publicacion'], $this->settings->publicacionOffset() );
        $this->assertTrue( $this->eventLog->has( 'settings.guardado_rechazado' ) );
    }

    /**
     * THE point of refusing the whole set: the offsets that were individually
     * fine must not land either. A half-applied calendar is worse than the
     * old one, because the operator believes they changed it.
     */
    public function test_un_valor_invalido_no_deja_pasar_a_los_que_si_eran_validos(): void {
        $regresosAntes = $this->settings->cierreRegresosOffset();

        $resultado = $this->guardar( [
            'dias_cierre_regresos'      => '-5',        // perfectly fine
            'hora_apertura_solicitudes' => 'mediodia',  // garbage
        ] );

        $this->assertFalse( $resultado['ok'] );
        $this->assertSame(
            $regresosAntes,
            $this->settings->cierreRegresosOffset(),
            'The valid row must NOT be written when another one is invalid.'
        );
    }

    public function test_un_interruptor_no_se_toca_cuando_el_guardado_se_rechaza(): void {
        $this->assertTrue( $this->settings->exencionArcoActiva() );

        // Would turn the switch off, but the calendar is invalid.
        $this->guardar( [ 'dias_apertura_solicitudes' => '-1' ], [ 'exencion_arco_activa' ] );

        $this->assertTrue(
            $this->settings->exencionArcoActiva(),
            'The switches ride on the same save; a refused one must leave them alone too.'
        );
    }

    // -------------------------------------------------------------------------
    // Bookkeeping
    // -------------------------------------------------------------------------

    public function test_guardar_sin_cambiar_nada_no_escribe_ni_registra(): void {
        $resultado = $this->guardar( [] );

        $this->assertTrue( $resultado['ok'] );
        $this->assertSame( [], $resultado['escritas'], 'Pressing Guardar without changing anything is not a change.' );
        $this->assertFalse(
            $this->eventLog->has( 'settings.modificado' ),
            'The audit trail must mean "somebody changed this", not "somebody opened the form".'
        );
    }

    public function test_cada_cambio_registra_el_valor_anterior_y_el_nuevo(): void {
        $this->guardar( [ 'dias_apertura_solicitudes' => '-9' ] );

        $eventos = array_values( array_filter(
            $this->eventLog->all(),
            static fn ( array $e ): bool => 'settings.modificado' === $e['evento']
        ) );

        $this->assertCount( 1, $eventos );
        $this->assertSame( 'plazo_apertura_solicitudes', $eventos[0]['contexto']['setting_key'] );
        $this->assertStringContainsString( '-6', (string) $eventos[0]['contexto']['anterior'] );
        $this->assertStringContainsString( '-9', (string) $eventos[0]['contexto']['nuevo'] );
    }

    // -------------------------------------------------------------------------
    // Render
    // -------------------------------------------------------------------------

    public function test_render_sin_capacidad_no_muestra_nada(): void {
        $GLOBALS['wp_test_current_user_can'] = false;

        $this->expectException( \RuntimeException::class );

        $this->page->render();
    }

    public function test_el_formulario_muestra_los_valores_guardados(): void {
        $this->guardar( [ 'dias_apertura_solicitudes' => '-8', 'hora_apertura_solicitudes' => '06:30:00' ] );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'name="dias_apertura_solicitudes"', $html );
        $this->assertStringContainsString( 'value="-8"', $html );
        $this->assertStringContainsString( 'value="06:30:00"', $html );
        $this->assertStringContainsString( 'Guardar configuración', $html );
    }

    public function test_cada_checkbox_refleja_su_propio_estado_guardado(): void {
        $html = $this->renderPagina();

        $this->assertTrue( $this->estaTildado( $html, 'exencion_arco_activa' ), 'Seeded ON.' );
        $this->assertTrue( $this->estaTildado( $html, 'cambios_habilitados' ), 'Seeded ON.' );
        $this->assertFalse( $this->estaTildado( $html, 'prioridad_padres_activa' ), 'Seeded OFF.' );

        $this->guardar( [], [ 'exencion_arco_activa' ] );

        $html = $this->renderPagina();

        // Only the one that was turned off changes — asserted per field, not
        // by counting attributes, so adding a fourth switch later does not
        // silently invalidate this.
        $this->assertFalse( $this->estaTildado( $html, 'exencion_arco_activa' ) );
        $this->assertTrue( $this->estaTildado( $html, 'cambios_habilitados' ) );
    }

    public function test_el_mensaje_guardado_se_muestra_en_el_textarea(): void {
        $this->guardar( [ 'mensaje_deshabilitado' => 'Escribile a la comisión.' ] );

        $this->assertStringContainsString( 'Escribile a la comisión.', $this->renderPagina() );
    }

    public function test_el_render_lista_todos_los_errores_del_rechazo(): void {
        $GLOBALS['wp_test_current_user_can'] = [ ProcessOwnerAuthorizer::CAPABILITY => true ];
        set_transient(
            'cambios_config_notice_' . get_current_user_id(),
            [ 'ok' => false, 'errores' => [ 'Primer problema.', 'Segundo problema.' ], 'escritas' => [], 'fallidas' => [] ],
            60
        );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'No se guardó nada', $html );
        $this->assertStringContainsString( 'Primer problema.', $html );
        $this->assertStringContainsString( 'Segundo problema.', $html );
    }

    // -------------------------------------------------------------------------
    // Menu wiring
    // -------------------------------------------------------------------------

    public function test_el_menu_registra_las_tres_pantallas_con_la_misma_capacidad(): void {
        global $wpdb;
        $GLOBALS['_prode_test_registered_admin_menus'] = [];

        ( new AdminMenu(
            $this->bandejaPageFake( $wpdb ),
            $this->historicoPageFake( $wpdb ),
            $this->page
        ) )->register();

        $registradas = $GLOBALS['_prode_test_registered_admin_menus'];

        $submenus = array_values( array_filter( $registradas, static fn ( array $m ): bool => isset( $m['parent_slug'] ) ) );

        $this->assertCount( 3, $submenus, 'Bandeja, Histórico and Configuración.' );
        $this->assertSame( BandejaPage::SLUG, $submenus[0]['menu_slug'], 'The tray stays first, so the menu lands on it.' );
        $this->assertSame( HistoricoPage::SLUG, $submenus[1]['menu_slug'] );
        $this->assertSame( ConfiguracionPage::SLUG, $submenus[2]['menu_slug'] );

        foreach ( $registradas as $entrada ) {
            $this->assertSame( ProcessOwnerAuthorizer::CAPABILITY, $entrada['capability'] );
        }

        unset( $GLOBALS['_prode_test_registered_admin_menus'] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Invokes the non-exiting core with a complete, valid form plus the
     * overrides a test cares about.
     *
     * @param array<string, string> $overrides
     * @param string[]              $desmarcar Checkbox names to leave ABSENT
     *        from $_POST, which is how HTML reports "unchecked".
     * @return array{ok: bool, errores: string[], escritas: string[], fallidas: string[]}
     */
    private function guardar( array $overrides, array $desmarcar = [] ): array {
        $_POST = $this->formularioValido( $overrides, $desmarcar );

        $ref = new \ReflectionMethod( ConfiguracionPage::class, 'ejecutarGuardar' );
        $leer = new \ReflectionMethod( ConfiguracionPage::class, 'leerFormulario' );

        /** @var array{ok: bool, errores: string[], escritas: string[], fallidas: string[]} $resultado */
        $resultado = $ref->invoke( $this->page, $leer->invoke( $this->page ) );

        return $resultado;
    }

    /**
     * @param array<string, string> $overrides
     * @param string[]              $desmarcar
     * @return array<string, string>
     */
    private function formularioValido( array $overrides = [], array $desmarcar = [] ): array {
        $form = [
            'dias_apertura_solicitudes' => '-6',
            'hora_apertura_solicitudes' => '00:00:00',
            'dias_cierre_regresos'      => '-4',
            'hora_cierre_regresos'      => '23:59:59',
            'dias_cierre_solicitudes'   => '-2',
            'hora_cierre_solicitudes'   => '23:59:59',
            'dias_publicacion'          => '-1',
            'hora_publicacion'          => '00:00:00',
            'minimo_fechas_cambio'      => '3',
            'cambios_habilitados'       => '1',
            // Mirrors the seed: the alert ships OFF with no address.
            'notificacion_nueva_destino' => '',
            'mensaje_deshabilitado'     => 'Por el momento no es posible gestionar los cambios de forma online. Por favor contactate con la Comisión Directiva.',
            // Mirrors the SEED exactly — exención ON, prioridad de padres
            // absent (= unchecked) — so `guardar( [] )` is a genuine no-op
            // and a test asserting "nothing changed" cannot pass by accident.
            'exencion_arco_activa'      => '1',
        ];

        foreach ( $desmarcar as $nombre ) {
            unset( $form[ $nombre ] );
        }

        return array_merge( $form, $overrides );
    }

    /** Whether the checkbox named $nombre carries WordPress's checked attribute. */
    private function estaTildado( string $html, string $nombre ): bool {
        return 1 === preg_match(
            '/name="' . preg_quote( $nombre, '/' ) . '"\s+value="1"\s+checked=\'checked\'/',
            $html
        );
    }

    private function renderPagina(): string {
        $GLOBALS['wp_test_current_user_can'] = [ ProcessOwnerAuthorizer::CAPABILITY => true ];

        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }

    private function bandejaPageFake( \wpdb $wpdb ): BandejaPage {
        return new BandejaPage(
            new ProcessOwnerAuthorizer(),
            new \EntreRedes\Cambios\Solicitudes\SolicitudRepository(
                $wpdb,
                new \EntreRedes\Cambios\Plazas\PlazaRepository( $wpdb, $this->eventLog ),
                new \EntreRedes\Cambios\Dictamen\DictamenPipeline(
                    new \EntreRedes\Cambios\Dictamen\DictamenContextAssembler(
                        new \EntreRedes\Cambios\Plazas\PlazaRepository( $wpdb, $this->eventLog ),
                        new \EntreRedes\Cambios\Calendario\FechaRepository( $wpdb, new InMemoryEventLog() ),
                        $this->settings,
                        $wpdb,
                        $this->eventLog
                    ),
                    $this->eventLog
                ),
                $this->eventLog
            ),
            new \EntreRedes\Cambios\Plazas\PlazaRepository( $wpdb, $this->eventLog ),
            $this->settings,
            $this->eventLog,
            new \EntreRedes\Cambios\Plazas\JugadorMetricasReader( $wpdb, $this->eventLog )
        );
    }

    private function historicoPageFake( \wpdb $wpdb ): HistoricoPage {
        $plazaRepository = new \EntreRedes\Cambios\Plazas\PlazaRepository( $wpdb, $this->eventLog );

        return new HistoricoPage(
            new ProcessOwnerAuthorizer(),
            new \EntreRedes\Cambios\Solicitudes\SolicitudRepository(
                $wpdb,
                $plazaRepository,
                new \EntreRedes\Cambios\Dictamen\DictamenPipeline(
                    new \EntreRedes\Cambios\Dictamen\DictamenContextAssembler(
                        $plazaRepository,
                        new \EntreRedes\Cambios\Calendario\FechaRepository( $wpdb, new InMemoryEventLog() ),
                        $this->settings,
                        $wpdb,
                        $this->eventLog
                    ),
                    $this->eventLog
                ),
                $this->eventLog
            ),
            $plazaRepository,
            new \EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader( $wpdb, $this->eventLog ),
            new \EntreRedes\Cambios\Calendario\FechaRepository( $wpdb, new InMemoryEventLog() ),
            $this->settings,
            $this->eventLog
        );
    }
}
