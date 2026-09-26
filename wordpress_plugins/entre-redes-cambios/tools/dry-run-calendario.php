<?php

declare(strict_types=1);

/**
 * Dry-run CLI: fetches the season 359 fixture from the live REST API and runs
 * it through the real seed pipeline (LigaResolver + PartidosApiClient +
 * FechaRepository + SeedTemporadaService), entirely against the in-memory
 * SQLite shim used by the test suite — NEVER against a real WordPress
 * install or database.
 *
 * Run it with:
 *   php tools/dry-run-calendario.php
 *
 * Exit code: 0 when every automatic validation below passes, 1 otherwise.
 * A failed validation is meant to be reported as-is (a real discrepancy
 * between what the model expects and what the API returns), never silently
 * adjusted to make the script pass.
 */

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\LigaResolver;
use EntreRedes\Cambios\Calendario\PartidosApiClient;
use EntreRedes\Cambios\Calendario\SeedTemporadaService;
use EntreRedes\Cambios\Migrations\InitialSchema;

// ─── Bootstrap (mirrors tests/bootstrap.php, standalone path) ────────────────

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'ENTRE_REDES_CAMBIOS_VERSION' ) ) {
    define( 'ENTRE_REDES_CAMBIOS_VERSION', '0.1.0' );
}
if ( ! defined( 'ENTRE_REDES_CAMBIOS_FILE' ) ) {
    define( 'ENTRE_REDES_CAMBIOS_FILE', dirname( __DIR__ ) . '/entre-redes-cambios.php' );
}
if ( ! defined( 'ENTRE_REDES_CAMBIOS_DIR' ) ) {
    define( 'ENTRE_REDES_CAMBIOS_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/../../' );
}

require_once __DIR__ . '/../tests/wp-shim.php';

InitialSchema::up();

global $wpdb;

// ─── Config ───────────────────────────────────────────────────────────────────

const SEASON_ID = 359;
const DRY_RUN_NOW = '2026-09-26 12:00:00'; // injected clock; this script never reads the real one
const BASE_URL  = 'https://entreredespadres.com.ar/wp-json/entre-redes/v1';

// ─── Production-style HTTP fetcher: file_get_contents, since this script runs
// outside WordPress entirely (no wp_remote_get() available) ──────────────────

$httpGetFn = static function ( string $url ): array {
    $context = stream_context_create( [
        'http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: entre-redes-cambios-dry-run/1.0\r\nAccept: application/json\r\n",
            'timeout'       => 20,
            'ignore_errors' => true,
        ],
    ] );

    $body = @file_get_contents( $url, false, $context );

    if ( false === $body ) {
        fwrite( STDERR, "WARN: request failed for {$url}\n" );
        return [];
    }

    $decoded = json_decode( $body, true );

    return is_array( $decoded ) ? $decoded : [];
};

$apiClient = new PartidosApiClient( $httpGetFn, BASE_URL );

// ─── Fetch (once — reused for both seed() runs below, so the idempotency
// check compares the pipeline against IDENTICAL input, not a live API that
// could theoretically change between the two calls) ─────────────────────────

echo "Descargando fixture de la temporada " . SEASON_ID . " desde " . BASE_URL . " ...\n";

$ligasIndex        = $apiClient->fetchLigasIndex();
$ligaToTorneoLabel = LigaResolver::torneoMap( $ligasIndex );

$partidos    = $apiClient->fetchPartidos( SEASON_ID, $ligasIndex );
$programados = $apiClient->fetchProgramados( $ligasIndex );
$allPartidos = array_merge( $partidos, $programados );

$stats = $apiClient->stats();

echo "\n=== Resumen de la descarga ===\n";
printf( "  publish (jugados):      %d\n", $stats['publish'] );
printf( "  future (programados):   %d\n", $stats['future'] );
printf( "  salteados (publish):    %d\n", $stats['skipped_publish'] );
printf( "  salteados (programados):%d\n", $stats['skipped_programados'] );

if ( ! empty( $stats['skipped_ligas'] ) ) {
    echo "  detalle de ligas desconocidas:\n";
    foreach ( $stats['skipped_ligas'] as $ligaName => $count ) {
        printf( "    - %-40s %d partido(s)\n", $ligaName, $count );
    }
}

$fetcherFn = static fn(): array => $allPartidos;

$repository = new FechaRepository( $wpdb );
$service    = new SeedTemporadaService( $repository, $fetcherFn, $ligaToTorneoLabel );

// ─── First run ────────────────────────────────────────────────────────────────

$service->seed( SEASON_ID, DRY_RUN_NOW );

$fechas = $repository->listBySeason( SEASON_ID );

echo "\n=== Calendario (temporada " . SEASON_ID . ") ===\n";
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
    $count   = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha_partido WHERE fecha_id = %d",
            $fechaId
        )
    );
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

// ─── Automatic validations ────────────────────────────────────────────────────

echo "\n=== Validaciones automaticas ===\n";

$failures = 0;

/**
 * @param bool $condition
 */
$check = static function ( string $label, bool $condition ) use ( &$failures ): void {
    printf( "[%s] %s\n", $condition ? ' OK ' : 'FALLA', $label );
    if ( ! $condition ) {
        $failures++;
    }
};

$totalFechas = count( $fechas );
$check( "23 fechas en total (encontradas: {$totalFechas})", 23 === $totalFechas );

$allHave15 = true;
$offenders = [];
foreach ( $fechas as $fecha ) {
    $fechaId = (int) $fecha['id'];
    if ( 15 !== ( $partidosCountByFecha[ $fechaId ] ?? -1 ) ) {
        $allHave15   = false;
        $offenders[] = sprintf(
            'orden %d (%s): %d partidos',
            (int) $fecha['orden'],
            (string) $fecha['play_date'],
            $partidosCountByFecha[ $fechaId ] ?? -1
        );
    }
}
$check(
    'todas las fechas tienen exactamente 15 partidos' . ( $allHave15 ? '' : ' -- ' . implode( '; ', $offenders ) ),
    $allHave15
);

$ordenes         = array_map( static fn( array $f ): int => (int) $f['orden'], $fechas );
$expectedOrdenes = range( 1, $totalFechas );
sort( $ordenes );
$check(
    'orden continuo 1..' . $totalFechas . ' sin huecos ni repetidos',
    $ordenes === $expectedOrdenes
);

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
$expectedPhaseCounts = [ 'Clasificacion' => 5, 'Apertura' => 9, 'Clausura' => 9 ];
$check(
    'numero_en_torneo reinicia en cada cambio de torneo, fases 5/9/9 (encontrado: ' . json_encode( $phaseCounts ) . ')',
    $resetsOk && $expectedPhaseCounts === $phaseCounts
);

$primera = $fechas[0] ?? null;
$check(
    'la primera fecha es Clasificacion con orden 1',
    null !== $primera && 1 === (int) $primera['orden'] && 'Clasificacion' === (string) $primera['torneo_label']
);

// ─── estado: the checks that would have caught the wiring bug ────────────────
//
// The first version of this script validated shape only — counts, orden,
// numero_en_torneo — and reported "todas las validaciones pasaron" while every
// single fecha sat at the 'programada' default, because nothing ever called
// EstadoDeriver. Shape was perfect; meaning was absent. These three checks
// exist so that can never pass silently again.

$jugadas = array_values( array_filter(
    $fechas,
    static fn( array $f ): bool => 'jugada' === (string) $f['estado']
) );

$check(
    'al menos una fecha quedo en estado jugada (' . count( $jugadas ) . ' de ' . count( $fechas ) . ')',
    count( $jugadas ) > 0
);

// Every fecha whose partidos all carry a result must have derived to 'jugada'.
$derivacionOk = true;
foreach ( $fechas as $f ) {
    $total  = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha_partido WHERE fecha_id = %d",
        (int) $f['id']
    ) );
    $conRes = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}cambios_fecha_partido
          WHERE fecha_id = %d AND tiene_resultado = 1",
        (int) $f['id']
    ) );
    $esperado = ( $total > 0 && $total === $conRes ) ? 'jugada' : 'programada';
    if ( $esperado !== (string) $f['estado'] ) {
        $derivacionOk = false;
        echo "       ! fecha {$f['play_date']}: esperaba '{$esperado}', tiene '{$f['estado']}'"
            . " ({$conRes} de {$total} partidos con resultado)\n";
    }
}
$check( 'el estado de cada fecha coincide con sus partidos', $derivacionOk );

// End-to-end check of the rule the whole feature hangs on.
$resueltasDesdeLaPrimera = $repository->countFechasResueltasDesdeFecha( SEASON_ID, (int) $primera['id'] );
$check(
    'countFechasResueltasDesdeFecha() desde la fecha 1 cuenta las ' . count( $jugadas ) . ' resueltas'
        . ' (devolvio: ' . $resueltasDesdeLaPrimera . ')',
    $resueltasDesdeLaPrimera === count( $jugadas )
);

// ─── Second run: idempotency ──────────────────────────────────────────────────

echo "\n=== Segunda corrida (idempotencia) ===\n";

$idsBefore    = array_column( $fechas, 'id' );
sort( $idsBefore );
$ordenBefore  = [];
foreach ( $fechas as $fecha ) {
    $ordenBefore[ (int) $fecha['id'] ] = (int) $fecha['orden'];
}

$second = $service->seed( SEASON_ID, DRY_RUN_NOW );

$fechasAfter = $repository->listBySeason( SEASON_ID );
$idsAfter    = array_column( $fechasAfter, 'id' );
sort( $idsAfter );

$check(
    'no se crearon fechas nuevas en la segunda corrida (' . count( $fechasAfter ) . ' vs ' . count( $fechas ) . ')',
    count( $fechasAfter ) === count( $fechas )
);

$check(
    'ningun fecha_id cambio entre corridas',
    $idsBefore === $idsAfter
);

$allSinCambios = true;
foreach ( $second as $item ) {
    if ( 'sin_cambios' !== $item['status'] ) {
        $allSinCambios = false;
    }
}
$check(
    'la segunda corrida reporta status=sin_cambios para todas las fechas',
    $allSinCambios
);

$ordenUnchanged = true;
foreach ( $fechasAfter as $fecha ) {
    $fechaId = (int) $fecha['id'];
    if ( ( $ordenBefore[ $fechaId ] ?? null ) !== (int) $fecha['orden'] ) {
        $ordenUnchanged = false;
    }
}
$dirtyOnRecalc = $repository->recalcularOrden( SEASON_ID );
$check(
    "recalcularOrden() no movio nada la segunda vez (orden estable: " . ( $ordenUnchanged ? 'si' : 'no' ) . ", filas modificadas en una tercera pasada: {$dirtyOnRecalc})",
    $ordenUnchanged && 0 === $dirtyOnRecalc
);

// ─── Exit ──────────────────────────────────────────────────────────────────────

echo "\n";
if ( $failures > 0 ) {
    echo "RESULTADO: {$failures} validacion(es) fallaron.\n";
    exit( 1 );
}

echo "RESULTADO: todas las validaciones pasaron.\n";
exit( 0 );
