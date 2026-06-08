<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\BackfillMatchMetaService;
use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Migrations\InitialSchema;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for BackfillMatchMetaService against the SQLite shim.
 *
 * The dispatcher is stubbed with canned /partidos payloads keyed by date.
 */
class BackfillMatchMetaServiceTest extends TestCase {

    private FechaRepository $repo;

    protected function setUp(): void {
        InitialSchema::up();

        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );

        $this->repo = new FechaRepository( $wpdb );
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fecha_matches" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_fechas" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}prode_settings" );
        InitialSchema::up();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Insert a legacy (empty-snapshot) match row directly. */
    private function insertLegacyMatch( int $fechaId, int $matchId, string $kickoff ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'prode_fecha_matches', [
            'fecha_id'      => $fechaId,
            'match_id'      => $matchId,
            'match_kickoff' => $kickoff,
            // home_team/away_team/zona default to '' ; escudos to '' via shim.
        ] );
    }

    private function getRow( int $matchId ): array {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_fecha_matches WHERE match_id = %d LIMIT 1",
                $matchId
            ),
            ARRAY_A
        );
    }

    private function partidoItem( int $id ): array {
        return [
            'id'               => $id,
            'equipo_local'     => "Home {$id}",
            'equipo_visitante' => "Away {$id}",
            'liga'             => "Zona {$id}",
            'escudo_local'     => "https://example.com/home-{$id}.png",
            'escudo_visitante' => "https://example.com/away-{$id}.png",
        ];
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_backfills_empty_rows_from_payload(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );

        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => '2026-06-06' === $date ? [ $this->partidoItem( 22608 ) ] : []
        );

        $updated = $service->run();

        $this->assertSame( 1, $updated );

        $row = $this->getRow( 22608 );
        $this->assertSame( 'Home 22608', $row['home_team'] );
        $this->assertSame( 'Away 22608', $row['away_team'] );
        $this->assertSame( 'Zona 22608', $row['zona'] );
        $this->assertSame( 'https://example.com/home-22608.png', $row['home_escudo'] );
        $this->assertSame( 'https://example.com/away-22608.png', $row['away_escudo'] );
    }

    public function test_already_snapshotted_rows_are_left_untouched(): void {
        // A row seeded WITH a snapshot must not be re-fetched or overwritten.
        $this->repo->upsertFecha( 'test_tenant', 359, '2026-06-05 13:45:00', [
            [
                'match_id'    => 22608,
                'kickoff'     => '2026-06-06 13:45:00',
                'home_team'   => 'Original Home',
                'away_team'   => 'Original Away',
                'zona'        => 'Original Zona',
                'home_escudo' => 'https://example.com/orig-home.png',
                'away_escudo' => 'https://example.com/orig-away.png',
            ],
        ] );

        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => [ $this->partidoItem( 22608 ) ] // would overwrite if used
        );

        $updated = $service->run();

        $this->assertSame( 0, $updated );
        $this->assertSame( 'Original Home', $this->getRow( 22608 )['home_team'] );
    }

    public function test_is_idempotent_on_second_run(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );

        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => [ $this->partidoItem( 22608 ) ]
        );

        $this->assertSame( 1, $service->run() );
        $this->assertSame( 0, $service->run() ); // nothing left to fill
    }

    public function test_match_not_in_payload_is_left_empty(): void {
        $this->insertLegacyMatch( 1, 99999, '2026-06-06 13:45:00' );

        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => [ $this->partidoItem( 22608 ) ] // no 99999
        );

        $this->assertSame( 0, $service->run() );
        $this->assertSame( '', $this->getRow( 99999 )['home_team'] );
    }

    public function test_groups_requests_by_play_date(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );
        $this->insertLegacyMatch( 2, 22630, '2026-06-13 13:45:00' );

        $datesQueried = [];
        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            function ( string $date ) use ( &$datesQueried ): array {
                $datesQueried[] = $date;
                $byDate = [
                    '2026-06-06' => [ $this->partidoItem( 22608 ) ],
                    '2026-06-13' => [ $this->partidoItem( 22630 ) ],
                ];
                return $byDate[ $date ] ?? [];
            }
        );

        $updated = $service->run();

        $this->assertSame( 2, $updated );
        sort( $datesQueried );
        $this->assertSame( [ '2026-06-06', '2026-06-13' ], $datesQueried );
        $this->assertSame( 'Home 22608', $this->getRow( 22608 )['home_team'] );
        $this->assertSame( 'Home 22630', $this->getRow( 22630 )['home_team'] );
    }

    public function test_empty_name_in_payload_does_not_overwrite(): void {
        $this->insertLegacyMatch( 1, 22608, '2026-06-06 13:45:00' );

        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => [
                [ 'id' => 22608, 'equipo_local' => '', 'equipo_visitante' => '' ],
            ]
        );

        $this->assertSame( 0, $service->run() );
        $this->assertSame( '', $this->getRow( 22608 )['home_team'] );
    }

    public function test_no_rows_returns_zero(): void {
        $service = new BackfillMatchMetaService(
            $GLOBALS['wpdb'],
            fn( string $date ): array => [ $this->partidoItem( 1 ) ]
        );
        $this->assertSame( 0, $service->run() );
    }
}
