<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\PlazaNoCerrada;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class PlazaNoCerradaTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_when_the_plaza_is_not_closed(): void {
        $ctx = $this->ctxFavorableSustitucion();

        $this->assertNull( ( new PlazaNoCerrada() )->evaluate( $ctx ) );
    }

    public function test_fails_when_the_plaza_has_a_closed_at(): void {
        $ctx = $this->ctxFavorableSustitucion(
            [
                'plaza' => $this->plaza( [ 'closed_at' => '2026-04-01 10:00:00' ] ),
            ]
        );

        $motivo = ( new PlazaNoCerrada() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_cerrada', $motivo->codigo() );
    }

    public function test_applies_to_a_regreso_too(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'plaza' => $this->plaza( [ 'closed_at' => '2026-04-01 10:00:00' ] ),
            ]
        );

        $motivo = ( new PlazaNoCerrada() )->evaluate( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'plaza_cerrada', $motivo->codigo() );
    }
}
