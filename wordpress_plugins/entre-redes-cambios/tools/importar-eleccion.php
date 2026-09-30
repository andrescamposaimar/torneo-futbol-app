<?php

declare(strict_types=1);

/**
 * CLI: backfills a season's `cambios_plaza` roster AND `cambios_capitan`
 * designations from the March 2026 election spreadsheet — see
 * `Plazas\Eleccion\EleccionImporter`'s class docblock for the full model
 * this script drives. THIN entry point on purpose: it only parses CLI
 * arguments, reads the `.xlsx` file (via PhpSpreadsheet), bootstraps
 * WordPress, and prints what `EleccionImporter` reports — every decision
 * lives in `src/Plazas/Eleccion/`, which this plugin's whole suite can
 * exercise against the SQLite test shim WITHOUT WordPress or a real
 * spreadsheet file.
 *
 * DRY-RUN IS THE DEFAULT for both writes this script can trigger. Neither
 * `--apply` (plazas) nor `--apply-capitanes` (captaincy) is implied by the
 * other — see `EleccionImporter::aplicarPlazas()` / `::aplicarCapitanes()`'s
 * own docblocks for why they are independent.
 *
 * `phpoffice/phpspreadsheet` is deliberately a `require-dev` dependency in
 * `composer.json`, not a `require` one: it is only ever used by this one-time
 * backfill script, never by the plugin at runtime (nothing under `src/`
 * references `PhpOffice`). Shipping it to production would mean every
 * WordPress request pays for autoloading a spreadsheet library to serve a
 * CLI command that runs once. A production install (`composer install
 * --no-dev`) will not have it — this script checks for that below and fails
 * with an actionable message instead of a bare fatal error.
 *
 * Usage:
 *   php tools/importar-eleccion.php --excel=<archivo.xlsx> --fecha-desde-id=<id>
 *       [--season-id=<id>] [--overrides=<archivo.csv>] [--apply] [--apply-capitanes]
 *       [--now="YYYY-MM-DD HH:MM:SS"]
 *   php tools/importar-eleccion.php --help
 *
 * Exit code: 0 when the plan has no errors (dry-run) or every requested
 * write succeeded; 1 when the spreadsheet, the plan, or the arguments
 * themselves are unusable.
 */

const USAGE = <<<'TXT'
Importador de plazas y capitanes desde la planilla de eleccion (Chami 2026) —
backfill del plantel de una temporada a partir del Excel de la eleccion de
marzo y de WordPress.

USO:
  php tools/importar-eleccion.php --excel=<archivo.xlsx> --fecha-desde-id=<id> [opciones]
  php tools/importar-eleccion.php --help

ARGUMENTOS:
  --excel=<archivo.xlsx>    Obligatorio. Ruta al Excel de la eleccion. Debe
                             tener las hojas 'x Equipo' y
                             'Titulares eleccion con datos', con sus columnas
                             tal como las exporta la eleccion.
  --fecha-desde-id=<id>     Obligatorio. El id de cambios_fecha desde el cual
                             arranca la ocupacion genesis de CADA plaza que
                             este import abra — el mismo para todas, porque la
                             conformacion del plantel ocurre una sola vez, el
                             mismo dia, para toda la liga.
  --season-id=<id>          Opcional. Por defecto, la temporada configurada en
                             Calendario\Settings::seasonId() (cambios_settings).
  --overrides=<archivo.csv> Opcional. CSV con columnas 'nombre_excel' y
                             'titular_player_id', para los titulares que no se
                             puedan resolver automaticamente contra el
                             plantel de WordPress del equipo. Sin este
                             archivo, cualquier titular no resuelto rechaza
                             la importacion completa.
  --apply                   Opcional. Sin esta bandera, el comando SOLO valida
                             y muestra el plan de PLAZAS — no escribe nada.
                             Con --apply, abre las plazas.
  --apply-capitanes         Opcional. Igual que --apply, pero para la
                             designacion de capitanes (fila 'CAP' de la hoja
                             'x Equipo'). Independiente de --apply: puede
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
  - Un titular que no se puede resolver por nombre contra el plantel de
    WordPress del equipo (ni por override) es un error que bloquea TODO el
    import, no solo esa plaza.
  - Reintentar --apply despues de una importacion exitosa no abre nada
    nuevo (se detecta por equipo + titular_player_id ya abiertos).
  - Al final se muestra un reporte de reemplazos (solo informativo, nunca
    escribe nada): por equipo, cuantos titulares oficiales tienen la marca
    'reemplazo_baja' en WordPress vs. cuantos jugadores del plantel de
    WordPress (que no son titulares oficiales) tienen 'reemplazo_alta'.

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

// --help never needs WordPress, PhpSpreadsheet, or a spreadsheet file.
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

// PhpSpreadsheet is a `require-dev` dependency on purpose (see this file's
// own docblock): it is needed ONLY for this one-time import, never at
// runtime, so a production install (`composer install --no-dev`) will not
// have it. Fail with an actionable message here, before touching any
// argument or file, instead of a bare "class not found" fatal later.
if ( ! class_exists( \PhpOffice\PhpSpreadsheet\IOFactory::class ) ) {
    fwrite( STDERR, "Falta la dependencia 'phpoffice/phpspreadsheet'. Es una dependencia de desarrollo a proposito (solo se usa para esta importacion puntual, nunca en produccion). Ejecute 'composer install' (sin --no-dev) desde el directorio del plugin (" . dirname( __DIR__ ) . ") para instalarla, y vuelva a correr este comando.\n" );
    exit( 1 );
}

if ( empty( $args['excel'] ) ) {
    fwrite( STDERR, "Falta --excel=<archivo.xlsx>.\n\n" . USAGE );
    exit( 1 );
}

if ( empty( $args['fecha-desde-id'] ) || ! ctype_digit( (string) $args['fecha-desde-id'] ) ) {
    fwrite( STDERR, "Falta o es invalido --fecha-desde-id=<id> (debe ser un entero positivo).\n\n" . USAGE );
    exit( 1 );
}

$excelPath = (string) $args['excel'];

if ( ! is_readable( $excelPath ) ) {
    fwrite( STDERR, "No se puede leer el archivo Excel: {$excelPath}\n" );
    exit( 1 );
}

// ─── Read the two sheets ──────────────────────────────────────────────────

const SHEET_EQUIPO     = 'x Equipo';
const SHEET_TITULARES  = 'Titulares eleccion con datos';

try {
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $excelPath );
} catch ( \Throwable $e ) {
    fwrite( STDERR, "Error leyendo el Excel: {$e->getMessage()}\n" );
    exit( 1 );
}

$equipoSheet    = $spreadsheet->getSheetByName( SHEET_EQUIPO );
$titularesSheet = $spreadsheet->getSheetByName( SHEET_TITULARES );

if ( null === $equipoSheet ) {
    fwrite( STDERR, "El Excel no tiene una hoja llamada '" . SHEET_EQUIPO . "'.\n" );
    exit( 1 );
}
if ( null === $titularesSheet ) {
    fwrite( STDERR, "El Excel no tiene una hoja llamada '" . SHEET_TITULARES . "'.\n" );
    exit( 1 );
}

$equipoRows    = $equipoSheet->toArray( null, true, true, false );
$titularesRows = $titularesSheet->toArray( null, true, true, false );

use EntreRedes\Cambios\Plazas\Eleccion\EleccionImporter;
use EntreRedes\Cambios\Plazas\Eleccion\EleccionOverrides;
use EntreRedes\Cambios\Plazas\Eleccion\EleccionSheetParser;

try {
    $equipoParsed    = EleccionSheetParser::parseEquipoSheet( $equipoRows );
    $titularesParsed = EleccionSheetParser::parseTitularesSheet( $titularesRows );
} catch ( \InvalidArgumentException $e ) {
    fwrite( STDERR, "Error en el Excel: {$e->getMessage()}\n" );
    exit( 1 );
}

$overrides = [];
if ( ! empty( $args['overrides'] ) ) {
    $overridesPath = (string) $args['overrides'];
    if ( ! is_readable( $overridesPath ) ) {
        fwrite( STDERR, "No se puede leer el archivo de overrides: {$overridesPath}\n" );
        exit( 1 );
    }
    try {
        $overrides = EleccionOverrides::parse( (string) file_get_contents( $overridesPath ) );
    } catch ( \InvalidArgumentException $e ) {
        fwrite( STDERR, "Error en el archivo de overrides: {$e->getMessage()}\n" );
        exit( 1 );
    }
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
use EntreRedes\Cambios\Calendario\Settings;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Observability\WpEventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;

global $wpdb;

$eventLog = new WpEventLog();
$settings = new Settings( $wpdb );

$seasonId     = isset( $args['season-id'] ) ? (int) $args['season-id'] : $settings->seasonId();
$fechaDesdeId = (int) $args['fecha-desde-id'];
$apply        = ! empty( $args['apply'] );
$applyCapitanes = ! empty( $args['apply-capitanes'] );
// DATETIME columns in this plugin are always UTC.
$now = isset( $args['now'] ) ? (string) $args['now'] : current_time( 'mysql', true );

$plazaRepository   = new PlazaRepository( $wpdb, $eventLog );
$capitanRepository = new CapitanRepository( $wpdb, $eventLog );
$fechaRepository   = new FechaRepository( $wpdb, $eventLog );
$importer          = new EleccionImporter( $wpdb, $plazaRepository, $capitanRepository, $fechaRepository, $eventLog );

printf(
    "Temporada: %d   Fecha de inicio: %d   Excel: %s   Equipos leidos: %d   Overrides: %d\n\n",
    $seasonId,
    $fechaDesdeId,
    $excelPath,
    count( $equipoParsed['teams'] ),
    count( $overrides )
);

try {
    $plan = $importer->planificar(
        $equipoParsed['teams'],
        $equipoParsed['errors'],
        $titularesParsed['puntajes'],
        $titularesParsed['errors'],
        $overrides,
        $seasonId,
        $fechaDesdeId
    );
} catch ( \Throwable $e ) {
    fwrite( STDERR, "Error inesperado durante la validacion: {$e->getMessage()}\n" );
    exit( 1 );
}

// ─── Plan, por equipo ────────────────────────────────────────────────────

echo "=== Plan de plazas por equipo ===\n";
printf( "%-30s %8s %-16s\n", 'equipo', 'titulares', 'estado' );

$estadoLabel = [
    'a_importar'   => 'A importar',
    'ya_importado' => 'Ya importado',
    'conflicto'    => 'CONFLICTO',
    'con_errores'  => 'Con errores',
];

foreach ( $plan->teamSummaries() as $team ) {
    printf(
        "%-30s %8d %-16s\n",
        $team['team_label'] . ' (id=' . $team['team_id'] . ')',
        $team['titular_count'],
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

// ─── Reporte de reemplazos (solo informativo) ────────────────────────────

$reporte = $importer->reportarReemplazos( $plan );

echo "\n=== Reporte de reemplazos (informativo, no escribe nada) ===\n";
printf( "%-30s %10s %10s %-10s\n", 'equipo', 'con_baja', 'altas', 'estado' );
foreach ( $reporte->porEquipo() as $row ) {
    printf(
        "%-30s %10d %10d %-10s\n",
        $row['team_label'] . ' (id=' . $row['team_id'] . ')',
        $row['titulares_con_baja'],
        $row['altas_no_titulares'],
        $row['difieren'] ? 'DIFIEREN' : 'ok'
    );
}

if ( [] !== $reporte->extras() ) {
    echo "\nJugadores en el plantel de WordPress que no son titulares oficiales ni tienen 'reemplazo_alta':\n";
    foreach ( $reporte->extras() as $extra ) {
        echo "  - {$extra['team_label']}: {$extra['player_label']} (id={$extra['player_id']})\n";
    }
}

if ( [] !== $reporte->omitidos() ) {
    echo "\nEquipos omitidos del reporte (titulares oficiales no resueltos):\n";
    foreach ( $reporte->omitidos() as $omitido ) {
        echo "  - {$omitido}\n";
    }
}

exit( 0 );
