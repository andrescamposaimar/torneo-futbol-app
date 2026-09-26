<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\CadenaResolver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CadenaResolver — pure, no DB, no clock. The injected
 * countResolvedFechasSinceFn is a stub keyed by fecha_desde_id, standing in
 * for Calendario\FechaRepository::countResolvedFechasSince().
 */
class CadenaResolverTest extends TestCase {

    /**
     * @param array<int, int> $resolvedSince Maps fecha_desde_id => number of
     *        resolved fechas counted from it (inclusive), exactly what
     *        Calendario\FechaRepository::countResolvedFechasSince() returns.
     */
    private function resolverWithStub( array $resolvedSince ): CadenaResolver {
        return new CadenaResolver(
            static function ( int $fechaId ) use ( $resolvedSince ): int {
                if ( ! isset( $resolvedSince[ $fechaId ] ) ) {
                    throw new \OutOfBoundsException( "No stub configured for fecha_id {$fechaId}." );
                }

                return $resolvedSince[ $fechaId ];
            }
        );
    }

    /**
     * @param array{fecha_desde_id: int, fecha_hasta_id: ?int, player_id: int, cerrada_por?: ?string} $overrides
     * @return array<string, mixed>
     */
    private function ocupacion( array $overrides ): array {
        return array_merge(
            [
                'fecha_hasta_id' => null,
                'cerrada_por'    => null,
                'es_titular'     => 0,
            ],
            $overrides
        );
    }

    // -------------------------------------------------------------------------
    // cumpleMinimo
    // -------------------------------------------------------------------------

    public function test_cumple_minimo_is_false_below_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 2 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertFalse( $resolver->cumpleMinimo( $ocupacion ) );
    }

    public function test_cumple_minimo_is_true_at_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 3 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertTrue( $resolver->cumpleMinimo( $ocupacion ) );
    }

    public function test_cumple_minimo_is_true_above_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 10 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertTrue( $resolver->cumpleMinimo( $ocupacion ) );
    }

    // -------------------------------------------------------------------------
    // Renewal by silence: an ocupacion that already met the minimo stays
    // vigent — there is no maximum duration, and nothing here closes it.
    // -------------------------------------------------------------------------

    public function test_plaza_liberable_is_true_well_past_the_minimo_but_the_vigent_link_stays_open(): void {
        $resolver = $this->resolverWithStub( [ 1 => 50 ] );

        $vigente     = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] );
        $ocupaciones = [ $vigente ];

        $this->assertTrue( $resolver->plazaLiberable( $ocupaciones ) );
        $this->assertSame( 0, $resolver->fechasFaltantesParaLiberar( $ocupaciones ) );

        // No duration cap: even though the minimo was cleared long ago, the
        // link handed to the resolver is still the SAME open link (no
        // fecha_hasta_id) — plazaLiberable() only reports that closing it
        // WOULD now be valid; it never closes anything itself.
        $this->assertNull( $vigente['fecha_hasta_id'] );
        $this->assertSame( 888, (int) $vigente['player_id'] );
    }

    // -------------------------------------------------------------------------
    // fechasFaltantesParaLiberar corre hacia adelante con cada eslabón nuevo
    // -------------------------------------------------------------------------

    public function test_a_new_link_pushes_the_liberation_forward_not_cached(): void {
        // The plaza was ALMOST liberable under its first suplente (fecha 1,
        // 2 resolved fechas — 1 short), but a NEW link starting later (fecha
        // 9, 0 resolved fechas yet) resets the wait from scratch. This is the
        // test that proves the liberation moment cannot be cached from the
        // first computation.
        $resolver = $this->resolverWithStub( [ 1 => 2, 9 => 0 ] );

        $primerSuplente = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 9,
            'player_id'      => 888,
            'cerrada_por'    => 'trunca',
        ] );

        $casiLiberable = [ array_merge( $primerSuplente, [ 'fecha_hasta_id' => null ] ) ];
        $this->assertSame( 1, $resolver->fechasFaltantesParaLiberar( $casiLiberable ) );

        $segundoSuplente = $this->ocupacion( [ 'fecha_desde_id' => 9, 'player_id' => 999 ] );
        $conNuevoEslabon = [ $primerSuplente, $segundoSuplente ];

        $this->assertSame( 3, $resolver->fechasFaltantesParaLiberar( $conNuevoEslabon ) );
        $this->assertFalse( $resolver->plazaLiberable( $conNuevoEslabon ) );
    }

    // -------------------------------------------------------------------------
    // titularPuedeVolver
    // -------------------------------------------------------------------------

    public function test_titular_puede_volver_is_false_before_the_minimo(): void {
        $resolver = $this->resolverWithStub( [ 1 => 1 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse( $resolver->titularPuedeVolver( $plaza, $ocupaciones ) );
    }

    public function test_titular_puede_volver_is_true_after_the_minimo(): void {
        $resolver = $this->resolverWithStub( [ 1 => 3 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertTrue( $resolver->titularPuedeVolver( $plaza, $ocupaciones ) );
    }

    public function test_titular_puede_volver_is_false_when_titular_already_occupies_the_plaza(): void {
        $resolver = $this->resolverWithStub( [ 1 => 10 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] ) ];

        $this->assertFalse( $resolver->titularPuedeVolver( $plaza, $ocupaciones ) );
    }

    // -------------------------------------------------------------------------
    // exOcupantesBloqueados — three links (titular -> S1 -> S2 -> S3): every
    // trunca ex-occupant stays blocked until the plaza liberates, then ALL
    // unblock together.
    // -------------------------------------------------------------------------

    public function test_all_trunco_ex_occupants_stay_blocked_until_the_plaza_liberates_then_unblock_together(): void {
        // Titular -> S1 (trunca, left too early) -> S2 (trunca too) -> S3
        // (vigent, has not yet cleared the minimo).
        $resolver = $this->resolverWithStub( [ 1 => 10, 5 => 10, 9 => 1 ] );

        $titular = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 1,
            'player_id'      => 777,
            'cerrada_por'    => 'reemplazada',
            'es_titular'     => 1,
        ] );
        $s1 = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 5,
            'player_id'      => 888,
            'cerrada_por'    => 'trunca',
        ] );
        $s2 = $this->ocupacion( [
            'fecha_desde_id' => 5,
            'fecha_hasta_id' => 9,
            'player_id'      => 999,
            'cerrada_por'    => 'trunca',
        ] );
        $s3 = $this->ocupacion( [ 'fecha_desde_id' => 9, 'player_id' => 111 ] );

        $cadena = [ $titular, $s1, $s2, $s3 ];

        // S3 has not cleared the minimo yet (only 1 resolved fecha since
        // fecha 9) — the plaza is NOT liberable, so both trunco ex-occupants
        // remain blocked.
        $this->assertFalse( $resolver->plazaLiberable( $cadena ) );
        $this->assertSame( [ 888, 999 ], $resolver->exOcupantesBloqueados( $cadena ) );

        // Now S3 has cleared the minimo: the plaza liberates, and BOTH
        // blocked ex-occupants unblock together — not one before the other.
        $resolverLiberado = $this->resolverWithStub( [ 1 => 10, 5 => 10, 9 => 3 ] );
        $this->assertTrue( $resolverLiberado->plazaLiberable( $cadena ) );
        $this->assertSame( [], $resolverLiberado->exOcupantesBloqueados( $cadena ) );
    }
}
