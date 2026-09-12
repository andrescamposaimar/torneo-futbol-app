<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Tests\Linking;

use EntreRedes\Campeones\Linking\NameNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EntreRedes\Campeones\Linking\NameNormalizer
 */
final class NameNormalizerTest extends TestCase {

    /**
     * @dataProvider provideNames
     */
    public function testNormalize( string $raw, string $expected ): void {
        $this->assertSame( $expected, NameNormalizer::normalize( $raw ) );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideNames(): array {
        return [
            'accented U tilde'      => [ 'MUÑOZ', 'MUNOZ' ],
            'already plain'         => [ 'MUNOZ', 'MUNOZ' ],
            'accented E acute'      => [ 'PÉREZ', 'PEREZ' ],
            'already plain E'       => [ 'PEREZ', 'PEREZ' ],
            'cedilla'               => [ 'GONÇALVES', 'GONCALVES' ],
            'apostrophe stripped'   => [ "O'BRIEN", 'O BRIEN' ],
            'hyphen stripped'       => [ 'DI-STEFANO', 'DI STEFANO' ],
            'mixed case'            => [ 'Garcia, Miguel Luis', 'GARCIA MIGUEL LUIS' ],
            'whitespace collapse'   => [ 'Juan   Pablo', 'JUAN PABLO' ],
            'empty input'           => [ '', '' ],
            'whitespace only'       => [ '   ', '' ],
            'period stripped'       => [ 'Reynaldo A. Muscari', 'REYNALDO A MUSCARI' ],
            'acute-mark apostrophe' => [ 'PABLO D´ELIA', 'PABLO D ELIA' ],
            'comma stripped'        => [ 'Garcia,', 'GARCIA' ],
            'digits preserved'      => [ 'Team 7', 'TEAM 7' ],
        ];
    }
}
