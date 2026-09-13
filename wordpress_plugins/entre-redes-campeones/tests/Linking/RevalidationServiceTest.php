<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use EntreRedes\Campeones\Linking\LinkWriteService;
use EntreRedes\Campeones\Linking\RevalidationService;
use EntreRedes\Campeones\Migrations\InitialSchema;
use EntreRedes\Campeones\Tests\Support\FailingResolutionApplyWpdb;
use EntreRedes\Campeones\Tests\Support\PartialFailureResolutionApplyWpdb;
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
            new LinkWriteService( $this->squads, $directory )
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

        $result = $this->service->revalidateYear( $this->tituloId );

        $this->assertSame( 1, $result->succeeded );
        $this->assertSame( 1, $result->total );

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

        $result = $this->service->revalidateYear( $this->tituloId );

        $this->assertSame( 1, $result->succeeded, 'The manual row must not be counted among revalidated rows.' );
        $this->assertSame( 1, $result->total, 'The manual row must not be counted among attempted rows either.' );
    }

    public function test_a_row_whose_resolution_write_fails_is_not_counted_as_revalidated(): void {
        // Item 6: revalidateYear() discarded applyResolution()'s boolean
        // inside the loop and returned count($entries) regardless — row 14
        // of 25 failing still reported 25. Force the write to fail for
        // every resolvable row and prove the count reflects that, not the
        // number of rows merely iterated.
        global $wpdb;
        $original = $wpdb;
        $failing  = new FailingResolutionApplyWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles = new TitleRepository( $failing );
            $squads = new SquadRepository( $failing );
            $title  = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

            $rows      = require __DIR__ . '/../Fixtures/players.php';
            $directory = FakePlayerDirectory::fromFixtureRows( $rows );

            $service = new RevalidationService(
                $titles,
                $squads,
                new LinkResolver( $directory ),
                new LinkWriteService( $squads, $directory )
            );

            $id = $squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );

            $result = $service->revalidateYear( $title->id );

            $this->assertSame( 0, $result->succeeded, 'A failed resolution write must not be counted as revalidated.' );
            $this->assertSame( 1, $result->total, 'The row was still attempted, even though its write failed.' );

            $row = $squads->find( $id );
            $this->assertSame( LinkState::SIN_CANDIDATO, $row->estadoVinculo, 'The row must be left exactly as it was when its write failed.' );
        } finally {
            $wpdb = $original;
        }
    }

    // -------------------------------------------------------------------------
    // Item 2 — revalidateYear() returned only $succeeded, discarding how
    // many rows were attempted. A caller could not tell "1 of 1 succeeded"
    // apart from "1 of 25 succeeded" — both came back as a bare `1`.
    // -------------------------------------------------------------------------

    public function test_a_partial_failure_reports_succeeded_strictly_less_than_total(): void {
        global $wpdb;
        $original = $wpdb;
        $failing  = new PartialFailureResolutionApplyWpdb();

        try {
            $wpdb = $failing;
            InitialSchema::up();

            $titles = new TitleRepository( $failing );
            $squads = new SquadRepository( $failing );
            $title  = $titles->createOrConflict( 2016, 'A', 'campeon', 'CHELSEA' );

            $rows      = require __DIR__ . '/../Fixtures/players.php';
            $directory = FakePlayerDirectory::fromFixtureRows( $rows );

            $service = new RevalidationService(
                $titles,
                $squads,
                new LinkResolver( $directory ),
                new LinkWriteService( $squads, $directory )
            );

            // Two resolvable rows; PartialFailureResolutionApplyWpdb fails
            // exactly the second resolution write it sees.
            $squads->insert( new SquadEntry( $title->id, 0, 'BASSO, A.' ) );
            $squads->insert( new SquadEntry( $title->id, 1, 'ZUBIZARRETA, F.' ) );

            $result = $service->revalidateYear( $title->id );

            $this->assertSame( 1, $result->succeeded );
            $this->assertSame( 2, $result->total, 'Both rows were attempted, even though only one write succeeded.' );
        } finally {
            $wpdb = $original;
        }
    }
}
