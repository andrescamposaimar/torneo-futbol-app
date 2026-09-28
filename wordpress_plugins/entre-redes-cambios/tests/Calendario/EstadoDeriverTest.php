<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\EstadoDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EstadoDeriver — pure state derivation with injected clock.
 */
class EstadoDeriverTest extends TestCase {

    public function test_all_partidos_with_resultado_derive_jugada(): void {
        $partidos = [
            [ 'tiene_resultado' => true ],
            [ 'tiene_resultado' => true ],
        ];

        $state = EstadoDeriver::derive( $partidos, '2026-05-30', '2026-06-01 00:00:00' );

        $this->assertSame( 'jugada', $state );
    }

    public function test_missing_resultados_before_play_date_derives_programada(): void {
        $partidos = [
            [ 'tiene_resultado' => false ],
            [ 'tiene_resultado' => true ],
        ];

        // now is BEFORE play_date.
        $state = EstadoDeriver::derive( $partidos, '2026-05-30', '2026-05-20 12:00:00' );

        $this->assertSame( 'programada', $state );
    }

    public function test_missing_resultados_after_play_date_still_derives_programada_never_suspendida(): void {
        $partidos = [
            [ 'tiene_resultado' => false ],
            [ 'tiene_resultado' => true ],
        ];

        // now is AFTER play_date, but not all results are in.
        $state = EstadoDeriver::derive( $partidos, '2026-05-30', '2026-06-05 12:00:00' );

        $this->assertSame( 'programada', $state );
        $this->assertNotSame( 'suspendida', $state );
    }

    public function test_empty_partidos_list_is_treated_as_not_played(): void {
        $state = EstadoDeriver::derive( [], '2026-05-30', '2026-06-05 12:00:00' );

        $this->assertSame( 'programada', $state );
    }

    public function test_never_returns_dirimida(): void {
        $allPossibleInputs = [
            [ [ [ 'tiene_resultado' => true ] ], '2026-05-30', '2026-05-20 00:00:00' ],
            [ [ [ 'tiene_resultado' => false ] ], '2026-05-30', '2026-05-20 00:00:00' ],
            [ [ [ 'tiene_resultado' => false ] ], '2026-05-30', '2026-06-05 00:00:00' ],
            [ [], '2026-05-30', '2026-06-05 00:00:00' ],
        ];

        foreach ( $allPossibleInputs as [ $partidos, $playDate, $now ] ) {
            $state = EstadoDeriver::derive( $partidos, $playDate, $now );
            $this->assertNotSame( 'dirimida', $state );
            $this->assertNotSame( 'suspendida', $state );
        }
    }
}
