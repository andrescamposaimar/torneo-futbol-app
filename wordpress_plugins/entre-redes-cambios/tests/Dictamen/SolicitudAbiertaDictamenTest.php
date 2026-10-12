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
use EntreRedes\Cambios\Tests\Support\SeedsSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end (assembler + real SQLite + the full twelve-rule ruleset) tests
 * for `Reglas\PlazaSinSolicitudAbierta` and `Reglas\EntranteSinSolicitudAbierta`.
 * The per-rule unit tests prove the rules read the context correctly; THESE
 * prove the assembler fills that context correctly — which rows count as
 * open, across both column pairs, across teams, never counting the request
 * being re-evaluated, and never reading a failed query as "nothing open".
 */
class SolicitudAbiertaDictamenTest extends TestCase {
    use SeedsSolicitudes;

    private const SEASON_ID = 359;
    private const OTHER_TEAM = 200;

    private PlazaRepository $plazaRepository;
    private InMemoryEventLog $eventLog;
    private DictamenPipeline $pipeline;
    private int $instante;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
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
        $this->pipeline        = new DictamenPipeline( $this->assembler( $wpdb ), $this->eventLog );
        $this->instante        = ( new \DateTimeImmutable( '2026-05-25 12:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();

        $this->seedFecha( 1, '2026-01-03' );
        $this->seedFecha( 5, '2026-05-30' );
    }

    protected function tearDown(): void {
        global $wpdb, $wp_test_position_terms;
        $p = $wpdb->prefix;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha', 'postmeta' ] as $table ) {
            $wpdb->query( "DELETE FROM {$p}{$table}" );
        }
        $wp_test_position_terms = [];
    }

    private function assembler( \wpdb $wpdb ): DictamenContextAssembler {
        return new DictamenContextAssembler(
            $this->plazaRepository,
            new FechaRepository( $wpdb, new InMemoryEventLog() ),
            new Settings( $wpdb, $this->eventLog ),
            $wpdb,
            $this->eventLog
        );
    }

    private function seedFecha( int $fechaId, string $playDate ): void {
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
            [ 'post_id' => $playerId, 'meta_key' => 'sp_metrics', 'meta_value' => serialize( [ 'puntaje' => (string) $puntaje ] ) ]
        );
    }

    private function plazaDeCampo( int $titular = 777, int $teamId = 100 ): int {
        return $this->plazaRepository->openPlaza( self::SEASON_ID, $teamId, $titular, Puntaje::fromDecimal( 3.0 ), 1, '2026-03-01 10:00:00' );
    }

    private function sustitucion( int $plazaId, int $entrante = 888, int $teamId = 100 ): SolicitudDeCambio {
        return SolicitudDeCambio::sustitucion( self::SEASON_ID, $teamId, $plazaId, $entrante, 5, $this->instante );
    }

    /** @return string[] */
    private function codigos( SolicitudDeCambio $solicitud ): array {
        return array_map( static fn ( $m ) => $m->codigo(), $this->pipeline->evaluate( $solicitud )->motivos() );
    }

    // --- baseline -------------------------------------------------------------

    public function test_baseline_a_clean_sustitucion_procede_so_every_rejection_below_is_caused_by_the_open_request(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );

        $dictamen = $this->pipeline->evaluate( $this->sustitucion( $plazaId ) );

        $this->assertTrue( $dictamen->procede(), 'Fixture baseline must be clean: ' . implode( ',', array_map( static fn ( $m ) => $m->codigo(), $dictamen->motivos() ) ) );
    }

    // --- Rule 1: the plaza ----------------------------------------------------

    /** @dataProvider estadosAbiertos */
    public function test_a_second_request_on_a_plaza_with_an_open_one_is_rejected( string $estado ): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'estado' => $estado, 'entrante_player_id' => 999 ] );

        $codigos = $this->codigos( $this->sustitucion( $plazaId ) );

        $this->assertContains( 'plaza_con_solicitud_abierta', $codigos );
        $this->assertNotContains( 'entrante_con_solicitud_abierta', $codigos, 'A different entrante: only the plaza rule applies.' );
    }

    /** @return array<string, array{string}> */
    public static function estadosAbiertos(): array {
        return [ 'pendiente' => [ 'pendiente' ], 'aprobada' => [ 'aprobada' ] ];
    }

    /** @dataProvider estadosCerrados */
    public function test_a_closed_request_on_the_plaza_does_not_block_a_new_one( string $estado ): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'estado' => $estado, 'entrante_player_id' => 999 ] );

        $this->assertNotContains( 'plaza_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaId ) ) );
    }

    /** @return array<string, array{string}> */
    public static function estadosCerrados(): array {
        return [ 'publicada' => [ 'publicada' ], 'rechazada' => [ 'rechazada' ], 'anulada' => [ 'anulada' ] ];
    }

    public function test_a_regreso_on_a_plaza_with_an_open_request_is_rejected_too(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'entrante_player_id' => 999 ] );

        $regreso = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, $this->instante );

        $this->assertContains( 'plaza_con_solicitud_abierta', $this->codigos( $regreso ) );
    }

    // --- Rule 2: the entrante -------------------------------------------------

    public function test_naming_the_same_entrante_in_a_second_open_request_is_rejected(): void {
        $plazaA = $this->plazaDeCampo( 777 );
        $plazaB = $this->plazaDeCampo( 778 );
        $this->seedPuntaje( 888, 2.5 );
        $this->seedSolicitud( [ 'plaza_id' => $plazaA, 'entrante_player_id' => 888 ] );

        $codigos = $this->codigos( $this->sustitucion( $plazaB ) );

        $this->assertContains( 'entrante_con_solicitud_abierta', $codigos );
        $this->assertNotContains( 'plaza_con_solicitud_abierta', $codigos, 'Different plaza: only the entrante rule applies.' );
    }

    public function test_the_entrante_rule_spans_every_team_of_the_season(): void {
        $plazaPropia = $this->plazaDeCampo( 777, 100 );
        $plazaAjena  = $this->plazaDeCampo( 555, self::OTHER_TEAM );
        $this->seedPuntaje( 888, 2.5 );
        // Team 200's captain already asked for 888.
        $this->seedSolicitud( [ 'team_id' => self::OTHER_TEAM, 'plaza_id' => $plazaAjena, 'entrante_player_id' => 888 ] );

        $this->assertContains( 'entrante_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaPropia ) ) );
    }

    public function test_the_entrante_rule_does_not_cross_seasons(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        $this->seedSolicitud( [ 'season_id' => 360, 'plaza_id' => 9999, 'entrante_player_id' => 888 ] );

        $this->assertNotContains( 'entrante_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaId ) ) );
    }

    /** @dataProvider estadosCerrados */
    public function test_a_closed_request_naming_the_entrante_does_not_block_them( string $estado ): void {
        $plazaA = $this->plazaDeCampo( 777 );
        $plazaB = $this->plazaDeCampo( 778 );
        $this->seedPuntaje( 888, 2.5 );
        $this->seedSolicitud( [ 'plaza_id' => $plazaA, 'estado' => $estado, 'entrante_player_id' => 888 ] );

        $this->assertNotContains( 'entrante_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaB ) ) );
    }

    public function test_the_entrante_rule_matches_the_outside_filler_of_an_open_grouped_request(): void {
        $plazaB = $this->plazaDeCampo( 778 );
        $this->seedPuntaje( 502, 2.5 );
        // Grouped request: field titular 501 -> goal (plaza 1), outside player 502 -> field plaza 2.
        $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => 9001,
            'plaza_campo_id'           => 9002,
            'entrante_player_id'       => 501,
            'entrante_campo_player_id' => 502,
        ] );

        $this->assertContains( 'entrante_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaB, 502 ) ), 'entrante_campo_player_id must count.' );
    }

    /**
     * The mirror of "an open regreso commits its titular": a regreso whose
     * returning titular (777, the plaza's titular) is already the entrante of
     * ANOTHER open request is refused on the entrante code.
     */
    public function test_a_regreso_is_refused_when_its_titular_is_already_committed_by_another_request(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedSolicitud( [ 'plaza_id' => 9001, 'entrante_player_id' => 777 ] );

        $regreso = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, $this->instante );

        $this->assertContains( 'entrante_con_solicitud_abierta', $this->codigos( $regreso ) );
    }

    public function test_a_regreso_with_no_competing_commitment_raises_neither_open_request_code(): void {
        $plazaId = $this->plazaDeCampo();
        // Unrelated open request on another plaza naming someone else.
        $this->seedSolicitud( [ 'plaza_id' => 9001, 'entrante_player_id' => 999 ] );

        $codigos = $this->codigos( SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, $this->instante ) );

        $this->assertNotContains( 'entrante_con_solicitud_abierta', $codigos );
        $this->assertNotContains( 'plaza_con_solicitud_abierta', $codigos );
    }

    /**
     * THE FALSE POSITIVE: once stored, the regreso's own row relates its plaza
     * to its titular. Re-evaluated at publish time WITH its own id it must not
     * collide with itself; without the id (the control) it would.
     */
    public function test_a_stored_regreso_does_not_commit_its_titular_against_itself(): void {
        $plazaId = $this->plazaDeCampo();
        $rowId   = $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'tipo' => 'regreso', 'entrante_player_id' => null, 'estado' => 'aprobada' ] );

        $regreso = SolicitudDeCambio::regreso( self::SEASON_ID, 100, $plazaId, 5, $this->instante );

        $this->assertContains( 'entrante_con_solicitud_abierta', $this->codigos( $regreso ), 'Control: without the id the row collides with itself.' );
        $this->assertNotContains( 'entrante_con_solicitud_abierta', $this->codigos( $regreso->conSolicitudId( $rowId ) ) );
        $this->assertNotContains( 'plaza_con_solicitud_abierta', $this->codigos( $regreso->conSolicitudId( $rowId ) ) );
    }

    // --- the request never collides with itself -------------------------------

    public function test_a_request_re_evaluated_with_its_own_row_id_does_not_collide_with_itself(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        $rowId = $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'estado' => 'aprobada', 'entrante_player_id' => 888 ] );

        $sinId = $this->codigos( $this->sustitucion( $plazaId ) );
        $conId = $this->codigos( $this->sustitucion( $plazaId )->conSolicitudId( $rowId ) );

        $this->assertContains( 'plaza_con_solicitud_abierta', $sinId, 'Control: without the id the row collides with itself.' );
        $this->assertContains( 'entrante_con_solicitud_abierta', $sinId );
        $this->assertNotContains( 'plaza_con_solicitud_abierta', $conId );
        $this->assertNotContains( 'entrante_con_solicitud_abierta', $conId );
    }

    public function test_excluding_its_own_id_does_not_hide_a_DIFFERENT_open_request_on_the_same_plaza(): void {
        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        $rowId = $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'estado' => 'aprobada', 'entrante_player_id' => 888 ] );
        $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'estado' => 'pendiente', 'entrante_player_id' => 999 ] );

        $this->assertContains( 'plaza_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazaId )->conSolicitudId( $rowId ) ) );
    }

    // --- the grouped request: both plazas, both entrantes ---------------------

    /**
     * @return array{arco: int, campo: int}
     */
    private function plazasDeReasignacion(): array {
        global $wp_test_position_terms;
        $wp_test_position_terms = [ 111 => [ 3 ] ]; // 111 is the goalkeeper

        $arco = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 2.5 ), 1, '2026-01-01 00:00:00' );

        $wp_test_position_terms = [];
        $campo                  = $this->plazaRepository->openPlaza( self::SEASON_ID, 100, 222, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );

        $this->seedPuntaje( 222, 3.0 );
        $this->seedPuntaje( 333, 2.5 );

        return [ 'arco' => $arco, 'campo' => $campo ];
    }

    public function test_a_clean_grouped_request_procede(): void {
        $plazas = $this->plazasDeReasignacion();

        $dictamen = $this->pipeline->evaluateGrupo( $this->sustitucion( $plazas['arco'], 222 ), $this->sustitucion( $plazas['campo'], 333 ) );

        $this->assertTrue( $dictamen->procede(), implode( ',', array_map( static fn ( $m ) => $m->codigo(), $dictamen->motivos() ) ) );
    }

    public function test_an_open_grouped_request_blocks_an_ordinary_request_on_either_of_its_plazas(): void {
        $plazas = $this->plazasDeReasignacion();
        $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => $plazas['arco'],
            'plaza_campo_id'           => $plazas['campo'],
            'entrante_player_id'       => 222,
            'entrante_campo_player_id' => 333,
        ] );

        $this->assertContains( 'plaza_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazas['arco'], 888 ) ), 'the goal plaza' );
        $this->assertContains( 'plaza_con_solicitud_abierta', $this->codigos( $this->sustitucion( $plazas['campo'], 889 ) ), 'the vacated field plaza' );
    }

    public function test_a_grouped_request_is_judged_on_both_legs_and_each_motivo_is_attributed_to_its_leg(): void {
        $plazas = $this->plazasDeReasignacion();
        // An open request already holds the FIELD plaza AND names the outside player.
        $this->seedSolicitud( [ 'plaza_id' => $plazas['campo'], 'entrante_player_id' => 333 ] );

        $dictamen = $this->pipeline->evaluateGrupo( $this->sustitucion( $plazas['arco'], 222 ), $this->sustitucion( $plazas['campo'], 333 ) );

        $this->assertFalse( $dictamen->procede() );

        $porCodigo = [];
        foreach ( $dictamen->motivos() as $motivo ) {
            $porCodigo[ $motivo->codigo() ][] = $motivo->movimiento();
        }

        $this->assertSame( [ 'campo' ], $porCodigo['plaza_con_solicitud_abierta'] ?? null, 'Only the field leg collides on its plaza.' );
        $this->assertSame( [ 'campo' ], $porCodigo['entrante_con_solicitud_abierta'] ?? null, 'Only the outside player is committed.' );
    }

    public function test_a_grouped_request_whose_field_titular_is_already_committed_elsewhere_fails_on_the_arco_leg(): void {
        $plazas = $this->plazasDeReasignacion();
        $otra   = $this->plazaDeCampo( 779 );
        // 222 (the field titular who would move into goal) is named by an open request elsewhere.
        $this->seedSolicitud( [ 'plaza_id' => $otra, 'entrante_player_id' => 222 ] );

        $dictamen = $this->pipeline->evaluateGrupo( $this->sustitucion( $plazas['arco'], 222 ), $this->sustitucion( $plazas['campo'], 333 ) );

        $porCodigo = [];
        foreach ( $dictamen->motivos() as $motivo ) {
            $porCodigo[ $motivo->codigo() ][] = $motivo->movimiento();
        }

        $this->assertSame( [ 'arco' ], $porCodigo['entrante_con_solicitud_abierta'] ?? null );
    }

    // --- fail closed ----------------------------------------------------------

    public function test_a_failed_open_request_read_aborts_the_dictamen_instead_of_permitting_the_request(): void {
        global $wpdb;

        $plazaId = $this->plazaDeCampo();
        $this->seedPuntaje( 888, 2.5 );
        // A real open request exists; a fail-OPEN assembler would still say "procede".
        $this->seedSolicitud( [ 'plaza_id' => $plazaId, 'entrante_player_id' => 999 ] );

        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $wpdb );

        $failingWpdb = new class( $pdo, $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, 'cambios_solicitud' ) ) {
                    $this->last_error = 'simulated get_results failure for test';

                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };

        $pipeline = new DictamenPipeline( $this->assembler( $failingWpdb ), $this->eventLog );

        try {
            $pipeline->evaluate( $this->sustitucion( $plazaId ) );
            $this->fail( 'A failed read of open requests must abort the dictamen, never produce one.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'simulated get_results failure', $e->getMessage() );
        }

        $this->assertTrue( $this->eventLog->has( 'dictamen.fallido' ) );
    }
}
