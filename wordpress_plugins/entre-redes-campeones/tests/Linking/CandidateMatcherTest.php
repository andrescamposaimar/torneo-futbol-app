<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\CandidateMatcher;
use EntreRedes\Campeones\Linking\PlayerKey;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EntreRedes\Campeones\Linking\CandidateMatcher
 */
final class CandidateMatcherTest extends TestCase {

    public function testExactSurnameAndInitialMatch(): void {
        $entry     = new PlayerKey( 'BASSO', 'A' );
        $candidate = new PlayerKey( 'BASSO', 'A' );

        $this->assertTrue( CandidateMatcher::matches( $entry, $candidate ) );
    }

    public function testInitialMismatchFails(): void {
        $entry     = new PlayerKey( 'SANTOS', 'A' );
        $candidate = new PlayerKey( 'SANTOS', 'J' );

        $this->assertFalse( CandidateMatcher::matches( $entry, $candidate ) );
    }

    public function testSurnameMismatchFails(): void {
        $entry     = new PlayerKey( 'GARCIA', 'M' );
        $candidate = new PlayerKey( 'CONEJERO', 'M' );

        $this->assertFalse( CandidateMatcher::matches( $entry, $candidate ) );
    }

    public function testWildcardOnEntrySideMatches(): void {
        // Reachable only from a one-token entry (e.g. "MAZZARA" with no initial).
        $entry     = new PlayerKey( 'MAZZARA', '' );
        $candidate = new PlayerKey( 'MAZZARA', 'M' );

        $this->assertTrue( CandidateMatcher::matches( $entry, $candidate ) );
    }

    public function testWildcardOnCandidateSideMatches(): void {
        // Reachable only from a one-token registered title.
        $entry     = new PlayerKey( 'RONALDO', 'C' );
        $candidate = new PlayerKey( 'RONALDO', '' );

        $this->assertTrue( CandidateMatcher::matches( $entry, $candidate ) );
    }
}
