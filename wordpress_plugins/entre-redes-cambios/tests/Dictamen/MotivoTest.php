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

    public function test_movimiento_is_null_when_not_tagged(): void {
        $motivo = new Motivo( 'algun_codigo', 'Algún mensaje legible.' );

        $this->assertNull( $motivo->movimiento() );
    }

    public function test_con_movimiento_returns_a_copy_tagged_with_the_movement(): void {
        $original = new Motivo( 'algun_codigo', 'Algún mensaje legible.', [ 'clave' => 42 ] );

        $tagged = $original->conMovimiento( 'arco' );

        $this->assertSame( 'arco', $tagged->movimiento() );
        $this->assertSame( 'algun_codigo', $tagged->codigo() );
        $this->assertSame( 'Algún mensaje legible.', $tagged->mensaje() );
        $this->assertSame( [ 'clave' => 42, 'movimiento' => 'arco' ], $tagged->datos() );

        // The original is untouched — conMovimiento() never mutates in place.
        $this->assertNull( $original->movimiento() );
        $this->assertSame( [ 'clave' => 42 ], $original->datos() );
    }
}
