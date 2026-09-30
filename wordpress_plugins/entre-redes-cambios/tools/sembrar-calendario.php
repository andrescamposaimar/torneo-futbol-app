<?php

declare(strict_types=1);

/**
 * CLI: runs the REAL seed pipeline (`Calendario\Cron\SeedCalendarioCron`,
 * the same production caller the daily WP-Cron event uses) against the REAL
 * `$wpdb` of a real WordPress install.
 *
 * *** THIS SCRIPT WRITES TO PRODUCTION. tools/dry-run-calendario.php DOES NOT. ***
 * `tools/dry-run-calendario.php` runs entirely against an in-memory SQLite
 * shim and can NEVER touch real data — see its own header. THIS script is
 * the opposite: with `--apply`, it fetches the live `/entre-redes/v1/*` API
 * and writes real rows into `cambios_fecha` / `cambios_fecha_partido` on
 * whatever WordPress install it is run against. Keep both scripts; they
 * answer different questions — one proves the pipeline is correct against a
 * known fixture, the other operates the real calendar.
 *
 * DRY-RUN IS THE DEFAULT. Without `--apply`, this script fetches NOTHING and
 * writes NOTHING: it only reads the calendar AS ALREADY PERSISTED (whatever
 * the cron, or a previous --apply, already wrote) and reports it — the same
 * table and the same generic validations `tools/dry-run-calendario.php`
 * uses (via `Calendario\Cli\CalendarioReport`), so a human reads one
 * consistent shape from either script. It deliberately does NOT preview a
 * hypothetical fresh fetch: `Calendario\SeedTemporadaService::seed()` has no
 * plan-only mode (unlike `Plazas\Alta\TitularesListImporter`), and wrapping
 * it in a transaction to roll back afterwards is NOT a safe substitute —
 * `FechaRepository::recalculateOrden()` opens and commits its OWN inner
 * transaction, so an outer BEGIN/ROLLBACK around the whole seed would either
 * be refused outright (the SQLite test shim) or, worse, silently commit
 * everything early on real MySQL (a nested START TRANSACTION implicitly
 * commits the outer one — see `Support\OpensTransactions`' own docblock).
 * That would make "dry run" secretly write, which is a strictly worse bug
 * than this script simply not previewing new fechas. `--apply` is therefore
 * the only mode that ever calls the fetcher.
 *
 * Usage:
 *   php tools/sembrar-calendario.php [--season-id=<id>] [--apply]
 *   php tools/sembrar-calendario.php --help
 *
 * Exit code: 0 when the report (dry-run) or the apply both succeed and every
 * validation passes; 1 when the seed itself failed/was skipped (lock held)
 * or any validation on the resulting calendar failed.
 */

const USAGE = <<<'TXT'
Siembra manual del calendario de fechas — corre el mismo pipeline que el
cron diario (Calendario\Cron\SeedCalendarioCron), pero AHORA MISMO, contra
la base de datos real. A diferencia de tools/dry-run-calendario.php (que
corre contra un shim en memoria y NUNCA toca datos reales), este script SI
escribe cuando se lo pide con --apply.

USO:
  php tools/sembrar-calendario.php [--season-id=<id>] [--apply]
  php tools/sembrar-calendario.php --help

ARGUMENTOS:
  --season-id=<id>   Opcional. Temporada a sembrar/reportar. Por defecto, la
                      configurada en cambios_settings (Calendario\Settings::
                      seasonId(), 359 si no fue configurada).
  --apply            Opcional. Sin esta bandera (comportamiento por
                      defecto), el comando NO se conecta a la API ni escribe
                      nada — solo lee y reporta el calendario TAL COMO ESTA
                      persistido hoy (lo que el cron, o un --apply anterior,
                      ya hayan sembrado), validando que sea consistente. Con
                      --apply, descarga el fixture real y siembra/actualiza
                      el calendario contra la base real.
  --help, -h          Muestra esta ayuda y termina (no requiere WordPress ni
                      una base de datos).

COMPORTAMIENTO:
  - El lock de superposicion (cambios_seed_calendario_lock) es el MISMO que
    usa el cron — si el cron esta corriendo en este instante, --apply se
    saltea en vez de competir con el, y lo dice explicitamente.
  - Una falla de red o una respuesta malformada de la API deja la tabla
    exactamente como estaba: nada se escribe hasta que la descarga completa
    (ligas + partidos + programados) termino sin errores.
  - El reporte (tabla + validaciones) es el MISMO en dry-run y despues de
    --apply — la diferencia es si --apply corrio el pipeline antes de
    imprimirlo.

TXT;

// CLI only — never answers an HTTP request. This file lives under the
// plugin's own tools/ directory, so if it were ever reachable over HTTP it
// would be a live, unauthenticated write path into cambios_fecha. See
// tools/dry-run-calendario.php / tools/importar-titulares.php for the same
// guard.
if ( 'cli' !== PHP_SAPI ) {
    http_response_code( 404 );
    exit( 1 );
}

/**
 * @param array<int, string> $argv
 * @return array<string, string|bool>
 */
function parseCliArgs( array $argv ): array {
    $parsed = [];

    foreach ( array_slice( $argv, 1 ) as $arg ) {
        if ( '--help' === $arg || '-h' === $arg ) {
            $parsed['help'] = true;
            continue;
        }
        if ( '--apply' === $arg ) {
            $parsed['apply'] = true;
            continue;
        }
        if ( 1 === preg_match( '/^--([a-z][a-z-]*)=(.*)$/', $arg, $m ) ) {
            $parsed[ $m[1] ] = $m[2];
        }
    }

    return $parsed;
}

$args = parseCliArgs( $argv );

// --help never needs WordPress, vendor/autoload, or a database connection.
if ( ! empty( $args['help'] ) ) {
    echo USAGE;
    exit( 0 );
}

$autoloadPath = __DIR__ . '/../vendor/autoload.php';

if ( ! file_exists( $autoloadPath ) ) {
    fwrite( STDERR, "No existe vendor/autoload.php. Ejecute 'composer install' desde el directorio del plugin (" . dirname( __DIR__ ) . ") antes de correr este comando.\n" );
    exit( 1 );
}

require_once $autoloadPath;

if ( isset( $args['season-id'] ) && ! ctype_digit( (string) $args['season-id'] ) ) {
    fwrite( STDERR, "--season-id=<id> debe ser un entero positivo.\n\n" . USAGE );
    exit( 1 );
}

$apply = ! empty( $args['apply'] );

// ─── Bootstrap WordPress — same upward search as tools/importar-titulares.php ──

$wpLoadPath = null;
$dir        = __DIR__;
for ( $i = 0; $i < 8; $i++ ) {
    $candidate = $dir . '/wp-load.php';
    if ( file_exists( $candidate ) ) {
        $wpLoadPath = $candidate;
        break;
    }
    $parent = dirname( $dir );
    if ( $parent === $dir ) {
        break;
    }
    $dir = $parent;
}

if ( null === $wpLoadPath ) {
    fwrite( STDERR, "No se encontro wp-load.php subiendo desde " . __DIR__ . ". Este comando necesita una instalacion real de WordPress — para probar el pipeline sin WordPress use tools/dry-run-calendario.php.\n" );
    exit( 1 );
}

require_once $wpLoadPath;

use EntreRedes\Cambios\Calendario\Cli\CalendarioReport;
use EntreRedes\Cambios\Calendario\Cron\SeedCalendarioCron;
use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Observability\WpEventLog;

global $wpdb;

$eventLog = new WpEventLog();
$settings = new Settings( $wpdb );
$seasonId = isset( $args['season-id'] ) ? (int) $args['season-id'] : $settings->seasonId();

$fechaRepository = new FechaRepository( $wpdb, $eventLog );

printf( "Temporada: %d   Modo: %s\n", $seasonId, $apply ? 'APLICAR (escribe en la base real)' : 'dry-run (solo lectura)' );

if ( $apply ) {
    echo "\nDescargando fixture real y sembrando el calendario...\n";

    $apiClient = SeedCalendarioCron::buildDefaultApiClient();
    $result    = ( new SeedCalendarioCron() )->execute( $wpdb, $eventLog, $settings, $apiClient );

    if ( null === $result ) {
        fwrite( STDERR, "\nEl seed no se aplico — ver el EventLog ('calendario.seed_fallido' o 'calendario.seed_bloqueado') para el motivo. No se garantiza que la tabla haya cambiado.\n" );
        exit( 1 );
    }

    printf( "\nSeed aplicado: %d fecha(s) procesada(s).\n", count( $result ) );
    foreach ( $result as $item ) {
        if ( 'sin_cambios' !== $item['status'] ) {
            printf( "  - fecha_id %d (%s): %s\n", $item['fecha_id'], $item['play_date'], $item['status'] );
        }
    }
}

// ─── Report — same presentation as tools/dry-run-calendario.php ─────────────

$fechas               = $fechaRepository->listBySeason( $seasonId );
$partidosCountByFecha = CalendarioReport::printTable( $fechas, $wpdb );
$failures             = CalendarioReport::printValidations( $fechas, $partidosCountByFecha, $wpdb, $fechaRepository, $seasonId );

echo "\n";
if ( $failures > 0 ) {
    echo "RESULTADO: {$failures} validacion(es) fallaron.\n";
    exit( 1 );
}

if ( ! $apply && [] === $fechas ) {
    echo "RESULTADO: sin fechas todavia (dry-run no escribe nada). Ejecute con --apply para sembrar el calendario.\n";
    exit( 0 );
}

echo "RESULTADO: OK.\n";
exit( 0 );
