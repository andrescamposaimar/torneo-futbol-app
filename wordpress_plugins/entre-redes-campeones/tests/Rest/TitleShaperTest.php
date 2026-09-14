<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Rest;

use EntreRedes\Campeones\Rest\TitleShaper;
use EntreRedes\Campeones\Titles\SquadEntry;
use EntreRedes\Campeones\Titles\TitleRecord;
use PHPUnit\Framework\TestCase;

/**
 * Pure row -> array mapping tests for TitleShaper (design §7, the
 * MatchShaper precedent). No WordPress, no wpdb, no HTTP request.
 */
class TitleShaperTest extends TestCase {

    private function title( int $anio = 2016, string $zona = 'A', string $posicion = 'campeon', string $equipo = 'CHELSEA' ): TitleRecord {
        return TitleRecord::fromRow( [
            'id'            => 1,
            'anio'          => $anio,
            'zona'          => $zona,
            'posicion'      => $posicion,
            'equipo_nombre' => $equipo,
            'created_at'    => '2016-01-01 00:00:00',
            'updated_at'    => '2016-01-01 00:00:00',
        ] );
    }

    private function entry( string $nombre, bool $capitan = false, ?int $jugadorId = null, string $estado = 'sin_candidato' ): SquadEntry {
        return SquadEntry::fromRow( [
            'id'                  => 1,
            'titulo_id'           => 1,
            'orden'               => 0,
            'jugador_nombre'      => $nombre,
            'jugador_nombre_norm' => $nombre,
            'jugador_id'          => $jugadorId,
            'es_capitan'          => $capitan,
            'estado_vinculo'      => $estado,
            'candidatos_json'     => null,
        ] );
    }

    // -------------------------------------------------------------------------
    // shapeHistoryEntry() — API-1 / API-3, plus the round-7 foto_url addition
    // -------------------------------------------------------------------------

    public function test_shape_history_entry_maps_the_title_header(): void {
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [] );

        $this->assertSame( 2016, $shaped['anio'] );
        $this->assertSame( 'A', $shaped['zona'] );
        $this->assertSame( 'campeon', $shaped['posicion'] );
        $this->assertSame( 'CHELSEA', $shaped['equipo_nombre'] );
        $this->assertSame( [], $shaped['plantel'] );
    }

    public function test_shape_history_entry_maps_every_squad_field(): void {
        $entry  = $this->entry( 'BASSO, A.', true, 5078, 'auto' );
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [ $entry ] );

        $row = $shaped['plantel'][0];
        $this->assertSame( 'BASSO, A.', $row['nombre'] );
        $this->assertTrue( $row['es_capitan'] );
        $this->assertSame( 5078, $row['jugador_id'] );
        $this->assertSame( 'auto', $row['estado_vinculo'] );
    }

    public function test_shape_history_entry_includes_an_all_unlinked_year_in_full(): void {
        // API-3: a year whose entire squad is sin_candidato appears in the
        // history in full — no error marker, no row dropped.
        $squad  = [ $this->entry( 'BASSO, A.' ), $this->entry( 'MAZZARA, M.' ) ];
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), $squad );

        $this->assertCount( 2, $shaped['plantel'] );
        foreach ( $shaped['plantel'] as $row ) {
            $this->assertNull( $row['jugador_id'] );
            $this->assertSame( 'sin_candidato', $row['estado_vinculo'] );
        }
    }

    public function test_a_linked_entry_present_in_the_photo_map_gets_that_url(): void {
        $entry  = $this->entry( 'BASSO, A.', false, 5078, 'auto' );
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [ $entry ], [ 5078 => 'https://example.com/basso-300x300.jpg' ] );

        $this->assertSame( 'https://example.com/basso-300x300.jpg', $shaped['plantel'][0]['foto_url'] );
    }

    public function test_a_linked_entry_absent_from_the_photo_map_gets_null(): void {
        $entry  = $this->entry( 'BASSO, A.', false, 5078, 'auto' );
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [ $entry ], [] );

        $this->assertNull( $shaped['plantel'][0]['foto_url'] );
    }

    public function test_an_unlinked_entry_always_gets_a_null_photo_and_its_null_id_is_never_used_as_a_map_key(): void {
        $entry  = $this->entry( 'MAZZARA, M.', false, null, 'sin_candidato' );
        // A pathological map with a null key would be a PHP TypeError risk;
        // this proves the shaper never even attempts the lookup for an
        // unlinked row.
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [ $entry ], [ 5078 => 'https://example.com/x.jpg' ] );

        $this->assertNull( $shaped['plantel'][0]['foto_url'] );
    }

    /**
     * @dataProvider falsyPhotoValueProvider
     */
    public function test_a_falsy_photo_map_value_is_coerced_to_null_never_a_boolean( mixed $rawValue ): void {
        // get_the_post_thumbnail_url() returns false (never null) for a
        // post with no thumbnail (design §7) — a naive pass-through would
        // emit `"foto_url": false`, a boolean in a nullable-string slot.
        $entry  = $this->entry( 'BASSO, A.', false, 5078, 'auto' );
        $shaped = TitleShaper::shapeHistoryEntry( $this->title(), [ $entry ], [ 5078 => $rawValue ] );

        $this->assertNull( $shaped['plantel'][0]['foto_url'] );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function falsyPhotoValueProvider(): array {
        return [
            'false'        => [ false ],
            'empty string' => [ '' ],
            'null'         => [ null ],
        ];
    }

    // -------------------------------------------------------------------------
    // shapePlayerTitulo() — API-2, deliberately NO photo field
    // -------------------------------------------------------------------------

    public function test_shape_player_titulo_maps_anio_equipo_and_captain_flag(): void {
        $shaped = TitleShaper::shapePlayerTitulo( [ 'anio' => 2023, 'equipo_nombre' => 'LIVERPOOL', 'es_capitan' => true ] );

        $this->assertSame( 2023, $shaped['anio'] );
        $this->assertSame( 'LIVERPOOL', $shaped['equipo_nombre'] );
        $this->assertTrue( $shaped['es_capitan'] );
    }

    public function test_shape_player_titulo_never_emits_a_photo_field(): void {
        // API-2 feeds the player-detail titles panel, which is text-only
        // (design §7's explicit decision) — a photo field here would be
        // silently redundant with the profile's own header photo.
        $shaped = TitleShaper::shapePlayerTitulo( [ 'anio' => 2016, 'equipo_nombre' => 'CHELSEA', 'es_capitan' => false ] );

        $this->assertArrayNotHasKey( 'foto_url', $shaped );
    }

    public function test_shape_player_titulo_casts_loosely_typed_row_values(): void {
        // Rows arrive from wpdb::get_results(ARRAY_A) as strings/ints
        // depending on the driver — the shaper must not trust the input type.
        $shaped = TitleShaper::shapePlayerTitulo( [ 'anio' => '2016', 'equipo_nombre' => 'CHELSEA', 'es_capitan' => '1' ] );

        $this->assertSame( 2016, $shaped['anio'] );
        $this->assertTrue( $shaped['es_capitan'] );
    }
}
