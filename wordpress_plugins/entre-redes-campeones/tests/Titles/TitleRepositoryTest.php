<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Titles;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Titles\TitleRepository;
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
}
