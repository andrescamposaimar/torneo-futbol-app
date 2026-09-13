<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Titles;

use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Support\FailingTitleDeleteWpdb;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleDeletionService;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TitleDeletionService — deletes a title and its full squad
 * inside one transaction (ADMIN-7), so a title is never left with orphaned
 * squad rows and a squad is never left pointing at a deleted title.
 */
class TitleDeletionServiceTest extends TestCase {

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    public function test_delete_removes_the_title_and_every_squad_row(): void {
        global $wpdb;

        $titles = new TitleRepository( $wpdb );
        $squads = new SquadRepository( $wpdb );
        $service = new TitleDeletionService( $wpdb, $titles, $squads );

        $title = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );
        $squads->insert( new SquadEntry( $title->id, 1, 'CALELLO, G.' ) );

        $this->assertTrue( $service->delete( $title->id ) );

        $this->assertNull( $titles->find( $title->id ) );
        $this->assertSame( [], $squads->findByTitle( $title->id ) );
    }

    public function test_delete_of_a_title_with_no_squad_succeeds(): void {
        global $wpdb;

        $titles  = new TitleRepository( $wpdb );
        $squads  = new SquadRepository( $wpdb );
        $service = new TitleDeletionService( $wpdb, $titles, $squads );

        $title = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

        $this->assertTrue( $service->delete( $title->id ) );
        $this->assertNull( $titles->find( $title->id ) );
    }

    public function test_a_failed_title_delete_rolls_back_and_leaves_the_squad_intact(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingTitleDeleteWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles  = new TitleRepository( $failing );
            $squads  = new SquadRepository( $failing );
            $service = new TitleDeletionService( $failing, $titles, $squads );

            $title = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
            $squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );

            $this->assertFalse( $service->delete( $title->id ) );

            // The squad delete happened inside the same transaction as the
            // failed title delete — a real ROLLBACK undoes both, or neither.
            $this->assertNotNull( $titles->find( $title->id ), 'A rolled-back delete must leave the title in place.' );
            $this->assertCount( 1, $squads->findByTitle( $title->id ), 'A rolled-back delete must leave the squad in place.' );
        } finally {
            $wpdb = $original;
        }
    }
}
