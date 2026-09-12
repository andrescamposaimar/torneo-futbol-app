<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\NameParser;
use EntreRedes\Campeones\Linking\PlayerKey;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion below is on the EXACT single key NameParser::keyFor()
 * returns, never a superset — a superset assertion is how rounds 1-4 of
 * design review hid their defects (design §9, ADR-C1). Real-data cases are
 * verified against wordpress_sql/entrered_wp257.sql; synthetic cases are
 * explicitly labeled and are never drawn from the real fixture, since the
 * real directory structurally cannot produce them (a registered sp_player
 * title always carries a given name).
 *
 * @covers \EntreRedes\Campeones\Linking\NameParser
 */
final class NameParserTest extends TestCase {

    public function testGarciaMiguelLuisComma(): void {
        // id 2225, comma branch.
        $this->assertKey( 'GARCIA', 'M', NameParser::keyFor( 'Garcia, Miguel Luis' ) );
    }

    public function testDeLaFuenteComma(): void {
        // id 2492 — the comma branch takes the surname AS WRITTEN, particles
        // included: DE LA FUENTE, never FUENTE.
        $this->assertKey( 'DE LA FUENTE', 'J', NameParser::keyFor( 'De La Fuente, Javier' ) );
    }

    public function testPalouDeComasemaComma(): void {
        // id 14819 — same: the full comma-branch surname, never COMASEMA alone.
        $this->assertKey( 'PALOU DE COMASEMA', 'A', NameParser::keyFor( 'Palou De Comasema, Adrian' ) );
    }

    public function testSheetFormBassoComma(): void {
        // ADMIN-1 sheet form.
        $this->assertKey( 'BASSO', 'A', NameParser::keyFor( 'BASSO, A.' ) );
    }

    public function testJuanSantosNoComma(): void {
        // id 4886, two-token no-comma name.
        $this->assertKey( 'SANTOS', 'J', NameParser::keyFor( 'Juan Santos' ) );
    }

    public function testJuanPabloCalabroNoComma(): void {
        // id 2289 — must key CALABRO, never PABLO CALABRO nor PABLO (round-4 defect).
        $this->assertKey( 'CALABRO', 'J', NameParser::keyFor( 'Juan Pablo Calabro' ) );
    }

    public function testGabrielGarciaConejeroNoComma(): void {
        // id 4698 — must key CONEJERO, never GARCIA (the accepted miss, ADR-C1).
        $this->assertKey( 'CONEJERO', 'G', NameParser::keyFor( 'Gabriel Garcia Conejero' ) );
    }

    public function testAndresDosSantosParticleAbsorbed(): void {
        // id 4814 — particle DOS absorbed into the surname.
        $this->assertKey( 'DOS SANTOS', 'A', NameParser::keyFor( 'Andres Dos Santos' ) );
    }

    public function testAndresOlallaDeLabraParticleAbsorbed(): void {
        // id 2253 — must key DE LABRA; never OLALLA, never LABRA, never DE.
        $this->assertKey( 'DE LABRA', 'A', NameParser::keyFor( 'Andres Olalla De Labra' ) );
    }

    public function testPabloDArezzoSingleCharAbsorbed(): void {
        // id 4659 — must key D AREZZO, never D (round-4 collision defect).
        $this->assertKey( 'D AREZZO', 'P', NameParser::keyFor( 'Pablo D Arezzo' ) );
    }

    public function testPabloDEliaSingleCharAbsorbed(): void {
        // id 14877 — a different bucket from 4659, proving the fix removed
        // the shared one-character-surname collision.
        $this->assertKey( 'D ELIA', 'P', NameParser::keyFor( 'PABLO D´ELIA' ) );
    }

    public function testAlejandroDAbraccioSingleCharAbsorbed(): void {
        // id 15171.
        $this->assertKey( 'D ABRACCIO', 'A', NameParser::keyFor( 'ALEJANDRO D´ABRACCIO' ) );
    }

    public function testDAgostinoFiveTokensOneKey(): void {
        // id 14674 — five tokens after normalization, one key.
        $this->assertKey( 'D AGOSTINO', 'E', NameParser::keyFor( 'ESTEBAN NICOLAS RAMON D´AGOSTINO' ) );
    }

    public function testDAgostinoCommaFormAgrees(): void {
        // Sheet form — proves the comma branch and the no-comma branch agree
        // on the same person.
        $this->assertKey( 'D AGOSTINO', 'E', NameParser::keyFor( 'D´AGOSTINO, E.' ) );
    }

    public function testReynaldoAMuscariMiddleInitialPrepass(): void {
        // id 4753, round-6 — must key MUSCARI, never A MUSCARI (the
        // false "4 of 4 are apostrophe fragments" defect, R10). Pins the
        // stripMiddleInitialPeriods prepass against the one real
        // occurrence of a genuine middle initial in the directory.
        $this->assertKey( 'MUSCARI', 'R', NameParser::keyFor( 'Reynaldo A. Muscari' ) );
    }

    public function testSingleTokenIsTheOnlyLegitimateWildcard(): void {
        // Synthetic, labeled — the only legitimate wildcard.
        $this->assertKey( 'RONALDO', '', NameParser::keyFor( 'RONALDO' ) );
    }

    public function testAbsorptionGuardNeverConsumesFirstToken(): void {
        // Synthetic, labeled — the i > 1 absorption guard: never {J PEREZ, ''}.
        // Also proves the middle-initial prepass never touches the first token.
        $this->assertKey( 'PEREZ', 'J', NameParser::keyFor( 'J Perez' ) );
    }

    public function testSurnameShorterThanTwoCharsIsDropped(): void {
        // Synthetic, labeled.
        $this->assertNull( NameParser::keyFor( 'Juan D' ) );
    }

    public function testEmptyRightHandSideFallsThroughToSingleToken(): void {
        // Synthetic, labeled — an empty right-hand side after the comma
        // preserves the "empty initial only for a one-token name" invariant.
        $this->assertKey( 'GARCIA', '', NameParser::keyFor( 'Garcia,' ) );
    }

    public function testBareParticleIsNeverASurname(): void {
        // Synthetic, labeled.
        $this->assertNull( NameParser::keyFor( 'De' ) );
    }

    public function testParticleFirstTwoTokenNameIsRejected(): void {
        // Synthetic, labeled, round-6 I2(a) — not drawn from the real
        // fixture (a registered title always carries a given name).
        // Without this guard the i > 1 loop stops immediately for a
        // two-token name and emits {LABRA, D}, a valid-looking key that
        // could auto-link a trophy to an unrelated real person.
        $this->assertNull( NameParser::keyFor( 'De Labra' ) );
    }

    public function testSurnameBuiltEntirelyFromParticlesIsRejected(): void {
        // Synthetic, labeled, round-6 I2(b) — not drawn from the real
        // fixture, for the same reason. Without the widened validate()
        // check this would key as {DE LOS, J}.
        $this->assertNull( NameParser::keyFor( 'Juan De Los' ) );
    }

    private function assertKey( string $surname, string $initial, ?PlayerKey $actual ): void {
        $this->assertNotNull( $actual );
        $this->assertSame( $surname, $actual->surname );
        $this->assertSame( $initial, $actual->initial );
    }
}
