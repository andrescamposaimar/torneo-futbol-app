<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario\Cli;

use EntreRedes\Cambios\Calendario\FechaRepository;

/**
 * Shared CLI presentation for the calendar: prints the derived fechas as a
 * table (orden, play_date, torneo_label, numero_en_torneo, partido count,
 * estado, veces_postergada) and runs the validations that hold true at ANY
 * point in the season — not tied to a specific fixture snapshot.
 *
 * Used by BOTH `tools/dry-run-calendario.php` (against the in-memory SQLite
 * shim, on a FROZEN historical fixture it knows the exact expected shape
 * of) and `tools/sembrar-calendario.php` (against the real `$wpdb`, on
 * whatever the real season looks like on the day an operator runs it). The
 * dry run layers its OWN extra assertions on top of what runs here (exact
 * fecha/partido counts, exact phase split) — those are regression pins
 * against a known-good snapshot, meaningless against a season still being
 * loaded week by week, so they stay local to that script rather than moving
 * here. What lives here are the invariants `Calendario\FechaRepository` and
 * `Calendario\EstadoDeriver` promise to hold REGARDLESS of how much of the
 * season has been loaded — an empty calendar (nothing loaded yet) is a
 * legitimate state here, never a failure.
 */
final class CalendarioReport {

    /**
     * Prints the table and returns each fecha's partido count (keyed by
     * `cambios_fecha.id`) — callers that also run validations need this same
     * count, so it is computed once here rather than twice.
     *
     * @param array<int, array<string, mixed>> $fechas As returned by
     *        FechaRepository::listBySeason().
     * @return array<int, int> fecha_id -> partido count.
     */
    public static function printTable( array $fechas, \wpdb $wpdb ): array {
        echo "\n=== Calendario ===\n";
        printf(
            "%-6s %-12s %-16s %-6s %-10s %-12s %-10s\n",
            'orden',
            'play_date',
            'torneo_label',
            'num',
            'partidos',
            'estado',
            'postergada'
        );

        $partidosCountByFecha = [];

        foreach ( $fechas as $fecha ) {
            $fechaId = (int) $fecha['id'];
            $count   = self::countPartidosDeFecha( $wpdb, $fechaId );
            $partidosCountByFecha[ $fechaId ] = $count;

            printf(
                "%-6d %-12s %-16s %-6d %-10d %-12s %-10d\n",
                (int) $fecha['orden'],
                (string) $fecha['play_date'],
                (string) $fecha['torneo_label'],
                (int) $fecha['numero_en_torneo'],
                $count,
                (string) $fecha['estado'],
                (int) $fecha['veces_postergada']
            );
        }

        if ( empty( $fechas ) ) {
            echo "  (sin fechas todavia)\n";
        }

        return $partidosCountByFecha;
    }

    /**
     * Validates the invariants that must hold at ANY point in the season —
     * see class docblock for exactly what is (and is not) checked here.
     * Prints `[ OK ]` / `[FALLA]` lines, mirroring the exact visual
     * convention `tools/dry-run-calendario.php` established first.
     *
     * @param array<int, array<string, mixed>> $fechas
     * @param array<int, int> $partidosCountByFecha fecha_id -> partido count,
     *        as returned by printTable().
     * @return int Number of failed validations.
     */
    public static function printValidations(
        array $fechas,
        array $partidosCountByFecha,
        \wpdb $wpdb,
        FechaRepository $repository,
        int $seasonId
    ): int {
        echo "\n=== Validaciones ===\n";

        $failures = 0;
        $check    = static function ( string $label, bool $condition ) use ( &$failures ): void {
            printf( "[%s] %s\n", $condition ? ' OK ' : 'FALLA', $label );
            if ( ! $condition ) {
                $failures++;
            }
        };

        $total = count( $fechas );

        if ( 0 === $total ) {
            echo "  (sin fechas para validar todavia — calendario vacio para esta temporada)\n";
            return 0;
        }

        // orden: continuous, no gaps, no repeats.
        $ordenes         = array_map( static fn( array $f ): int => (int) $f['orden'], $fechas );
        $expectedOrdenes = range( 1, $total );
        sort( $ordenes );
        $check( "orden continuo 1..{$total} sin huecos ni repetidos", $ordenes === $expectedOrdenes );

        // numero_en_torneo resets whenever torneo_label changes.
        $phaseCounts = [];
        $prevTorneo  = null;
        $resetsOk    = true;
        foreach ( $fechas as $fecha ) {
            $torneo = (string) $fecha['torneo_label'];
            $numero = (int) $fecha['numero_en_torneo'];

            $phaseCounts[ $torneo ] = ( $phaseCounts[ $torneo ] ?? 0 ) + 1;

            if ( $torneo !== $prevTorneo && 1 !== $numero ) {
                $resetsOk = false;
            }
            $prevTorneo = $torneo;
        }
        $check(
            'numero_en_torneo reinicia en cada cambio de torneo (fases encontradas: ' . json_encode( $phaseCounts ) . ')',
            $resetsOk
        );

        // The first fecha chronologically is always Clasificacion, orden 1.
        $primera = $fechas[0];
        $check(
            'la primera fecha es Clasificacion con orden 1',
            1 === (int) $primera['orden'] && 'Clasificacion' === (string) $primera['torneo_label']
        );

        // estado: the checks that would have caught the wiring bug — see
        // tools/dry-run-calendario.php's own docblock for the incident this
        // guards against (shape can be perfect while meaning is absent).
        $jugadas      = [];
        $derivacionOk = true;
        foreach ( $fechas as $f ) {
            $fechaId  = (int) $f['id'];
            $total_   = $partidosCountByFecha[ $fechaId ] ?? 0;
            $conRes   = self::countPartidosDeFecha( $wpdb, $fechaId, true );
            $esperado = ( $total_ > 0 && $total_ === $conRes ) ? 'jugada' : 'programada';

            if ( 'jugada' === (string) $f['estado'] ) {
                $jugadas[] = $f;
            }

            if ( 'derivado' === (string) ( $f['estado_origen'] ?? 'derivado' ) && $esperado !== (string) $f['estado'] ) {
                $derivacionOk = false;
                echo "       ! fecha {$f['play_date']}: esperaba '{$esperado}', tiene '{$f['estado']}'"
                    . " ({$conRes} de {$total_} partidos con resultado)\n";
            }
        }
        $check( 'el estado de cada fecha derivada coincide con sus partidos', $derivacionOk );

        // The end-to-end check the whole feature hangs on.
        $resueltasDesdeLaPrimera = $repository->countResolvedFechasSince( $seasonId, (int) $primera['id'] );
        $check(
            'countResolvedFechasSince() desde la primera fecha cuenta las ' . count( $jugadas ) . ' resueltas'
                . ' (devolvio: ' . $resueltasDesdeLaPrimera . ')',
            $resueltasDesdeLaPrimera === count( $jugadas )
        );

        return $failures;
    }

    /**
     * COUNT(*) of a fecha's partido rows, optionally restricted to ones with
     * a loaded result.
     */
    private static function countPartidosDeFecha( \wpdb $wpdb, int $fechaId, bool $soloConResultado = false ): int {
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha_partido WHERE fecha_id = %d";
        if ( $soloConResultado ) {
            $sql .= ' AND tiene_resultado = 1';
        }

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $fechaId ) );
    }
}
