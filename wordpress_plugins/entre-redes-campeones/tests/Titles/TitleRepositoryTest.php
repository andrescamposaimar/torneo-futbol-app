<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Titles;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Support\FailingInsertWpdb;
use EntreRedes\Campeones\Tests\Support\FailingUpdateWpdb;
use EntreRedes\Campeones\Titles\TitleRepository;
use EntreRedes\Campeones\Titles\WriteFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for TitleRepository against the in-memory SQLite shim.
 *
 * NOTE — SQLite shim gap: the dbDelta shim drops UNIQUE KEY lines from the
 * DDL translation (tests/wp-shim.php, _campeones_mysql_to_sqlite()), so the
 * uq_anio_zona_posicion UNIQUE index is NOT enforced by the test DB.
 * createOrConflict() uses SELECT-then-INSERT as the authoritative dedup
 * mechanism (REC-7, REC-9) — these tests verify that in application code,
 * never by relying on a DB constraint violation.
 *
 * setUp/tearDown pattern mirrors entre-redes-prode's PredictionRepositoryTest:
 * the shared in-memory SQLite DB has no per-test rollback, so rows are
 * deleted in setUp and tearDown.
 */
class TitleRepositoryTest extends TestCase {

    private TitleRepository $repository;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->repository = new TitleRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    public function test_insert_and_read(): void {
        $created = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $this->assertNotNull( $created );
        $this->assertSame( 2016, $created->anio );
        $this->assertSame( 'A', $created->zona );
        $this->assertSame( 'campeon', $created->posicion );
        $this->assertSame( 'CHELSEA', $created->equipoNombre );

        $found = $this->repository->findByKey( 2016, 'A', 'campeon' );
        $this->assertNotNull( $found );
        $this->assertSame( $created->id, $found->id );
        $this->assertSame( 'CHELSEA', $found->equipoNombre );
    }

    public function test_duplicate_anio_zona_posicion_is_rejected_in_application_code(): void {
        // REC-7: the UNIQUE KEY in the schema is a backstop only — the
        // SQLite shim drops every UNIQUE KEY / KEY line from CREATE TABLE
        // (design §2), so this must be provably enforced without relying on
        // that constraint.
        $first = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->assertNotNull( $first );

        $second = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'LIVERPOOL' );
        $this->assertNull( $second, 'A second title for the same (anio, zona, posicion) must be rejected.' );
    }

    public function test_first_record_is_unchanged_after_a_rejected_duplicate(): void {
        $first = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->assertNotNull( $first );

        $this->repository->createOrConflict( 2016, 'A', 'campeon', 'LIVERPOOL' );

        $reread = $this->repository->findByKey( 2016, 'A', 'campeon' );
        $this->assertNotNull( $reread );
        $this->assertSame( 'CHELSEA', $reread->equipoNombre, 'The existing record must be unchanged by a rejected duplicate.' );
        $this->assertSame( $first->id, $reread->id );

        // findByKey() alone (LIMIT 1, no ORDER BY) would pass even if the
        // rejected duplicate had ALSO been phantom-inserted — it would just
        // never be the row that LIMIT 1 happens to return. COUNT(*) is the
        // only assertion that actually proves no phantom row exists.
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}campeones_titulo
                  WHERE anio = %d AND zona = %s AND posicion = %s",
                2016,
                'A',
                'campeon'
            )
        );
        $this->assertSame( '1', (string) $count, 'A rejected duplicate must never result in more than one row for the key.' );
    }

    public function test_different_zone_or_position_is_not_a_conflict(): void {
        $a = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $b = $this->repository->createOrConflict( 2016, 'B', 'campeon', 'RIVER' );
        $c = $this->repository->createOrConflict( 2016, 'A', 'subcampeon', 'BOCA' );

        $this->assertNotNull( $a );
        $this->assertNotNull( $b );
        $this->assertNotNull( $c );
    }

    public function test_find_by_id(): void {
        $created = $this->repository->createOrConflict( 2011, 'A', 'campeon', 'INDEPENDIENTE' );
        $this->assertNotNull( $created );

        $found = $this->repository->find( $created->id );
        $this->assertNotNull( $found );
        $this->assertSame( 'INDEPENDIENTE', $found->equipoNombre );

        $this->assertNull( $this->repository->find( 999999 ) );
    }

    public function test_a_failed_insert_throws_instead_of_being_confused_with_a_conflict(): void {
        // createOrConflict() already uses `null` to mean "duplicate key
        // found" (REC-7). A write failure must be a DIFFERENT signal, or a
        // caller can never distinguish lost data from a legitimate conflict.
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingInsertWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $repository = new TitleRepository( $failing );

            $this->expectException( WriteFailedException::class );
            $repository->createOrConflict( 2099, 'A', 'campeon', 'BOCA' );
        } finally {
            $wpdb = $original;
        }
    }

    // -------------------------------------------------------------------------
    // findAll() / update() / delete() — added slice 3 for the Titles admin
    // list page (ADMIN-7: create/edit/delete individual title records).
    // -------------------------------------------------------------------------

    public function test_find_all_orders_by_anio_descending(): void {
        $this->repository->createOrConflict( 2011, 'A', 'campeon', 'INDEPENDIENTE' );
        $this->repository->createOrConflict( 2019, 'A', 'campeon', 'RIVER' );
        $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $all = $this->repository->findAll();

        $this->assertCount( 3, $all );
        $this->assertSame( [ 2019, 2016, 2011 ], array_map( static fn ( $t ) => $t->anio, $all ) );
    }

    public function test_find_all_is_empty_before_any_title_exists(): void {
        $this->assertSame( [], $this->repository->findAll() );
    }

    public function test_update_changes_the_team_name(): void {
        $created = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $this->assertTrue( $this->repository->update( $created->id, [ 'equipo_nombre' => 'BOCA' ] ) );

        $reread = $this->repository->find( $created->id );
        $this->assertSame( 'BOCA', $reread->equipoNombre );
        // Identity fields (anio/zona/posicion) are untouched by a header edit.
        $this->assertSame( 2016, $reread->anio );
    }

    public function test_update_reports_failure_when_the_write_fails(): void {
        // Item 9: no test previously forced $wpdb->update() to return false
        // for campeones_titulo — update() silently trusted it always
        // succeeded.
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingUpdateWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $repository = new TitleRepository( $failing );
            $created    = $repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

            $this->assertFalse( $repository->update( $created->id, [ 'equipo_nombre' => 'BOCA' ] ) );

            $reread = $repository->find( $created->id );
            $this->assertSame( 'CHELSEA', $reread->equipoNombre, 'A failed update must leave the existing row unchanged.' );
        } finally {
            $wpdb = $original;
        }
    }

    public function test_delete_removes_the_title_row(): void {
        $created = $this->repository->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $this->assertTrue( $this->repository->delete( $created->id ) );
        $this->assertNull( $this->repository->find( $created->id ) );
    }

    public function test_a_failed_insert_leaves_no_row_behind(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingInsertWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $repository = new TitleRepository( $failing );

            try {
                $repository->createOrConflict( 2099, 'A', 'campeon', 'BOCA' );
                $this->fail( 'Expected WriteFailedException was not thrown.' );
            } catch ( WriteFailedException $e ) {
                // Expected.
            }

            $count = $failing->get_var(
                "SELECT COUNT(*) FROM {$failing->prefix}campeones_titulo WHERE anio = 2099"
            );
            $this->assertSame( '0', (string) $count, 'A failed insert must not leave a partially-committed row.' );
        } finally {
            $wpdb = $original;
        }
    }
}
