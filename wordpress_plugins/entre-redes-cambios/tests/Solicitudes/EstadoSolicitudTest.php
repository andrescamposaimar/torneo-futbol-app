<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Solicitudes;

use EntreRedes\Cambios\Solicitudes\EstadoSolicitud;
use EntreRedes\Cambios\Solicitudes\Exception\TransicionInvalidaException;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive coverage of EstadoSolicitud's transition graph — every VALID
 * pair succeeds, and every OTHER pair among the 5 known states throws. This
 * is the single place the whole 5x5 matrix is enumerated; SolicitudRepositoryTest
 * only needs to check that the repository actually consults this class, not
 * re-derive the matrix itself.
 */
class EstadoSolicitudTest extends TestCase {

    private const VALID_PAIRS = [
        [ EstadoSolicitud::PENDIENTE, EstadoSolicitud::APROBADA ],
        [ EstadoSolicitud::PENDIENTE, EstadoSolicitud::RECHAZADA ],
        [ EstadoSolicitud::PENDIENTE, EstadoSolicitud::ANULADA ],
        [ EstadoSolicitud::APROBADA, EstadoSolicitud::PUBLICADA ],
        [ EstadoSolicitud::APROBADA, EstadoSolicitud::RECHAZADA ],
        [ EstadoSolicitud::APROBADA, EstadoSolicitud::ANULADA ],
    ];

    public function test_every_valid_transition_is_valid(): void {
        foreach ( self::VALID_PAIRS as [ $desde, $hasta ] ) {
            $this->assertTrue(
                EstadoSolicitud::esTransicionValida( $desde, $hasta ),
                "{$desde} -> {$hasta} should be valid"
            );

            EstadoSolicitud::assertTransicionValida( $desde, $hasta );
        }

        $this->addToAssertionCount( 1 );
    }

    /**
     * Every OTHER pair among the 5 known states — including a state to
     * itself, and every transition OUT of a terminal state — must be
     * invalid and must throw.
     */
    public function test_every_other_pair_among_known_states_is_invalid(): void {
        $validSet = array_map(
            static fn ( array $pair ): string => implode( '->', $pair ),
            self::VALID_PAIRS
        );

        foreach ( EstadoSolicitud::todos() as $desde ) {
            foreach ( EstadoSolicitud::todos() as $hasta ) {
                if ( in_array( "{$desde}->{$hasta}", $validSet, true ) ) {
                    continue;
                }

                $this->assertFalse(
                    EstadoSolicitud::esTransicionValida( $desde, $hasta ),
                    "{$desde} -> {$hasta} should be INVALID"
                );

                try {
                    EstadoSolicitud::assertTransicionValida( $desde, $hasta );
                    $this->fail( "Expected TransicionInvalidaException for {$desde} -> {$hasta}" );
                } catch ( TransicionInvalidaException $e ) {
                    $this->assertStringContainsString( $desde, $e->getMessage() );
                    $this->assertStringContainsString( $hasta, $e->getMessage() );
                }
            }
        }
    }

    public function test_terminal_states(): void {
        $this->assertTrue( EstadoSolicitud::esTerminal( EstadoSolicitud::PUBLICADA ) );
        $this->assertTrue( EstadoSolicitud::esTerminal( EstadoSolicitud::RECHAZADA ) );
        $this->assertTrue( EstadoSolicitud::esTerminal( EstadoSolicitud::ANULADA ) );
        $this->assertFalse( EstadoSolicitud::esTerminal( EstadoSolicitud::PENDIENTE ) );
        $this->assertFalse( EstadoSolicitud::esTerminal( EstadoSolicitud::APROBADA ) );
    }

    public function test_transicionesDesde_is_empty_for_terminal_and_unknown_states(): void {
        $this->assertSame( [], EstadoSolicitud::transicionesDesde( EstadoSolicitud::PUBLICADA ) );
        $this->assertSame( [], EstadoSolicitud::transicionesDesde( 'estado_inexistente' ) );
    }

    public function test_esValido(): void {
        foreach ( EstadoSolicitud::todos() as $estado ) {
            $this->assertTrue( EstadoSolicitud::esValido( $estado ) );
        }

        $this->assertFalse( EstadoSolicitud::esValido( 'no_existe' ) );
    }
}
