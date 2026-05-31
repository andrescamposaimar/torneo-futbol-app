<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\FechaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FechaResolver.
 *
 * All tests inject a stub dispatcher closure returning canned payloads.
 * No real rest_do_request is ever called.
 *
 * Payload shape mirrors the /entre-redes/v1/partidos-programados response:
 *   { id, fecha, hora, equipo_local, equipo_visitante, goles_local, goles_visitante, ... }
 */
class FechaResolverTest extends TestCase {

    // -------------------------------------------------------------------------
    // Helpers — canned payload factories
    // -------------------------------------------------------------------------

    private function makeItem( int $id, string $fecha, string $hora, ?int $golesLocal = null, ?int $golesVisitante = null ): array {
        return [
            'id'                => $id,
            'fecha'             => $fecha,
            'hora'              => $hora,
            'equipo_local'      => "Home Team {$id}",
            'equipo_visitante'  => "Away Team {$id}",
            'goles_local'       => $golesLocal,
            'goles_visitante'   => $golesVisitante,
        ];
    }

    /**
     * Returns a dispatcher stub that always returns the given items array.
     */
    private function stubDispatcher( array $items ): callable {
        return static function () use ( $items ): array {
            return $items;
        };
    }

    // -------------------------------------------------------------------------
    // resolveNext — empty / null-returning cases
    // -------------------------------------------------------------------------

    public function test_empty_items_returns_null(): void {
        $resolver = new FechaResolver( $this->stubDispatcher( [] ) );
        $this->assertNull( $resolver->resolveNext() );
    }

    public function test_all_items_have_scores_returns_null(): void {
        $items = [
            $this->makeItem( 10, '2026-05-30', '13:45', 2, 1 ),
            $this->makeItem( 11, '2026-05-30', '15:10', 0, 0 ),
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $this->assertNull( $resolver->resolveNext() );
    }

    public function test_null_goles_item_is_included(): void {
        // Both null means upcoming (not yet played)
        $items = [
            $this->makeItem( 10, '2026-05-30', '13:45', null, null ),
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();
        $this->assertNotNull( $result );
        $this->assertCount( 1, $result['matches'] );
    }

    // -------------------------------------------------------------------------
    // resolveNext — happy path, date grouping, normalization
    // -------------------------------------------------------------------------

    public function test_happy_path_multiple_matches_same_date(): void {
        $items = [
            $this->makeItem( 10, '2026-05-30', '13:45' ),
            $this->makeItem( 11, '2026-05-30', '15:10' ),
            $this->makeItem( 12, '2026-06-06', '13:45' ), // different date — excluded
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext( 1 );

        $this->assertNotNull( $result );
        $this->assertSame( '2026-05-30', $result['play_date'] );
        $this->assertCount( 2, $result['matches'] );

        $matchIds = array_column( $result['matches'], 'match_id' );
        $this->assertContains( 10, $matchIds );
        $this->assertContains( 11, $matchIds );
        $this->assertNotContains( 12, $matchIds );
    }

    public function test_kickoff_is_composed_from_fecha_and_hora(): void {
        $items = [ $this->makeItem( 10, '2026-05-30', '13:45' ) ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $this->assertSame( '2026-05-30 13:45', $result['matches'][0]['kickoff'] );
    }

    public function test_match_id_is_cast_to_int_from_id(): void {
        $items = [ $this->makeItem( 42, '2026-05-30', '10:00' ) ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $this->assertSame( 42, $result['matches'][0]['match_id'] );
        $this->assertIsInt( $result['matches'][0]['match_id'] );
    }

    public function test_missing_hora_defaults_to_0000(): void {
        $item = $this->makeItem( 10, '2026-05-30', '' );
        unset( $item['hora'] ); // hora absent entirely
        $resolver = new FechaResolver( $this->stubDispatcher( [ $item ] ) );
        $result = $resolver->resolveNext();

        $this->assertStringEndsWith( '00:00', $result['matches'][0]['kickoff'] );
    }

    public function test_empty_hora_defaults_to_0000(): void {
        $item = $this->makeItem( 10, '2026-05-30', '' ); // hora is empty string
        $resolver = new FechaResolver( $this->stubDispatcher( [ $item ] ) );
        $result = $resolver->resolveNext();

        $this->assertSame( '2026-05-30 00:00', $result['matches'][0]['kickoff'] );
    }

    public function test_multi_zona_same_date_all_grouped_under_play_date(): void {
        // Simulates two different ligas/zonas on the same date
        $items = [
            $this->makeItem( 10, '2026-05-30', '10:00' ),
            $this->makeItem( 11, '2026-05-30', '12:00' ),
            $this->makeItem( 12, '2026-05-30', '14:00' ),
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $this->assertSame( '2026-05-30', $result['play_date'] );
        $this->assertCount( 3, $result['matches'] );
    }

    public function test_already_played_item_is_skipped(): void {
        $items = [
            $this->makeItem( 10, '2026-05-30', '13:45', 2, 1 ), // played
            $this->makeItem( 11, '2026-05-30', '15:10', null, null ), // upcoming
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $this->assertNotNull( $result );
        $this->assertCount( 1, $result['matches'] );
        $this->assertSame( 11, $result['matches'][0]['match_id'] );
    }

    public function test_earliest_kickoff_is_returned_in_result(): void {
        $items = [
            $this->makeItem( 10, '2026-05-30', '15:10' ),
            $this->makeItem( 11, '2026-05-30', '13:45' ), // earlier
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $this->assertSame( '2026-05-30 13:45', $result['earliest_kickoff'] );
    }

    public function test_team_names_are_mapped_from_equipo_fields(): void {
        $items = [
            [
                'id'               => 10,
                'fecha'            => '2026-05-30',
                'hora'             => '13:45',
                'equipo_local'     => 'Marianista FC',
                'equipo_visitante' => 'Rival United',
                'goles_local'      => null,
                'goles_visitante'  => null,
            ],
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $items ) );
        $result = $resolver->resolveNext();

        $match = $result['matches'][0];
        $this->assertSame( 'Marianista FC', $match['home_team'] );
        $this->assertSame( 'Rival United', $match['away_team'] );
    }

    // -------------------------------------------------------------------------
    // enrichMatches
    // -------------------------------------------------------------------------

    public function test_enrich_matches_attaches_team_names_by_match_id(): void {
        $liveItems = [
            [
                'id'               => 10,
                'fecha'            => '2026-05-30',
                'hora'             => '13:45',
                'equipo_local'     => 'Marianista FC',
                'equipo_visitante' => 'Rival United',
                'goles_local'      => null,
                'goles_visitante'  => null,
            ],
            [
                'id'               => 11,
                'fecha'            => '2026-05-30',
                'hora'             => '15:10',
                'equipo_local'     => 'Eagles SC',
                'equipo_visitante' => 'Lions CF',
                'goles_local'      => null,
                'goles_visitante'  => null,
            ],
        ];
        $resolver = new FechaResolver( $this->stubDispatcher( $liveItems ) );

        $persisted = [
            [ 'match_id' => 10, 'match_kickoff' => '2026-05-30 13:45:00' ],
            [ 'match_id' => 11, 'match_kickoff' => '2026-05-30 15:10:00' ],
        ];

        $enriched = $resolver->enrichMatches( $persisted );

        $this->assertSame( 'Marianista FC', $enriched[0]['home_team'] );
        $this->assertSame( 'Rival United', $enriched[0]['away_team'] );
        $this->assertSame( 'Eagles SC', $enriched[1]['home_team'] );
        $this->assertSame( 'Lions CF', $enriched[1]['away_team'] );
    }

    public function test_enrich_matches_unknown_match_id_falls_back_to_empty_string(): void {
        // Live endpoint returns no item for match_id=99
        $resolver = new FechaResolver( $this->stubDispatcher( [] ) );

        $persisted = [
            [ 'match_id' => 99, 'match_kickoff' => '2026-05-30 13:45:00' ],
        ];

        $enriched = $resolver->enrichMatches( $persisted );

        $this->assertSame( '', $enriched[0]['home_team'] );
        $this->assertSame( '', $enriched[0]['away_team'] );
    }
}
