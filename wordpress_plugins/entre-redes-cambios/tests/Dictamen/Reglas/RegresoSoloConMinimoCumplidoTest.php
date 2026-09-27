<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen\Reglas;

use EntreRedes\Cambios\Dictamen\Reglas\RegresoSoloConMinimoCumplido;
use EntreRedes\Cambios\Tests\Support\BuildsDictamenFixtures;
use PHPUnit\Framework\TestCase;

class RegresoSoloConMinimoCumplidoTest extends TestCase {
    use BuildsDictamenFixtures;

    public function test_passes_once_the_vigent_suplente_cleared_the_minimo(): void {
        // Favorable regreso fixture: suplente (999) since fecha 9, 10
        // resolved fechas counted — well past the mínimo of 3.
        $ctx = $this->ctxFavorableRegreso();

        $this->assertNull( ( new RegresoSoloConMinimoCumplido() )->evaluar( $ctx ) );
    }

    public function test_fails_before_the_minimo_and_reports_how_many_fechas_are_missing(): void {
        $ctx = $this->ctxFavorableRegreso( [ 'countResolvedFechasSinceFn' => static fn ( int $fechaId ): int => 1 ] );

        $motivo = ( new RegresoSoloConMinimoCumplido() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'regreso_antes_del_minimo', $motivo->codigo() );
        $this->assertSame( [ 'fechasFaltantes' => 2 ], $motivo->datos() );
    }

    public function test_is_a_noop_when_the_titular_already_occupies_the_plaza(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'ocupaciones' => [ $this->ocupacion() ], // vigent link is the titular (777) himself
            ]
        );

        $this->assertNull( ( new RegresoSoloConMinimoCumplido() )->evaluar( $ctx ) );
    }

    public function test_does_not_apply_to_a_sustitucion(): void {
        $ctx = $this->ctxFavorableSustitucion( [ 'countResolvedFechasSinceFn' => static fn ( int $fechaId ): int => 0 ] );

        $this->assertNull( ( new RegresoSoloConMinimoCumplido() )->evaluar( $ctx ) );
    }

    public function test_reports_an_indeterminate_count_when_the_counter_throws_on_the_second_call(): void {
        $ctx = $this->ctxFavorableRegreso(
            [
                'countResolvedFechasSinceFn' => static function ( int $fechaId ): int {
                    throw new \RuntimeException( 'fecha_id ya no existe en la temporada' );
                },
            ]
        );

        $motivo = ( new RegresoSoloConMinimoCumplido() )->evaluar( $ctx );

        $this->assertNotNull( $motivo );
        $this->assertSame( 'regreso_antes_del_minimo', $motivo->codigo() );
        $this->assertSame( [ 'fechasFaltantes' => null ], $motivo->datos() );
    }
}
