<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolution;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RegisteredPlayer;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LinkWriteService — applies a LinkResolution to a squad row
 * (task 3.1) and lets a human set/change/clear a link, which always
 * transitions the row to `manual` (LINK-8).
 */
class LinkWriteServiceTest extends TestCase {

    private SquadRepository $squads;
    private LinkWriteService $service;
    private int $tituloId;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        $this->squads  = new SquadRepository( $wpdb );
        $this->service = new LinkWriteService( $this->squads, $directory );

        $titles         = new TitleRepository( $wpdb );
        $title          = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->tituloId = $title->id;
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    private function insertEntry( string $nombre = 'BASSO, A.' ): int {
        return $this->squads->insert( new SquadEntry( $this->tituloId, 0, $nombre ) );
    }

    // -------------------------------------------------------------------------
    // applyResolution() — auto / ambiguo / sin_candidato
    // -------------------------------------------------------------------------

    public function test_auto_resolution_sets_the_pointer(): void {
        $id = $this->insertEntry();

        $resolution = new LinkResolution( 5078, LinkState::AUTO, [] );
        $this->assertTrue( $this->service->applyResolution( $id, $resolution ) );

        $row = $this->squads->find( $id );
        $this->assertSame( 5078, $row->jugadorId );
        $this->assertSame( LinkState::AUTO, $row->estadoVinculo );
        $this->assertNull( $row->candidatosJson );
    }

    public function test_ambiguo_resolution_stores_candidates_and_leaves_pointer_null(): void {
        $id = $this->insertEntry();

        $candidates = [
            new RegisteredPlayer( 2225, 'Garcia, Miguel Luis' ),
            new RegisteredPlayer( 2461, 'Garcia, Marcelo Daniel' ),
        ];
        $resolution = new LinkResolution( null, LinkState::AMBIGUO, $candidates );
        $this->assertTrue( $this->service->applyResolution( $id, $resolution ) );

        $row = $this->squads->find( $id );
        $this->assertNull( $row->jugadorId );
        $this->assertSame( LinkState::AMBIGUO, $row->estadoVinculo );
        $this->assertNotNull( $row->candidatosJson );
        $this->assertSame( [ 2225, 2461 ], json_decode( $row->candidatosJson, true ) );
    }

    public function test_sin_candidato_resolution_leaves_pointer_null_and_no_candidates(): void {
        $id = $this->insertEntry();

        $resolution = new LinkResolution( null, LinkState::SIN_CANDIDATO, [] );
        $this->assertTrue( $this->service->applyResolution( $id, $resolution ) );

        $row = $this->squads->find( $id );
        $this->assertNull( $row->jugadorId );
        $this->assertSame( LinkState::SIN_CANDIDATO, $row->estadoVinculo );
        $this->assertNull( $row->candidatosJson );
    }

    // -------------------------------------------------------------------------
    // setManualLink() — LINK-8: any human set/change/clear becomes `manual`
    // -------------------------------------------------------------------------

    public function test_human_set_becomes_manual_with_the_chosen_pointer(): void {
        $id = $this->insertEntry();

        $this->assertTrue( $this->service->setManualLink( $id, 2225 ) );

        $row = $this->squads->find( $id );
        $this->assertSame( 2225, $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        $this->assertNull( $row->candidatosJson, 'A manual decision retains no candidate list.' );
    }

    public function test_human_change_replaces_the_pointer_and_stays_manual(): void {
        $id = $this->insertEntry();

        $this->service->setManualLink( $id, 2225 );
        $this->service->setManualLink( $id, 2461 );

        $row = $this->squads->find( $id );
        $this->assertSame( 2461, $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
    }

    public function test_human_clear_becomes_manual_with_a_null_pointer(): void {
        // LINK-8/LINK-9 acceptance scenario "manual unlink survives
        // re-validation": Desvincular leaves the pointer null but the state
        // becomes (and stays) `manual` — never back to sin_candidato/auto.
        $id = $this->insertEntry();
        $this->squads->update( $id, [ 'jugador_id' => 5078, 'estado_vinculo' => LinkState::AUTO ] );

        $this->assertTrue( $this->service->setManualLink( $id, null ) );

        $row = $this->squads->find( $id );
        $this->assertNull( $row->jugadorId );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
    }

    public function test_set_manual_link_rejects_a_nonexistent_player_id(): void {
        // Item 8: setManualLink() accepted any integer with no existence
        // check, so a fat-fingered id created a dangling reference. 999999
        // is not in the fixture directory.
        $id = $this->insertEntry();

        $this->assertFalse( $this->service->setManualLink( $id, 999999 ) );

        $row = $this->squads->find( $id );
        $this->assertSame( 'sin_candidato', $row->estadoVinculo, 'A rejected id must not become manual.' );
        $this->assertNull( $row->jugadorId );
    }
}
