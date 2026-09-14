<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\NameParser;
use EntreRedes\Campeones\Linking\PlayerKey;
use PHPUnit\Framework\TestCase;

/**
 * The mandatory directory-wide guard (ADR-C1, design §9, NON-NEGOTIABLE #1).
 *
 * Runs NameParser::keyFor() over every published sp_player title in the
 * real directory (tests/Fixtures/player_titles.php, 1105 rows, regenerated
 * by tools/extract-player-titles.php). Every defect in rounds 1-4 of design
 * review was found by a judge writing this exact simulation by hand,
 * expensively, and throwing the code away — this test makes it a
 * permanent, cheap, committed guard that runs on every `composer test`.
 *
 * This test MUST run against the real fixture, never a hand-picked subset.
 *
 * @covers \EntreRedes\Campeones\Linking\NameParser
 */
final class NameParserPropertyTest extends TestCase {

    /**
     * @var array<int, array{id: int, title: string}>
     */
    private static array $players;

    /**
     * @var array<int, PlayerKey|null>
     */
    private static array $keysById;

    public static function setUpBeforeClass(): void {
        self::$players = require __DIR__ . '/../Fixtures/player_titles.php';

        self::$keysById = [];
        foreach ( self::$players as $player ) {
            self::$keysById[ $player['id'] ] = NameParser::keyFor( $player['title'] );
        }
    }

    public function testCorpusIsNotEmpty(): void {
        // A guard over zero rows would trivially pass every assertion below
        // and prove nothing — pin the fixture is actually loaded.
        $this->assertSame( 1105, count( self::$players ) );
    }

    public function testNoTitleYieldsANullKey(): void {
        $offenders = [];
        foreach ( self::$players as $player ) {
            if ( null === self::$keysById[ $player['id'] ] ) {
                $offenders[] = $player['id'] . ': ' . $player['title'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "The following titles produced a null key (must be zero):\n" . implode( "\n", $offenders )
        );
    }

    public function testNoKeyHasAnEmptySurname(): void {
        $offenders = [];
        foreach ( self::$players as $player ) {
            $key = self::$keysById[ $player['id'] ];
            if ( null !== $key && '' === $key->surname ) {
                $offenders[] = $player['id'] . ': ' . $player['title'];
            }
        }

        $this->assertSame( [], $offenders, "Titles with an empty surname:\n" . implode( "\n", $offenders ) );
    }

    public function testNoSurnameShorterThanTwoCharacters(): void {
        $offenders = [];
        foreach ( self::$players as $player ) {
            $key = self::$keysById[ $player['id'] ];
            if ( null !== $key && mb_strlen( $key->surname, 'UTF-8' ) < 2 ) {
                $offenders[] = $player['id'] . ': ' . $player['title'] . ' -> ' . $key->surname;
            }
        }

        $this->assertSame( [], $offenders, "Titles with a surname shorter than 2 chars:\n" . implode( "\n", $offenders ) );
    }

    public function testNoSurnameIsComposedEntirelyOfParticleTokens(): void {
        // Consumes NameParser::particles() directly rather than hand-copying
        // the list — this test is the mandatory guard against the bug class
        // that consumed four review rounds, and it must not validate against
        // a stale copy of the rule it is supposed to be checking.
        $particles = NameParser::particles();
        $offenders = [];

        foreach ( self::$players as $player ) {
            $key = self::$keysById[ $player['id'] ];
            if ( null === $key ) {
                continue;
            }

            $allParticles = true;
            foreach ( explode( ' ', $key->surname ) as $token ) {
                if ( ! in_array( $token, $particles, true ) ) {
                    $allParticles = false;
                    break;
                }
            }

            if ( $allParticles ) {
                $offenders[] = $player['id'] . ': ' . $player['title'] . ' -> ' . $key->surname;
            }
        }

        $this->assertSame( [], $offenders, "Titles whose surname is all particle tokens:\n" . implode( "\n", $offenders ) );
    }

    public function testNoMultiTokenNameYieldsAnEmptyInitial(): void {
        $offenders = [];
        foreach ( self::$players as $player ) {
            $key = self::$keysById[ $player['id'] ];
            if ( null === $key || '' !== $key->initial ) {
                continue;
            }

            // An empty initial is only legitimate for a genuine single-token
            // title. Detect a multi-token title the same way NameParser
            // itself tokenizes, using the normalized form of the title.
            $normalized = \EntreRedes\Campeones\Linking\NameNormalizer::normalize(
                str_contains( $player['title'], ',' )
                    ? substr( $player['title'], 0, strpos( $player['title'], ',' ) )
                    : $player['title']
            );
            $tokenCount = '' === $normalized ? 0 : count( explode( ' ', $normalized ) );

            if ( $tokenCount > 1 ) {
                $offenders[] = $player['id'] . ': ' . $player['title'];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Multi-token titles yielding an empty initial (must be zero):\n" . implode( "\n", $offenders )
        );
    }

    public function testIdempotence(): void {
        foreach ( self::$players as $player ) {
            $first  = self::$keysById[ $player['id'] ];
            $second = NameParser::keyFor( $player['title'] );

            if ( null === $first ) {
                $this->assertNull( $second, "Non-idempotent (first null, second not) for id {$player['id']}" );
                continue;
            }

            $this->assertNotNull( $second, "Non-idempotent (first non-null, second null) for id {$player['id']}" );
            $this->assertSame( $first->surname, $second->surname, "Non-idempotent surname for id {$player['id']}" );
            $this->assertSame( $first->initial, $second->initial, "Non-idempotent initial for id {$player['id']}" );
        }
    }

    /**
     * Informational report, written to test output rather than asserted —
     * it is a measurement, not a contract (design §9/§11).
     */
    public function testReportBucketDistributionAndCollisions(): void {
        $buckets = [];
        $commaLessCount = 0;
        $singleLetterPeriodTitles = [];

        foreach ( self::$players as $player ) {
            $key = self::$keysById[ $player['id'] ];
            if ( null === $key ) {
                continue;
            }
            $bucketKey             = $key->surname . '|' . $key->initial;
            $buckets[ $bucketKey ][] = $player['id'];

            if ( ! str_contains( $player['title'], ',' ) ) {
                $commaLessCount++;
            }

            foreach ( preg_split( '/\s+/', trim( $player['title'] ) ) ?: [] as $token ) {
                if ( 1 === preg_match( '/^[A-Za-z]\.$/', $token ) ) {
                    $singleLetterPeriodTitles[] = $player['id'] . ': ' . $player['title'];
                }
            }
        }

        $collisions = array_filter( $buckets, static fn ( array $ids ): bool => count( $ids ) >= 2 );
        uasort( $collisions, static fn ( array $a, array $b ): int => count( $b ) <=> count( $a ) );

        $report = sprintf(
            "Surname+initial buckets: %d\nCollision buckets (2+): %d\nComma-less titles: %d\nSingle-letter-period tokens: %d\n",
            count( $buckets ),
            count( $collisions ),
            $commaLessCount,
            count( $singleLetterPeriodTitles )
        );

        fwrite( STDERR, "\n" . $report );

        $this->assertTrue( true );
    }
}
