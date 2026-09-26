<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\PartidosApiClient;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PartidosApiClient against a stub `$httpGetFn` — no real HTTP,
 * no WordPress. The stub inspects the requested URL (path + query string) and
 * returns canned, already-decoded response bodies, exactly the contract
 * `$httpGetFn` is documented to fulfil.
 */
class PartidosApiClientTest extends TestCase {

    private const BASE_URL = 'https://example.test/wp-json/entre-redes/v1';

    private const LIGAS = [
        [ 'id' => 373, 'name' => '2026 - Apertura Zona A', 'seasons' => [ 359 ] ],
        [ 'id' => 374, 'name' => '2026 - Apertura Zona B', 'seasons' => [ 359 ] ],
        [ 'id' => 371, 'name' => 'Clasificacion General', 'seasons' => [ 359 ] ],
    ];

    /**
     * @param array<int, array{items: array<int, array<string, mixed>>, total_pages: int}> $partidosPages
     *        Keyed by page number.
     * @param array<int, array{items: array<int, array<string, mixed>>, total_pages: int}> $programadosPages
     *        Keyed by page number.
     */
    private function client(
        array $partidosPages = [],
        array $programadosPages = [],
        array $ligas = self::LIGAS
    ): PartidosApiClient {
        $httpGetFn = static function ( string $url ) use ( $partidosPages, $programadosPages, $ligas ): array {
            $parts = parse_url( $url );
            $path  = $parts['path'] ?? '';
            parse_str( $parts['query'] ?? '', $query );

            if ( str_ends_with( $path, '/partidos-programados' ) ) {
                $page = (int) ( $query['page'] ?? 1 );
                return $programadosPages[ $page ] ?? [ 'items' => [], 'total_pages' => 0 ];
            }

            if ( str_ends_with( $path, '/partidos' ) ) {
                $page = (int) ( $query['page'] ?? 1 );
                return $partidosPages[ $page ] ?? [ 'items' => [], 'total_pages' => 0 ];
            }

            if ( str_ends_with( $path, '/ligas' ) ) {
                return $ligas;
            }

            return [];
        };

        return new PartidosApiClient( $httpGetFn, self::BASE_URL );
    }

    // -------------------------------------------------------------------------
    // fetchLigasIndex(): delegates to LigaResolver::index()
    // -------------------------------------------------------------------------

    public function test_fetch_ligas_index_excludes_unresolvable_ligas(): void {
        $client = $this->client( [], [], array_merge( self::LIGAS, [
            [ 'id' => 900, 'name' => '2024 - Playoffs Final', 'seasons' => [ 149 ] ],
        ] ) );

        $index = $client->fetchLigasIndex();

        $this->assertArrayHasKey( '2026 - Apertura Zona A', $index );
        $this->assertArrayNotHasKey( '2024 - Playoffs Final', $index );
    }

    // -------------------------------------------------------------------------
    // fetchPartidos(): pagination, kickoff/zona normalization, tiene_resultado
    // -------------------------------------------------------------------------

    public function test_fetch_partidos_paginates_until_total_pages_is_exhausted(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 1001, 'fecha' => '2026-05-30', 'hora' => '16:25', 'liga' => '2026 - Apertura Zona A' ],
                    [ 'id' => 1002, 'fecha' => '2026-05-30', 'hora' => '17:00', 'liga' => '2026 - Apertura Zona B' ],
                ],
                'total_pages' => 2,
            ],
            2 => [
                'items'       => [
                    [ 'id' => 1003, 'fecha' => '2026-06-06', 'hora' => '10:00', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 2,
            ],
        ] );

        $ligasIndex = $client->fetchLigasIndex();
        $partidos   = $client->fetchPartidos( 359, $ligasIndex );

        $this->assertCount( 3, $partidos );
        $this->assertSame( [ 1001, 1002, 1003 ], array_column( $partidos, 'match_id' ) );
        $this->assertSame( 3, $client->stats()['publish'] );
    }

    public function test_fetch_partidos_normalizes_kickoff_from_fecha_and_hora(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 2001, 'fecha' => '2026-09-12', 'hora' => '16:25', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertSame( '2026-09-12 16:25:00', $partidos[0]['kickoff'] );
    }

    public function test_fetch_partidos_defaults_kickoff_time_to_midnight_when_hora_is_empty(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 2002, 'fecha' => '2026-09-12', 'hora' => '', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertSame( '2026-09-12 00:00:00', $partidos[0]['kickoff'] );
    }

    public function test_fetch_partidos_extracts_zona_suffix_from_liga_name(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 3001, 'fecha' => '2026-05-30', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertSame( 'Zona A', $partidos[0]['zona'] );
    }

    public function test_fetch_partidos_falls_back_to_full_liga_name_when_there_is_no_zona_token(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 3002, 'fecha' => '2026-03-07', 'hora' => '16:00', 'liga' => 'Clasificacion General' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertSame( 'Clasificacion General', $partidos[0]['zona'] );
    }

    public function test_fetch_partidos_marks_every_item_as_having_a_resultado(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 4001, 'fecha' => '2026-05-30', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertTrue( $partidos[0]['tiene_resultado'] );
    }

    public function test_fetch_partidos_skips_and_counts_an_item_with_an_unknown_liga(): void {
        $client = $this->client( [
            1 => [
                'items'       => [
                    [ 'id' => 5001, 'fecha' => '2026-05-30', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                    [ 'id' => 5002, 'fecha' => '2026-05-30', 'hora' => '16:00', 'liga' => 'Liga Fantasma Zona Z' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $partidos = $client->fetchPartidos( 359, $client->fetchLigasIndex() );

        $this->assertCount( 1, $partidos );
        $this->assertSame( [ 5001 ], array_column( $partidos, 'match_id' ) );

        $stats = $client->stats();
        $this->assertSame( 1, $stats['skipped_publish'] );
        $this->assertSame( [ 'Liga Fantasma Zona Z' => 1 ], $stats['skipped_ligas'] );
    }

    // -------------------------------------------------------------------------
    // fetchProgramados(): tiene_resultado=false, filtering by ligasIndex
    // (the endpoint itself does not filter by temporada)
    // -------------------------------------------------------------------------

    public function test_fetch_programados_marks_every_item_as_not_having_a_resultado(): void {
        $client = $this->client( [], [
            1 => [
                'items'       => [
                    [ 'id' => 6001, 'fecha' => '2026-11-14', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $programados = $client->fetchProgramados( $client->fetchLigasIndex() );

        $this->assertFalse( $programados[0]['tiene_resultado'] );
    }

    public function test_fetch_programados_paginates_until_total_pages_is_exhausted(): void {
        $client = $this->client( [], [
            1 => [
                'items'       => [
                    [ 'id' => 7001, 'fecha' => '2026-11-14', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                ],
                'total_pages' => 2,
            ],
            2 => [
                'items'       => [
                    [ 'id' => 7002, 'fecha' => '2026-11-21', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona B' ],
                ],
                'total_pages' => 2,
            ],
        ] );

        $programados = $client->fetchProgramados( $client->fetchLigasIndex() );

        $this->assertSame( [ 7001, 7002 ], array_column( $programados, 'match_id' ) );
        $this->assertSame( 2, $client->stats()['future'] );
    }

    public function test_fetch_programados_filters_out_items_whose_liga_is_not_in_the_index(): void {
        // /partidos-programados does not filter by temporada — an item
        // belonging to a liga NOT in the injected ligasIndex (e.g. old-season
        // noise, or a liga not yet registered) must be excluded and counted,
        // never trusted blindly.
        $client = $this->client( [], [
            1 => [
                'items'       => [
                    [ 'id' => 8001, 'fecha' => '2026-11-14', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                    [ 'id' => 8002, 'fecha' => '2026-11-14', 'hora' => '16:00', 'liga' => '2024 - Apertura Zona A' ],
                ],
                'total_pages' => 1,
            ],
        ] );

        $programados = $client->fetchProgramados( $client->fetchLigasIndex() );

        $this->assertCount( 1, $programados );
        $this->assertSame( [ 8001 ], array_column( $programados, 'match_id' ) );

        $stats = $client->stats();
        $this->assertSame( 1, $stats['skipped_programados'] );
        $this->assertSame( [ '2024 - Apertura Zona A' => 1 ], $stats['skipped_ligas'] );
    }

    // -------------------------------------------------------------------------
    // fetchAll(): orchestration
    // -------------------------------------------------------------------------

    public function test_fetch_all_combines_partidos_and_programados(): void {
        $client = $this->client(
            [
                1 => [
                    'items'       => [
                        [ 'id' => 9001, 'fecha' => '2026-05-30', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                    ],
                    'total_pages' => 1,
                ],
            ],
            [
                1 => [
                    'items'       => [
                        [ 'id' => 9002, 'fecha' => '2026-11-14', 'hora' => '16:00', 'liga' => '2026 - Apertura Zona A' ],
                    ],
                    'total_pages' => 1,
                ],
            ]
        );

        $all = $client->fetchAll( 359 );

        $this->assertSame( [ 9001, 9002 ], array_column( $all, 'match_id' ) );
        $this->assertTrue( $all[0]['tiene_resultado'] );
        $this->assertFalse( $all[1]['tiene_resultado'] );
    }
}
