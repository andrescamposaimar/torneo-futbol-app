<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\LinkResolver;
use EntreRedes\Campeones\Linking\LinkState;
use PHPUnit\Framework\TestCase;

/**
 * NON-NEGOTIABLE #2 (design §3/§9, ADR-C1). Pins the documented misses
 * this conservative matcher accepts, so that reintroducing generated
 * surname variants — the mechanism four adversarial review rounds each
 * found a new defect in — turns this suite red instead of silently
 * "fixing" a case that was never a defect.
 *
 * Every id below is verified against wordpress_sql/entrered_wp257.sql via
 * tests/Fixtures/players.php. A pre-2016 year is used throughout so the
 * season filter (LINK-2/LINK-3) never participates — these misses are
 * about the surname key rule, not season narrowing.
 *
 * @covers \EntreRedes\Campeones\Linking\LinkResolver
 */
final class AcceptedMissesTest extends TestCase {

    private LinkResolver $resolver;

    protected function setUp(): void {
        $rows            = require __DIR__ . '/../Fixtures/players.php';
        $this->resolver   = new LinkResolver( FakePlayerDirectory::fromFixtureRows( $rows ) );
    }

    /**
     * Gabriel Garcia Conejero (4698) keys {CONEJERO, G} — his last token,
     * not his middle token, is what the parser indexes. "GARCIA, G." never
     * reaches him; it resolves auto to Garcia, Gaston (21517), who
     * genuinely keys {GARCIA, G}.
     */
    public function testGarciaGMissesConejeroResolvesToGastonInstead(): void {
        $resolution = $this->resolver->resolve( 'GARCIA, G.', 2012 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 21517, $resolution->playerId );
        $this->assertNotSame( 4698, $resolution->playerId );
    }

    /**
     * Andres Olalla De Labra (2253) keys {DE LABRA, A} — OLALLA is a
     * middle token, never indexed. "OLALLA, A." must stay sin_candidato.
     */
    public function testOlallaAMissesDeLabra(): void {
        $resolution = $this->resolver->resolve( 'OLALLA, A.', 2012 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    /**
     * De La Fuente, Javier (2492) — the comma branch takes the surname AS
     * WRITTEN, particles included: DE LA FUENTE. "FUENTE, J." must stay
     * sin_candidato.
     */
    public function testFuenteJMissesDeLaFuente(): void {
        $resolution = $this->resolver->resolve( 'FUENTE, J.', 2012 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    /**
     * Palou De Comasema, Adrian (14819) keys {PALOU DE COMASEMA, A} in
     * full. "COMASEMA, A." must stay sin_candidato.
     */
    public function testComasemaAMissesPalouDeComasema(): void {
        $resolution = $this->resolver->resolve( 'COMASEMA, A.', 2012 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    /**
     * The corrected SANTOS case (round-5/round-6). Verified against the
     * dump: the three published titles containing "santos" are
     * Juan Santos (4886, {SANTOS, J}), Andres Dos Santos (4814,
     * {DOS SANTOS, A} — NOT in the SANTOS bucket at all, DOS is absorbed)
     * and Santostefano, Pablo (2274, {SANTOSTEFANO, P} — a different
     * surname entirely). None of the three has {SANTOS, A}. Asserted over
     * the full key of every one of them, not a hand-picked key.
     */
    public function testSantosAMatchesNobody(): void {
        $resolution = $this->resolver->resolve( 'SANTOS, A.', 2012 );

        $this->assertSame( LinkState::SIN_CANDIDATO, $resolution->estado );
        $this->assertNull( $resolution->playerId );
        $this->assertSame( [], $resolution->candidates );
    }

    /**
     * Proves the absorption is what moved Andres Dos Santos out of the
     * SANTOS bucket, not an accident of bucketing: he resolves auto under
     * his own written form.
     */
    public function testDosSantosAResolvesAutoIndependently(): void {
        $resolution = $this->resolver->resolve( 'DOS SANTOS, A.', 2012 );

        $this->assertSame( LinkState::AUTO, $resolution->estado );
        $this->assertSame( 4814, $resolution->playerId );
    }
}
