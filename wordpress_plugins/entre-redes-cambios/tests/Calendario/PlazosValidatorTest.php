<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Calendario;

use EntreRedes\Cambios\Calendario\PlazosValidator;
use PHPUnit\Framework\TestCase;

/**
 * PlazosValidator is pure, so every rule is provable with a plain array —
 * no database, no clock, no WordPress.
 *
 * The rule that matters most is the ORDER one: nothing else in the codebase
 * checks it, and getting it wrong produces a window that never opens with no
 * error anywhere. See that class's own docblock.
 */
class PlazosValidatorTest extends TestCase {

    /** The real production calendar. */
    private const VALIDOS = [
        'apertura_solicitudes' => [ 'days' => -6, 'time' => '00:00:00' ],
        'cierre_regresos'      => [ 'days' => -4, 'time' => '23:59:59' ],
        'cierre_solicitudes'   => [ 'days' => -2, 'time' => '23:59:59' ],
        'publicacion'          => [ 'days' => -1, 'time' => '00:00:00' ],
    ];

    public function test_el_calendario_real_es_valido(): void {
        $this->assertSame( [], PlazosValidator::validar( self::VALIDOS ) );
    }

    public function test_dos_plazos_en_el_mismo_instante_son_validos(): void {
        // `<=`, not `<`: closing regresos and cambios together is a real
        // operator choice, unlike inverting them.
        $offsets                       = self::VALIDOS;
        $offsets['cierre_regresos']    = [ 'days' => -2, 'time' => '23:59:59' ];

        $this->assertSame( [], PlazosValidator::validar( $offsets ) );
    }

    // -------------------------------------------------------------------------
    // The order — the rule this class exists for
    // -------------------------------------------------------------------------

    public function test_la_apertura_despues_del_cierre_se_rechaza(): void {
        $offsets                           = self::VALIDOS;
        $offsets['apertura_solicitudes']   = [ 'days' => -1, 'time' => '00:00:00' ];

        $errores = PlazosValidator::validar( $offsets );

        $this->assertNotEmpty( $errores, 'An apertura after the cierre makes the window never open.' );
        $this->assertStringContainsString( 'nunca se abre', implode( ' ', $errores ) );
    }

    public function test_el_orden_se_compara_por_hora_cuando_el_dia_es_el_mismo(): void {
        // Same day, inverted hours: -2/23:59 cannot come before -2/00:00.
        $offsets                         = self::VALIDOS;
        $offsets['cierre_regresos']      = [ 'days' => -2, 'time' => '23:59:59' ];
        $offsets['cierre_solicitudes']   = [ 'days' => -2, 'time' => '00:00:00' ];

        $this->assertNotEmpty(
            PlazosValidator::validar( $offsets ),
            'With days equal, the time must still order the two plazos.'
        );
    }

    public function test_la_publicacion_antes_del_cierre_se_rechaza(): void {
        $offsets                 = self::VALIDOS;
        $offsets['publicacion']  = [ 'days' => -5, 'time' => '00:00:00' ];

        $this->assertNotEmpty( PlazosValidator::validar( $offsets ) );
    }

    public function test_un_dia_mas_negativo_es_mas_temprano(): void {
        // Guards the comparator's direction, which is the easiest thing to
        // invert by accident: days are NEGATIVE, so -6 is EARLIER than -1.
        $this->assertSame( [], PlazosValidator::validar( self::VALIDOS ) );

        $invertido = [
            'apertura_solicitudes' => [ 'days' => -1, 'time' => '00:00:00' ],
            'cierre_regresos'      => [ 'days' => -2, 'time' => '00:00:00' ],
            'cierre_solicitudes'   => [ 'days' => -4, 'time' => '00:00:00' ],
            'publicacion'          => [ 'days' => -6, 'time' => '00:00:00' ],
        ];

        $this->assertCount(
            3,
            PlazosValidator::validar( $invertido ),
            'A fully reversed calendar breaks all three adjacent pairs, and every one must be reported.'
        );
    }

    // -------------------------------------------------------------------------
    // Each value on its own
    // -------------------------------------------------------------------------

    public function test_una_clave_faltante_es_un_error(): void {
        $offsets = self::VALIDOS;
        unset( $offsets['publicacion'] );

        $errores = PlazosValidator::validar( $offsets );

        $this->assertNotEmpty( $errores );
        $this->assertStringContainsString( 'Publicación', implode( ' ', $errores ) );
    }

    public function test_los_dias_tienen_que_ser_entero(): void {
        $offsets                           = self::VALIDOS;
        $offsets['apertura_solicitudes']   = [ 'days' => 'seis', 'time' => '00:00:00' ];

        $this->assertNotEmpty( PlazosValidator::validar( $offsets ) );
    }

    public function test_los_dias_fuera_de_rango_se_rechazan(): void {
        foreach ( [ -91, 1 ] as $dias ) {
            $offsets                         = self::VALIDOS;
            $offsets['apertura_solicitudes'] = [ 'days' => $dias, 'time' => '00:00:00' ];

            $this->assertNotEmpty(
                PlazosValidator::validar( $offsets ),
                "days={$dias} is outside the allowed range and must be refused."
            );
        }
    }

    public function test_el_dia_del_partido_es_valido(): void {
        // 0 is the match day itself — unusual, not wrong.
        $offsets                 = self::VALIDOS;
        $offsets['publicacion']  = [ 'days' => 0, 'time' => '00:00:00' ];

        $this->assertSame( [], PlazosValidator::validar( $offsets ) );
    }

    public function test_una_hora_mal_formada_se_rechaza(): void {
        foreach ( [ '23:59', '2:3:4', 'mediodia', '', '23:59:59.5' ] as $hora ) {
            $offsets                         = self::VALIDOS;
            $offsets['apertura_solicitudes'] = [ 'days' => -6, 'time' => $hora ];

            $this->assertNotEmpty(
                PlazosValidator::validar( $offsets ),
                "time='{$hora}' is not HH:MM:SS and must be refused."
            );
        }
    }

    /**
     * A WELL-FORMED but impossible time must be refused too:
     * DateTimeImmutable would roll "25:00:00" into the next day and silently
     * move the plazo 24 hours later than the operator typed.
     */
    public function test_una_hora_bien_formada_pero_imposible_se_rechaza(): void {
        foreach ( [ '25:00:00', '12:60:00', '12:00:60' ] as $hora ) {
            $offsets                         = self::VALIDOS;
            $offsets['apertura_solicitudes'] = [ 'days' => -6, 'time' => $hora ];

            $errores = PlazosValidator::validar( $offsets );

            $this->assertNotEmpty( $errores, "time='{$hora}' parses as HH:MM:SS but is not a real time." );
            $this->assertStringContainsString( 'no es una hora válida', implode( ' ', $errores ) );
        }
    }

    public function test_informa_todos_los_problemas_no_solo_el_primero(): void {
        $offsets = [
            'apertura_solicitudes' => [ 'days' => 'x', 'time' => 'y' ],
            'cierre_regresos'      => [ 'days' => -4, 'time' => 'nope' ],
            'cierre_solicitudes'   => [ 'days' => -2, 'time' => '23:59:59' ],
            'publicacion'          => [ 'days' => -1, 'time' => '00:00:00' ],
        ];

        $this->assertGreaterThanOrEqual(
            3,
            count( PlazosValidator::validar( $offsets ) ),
            'Fixing one typo per round-trip is how a Thursday gets lost.'
        );
    }

    public function test_el_orden_no_se_evalua_si_algun_valor_es_invalido(): void {
        // A malformed pair cannot be compared, and reporting a second,
        // confusing "they are crossed" error about the same typo would send
        // the operator looking in the wrong place.
        $offsets = [
            'apertura_solicitudes' => [ 'days' => -1, 'time' => 'basura' ],
            'cierre_regresos'      => [ 'days' => -4, 'time' => '23:59:59' ],
            'cierre_solicitudes'   => [ 'days' => -2, 'time' => '23:59:59' ],
            'publicacion'          => [ 'days' => -1, 'time' => '00:00:00' ],
        ];

        $errores = PlazosValidator::validar( $offsets );

        $this->assertStringNotContainsString( 'nunca se abre', implode( ' ', $errores ) );
    }
}
