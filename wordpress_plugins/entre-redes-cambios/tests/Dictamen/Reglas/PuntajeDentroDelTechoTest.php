<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\PuntajeDentroDelTecho;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class PuntajeDentroDelTechoTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_entrante_puntaje_is_within_the_techo(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entrantePuntaje' => Puntaje::fromDecimal( 2.5 ) ] );

        $this->assertNull( ( new PuntajeDentroDelTecho() )->evaluar( $ctx ) );
    }

    public function test_passes_at_the_exact_boundary(): void {
        // plaza's techo is 3.0 (puntaje_techo => 6 half-points, see fixture).
        $ctx = $this->ctxFavorableSustitucion( [ 'entrantePuntaje' => Puntaje::fromDecimal( 3.0 ) ] );

        $this->assertNull( ( new PuntajeDentroDelTecho() )->evaluar( $ctx ) );
    }

    public function test_fails_above_the_techo(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'entrantePuntaje' => Puntaje::fromDecimal( 3.5 ) ] );

        $motivo = ( new PuntajeDentroDelTecho() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'puntaje_excede_techo', $motivo->codigo() );
    }

    public function test_applies_the_2_5_floor_even_for_a_lower_raw_techo(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'plaza'           => $this->plaza( [ 'puntaje_techo' => 4 ] ), // raw techo 2.0
                'entrantePuntaje' => Puntaje::fromDecimal( 2.5 ),
            ]
        );

        // techoEfectivo() floors 2.0 up to 2.5 — an entrante of exactly 2.5
        // must still be admitted.
        $this->assertNull( ( new PuntajeDentroDelTecho() )->evaluar( $ctx ) );
    }

    public function test_does_not_apply_to_a_regreso(): void {
        $ctx = $this->ctxFavorableRegreso();

        $this->assertNull( ( new PuntajeDentroDelTecho() )->evaluar( $ctx ) );
    }
}
