<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Plazas;

use EntreRedes\Cambios\Plazas\Puntaje;
use PHPUnit\Framework\TestCase;

class PuntajeTest extends TestCase {

    /**
     * @return array<string, array{0: float, 1: int}>
     */
    public static function validDecimalsProvider(): array {
        return [
            '1'   => [ 1.0, 2 ],
            '1.5' => [ 1.5, 3 ],
            '2'   => [ 2.0, 4 ],
            '2.5' => [ 2.5, 5 ],
            '3'   => [ 3.0, 6 ],
            '3.5' => [ 3.5, 7 ],
            '4'   => [ 4.0, 8 ],
            '4.5' => [ 4.5, 9 ],
            '5'   => [ 5.0, 10 ],
        ];
    }

    /**
     * @dataProvider validDecimalsProvider
     */
    public function test_from_decimal_accepts_all_9_discrete_values( float $decimal, int $expectedHalfPoints ): void {
        $puntaje = Puntaje::fromDecimal( $decimal );

        $this->assertSame( $expectedHalfPoints, $puntaje->halfPoints() );
        $this->assertSame( $decimal, $puntaje->toDecimal() );
    }

    /**
     * @dataProvider validDecimalsProvider
     */
    public function test_from_half_points_accepts_all_9_discrete_values( float $decimal, int $expectedHalfPoints ): void {
        $puntaje = Puntaje::fromHalfPoints( $expectedHalfPoints );

        $this->assertSame( $expectedHalfPoints, $puntaje->halfPoints() );
        $this->assertSame( $decimal, $puntaje->toDecimal() );
    }

    public function test_from_decimal_rejects_a_value_not_in_the_discrete_set(): void {
        $this->expectException( \InvalidArgumentException::class );

        Puntaje::fromDecimal( 2.3 );
    }

    public function test_from_half_points_rejects_below_the_minimum(): void {
        $this->expectException( \InvalidArgumentException::class );

        Puntaje::fromHalfPoints( 1 );
    }

    public function test_from_half_points_rejects_above_the_maximum(): void {
        $this->expectException( \InvalidArgumentException::class );

        Puntaje::fromHalfPoints( 11 );
    }

    public function test_from_decimal_normalizes_a_comma_decimal_separator(): void {
        $puntaje = Puntaje::fromDecimal( '2,5' );

        $this->assertSame( 5, $puntaje->halfPoints() );
        $this->assertSame( 2.5, $puntaje->toDecimal() );
    }

    // -------------------------------------------------------------------------
    // techoEfectivo() — la regla del 2,5
    // -------------------------------------------------------------------------

    public function test_techo_efectivo_of_a_puntaje_below_2_5_floors_to_2_5(): void {
        $this->assertSame( 2.5, Puntaje::fromDecimal( 2.0 )->techoEfectivo()->toDecimal() );
        $this->assertSame( 2.5, Puntaje::fromDecimal( 1.5 )->techoEfectivo()->toDecimal() );
        $this->assertSame( 2.5, Puntaje::fromDecimal( 1.0 )->techoEfectivo()->toDecimal() );
    }

    public function test_techo_efectivo_of_2_5_itself_is_2_5(): void {
        $this->assertSame( 2.5, Puntaje::fromDecimal( 2.5 )->techoEfectivo()->toDecimal() );
    }

    public function test_techo_efectivo_above_2_5_is_unchanged(): void {
        $this->assertSame( 3.0, Puntaje::fromDecimal( 3.0 )->techoEfectivo()->toDecimal() );
        $this->assertSame( 5.0, Puntaje::fromDecimal( 5.0 )->techoEfectivo()->toDecimal() );
    }

    // -------------------------------------------------------------------------
    // admite() — borde exacto
    // -------------------------------------------------------------------------

    public function test_admite_is_true_at_the_exact_boundary(): void {
        $techo = Puntaje::fromDecimal( 2.5 );

        $this->assertTrue( $techo->admite( Puntaje::fromDecimal( 2.5 ) ) );
    }

    public function test_admite_is_true_below_the_boundary(): void {
        $techo = Puntaje::fromDecimal( 3.0 );

        $this->assertTrue( $techo->admite( Puntaje::fromDecimal( 2.5 ) ) );
    }

    public function test_admite_is_false_above_the_boundary(): void {
        $techo = Puntaje::fromDecimal( 2.5 );

        $this->assertFalse( $techo->admite( Puntaje::fromDecimal( 3.0 ) ) );
    }

    public function test_admite_applies_the_2_5_floor_even_for_a_lower_techo(): void {
        // techo_efectivo of a 2.0 techo is 2.5 — an entrante of 2.5 must
        // still be admitted even though the RAW techo is only 2.0.
        $techo = Puntaje::fromDecimal( 2.0 );

        $this->assertTrue( $techo->admite( Puntaje::fromDecimal( 2.5 ) ) );
        $this->assertFalse( $techo->admite( Puntaje::fromDecimal( 3.0 ) ) );
    }

    /**
     * THE bug this whole value object exists to avoid: naive float
     * comparison of a boundary condition must never be relied upon directly.
     * halfPoints() representation makes `2.5 <= 2.5` an EXACT integer
     * comparison (5 <= 5), always true, regardless of how either side's
     * puntaje was originally parsed or computed.
     */
    public function test_half_points_representation_never_fails_the_2_5_boundary_comparison(): void {
        $fromDecimal = Puntaje::fromDecimal( 2.5 );
        $fromComma   = Puntaje::fromDecimal( '2,5' );
        $fromHalf    = Puntaje::fromHalfPoints( 5 );

        $this->assertSame( $fromDecimal->halfPoints(), $fromComma->halfPoints() );
        $this->assertSame( $fromDecimal->halfPoints(), $fromHalf->halfPoints() );
        $this->assertTrue( $fromDecimal->halfPoints() <= $fromComma->halfPoints() );
        $this->assertTrue( $fromHalf->admite( $fromDecimal ) );
    }

    public function test_to_string_renders_without_trailing_zero(): void {
        $this->assertSame( '2.5', (string) Puntaje::fromDecimal( 2.5 ) );
        $this->assertSame( '5', (string) Puntaje::fromDecimal( 5.0 ) );
    }
}
