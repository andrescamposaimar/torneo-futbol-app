<?php

declare(strict_types=1);

/**
 * CLI: backfills a season's `cambios_plaza` roster from a CSV — see
 * `Plazas\PlazaImporter`'s class docblock for the full model this script
 * drives (column contract, resolution rules, idempotency key, the "exactly
 * 11 plazas" warning). This file is a THIN entry point on purpose: it only
 * parses CLI arguments, bootstraps WordPress, and prints what `PlazaImporter` reports —
 * every decision (what counts as a valid row, what already-imported means,
 * what gets written) lives in `src/Plazas/PlazaImporter.php` and
 * `src/Plazas/PlazaImportCsvParser.php`, which this plugin's whole suite can
 * exercise against the SQLite test shim WITHOUT WordPress. Putting logic here
 * instead would make it untestable the same way — see this file's sibling
 * `tools/dry-run-calendario.php` for the established precedent.
 *
 * DRY-RUN IS THE DEFAULT. Nothing is written to the database unless --apply
 * is passed explicitly. A dry-run still needs a real WordPress + database
 * connection (unlike dry-run-calendario.php, which runs entirely against the
 * in-memory SQLite shim): resolving `equipo`/`titular` by title, checking
 * season registration, and checking for an already-existing roster are all
 * real reads against THIS install's `wp_posts` / `wp_term_relationships` /
 * `cambios_plaza` — there is no meaningful dry-run of "does this team already
 * have a roster" without asking the real database.
 *
 * Usage:
 *   php tools/importar-plazas.php --csv=<archivo.csv> --fecha-desde-id=<id> [--season-id=<id>] [--apply] [--now="YYYY-MM-DD HH:MM:SS"]
 *   php tools/importar-plazas.php --help
 *
 * Exit code: 0 when the plan has no errors (dry-run) or the import was
 * applied successfully; 1 when the CSV or the plan has ANY error, or the
 * CSV/arguments themselves are unusable. See `PlazaImportPlan::hasErrors()`.
 */

const USAGE = <<<'TXT'
Importador de plazas (cambios_plaza) — backfill del plantel de una temporada
desde un CSV.

USO:
  php tools/importar-plazas.php --csv=<archivo.csv> --fecha-desde-id=<id> [opciones]
  php tools/importar-plazas.php --help

ARGUMENTOS:
  --csv=<archivo.csv>       Obligatorio. Ruta al CSV a importar. Ver
                             templates/plazas-import-template.csv para el
                             formato exacto (columnas: equipo, titular,
                             puntaje_techo).
  --fecha-desde-id=<id>     Obligatorio. El id de cambios_fecha desde el cual
                             arranca la ocupación génesis de CADA plaza que
                             este CSV abra — el mismo para todas, porque la
                             conformación del plantel ocurre una sola vez, el
                             mismo día, para toda la liga.
  --season-id=<id>          Opcional. Por defecto, la temporada configurada en
                             Calendario\Settings::seasonId() (cambios_settings).
  --apply                   Opcional. Sin esta bandera, el comando SOLO valida
                             y muestra el plan — no escribe nada en la base de
                             datos. Con --apply, escribe.
  --now="YYYY-MM-DD HH:MM:SS"
                             Opcional. Fuerza el instante registrado como
                             created_at de cada plaza/ocupación abierta. Por
                             defecto, la hora UTC actual del servidor.
  --help, -h                 Muestra esta ayuda y termina (no requiere
                             WordPress ni una base de datos).

COLUMNAS DEL CSV (equipo, titular, puntaje_techo):
  - equipo / titular: el id de WordPress del equipo/jugador, O su nombre
    EXACTO (post_title) tal como figura publicado en el sitio. Un nombre
    ambiguo o inexistente es un error — nunca se adivina.
  - puntaje_techo: uno de 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5 (coma o punto).

  Filas cuya primera celda empieza con '#' se ignoran (ejemplos comentados).
  Columnas de más (por ejemplo 'notas') se aceptan y se ignoran.

COMPORTAMIENTO:
  - Se valida el archivo COMPLETO antes de escribir una sola fila. Si hay
    CUALQUIER error, no se abre NINGUNA plaza — un plantel a medias es peor
    que ninguno.
  - Un equipo cuyas filas no suman exactamente 11 genera una ADVERTENCIA,
    no un error — el plantel real puede legítimamente diferir a mitad de
    temporada.
  - Reintentar --apply después de una importación exitosa no abre nada
    nuevo (se detecta por equipo + titular_player_id ya abiertos). Una
    importación a medias (algunos equipos sí, otros no) SÍ completa lo que
    falta, sin duplicar lo ya hecho.

TXT;

// CLI only — never answers an HTTP request. This file lives under the
// plugin's own tools/ directory, so if it were ever reachable over HTTP it
// would be a live, unauthenticated write path into cambios_plaza. See
// tools/dry-run-calendario.php for the same guard, for the same reason.
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

// --help never needs WordPress — see this file's class docblock and the
// definition-of-done this script must satisfy ("--help explains itself
// without needing WordPress").
if ( ! empty( $args['help'] ) ) {
    echo USAGE;
    exit( 0 );
}

if ( empty( $args['csv'] ) ) {
    fwrite( STDERR, "Falta --csv=<archivo.csv>.\n\n" . USAGE );
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

$csvContent = file_get_contents( $csvPath );

if ( false === $csvContent ) {
    fwrite( STDERR, "Error leyendo el archivo CSV: {$csvPath}\n" );
    exit( 1 );
}

// ─── Bootstrap ────────────────────────────────────────────────────────────
//
// The plugin's OWN Composer autoloader first — never dependent on the
// plugin being active in the target WordPress install, mirrors
// tools/dry-run-calendario.php's own bootstrap. Then a REAL wp-load.php,
// unlike that script: this one reads and writes the real `cambios_plaza`
// table and resolves real `wp_posts` titles, which the SQLite shim cannot
// stand in for (see this file's class docblock).

require_once __DIR__ . '/../vendor/autoload.php';

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
use EntreRedes\Cambios\Observability\WpEventLog;
use EntreRedes\Cambios\Plazas\PlazaImportCsvParser;
use EntreRedes\Cambios\Plazas\PlazaImporter;
use EntreRedes\Cambios\Plazas\PlazaRepository;

global $wpdb;

try {
    $rows = PlazaImportCsvParser::parse( $csvContent );
} catch ( \InvalidArgumentException $e ) {
    fwrite( STDERR, "Error en el CSV: {$e->getMessage()}\n" );
    exit( 1 );
}

$eventLog = new WpEventLog();
$settings = new Settings( $wpdb );

$seasonId     = isset( $args['season-id'] ) ? (int) $args['season-id'] : $settings->seasonId();
$fechaDesdeId = (int) $args['fecha-desde-id'];
$apply        = ! empty( $args['apply'] );
// DATETIME columns in this plugin are always UTC — see FechaRepository's own
// convention (`current_time( 'mysql', true )`, $gmt = true, never local time).
$now = isset( $args['now'] ) ? (string) $args['now'] : current_time( 'mysql', true );

$plazaRepository = new PlazaRepository( $wpdb, $eventLog );
$fechaRepository = new FechaRepository( $wpdb, $eventLog );
$importer        = new PlazaImporter( $wpdb, $plazaRepository, $fechaRepository, $eventLog );

printf( "Temporada: %d   Fecha de inicio: %d   CSV: %s   Filas leidas: %d\n\n", $seasonId, $fechaDesdeId, $csvPath, count( $rows ) );

try {
    $plan = $importer->planificar( $rows, $seasonId, $fechaDesdeId );
} catch ( \Throwable $e ) {
    fwrite( STDERR, "Error inesperado durante la validacion: {$e->getMessage()}\n" );
    exit( 1 );
}

// ─── Plan, por equipo ────────────────────────────────────────────────────

echo "=== Plan por equipo ===\n";
printf( "%-30s %8s %8s %-16s\n", 'equipo', 'plazas', 'filas', 'estado' );

$estadoLabel = [
    'a_importar'   => 'A importar',
    'ya_importado' => 'Ya importado',
    'conflicto'    => 'CONFLICTO',
    'con_errores'  => 'Con errores',
];

foreach ( $plan->teamSummaries() as $team ) {
    printf(
        "%-30s %8d %8d %-16s\n",
        $team['team_label'] . ' (id=' . $team['team_id'] . ')',
        $team['plazas'],
        count( $team['lines'] ),
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
    echo "\nRESULTADO: nada para importar — todos los equipos ya estaban importados.\n";
    exit( 0 );
}

if ( ! $apply ) {
    printf( "\nRESULTADO: dry-run OK. %d plaza(s) se abririan. Ejecute con --apply para escribir.\n", count( $rowsToOpen ) );
    exit( 0 );
}

try {
    $opened = $importer->aplicar( $plan, $seasonId, $fechaDesdeId, $now );
} catch ( \Throwable $e ) {
    fwrite( STDERR, "Error al escribir: {$e->getMessage()}\n" );
    exit( 1 );
}

printf( "\nRESULTADO: se abrieron %d plaza(s).\n", $opened );
exit( 0 );
