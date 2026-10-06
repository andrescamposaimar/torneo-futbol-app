<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Dictamen\DictamenContextAssembler;
use EntreRedes\Cambios\Dictamen\DictamenPipeline;
use EntreRedes\Cambios\Dictamen\SolicitudDeCambio;
use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DictamenPipeline — the single production entry
 * point composing DictamenContextAssembler + DictamenEngineFactory (see
 * that class's docblock, and README's "Contracts for slice 4" point 2).
 */
class DictamenPipelineTest extends TestCase {

    private const SEASON_ID = 359;

    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private InMemoryEventLog $eventLog;
    private DictamenPipeline $pipeline;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );

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
        $this->fechaRepository = new FechaRepository( $wpdb, new InMemoryEventLog() );
        $settings              = new Settings( $wpdb );

        $assembler = new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            $settings,
            $wpdb,
            $this->eventLog
        );

        $this->pipeline = new DictamenPipeline( $assembler, $this->eventLog );

        $this->seedFecha( 1, self::SEASON_ID, '2026-01-03' );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );
        $wp_test_position_terms = []; // see tests/wp-shim.php's wp_get_object_terms() docblock
    }

    private function seedFecha( int $fechaId, int $seasonId, string $playDate = '2026-05-30' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id'                 => $fechaId,
                'season_id'          => $seasonId,
                'orden'              => $fechaId,
                'torneo_liga_ids'    => '1',
                'torneo_label'       => 'Apertura',
                'numero_en_torneo'   => $fechaId,
                'play_date'          => $playDate,
                'play_date_original' => $playDate,
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

    public function test_evaluate_returns_a_dictamen_for_a_clean_regreso(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );

        $solicitud = SolicitudDeCambio::regreso(
            self::SEASON_ID,
            100,
            $plazaId,
            5,
            ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp()
        );

        $dictamen = $this->pipeline->evaluate( $solicitud );

        $this->assertTrue( $dictamen->procede() );
    }

    public function test_evaluate_logs_and_rethrows_when_context_assembly_fails(): void {
        $solicitud = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, 999999, 888, 5, time() );

        try {
            $this->pipeline->evaluate( $solicitud );
            $this->fail( 'Expected a RuntimeException from a nonexistent plaza.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'plaza 999999 does not exist', $e->getMessage() );
        }

        $this->assertTrue( $this->eventLog->has( 'dictamen.fallido' ) );
        $last = $this->eventLog->last();
        $this->assertSame( self::SEASON_ID, $last['contexto']['season_id'] );
        $this->assertSame( 100, $last['contexto']['team_id'] );
        $this->assertSame( 999999, $last['contexto']['plaza_id'] );
        $this->assertSame( 5, $last['contexto']['fecha_id'] );
        $this->assertSame( 'sustitucion', $last['contexto']['tipo'] );
    }

    // -------------------------------------------------------------------------
    // evaluateGrupo() — grouped goalkeeper reassignment (0.1.15)
    // -------------------------------------------------------------------------

    /**
     * @return array{plazaArcoId: int, plazaCampoId: int} 100 is the team id
     *         for both — the goal plaza (titular 111, techo 2.5) and the
     *         field plaza (titular 222, techo 3.0 — already seeded with
     *         puntaje 3.0, matching "a plaza's techo is snapshotted from its
     *         titular"). Puntajes only ever use one of the 9 valid discrete
     *         values (1..5 in steps of 0.5) — see Plazas\Puntaje's own
     *         docblock.
     */
    private function seedPlazasDeReasignacion(): array {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ]; // 111 is the goalkeeper (term 3)

        $plazaArcoId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );

        $wp_test_position_terms = []; // 222 is an ordinary field player
        $plazaCampoId           = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );

        $this->seedPuntaje( 222, 3.0 );

        return [ 'plazaArcoId' => $plazaArcoId, 'plazaCampoId' => $plazaCampoId ];
    }

    public function test_evaluate_grupo_accepts_a_titular_over_the_goal_techo_when_exencion_is_on(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 2.5 ); // the outside player, within plazaCampo's 3.0 techo

        $instante = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $legArco  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaArcoId'], 222, 5, $instante );
        $legCampo = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaCampoId'], 333, 5, $instante );

        $pipelineConExencion = new DictamenPipeline( $this->assembler(), $this->eventLog, null, false, true );

        $dictamen = $pipelineConExencion->evaluateGrupo( $legArco, $legCampo );

        $this->assertTrue( $dictamen->procede(), 'With the exemption ON, a titular (puntaje 5.0) over the goal plaza\'s techo (2.5) must still procede.' );
    }

    public function test_evaluate_grupo_rejects_a_titular_over_the_goal_techo_when_exencion_is_off(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 4.5 );

        $instante = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $legArco  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaArcoId'], 222, 5, $instante );
        $legCampo = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaCampoId'], 333, 5, $instante );

        $pipelineSinExencion = new DictamenPipeline( $this->assembler(), $this->eventLog, null, false, false );

        $dictamen = $pipelineSinExencion->evaluateGrupo( $legArco, $legCampo );

        $this->assertFalse( $dictamen->procede(), 'With the exemption OFF, movement 1 is subject to the ordinary techo and must be rejected.' );
        $this->assertNotNull( $dictamen->motivo( 'puntaje_excede_techo' ) );
    }

    /**
     * Movement 2 is judged by the ORDINARY rules regardless of the
     * exemption setting — an outside player over the VACATED plaza's own
     * techo is rejected exactly like any ordinary sustitucion.
     */
    public function test_evaluate_grupo_rejects_leg_campo_when_the_outside_player_exceeds_its_techo(): void {
        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 4.0 ); // above plazaCampo's 3.0 techo

        $instante = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $legArco  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaArcoId'], 222, 5, $instante );
        $legCampo = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaCampoId'], 333, 5, $instante );

        $dictamen = $this->pipeline->evaluateGrupo( $legArco, $legCampo );

        $this->assertFalse( $dictamen->procede() );
        $this->assertNotNull( $dictamen->motivo( 'puntaje_excede_techo' ) );
    }

    /**
     * Movement 2 also enforces "the outside player must not be a
     * goalkeeper" — `Reglas\ArqueroNoOcupaPlazaDeCampo`, completely
     * unmodified, firing on the ORDINARY (non-exempt) leg.
     */
    public function test_evaluate_grupo_rejects_leg_campo_when_the_outside_player_is_a_goalkeeper(): void {
        global $wp_test_position_terms;

        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 4.5 );
        $wp_test_position_terms[333] = [ 3 ]; // the outside player is a goalkeeper

        $instante = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $legArco  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaArcoId'], 222, 5, $instante );
        $legCampo = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaCampoId'], 333, 5, $instante );

        $dictamen = $this->pipeline->evaluateGrupo( $legArco, $legCampo );

        $this->assertFalse( $dictamen->procede() );
        $this->assertNotNull( $dictamen->motivo( 'arquero_no_ocupa_plaza_de_campo' ) );
    }

    /**
     * UNION, not "the first leg's motivos only" — a request broken on BOTH
     * legs at once must report motivos from BOTH.
     */
    public function test_evaluate_grupo_unions_motivos_from_both_legs(): void {
        global $wp_test_position_terms;

        $plazas = $this->seedPlazasDeReasignacion();
        $this->seedFecha( 5, self::SEASON_ID, '2026-05-30' );
        $this->seedPuntaje( 333, 4.0 ); // leg campo: over techo
        $wp_test_position_terms[333] = [ 3 ]; // leg campo: ALSO a goalkeeper

        $instante = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $legArco  = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaArcoId'], 222, 5, $instante );
        $legCampo = SolicitudDeCambio::sustitucion( self::SEASON_ID, 100, $plazas['plazaCampoId'], 333, 5, $instante );

        // exemption OFF: leg arco ALSO breaks on techo (222's puntaje 5.0 > 2.5).
        $pipelineSinExencion = new DictamenPipeline( $this->assembler(), $this->eventLog, null, false, false );

        $dictamen = $pipelineSinExencion->evaluateGrupo( $legArco, $legCampo );

        $this->assertFalse( $dictamen->procede() );
        $codigos = array_map( static fn ( $m ) => $m->codigo(), $dictamen->motivos() );
        $this->assertContains( 'puntaje_excede_techo', $codigos );
        $this->assertContains( 'arquero_no_ocupa_plaza_de_campo', $codigos );
        $this->assertGreaterThanOrEqual( 2, count( $codigos ), 'Both legs\' motivos must be present, never truncated to one.' );
    }

    private function assembler(): DictamenContextAssembler {
        global $wpdb;

        return new DictamenContextAssembler(
            $this->plazaRepository,
            $this->fechaRepository,
            new Settings( $wpdb ),
            $wpdb,
            $this->eventLog
        );
    }
}
