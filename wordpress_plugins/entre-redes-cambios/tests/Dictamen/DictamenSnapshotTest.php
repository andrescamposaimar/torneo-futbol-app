<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\DictamenSnapshot;
use EntreRedes\Cambios\Dictamen\Motivo;
use PHPUnit\Framework\TestCase;

class DictamenSnapshotTest extends TestCase {

    public function test_from_dictamen_favorable_round_trips_through_json(): void {
        $dictamen = Dictamen::from( [] );
        $snapshot = DictamenSnapshot::fromDictamen( $dictamen, '2026-05-27 10:00:00' );

        $this->assertTrue( $snapshot->procede() );
        $this->assertSame( [], $snapshot->motivos() );
        $this->assertSame( [], $snapshot->motivoCodigos() );
        $this->assertSame( '2026-05-27 10:00:00', $snapshot->evaluadoAt() );

        $restored = DictamenSnapshot::fromJson( $snapshot->toJson() );

        $this->assertTrue( $restored->procede() );
        $this->assertSame( [], $restored->motivoCodigos() );
        $this->assertSame( '2026-05-27 10:00:00', $restored->evaluadoAt() );
    }

    public function test_from_dictamen_with_motivos_round_trips_through_json(): void {
        $dictamen = Dictamen::from( [
            new Motivo( 'fuera_de_plazo', 'La solicitud está fuera de plazo.', [ 'foo' => 'bar' ] ),
            new Motivo( 'entrante_ocupa_otra_plaza_vigente', 'El entrante ya ocupa otra plaza.' ),
        ] );

        $snapshot = DictamenSnapshot::fromDictamen( $dictamen, '2026-05-27 10:00:00' );

        $this->assertFalse( $snapshot->procede() );
        $this->assertSame( [ 'fuera_de_plazo', 'entrante_ocupa_otra_plaza_vigente' ], $snapshot->motivoCodigos() );

        $restored = DictamenSnapshot::fromJson( $snapshot->toJson() );

        $this->assertFalse( $restored->procede() );
        $this->assertSame( [ 'fuera_de_plazo', 'entrante_ocupa_otra_plaza_vigente' ], $restored->motivoCodigos() );
        $this->assertSame( [ 'foo' => 'bar' ], $restored->motivos()[0]['datos'] );
    }

    public function test_from_json_throws_on_malformed_input(): void {
        $this->expectException( \RuntimeException::class );

        DictamenSnapshot::fromJson( '{"procede":true}' );
    }

    public function test_from_json_throws_on_non_json(): void {
        $this->expectException( \RuntimeException::class );

        DictamenSnapshot::fromJson( 'not json at all' );
    }
}
