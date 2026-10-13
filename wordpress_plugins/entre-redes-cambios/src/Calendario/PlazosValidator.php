<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Validates a complete set of the four plazo offsets BEFORE anything writes
 * them to `cambios_settings`. Pure: no database, no clock, no WordPress — so
 * every rule below is unit-testable with a plain array.
 *
 * *** WHY THIS EXISTS AT ALL (0.1.23) ***
 * `PlazosCalculator::isWithinSolicitudWindow()` is literally
 *
 *     $now >= $plazos['apertura_solicitudes'] && $now <= $plazos['cierre_solicitudes']
 *
 * and NOTHING anywhere checks that the first is earlier than the second. An
 * operator who swaps two numbers on the settings screen therefore produces a
 * window that is **never open**, with no exception, no event and no message:
 * every captain simply finds the request button disabled and nobody can tell
 * them why. That failure is silent, season-long and indistinguishable from
 * "the feature is broken".
 *
 * The settings screen is the ONLY writer of these rows, so this is the one
 * place the invariant can be defended. It refuses the whole save rather than
 * storing a half-valid set — a partially applied calendar is worse than the
 * old one, because the operator believes they changed it.
 *
 * *** THE ORDER, AND WHY IT IS A CHAIN AND NOT FOUR INDEPENDENT NUMBERS ***
 * Offsets are relative to the NEXT match's play date (see
 * `PlazosCalculator`'s own docblock for why the anchor is the match ahead and
 * not the one behind: a postponement then reflows every plazo by itself).
 * `days` is negative for "before the match", so a MORE negative day is
 * EARLIER, and within one day `time` breaks the tie. The real calendar is
 *
 *     apertura -6/00:00  ->  regresos -4/23:59  ->  cambios -2/23:59  ->  publicación -1/00:00
 *
 * which must stay weakly increasing in that order. `<=` rather than `<` on
 * purpose: collapsing two deadlines onto the same instant is a legitimate
 * operator choice (for example closing regresos and cambios together),
 * whereas INVERTING any adjacent pair is always a mistake.
 */
final class PlazosValidator {

    /**
     * The four keys, IN CHRONOLOGICAL ORDER — the same keys
     * `Settings::plazosOffsets()` returns and `PlazosCalculator::compute()`
     * expects. The order of this list IS the invariant.
     */
    public const CLAVES_EN_ORDEN = [
        'apertura_solicitudes',
        'cierre_regresos',
        'cierre_solicitudes',
        'publicacion',
    ];

    /** Spanish labels for messages the operator reads. */
    private const ETIQUETAS = [
        'apertura_solicitudes' => 'Apertura de solicitudes',
        'cierre_regresos'      => 'Cierre de regresos',
        'cierre_solicitudes'   => 'Cierre de cambios',
        'publicacion'          => 'Publicación',
    ];

    /**
     * How many days before the match an offset may sit. A fecha is roughly
     * weekly, so anything beyond two months is a typo, not a calendar; `0`
     * (match day itself) is allowed because closing something on match day
     * is a real, if unusual, choice.
     */
    private const DIAS_MINIMO = -90;
    private const DIAS_MAXIMO = 0;

    private function __construct() {
    }

    /**
     * @param array<string, array{days: mixed, time: mixed}> $offsets Keyed by
     *        CLAVES_EN_ORDEN. A missing key is itself an error — a partial
     *        set must never be written, since the rows left behind would
     *        pair the operator's new intent with the old calendar.
     * @return string[] Spanish error sentences, empty when the set is valid.
     *         Every problem found is reported, not just the first: an
     *         operator fixing one typo per round-trip is how a Thursday gets
     *         lost.
     */
    public static function validar( array $offsets ): array {
        $errores = [];

        foreach ( self::CLAVES_EN_ORDEN as $clave ) {
            if ( ! isset( $offsets[ $clave ] ) || ! is_array( $offsets[ $clave ] ) ) {
                $errores[] = sprintf( 'Falta el plazo "%s".', self::ETIQUETAS[ $clave ] );
                continue;
            }

            $errores = array_merge( $errores, self::validarUno( $clave, $offsets[ $clave ] ) );
        }

        // Ordering is only meaningful once every member is individually sane;
        // comparing a malformed pair would report a second, confusing error
        // about the same typo.
        if ( ! empty( $errores ) ) {
            return $errores;
        }

        return self::validarOrden( $offsets );
    }

    /**
     * @param array{days: mixed, time: mixed} $offset
     * @return string[]
     */
    private static function validarUno( string $clave, array $offset ): array {
        $errores   = [];
        $etiqueta  = self::ETIQUETAS[ $clave ];
        $days      = $offset['days'] ?? null;
        $time      = $offset['time'] ?? null;

        if ( ! is_int( $days ) ) {
            $errores[] = sprintf( '"%s": los días tienen que ser un número entero.', $etiqueta );
        } elseif ( $days < self::DIAS_MINIMO || $days > self::DIAS_MAXIMO ) {
            $errores[] = sprintf(
                '"%s": los días tienen que estar entre %d y %d (negativo = antes del partido).',
                $etiqueta,
                self::DIAS_MINIMO,
                self::DIAS_MAXIMO
            );
        }

        if ( ! is_string( $time ) || 1 !== preg_match( '/^([0-9]{2}):([0-9]{2}):([0-9]{2})$/', $time, $m ) ) {
            $errores[] = sprintf( '"%s": la hora tiene que tener el formato HH:MM:SS.', $etiqueta );

            return $errores;
        }

        // A well-FORMED string can still be an impossible time, and
        // DateTimeImmutable would happily roll "25:00:00" over into the next
        // day — silently moving the plazo a day later than the operator
        // typed.
        if ( (int) $m[1] > 23 || (int) $m[2] > 59 || (int) $m[3] > 59 ) {
            $errores[] = sprintf( '"%s": "%s" no es una hora válida.', $etiqueta, $time );
        }

        return $errores;
    }

    /**
     * @param array<string, array{days: int, time: string}> $offsets
     * @return string[]
     */
    private static function validarOrden( array $offsets ): array {
        $errores = [];
        $claves  = self::CLAVES_EN_ORDEN;

        for ( $i = 1; $i < count( $claves ); $i++ ) {
            $antes   = $claves[ $i - 1 ];
            $despues = $claves[ $i ];

            if ( self::compara( $offsets[ $antes ], $offsets[ $despues ] ) > 0 ) {
                $errores[] = sprintf(
                    '"%s" tiene que ser anterior o igual a "%s". Si se cruzan, la ventana de cambios nunca se abre.',
                    self::ETIQUETAS[ $antes ],
                    self::ETIQUETAS[ $despues ]
                );
            }
        }

        return $errores;
    }

    /**
     * Lexicographic on (days, time) — days first because a more negative day
     * is always earlier, whatever the hour.
     *
     * @param array{days: int, time: string} $a
     * @param array{days: int, time: string} $b
     * @return int <0, 0 or >0, the usual comparator contract.
     */
    private static function compara( array $a, array $b ): int {
        if ( $a['days'] !== $b['days'] ) {
            return $a['days'] <=> $b['days'];
        }

        return strcmp( $a['time'], $b['time'] );
    }
}
