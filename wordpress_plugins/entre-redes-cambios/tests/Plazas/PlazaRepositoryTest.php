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
     * the real (shim) $wpdb, whose insert() or update() unconditionally
     * returns false — mirrors CapitanRepositoryTest::wpdbThatFailsOn()
     * exactly, for the same reason (simulating a real wpdb write failure,
     * which never throws).
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
            'update' => new class( $pdo, $real->prefix ) extends \wpdb {
                public function __construct( \PDO $pdo, string $prefix ) {
                    $ref = new \ReflectionProperty( \wpdb::class, 'pdo' );
                    $ref->setValue( $this, $pdo );
                    $this->prefix = $prefix;
                }

                public function update( string $table, array $data, array $where ): int|false {
                    $this->last_error = 'simulated update failure for test';
                    return false;
                }
            },
            default => throw new \InvalidArgumentException( "Unsupported method: {$method}" ),
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
        $this->assertSame( 1, (int) $vigente['es_titular'] );
        $this->assertSame( 1, (int) $vigente['fecha_desde_id'] );
        $this->assertNull( $vigente['fecha_hasta_id'] );

        $this->assertSame( 1, $this->countVigentesFor( $plazaId ) );
        $this->assertSame( 1, $this->countOcupacionesFor( $plazaId ) );
    }

    public function test_open_plaza_rejects_an_invalid_tipo(): void {
        $this->expectException( \InvalidArgumentException::class );

        $this->repo->openPlaza( 359, 100, 777, Puntaje::fromDecimal( 3.0 ), 'banco', 1, '2026-03-01 10:00:00' );
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
        $this->assertSame( 0, (int) $newLink['es_titular'] );
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

        $failingWpdb = $this->wpdbThatFailsOn( $wpdb, 'update' );
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
