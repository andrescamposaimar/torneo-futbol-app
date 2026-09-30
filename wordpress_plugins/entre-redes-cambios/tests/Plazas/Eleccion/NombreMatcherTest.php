<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\NombreMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Fixture names are invented, but shaped exactly like the real cases this
 * feature's task brief names as required survivors: a stray space before
 * the comma, a compound surname shortened on one side, and a genuine
 * ambiguous tie that must never be resolved automatically.
 */
class NombreMatcherTest extends TestCase {

    public function test_exact_match_after_normalization(): void {
        $pool = [ 11 => 'PEREZ, JUAN' ];

        $this->assertSame( [ 11 ], NombreMatcher::candidatosCoincidentes( 'Perez, Juan', $pool ) );
    }

    public function test_survives_a_stray_space_before_the_comma(): void {
        $pool = [ 11 => 'SINCLAIR, JUAN MARTIN' ];

        $this->assertSame( [ 11 ], NombreMatcher::candidatosCoincidentes( 'SINCLAIR , JUAN MARTIN', $pool ) );
    }

    public function test_survives_a_compound_surname_shortened_on_one_side(): void {
        $pool = [ 22 => 'MORALEJO, EZEQUIEL' ];

        $this->assertSame( [ 22 ], NombreMatcher::candidatosCoincidentes( 'MORALEJO RIVERA, EZEQUIEL', $pool ) );
    }

    public function test_survives_a_compound_surname_extended_on_one_side(): void {
        $pool = [ 33 => 'PALOU DE COMASEMA, JAVIER IGNACIO' ];

        $this->assertSame( [ 33 ], NombreMatcher::candidatosCoincidentes( 'PALOU, JAVIER', $pool ) );
    }

    public function test_no_shared_surname_is_no_match(): void {
        $pool = [ 11 => 'GOMEZ, JUAN' ];

        $this->assertSame( [], NombreMatcher::candidatosCoincidentes( 'PEREZ, JUAN', $pool ) );
    }

    public function test_shared_surname_but_different_first_given_name_is_no_match(): void {
        $pool = [ 11 => 'PEREZ, CARLOS' ];

        $this->assertSame( [], NombreMatcher::candidatosCoincidentes( 'PEREZ, JUAN', $pool ) );
    }

    public function test_ambiguous_tie_returns_every_candidate_never_picks_one(): void {
        // A compound surname split across two different candidates, each
        // sharing ONE token with the needle and the same first given name —
        // both satisfy the condition, so both must come back, never just one.
        $pool = [
            11 => 'GONZALEZ, JUAN CARLOS',
            12 => 'MARTINEZ, JUAN PABLO',
        ];

        $matches = NombreMatcher::candidatosCoincidentes( 'GONZALEZ MARTINEZ, JUAN', $pool );
        sort( $matches );

        $this->assertSame( [ 11, 12 ], $matches );
    }

    public function test_a_first_given_name_mismatch_is_no_match_even_with_surname_overlap(): void {
        // Real case this matcher must NOT resolve on its own (see
        // EleccionImporter's class docblock on the override file): the
        // surnames overlap but the first given name differs, so this stays
        // unresolved rather than being guessed.
        $pool = [ 11 => 'MONTESANO DIAZ, RICHARD PABLO' ];

        $this->assertSame( [], NombreMatcher::candidatosCoincidentes( 'MONTESANO, PABLO', $pool ) );
    }

    public function test_a_name_with_no_comma_never_matches(): void {
        $pool = [ 11 => 'PEREZ JUAN' ];

        $this->assertSame( [], NombreMatcher::candidatosCoincidentes( 'PEREZ JUAN', $pool ) );
    }
}
