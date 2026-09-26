<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\LigaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LigaResolver — pure, no DB, no HTTP.
 *
 * Fixtures below deliberately mix the ASCII hyphen and the Unicode en dash
 * (U+2013) exactly as production league names do — see LigaResolver's class
 * docblock, "THE TWO-DASH TRAP".
 */
class LigaResolverTest extends TestCase {

    // -------------------------------------------------------------------------
    // torneoLabelFromName(): both dash variants, no-accent Clasificacion,
    // unresolvable names
    // -------------------------------------------------------------------------

    public function test_resolves_apertura_from_a_name_using_an_ascii_hyphen(): void {
        $this->assertSame(
            'Apertura',
            LigaResolver::torneoLabelFromName( '2026 - Apertura Zona A' )
        );
    }

    public function test_resolves_clausura_from_a_name_using_a_unicode_en_dash(): void {
        // U+2013 EN DASH, not a regular hyphen-minus.
        $this->assertSame(
            'Clausura',
            LigaResolver::torneoLabelFromName( "2026 \u{2013} Clausura Zona B" )
        );
    }

    public function test_resolves_clasificacion_without_its_accent(): void {
        $this->assertSame(
            'Clasificacion',
            LigaResolver::torneoLabelFromName( 'Clasificacion Zona 1' )
        );
    }

    public function test_resolves_clasificacion_even_if_a_future_edit_adds_the_accent_back(): void {
        $this->assertSame(
            'Clasificacion',
            LigaResolver::torneoLabelFromName( 'Clasificación Zona 1' )
        );
    }

    public function test_returns_null_for_a_name_matching_none_of_the_three_keywords(): void {
        $this->assertNull( LigaResolver::torneoLabelFromName( '2024 - Playoffs Final' ) );
    }

    public function test_returns_null_for_an_empty_name(): void {
        $this->assertNull( LigaResolver::torneoLabelFromName( '' ) );
    }

    // -------------------------------------------------------------------------
    // index(): exclusion of unresolvable ligas, tolerance of both `seasons`
    // shapes
    // -------------------------------------------------------------------------

    public function test_index_excludes_ligas_whose_name_resolves_to_no_torneo(): void {
        $ligas = [
            [ 'id' => 373, 'name' => '2026 - Apertura Zona A', 'seasons' => [ 149, 359 ] ],
            [ 'id' => 900, 'name' => '2024 - Playoffs Final', 'seasons' => [ 149 ] ],
        ];

        $index = LigaResolver::index( $ligas );

        $this->assertArrayHasKey( '2026 - Apertura Zona A', $index );
        $this->assertArrayNotHasKey( '2024 - Playoffs Final', $index );
        $this->assertCount( 1, $index );
    }

    public function test_index_builds_id_and_torneo_label_per_name(): void {
        $ligas = [
            [ 'id' => 373, 'name' => '2026 - Apertura Zona A', 'seasons' => [ 359 ] ],
            [ 'id' => 377, 'name' => "2026 \u{2013} Clausura Zona A", 'seasons' => [ 359 ] ],
        ];

        $index = LigaResolver::index( $ligas );

        $this->assertSame(
            [ 'id' => 373, 'torneo_label' => 'Apertura' ],
            $index[ '2026 - Apertura Zona A' ]
        );
        $this->assertSame(
            [ 'id' => 377, 'torneo_label' => 'Clausura' ],
            $index[ "2026 \u{2013} Clausura Zona A" ]
        );
    }

    public function test_index_tolerates_seasons_as_a_plain_list(): void {
        $ligas = [
            [ 'id' => 143, 'name' => '2024 - Apertura Zona A', 'seasons' => [ 149, 359 ] ],
        ];

        $index = LigaResolver::index( $ligas );

        $this->assertSame( 143, $index[ '2024 - Apertura Zona A' ]['id'] );
    }

    public function test_index_tolerates_seasons_as_an_object_with_numeric_string_keys(): void {
        // What a JSON-decoded PHP array-with-gaps looks like:
        // json_decode('{"0":143,"19":359}', true) === ['0' => 143, '19' => 359].
        $ligas = [
            [ 'id' => 143, 'name' => '2024 - Apertura Zona A', 'seasons' => [ '0' => 143, '19' => 359 ] ],
        ];

        $index = LigaResolver::index( $ligas );

        $this->assertSame( 143, $index[ '2024 - Apertura Zona A' ]['id'] );
    }

    public function test_index_tolerates_a_missing_seasons_key_entirely(): void {
        $ligas = [
            [ 'id' => 143, 'name' => '2024 - Apertura Zona A' ],
        ];

        $index = LigaResolver::index( $ligas );

        $this->assertSame( 143, $index[ '2024 - Apertura Zona A' ]['id'] );
    }

    // -------------------------------------------------------------------------
    // torneoMap(): the shape SeedTemporadaService's constructor expects
    // -------------------------------------------------------------------------

    public function test_torneo_map_keys_by_liga_id(): void {
        $index = [
            '2026 - Apertura Zona A'       => [ 'id' => 373, 'torneo_label' => 'Apertura' ],
            "2026 \u{2013} Clausura Zona A" => [ 'id' => 377, 'torneo_label' => 'Clausura' ],
            'Clasificacion Zona 1'         => [ 'id' => 371, 'torneo_label' => 'Clasificacion' ],
        ];

        $this->assertSame(
            [
                373 => 'Apertura',
                377 => 'Clausura',
                371 => 'Clasificacion',
            ],
            LigaResolver::torneoMap( $index )
        );
    }

    public function test_torneo_map_of_an_empty_index_is_empty(): void {
        $this->assertSame( [], LigaResolver::torneoMap( [] ) );
    }
}
