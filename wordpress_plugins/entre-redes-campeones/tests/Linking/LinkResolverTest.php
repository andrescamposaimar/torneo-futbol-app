<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use PHPUnit\Framework\TestCase;

/**
 * Fake directory, real fixtures (design §9). Every id below is verified
 * against wordpress_sql/entrered_wp257.sql via tests/Fixtures/players.php.
 *
 * @covers \EntreRedes\Campeones\Linking\LinkResolver
 */
final class LinkResolverTest extends TestCase {

    private LinkResolver $resolver;

    protected function setUp(): void {
        $rows           = require __DIR__ . '/../Fixtures/players.php';
        $directory      = FakePlayerDirectory::fromFixtureRows( $rows );
        $this->resolver = new LinkResolver( $directory );
    }

    public function testSingleCandidateAutoLinks(): void {
        // Basso, Alejandro (5078), 2016.
        $resolution = $this->resolver->resolve( 'BASSO, A.', 2016 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 5078, $resolution->playerId );
    }

    public function testGarciaMBecomesAmbiguousBothRetained(): void {
        // The spec's flagship homonym scenario: 2225 and 2461, both {GARCIA, M}.
        $resolution = $this->resolver->resolve( 'GARCIA, M.', 2016 );

        $this->assertSame( LinkState::AMBIGUO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame(
            [ 2225, 2461 ],
            array_map( static fn ( $c ) => $c->id, $resolution->candidates )
        );
    }

    public function testGarciaABecomesAmbiguousSecondVerifiedPair(): void {
        // 2494 and 10422, both {GARCIA, A} — a second verified same-initial pair.
        $resolution = $this->resolver->resolve( 'GARCIA, A.', 2017 );

        $this->assertSame( LinkState::AMBIGUO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame(
            [ 2494, 10422 ],
            array_map( static fn ( $c ) => $c->id, $resolution->candidates )
        );
    }

    public function testPardoGBecomesAmbiguousAllThreeRetained(): void {
        // The largest measured collision in the directory: 4770, 13677,
        // 22934, all {PARDO, G}. Pre-2016 so no season narrowing applies —
        // the first assertion that candidatos_json correctly retains 3+
        // candidates, not only ever exercised at exactly two.
        $resolution = $this->resolver->resolve( 'PARDO, G.', 2012 );

        $this->assertSame( LinkState::AMBIGUO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame(
            [ 4770, 13677, 22934 ],
            array_map( static fn ( $c ) => $c->id, $resolution->candidates )
        );
    }

    public function testGarciaGAutoLinksToGaston(): void {
        // 4698 is {CONEJERO, G} and is not a candidate for this bucket.
        $resolution = $this->resolver->resolve( 'GARCIA, G.', 2016 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 21517, $resolution->playerId );
    }

    public function testCalelloGAndCalelloJDifferentInitialsNeverCollide(): void {
        $resolutionG = $this->resolver->resolve( 'CALELLO, G.', 2018 );
        $resolutionJ = $this->resolver->resolve( 'CALELLO, J.', 2018 );

        $this->assertSame( LinkState::AUTO, $resolutionG->estado );
        $this->assertSame( 2323, $resolutionG->playerId );

        $this->assertSame( LinkState::AUTO, $resolutionJ->estado );
        $this->assertSame( 11599, $resolutionJ->playerId );
    }

    public function testConejeroGAutoLinks(): void {
        $resolution = $this->resolver->resolve( 'CONEJERO, G.', 2016 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4698, $resolution->playerId );
    }

    public function testDosSantosAAutoLinks(): void {
        $resolution = $this->resolver->resolve( 'DOS SANTOS, A.', 2018 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4814, $resolution->playerId );
    }

    public function testDAgostinoAutoLinks(): void {
        $resolution = $this->resolver->resolve( 'D´AGOSTINO, E.', 2018 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 14674, $resolution->playerId );
    }

    public function testMuscariRAutoLinks(): void {
        // Proves the middle-initial prepass fix is reachable end-to-end
        // from a sheet entry, not just from NameParserTest.
        $resolution = $this->resolver->resolve( 'MUSCARI, R.', 2018 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4753, $resolution->playerId );
    }

    public function testMazzaraMAutoLinksNowThatTheRealCandidateIsInTheFixture(): void {
        // Corrected: `MAZZARA, M.` was previously asserted `sin_candidato`
        // against a fixture that deliberately excluded the one real player
        // who matches. Id 4739 (Mazzara, Mauro) IS a real, published
        // `sp_player` row keying {MAZZARA, M} (verified against
        // wordpress_sql/entrered_wp257.sql — see tests/Fixtures/players.php).
        // 2011 is pre-2016, so LINK-3 skips season narrowing entirely; the
        // candidate set is exactly one and the real outcome is `auto`.
        $resolution = $this->resolver->resolve( 'MAZZARA, M.', 2011 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4739, $resolution->playerId );
    }

    public function testNoCandidateStaysUnlinkedAndDisplayable(): void {
        // `ZUBIZARRETA, F.` genuinely has zero candidates in the real
        // directory — verified two ways: (1) running NameParser::keyFor()
        // over all 1105 real, published sp_player titles in
        // tests/Fixtures/player_titles.php produces no {ZUBIZARRETA, F}
        // (and no {ZUBIZARRETA, *}) key at all; (2) the raw string
        // "zubizarreta" (case-insensitive) does not appear anywhere in
        // wordpress_sql/entrered_wp257.sql. Unlike the previous MAZZARA
        // example, this is a genuine zero-candidate name, not an artifact
        // of a curated fixture that happens to omit someone.
        $resolution = $this->resolver->resolve( 'ZUBIZARRETA, F.', 2011 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    public function testSeasonFilterReducesThreeCandidatesToOneAuto(): void {
        // PARDO bucket, but at a >= 2016 year where only 4770 covers 2018.
        $resolution = $this->resolver->resolve( 'PARDO, G.', 2018 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4770, $resolution->playerId );
    }

    public function testSeasonFilterReducesCandidatesToZeroSinCandidato(): void {
        // None of the three PARDO candidates' seasons cover 2025 — must
        // become sin_candidato, never fall back to the unfiltered set.
        $resolution = $this->resolver->resolve( 'PARDO, G.', 2025 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    public function testYear2012SeasonFilterNotApplied(): void {
        // GARCIA, M. bucket: 2225 season 2016, 2461 season 2016 — neither
        // covers 2012, but LINK-3 says pre-2016 skips narrowing entirely,
        // so both remain and the entry is still ambiguo.
        $resolution = $this->resolver->resolve( 'GARCIA, M.', 2012 );

        $this->assertSame( LinkState::AMBIGUO, $resolution->estado );
        $this->assertSame(
            [ 2225, 2461 ],
            array_map( static fn ( $c ) => $c->id, $resolution->candidates )
        );
    }

    public function testOneTokenEntryAgainstTwoSameSurnamePlayersIsAmbiguous(): void {
        // A one-token sheet entry ("Calello") widens toward a human, never
        // toward a guess, even though 2323 and 11599 have different initials.
        $resolution = $this->resolver->resolve( 'Calello', 2018 );

        $this->assertSame( LinkState::AMBIGUO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame(
            [ 2323, 11599 ],
            array_map( static fn ( $c ) => $c->id, $resolution->candidates )
        );
    }

    public function testDegenerateNameYieldsSinCandidato(): void {
        // NameParser::keyFor() returns null for this input — resolution
        // must stop immediately, never throw.
        $resolution = $this->resolver->resolve( 'De', 2020 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }
}
