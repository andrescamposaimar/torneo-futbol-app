<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Dictamen;

use EntreRedes\Cambios\Dictamen\Dictamen;
use EntreRedes\Cambios\Dictamen\Motivo;
use PHPUnit\Framework\TestCase;

class DictamenTest extends TestCase {

    public function test_procede_is_true_with_no_motivos(): void {
        $dictamen = Dictamen::desde( [] );

        $this->assertTrue( $dictamen->procede() );
        $this->assertSame( [], $dictamen->motivos() );
    }

    public function test_procede_is_false_with_any_motivo(): void {
        $motivo   = new Motivo( 'algun_codigo', 'Algún mensaje.' );
        $dictamen = Dictamen::desde( [ $motivo ] );

        $this->assertFalse( $dictamen->procede() );
        $this->assertSame( [ $motivo ], $dictamen->motivos() );
    }

    public function test_motivo_finds_by_codigo(): void {
        $a        = new Motivo( 'codigo_a', 'Mensaje A.' );
        $b        = new Motivo( 'codigo_b', 'Mensaje B.' );
        $dictamen = Dictamen::desde( [ $a, $b ] );

        $this->assertSame( $b, $dictamen->motivo( 'codigo_b' ) );
        $this->assertNull( $dictamen->motivo( 'codigo_inexistente' ) );
    }

    public function test_fechas_faltantes_para_liberacion_reads_the_generic_datos_payload(): void {
        $motivo   = new Motivo( 'regreso_antes_del_minimo', 'Faltan 2 fechas.', [ 'fechasFaltantes' => 2 ] );
        $dictamen = Dictamen::desde( [ $motivo ] );

        $this->assertSame( 2, $dictamen->fechasFaltantesParaLiberacion() );
    }

    public function test_fechas_faltantes_para_liberacion_is_null_when_no_motivo_reports_it(): void {
        $motivo   = new Motivo( 'otro_motivo', 'Otro problema.' );
        $dictamen = Dictamen::desde( [ $motivo ] );

        $this->assertNull( $dictamen->fechasFaltantesParaLiberacion() );
    }

    public function test_fechas_faltantes_para_liberacion_is_null_when_reported_as_indeterminate(): void {
        // See Reglas\RegresoSoloConMinimoCumplido's docblock: an
        // indeterminate count is reported as `null`, never guessed at.
        $motivo   = new Motivo( 'regreso_antes_del_minimo', 'Dato no disponible.', [ 'fechasFaltantes' => null ] );
        $dictamen = Dictamen::desde( [ $motivo ] );

        $this->assertNull( $dictamen->fechasFaltantesParaLiberacion() );
    }

    public function test_favorable_dictamen_has_no_motivos_at_all(): void {
        $dictamen = Dictamen::desde( [] );

        $this->assertTrue( $dictamen->procede() );
        $this->assertNull( $dictamen->fechasFaltantesParaLiberacion() );
        $this->assertNull( $dictamen->motivo( 'cualquier_codigo' ) );
    }
}
