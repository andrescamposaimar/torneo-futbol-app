<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\SquadRepository;
use EntreRedes\Campeones\Titles\TitleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RevalidationService::revalidateYear() (task 3.3, ADMIN-9,
 * LINK-9, LINK-10).
 *
 * Consumes SquadRepository::findResolvableByTitle() only, so a `manual` row
 * is excluded at the query level (LINK-10) — never loaded, never touched,
 * no matter how many times revalidation runs.
 */
class RevalidationServiceTest extends TestCase {

    private SquadRepository $squads;
    private TitleRepository $titles;
    private RevalidationService $service;
    private int $tituloId;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );

        $this->squads = new SquadRepository( $wpdb );
        $this->titles = new TitleRepository( $wpdb );

        $title          = $this->titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );
        $this->tituloId = $title->id;

        $rows      = require __DIR__ . '/../Fixtures/players.php';
        $directory = FakePlayerDirectory::fromFixtureRows( $rows );

        $this->service = new RevalidationService(
            $this->titles,
            $this->squads,
            new LinkResolver( $directory ),
            new LinkWriteService( $this->squads )
        );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_plantel" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}campeones_titulo" );
    }

    public function test_a_stale_row_is_re_resolved_to_the_current_ambiguo_outcome(): void {
        // Inserted as if a previous, wrong resolution had marked it
        // sin_candidato; revalidation must recompute it against the real
        // directory, where "GARCIA, M." 2016 is a genuine two-way ambiguity.
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'GARCIA, M.', false, LinkState::SIN_CANDIDATO )
        );

        $count = $this->service->revalidateYear( $this->tituloId );

        $this->assertSame( 1, $count );

        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::AMBIGUO, $row->estadoVinculo );
        $this->assertNull( $row->jugadorId );
        $this->assertNotNull( $row->candidatosJson );
    }

    public function test_an_already_correct_auto_row_is_idempotent(): void {
        $id = $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );

        $this->service->revalidateYear( $this->tituloId );
        $first = $this->squads->find( $id );

        $this->service->revalidateYear( $this->tituloId );
        $second = $this->squads->find( $id );

        $this->assertSame( LinkState::AUTO, $first->estadoVinculo );
        $this->assertSame( $first->jugadorId, $second->jugadorId );
        $this->assertSame( $first->estadoVinculo, $second->estadoVinculo );
    }

    public function test_a_manual_row_is_never_loaded_and_never_changes_across_three_runs(): void {
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'MAZZARA, M.', false, LinkState::MANUAL, 999999 )
        );

        $this->service->revalidateYear( $this->tituloId );
        $this->service->revalidateYear( $this->tituloId );
        $this->service->revalidateYear( $this->tituloId );

        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        $this->assertSame( 999999, $row->jugadorId, 'A manual pointer must never be overwritten by revalidation, however many times it runs.' );
    }

    public function test_a_manual_unlink_with_a_null_pointer_survives_revalidation(): void {
        // LINK-9 acceptance scenario "manual unlink survives re-validation":
        // a manual row with NO pointer must stay unlinked, even though
        // BASSO, A. would otherwise resolve auto with exactly one candidate.
        $id = $this->squads->insert(
            new SquadEntry( $this->tituloId, 0, 'BASSO, A.', false, LinkState::MANUAL, null )
        );

        $this->service->revalidateYear( $this->tituloId );

        $row = $this->squads->find( $id );
        $this->assertSame( LinkState::MANUAL, $row->estadoVinculo );
        $this->assertNull( $row->jugadorId );
    }

    public function test_only_resolvable_rows_are_counted(): void {
        $this->squads->insert( new SquadEntry( $this->tituloId, 0, 'BASSO, A.' ) );
        $this->squads->insert(
            new SquadEntry( $this->tituloId, 1, 'MAZZARA, M.', false, LinkState::MANUAL, 999999 )
        );

        $count = $this->service->revalidateYear( $this->tituloId );

        $this->assertSame( 1, $count, 'The manual row must not be counted among revalidated rows.' );
    }
}
