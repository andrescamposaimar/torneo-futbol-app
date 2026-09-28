<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Migrations\InitialSchema;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
use EntreRedes\Cambios\Plazas\Exception\PlazaPersistenceException;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for PlazaRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap (see InitialSchema's class docblock): the dbDelta
 * shim drops every KEY/INDEX line, so "at most one vigent ocupación per
 * plaza" was never a UNIQUE KEY to begin with (mirrors
 * CapitanRepositoryTest's note on cambios_capitan). These tests assert that
 * PROPERTY directly rather than relying on any DB constraint.
 */
class PlazaRepositoryTest extends TestCase {

    private const SEASON_ID = 359;
    private const OTHER_SEASON_ID = 999;

    private PlazaRepository $repo;
    private InMemoryEventLog $eventLog;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_fecha" );

        // Every fecha_id this test file passes to openPlaza() / succeedOcupacion() /
        // closeOcupacionByRegresoTitular() must exist in cambios_fecha (see
        // PlazaRepository::assertFechaExistsInSeason()) — seed them all here,
        // once, rather than scattering fixture rows through individual tests.
        foreach ( [ 1, 4, 5, 7, 9, 11, 13 ] as $fechaId ) {
            $this->seedFecha( $fechaId, self::SEASON_ID );
        }
        $this->seedFecha( 900, self::OTHER_SEASON_ID );

        $this->eventLog = new InMemoryEventLog();
        $this->repo     = new PlazaRepository( $wpdb, $this->eventLog );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_fecha" );
    }

    /**
     * Minimal cambios_fecha fixture row — only the columns
     * assertFechaExistsInSeason() and NOT NULL constraints require.
     */
    private function seedFecha( int $fechaId, int $seasonId ): void {
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
                'play_date'          => '2026-01-01',
                'play_date_original' => '2026-01-01',
                'created_at'         => '2026-01-01 00:00:00',
                'updated_at'         => '2026-01-01 00:00:00',
            ]
        );
    }

    private function countVigentesFor( int $plazaId ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_ocupacion
                  WHERE plaza_id = %d AND fecha_hasta_id IS NULL",
                $plazaId
            )
        );
    }

    private function countOcupacionesFor( int $plazaId ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_ocupacion WHERE plaza_id = %d",
                $plazaId
            )
        );
    }

    /**
     * Builds a \wpdb subclass sharing the SAME underlying PDO connection as
     * the real (shim) $wpdb, whose insert() unconditionally returns false —
     * mirrors CapitanRepositoryTest::wpdbThatFailsOn() exactly, for the same
     * reason (simulating a real wpdb write failure, which never throws).
     */
    private function wpdbThatFailsOn( \wpdb $real, string $method ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return match ( $method ) {
            'insert' => new class( $pdo, $real->prefix ) extends \wpdb {
                public function __construct( \PDO $pdo, string $prefix ) {
                    $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                    $ref->setValue( $this, $pdo );
                    $this->prefix = $prefix;
                }

                public function insert( string $table, array $data, mixed $format = null ): int|false {
                    $this->last_error = 'simulated insert failure for test';
                    return false;
                }
            },
            default => throw new \InvalidArgumentException( "Unsupported method: {$method}" ),
        };
    }

    /**
     * closeOcupacion() no longer uses `$wpdb->update()` — it can't express
     * the `fecha_hasta_id IS NULL` compare-and-swap guard through it (see
     * PlazaRepository::closeOcupacion()'s docblock) — so simulating "the
     * close write fails at the wpdb level" now means making the raw
     * `$wpdb->query()` call fail, not `update()`. Matches the CAS UPDATE's
     * shape specifically, so every OTHER query() call (START TRANSACTION,
     * COMMIT, ROLLBACK, and any other UPDATE) still runs for real.
     */
    private function wpdbThatFailsCloseOcupacion( \wpdb $real ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function query( string $sql ): int|false {
                if (
                    str_contains( $sql, 'UPDATE' )
                    && str_contains( $sql, 'cambios_ocupacion' )
                    && str_contains( $sql, 'fecha_hasta_id IS NULL' )
                ) {
                    $this->last_error = 'simulated close failure for test';
                    return false;
                }

                return parent::query( $sql );
            }
        };
    }

    /**
     * Like wpdbThatFailsOn(), but fails insert() ONLY for a specific table —
     * every other insert() call delegates to a real, working insert. Used to
     * pin down exactly which of openPlaza()'s two inserts failed, instead of
     * failing both indiscriminately.
     */
    private function wpdbThatFailsInsertOnTable( \wpdb $real, string $tableSuffix ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $tableSuffix ) extends \wpdb {
            private string $failingTableSuffix;

            public function __construct( \PDO $pdo, string $prefix, string $failingTableSuffix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix             = $prefix;
                $this->failingTableSuffix = $failingTableSuffix;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                if ( str_ends_with( $table, $this->failingTableSuffix ) ) {
                    $this->last_error = 'simulated insert failure for test';
                    return false;
                }

                return parent::insert( $table, $data, $format );
            }
        };
    }

    /**
     * Simulates data corruption (or a bug elsewhere) by closing every vigent
     * ocupación of a plaza directly via raw SQL, bypassing the repository
     * entirely — leaving the plaza in a state PlazaRepository's own API can
     * never itself produce (a plaza with zero vigent ocupaciones), so the
     * "no vigent ocupación" branches of succeedOcupacion() and
     * closeOcupacionByRegresoTitular() can be exercised.
     */
    private function closeEveryVigenteRawSql( int $plazaId ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}cambios_ocupacion
                    SET fecha_hasta_id = 999, cerrada_por = 'trunca'
                  WHERE plaza_id = %d AND fecha_hasta_id IS NULL",
                $plazaId
            )
        );
    }

    // -------------------------------------------------------------------------
    // openPlaza
    // -------------------------------------------------------------------------

    public function test_open_plaza_creates_the_plaza_and_its_titular_ocupacion(): void {
        $plazaId = $this->repo->openPlaza(
            359,
            100,
            777,
            Puntaje::fromDecimal( 3.0 ),
            'campo',
            1,
            '2026-03-01 10:00:00'
        );

        $this->assertGreaterThan( 0, $plazaId );

        $plaza = $this->repo->findPlaza( $plazaId );
        $this->assertNotNull( $plaza );
        $this->assertSame( 777, (int) $plaza['titular_player_id'] );
        $this->assertSame( 6, (int) $plaza['puntaje_techo'] );
        $this->assertSame( 'campo', (string) $plaza['tipo'] );

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertNotNull( $vigente );
        $this->assertSame( 777, (int) $vigente['player_id'] );
        $this->assertSame( 1, (int) $vigente['es_genesis'] );
        $this->assertSame( 1, (int) $vigente['fecha_desde_id'] );
        $this->assertNull( $vigente['fecha_hasta_id'] );

        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ) );
    }

    public function test_open_plaza_rejects_an_invalid_tipo(): void {
        $this->expectException( \InvalidArgumentException::class );

        $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'banco', 1, '2026-03-01 10:00:00' );
    }

    public function test_open_plaza_rolls_back_when_the_FIRST_insert_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsInsertOnTable( $wpdb, 'cambios_plaza' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $plazaCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_plaza" );
        $this->assertSame( 0, $plazaCount, 'No plaza row must survive when the FIRST insert (cambios_plaza) failed.' );

        $ocupacionCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_ocupacion" );
        $this->assertSame( 0, $ocupacionCount, 'The genesis ocupación must never have been attempted for a plaza that never got an id.' );
    }

    /**
     * A `wpdb->insert()` that reports success (`1`, not `false`) but leaves
     * `insert_id <= 0` — a case `false === $result` alone would miss. This
     * mirrors the real-world contract risk documented on
     * PlazaRepository::openPlaza(): `$plazaId <= 0` must be checked
     * explicitly, not inferred from the insert's own return value.
     */
    private function wpdbThatReturnsZeroInsertIdForTable( \wpdb $real, string $tableSuffix ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $tableSuffix ) extends \wpdb {
            private string $targetTableSuffix;

            public function __construct( \PDO $pdo, string $prefix, string $targetTableSuffix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix           = $prefix;
                $this->targetTableSuffix = $targetTableSuffix;
            }

            public function insert( string $table, array $data, mixed $format = null ): int|false {
                $result = parent::insert( $table, $data, $format );

                if ( str_ends_with( $table, $this->targetTableSuffix ) ) {
                    // The row really was inserted (so a real auto-increment id
                    // exists) — we only lie about what wpdb reports back, to
                    // simulate the documented but otherwise unreachable
                    // insert_id <= 0 contract violation.
                    $this->insert_id = 0;
                }

                return $result;
            }
        };
    }

    public function test_open_plaza_rolls_back_when_the_plaza_insert_id_is_not_positive(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatReturnsZeroInsertIdForTable( $wpdb, 'cambios_plaza' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $plazaCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_plaza" );
        $this->assertSame( 0, $plazaCount, 'The row inserted under a bogus insert_id must be rolled back, not left orphaned.' );

        $ocupacionCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_ocupacion" );
        $this->assertSame( 0, $ocupacionCount );
    }

    // -------------------------------------------------------------------------
    // never two vigent ocupaciones for the same plaza — THE property
    // -------------------------------------------------------------------------

    public function test_never_two_vigent_ocupaciones_across_a_chain_of_successions(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );

        $this->repo->succeedOcupacion( $plazaId, 999, 9, 'trunca', '2026-05-01 10:00:00' );
        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 13, '2026-06-01 10:00:00' );
        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );

        $this->assertSame( 4, $this->countOcupacionesFor( $plazaId ), 'Every link must be preserved as history.' );
    }

    /**
     * A \wpdb whose FIRST `query()` call matching closeOcupacion()'s
     * compare-and-swap UPDATE shape (contains `fecha_hasta_id IS NULL` in an
     * UPDATE against cambios_ocupacion) first executes a "concurrent"
     * raw UPDATE closing the SAME row — no `IS NULL` guard, simulating
     * another request that already won the race — before letting the real
     * CAS UPDATE run. This reproduces the exact TOCTOU window
     * succeedOcupacion() cannot see from the outside: its own
     * findOcupacionVigente() read already happened (it still sees the row as
     * vigent), but by the time its UPDATE executes, the row has already been
     * closed by someone else.
     */
    private function wpdbThatRacesToCloseConcurrently( \wpdb $real ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix ) extends \wpdb {
            private bool $raced = false;

            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function query( string $sql ): int|false {
                if (
                    ! $this->raced
                    && str_contains( $sql, 'UPDATE' )
                    && str_contains( $sql, 'cambios_ocupacion' )
                    && str_contains( $sql, 'fecha_hasta_id IS NULL' )
                ) {
                    $this->raced = true;

                    // The "concurrent request" that wins the race: closes
                    // every currently-vigent row of cambios_ocupacion,
                    // unconditionally, entirely outside of the CAS guard —
                    // exactly what a plain `UPDATE ... WHERE id = %d` (no
                    // `IS NULL` check) would have let a loser also do.
                    parent::query(
                        "UPDATE {$this->prefix}cambios_ocupacion "
                        . "SET fecha_hasta_id = 4, cerrada_por = 'reemplazada' "
                        . 'WHERE fecha_hasta_id IS NULL'
                    );
                }

                return parent::query( $sql );
            }
        };
    }

    public function test_succeed_ocupacion_fails_instead_of_creating_a_second_vigente_when_it_loses_the_close_race(): void {
        global $wpdb;

        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $racingWpdb = $this->wpdbThatRacesToCloseConcurrently( $wpdb );
        $racingRepo = new PlazaRepository( $racingWpdb, new InMemoryEventLog() );

        try {
            $racingRepo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException: the CAS UPDATE should have affected 0 rows after losing the race.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected — the CAS guard detected the lost race.
        }

        // The transaction must have rolled back, so the concurrent close is
        // undone too — the ONLY property that actually matters here is that
        // the chain never ends up with two vigent ocupaciones.
        $this->assertSame(
            1,
            $this->countVigentesFor( $plazaId ),
            'Losing the close race must never leave two vigent ocupaciones — it must roll back cleanly instead.'
        );
    }

    /**
     * findOcupacionVigente() must not paper over a corrupted state with
     * `LIMIT 1` — if two vigent rows ever exist for the same plaza (however
     * that happened), it must throw rather than pick one at random. Since
     * PlazaRepository's own API can never itself produce this state (that is
     * exactly what the previous test defends), the second vigent row here is
     * inserted directly via raw SQL to simulate corrupted data.
     */
    public function test_find_ocupacion_vigente_throws_when_more_than_one_row_is_vigent(): void {
        global $wpdb;

        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        // Insert a SECOND vigent ocupación for the same plaza directly —
        // corrupted data no code path reachable through the public API
        // could ever produce.
        $wpdb->insert(
            $wpdb->prefix . 'cambios_ocupacion',
            [
                'plaza_id'       => $plazaId,
                'player_id'      => 888,
                'es_genesis'     => 0,
                'fecha_desde_id' => 5,
                'fecha_hasta_id' => null,
                'cerrada_por'    => null,
                'created_at'     => '2026-04-01 10:00:00',
            ]
        );

        $this->assertSame( 2, $this->countVigentesFor( $plazaId ), 'Precondition: the corrupted state really has 2 vigent rows.' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} has 2 vigent ocupaciones" );

        $this->repo->findOcupacionVigente( $plazaId );
    }

    // -------------------------------------------------------------------------
    // succeedOcupacion — closes the previous link with the correct reason
    // -------------------------------------------------------------------------

    public function test_succeed_ocupacion_closes_the_previous_link_as_reemplazada(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $newId = $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $cadena = $this->repo->listOcupaciones( $plazaId );
        $this->assertCount( 2, $cadena );

        $titularLink = $cadena[0];
        $this->assertSame( 777, (int) $titularLink['player_id'] );
        $this->assertSame( 5, (int) $titularLink['fecha_hasta_id'] );
        $this->assertSame( 'reemplazada', (string) $titularLink['cerrada_por'] );

        $newLink = $cadena[1];
        $this->assertSame( $newId, (int) $newLink['id'] );
        $this->assertSame( 888, (int) $newLink['player_id'] );
        $this->assertSame( 0, (int) $newLink['es_genesis'] );
        $this->assertNull( $newLink['fecha_hasta_id'] );
    }

    public function test_succeed_ocupacion_closes_the_previous_link_as_trunca(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'suplente', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'trunca', '2026-04-01 10:00:00' );

        $cadena = $this->repo->listOcupaciones( $plazaId );
        $this->assertSame( 'trunca', (string) $cadena[0]['cerrada_por'] );
    }

    public function test_succeed_ocupacion_rejects_regreso_titular_as_a_reason(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->expectException( \InvalidArgumentException::class );

        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'regreso_titular', '2026-04-01 10:00:00' );
    }

    public function test_succeed_ocupacion_throws_when_the_plaza_has_no_vigent_ocupacion(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->closeEveryVigenteRawSql( $plazaId );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} has no vigent ocupación to succeed" );

        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
    }

    // -------------------------------------------------------------------------
    // closeOcupacionByRegresoTitular — opens a link of the TITULAR, never the
    // intermediate suplente
    // -------------------------------------------------------------------------

    public function test_close_by_regreso_titular_opens_a_link_of_the_titular_not_the_suplente(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 999, 9, 'reemplazada', '2026-05-01 10:00:00' );

        $newId = $this->repo->closeOcupacionByRegresoTitular( $plazaId, 13, '2026-06-01 10:00:00' );

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertSame( $newId, (int) $vigente['id'] );
        $this->assertSame( 777, (int) $vigente['player_id'], 'The titular, never the intermediate suplente (999), must return.' );

        $cadena = $this->repo->listOcupaciones( $plazaId );
        $closedSuplenteLink = $cadena[2];
        $this->assertSame( 999, (int) $closedSuplenteLink['player_id'] );
        $this->assertSame( 'regreso_titular', (string) $closedSuplenteLink['cerrada_por'] );
    }

    public function test_close_by_regreso_titular_is_idempotent_when_titular_already_vigent(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $vigenteBefore = $this->repo->findOcupacionVigente( $plazaId );

        $returnedId = $this->repo->closeOcupacionByRegresoTitular( $plazaId, 5, '2026-04-01 10:00:00' );

        $this->assertSame( (int) $vigenteBefore['id'], $returnedId );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ), 'No new link should be created for an already-vigent titular.' );
    }

    public function test_close_by_regreso_titular_throws_when_the_plaza_does_not_exist(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'plaza 999999 does not exist' );

        $this->repo->closeOcupacionByRegresoTitular( 999999, 5, '2026-04-01 10:00:00' );
    }

    public function test_close_by_regreso_titular_throws_when_the_plaza_has_no_vigent_ocupacion(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->closeEveryVigenteRawSql( $plazaId );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} has no vigent ocupación to close" );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 5, '2026-04-01 10:00:00' );
    }

    // -------------------------------------------------------------------------
    // Rollback when a write fails
    // -------------------------------------------------------------------------

    public function test_open_plaza_rolls_back_when_the_ocupacion_insert_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsInsertOnTable( $wpdb, 'cambios_ocupacion' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $plazaCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_plaza" );
        $this->assertSame( 0, $plazaCount, 'No plaza row must survive when the ocupación insert failed — the plaza insert must roll back too.' );

        $ocupacionCount = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_ocupacion" );
        $this->assertSame( 0, $ocupacionCount );
    }

    public function test_succeed_ocupacion_rolls_back_when_the_new_insert_fails(): void {
        global $wpdb;

        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $failingWpdb = $this->wpdbThatFailsOn( $wpdb, 'insert' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertNotNull( $vigente );
        $this->assertSame( 777, (int) $vigente['player_id'], 'The original occupant must remain vigent when the successor insert failed.' );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ), 'No new row must have been committed when the insert failed.' );
    }

    public function test_succeed_ocupacion_rolls_back_when_the_close_update_fails(): void {
        global $wpdb;

        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $failingWpdb = $this->wpdbThatFailsCloseOcupacion( $wpdb );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );
        $this->assertSame(
            1,
            $this->countOcupacionesFor( $plazaId ),
            'The new successor insert must not have been committed either, even though update() failed first.'
        );
    }

    // -------------------------------------------------------------------------
    // listOcupaciones — chronological order
    // -------------------------------------------------------------------------

    public function test_list_ocupaciones_orders_chronologically_by_fecha_desde_id(): void {
        $plazaId = $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 999, 9, 'reemplazada', '2026-05-01 10:00:00' );

        $cadena = $this->repo->listOcupaciones( $plazaId );

        $this->assertSame( [ 777, 888, 999 ], array_map( 'intval', array_column( $cadena, 'player_id' ) ) );
        $this->assertSame( [ 1, 5, 9 ], array_map( 'intval', array_column( $cadena, 'fecha_desde_id' ) ) );
    }

    // -------------------------------------------------------------------------
    // The interval is half-open: [fecha_desde_id, fecha_hasta_id)
    // -------------------------------------------------------------------------

    /**
     * Every link's end IS its successor's start — the chain has no gap and no
     * overlap between consecutive ocupaciones.
     *
     * Asserted as a relation over the whole chain rather than as literal ids,
     * because the literal alone does not say WHY the value is what it is. The
     * closed reading ("the last fecha actually occupied") would make each end
     * the fecha BEFORE its successor's start, and the calendar has gaps —
     * weekends with no fecha — so that value is a lookup, not a subtraction.
     *
     * This is a counting feature: three resolved fechas before a titular may
     * return. An off-by-one in how an interval is read is an off-by-one in the
     * answer the committee acts on.
     */
    public function test_each_ocupacion_ends_exactly_where_its_successor_begins(): void {
        $plazaId = $this->repo->openPlaza(
            359,
            100,
            777,
            Puntaje::fromDecimal( 3.0 ),
            'campo',
            1,
            '2026-03-01 10:00:00'
        );

        $this->repo->succeedOcupacion( $plazaId, 2001, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 2002, 7, 'trunca', '2026-05-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 2003, 11, 'reemplazada', '2026-06-01 10:00:00' );

        $chain = $this->repo->listOcupaciones( $plazaId );
        $this->assertCount( 4, $chain );

        for ( $i = 0; $i < count( $chain ) - 1; $i++ ) {
            $this->assertSame(
                (int) $chain[ $i + 1 ]['fecha_desde_id'],
                (int) $chain[ $i ]['fecha_hasta_id'],
                "link {$i} must end exactly where link " . ( $i + 1 ) . ' begins'
            );
        }

        $this->assertNull(
            $chain[ count( $chain ) - 1 ]['fecha_hasta_id'],
            'only the vigent link is open-ended'
        );
    }

    // -------------------------------------------------------------------------
    // Fecha id validation — openPlaza / succeedOcupacion / closeOcupacionByRegresoTitular
    // -------------------------------------------------------------------------

    public function test_open_plaza_rejects_a_nonexistent_fecha_desde_id(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_desde_id 99999 does not exist in cambios_fecha' );

        $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 99999, '2026-03-01 10:00:00' );
    }

    public function test_open_plaza_rejects_a_fecha_desde_id_from_another_season(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_desde_id 900 belongs to season ' . self::OTHER_SEASON_ID );

        $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 900, '2026-03-01 10:00:00' );
    }

    public function test_succeed_ocupacion_rejects_a_nonexistent_fecha_id(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_id 99999 does not exist in cambios_fecha' );

        $this->repo->succeedOcupacion( $plazaId, 888, 99999, 'reemplazada', '2026-04-01 10:00:00' );
    }

    public function test_succeed_ocupacion_rejects_a_fecha_id_from_another_season(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_id 900 belongs to season ' . self::OTHER_SEASON_ID );

        $this->repo->succeedOcupacion( $plazaId, 888, 900, 'reemplazada', '2026-04-01 10:00:00' );
    }

    public function test_close_by_regreso_titular_rejects_a_nonexistent_fecha_id(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_id 99999 does not exist in cambios_fecha' );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 99999, '2026-05-01 10:00:00' );
    }

    public function test_close_by_regreso_titular_rejects_a_fecha_id_from_another_season(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'fecha_id 900 belongs to season ' . self::OTHER_SEASON_ID );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 900, '2026-05-01 10:00:00' );
    }

    // -------------------------------------------------------------------------
    // EventLog — audit events on every successful write
    // -------------------------------------------------------------------------

    public function test_open_plaza_records_an_audit_event(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->assertTrue( $this->eventLog->has( 'plaza.abierta' ) );
        $event = $this->eventLog->last();
        $this->assertSame( 'plaza.abierta', $event['evento'] );
        $this->assertSame( $plazaId, $event['contexto']['plaza_id'] );
        $this->assertSame( self::SEASON_ID, $event['contexto']['season_id'] );
        $this->assertSame( 777, $event['contexto']['titular_player_id'] );
    }

    public function test_succeed_ocupacion_records_an_audit_event(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $newId   = $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $this->assertTrue( $this->eventLog->has( 'ocupacion.sucedida' ) );
        $event = $this->eventLog->last();
        $this->assertSame( 'ocupacion.sucedida', $event['evento'] );
        $this->assertSame( $plazaId, $event['contexto']['plaza_id'] );
        $this->assertSame( 777, $event['contexto']['saliente_player_id'] );
        $this->assertSame( 888, $event['contexto']['entrante_player_id'] );
        $this->assertSame( $newId, $event['contexto']['ocupacion_nueva_id'] );
    }

    public function test_close_by_regreso_titular_records_an_audit_event_on_a_real_change(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 9, '2026-05-01 10:00:00' );

        $this->assertTrue( $this->eventLog->has( 'ocupacion.regreso_titular' ) );
        $event = $this->eventLog->last();
        $this->assertSame( 777, $event['contexto']['titular_player_id'] );
        $this->assertSame( 888, $event['contexto']['suplente_player_id'] );
    }

    public function test_close_by_regreso_titular_records_no_event_on_the_idempotent_no_op(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 5, '2026-04-01 10:00:00' );

        $this->assertFalse(
            $this->eventLog->has( 'ocupacion.regreso_titular' ),
            'No write happened on the idempotent no-op branch, so nothing should be logged as one.'
        );
    }

    // -------------------------------------------------------------------------
    // EventLog — failure events recorded BEFORE the exception propagates
    // -------------------------------------------------------------------------

    public function test_open_plaza_records_the_failure_event_before_throwing_on_an_invalid_tipo(): void {
        try {
            $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'banco', 1, '2026-03-01 10:00:00' );
            $this->fail( 'Expected InvalidArgumentException.' );
        } catch ( \InvalidArgumentException $e ) {
            // expected
        }

        $this->assertTrue( $this->eventLog->has( 'escritura.fallida' ) );
        $this->assertSame( 'openPlaza', $this->eventLog->last()['contexto']['operacion'] );
    }

    public function test_succeed_ocupacion_records_the_failure_event_before_throwing_when_no_vigente(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->closeEveryVigenteRawSql( $plazaId );

        try {
            $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            // expected — the event must already be there.
        }

        $this->assertTrue( $this->eventLog->has( 'escritura.fallida' ) );
        $last = $this->eventLog->last();
        $this->assertSame( 'succeedOcupacion', $last['contexto']['operacion'] );
        $this->assertSame( $plazaId, $last['contexto']['plaza_id'] );
    }

    public function test_open_plaza_records_the_failure_event_before_throwing_when_the_insert_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsInsertOnTable( $wpdb, 'cambios_plaza' );
        $failingEventLog = new InMemoryEventLog();
        $failingRepo = new PlazaRepository( $failingWpdb, $failingEventLog );

        try {
            $failingRepo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $this->assertTrue(
            $failingEventLog->has( 'escritura.fallida' ),
            'The failure must be recorded even though the wpdb-level exception propagates.'
        );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }

    // -------------------------------------------------------------------------
    // undoLastOcupacion — correction primitive
    // -------------------------------------------------------------------------

    public function test_undo_last_ocupacion_deletes_the_last_link_and_reopens_the_previous_one(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $newId   = $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $this->repo->undoLastOcupacion( $plazaId, '2026-04-02 10:00:00' );

        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ), 'The undone link must be gone entirely, not just closed.' );

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertSame( 777, (int) $vigente['player_id'], 'The previous occupant must be vigent again.' );
        $this->assertNull( $vigente['fecha_hasta_id'] );
        $this->assertNull( $vigente['cerrada_por'] );

        $this->assertTrue( $this->eventLog->has( 'ocupacion.deshecha' ) );
        $event = $this->eventLog->last();
        $this->assertSame( $newId, $event['contexto']['ocupacion_deshecha_id'] );
        $this->assertSame( (int) $vigente['id'], $event['contexto']['ocupacion_reabierta_id'] );
    }

    public function test_undo_last_ocupacion_refuses_a_chain_of_a_single_genesis_link(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'has only its genesis ocupación' );

        $this->repo->undoLastOcupacion( $plazaId, '2026-04-01 10:00:00' );
    }

    public function test_undo_last_ocupacion_rolls_back_when_the_reopen_write_fails(): void {
        global $wpdb;

        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $failingWpdb = new class( $this->pdoOf( $wpdb ), $wpdb->prefix ) extends \wpdb {
            public function __construct( \PDO $pdo, string $prefix ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix = $prefix;
            }

            public function update( string $table, array $data, array $where ): int|false {
                $this->last_error = 'simulated reopen failure for test';
                return false;
            }
        };
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        try {
            $failingRepo->undoLastOcupacion( $plazaId, '2026-04-02 10:00:00' );
            $this->fail( 'Expected PlazaPersistenceException.' );
        } catch ( PlazaPersistenceException $e ) {
            // expected
        }

        $this->assertSame(
            2,
            $this->countOcupacionesFor( $plazaId ),
            'The delete of the last link must have rolled back too — both links must still exist.'
        );
        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertSame( 888, (int) $vigente['player_id'], 'The successor must remain vigent when the undo failed.' );
    }

    private function pdoOf( \wpdb $wpdb ): \PDO {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        return $ref->getValue( $wpdb );
    }

    // -------------------------------------------------------------------------
    // closePlaza — correction primitive
    // -------------------------------------------------------------------------

    public function test_close_plaza_sets_closed_at(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $this->repo->closePlaza( $plazaId, '2026-04-01 10:00:00' );

        $plaza = $this->repo->findPlaza( $plazaId );
        $this->assertSame( '2026-04-01 10:00:00', (string) $plaza['closed_at'] );

        $this->assertTrue( $this->eventLog->has( 'plaza.cerrada' ) );
        $this->assertSame( $plazaId, $this->eventLog->last()['contexto']['plaza_id'] );
    }

    public function test_close_plaza_throws_when_the_plaza_does_not_exist(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'plaza 999999 does not exist' );

        $this->repo->closePlaza( 999999, '2026-04-01 10:00:00' );
    }

    // -------------------------------------------------------------------------
    // Slice 4b — dictamen context queries: fail loud, never silently empty
    // -------------------------------------------------------------------------

    /**
     * A \wpdb subclass whose get_results() sets $wpdb->last_error and
     * returns [] (its declared return type is non-nullable `array`, so it
     * cannot itself return null — see PlazaRepository's class docblock,
     * "READ FAILURES...", for why checking last_error is what makes this
     * simulable at all) whenever the SQL contains $mustContain. Every other
     * get_results() call — including the internal ones this same test still
     * relies on for setup — passes through to the real, working
     * implementation.
     */
    private function wpdbThatFailsGetResults( \wpdb $real, string $mustContain ): \wpdb {
        $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
        $pdo = $ref->getValue( $real );

        return new class( $pdo, $real->prefix, $mustContain ) extends \wpdb {
            private string $mustContain;

            public function __construct( \PDO $pdo, string $prefix, string $mustContain ) {
                $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                $ref->setValue( $this, $pdo );
                $this->prefix      = $prefix;
                $this->mustContain = $mustContain;
            }

            public function get_results( string $sql, string $output = OBJECT ): array {
                if ( str_contains( $sql, $this->mustContain ) ) {
                    $this->last_error = 'simulated get_results failure for test';
                    return [];
                }

                return parent::get_results( $sql, $output );
            }
        };
    }

    // --- listOcupacionesVigentesDeJugador -------------------------------------

    public function test_list_ocupaciones_vigentes_de_jugador_returns_vigent_rows_across_plazas(): void {
        // 777 is only ever succeeded IN to plazaB here — it must not also
        // be plazaA's titular, or it would (correctly) show up as vigent
        // there too, defeating the "exactly one" assertion below.
        $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $plazaB = $this->repo->openPlaza( self::SEASON_ID, 100, 888, Puntaje::fromDecimal( 3.0 ), 'suplente', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaB, 777, 5, 'reemplazada', '2026-04-01 10:00:00' );

        $vigentes = $this->repo->listOcupacionesVigentesDeJugador( self::SEASON_ID, 777 );

        $this->assertCount( 1, $vigentes );
        $this->assertSame( $plazaB, (int) $vigentes[0]['plaza_id'] );
        $this->assertSame( 777, (int) $vigentes[0]['player_id'] );
    }

    public function test_list_ocupaciones_vigentes_de_jugador_excludes_the_given_plaza(): void {
        $plazaA = $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $vigentes = $this->repo->listOcupacionesVigentesDeJugador( self::SEASON_ID, 777, $plazaA );

        $this->assertSame( [], $vigentes );
    }

    public function test_list_ocupaciones_vigentes_de_jugador_returns_an_empty_array_when_genuinely_none(): void {
        $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $vigentes = $this->repo->listOcupacionesVigentesDeJugador( self::SEASON_ID, 999999 );

        $this->assertSame( [], $vigentes );
    }

    public function test_list_ocupaciones_vigentes_de_jugador_throws_when_the_query_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, 'cambios_ocupacion' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->listOcupacionesVigentesDeJugador( self::SEASON_ID, 777 );
    }

    public function test_list_ocupaciones_vigentes_de_jugador_records_a_lectura_fallida_event_before_throwing(): void {
        global $wpdb;

        $failingWpdb     = $this->wpdbThatFailsGetResults( $wpdb, 'cambios_ocupacion' );
        $failingEventLog = new InMemoryEventLog();
        $failingRepo     = new PlazaRepository( $failingWpdb, $failingEventLog );

        try {
            $failingRepo->listOcupacionesVigentesDeJugador( self::SEASON_ID, 777 );
            $this->fail( 'Expected RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $failingEventLog->has( 'lectura.fallida' ) );
        $this->assertSame( 'listOcupacionesVigentesDeJugador', $failingEventLog->last()['contexto']['operacion'] );
        $this->assertNotNull( $failingEventLog->last()['contexto']['last_error'] ?? null );
    }

    // --- listPlazasConCierreTruncadoDeJugador ---------------------------------

    public function test_list_plazas_con_cierre_truncado_de_jugador_returns_one_full_chain_per_plaza(): void {
        // succeedOcupacion()'s cerrada_por describes how the OUTGOING
        // (previously vigent) link ended — so to give player 888 a trunca
        // closure, 888 must first BE the vigent occupant succeeded away,
        // not the incoming player of the succession that produces it.
        $plazaA = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaA, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaA, 555, 7, 'trunca', '2026-05-01 10:00:00' );

        $plazaB = $this->repo->openPlaza( self::SEASON_ID, 100, 222, Puntaje::fromDecimal( 3.0 ), 'suplente', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaB, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaB, 333, 7, 'reemplazada', '2026-05-01 10:00:00' );

        $chains = $this->repo->listPlazasConCierreTruncadoDeJugador( self::SEASON_ID, 888 );

        $this->assertCount( 1, $chains, 'Only plazaA has a trunca closure for player 888 — plazaB closed it reemplazada.' );
        $this->assertCount( 3, $chains[0], 'The FULL chain must come back, not just the trunca link.' );
        $this->assertSame( $plazaA, (int) $chains[0][0]['plaza_id'] );
        $this->assertSame( 888, (int) $chains[0][1]['player_id'] );
        $this->assertSame( 'trunca', (string) $chains[0][1]['cerrada_por'] );
    }

    public function test_list_plazas_con_cierre_truncado_de_jugador_returns_an_empty_array_when_genuinely_none(): void {
        $this->repo->openPlaza( self::SEASON_ID, 100, 777, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $chains = $this->repo->listPlazasConCierreTruncadoDeJugador( self::SEASON_ID, 888 );

        $this->assertSame( [], $chains );
    }

    public function test_list_plazas_con_cierre_truncado_de_jugador_throws_when_the_plaza_id_lookup_fails(): void {
        global $wpdb;

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, "cerrada_por = 'trunca'" );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->listPlazasConCierreTruncadoDeJugador( self::SEASON_ID, 888 );
    }

    public function test_list_plazas_con_cierre_truncado_de_jugador_throws_when_a_chain_fetch_fails(): void {
        global $wpdb;

        $plazaA = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaA, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaA, 555, 7, 'trunca', '2026-05-01 10:00:00' );

        $failingWpdb = $this->wpdbThatFailsGetResults( $wpdb, 'ORDER BY fecha_desde_id ASC' );
        $failingRepo = new PlazaRepository( $failingWpdb, new InMemoryEventLog() );

        $this->expectException( \RuntimeException::class );

        $failingRepo->listPlazasConCierreTruncadoDeJugador( self::SEASON_ID, 888 );
    }

    // -------------------------------------------------------------------------
    // "WithinTransaction" variants (slice 4c) — see PlazaRepository's class
    // docblock, ""WithinTransaction" VARIANTS (slice 4c)", for why these exist.
    // -------------------------------------------------------------------------

    public function test_succeed_ocupacion_within_transaction_applies_the_write_but_logs_nothing(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $newId = $this->repo->succeedOcupacionWithinTransaction( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertSame( $newId, (int) $vigente['id'] );
        $this->assertSame( 888, (int) $vigente['player_id'] );
        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );

        $this->assertFalse( $this->eventLog->has( 'ocupacion.sucedida' ) );
    }

    public function test_close_ocupacion_by_regreso_titular_within_transaction_applies_the_write_but_logs_nothing(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );

        $newId = $this->repo->closeOcupacionByRegresoTitularWithinTransaction( $plazaId, 5, '2026-05-01 10:00:00' );

        $vigente = $this->repo->findOcupacionVigente( $plazaId );
        $this->assertSame( $newId, (int) $vigente['id'] );
        $this->assertSame( 111, (int) $vigente['player_id'] );

        $this->assertFalse( $this->eventLog->has( 'ocupacion.regreso_titular' ) );
    }

    public function test_close_ocupacion_by_regreso_titular_within_transaction_is_idempotent_and_logs_nothing(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        $vigenteAntes = $this->repo->findOcupacionVigente( $plazaId );
        $newId        = $this->repo->closeOcupacionByRegresoTitularWithinTransaction( $plazaId, 4, '2026-04-01 10:00:00' );

        $this->assertSame( (int) $vigenteAntes['id'], $newId );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ) );
        $this->assertFalse( $this->eventLog->has( 'ocupacion.regreso_titular' ) );
    }

    /**
     * The whole reason these variants exist: two of them, wrapped in ONE
     * ambient transaction the CALLER controls (exactly how
     * Solicitudes\SolicitudRepository::publicarLote() uses them), roll back
     * TOGETHER when the caller's transaction is rolled back — not just the
     * one whose own write happened to fail.
     */
    public function test_within_transaction_variants_share_an_ambient_transaction_and_roll_back_together(): void {
        $plazaA = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $plazaB = $this->repo->openPlaza( self::SEASON_ID, 101, 222, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        $this->repo->succeedOcupacionWithinTransaction( $plazaA, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->succeedOcupacionWithinTransaction( $plazaB, 999, 4, 'reemplazada', '2026-04-01 10:00:00' );

        $wpdb->query( 'ROLLBACK' );

        $vigenteA = $this->repo->findOcupacionVigente( $plazaA );
        $vigenteB = $this->repo->findOcupacionVigente( $plazaB );

        $this->assertSame( 111, (int) $vigenteA['player_id'] );
        $this->assertSame( 222, (int) $vigenteB['player_id'] );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaA ) );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaB ) );
    }

    // -------------------------------------------------------------------------
    // Defense in depth — a closed plaza refuses every write, on all 4
    // entry points (see assertPlazaNotClosed()'s and Dictamen\Reglas\
    // PlazaNoCerrada's docblocks for why the READ-side rule alone is not
    // enough).
    // -------------------------------------------------------------------------

    public function test_succeed_ocupacion_refuses_to_write_over_a_closed_plaza(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->closePlaza( $plazaId, '2026-03-15 10:00:00' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} is closed" );

        $this->repo->succeedOcupacion( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
    }

    public function test_succeed_ocupacion_within_transaction_refuses_to_write_over_a_closed_plaza(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->closePlaza( $plazaId, '2026-03-15 10:00:00' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} is closed" );

        $this->repo->succeedOcupacionWithinTransaction( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
    }

    public function test_close_ocupacion_by_regreso_titular_refuses_to_write_over_a_closed_plaza(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->closePlaza( $plazaId, '2026-04-15 10:00:00' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} is closed" );

        $this->repo->closeOcupacionByRegresoTitular( $plazaId, 5, '2026-05-01 10:00:00' );
    }

    public function test_close_ocupacion_by_regreso_titular_within_transaction_refuses_to_write_over_a_closed_plaza(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->succeedOcupacion( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
        $this->repo->closePlaza( $plazaId, '2026-04-15 10:00:00' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( "plaza {$plazaId} is closed" );

        $this->repo->closeOcupacionByRegresoTitularWithinTransaction( $plazaId, 5, '2026-05-01 10:00:00' );
    }

    public function test_succeed_ocupacion_on_a_closed_plaza_records_the_failure_event(): void {
        $plazaId = $this->repo->openPlaza( self::SEASON_ID, 100, 111, Puntaje::fromDecimal( 3.0 ), 'campo', 1, '2026-03-01 10:00:00' );
        $this->repo->closePlaza( $plazaId, '2026-03-15 10:00:00' );

        try {
            $this->repo->succeedOcupacion( $plazaId, 888, 4, 'reemplazada', '2026-04-01 10:00:00' );
            $this->fail( 'Expected a RuntimeException.' );
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $this->eventLog->has( 'escritura.fallida' ) );
        $this->assertSame( $plazaId, $this->eventLog->last()['contexto']['plaza_id'] );
    }

}
