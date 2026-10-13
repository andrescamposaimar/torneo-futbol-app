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

    /**
     * "Open" (holds its plaza and entrante) and "not terminal" are the same
     * fact — see EstadoSolicitud::ABIERTAS. Adding a state to the graph
     * without deciding whether it is open (or vice versa) must fail HERE,
     * not as a silent gap in the open-request rules.
     */
    public function test_abiertas_is_exactly_the_set_of_non_terminal_states(): void {
        $noTerminales = array_values( array_filter( EstadoSolicitud::todos(), static fn ( string $e ): bool => ! EstadoSolicitud::esTerminal( $e ) ) );

        $abiertas = EstadoSolicitud::abiertas();
        sort( $noTerminales );
        sort( $abiertas );

        $this->assertSame( $noTerminales, $abiertas );
        $this->assertSame( [ 'aprobada', 'pendiente' ], $abiertas );
    }

    /**
     * `terminales()` and `abiertas()` must PARTITION `todos()` — every state
     * in exactly one of the two, none in both and none in neither. The
     * histórico (`Admin\HistoricoPage`) shows one side and the bandeja the
     * other, so a sixth estado that landed in neither would be invisible on
     * BOTH screens: decided, stored, and impossible to find.
     */
    public function test_terminales_and_abiertas_partition_every_state(): void {
        $terminales = EstadoSolicitud::terminales();
        $abiertas   = EstadoSolicitud::abiertas();

        $union = array_merge( $terminales, $abiertas );
        sort( $union );

        $todos = EstadoSolicitud::todos();
        sort( $todos );

        $this->assertSame( $todos, $union, 'Every estado must be either terminal or open — never neither.' );
        $this->assertSame( [], array_intersect( $terminales, $abiertas ), 'No estado may be both terminal and open.' );
        // Canonicalizing: the ORDER of terminales() is an accident of the
        // TODOS constant, not a contract. Pinning it with assertSame would
        // fail on a harmless reordering and teach the next person that the
        // order means something.
        $this->assertEqualsCanonicalizing( [ 'rechazada', 'publicada', 'anulada' ], $terminales );
    }
}
