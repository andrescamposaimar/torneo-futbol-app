<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Alta;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Support\ChecksReads;
use EntreRedes\Cambios\Support\OpensTransactions;

/**
 * Backfills a season's `cambios_plaza` roster AND `cambios_capitan`
 * designations from a plain CSV of already-resolved ids — replacing this
 * plugin's previous spreadsheet-based importer (removed entirely, together
 * with the whole namespace that held it and the `.xlsx` parsing it depended
 * on — see this feature's own task brief for why).
 *
 * *** WHY THE SPREADSHEET PARSER IS GONE, NOT JUST REPLACED ***
 * That previous importer existed to bridge TWO sources that did not agree on
 * identity: an election spreadsheet that named titulares by TEXT, and
 * WordPress, which only knows them by id — so most of that class's own
 * complexity (a token-based name matcher, a text normalizer, a team-alias
 * map, and an operator-supplied override file) was entirely about resolving a name to an id well
 * enough to trust it. That cross-reference is now DONE: the process owner
 * settled which 330 players are this season's official titulares, and
 * handed that list over already resolved to WordPress `sp_player`/`sp_team`
 * ids. There is no name to match any more, so there is nothing left for a
 * name matcher to do. The spreadsheet itself was never a data source this
 * plugin should keep depending on — it was a one-off tool the process owner
 * used to arrive at the answer, not the answer's permanent home — so this
 * class reads only the plain CSV that IS the answer, never the spreadsheet
 * that produced it.
 *
 * *** THE ONE INPUT: A PLAIN LIST, ALREADY RESOLVED ***
 * `TitularesListParser::parse()` reads the CSV's FORMAT (four required
 * columns, all four cells present and well-shaped per row — see that
 * class's own docblock). Everything this class validates below is a
 * DATABASE-BACKED question the parser cannot answer on its own: does
 * `team_id` exist as a real `sp_team`, does `titular_player_id` exist as a
 * real `sp_player` AND is that player registered in THIS season, is
 * `puntaje_techo` actually one of the 9 valid puntajes (`Plazas\Puntaje`
 * enforces the value set; this class only hands the raw string to it), does
 * exactly one row per team carry `es_capitan`, and does the same player
 * appear twice anywhere in the file. `planificar()` performs ONLY reads —
 * see class docblock "VALIDATE THE WHOLE FILE FIRST".
 *
 * *** NEVER CREATE A PLAYER OR A TEAM — AN UNRESOLVED ID IS ALWAYS A HARD
 * ERROR, NEVER A REASON TO INSERT ONE ***
 * Both a `sp_player` and its `sp_team` already exist by the time this
 * importer ever runs: a new player is registered by hand, by the league's
 * own operator, at inscription time — BEFORE the election this CSV's data
 * comes from even happens. SportsPress's own player importer
 * (`includes/admin/importers/class-sp-player-importer.php`) takes the
 * opposite approach for its own CSV format: it calls `wp_insert_post()` for
 * any name it cannot match to an existing player. Copying that here would
 * be WORSE than simply failing: an id typo, or a row that points at the
 * wrong season's player by mistake, would silently fork a real person's
 * playing history into a brand-new, empty-history post — indistinguishable
 * from a genuine new player until someone notices a name they do not
 * recognize, or a stats page quietly stops adding up. A hard, loud refusal
 * ("team_id 99999 does not exist") costs an operator thirty seconds to fix
 * a typo; a silently created duplicate costs a data-integrity investigation
 * weeks later. `loadExistingTeamIds()` / `loadExistingPlayerIds()` below are
 * read-only by construction — nothing in this class ever calls
 * `wp_insert_post()`, and no method here accepts an `equipo`/player NAME as
 * anything other than a label for an error message (see
 * `TitularesListParser`'s own docblock, "an `equipo` column MAY also be
 * present").
 *
 * *** VALIDATE THE WHOLE FILE BEFORE WRITING A SINGLE ROW ***
 * `planificar()` performs only reads; `aplicarPlazas()` / `aplicarCapitanes()`
 * both refuse outright when `$plan->hasErrors()`, and every plaza opened by
 * `aplicarPlazas()` is written as ONE atomic transaction (see
 * `PlazaRepository::openPlazaWithinTransaction()`'s own docblock for why a
 * nested transaction is unsafe to rely on). A partially loaded roster is
 * worse than none: the captain screens would show some teams a plausible,
 * wrong squad, and nobody would notice until a captain requested a change
 * against a plaza that does not exist.
 *
 * *** IDEMPOTENT ON (season_id, team_id, titular_player_id) ***
 * Exactly the same key the previous importer used — a team
 * already carrying the exact same 11 (or however many) titulares, at the
 * exact same puntaje, is reported `ya_importado` and contributes nothing to
 * `rowsToOpen()`; re-running `--apply` after a successful load opens
 * nothing new. A team whose EXISTING open plazas do not match what this
 * file describes is `conflicto` — a hard error naming the team, never
 * silently resolved by picking one side.
 *
 * *** A ROW COUNT OTHER THAN 11 IS A WARNING, NEVER AN ERROR ***
 * See `TitularesListPlan`'s own class docblock for the full reasoning: this
 * importer is also meant to re-run mid-season, when a real squad can
 * legitimately differ from exactly 11 rows.
 *
 * `season_id` and the genesis `fecha_desde_id` are never CSV columns —
 * both are identical for every row of one load (the whole file describes
 * ONE conformación moment, for one season), so both are supplied once, by
 * the CLI caller, as arguments to `planificar()` / `aplicarPlazas()` — see
 * `tools/importar-titulares.php`.
 */
class TitularesListImporter {

    use ChecksReads;
    use OpensTransactions;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private CapitanRepository $capitanRepository;
    private FechaRepository $fechaRepository;
    private EventLog $eventLog;

    public function __construct(
        \wpdb $wpdb,
        PlazaRepository $plazaRepository,
        CapitanRepository $capitanRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog
    ) {
        $this->wpdb              = $wpdb;
        $this->plazaRepository   = $plazaRepository;
        $this->capitanRepository = $capitanRepository;
        $this->fechaRepository   = $fechaRepository;
        $this->eventLog          = $eventLog;
    }

    /**
     * Resolves and validates the ENTIRE backfill — every row's `team_id` /
     * `titular_player_id` / `puntaje_techo` / `es_capitan`, against
     * `$seasonId` / `$fechaDesdeId`. READ-ONLY: never writes a single row.
     * Always returns a plan; a plan with `hasErrors()` true is the honest,
     * complete report of everything wrong, never a partial one that stopped
     * at the first problem — see `TitularesListPlan`'s own class docblock.
     *
     * @param array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}> $rows
     *        `TitularesListParser::parse()`'s own `rows` output.
     * @param array<int, string> $parserErrors Same parser's `errors`.
     */
    public function planificar( array $rows, array $parserErrors, int $seasonId, int $fechaDesdeId ): TitularesListPlan {
        $errors   = $parserErrors;
        $warnings = [];

        $fechaError = $this->validateFechaDesde( $fechaDesdeId, $seasonId );
        if ( null !== $fechaError ) {
            $errors[] = $fechaError;
        }

        $existingTeamIds       = $this->loadExistingTeamIds();
        $existingPlayerIds     = $this->loadExistingPlayerIds();
        $playersRegisteredInSeason = $this->loadPlayersRegisteredInSeason( $seasonId );

        // A duplicate titular anywhere in the file is a contradiction no
        // single row can be blamed for alone — collected up front, across
        // every row, rather than re-derived per team below.
        $this->collectDuplicatePlayers( $rows, $errors );

        $byTeam = [];
        foreach ( $rows as $row ) {
            $byTeam[ $row['team_id'] ][] = $row;
        }

        $teamSummaries = [];
        $rowsToOpen    = [];
        $capitanes     = [];

        foreach ( $byTeam as $teamId => $teamRows ) {
            $label = $this->teamLabel( $teamId, $teamRows );

            if ( ! isset( $existingTeamIds[ $teamId ] ) ) {
                $errors[]        = "Equipo {$label}: team_id {$teamId} no existe como sp_team.";
                $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => 'con_errores', 'row_count' => count( $teamRows ) ];
                continue;
            }

            if ( 11 !== count( $teamRows ) ) {
                $warnings[] = "Equipo {$label} (team_id {$teamId}): tiene " . count( $teamRows ) . ' fila(s), no 11.';
            }

            $capitanCount = 0;
            foreach ( $teamRows as $row ) {
                if ( $row['es_capitan'] ) {
                    ++$capitanCount;
                }
            }

            if ( 0 === $capitanCount ) {
                $errors[] = "Equipo {$label} (team_id {$teamId}): ninguna fila tiene es_capitan=1.";
            } elseif ( $capitanCount > 1 ) {
                $errors[] = "Equipo {$label} (team_id {$teamId}): {$capitanCount} filas tienen es_capitan=1, debe ser exactamente 1.";
            }

            $resolved   = [];
            $rowHasError = false;

            foreach ( $teamRows as $row ) {
                $playerId = $row['titular_player_id'];

                if ( ! isset( $existingPlayerIds[ $playerId ] ) ) {
                    $errors[]    = "Equipo {$label}, fila {$row['line']}: titular_player_id {$playerId} no existe como sp_player.";
                    $rowHasError = true;
                    continue;
                }

                if ( ! isset( $playersRegisteredInSeason[ $playerId ] ) ) {
                    $errors[]    = "Equipo {$label}, fila {$row['line']}: el jugador {$playerId} no esta registrado en la temporada {$seasonId}.";
                    $rowHasError = true;
                    continue;
                }

                try {
                    $puntaje = Puntaje::fromDecimal( $row['puntaje_raw'] );
                } catch ( \InvalidArgumentException $e ) {
                    $errors[]    = "Equipo {$label}, fila {$row['line']}: {$e->getMessage()}";
                    $rowHasError = true;
                    continue;
                }

                $resolved[] = [ 'player_id' => $playerId, 'puntaje' => $puntaje, 'es_capitan' => $row['es_capitan'] ];
            }

            if ( $rowHasError ) {
                $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => 'con_errores', 'row_count' => count( $teamRows ) ];
                continue;
            }

            [ $estado, $conflictError ] = $this->compareAgainstExisting( $seasonId, $teamId, $label, $resolved );

            if ( null !== $conflictError ) {
                $errors[] = $conflictError;
            }

            if ( 'a_importar' === $estado ) {
                foreach ( $resolved as $r ) {
                    $rowsToOpen[] = [
                        'team_id'           => $teamId,
                        'team_label'        => $label,
                        'titular_player_id' => $r['player_id'],
                        'puntaje'           => $r['puntaje'],
                    ];
                }
            }

            $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => $estado, 'row_count' => count( $teamRows ) ];

            foreach ( $resolved as $r ) {
                if ( $r['es_capitan'] ) {
                    $capitanes[] = [ 'team_id' => $teamId, 'team_label' => $label, 'player_id' => $r['player_id'] ];
                }
            }
        }

        if ( [] !== $errors ) {
            $rowsToOpen = [];
            $capitanes  = [];
        }

        return new TitularesListPlan( $errors, $warnings, $teamSummaries, $rowsToOpen, $capitanes );
    }

    /**
     * Opens every plaza `$plan->rowsToOpen()` describes, as ONE atomic
     * transaction — see class docblock, "VALIDATE THE WHOLE FILE BEFORE
     * WRITING A SINGLE ROW".
     *
     * @throws \LogicException When `$plan->hasErrors()` is true.
     * @return int The number of plazas actually opened.
     */
    public function aplicarPlazas( TitularesListPlan $plan, int $seasonId, int $fechaDesdeId, string $now ): int {
        if ( $plan->hasErrors() ) {
            throw new \LogicException(
                'TitularesListImporter::aplicarPlazas(): refusing to write a plan that has validation errors. '
                . 'Call planificar() again after fixing the input, and only call aplicarPlazas() when hasErrors() is false.'
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
                $plazaId  = $this->plazaRepository->openPlazaWithinTransaction(
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
                'origen'            => 'importar-titulares',
            ] );
        }

        $this->eventLog->record( 'titulares.plazas_importadas', [
            'season_id'       => $seasonId,
            'fecha_desde_id'  => $fechaDesdeId,
            'plazas_abiertas' => count( $opened ),
        ] );

        return count( $opened );
    }

    /**
     * Designates every captain `$plan->capitanes()` describes via
     * `CapitanRepository::designateCapitan()` — never writes to
     * `cambios_capitan` directly. Independent of `aplicarPlazas()`: a caller
     * may run this without ever calling `aplicarPlazas()`, and vice versa —
     * see `TitularesListPlan`'s class docblock, "ONE PLAN, TWO INDEPENDENT
     * WRITES". Each designation is independently idempotent (see
     * `designateCapitan()`'s own docblock); this method does not wrap them in
     * one shared transaction — a captain, unlike a plaza, is not part of an
     * all-or-nothing roster.
     *
     * @throws \LogicException When `$plan->hasErrors()` is true.
     * @return int The number of teams processed (designateCapitan() calls
     *         made) — includes idempotent no-ops (see that method).
     */
    public function aplicarCapitanes( TitularesListPlan $plan, int $seasonId, string $now ): int {
        if ( $plan->hasErrors() ) {
            throw new \LogicException(
                'TitularesListImporter::aplicarCapitanes(): refusing to write a plan that has validation errors. '
                . 'Call planificar() again after fixing the input, and only call aplicarCapitanes() when hasErrors() is false.'
            );
        }

        $capitanes = $plan->capitanes();

        foreach ( $capitanes as $c ) {
            $this->capitanRepository->designateCapitan( $seasonId, $c['team_id'], $c['player_id'], null, $now );
        }

        $this->eventLog->record( 'titulares.capitanes_importados', [
            'season_id' => $seasonId,
            'cantidad'  => count( $capitanes ),
        ] );

        return count( $capitanes );
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}> $rows
     * @param array<int, string> $errors
     */
    private function collectDuplicatePlayers( array $rows, array &$errors ): void {
        $lines = [];
        foreach ( $rows as $row ) {
            $lines[ $row['titular_player_id'] ][] = $row['line'];
        }

        foreach ( $lines as $playerId => $lineNumbers ) {
            if ( count( $lineNumbers ) > 1 ) {
                $errors[] = 'El jugador ' . $playerId . ' aparece ' . count( $lineNumbers )
                    . ' veces en el archivo (filas ' . implode( ', ', $lineNumbers ) . '), no puede ser titular de mas de una plaza.';
            }
        }
    }

    /**
     * @param array<int, array{player_id:int, puntaje:Puntaje, es_capitan:bool}> $resolved
     * @return array{0:string, 1:?string} [estado ('a_importar'|'ya_importado'|'conflicto'), error message or null]
     */
    private function compareAgainstExisting( int $seasonId, int $teamId, string $label, array $resolved ): array {
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

        $porTitular = [];
        foreach ( $resolved as $r ) {
            $porTitular[ $r['player_id'] ] = $r;
        }

        $sameTitulares = [] === array_diff_key( $existingByTitular, $porTitular )
            && [] === array_diff_key( $porTitular, $existingByTitular );

        if ( $sameTitulares ) {
            $matches = true;
            foreach ( $porTitular as $titularId => $r ) {
                if ( (int) $existingByTitular[ $titularId ]['puntaje_techo'] !== $r['puntaje']->halfPoints() ) {
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
            "Equipo {$label} (team_id={$teamId}) ya tiene " . count( $existing )
                . " plaza(s) abierta(s) para la temporada {$seasonId}, y el archivo agregaria o modificaria mas — "
                . 'revise manualmente antes de reimportar.',
        ];
    }

    // -------------------------------------------------------------------------
    // Raw SQL lookups (never WP_Query — see Plazas\CandidatosResolver's own
    // class docblock for why this whole plugin avoids it)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, true> Every `sp_team` post id, regardless of
     *         `post_status` — a trashed or draft team is still a real post
     *         this backfill must not treat as "does not exist"; the
     *         distinction this importer actually cares about is "does a row
     *         exist at all", never the WordPress publish workflow state of
     *         that row.
     */
    private function loadExistingTeamIds(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT ID AS id FROM {$p}posts WHERE post_type = 'sp_team'",
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadExistingTeamIds', [] );

        $set = [];
        foreach ( $rows as $row ) {
            $set[ (int) $row['id'] ] = true;
        }

        return $set;
    }

    /**
     * @return array<int, true> Every `sp_player` post id, regardless of
     *         `post_status` — see `loadExistingTeamIds()`'s docblock for why.
     */
    private function loadExistingPlayerIds(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT ID AS id FROM {$p}posts WHERE post_type = 'sp_player'",
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadExistingPlayerIds', [] );

        $set = [];
        foreach ( $rows as $row ) {
            $set[ (int) $row['id'] ] = true;
        }

        return $set;
    }

    /**
     * @return array<int, true> Every published `sp_player` post id carrying
     *         the `sp_season` taxonomy term `$seasonId` — same query the
     *         previous importer's own equivalent lookup used (now removed
     *         together with that whole namespace), kept
     *         here as a set rather than an id-to-title map since this class
     *         never needs the title (see class docblock — an id that fails
     *         this check is always a hard error, never a name to display).
     */
    private function loadPlayersRegisteredInSeason( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id
                   FROM {$p}posts posts
                   INNER JOIN {$p}term_relationships tr ON tr.object_id = posts.ID
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND tt.taxonomy = 'sp_season'
                    AND tt.term_id = %d",
                $seasonId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadPlayersRegisteredInSeason', [ 'season_id' => $seasonId ] );

        $set = [];
        foreach ( $rows as $row ) {
            $set[ (int) $row['id'] ] = true;
        }

        return $set;
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
     * @param array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}> $teamRows
     */
    private function teamLabel( int $teamId, array $teamRows ): string {
        foreach ( $teamRows as $row ) {
            if ( '' !== $row['equipo'] ) {
                return "'{$row['equipo']}' (team_id={$teamId})";
            }
        }

        return "(team_id={$teamId})";
    }
}
