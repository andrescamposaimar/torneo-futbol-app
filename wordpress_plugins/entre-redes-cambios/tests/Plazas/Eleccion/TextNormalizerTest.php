<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\TextNormalizer;
use PHPUnit\Framework\TestCase;

class TextNormalizerTest extends TestCase {

    public function test_uppercases_and_strips_accents(): void {
        $this->assertSame( 'MARIA JOSE', TextNormalizer::normalize( 'María José' ) );
    }

    public function test_collapses_repeated_whitespace_and_trims(): void {
        $this->assertSame( 'SINCLAIR , JUAN MARTIN', TextNormalizer::normalize( '  Sinclair  ,   Juan   Martin  ' ) );
    }

    public function test_strips_characters_outside_the_allowed_set(): void {
        $this->assertSame( 'COTE DIVOIRE', TextNormalizer::normalize( "Cote D'Ivoire" ) );
    }

    public function test_keeps_commas_and_digits(): void {
        $this->assertSame( 'PEREZ, JUAN 2', TextNormalizer::normalize( 'Perez, Juan 2' ) );
    }
}
