<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\PlazosCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlazosCalculator — pure DateTime math with injected clock
 * and timezone. No DB, no shim, no globals. All boundary cases are fully
 * deterministic.
 */
class PlazosCalculatorTest extends TestCase {

    /** @return array<string, array{days:int, time:string}> */
    private function specOffsets(): array {
        return [
            'apertura_solicitudes' => [ 'days' => -6, 'time' => '00:00:00' ],
            'cierre_regresos'      => [ 'days' => -4, 'time' => '23:59:59' ],
            'cierre_solicitudes'   => [ 'days' => -2, 'time' => '23:59:59' ],
            'publicacion'          => [ 'days' => -1, 'time' => '00:00:00' ],
        ];
    }

    private const BA = 'America/Argentina/Buenos_Aires';

    // -------------------------------------------------------------------------
    // compute() — civil deadlines
    // -------------------------------------------------------------------------

    public function test_computes_the_four_plazos_for_a_known_play_date(): void {
        // 2026-05-30 is a Saturday.
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );

        $this->assertSame(
            [
                'apertura_solicitudes' => '2026-05-24 00:00:00', // Sunday
                'cierre_regresos'      => '2026-05-26 23:59:59', // Tuesday
                'cierre_solicitudes'   => '2026-05-28 23:59:59', // Thursday
                'publicacion'          => '2026-05-29 00:00:00', // Friday
            ],
            $plazos
        );
    }

    public function test_compute_crosses_month_and_year_boundary(): void {
        // 2027-01-02 is a Saturday; all four plazos fall in December 2026.
        $plazos = PlazosCalculator::compute( '2027-01-02', $this->specOffsets(), self::BA );

        $this->assertSame(
            [
                'apertura_solicitudes' => '2026-12-27 00:00:00',
                'cierre_regresos'      => '2026-12-29 23:59:59',
                'cierre_solicitudes'   => '2026-12-31 23:59:59',
                'publicacion'          => '2027-01-01 00:00:00',
            ],
            $plazos
        );
    }

    public function test_throws_when_an_offset_key_is_missing(): void {
        $this->expectException( \InvalidArgumentException::class );

        PlazosCalculator::compute(
            '2026-05-30',
            [ 'apertura_solicitudes' => [ 'days' => -6, 'time' => '00:00:00' ] ], // missing the other 3
            self::BA
        );
    }

    // -------------------------------------------------------------------------
    // Civil vs. absolute: what the timezone does and does not change
    // -------------------------------------------------------------------------

    /**
     * A civil deadline is timezone-invariant BY CONSTRUCTION — "Sunday at
     * midnight" is Sunday at midnight on every wall clock. This test pins that
     * as intended behavior so nobody later "fixes" compute() into elapsed-time
     * arithmetic to make the timezone visibly matter here. The place the
     * timezone is load-bearing is computeUtc(), tested below.
     *
     * The play_date is chosen so the window crosses the 2026-03-08 US
     * spring-forward, the case where elapsed-time arithmetic would drift.
     */
    public function test_compute_returns_the_same_civil_deadline_in_any_timezone(): void {
        $ba = PlazosCalculator::compute( '2026-03-14', $this->specOffsets(), self::BA );
        $ny = PlazosCalculator::compute( '2026-03-14', $this->specOffsets(), 'America/New_York' );

        $this->assertSame( $ba, $ny );
        $this->assertSame( '2026-03-08 00:00:00', $ba['apertura_solicitudes'] );
    }

    /**
     * computeUtc() is where $tz genuinely changes the answer: the same civil
     * deadline is a different real-world instant per zone. Values verified
     * independently against PHP's tz database.
     */
    public function test_compute_utc_differs_per_timezone(): void {
        $ba = PlazosCalculator::computeUtc( '2026-03-14', $this->specOffsets(), self::BA );
        $ny = PlazosCalculator::computeUtc( '2026-03-14', $this->specOffsets(), 'America/New_York' );

        $this->assertNotSame( $ba['apertura_solicitudes'], $ny['apertura_solicitudes'] );
        $this->assertSame( '2026-03-08 03:00:00', $ba['apertura_solicitudes'] ); // UTC-3, no DST
        $this->assertSame( '2026-03-08 05:00:00', $ny['apertura_solicitudes'] ); // still EST at midnight
    }

    public function test_compute_utc_shifts_argentina_deadlines_by_three_hours(): void {
        $utc = PlazosCalculator::computeUtc( '2026-05-30', $this->specOffsets(), self::BA );

        $this->assertSame(
            [
                'apertura_solicitudes' => '2026-05-24 03:00:00',
                'cierre_regresos'      => '2026-05-27 02:59:59', // rolls into the next UTC day
                'cierre_solicitudes'   => '2026-05-29 02:59:59',
                'publicacion'          => '2026-05-29 03:00:00',
            ],
            $utc
        );
    }

    // -------------------------------------------------------------------------
    // isWithinSolicitudWindow — exact boundary seconds
    // -------------------------------------------------------------------------

    public function test_is_within_window_one_second_before_apertura_is_false(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertFalse( PlazosCalculator::isWithinSolicitudWindow( '2026-05-23 23:59:59', $plazos ) );
    }

    public function test_is_within_window_exactly_at_apertura_is_true(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-24 00:00:00', $plazos ) );
    }

    public function test_is_within_window_one_second_after_apertura_is_true(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-24 00:00:01', $plazos ) );
    }

    public function test_is_within_window_one_second_before_cierre_solicitudes_is_true(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-28 23:59:58', $plazos ) );
    }

    public function test_is_within_window_exactly_at_cierre_solicitudes_is_true(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-28 23:59:59', $plazos ) );
    }

    public function test_is_within_window_one_second_after_cierre_solicitudes_is_false(): void {
        $plazos = PlazosCalculator::compute( '2026-05-30', $this->specOffsets(), self::BA );
        $this->assertFalse( PlazosCalculator::isWithinSolicitudWindow( '2026-05-29 00:00:00', $plazos ) );
    }

    /**
     * The window works identically in the UTC frame, as long as now and plazos
     * come from the same frame. Guards the pairing rule in the method docblock.
     */
    public function test_is_within_window_works_in_the_utc_frame(): void {
        $utc = PlazosCalculator::computeUtc( '2026-05-30', $this->specOffsets(), self::BA );

        $this->assertFalse( PlazosCalculator::isWithinSolicitudWindow( '2026-05-24 02:59:59', $utc ) );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-24 03:00:00', $utc ) );
        $this->assertTrue( PlazosCalculator::isWithinSolicitudWindow( '2026-05-29 02:59:59', $utc ) );
        $this->assertFalse( PlazosCalculator::isWithinSolicitudWindow( '2026-05-29 03:00:00', $utc ) );
    }
}
