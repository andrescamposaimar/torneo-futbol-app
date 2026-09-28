<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\Motivo;
use PHPUnit\Framework\TestCase;

class MotivoTest extends TestCase {

    public function test_exposes_codigo_mensaje_and_datos(): void {
        $motivo = new Motivo( 'algun_codigo', 'Algún mensaje legible.', [ 'clave' => 42 ] );

        $this->assertSame( 'algun_codigo', $motivo->codigo() );
        $this->assertSame( 'Algún mensaje legible.', $motivo->mensaje() );
        $this->assertSame( [ 'clave' => 42 ], $motivo->datos() );
    }

    public function test_datos_defaults_to_an_empty_array(): void {
        $motivo = new Motivo( 'algun_codigo', 'Algún mensaje legible.' );

        $this->assertSame( [], $motivo->datos() );
    }
}
