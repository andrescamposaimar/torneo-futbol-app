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
        $this->fechaRepository = new FechaRepository( $wpdb );
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
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( "DELETE FROM {$p}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$p}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$p}cambios_fecha" );
        $wpdb->query( "DELETE FROM {$p}postmeta" );
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

    public function test_evaluate_returns_a_dictamen_for_a_clean_regreso(): void {
        $plazaId = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
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
}
