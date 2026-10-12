<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Solicitudes\SolicitudesAbiertasReader;
use EntreRedes\Cambios\Tests\Support\SeedsSolicitudes;
use PHPUnit\Framework\TestCase;

/**
 * The reader is the single definition of "open" shared by the dictamen, the
 * roster endpoint and the candidate filter — see its class docblock. These
 * tests pin that definition against a real (SQLite) `cambios_solicitud`.
 */
class SolicitudesAbiertasReaderTest extends TestCase {
    use SeedsSolicitudes;

    private const SEASON_ID = 359;

    private InMemoryEventLog $eventLog;
    private SolicitudesAbiertasReader $reader;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
        }

        $this->eventLog = new InMemoryEventLog();
        $this->reader   = new SolicitudesAbiertasReader( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        foreach ( [ 'cambios_solicitud', 'cambios_ocupacion', 'cambios_plaza', 'cambios_fecha' ] as $table ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
        }
    }

    // --- listAbiertasPorPlaza -------------------------------------------------

    public function test_plaza_pendiente_and_aprobada_both_count_as_open(): void {
        $pendiente = $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'pendiente' ] );
        $aprobada  = $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'aprobada' ] );

        $abiertas = $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 1 );

        $this->assertSame(
            [ [ 'id' => $pendiente, 'estado' => 'pendiente' ], [ 'id' => $aprobada, 'estado' => 'aprobada' ] ],
            $abiertas
        );
    }

    /**
     * @dataProvider estadosCerrados
     */
    public function test_plaza_a_closed_request_does_not_count(string $estado): void {
        $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => $estado ] );

        $this->assertSame( [], $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 1 ) );
    }

    /** @return array<string, array{string}> */
    public static function estadosCerrados(): array {
        return [ 'publicada' => [ 'publicada' ], 'rechazada' => [ 'rechazada' ], 'anulada' => [ 'anulada' ] ];
    }

    public function test_plaza_is_matched_through_plaza_campo_id_of_a_grouped_request(): void {
        $id = $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => 1,
            'plaza_campo_id'           => 2,
            'entrante_player_id'       => 501,
            'entrante_campo_player_id' => 502,
        ] );

        $this->assertSame( [ [ 'id' => $id, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 1 ), 'the goal plaza' );
        $this->assertSame( [ [ 'id' => $id, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 2 ), 'the vacated field plaza' );
        $this->assertSame( [], $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 3 ) );
    }

    public function test_plaza_excludes_the_given_solicitud_id(): void {
        $self  = $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'aprobada' ] );
        $other = $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'pendiente' ] );

        $this->assertSame(
            [ [ 'id' => $other, 'estado' => 'pendiente' ] ],
            $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 1, $self )
        );
    }

    public function test_plaza_is_scoped_to_the_season(): void {
        $this->seedSolicitud( [ 'season_id' => 360, 'plaza_id' => 1 ] );

        $this->assertSame( [], $this->reader->listAbiertasPorPlaza( self::SEASON_ID, 1 ) );
    }

    // --- listAbiertasPorEntrante ----------------------------------------------

    public function test_entrante_matches_across_a_different_team_of_the_same_season(): void {
        $id = $this->seedSolicitud( [ 'team_id' => 200, 'plaza_id' => 50, 'entrante_player_id' => 888 ] );

        $this->assertSame( [ [ 'id' => $id, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 888 ) );
    }

    public function test_entrante_matches_on_entrante_campo_player_id(): void {
        $id = $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => 1,
            'plaza_campo_id'           => 2,
            'entrante_player_id'       => 501,
            'entrante_campo_player_id' => 502,
        ] );

        $this->assertSame( [ [ 'id' => $id, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 502 ), 'the outside filler' );
        $this->assertSame( [ [ 'id' => $id, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 501 ), 'the field titular moving into goal' );
    }

    /**
     * @dataProvider estadosCerrados
     */
    public function test_entrante_a_closed_request_does_not_count(string $estado): void {
        $this->seedSolicitud( [ 'entrante_player_id' => 888, 'estado' => $estado ] );

        $this->assertSame( [], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 888 ) );
    }

    public function test_entrante_excludes_the_given_solicitud_id_and_is_season_scoped(): void {
        $self = $this->seedSolicitud( [ 'entrante_player_id' => 888, 'estado' => 'aprobada' ] );
        $this->seedSolicitud( [ 'season_id' => 360, 'entrante_player_id' => 888 ] );

        $this->assertSame( [], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 888, $self ) );
    }

    // --- listPlayerIdsComprometidos -------------------------------------------

    public function test_comprometidos_lists_both_entrante_columns_of_open_rows_only(): void {
        $this->seedSolicitud( [ 'entrante_player_id' => 11, 'plaza_id' => 1 ] );
        $this->seedSolicitud( [ 'entrante_player_id' => 12, 'estado' => 'aprobada', 'plaza_id' => 2 ] );
        $this->seedSolicitud( [ 'entrante_player_id' => 13, 'estado' => 'publicada', 'plaza_id' => 3 ] );
        $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'plaza_id'                 => 4,
            'plaza_campo_id'           => 5,
            'entrante_player_id'       => 14,
            'entrante_campo_player_id' => 15,
        ] );
        $this->seedSolicitud( [ 'team_id' => 200, 'plaza_id' => 6, 'entrante_player_id' => 16 ] );

        $ids = $this->reader->listPlayerIdsComprometidos( self::SEASON_ID );
        sort( $ids );

        $this->assertSame( [ 11, 12, 14, 15, 16 ], $ids );
    }

    public function test_comprometidos_is_scoped_to_the_season(): void {
        $this->seedSolicitud( [ 'season_id' => 360, 'plaza_id' => 1, 'entrante_player_id' => 21, 'entrante_campo_player_id' => 22 ] );
        $this->seedSolicitud( [ 'season_id' => self::SEASON_ID, 'plaza_id' => 2, 'entrante_player_id' => 23 ] );

        $this->assertSame( [ 23 ], $this->reader->listPlayerIdsComprometidos( self::SEASON_ID ), 'Players of another season must not be reported committed.' );
    }

    // --- abiertaPorPlazaDeEquipo ----------------------------------------------

    public function test_por_plaza_de_equipo_is_one_query_and_indexes_a_grouped_request_under_both_plazas(): void {
        global $wpdb;

        $simple  = $this->seedSolicitud( [ 'plaza_id' => 1 ] );
        $grouped = $this->seedSolicitud( [
            'tipo'                     => 'reasignacion_arquero',
            'estado'                   => 'aprobada',
            'plaza_id'                 => 2,
            'plaza_campo_id'           => 3,
            'entrante_player_id'       => 501,
            'entrante_campo_player_id' => 502,
        ] );
        $this->seedSolicitud( [ 'plaza_id' => 4, 'estado' => 'publicada' ] );
        $this->seedSolicitud( [ 'team_id' => 200, 'plaza_id' => 5 ] );

        $wpdb->queries = [];

        $porPlaza = $this->reader->abiertaPorPlazaDeEquipo( self::SEASON_ID, 100 );

        $consultas = array_filter( $wpdb->queries, static fn ( string $sql ): bool => str_contains( $sql, 'cambios_solicitud' ) );
        $this->assertCount( 1, $consultas, 'the whole team must be resolved in ONE query, never one per plaza.' );

        $this->assertSame(
            [
                1 => [ 'id' => $simple, 'estado' => 'pendiente' ],
                2 => [ 'id' => $grouped, 'estado' => 'aprobada' ],
                3 => [ 'id' => $grouped, 'estado' => 'aprobada' ],
            ],
            $porPlaza
        );
    }

    public function test_por_plaza_de_equipo_oldest_open_request_wins_a_plaza(): void {
        $first = $this->seedSolicitud( [ 'plaza_id' => 1 ] );
        $this->seedSolicitud( [ 'plaza_id' => 1, 'estado' => 'aprobada' ] );

        $this->assertSame( [ 1 => [ 'id' => $first, 'estado' => 'pendiente' ] ], $this->reader->abiertaPorPlazaDeEquipo( self::SEASON_ID, 100 ) );
    }

    // --- a regreso commits its returning titular ------------------------------

    /** Opens a plaza (id returned) whose permanent titular is $titular, in season 359. */
    private function plazaConTitular( int $titular ): int {
        global $wpdb;

        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_fecha WHERE id = 1" );
        $wpdb->insert(
            $wpdb->prefix . 'cambios_fecha',
            [
                'id' => 1, 'season_id' => self::SEASON_ID, 'orden' => 1, 'torneo_liga_ids' => '1', 'torneo_label' => 'A',
                'numero_en_torneo' => 1, 'play_date' => '2026-01-01', 'play_date_original' => '2026-01-01',
                'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]
        );

        return ( new PlazaRepository( $wpdb, $this->eventLog ) )->openPlaza( self::SEASON_ID, 100, $titular, Puntaje::fromDecimal( 3.0 ), 1, '2026-01-01 00:00:00' );
    }

    public function test_an_open_regreso_commits_the_titular_of_its_plaza_in_both_player_lookups(): void {
        $plaza  = $this->plazaConTitular( 777 );
        $regreso = $this->seedSolicitud( [ 'plaza_id' => $plaza, 'tipo' => 'regreso', 'entrante_player_id' => null ] );

        $this->assertSame( [ [ 'id' => $regreso, 'estado' => 'pendiente' ] ], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 777 ) );
        $this->assertSame( [ 777 ], $this->reader->listPlayerIdsComprometidos( self::SEASON_ID ) );
    }

    public function test_a_regreso_never_blocks_itself_through_the_exclusion(): void {
        $plaza   = $this->plazaConTitular( 777 );
        $regreso = $this->seedSolicitud( [ 'plaza_id' => $plaza, 'tipo' => 'regreso', 'entrante_player_id' => null, 'estado' => 'aprobada' ] );

        $this->assertSame( [], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 777, $regreso ) );
    }

    /** @dataProvider estadosCerrados */
    public function test_a_closed_regreso_does_not_commit_its_titular( string $estado ): void {
        $plaza = $this->plazaConTitular( 777 );
        $this->seedSolicitud( [ 'plaza_id' => $plaza, 'tipo' => 'regreso', 'entrante_player_id' => null, 'estado' => $estado ] );

        $this->assertSame( [], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 777 ) );
        $this->assertSame( [], $this->reader->listPlayerIdsComprometidos( self::SEASON_ID ) );
    }

    public function test_a_sustitucion_on_a_plaza_does_not_commit_that_plazas_titular(): void {
        $plaza = $this->plazaConTitular( 777 );
        $this->seedSolicitud( [ 'plaza_id' => $plaza, 'tipo' => 'sustitucion', 'entrante_player_id' => 888 ] );

        $this->assertSame( [], $this->reader->listAbiertasPorEntrante( self::SEASON_ID, 777 ), 'Only a REGRESO brings the titular back.' );
        $this->assertSame( [ 888 ], $this->reader->listPlayerIdsComprometidos( self::SEASON_ID ) );
    }

    public function test_the_regreso_titular_comes_from_the_same_single_query(): void {
        global $wpdb;

        $plaza = $this->plazaConTitular( 777 );
        $this->seedSolicitud( [ 'plaza_id' => $plaza, 'tipo' => 'regreso', 'entrante_player_id' => null ] );
        $this->seedSolicitud( [ 'plaza_id' => $plaza + 100, 'tipo' => 'regreso', 'entrante_player_id' => null ] );

        $wpdb->queries = [];
        $this->reader->listPlayerIdsComprometidos( self::SEASON_ID );

        $this->assertCount( 1, array_filter( $wpdb->queries, static fn ( string $q ): bool => str_contains( $q, 'cambios_solicitud' ) ) );
    }

    // --- fail closed ----------------------------------------------------------

    /**
     * @return array<string, array{callable(SolicitudesAbiertasReader): mixed, string}>
     */
    public static function lecturas(): array {
        return [
            'por plaza'      => [ static fn ( SolicitudesAbiertasReader $r ) => $r->listAbiertasPorPlaza( 359, 1 ), 'listAbiertasPorPlaza' ],
            'por entrante'   => [ static fn ( SolicitudesAbiertasReader $r ) => $r->listAbiertasPorEntrante( 359, 888 ), 'listAbiertasPorEntrante' ],
            'comprometidos'  => [ static fn ( SolicitudesAbiertasReader $r ) => $r->listPlayerIdsComprometidos( 359 ), 'listPlayerIdsComprometidos' ],
            'por equipo'     => [ static fn ( SolicitudesAbiertasReader $r ) => $r->abiertaPorPlazaDeEquipo( 359, 100 ), 'abiertaPorPlazaDeEquipo' ],
        ];
    }

    /**
     * A failed query must THROW — never come back as "nothing is open",
     * which every consumer reads as permission.
     *
     * @dataProvider lecturas
     * @param callable(SolicitudesAbiertasReader): mixed $lectura
     */
    public function test_a_failed_read_throws_and_logs_instead_of_reading_as_nothing_open( callable $lectura, string $operacion ): void {
        global $wpdb;

        // A real, open row exists: if the failure were swallowed into [],
        // the assertion below would be "no open request" while one exists.
        $this->seedSolicitud( [ 'plaza_id' => 1, 'entrante_player_id' => 888 ] );

        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $wpdb );

        $failingWpdb = new class( $pdo, $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                $this->last_error = 'simulated get_results failure for test';

                return [];
            }
        };

        $eventLog = new InMemoryEventLog();
        $reader   = new SolicitudesAbiertasReader( $failingWpdb, $eventLog );

        try {
            $lectura( $reader );
            $this->fail( 'A failed read must throw, never return an empty (permissive) result.' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'simulated get_results failure', $e->getMessage() );
        }

        $this->assertTrue( $eventLog->has( 'lectura.fallida' ) );
        $this->assertSame( $operacion, $eventLog->last()['contexto']['operacion'] );
    }
}
