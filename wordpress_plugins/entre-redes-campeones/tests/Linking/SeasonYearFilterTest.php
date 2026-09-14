<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\SeasonYearFilter;
use PHPUnit\Framework\TestCase;

/**
 * sp_season term names are ALWAYS a bare 4-digit year (design §3) — the
 * compound sp_league names ('2022 - Apertura Zona A') belong to a
 * different taxonomy entirely and are never an sp_season fixture.
 *
 * @covers \EntreRedes\Campeones\Linking\SeasonYearFilter
 */
final class SeasonYearFilterTest extends TestCase {

    public function testBareYear2016CoversItself(): void {
        $this->assertTrue( SeasonYearFilter::seasonCoversYear( '2016', 2016 ) );
    }

    public function testBareYear2017CoversItself(): void {
        $this->assertTrue( SeasonYearFilter::seasonCoversYear( '2017', 2017 ) );
    }

    public function testBareYear2026CoversItself(): void {
        $this->assertTrue( SeasonYearFilter::seasonCoversYear( '2026', 2026 ) );
    }

    public function testNonMatchingYearIsFalse(): void {
        $this->assertFalse( SeasonYearFilter::seasonCoversYear( '2016', 2017 ) );
    }

    public function testDefensiveHypotheticalSuffixStillMatchesLeadingYear(): void {
        // Explicitly labeled defensive/hypothetical — every observed
        // sp_season value today is already a bare year; this guards
        // against a value that is not, without ever being confused with
        // the sp_league compound shape below.
        $this->assertTrue( SeasonYearFilter::seasonCoversYear( '2016 (provisional)', 2016 ) );
    }

    public function testSpLeagueCompoundShapeIsNotAnSpSeasonFixture(): void {
        // Never the sp_league compound shape — a substring-contains rule
        // would wrongly satisfy two different years from one term name.
        $this->assertFalse( SeasonYearFilter::seasonCoversYear( '2022 - Apertura Zona A', 2023 ) );
        $this->assertTrue( SeasonYearFilter::seasonCoversYear( '2022 - Apertura Zona A', 2022 ) );
    }
}
