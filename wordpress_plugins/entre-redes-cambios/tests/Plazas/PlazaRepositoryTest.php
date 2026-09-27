<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Migrations\InitialSchema;
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

    private PlazaRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );

        $this->repo = new PlazaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_ocupacion" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}cambios_plaza" );
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
        $failingRepo = new PlazaRepository( $failingWpdb );

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
        $failingRepo = new PlazaRepository( $failingWpdb );

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
        $racingRepo = new PlazaRepository( $racingWpdb );

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
        $failingRepo = new PlazaRepository( $failingWpdb );

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
        $failingRepo = new PlazaRepository( $failingWpdb );

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
        $failingRepo = new PlazaRepository( $failingWpdb );

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

}
