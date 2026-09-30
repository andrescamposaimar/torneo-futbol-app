<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\ChecksReads;
use EntreRedes\Cambios\Support\OpensTransactions;

/**
 * Backfills a season's `cambios_plaza` roster from a CSV — the one-time
 * import that turns the process owner's spreadsheet into the 11 titular
 * plazas every team needs before a single captain screen can show anything
 * real. See `Plazas\PlazaRepository::openPlaza()` for what a plaza actually
 * is; this class only resolves a CSV row into the arguments that method
 * needs, validates the WHOLE file, and — only once nothing in it is wrong —
 * opens every plaza in one atomic batch.
 *
 * *** THE THREE CSV COLUMNS, AND WHAT IS DELIBERATELY NOT ONE ***
 * `equipo`, `titular`, `puntaje_techo` — see
 * `Plazas\PlazaImportCsvParser`'s class docblock for the full column
 * contract. `season_id` and `fecha_desde_id` are NOT columns: every plaza a
 * single run of this importer opens shares the same season and the same
 * starting fecha (the "conformación" of March happens once, for the whole
 * league, on one day) — repeating either on every row would only be 300
 * chances to introduce a typo the CSV's own shape cannot catch. Both are
 * passed once, to `planificar()`/`aplicar()`, from
 * `Calendario\Settings::seasonId()` or a CLI argument (see
 * `tools/importar-plazas.php`).
 *
 * *** RESOLUTION: BY ID WHEN GIVEN ONE, BY EXACT TITLE OTHERWISE ***
 * `equipo` and `titular` each accept either a bare WordPress post id (a
 * string of digits) or the post's EXACT `post_title` — never a fuzzy or
 * partial match. An id that does not resolve to a published `sp_team` /
 * `sp_player` post, a title that matches zero posts, or a title that matches
 * MORE than one, are all hard errors naming the offending row — this class
 * never guesses. Mirrors `Plazas\CandidatosResolver`'s own raw-SQL query
 * style (never `WP_Query`/`get_page_by_title()`: the SQLite test shim this
 * whole plugin's suite depends on has no equivalent for either — see
 * `Plazas\PlazaRepository`'s class docblock).
 *
 * *** VALIDATE THE WHOLE FILE FIRST, WRITE NOTHING UNTIL IT IS CLEAN ***
 * `planificar()` performs ONLY reads — it never opens a transaction and
 * never calls `PlazaRepository::openPlazaWithinTransaction()`. Every row is
 * resolved and validated independently of every other row's outcome, so a
 * single bad row can never hide a second, unrelated bad row behind it (see
 * `PlazaImportPlan::errors()` — always the FULL list). `aplicar()` refuses
 * outright when `PlazaImportPlan::hasErrors()` is true. This is the same
 * discipline `tools/dry-run-calendario.php` established for the calendar
 * seeder, applied here to a write path instead of a read-only diagnostic —
 * see this class's own `aplicar()` docblock for why a partial roster is
 * WORSE than none.
 *
 * *** IDEMPOTENCY KEY: (season_id, team_id, titular_player_id) ***
 * `cambios_plaza.titular_player_id` is the one column
 * `PlazaRepository::openPlaza()`'s own docblock calls PERMANENT — "a titular
 * never changes team, and this column is never reassigned" — for the entire
 * lifetime of a plaza. That makes it the natural, stable key for "does this
 * plaza already exist": not row order, not a generated id the CSV does not
 * carry, but the one fact about a plaza that can never drift once opened.
 * Per team, `planificar()` compares the SET of titular ids the CSV lists
 * against the SET already open (`closed_at IS NULL`) for that team:
 *   - Nothing open yet for the team -> every CSV row for it is queued to open.
 *   - The two sets match EXACTLY, same `puntaje_techo` per titular -> the
 *     team is already imported; NOTHING is queued for it, and this is
 *     reported as a no-op, never an error. This is what makes a second
 *     `--apply` run of an already-succeeded import open nothing (see
 *     `aplicar()`).
 *   - Anything else (some overlap, a different `puntaje_techo` for the same
 *     titular, an extra or missing titular) -> a hard error naming the team
 *     and its rows. Silently accepting this would either duplicate a plaza
 *     or silently apply a change to data this importer did not create — a
 *     human must look at it. A CLOSED plaza (`closed_at` set) never counts
 *     as "already open" here: `PlazaRepository::closePlaza()`'s own
 *     docblock says a closed plaza "stops existing entirely", so a fresh
 *     import for that same titular is exactly what re-opening a mistakenly
 *     closed slot looks like, not a duplicate.
 *
 * *** THE "EXACTLY 11 PLAZAS" CHECK IS A WARNING, NEVER AN ERROR ***
 * Counted straight from the CSV's rows per team (independent of whether
 * `titular`/`puntaje_techo` resolved) — see `planificar()`'s docblock. A
 * mid-season roster legitimately drifting from 11 is real (see this
 * feature's own task brief); refusing the whole import over it would block
 * the very backfill this importer exists to enable.
 */
class PlazaImporter {

    use ChecksReads;
    use OpensTransactions;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private FechaRepository $fechaRepository;
    private EventLog $eventLog;

    public function __construct(
        \wpdb $wpdb,
        PlazaRepository $plazaRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog
    ) {
        $this->wpdb            = $wpdb;
        $this->plazaRepository = $plazaRepository;
        $this->fechaRepository = $fechaRepository;
        $this->eventLog        = $eventLog;
    }

    /**
     * Resolves and validates every row of an already-parsed CSV (see
     * `Plazas\PlazaImportCsvParser::parse()`) against `$seasonId` /
     * `$fechaDesdeId` — READ-ONLY, never writes a single row. Always returns
     * a plan; a plan with `hasErrors()` true is the honest, complete report
     * of everything wrong, never a partial one that stopped at the first
     * problem.
     *
     * @param array<int, array{line:int, equipo:string, titular:string, puntaje_techo:string}> $parsedRows
     */
    public function planificar( array $parsedRows, int $seasonId, int $fechaDesdeId ): PlazaImportPlan {
        $errors   = [];
        $warnings = [];

        $fechaError = $this->validateFechaDesde( $fechaDesdeId, $seasonId );
        if ( null !== $fechaError ) {
            $errors[] = $fechaError;
        }

        // Pass 1 — resolve every row independently. A row that fails one
        // check still gets every OTHER check it can run, so one bad column
        // never hides a second bad column on the SAME row either.
        $resolved         = []; // line => resolved fields (only entries with NO per-row error)
        $titularsByLine   = []; // line => player_id, for EVERY row whose titular resolved, even if another
                                 // column on that same row failed — see class docblock, "duplicate titular".

        foreach ( $parsedRows as $row ) {
            $line = (int) $row['line'];

            $teamId = $this->resolveTeamId( (string) $row['equipo'], $line, $errors );

            $titularId = $this->resolvePlayerId( (string) $row['titular'], $line, $errors );
            if ( null !== $titularId ) {
                $titularsByLine[ $line ] = $titularId;
            }

            $puntaje = $this->resolvePuntaje( (string) $row['puntaje_techo'], $line, $errors );

            if ( null !== $teamId && null !== $titularId && null !== $puntaje ) {
                $resolved[ $line ] = [
                    'team_id'            => $teamId,
                    'titular_player_id'  => $titularId,
                    'puntaje'            => $puntaje,
                ];
            }
        }

        // Pass 2 — the same titular cannot open two plazas, in this CSV or
        // against what already exists. Checked over EVERY row whose titular
        // resolved, regardless of that row's other columns (see loop above).
        $this->checkDuplicateTitulares( $titularsByLine, $errors );

        // Pass 3 — every resolved titular must be registered THIS season.
        $this->checkTitularesRegistradosEnTemporada( $resolved, $seasonId, $errors );

        // Pass 4 — group by team: the "exactly 11 plazas" warning (from raw
        // row counts, independent of whether other columns resolved) and the
        // already-imported / would-duplicate check (only for teams whose
        // OWN rows are all otherwise clean — see buildTeamSummaries()).
        [ $teamSummaries, $rowsToOpen, $teamErrors ] =
            $this->buildTeamSummaries( $parsedRows, $resolved, $seasonId, $warnings );

        $errors = array_merge( $errors, $teamErrors );

        // A plan with ANY error opens nothing — rowsToOpen is discarded
        // entirely rather than left half-populated for a caller to misuse.
        if ( [] !== $errors ) {
            $rowsToOpen = [];
        }

        return new PlazaImportPlan( $errors, $warnings, $teamSummaries, $rowsToOpen );
    }

    /**
     * Opens every plaza `$plan->rowsToOpen()` describes, as ONE atomic
     * transaction — see class docblock on `Plazas\PlazaRepository`,
     * "'WithinTransaction' VARIANTS": a half-applied backfill is exactly the
     * "some teams see a plausible, wrong squad" failure mode this whole
     * importer exists to prevent, the same reasoning
     * `Solicitudes\SolicitudRepository::publicarLote()` applies to the
     * Friday lote.
     *
     * @throws \LogicException When `$plan->hasErrors()` is true — this
     *         method never attempts to write a plan the caller has not
     *         confirmed is clean; the CLI tool is expected to check
     *         `hasErrors()` itself and never call this otherwise, but this
     *         guard exists so a future caller cannot bypass that by mistake.
     * @return int The number of plazas actually opened (0 for a plan whose
     *         every team was already imported — see class docblock,
     *         "IDEMPOTENCY KEY").
     */
    public function aplicar( PlazaImportPlan $plan, int $seasonId, int $fechaDesdeId, string $now ): int {
        if ( $plan->hasErrors() ) {
            throw new \LogicException(
                'PlazaImporter::aplicar(): refusing to write a plan that has validation errors. '
                . 'Call planificar() again after fixing the CSV, and only call aplicar() when hasErrors() is false.'
            );
        }

        $rows = $plan->rowsToOpen();

        if ( [] === $rows ) {
            return 0;
        }

        $this->beginTransaction( __FUNCTION__, [ 'season_id' => $seasonId, 'fecha_desde_id' => $fechaDesdeId ] );

        $opened = [];

        try {
            foreach ( $rows as $row ) {
                $plazaId = $this->plazaRepository->openPlazaWithinTransaction(
                    $seasonId,
                    $row['team_id'],
                    $row['titular_player_id'],
                    $row['puntaje'],
                    $fechaDesdeId,
                    $now
                );

                $opened[] = [ 'plaza_id' => $plazaId, 'team_id' => $row['team_id'], 'titular_player_id' => $row['titular_player_id'] ];
            }
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e, [ 'season_id' => $seasonId, 'fecha_desde_id' => $fechaDesdeId ] );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__, [ 'season_id' => $seasonId, 'fecha_desde_id' => $fechaDesdeId ] );

        foreach ( $opened as $o ) {
            $this->eventLog->record( 'plaza.abierta', [
                'plaza_id'          => $o['plaza_id'],
                'season_id'         => $seasonId,
                'team_id'           => $o['team_id'],
                'titular_player_id' => $o['titular_player_id'],
                'fecha_desde_id'    => $fechaDesdeId,
                'origen'            => 'importar-plazas',
            ] );
        }

        $this->eventLog->record( 'importacion.completada', [
            'season_id'       => $seasonId,
            'fecha_desde_id'  => $fechaDesdeId,
            'plazas_abiertas' => count( $opened ),
        ] );

        return count( $opened );
    }

    // -------------------------------------------------------------------------
    // Per-row resolution
    // -------------------------------------------------------------------------

    private function resolveTeamId( string $raw, int $line, array &$errors ): ?int {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            $errors[] = "Fila {$line}: la columna 'equipo' esta vacia.";
            return null;
        }

        if ( ctype_digit( $raw ) ) {
            $id = (int) $raw;
            if ( ! $this->postExistsPublished( 'sp_team', $id ) ) {
                $errors[] = "Fila {$line}: no existe un equipo publicado (sp_team) con id {$id}.";
                return null;
            }
            return $id;
        }

        $ids = $this->findPostIdsByTitle( 'sp_team', $raw );

        if ( [] === $ids ) {
            $errors[] = "Fila {$line}: no existe ningun equipo publicado cuyo nombre sea exactamente '{$raw}'.";
            return null;
        }

        if ( count( $ids ) > 1 ) {
            $errors[] = "Fila {$line}: el nombre de equipo '{$raw}' es ambiguo (coincide con "
                . count( $ids ) . ' equipos); use el id en su lugar.';
            return null;
        }

        return $ids[0];
    }

    private function resolvePlayerId( string $raw, int $line, array &$errors ): ?int {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            $errors[] = "Fila {$line}: la columna 'titular' esta vacia.";
            return null;
        }

        if ( ctype_digit( $raw ) ) {
            $id = (int) $raw;
            if ( ! $this->postExistsPublished( 'sp_player', $id ) ) {
                $errors[] = "Fila {$line}: no existe un jugador publicado (sp_player) con id {$id}.";
                return null;
            }
            return $id;
        }

        $ids = $this->findPostIdsByTitle( 'sp_player', $raw );

        if ( [] === $ids ) {
            $errors[] = "Fila {$line}: no existe ningun jugador publicado cuyo nombre sea exactamente '{$raw}'.";
            return null;
        }

        if ( count( $ids ) > 1 ) {
            $errors[] = "Fila {$line}: el nombre de jugador '{$raw}' es ambiguo (coincide con "
                . count( $ids ) . ' jugadores); use el id en su lugar.';
            return null;
        }

        return $ids[0];
    }

    private function resolvePuntaje( string $raw, int $line, array &$errors ): ?Puntaje {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            $errors[] = "Fila {$line}: la columna 'puntaje_techo' esta vacia.";
            return null;
        }

        try {
            return Puntaje::fromDecimal( $raw );
        } catch ( \InvalidArgumentException $e ) {
            $errors[] = "Fila {$line}: {$e->getMessage()}";
            return null;
        }
    }

    /**
     * @param array<int, int> $titularsByLine line => player_id
     * @param array<int, string> $errors
     */
    private function checkDuplicateTitulares( array $titularsByLine, array &$errors ): void {
        $linesByPlayer = [];
        foreach ( $titularsByLine as $line => $playerId ) {
            $linesByPlayer[ $playerId ][] = $line;
        }

        foreach ( $linesByPlayer as $playerId => $lines ) {
            if ( count( $lines ) > 1 ) {
                sort( $lines );
                $errors[] = 'El jugador titular ' . $playerId . ' aparece como titular en mas de una fila '
                    . '(filas ' . implode( ', ', $lines ) . ') — un jugador solo puede ser titular de una plaza.';
            }
        }
    }

    /**
     * @param array<int, array{team_id:int, titular_player_id:int, puntaje:Puntaje}> $resolved line => fields
     * @param array<int, string> $errors
     */
    private function checkTitularesRegistradosEnTemporada( array $resolved, int $seasonId, array &$errors ): void {
        if ( [] === $resolved ) {
            return;
        }

        $playerIds = array_values( array_unique( array_map(
            static fn ( array $r ): int => $r['titular_player_id'],
            $resolved
        ) ) );

        $registrados = $this->playerIdsRegistradosEnTemporada( $seasonId, $playerIds );
        $registradosSet = array_flip( $registrados );

        foreach ( $resolved as $line => $r ) {
            if ( ! isset( $registradosSet[ $r['titular_player_id'] ] ) ) {
                $errors[] = "Fila {$line}: el jugador {$r['titular_player_id']} no esta registrado en la temporada {$seasonId}.";
            }
        }
    }

    // -------------------------------------------------------------------------
    // Team-level grouping: 9+2 warning + already-imported / conflict check
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{line:int, equipo:string, titular:string, puntaje_techo:string}> $parsedRows
     * @param array<int, array{team_id:int, titular_player_id:int, puntaje:Puntaje}> $resolved line => fields
     * @param array<int, string> $warnings
     * @return array{0: array<int, array{team_id:int, team_label:string, plazas:int, estado:string, lines:array<int,int>}>, 1: array<int, array{line:int, team_id:int, team_label:string, titular_player_id:int, puntaje:Puntaje}>, 2: array<int, string>}
     */
    private function buildTeamSummaries( array $parsedRows, array $resolved, int $seasonId, array &$warnings ): array {
        // Raw row counts per team, straight from the CSV — see class
        // docblock, "THE 'EXACTLY 11 PLAZAS' CHECK". team_id here is only
        // known when 'equipo' itself resolved; a row whose team could not be
        // resolved contributes to no team's count (it already has its own
        // row error).
        $rawRowCounts = []; // team_id => ['count'=>n, 'lines'=>[...]]

        foreach ( $parsedRows as $row ) {
            $line = (int) $row['line'];

            $teamId = $this->quietlyResolveTeamId( (string) $row['equipo'] );
            if ( null === $teamId ) {
                continue;
            }

            $rawRowCounts[ $teamId ]['count']    = ( $rawRowCounts[ $teamId ]['count'] ?? 0 ) + 1;
            $rawRowCounts[ $teamId ]['lines'][]  = $line;
        }

        // Fully-resolved rows, grouped by team — the only rows that could
        // ever be opened or compared against what already exists.
        $resolvedByTeam = [];
        foreach ( $resolved as $line => $r ) {
            $resolvedByTeam[ $r['team_id'] ][ $line ] = $r;
        }

        $teamSummaries = [];
        $rowsToOpen    = [];
        $teamErrors    = [];

        foreach ( $rawRowCounts as $teamId => $counts ) {
            $label = $this->teamLabel( $teamId );

            if ( 11 !== ( $counts['count'] ?? 0 ) ) {
                $warnings[] = "Equipo '{$label}' (id={$teamId}): " . ( $counts['count'] ?? 0 )
                    . ' plaza(s) (se esperaban 11, por reglamento).';
            }

            $teamRows = $resolvedByTeam[ $teamId ] ?? [];
            $allRowsForTeamResolved = count( $teamRows ) === count( $counts['lines'] );

            if ( ! $allRowsForTeamResolved ) {
                // At least one of this team's rows already has its own error
                // (unknown player, bad puntaje, etc.) — the whole import is
                // refused for THAT reason already; skip the already-imported
                // comparison for this team rather than add confusing noise.
                $teamSummaries[] = [
                    'team_id'    => $teamId,
                    'team_label' => $label,
                    'plazas'     => $counts['count'] ?? 0,
                    'estado'     => 'con_errores',
                    'lines'      => $counts['lines'],
                ];
                continue;
            }

            [ $estado, $error ] = $this->compareAgainstExisting( $seasonId, $teamId, $label, $teamRows );

            if ( null !== $error ) {
                $teamErrors[] = $error;
            }

            if ( 'a_importar' === $estado ) {
                foreach ( $teamRows as $line => $r ) {
                    $rowsToOpen[] = [
                        'line'               => $line,
                        'team_id'            => $teamId,
                        'team_label'         => $label,
                        'titular_player_id'  => $r['titular_player_id'],
                        'puntaje'            => $r['puntaje'],
                    ];
                }
            }

            $teamSummaries[] = [
                'team_id'    => $teamId,
                'team_label' => $label,
                'plazas'     => $counts['count'] ?? 0,
                'estado'     => $estado,
                'lines'      => $counts['lines'],
            ];
        }

        return [ $teamSummaries, $rowsToOpen, $teamErrors ];
    }

    /**
     * @param array<int, array{team_id:int, titular_player_id:int, puntaje:Puntaje}> $teamRows line => fields
     * @return array{0:string, 1:?string} [estado ('a_importar'|'ya_importado'|'conflicto'), error message or null]
     */
    private function compareAgainstExisting( int $seasonId, int $teamId, string $label, array $teamRows ): array {
        $existing = array_values( array_filter(
            $this->plazaRepository->listPlazasByEquipo( $seasonId, $teamId ),
            static fn ( array $p ): bool => null === $p['closed_at']
        ) );

        if ( [] === $existing ) {
            return [ 'a_importar', null ];
        }

        $existingByTitular = [];
        foreach ( $existing as $p ) {
            $existingByTitular[ (int) $p['titular_player_id'] ] = $p;
        }

        $csvByTitular = [];
        foreach ( $teamRows as $r ) {
            $csvByTitular[ $r['titular_player_id'] ] = $r;
        }

        // $existing is non-empty here (checked above), so $existingByTitular
        // is too — a plain key-set comparison is enough to know whether the
        // CSV names EXACTLY the same titulares this team already has open.
        $sameTitulares = [] === array_diff_key( $existingByTitular, $csvByTitular )
            && [] === array_diff_key( $csvByTitular, $existingByTitular );

        if ( $sameTitulares ) {
            $matches = true;
            foreach ( $csvByTitular as $titularId => $r ) {
                $existingRow = $existingByTitular[ $titularId ];
                if ( (int) $existingRow['puntaje_techo'] !== $r['puntaje']->halfPoints() ) {
                    $matches = false;
                    break;
                }
            }

            if ( $matches ) {
                return [ 'ya_importado', null ];
            }
        }

        return [
            'conflicto',
            "Equipo '{$label}' (id={$teamId}) ya tiene " . count( $existing )
                . " plaza(s) abierta(s) para la temporada {$seasonId}, y este CSV agregaria o modificaria mas — "
                . 'revise manualmente antes de reimportar (posible roster ya cargado por otra via, o CSV desactualizado).',
        ];
    }

    // -------------------------------------------------------------------------
    // Raw SQL lookups (never WP_Query — see class docblock)
    // -------------------------------------------------------------------------

    /**
     * Same resolution `resolveTeamId()` uses, but swallowing every "not
     * found" outcome into `null` instead of appending to `$errors` — used
     * ONLY by `buildTeamSummaries()`'s 9+2 tally, which must never double-log
     * an error `planificar()`'s own row loop already recorded for the exact
     * same cell.
     */
    private function quietlyResolveTeamId( string $raw ): ?int {
        $raw = trim( $raw );

        if ( '' === $raw ) {
            return null;
        }

        if ( ctype_digit( $raw ) ) {
            $id = (int) $raw;
            return $this->postExistsPublished( 'sp_team', $id ) ? $id : null;
        }

        $ids = $this->findPostIdsByTitle( 'sp_team', $raw );

        return 1 === count( $ids ) ? $ids[0] : null;
    }

    private function postExistsPublished( string $postType, int $id ): bool {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}posts WHERE ID = %d AND post_type = %s AND post_status = 'publish'",
                $id,
                $postType
            )
        );

        $this->assertScalarReadSucceeded( 'postExistsPublished', [ 'post_type' => $postType, 'id' => $id ] );

        return null !== $count && (int) $count > 0;
    }

    /**
     * @return array<int, int>
     */
    private function findPostIdsByTitle( string $postType, string $title ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID FROM {$p}posts WHERE post_type = %s AND post_status = 'publish' AND post_title = %s",
                $postType,
                $title
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'findPostIdsByTitle', [ 'post_type' => $postType ] );

        return array_map( static fn ( array $r ): int => (int) $r['ID'], $rows );
    }

    private function teamLabel( int $teamId ): string {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $title = $wpdb->get_var(
            $wpdb->prepare( "SELECT post_title FROM {$p}posts WHERE ID = %d", $teamId )
        );

        $title = null !== $title ? trim( (string) $title ) : '';

        return '' !== $title ? $title : 'Equipo #' . $teamId;
    }

    /**
     * Same `sp_season` taxonomy join `Plazas\CandidatosResolver::playerIdsRegistradosEnTemporada()`
     * uses, restricted to `$playerIds` instead of every registered player —
     * this importer only ever needs to know about the titulares the CSV
     * actually named.
     *
     * @param array<int, int> $playerIds
     * @return array<int, int> The subset of $playerIds that IS registered.
     */
    private function playerIdsRegistradosEnTemporada( int $seasonId, array $playerIds ): array {
        if ( [] === $playerIds ) {
            return [];
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $placeholders = implode( ', ', array_fill( 0, count( $playerIds ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id
                   FROM {$p}posts posts
                   INNER JOIN {$p}term_relationships tr ON tr.object_id = posts.ID
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND tt.taxonomy = 'sp_season'
                    AND tt.term_id = %d
                    AND posts.ID IN ({$placeholders})",
                array_merge( [ $seasonId ], $playerIds )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'playerIdsRegistradosEnTemporada', [ 'season_id' => $seasonId ] );

        return array_map( static fn ( array $r ): int => (int) $r['id'], $rows );
    }

    private function validateFechaDesde( int $fechaDesdeId, int $seasonId ): ?string {
        $fecha = $this->fechaRepository->findById( $fechaDesdeId );

        if ( null === $fecha ) {
            return "La fecha de inicio (fecha_desde_id={$fechaDesdeId}) no existe.";
        }

        if ( (int) $fecha['season_id'] !== $seasonId ) {
            return "La fecha de inicio (fecha_desde_id={$fechaDesdeId}) pertenece a la temporada "
                . (int) $fecha['season_id'] . ", no a la temporada {$seasonId}.";
        }

        return null;
    }

    /**
     * The `get_var()` analogue of `Support\ChecksReads::assertReadSucceeded()`
     * — see `Calendario\FechaRepository::assertRowReadSucceeded()`'s own
     * docblock for why a scalar read checks ONLY `$wpdb->last_error`, never
     * the nullness of the value, to avoid turning a genuine "zero rows
     * match" into a false failure.
     *
     * @param array<string, mixed> $contexto
     * @throws \RuntimeException
     */
    private function assertScalarReadSucceeded( string $operacion, array $contexto ): void {
        $lastError = (string) ( $this->wpdb->last_error ?? '' );

        if ( '' === $lastError ) {
            return;
        }

        $this->eventLog->record( 'lectura.fallida', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new \RuntimeException(
            sprintf( 'PlazaImporter::%s(): the query failed at the wpdb level (%s).', $operacion, $lastError )
        );
    }
}
