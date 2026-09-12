<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Titles;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Support\FailingInsertWpdb;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use EntreRedes\Campeones\Titles\WriteFailedException;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for SquadRepository against the in-memory SQLite shim.
 *
 * CRUD only in this slice — findByStates()/countByStates() (the aggregate
 * review queue query) land in slice 4 once the review queue exists to
 * consume them.
 */
class SquadRepositoryTest extends TestCase {

    private SquadRepository $repository;
    private TitleRepository $titles;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->repository = new SquadRepository( $wpdb );
        $this->titles      = new TitleRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    private function makeTitle( int $anio = 2016 ): int {
        $title = $this->titles->createOrConflict( $anio, 'A', 'campeon', 'CHELSEA' );
        $this->assertNotNull( $title );
        return $title->id;
    }

    public function test_squad_reads_back_ordered_by_orden(): void {
        $tituloId = $this->makeTitle();

        // Insert out of order to prove the read, not the insertion order,
        // is what orders the result.
        $this->repository->insert( new SquadEntry( $tituloId, 2, 'MAZZARA, M.' ) );
        $this->repository->insert( new SquadEntry( $tituloId, 0, 'BASSO, A.' ) );
        $this->repository->insert( new SquadEntry( $tituloId, 1, 'CALELLO, G.' ) );

        $rows = $this->repository->findByTitle( $tituloId );

        $this->assertCount( 3, $rows );
        $this->assertSame( 'BASSO, A.', $rows[0]->jugadorNombre );
        $this->assertSame( 'CALELLO, G.', $rows[1]->jugadorNombre );
        $this->assertSame( 'MAZZARA, M.', $rows[2]->jugadorNombre );
    }

    public function test_find_resolvable_by_title_excludes_manual_rows_at_the_query_level(): void {
        $tituloId = $this->makeTitle();

        $this->repository->insert( new SquadEntry( $tituloId, 0, 'BASSO, A.', false, 'auto' ) );
        $manualId = $this->repository->insert( new SquadEntry( $tituloId, 1, 'CALELLO, G.', false, 'manual' ) );
        $this->repository->insert( new SquadEntry( $tituloId, 2, 'MAZZARA, M.', false, 'sin_candidato' ) );

        $resolvable = $this->repository->findResolvableByTitle( $tituloId );

        $this->assertCount( 2, $resolvable, 'The manual row must not appear among resolvable rows.' );

        $ids = array_map( static fn( SquadEntry $entry ): int => $entry->id, $resolvable );
        $this->assertNotContains(
            $manualId,
            $ids,
            'LINK-10: a manual row must be excluded by the query itself, not filtered afterward.'
        );
    }

    public function test_unlinked_squad_entries_read_back_complete(): void {
        // REC-6: a squad entry with no jugador_id is a valid, complete
        // entry — not an error and not "incomplete".
        $tituloId = $this->makeTitle();

        $this->repository->insert( new SquadEntry( $tituloId, 0, 'BASSO, A.' ) );
        $this->repository->insert( new SquadEntry( $tituloId, 1, 'CALELLO, G.' ) );

        $rows = $this->repository->findByTitle( $tituloId );

        $this->assertCount( 2, $rows );
        foreach ( $rows as $row ) {
            $this->assertNull( $row->jugadorId, 'A squad entry may be fully unlinked and still be complete.' );
            $this->assertSame( 'sin_candidato', $row->estadoVinculo );
        }
    }

    public function test_zero_link_title_reads_back_as_a_clean_empty_array(): void {
        // REC-6: a title with ZERO squad entries at all is a valid, complete,
        // displayable record — findByTitle() must return a clean [], never
        // null or an error, for a title nobody has linked squad rows to yet.
        $tituloId = $this->makeTitle();

        $rows = $this->repository->findByTitle( $tituloId );

        $this->assertSame( [], $rows );
    }

    public function test_rows_sharing_the_same_orden_are_ordered_deterministically_by_id(): void {
        // ORDER BY orden ASC alone leaves ties (rows sharing the same
        // orden) in implementation-defined order. `, id ASC` pins a
        // deterministic tiebreaker so the result is reproducible regardless
        // of storage engine or query plan.
        $tituloId = $this->makeTitle();

        $firstId  = $this->repository->insert( new SquadEntry( $tituloId, 0, 'BASSO, A.' ) );
        $secondId = $this->repository->insert( new SquadEntry( $tituloId, 0, 'CALELLO, G.' ) );
        $thirdId  = $this->repository->insert( new SquadEntry( $tituloId, 0, 'MAZZARA, M.' ) );

        $rows = $this->repository->findByTitle( $tituloId );

        $this->assertCount( 3, $rows );
        $this->assertSame( [ $firstId, $secondId, $thirdId ], array_map( static fn( SquadEntry $r ): int => $r->id, $rows ) );

        $resolvable = $this->repository->findResolvableByTitle( $tituloId );
        $this->assertSame( [ $firstId, $secondId, $thirdId ], array_map( static fn( SquadEntry $r ): int => $r->id, $resolvable ) );
    }

    public function test_update_and_delete(): void {
        $tituloId = $this->makeTitle();
        $id       = $this->repository->insert( new SquadEntry( $tituloId, 0, 'BASSO, A.' ) );

        $this->assertTrue(
            $this->repository->update( $id, [ 'estado_vinculo' => 'manual', 'jugador_id' => 42 ] )
        );

        $row = $this->repository->find( $id );
        $this->assertSame( 'manual', $row->estadoVinculo );
        $this->assertSame( 42, $row->jugadorId );

        $this->assertTrue( $this->repository->delete( $id ) );
        $this->assertNull( $this->repository->find( $id ) );
    }

    public function test_find_by_title_only_returns_rows_for_that_title(): void {
        $tituloA = $this->makeTitle( 2016 );
        $tituloB = $this->makeTitle( 2017 );

        $this->repository->insert( new SquadEntry( $tituloA, 0, 'BASSO, A.' ) );
        $this->repository->insert( new SquadEntry( $tituloB, 0, 'CALELLO, G.' ) );

        $rowsA = $this->repository->findByTitle( $tituloA );
        $this->assertCount( 1, $rowsA );
        $this->assertSame( 'BASSO, A.', $rowsA[0]->jugadorNombre );
    }

    public function test_a_failed_insert_throws_instead_of_returning_a_bogus_id(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingInsertWpdb( 'wp_campeones_plantel' );

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles     = new TitleRepository( $failing );
            $repository = new SquadRepository( $failing );

            $title = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
            $this->assertNotNull( $title );

            $this->expectException( WriteFailedException::class );
            $repository->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );
        } finally {
            $wpdb = $original;
        }
    }
}
