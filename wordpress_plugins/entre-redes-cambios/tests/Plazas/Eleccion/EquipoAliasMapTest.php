<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas\Eleccion;

use EntreRedes\Cambios\Plazas\Eleccion\EquipoAliasMap;
use EntreRedes\Cambios\Plazas\Eleccion\TextNormalizer;
use PHPUnit\Framework\TestCase;

class EquipoAliasMapTest extends TestCase {

    public function test_resolves_the_known_alias(): void {
        $normalized = TextNormalizer::normalize( "Cote D'Ivoire" );

        $this->assertSame( 'COSTA DE MARFIL', EquipoAliasMap::resolve( $normalized ) );
    }

    public function test_leaves_an_unaliased_name_unchanged(): void {
        $this->assertSame( 'BOCA', EquipoAliasMap::resolve( 'BOCA' ) );
    }
}
