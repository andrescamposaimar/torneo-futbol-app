<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\AdminMenu;
use EntreRedes\Cambios\Admin\BandejaPage;
use EntreRedes\Cambios\Admin\HistoricoPage;
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
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Solicitudes\SolicitudRepository;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for HistoricoPage — built against REAL repositories, the
 * same style as BandejaPageTest, because the two facts this screen exists to
 * state are both derived ones: "is this substitution liberable yet" comes out
 * of the real ocupación chain and the real fecha calendar, and "who left"
 * comes out of a column frozen at write time. Neither can be proven against
 * a stub that simply returns the answer.
 *
 * `render()` is safe to call directly here: unlike BandejaPage's POST
 * handlers, this page writes nothing and never reaches `wp_safe_redirect()`
 * + `exit`.
 */
class HistoricoPageTest extends TestCase {

    private const SEASON_ID = 359;
    private const TEAM_ID   = 100;

    /** Which plaza crearYRechazar() files its solicitud against — see that helper. */
    private const PLAZA_DE_PRUEBA = 3;

    private PlazaRepository $plazaRepository;
    private SolicitudRepository $solicitudRepository;
    private InMemoryEventLog $eventLog;
    private HistoricoPage $page;
    private DictamenPipeline $pipeline;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb, $wp_test_post_titles;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        $wp_test_post_titles = [
            self::TEAM_ID => 'SUIZA',
            777           => 'Titular Perez',
            888           => 'Suplente Gomez',
            999           => 'Otro Lopez',
        ];

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

        $this->pipeline            = new DictamenPipeline( $assembler, $this->eventLog );
        $this->solicitudRepository = new SolicitudRepository(
            $wpdb,
            $this->plazaRepository,
            $this->pipeline,
            $this->eventLog
        );

        $this->page = $this->construirPagina( $wpdb );

        unset( $GLOBALS['wp_test_current_user_can'] );
        $_GET = [];
    }

    /**
     * Builds the page under test, optionally with a substituted collaborator
     * — the only way to reach the two degradation paths (an untrustworthy
     * fecha count, a section that cannot be read) from outside.
     */
    private function construirPagina(
        \wpdb $wpdb,
        ?FechaRepository $fechaRepository = null,
        ?SolicitudRepository $solicitudRepository = null
    ): HistoricoPage {
        return new HistoricoPage(
            new ProcessOwnerAuthorizer(),
            $solicitudRepository ?? $this->solicitudRepository,
            $this->plazaRepository,
            new SolicitudesAbiertasReader( $wpdb, $this->eventLog ),
            $fechaRepository ?? new FechaRepository( $wpdb, new InMemoryEventLog() ),
            new Settings( $wpdb, $this->eventLog ),
            $this->eventLog
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_decision', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }

        unset( $GLOBALS['wp_test_current_user_can'], $GLOBALS['wp_test_post_titles'] );
        $_GET = [];
    }

    // -------------------------------------------------------------------------
    // Permission
    // -------------------------------------------------------------------------

    public function test_render_sin_capacidad_no_muestra_nada(): void {
        $GLOBALS['wp_test_current_user_can'] = false;

        $this->expectException( \RuntimeException::class );

        $this->page->render();
    }

    // -------------------------------------------------------------------------
    // Vigentes — question 1
    // -------------------------------------------------------------------------

    public function test_una_plaza_ocupada_por_su_titular_no_es_un_cambio_vigente(): void {
        $this->seedFecha( 1, 'jugada' );
        $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'No hay cambios vigentes', $html );
        $this->assertStringNotContainsString( 'Titular Perez', $html );
    }

    public function test_una_sustitucion_publicada_aparece_como_vigente(): void {
        $this->seedFecha( 1, 'jugada' );
        $this->seedFecha( 2, 'programada' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $html = $this->renderPagina();

        $this->assertStringNotContainsString( 'No hay cambios vigentes', $html );
        $this->assertStringContainsString( 'SUIZA', $html );
        $this->assertStringContainsString( 'Suplente Gomez', $html, 'The table must name who is occupying the plaza.' );
        $this->assertStringContainsString( 'Titular Perez', $html, 'The table must name whose plaza it is.' );
    }

    public function test_cuando_no_se_cumplio_el_minimo_dice_cuantas_fechas_faltan(): void {
        // Only fecha 2 is resolved from the substitution onwards, so 2 of the
        // 3 required fechas are still missing.
        $this->seedFecha( 1, 'jugada' );
        $this->seedFecha( 2, 'jugada' );
        $this->seedFecha( 3, 'programada' );
        $this->seedFecha( 4, 'programada' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'Faltan 2 fechas para poder volver.', $html );
        $this->assertStringNotContainsString( 'nadie pidió el regreso', $html );
    }

    public function test_cuando_ya_es_liberable_y_nadie_pidio_el_regreso_lo_dice(): void {
        // Three resolved fechas from the substitution onwards — the minimum
        // is met, and nothing was ever requested.
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId, 'jugada' );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'El titular ya puede volver', $html );
        $this->assertStringContainsString( 'nadie pidió el regreso', $html );
    }

    public function test_un_regreso_ya_pedido_reemplaza_el_aviso_en_lugar_de_insistir(): void {
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId, 'jugada' );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $solicitudId = $this->solicitudRepository->crear(
            SolicitudDeCambio::regreso( self::SEASON_ID, self::TEAM_ID, $plazaId, 4, time() ),
            777,
            Dictamen::from( [] ),
            '2026-04-01 10:00:00'
        );

        $html = $this->renderPagina();

        // "(Pendiente)", not "(pendiente)": the estado goes through
        // etiquetaEstado(), so the operator never reads a raw enum here.
        $this->assertStringContainsString( 'Solicitud #' . $solicitudId . ' abierta (Pendiente)', $html );
        $this->assertStringNotContainsString( 'abierta (pendiente)', $html );
        $this->assertStringNotContainsString(
            'nadie pidió el regreso',
            $html,
            'A plaza whose regreso is already filed must not be reported as abandoned — it is sitting in the tray.'
        );
    }

    public function test_cuando_el_conteo_no_es_confiable_lo_dice_en_vez_de_inventar_un_cero(): void {
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId, 'jugada' );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        // A counter that claims MORE resolved fechas than the season has is
        // impossible, and BoundedFechaCounter turns it into
        // FechaCountUnavailableException. This is the only honest way to
        // reach the degradation from outside.
        $html = $this->renderPaginaCon( $this->fechaRepositoryQueInflaElConteo() );

        $this->assertStringContainsString( 'No se pudo calcular cuánto falta.', $html );
        $this->assertStringNotContainsString(
            'El titular ya puede volver',
            $html,
            'A count that could not be trusted must NEVER be reported as "0 fechas missing".'
        );
        $this->assertStringNotContainsString( 'Faltan', $html );
        $this->assertTrue(
            $this->eventLog->has( 'admin.historico_conteo_no_confiable' ),
            'The degradation must leave a trace, aggregated once per render.'
        );
    }

    /**
     * The race the empty-chain guard exists for: the ocupación row is read,
     * and by the time its chain is fetched the row is gone. CadenaResolver
     * throws \InvalidArgumentException on an empty chain, documented as a
     * CALLER bug — true for its other callers, not for this one. Letting it
     * propagate would blank a read-only page over a row that no longer
     * exists, blaming the wrong thing.
     */
    public function test_si_la_cadena_desaparece_entre_las_dos_consultas_degrada_en_vez_de_explotar(): void {
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId, 'jugada' );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        global $wpdb;
        $this->plazaRepository = $this->plazaRepositoryQuePierdeLaCadena();
        $this->page            = $this->construirPagina( $wpdb );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'No se pudo calcular cuánto falta.', $html );
        $this->assertStringContainsString( 'Suplente Gomez', $html, 'The row itself must still render.' );
        $this->assertStringNotContainsString( 'El titular ya puede volver', $html );
        $this->assertStringNotContainsString( 'No se pudo cargar esta sección', $html, 'This must degrade the ROW, not the section.' );
        $this->assertTrue( $this->eventLog->has( 'admin.historico_conteo_no_confiable' ) );
    }

    // -------------------------------------------------------------------------
    // Histórico — question 2
    // -------------------------------------------------------------------------

    public function test_una_solicitud_pendiente_no_esta_en_el_historico(): void {
        $this->seedFecha( 1, 'programada' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'Todavía no se resolvió ninguna solicitud', $html );
    }

    public function test_una_solicitud_rechazada_aparece_con_quien_la_decidio(): void {
        $id = $this->crearYRechazar();

        $html = $this->renderPagina();

        $this->assertStringNotContainsString( 'Todavía no se resolvió ninguna solicitud', $html );
        // NOT a bare 'Rechazada': renderFiltros() prints one <option>Rechazada</option>
        // for every terminal estado whatever the data, so the plain word
        // would pass against an EMPTY table. Only the row prints it in bold.
        $this->assertStringContainsString( '<strong>Rechazada</strong>', $html );
        $this->assertStringContainsString( 'La comision', $html, 'The decision chain must name who decided.' );
        $this->assertStringContainsString( 'no corresponde', $html, 'The note must be shown.' );

        // The id cell, pinned as a whole cell. crearYRechazar() deliberately
        // puts the solicitud on a plaza whose id differs from the solicitud's
        // own, so printing the wrong one of the two fails here.
        $this->assertMatchesRegularExpression( '#<td>\s*' . $id . '\s*</td>#', $html );
        $this->assertStringContainsString( 'plaza #' . self::PLAZA_DE_PRUEBA, $html );
    }

    /**
     * THE regression this screen's own shaping exists for. The tray derives
     * "quién sale" from the plaza's CURRENT vigent ocupación; doing that here
     * would make an old row relabel itself with whoever holds the plaza
     * today. The frozen `saliente_player_id` is the only honest answer.
     */
    public function test_el_saliente_del_historico_es_el_congelado_no_el_ocupante_de_hoy(): void {
        $this->seedFecha( 1, 'jugada' );
        $this->seedFecha( 2, 'programada' );

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        // Created while "Titular Perez" (777) still holds the plaza, so that
        // is the saliente frozen into the row.
        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        // The plaza moves on: today it is held by somebody else entirely.
        $this->plazaRepository->succeedOcupacion( $plazaId, 999, 2, 'reemplazada', '2026-06-01 00:00:00' );

        $this->solicitudRepository->rechazar( $id, 1, null, '2026-06-02 10:00:00', 'La comision' );

        $html = $this->renderPagina();

        $this->assertStringContainsString(
            'Sale: Titular Perez',
            $html,
            'The histórico must name who the request would have replaced WHEN IT WAS MADE.'
        );
        $this->assertStringNotContainsString(
            'Sale: Otro Lopez',
            $html,
            "Re-deriving the saliente from today's chain states something false about the past."
        );
    }

    public function test_el_historico_ordena_lo_mas_reciente_primero(): void {
        $this->seedFecha( 1, 'programada' );
        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 999, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $viejo = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaA, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-01 10:00:00'
        );
        $nuevo = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaB, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-02 10:00:00'
        );

        $this->solicitudRepository->rechazar( $viejo, 1, null, '2026-05-10 10:00:00', 'La comision' );
        $this->solicitudRepository->rechazar( $nuevo, 1, null, '2026-05-20 10:00:00', 'La comision' );

        $rows = $this->solicitudRepository->listResueltas( self::SEASON_ID );

        $this->assertSame(
            [ $nuevo, $viejo ],
            array_map( static fn ( array $r ): int => (int) $r['id'], $rows ),
            'A histórico is a log: the most recently resolved row comes first.'
        );

        // And the PAGE must keep that order — the repository getting it right
        // is worth nothing if filtrar() or the render loop reshuffles.
        $html = $this->renderPagina();

        $posNuevo = strpos( $html, '<td>' . $nuevo . '</td>' );
        $posViejo = strpos( $html, '<td>' . $viejo . '</td>' );

        $this->assertIsInt( $posNuevo );
        $this->assertIsInt( $posViejo );
        $this->assertLessThan( $posViejo, $posNuevo, 'The rendered table must put the most recent row first.' );
    }

    /**
     * The `id DESC` tie-break, which the test above cannot reach because its
     * two rows resolve at different instants.
     */
    public function test_el_historico_desempata_por_id_descendente(): void {
        $this->seedFecha( 1, 'programada' );
        $plazaA = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $plazaB = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 999, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );

        $primero = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaA, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-01 10:00:00'
        );
        $segundo = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaB, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-02 10:00:00'
        );

        // The SAME instant for both, so only the tie-break can order them.
        $this->solicitudRepository->rechazar( $primero, 1, null, '2026-05-10 10:00:00', 'La comision' );
        $this->solicitudRepository->rechazar( $segundo, 1, null, '2026-05-10 10:00:00', 'La comision' );

        $this->assertSame(
            [ $segundo, $primero ],
            array_map(
                static fn ( array $r ): int => (int) $r['id'],
                $this->solicitudRepository->listResueltas( self::SEASON_ID )
            )
        );
    }

    /**
     * A `regreso` has no `entrante_player_id`: who enters is the plaza's own
     * permanent titular, derived through listPlazasPorIds(). Only a
     * sustitución was covered before.
     */
    public function test_en_un_regreso_el_que_entra_es_el_titular_de_la_plaza(): void {
        foreach ( [ 1, 2, 3, 4 ] as $fechaId ) {
            $this->seedFecha( $fechaId, 'jugada' );
        }

        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::regreso( self::SEASON_ID, self::TEAM_ID, $plazaId, 4, time() ),
            777,
            Dictamen::from( [] ),
            '2026-04-01 10:00:00'
        );
        $this->solicitudRepository->rechazar( $id, 1, null, '2026-04-02 10:00:00', 'La comision' );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'Regreso', $html );
        $this->assertStringContainsString(
            'Sale: Suplente Gomez — Entra: Titular Perez',
            $html,
            'A regreso puts the plaza\'s titular back in; the suplente is the one leaving.'
        );
    }

    /**
     * A `tipo` outside the ENUM is only reachable through a corrupt row, but
     * if it ever happens it must cost ONE row, not the whole page — the
     * difference between this read-only screen and the tray, which throws.
     */
    public function test_una_fila_con_tipo_corrupto_no_se_lleva_puesta_a_las_demas(): void {
        $idSano = $this->crearYRechazar();
        $idRoto = $this->insertarSolicitudCorrupta();

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'Tipo desconocido', $html );
        $this->assertMatchesRegularExpression( '#<td>\s*' . $idRoto . '\s*</td>#', $html, 'The corrupt row is shown, labelled.' );
        $this->assertMatchesRegularExpression(
            '#<td>\s*' . $idSano . '\s*</td>#',
            $html,
            'THE POINT: the healthy row must survive the corrupt one.'
        );
        $this->assertStringContainsString( '<strong>Rechazada</strong>', $html );
        $this->assertTrue( $this->eventLog->has( 'admin.historico_filas_invalidas_fallida' ) );
    }

    public function test_si_una_seccion_falla_la_otra_se_sigue_viendo(): void {
        $this->seedFecha( 1, 'jugada' );
        $this->seedFecha( 2, 'programada' );
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        $this->plazaRepository->succeedOcupacion( $plazaId, 888, 2, 'reemplazada', '2026-03-10 00:00:00' );

        global $wpdb;
        $this->page = $this->construirPagina( $wpdb, null, $this->solicitudRepositoryQueFallaAlListarResueltas() );

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'No se pudo cargar esta sección', $html );
        $this->assertStringContainsString(
            'Suplente Gomez',
            $html,
            'Vigentes is the ACTIONABLE table — it must survive the histórico failing.'
        );
        $this->assertTrue( $this->eventLog->has( 'admin.historico_seccion_fallida' ) );
        $this->assertSame( 'historico', $this->eventLog->last()['contexto']['seccion'] );
    }

    // -------------------------------------------------------------------------
    // Filters
    // -------------------------------------------------------------------------

    public function test_el_filtro_de_estado_deja_afuera_los_demas_estados(): void {
        $this->crearYRechazar();

        $_GET = [ 'estado' => EstadoSolicitud::PUBLICADA ];

        $html = $this->renderPagina();

        $this->assertStringContainsString( 'Ninguna solicitud resuelta coincide con el filtro.', $html );
    }

    public function test_un_estado_inventado_en_la_url_no_filtra_nada(): void {
        $this->crearYRechazar();

        $_GET = [ 'estado' => 'inventado' ];

        $html = $this->renderPagina();

        $this->assertStringNotContainsString(
            'Ninguna solicitud resuelta coincide con el filtro.',
            $html,
            'An unrecognized estado must degrade to "no filter", never to an empty table that reads as "nothing happened".'
        );
        $this->assertStringContainsString( '<strong>Rechazada</strong>', $html, 'The row itself must still be there.' );
    }

    public function test_un_estado_como_arreglo_en_la_url_no_rompe_ni_filtra(): void {
        $this->crearYRechazar();

        // `?estado[]=x` sends an ARRAY. Without the is_scalar() guard the
        // cast raises "Array to string conversion" — which PHPUnit reports
        // as a WARNING, and a warning does not fail a suite. Promoting it to
        // an exception here is what makes this test able to fail at all.
        $capturados = [];
        set_error_handler(
            static function ( int $severidad, string $mensaje ) use ( &$capturados ): bool {
                $capturados[] = $mensaje;

                return true;
            },
            E_ALL
        );

        try {
            $_GET = [ 'estado' => [ 'rechazada' ], 'equipo' => [ '100' ] ];

            $html = $this->renderPagina();
        } finally {
            restore_error_handler();
        }

        $this->assertSame( [], $capturados, 'Reading the filters must raise no PHP diagnostic at all.' );
        $this->assertStringContainsString( '<strong>Rechazada</strong>', $html );
        $this->assertStringNotContainsString( 'Ninguna solicitud resuelta coincide con el filtro.', $html );
    }

    public function test_un_equipo_inventado_en_la_url_no_filtra_nada(): void {
        $this->crearYRechazar();

        $_GET = [ 'equipo' => '4242' ];

        $html = $this->renderPagina();

        $this->assertStringNotContainsString( 'Ninguna solicitud resuelta coincide con el filtro.', $html );
        $this->assertStringContainsString( '<strong>Rechazada</strong>', $html );
    }

    // -------------------------------------------------------------------------
    // Menu wiring
    // -------------------------------------------------------------------------

    public function test_el_menu_registra_la_bandeja_y_el_historico_con_la_misma_capacidad(): void {
        $GLOBALS['_prode_test_registered_admin_menus'] = [];

        ( new AdminMenu( $this->bandejaPageFake(), $this->page ) )->register();

        $slugs = array_map(
            static fn ( array $m ): string => (string) $m['menu_slug'],
            $GLOBALS['_prode_test_registered_admin_menus']
        );

        // THREE registrations: the top-level page plus TWO submenus. Counting
        // matters — `assertContains( BandejaPage::SLUG )` alone would pass on
        // the top-level entry even if the Bandeja submenu (the one that
        // renames the auto-created duplicate from "Cambios" to "Bandeja")
        // were deleted.
        $this->assertCount( 3, $GLOBALS['_prode_test_registered_admin_menus'] );

        $submenus = array_values( array_filter(
            $GLOBALS['_prode_test_registered_admin_menus'],
            static fn ( array $m ): bool => isset( $m['parent_slug'] )
        ) );

        $this->assertCount( 2, $submenus, 'Both pages must be reachable as named submenu entries.' );
        $this->assertSame( BandejaPage::SLUG, $submenus[0]['menu_slug'], 'The tray stays first, so the menu lands on it.' );
        $this->assertSame( HistoricoPage::SLUG, $submenus[1]['menu_slug'] );

        foreach ( $submenus as $submenu ) {
            $this->assertSame( BandejaPage::SLUG, $submenu['parent_slug'] );
        }

        $this->assertContains( BandejaPage::SLUG, $slugs );
        $this->assertContains( HistoricoPage::SLUG, $slugs );

        foreach ( $GLOBALS['_prode_test_registered_admin_menus'] as $entrada ) {
            $this->assertSame(
                ProcessOwnerAuthorizer::CAPABILITY,
                $entrada['capability'],
                'Every entry of this menu is gated on gestionar_cambios, never on manage_options.'
            );
        }

        unset( $GLOBALS['_prode_test_registered_admin_menus'] );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function renderPagina(): string {
        $GLOBALS['wp_test_current_user_can'] = [ ProcessOwnerAuthorizer::CAPABILITY => true ];

        ob_start();
        $this->page->render();

        return (string) ob_get_clean();
    }

    private function renderPaginaCon( FechaRepository $fechaRepository ): string {
        global $wpdb;

        $this->page = $this->construirPagina( $wpdb, $fechaRepository );

        return $this->renderPagina();
    }

    /**
     * A FechaRepository whose countResolvedFechasSince() claims more resolved
     * fechas than listBySeason() can account for — impossible by definition,
     * which is exactly what Calendario\BoundedFechaCounter refuses to pass on.
     */
    private function fechaRepositoryQueInflaElConteo(): FechaRepository {
        global $wpdb;

        return new class( $wpdb, new InMemoryEventLog() ) extends FechaRepository {
            public function countResolvedFechasSince( int $seasonId, int $fechaId ): int {
                return 9999;
            }
        };
    }

    /**
     * A PlazaRepository that still reports the live substitution but returns
     * no chain for it — the shape a row deleted between the two queries
     * would produce.
     */
    private function plazaRepositoryQuePierdeLaCadena(): PlazaRepository {
        global $wpdb;

        return new class( $wpdb, $this->eventLog ) extends PlazaRepository {
            public function listOcupacionesDePlazas( array $plazaIds ): array {
                return [];
            }
        };
    }

    /** A SolicitudRepository whose histórico read fails, leaving Vigentes untouched. */
    private function solicitudRepositoryQueFallaAlListarResueltas(): SolicitudRepository {
        global $wpdb;

        return new class( $wpdb, $this->plazaRepository, $this->pipeline, $this->eventLog ) extends SolicitudRepository {
            public function listResueltas( int $seasonId ): array {
                throw new \RuntimeException( 'simulated read failure for test' );
            }
        };
    }

    /**
     * Writes a row whose `tipo` is outside the ENUM. Only possible because
     * the SQLite shim rewrites ENUM to TEXT — which is the point: production
     * MySQL would reject it, so this is the one place the degradation can be
     * exercised at all.
     */
    private function insertarSolicitudCorrupta(): int {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_solicitud',
            [
                'season_id'                => self::SEASON_ID,
                'team_id'                  => self::TEAM_ID,
                'plaza_id'                 => self::PLAZA_DE_PRUEBA,
                'tipo'                     => 'chamuyo',
                'fecha_id'                 => 1,
                'solicitada_por'           => 777,
                'solicitada_at'            => '2026-05-27 10:00:00',
                'solicitud_instante_epoch' => 1779000000,
                'dictamen_original'        => '{"procede":true,"motivos":[]}',
                'estado'                   => 'rechazada',
                'resuelta_at'              => '2026-05-29 10:00:00',
                'created_at'               => '2026-05-27 10:00:00',
                'updated_at'               => '2026-05-29 10:00:00',
            ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Rejects one solicitud, placed on the THIRD plaza on purpose: the first
     * solicitud of a fresh database gets id 1, and so does the first plaza,
     * so a test that pins "the id cell says 1" would pass just as happily if
     * the cell printed `plaza_id`. Two throwaway plazas push them apart.
     */
    private function crearYRechazar(): int {
        $this->seedFecha( 1, 'programada' );

        $plazaId = 0;
        for ( $i = 0; $i < self::PLAZA_DE_PRUEBA; $i++ ) {
            $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, self::TEAM_ID, 777 + $i, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 00:00:00' );
        }

        $this->assertSame( self::PLAZA_DE_PRUEBA, $plazaId, 'Fixture assumption: plaza ids start at 1 in a clean database.' );

        $id = $this->solicitudRepository->crear(
            SolicitudDeCambio::sustitucion( self::SEASON_ID, self::TEAM_ID, $plazaId, 888, 1, time() ),
            777,
            Dictamen::from( [] ),
            '2026-05-27 10:00:00'
        );

        $this->assertNotSame( $plazaId, $id, 'The whole point of this fixture is that the two ids differ.' );

        $this->solicitudRepository->rechazar( $id, 1, 'no corresponde', '2026-05-28 10:00:00', 'La comision' );

        return $id;
    }

    private function bandejaPageFake(): BandejaPage {
        global $wpdb;

        $settings        = new Settings( $wpdb, $this->eventLog );
        $fechaRepository = new FechaRepository( $wpdb, new InMemoryEventLog() );

        return new BandejaPage(
            new ProcessOwnerAuthorizer(),
            $this->solicitudRepository,
            $this->plazaRepository,
            $settings,
            $this->eventLog,
            new \EntreRedes\Cambios\Plazas\JugadorMetricasReader( $wpdb, $this->eventLog )
        );
    }

    private function seedFecha( int $fechaId, string $estado ): void {
        global $wpdb;

        $playDate = sprintf( '2026-03-%02d', $fechaId );

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
                'estado'             => $estado,
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }
}
