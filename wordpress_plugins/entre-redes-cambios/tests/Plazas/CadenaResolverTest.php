<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\CadenaResolver;
use EntreRedes\Cambios\Plazas\Exception\FechaCountUnavailableException;
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
                'es_genesis'     => 0,
            ],
            $overrides
        );
    }

    // -------------------------------------------------------------------------
    // meetsMinimo
    // -------------------------------------------------------------------------

    public function test_cumple_minimo_is_false_below_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 2 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertFalse( $resolver->meetsMinimo( $ocupacion ) );
    }

    public function test_cumple_minimo_is_true_at_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 3 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertTrue( $resolver->meetsMinimo( $ocupacion ) );
    }

    public function test_cumple_minimo_is_true_above_the_threshold(): void {
        $resolver  = $this->resolverWithStub( [ 1 => 10 ] );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->assertTrue( $resolver->meetsMinimo( $ocupacion ) );
    }

    // -------------------------------------------------------------------------
    // Renewal by silence: an ocupacion that already met the minimo stays
    // vigent — there is no maximum duration, and nothing here closes it.
    // -------------------------------------------------------------------------

    public function test_plaza_liberable_is_true_well_past_the_minimo_but_the_vigent_link_stays_open(): void {
        $resolver = $this->resolverWithStub( [ 1 => 50 ] );

        $vigente     = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] );
        $ocupaciones = [ $vigente ];

        $this->assertTrue( $resolver->isPlazaLiberable( $ocupaciones ) );
        $this->assertSame( 0, $resolver->countFechasUntilLiberacion( $ocupaciones ) );

        // No duration cap: even though the minimo was cleared long ago, the
        // link handed to the resolver is still the SAME open link (no
        // fecha_hasta_id) — isPlazaLiberable() only reports that closing it
        // WOULD now be valid; it never closes anything itself.
        $this->assertNull( $vigente['fecha_hasta_id'] );
        $this->assertSame( 888, (int) $vigente['player_id'] );
    }

    // -------------------------------------------------------------------------
    // countFechasUntilLiberacion corre hacia adelante con cada eslabón nuevo
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
        $this->assertSame( 1, $resolver->countFechasUntilLiberacion( $casiLiberable ) );

        $segundoSuplente = $this->ocupacion( [ 'fecha_desde_id' => 9, 'player_id' => 999 ] );
        $conNuevoEslabon = [ $primerSuplente, $segundoSuplente ];

        $this->assertSame( 3, $resolver->countFechasUntilLiberacion( $conNuevoEslabon ) );
        $this->assertFalse( $resolver->isPlazaLiberable( $conNuevoEslabon ) );
    }

    // -------------------------------------------------------------------------
    // canTitularReturn
    // -------------------------------------------------------------------------

    public function test_titular_puede_volver_is_false_before_the_minimo(): void {
        $resolver = $this->resolverWithStub( [ 1 => 1 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse( $resolver->canTitularReturn( $plaza, $ocupaciones ) );
    }

    public function test_titular_puede_volver_is_true_after_the_minimo(): void {
        $resolver = $this->resolverWithStub( [ 1 => 3 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertTrue( $resolver->canTitularReturn( $plaza, $ocupaciones ) );
    }

    public function test_titular_puede_volver_is_false_when_titular_already_occupies_the_plaza(): void {
        $resolver = $this->resolverWithStub( [ 1 => 10 ] );
        $plaza    = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] ) ];

        $this->assertFalse( $resolver->canTitularReturn( $plaza, $ocupaciones ) );
    }

    // -------------------------------------------------------------------------
    // listExOcupantesBloqueados — three links (titular -> S1 -> S2 -> S3): every
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
            'es_genesis'     => 1,
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
        $this->assertFalse( $resolver->isPlazaLiberable( $cadena ) );
        $this->assertSame( [ 888, 999 ], $resolver->listExOcupantesBloqueados( $cadena ) );

        // Now S3 has cleared the minimo: the plaza liberates, and BOTH
        // blocked ex-occupants unblock together — not one before the other.
        $resolverLiberado = $this->resolverWithStub( [ 1 => 10, 5 => 10, 9 => 3 ] );
        $this->assertTrue( $resolverLiberado->isPlazaLiberable( $cadena ) );
        $this->assertSame( [], $resolverLiberado->listExOcupantesBloqueados( $cadena ) );
    }

    // -------------------------------------------------------------------------
    // Fail-closed: an uncountable resolved-fechas answer must never liberate
    // a plaza, let the titular return, or unblock a trunco ex-occupant.
    // -------------------------------------------------------------------------

    private function resolverThatThrows( \Throwable $exception ): CadenaResolver {
        return new CadenaResolver(
            static function ( int $fechaId ) use ( $exception ): int {
                throw $exception;
            }
        );
    }

    private function resolverThatReturnsNegative( int $negativeCount ): CadenaResolver {
        return new CadenaResolver(
            static function ( int $fechaId ) use ( $negativeCount ): int {
                return $negativeCount;
            }
        );
    }

    public function test_meets_minimo_wraps_a_throwing_callable(): void {
        $resolver  = $this->resolverThatThrows( new \RuntimeException( 'fecha_id no longer exists in season' ) );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->expectException( FechaCountUnavailableException::class );

        $resolver->meetsMinimo( $ocupacion );
    }

    public function test_meets_minimo_rejects_a_negative_count(): void {
        $resolver  = $this->resolverThatReturnsNegative( -1 );
        $ocupacion = $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 777 ] );

        $this->expectException( FechaCountUnavailableException::class );

        $resolver->meetsMinimo( $ocupacion );
    }

    public function test_is_plaza_liberable_fails_closed_when_the_callable_throws(): void {
        $resolver    = $this->resolverThatThrows( new \RuntimeException( 'boom' ) );
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse(
            $resolver->isPlazaLiberable( $ocupaciones ),
            'A broken counter must never be read as "0 fechas missing".'
        );
    }

    public function test_is_plaza_liberable_fails_closed_when_the_callable_returns_a_negative_count(): void {
        $resolver    = $this->resolverThatReturnsNegative( -5 );
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse( $resolver->isPlazaLiberable( $ocupaciones ) );
    }

    public function test_can_titular_return_fails_closed_when_the_callable_throws(): void {
        $resolver    = $this->resolverThatThrows( new \RuntimeException( 'boom' ) );
        $plaza       = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse(
            $resolver->canTitularReturn( $plaza, $ocupaciones ),
            'A broken counter must never let the titular return.'
        );
    }

    public function test_can_titular_return_fails_closed_when_the_callable_returns_a_negative_count(): void {
        $resolver    = $this->resolverThatReturnsNegative( -1 );
        $plaza       = [ 'titular_player_id' => 777 ];
        $ocupaciones = [ $this->ocupacion( [ 'fecha_desde_id' => 1, 'player_id' => 888 ] ) ];

        $this->assertFalse( $resolver->canTitularReturn( $plaza, $ocupaciones ) );
    }

    public function test_list_ex_ocupantes_bloqueados_keeps_everyone_blocked_when_the_callable_throws(): void {
        $resolver = $this->resolverThatThrows( new \RuntimeException( 'boom' ) );

        $titular = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 1,
            'player_id'      => 777,
            'cerrada_por'    => 'reemplazada',
            'es_genesis'     => 1,
        ] );
        $s1 = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 5,
            'player_id'      => 888,
            'cerrada_por'    => 'trunca',
        ] );
        // The vigent link — its count is what makes the callable throw.
        $s2 = $this->ocupacion( [ 'fecha_desde_id' => 5, 'player_id' => 999 ] );

        $cadena = [ $titular, $s1, $s2 ];

        $this->assertSame(
            [ 888 ],
            $resolver->listExOcupantesBloqueados( $cadena ),
            'Never liberate because the count could not be verified — every trunco ex-occupant must stay blocked.'
        );
    }

    public function test_list_ex_ocupantes_bloqueados_keeps_everyone_blocked_when_the_callable_returns_a_negative_count(): void {
        $resolver = $this->resolverThatReturnsNegative( -3 );

        $titular = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 1,
            'player_id'      => 777,
            'cerrada_por'    => 'reemplazada',
            'es_genesis'     => 1,
        ] );
        $s1 = $this->ocupacion( [
            'fecha_desde_id' => 1,
            'fecha_hasta_id' => 5,
            'player_id'      => 888,
            'cerrada_por'    => 'trunca',
        ] );
        $s2 = $this->ocupacion( [ 'fecha_desde_id' => 5, 'player_id' => 999 ] );

        $cadena = [ $titular, $s1, $s2 ];

        $this->assertSame( [ 888 ], $resolver->listExOcupantesBloqueados( $cadena ) );
    }

    // -------------------------------------------------------------------------
    // Empty chain: a plaza structurally cannot have zero ocupaciones —
    // PlazaRepository::openPlaza() always creates the genesis link in the
    // same transaction as the plaza. An empty array reaching this class is a
    // CALLER BUG (wrong plaza_id, or a plaza never persisted correctly), not
    // a legitimately liberable plaza — see countFechasUntilLiberacion()'s
    // docblock for why this is a HARD failure (\InvalidArgumentException)
    // rather than something isPlazaLiberable() swallows into `false` like it
    // does for an unavailable count.
    // -------------------------------------------------------------------------

    public function test_count_fechas_until_liberacion_rejects_an_empty_chain(): void {
        $resolver = $this->resolverWithStub( [] );

        $this->expectException( \InvalidArgumentException::class );

        $resolver->countFechasUntilLiberacion( [] );
    }

    public function test_is_plaza_liberable_rejects_an_empty_chain_instead_of_vacuously_true(): void {
        $resolver = $this->resolverWithStub( [] );

        // Before this guard existed, an empty chain read as "0 fechas
        // missing" (nothing to count) and isPlazaLiberable([]) returned
        // `true` vacuously — exactly the kind of silent "yes" this class
        // must never produce. It is now a hard failure instead.
        $this->expectException( \InvalidArgumentException::class );

        $resolver->isPlazaLiberable( [] );
    }
}
