<?php

declare(strict_types=1);

/**
 * CLI: backfills a season's `cambios_plaza` roster AND `cambios_capitan`
 * designations from a plain CSV of already-resolved titulares — see
 * `Plazas\Alta\TitularesListImporter`'s class docblock for the full model
 * this script drives, and for why it replaces this plugin's previous
 * spreadsheet-import script (removed together with the whole namespace that
 * held it: parsing the March 2026 election spreadsheet was the wrong design
 * from the start — that spreadsheet was a one-off cross-reference the
 * process owner used to settle who the official titulares are, never a data
 * source to keep parsing on an ongoing basis).
 *
 * THIN entry point on purpose: it only parses CLI arguments, reads the CSV
 * file, bootstraps WordPress, and prints what `TitularesListImporter`
 * reports — every decision lives in `src/Plazas/Alta/`, which this plugin's
 * whole suite can exercise against the SQLite test shim WITHOUT WordPress or
 * a real CSV file.
 *
 * This plugin no longer depends on any spreadsheet-parsing library at all —
 * this script reads a plain CSV with PHP's own `str_getcsv()`, nothing else.
 *
 * DRY-RUN IS THE DEFAULT for both writes this script can trigger. Neither
 * `--apply` (plazas) nor `--apply-capitanes` (captaincy) is implied by the
 * other — see `TitularesListImporter::aplicarPlazas()` / `::aplicarCapitanes()`'s
 * own docblocks for why they are independent.
 *
 * Usage:
 *   php tools/importar-titulares.php --csv=<archivo.csv> --season-id=<id> --fecha-desde-id=<id>
 *       [--apply] [--apply-capitanes] [--now="YYYY-MM-DD HH:MM:SS"]
 *   php tools/importar-titulares.php --help
 *
 * Exit code: 0 when the plan has no errors (dry-run) or every requested
 * write succeeded; 1 when the CSV, the plan, or the arguments themselves are
 * unusable.
 */

const USAGE = <<<'TXT'
Importador de plazas y capitanes desde la lista de titulares oficiales —
backfill del plantel de una temporada a partir de un CSV con los ids ya
resueltos (team_id / titular_player_id de WordPress), sin parsear ninguna
planilla.

USO:
  php tools/importar-titulares.php --csv=<archivo.csv> --season-id=<id> --fecha-desde-id=<id> [opciones]
  php tools/importar-titulares.php --help

ARGUMENTOS:
  --csv=<archivo.csv>       Obligatorio. Ruta al CSV con las columnas
                             'team_id', 'titular_player_id', 'puntaje_techo'
                             y 'es_capitan' (una columna 'equipo' es opcional
                             y solo se usa para que los mensajes de error se
                             lean mejor — ver tools/examples/titulares-oficiales.ejemplo.csv).
  --season-id=<id>          Obligatorio. La temporada (sp_season) a la que
                             pertenecen todas las filas del archivo.
  --fecha-desde-id=<id>     Obligatorio. El id de cambios_fecha desde el cual
                             arranca la ocupacion genesis de CADA plaza que
                             este import abra — el mismo para todas, porque la
                             conformacion del plantel ocurre una sola vez, el
                             mismo dia, para toda la liga.
  --apply                   Opcional. Sin esta bandera, el comando SOLO valida
                             y muestra el plan de PLAZAS — no escribe nada.
                             Con --apply, abre las plazas.
  --apply-capitanes         Opcional. Igual que --apply, pero para la
                             designacion de capitanes (filas con
                             es_capitan=1). Independiente de --apply: puede
                             usarse sola, con --apply, o ninguna de las dos.
  --now="YYYY-MM-DD HH:MM:SS"
                             Opcional. Fuerza el instante registrado como
                             created_at de cada plaza/capitania. Por defecto,
                             la hora UTC actual del servidor.
  --help, -h                 Muestra esta ayuda y termina (no requiere
                             WordPress ni una base de datos).

COMPORTAMIENTO:
  - Se valida el archivo COMPLETO antes de escribir una sola fila. Si hay
    CUALQUIER error, no se abre NINGUNA plaza ni se designa NINGUN capitan —
    un plantel a medias es peor que ninguno.
  - Un team_id o un titular_player_id que no existan en WordPress son errores
    que bloquean TODO el import, nunca se crea un equipo o un jugador nuevo.
  - Un equipo cuya cantidad de filas no sea exactamente 11 es una
    ADVERTENCIA, no un error — un plantel real a mitad de temporada puede
    legitimamente diferir.
  - Reintentar --apply despues de una importacion exitosa no abre nada
    nuevo (se detecta por equipo + titular_player_id ya abiertos).

TXT;

// CLI only — never answers an HTTP request. This file lives under the
// plugin's own tools/ directory, so if it were ever reachable over HTTP it
// would be a live, unauthenticated write path into cambios_plaza /
// cambios_capitan. See tools/dry-run-calendario.php for the same guard.
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
        if ( '--apply-capitanes' === $arg ) {
            $parsed['apply-capitanes'] = true;
            continue;
        }
        if ( 1 === preg_match( '/^--([a-z][a-z-]*)=(.*)$/', $arg, $m ) ) {
            $parsed[ $m[1] ] = $m[2];
        }
    }

    return $parsed;
}

$args = parseCliArgs( $argv );

// --help never needs WordPress, vendor/autoload, or a CSV file.
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

if ( empty( $args['csv'] ) ) {
    fwrite( STDERR, "Falta --csv=<archivo.csv>.\n\n" . USAGE );
    exit( 1 );
}

if ( empty( $args['season-id'] ) || ! ctype_digit( (string) $args['season-id'] ) ) {
    fwrite( STDERR, "Falta o es invalido --season-id=<id> (debe ser un entero positivo).\n\n" . USAGE );
    exit( 1 );
}

if ( empty( $args['fecha-desde-id'] ) || ! ctype_digit( (string) $args['fecha-desde-id'] ) ) {
    fwrite( STDERR, "Falta o es invalido --fecha-desde-id=<id> (debe ser un entero positivo).\n\n" . USAGE );
    exit( 1 );
}

$csvPath = (string) $args['csv'];

if ( ! is_readable( $csvPath ) ) {
    fwrite( STDERR, "No se puede leer el archivo CSV: {$csvPath}\n" );
    exit( 1 );
}

// ─── Parse the CSV (format only — see TitularesListParser's own docblock) ──

use EntreRedes\Cambios\Plazas\Alta\TitularesListImporter;
use EntreRedes\Cambios\Plazas\Alta\TitularesListParser;

try {
    $parsed = TitularesListParser::parse( (string) file_get_contents( $csvPath ) );
} catch ( \InvalidArgumentException $e ) {
    fwrite( STDERR, "Error en el CSV: {$e->getMessage()}\n" );
    exit( 1 );
}

// ─── Bootstrap WordPress ──────────────────────────────────────────────────

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
    fwrite( STDERR, "No se encontro wp-load.php subiendo desde " . __DIR__ . ". Este comando necesita una instalacion real de WordPress.\n" );
    exit( 1 );
}

require_once $wpLoadPath;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Observability\WpEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;

global $wpdb;

$eventLog = new WpEventLog();

$seasonId       = (int) $args['season-id'];
$fechaDesdeId   = (int) $args['fecha-desde-id'];
$apply          = ! empty( $args['apply'] );
$applyCapitanes = ! empty( $args['apply-capitanes'] );
// DATETIME columns in this plugin are always UTC.
$now = isset( $args['now'] ) ? (string) $args['now'] : current_time( 'mysql', true );

$plazaRepository   = new PlazaRepository( $wpdb, $eventLog );
$capitanRepository = new CapitanRepository( $wpdb, $eventLog );
$fechaRepository   = new FechaRepository( $wpdb, $eventLog );
$importer          = new TitularesListImporter( $wpdb, $plazaRepository, $capitanRepository, $fechaRepository, $eventLog );

printf(
    "Temporada: %d   Fecha de inicio: %d   CSV: %s   Filas leidas: %d\n\n",
    $seasonId,
    $fechaDesdeId,
    $csvPath,
    count( $parsed['rows'] )
);

try {
    $plan = $importer->planificar( $parsed['rows'], $parsed['errors'], $seasonId, $fechaDesdeId );
} catch ( \Throwable $e ) {
    fwrite( STDERR, "Error inesperado durante la validacion: {$e->getMessage()}\n" );
    exit( 1 );
}

// ─── Plan, por equipo ────────────────────────────────────────────────────

echo "=== Plan de plazas por equipo ===\n";
printf( "%-40s %8s %-16s\n", 'equipo', 'filas', 'estado' );

$estadoLabel = [
    'a_importar'   => 'A importar',
    'ya_importado' => 'Ya importado',
    'conflicto'    => 'CONFLICTO',
    'con_errores'  => 'Con errores',
];

foreach ( $plan->teamSummaries() as $team ) {
    printf(
        "%-40s %8d %-16s\n",
        $team['team_label'],
        $team['row_count'],
        $estadoLabel[ $team['estado'] ] ?? $team['estado']
    );
}

if ( [] !== $plan->warnings() ) {
    echo "\n=== Advertencias ===\n";
    foreach ( $plan->warnings() as $warning ) {
        echo "  - {$warning}\n";
    }
}

if ( $plan->hasErrors() ) {
    echo "\n=== Errores ===\n";
    foreach ( $plan->errors() as $error ) {
        echo "  - {$error}\n";
    }
    printf( "\nRESULTADO: %d error(es). Se rechaza la importacion completa — no se escribio nada.\n", count( $plan->errors() ) );
    exit( 1 );
}

$rowsToOpen = $plan->rowsToOpen();

if ( [] === $rowsToOpen ) {
    echo "\nPlazas: nada para importar — todos los equipos ya estaban importados.\n";
} elseif ( ! $apply ) {
    printf( "\nPlazas: dry-run OK. %d plaza(s) se abririan. Ejecute con --apply para escribir.\n", count( $rowsToOpen ) );
} else {
    try {
        $opened = $importer->aplicarPlazas( $plan, $seasonId, $fechaDesdeId, $now );
    } catch ( \Throwable $e ) {
        fwrite( STDERR, "Error al escribir plazas: {$e->getMessage()}\n" );
        exit( 1 );
    }
    printf( "\nPlazas: se abrieron %d plaza(s).\n", $opened );
}

$capitanes = $plan->capitanes();

if ( [] === $capitanes ) {
    echo "Capitanes: nada para designar.\n";
} elseif ( ! $applyCapitanes ) {
    printf( "Capitanes: dry-run OK. %d capitan(es) se designarian. Ejecute con --apply-capitanes para escribir.\n", count( $capitanes ) );
} else {
    try {
        $designated = $importer->aplicarCapitanes( $plan, $seasonId, $now );
    } catch ( \Throwable $e ) {
        fwrite( STDERR, "Error al designar capitanes: {$e->getMessage()}\n" );
        exit( 1 );
    }
    printf( "Capitanes: se procesaron %d equipo(s).\n", $designated );
}

exit( 0 );
