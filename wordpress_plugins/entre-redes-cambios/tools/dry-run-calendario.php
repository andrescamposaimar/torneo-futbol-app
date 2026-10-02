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

// CLI only. This file lives under the plugin directory, so if tools/ ever ships
// to a server that executes PHP anywhere in the plugin tree, it would be
// reachable over HTTP. Nothing here writes to a real database — it runs against
// the in-memory SQLite shim and reads an API that is already public — but a
// diagnostic script has no business answering web requests.
if ( 'cli' !== PHP_SAPI ) {
    http_response_code( 404 );
    exit( 1 );
}

use EntreRedes\Cambios\Calendario\Cli\CalendarioReport;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Observability\InMemoryEventLog;
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

// The expected shape of SEASON_ID's fixture, verified against the live API on
// 2026-09-26. Named here, together, so "what does correct look like" reads at
// a glance instead of being reconstructed from five separate literals spread
// across the validations below.
const EXPECTED_TOTAL_FECHAS       = 23;
const EXPECTED_PARTIDOS_POR_FECHA = 15;
const EXPECTED_PHASE_COUNTS       = [ 'Clasificacion' => 5, 'Apertura' => 9, 'Clausura' => 9 ];

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

    // Throw, never return []. PartidosApiClient treats a fetch that comes back
    // empty as a genuine empty page, so swallowing a failure here would make a
    // truncated download look like a complete one.
    if ( false === $body ) {
        throw new RuntimeException( "Request failed for {$url}" );
    }

    $status = 0;
    foreach ( $http_response_header ?? [] as $header ) {
        if ( 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $header, $m ) ) {
            $status = (int) $m[1];
        }
    }

    if ( 200 !== $status ) {
        throw new RuntimeException( "HTTP {$status} for {$url}" );
    }

    $decoded = json_decode( $body, true );

    if ( ! is_array( $decoded ) ) {
        throw new RuntimeException( "Body of {$url} is not JSON: " . substr( $body, 0, 120 ) );
    }

    return $decoded;
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

$repository = new FechaRepository( $wpdb, new InMemoryEventLog() );
$service    = new SeedTemporadaService( $repository, $fetcherFn, $ligaToTorneoLabel );

// ─── First run ────────────────────────────────────────────────────────────────

$service->seed( SEASON_ID, DRY_RUN_NOW );

$fechas = $repository->listBySeason( SEASON_ID );

printf( "Temporada: %d\n", SEASON_ID );
// Shared with tools/sembrar-calendario.php — see CalendarioReport's own
// class docblock for why the table print lives there and not here.
$partidosCountByFecha = CalendarioReport::printTable( $fechas, $wpdb );

// ─── Validaciones especificas de ESTE snapshot ───────────────────────────────
//
// These are regression pins against the EXACT fixture verified against the
// live API on 2026-09-26 — meaningless against a real, still-loading season
// (see CalendarioReport's class docblock for why they stay local to this
// script instead of living alongside the generic invariants).

echo "\n=== Validaciones especificas de esta temporada (snapshot verificado) ===\n";

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
$check(
    EXPECTED_TOTAL_FECHAS . " fechas en total (encontradas: {$totalFechas})",
    EXPECTED_TOTAL_FECHAS === $totalFechas
);

$allHaveExpectedCount = true;
$offenders            = [];
foreach ( $fechas as $fecha ) {
    $fechaId = (int) $fecha['id'];
    if ( EXPECTED_PARTIDOS_POR_FECHA !== ( $partidosCountByFecha[ $fechaId ] ?? -1 ) ) {
        $allHaveExpectedCount = false;
        $offenders[]          = sprintf(
            'orden %d (%s): %d partidos',
            (int) $fecha['orden'],
            (string) $fecha['play_date'],
            $partidosCountByFecha[ $fechaId ] ?? -1
        );
    }
}
$check(
    'todas las fechas tienen exactamente ' . EXPECTED_PARTIDOS_POR_FECHA . ' partidos'
        . ( $allHaveExpectedCount ? '' : ' -- ' . implode( '; ', $offenders ) ),
    $allHaveExpectedCount
);

$phaseCounts = [];
foreach ( $fechas as $fecha ) {
    $torneo                 = (string) $fecha['torneo_label'];
    $phaseCounts[ $torneo ] = ( $phaseCounts[ $torneo ] ?? 0 ) + 1;
}
$check(
    'fases ' . implode( '/', EXPECTED_PHASE_COUNTS ) . ' (encontrado: ' . json_encode( $phaseCounts ) . ')',
    EXPECTED_PHASE_COUNTS === $phaseCounts
);

// The first version of this script validated shape only — counts, orden,
// numero_en_torneo — and reported "todas las validaciones pasaron" while
// every single fecha sat at the 'programada' default, because nothing ever
// called EstadoDeriver. Shape was perfect; meaning was absent. This check
// (specific to the known-good snapshot, where 19 of 23 fechas are already
// played) exists so that can never pass silently again.
$jugadas = array_values( array_filter(
    $fechas,
    static fn( array $f ): bool => 'jugada' === (string) $f['estado']
) );
$check(
    'al menos una fecha quedo en estado jugada (' . count( $jugadas ) . ' de ' . count( $fechas ) . ')',
    count( $jugadas ) > 0
);

// ─── Validaciones genericas — validas en cualquier momento de la temporada ───
// Shared with tools/sembrar-calendario.php.

$failures += CalendarioReport::printValidations( $fechas, $partidosCountByFecha, $wpdb, $repository, SEASON_ID );

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
$dirtyOnRecalc = $repository->recalculateOrden( SEASON_ID );
$check(
    "recalculateOrden() no movio nada la segunda vez (orden estable: " . ( $ordenUnchanged ? 'si' : 'no' ) . ", filas modificadas en una tercera pasada: {$dirtyOnRecalc})",
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
