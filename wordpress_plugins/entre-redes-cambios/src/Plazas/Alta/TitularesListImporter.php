<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Alta;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\PosicionResolver;
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
 * weeks later. `loadTeamStatuses()` / `loadPlayerStatuses()` below are
 * read-only by construction — nothing in this class ever calls
 * `wp_insert_post()`, and no method here accepts an `equipo`/player NAME as
 * anything other than a label for an error message (see
 * `TitularesListParser`'s own docblock, "an `equipo` column MAY also be
 * present").
 *
 * *** "DOES NOT EXIST" AND "EXISTS BUT ISN'T PUBLISHED" ARE DIFFERENT
 * PROBLEMS, NEVER COLLAPSED INTO ONE ***
 * A `team_id`/`titular_player_id` can fail to resolve THREE distinct ways,
 * and each one calls for a different operator action: no row with that id
 * at all (a typo in the CSV — fix the id), a row that exists but sits in
 * `draft`/`pending`/`trash`/etc (a real person or team, just not published
 * right now — restore or publish it, never re-type anything), or — players
 * only — a row that IS published but was never tagged into this season (a
 * registration gap, not an identity problem). Collapsing the second case
 * into "does not exist" would be actively misleading: it would send an
 * operator hunting for a typo in an id that is already correct. See
 * `loadTeamStatuses()` / `loadPlayerStatuses()` below — both return every
 * matching post's `post_status`, precisely so `planificar()` can tell these
 * apart and name the actual status in the error.
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
 *
 * *** A TEAM WITHOUT A GOALKEEPER CANNOT EXIST (0.1.13) ***
 * The process owner's invariant is explicit: every team has exactly one
 * goalkeeper, so exactly one of its 11 plazas IS the goalkeeper's plaza — a
 * team without one cannot exist (see `Migrations\InitialSchema`'s own class
 * docblock on `cambios_plaza.es_arco` for the full reasoning). The moment to
 * enforce that is HERE, at roster creation, never after: `planificar()`
 * resolves every row's `titular_player_id` `sp_position` in ONE batched
 * `PosicionResolver::resolverParaIds()` call (same batching discipline as
 * every other population-wide lookup in this plugin) and refuses a team
 * whose 11 titulares include zero — or more than one — resolving as the
 * titular goalkeeper (`PosicionResolver::esPosicionDelArqueroTitular()`,
 * term 3 ONLY), mirroring the existing `es_capitan` "exactly one" check
 * immediately above it. This is a VALIDATION only; the actual `es_arco`
 * value each opened plaza gets is derived and persisted independently, at
 * write time, by `Plazas\PlazaRepository::doOpenPlaza()` — see that
 * method's own docblock. Both read the SAME underlying `sp_position` data,
 * so as long as nobody edits a titular's position between `planificar()` and
 * `aplicarPlazas()` (an admin-run, single-operator workflow — not a
 * concurrent one), the two can never disagree.
 */
class TitularesListImporter {

    use ChecksReads;
    use OpensTransactions;

    private \wpdb $wpdb;
    private PlazaRepository $plazaRepository;
    private CapitanRepository $capitanRepository;
    private FechaRepository $fechaRepository;
    private EventLog $eventLog;
    private PosicionResolver $posicionResolver;

    /**
     * @param PosicionResolver|null $posicionResolver Defaults to a plain
     *        instance — overridable in tests, same pattern as every other
     *        optional collaborator in this plugin. See class docblock,
     *        "A TEAM WITHOUT A GOALKEEPER CANNOT EXIST".
     */
    public function __construct(
        \wpdb $wpdb,
        PlazaRepository $plazaRepository,
        CapitanRepository $capitanRepository,
        FechaRepository $fechaRepository,
        EventLog $eventLog,
        ?PosicionResolver $posicionResolver = null
    ) {
        $this->wpdb              = $wpdb;
        $this->plazaRepository   = $plazaRepository;
        $this->capitanRepository = $capitanRepository;
        $this->fechaRepository   = $fechaRepository;
        $this->eventLog          = $eventLog;
        $this->posicionResolver  = $posicionResolver ?? new PosicionResolver( $eventLog );
    }

    /**
     * Resolves and validates the ENTIRE backfill — every row's `team_id` /
     * `titular_player_id` / `puntaje_techo` / `es_capitan`, against
     * `$seasonId` / `$fechaDesdeId`. READ-ONLY: never writes a single row.
     * Always returns a plan; a plan with `hasErrors()` true is the honest,
     * complete report of everything wrong, never a partial one that stopped
     * at the first problem — see `TitularesListPlan`'s own class docblock.
     *
     * *** A FAILED POSITION RESOLUTION MUST ABORT, NEVER REPORT "NO
     * GOALKEEPER" FOR EVERY TEAM (0.1.14) *** `PosicionResolver::resolverParaIds()`
     * now throws rather than silently returning `SIN_POSICION` for every
     * `$titularIds` — see that class's own docblock. This method does NOT
     * catch that exception: letting it propagate straight to the CLI caller
     * (`tools/importar-titulares.php`) is the correct behavior here, because
     * the alternative — the OLD silent-degradation behavior — would have made
     * `$arcoCount` read `0` for every single team in the file, producing a
     * plan whose `errors` falsely claim every team has no goalkeeper. A hard
     * stop with "could not read sp_position, try again" is honest; a plan
     * full of misleading per-team errors is not.
     *
     * @param array<int, array{line:int, team_id:int, equipo:string, titular_player_id:int, puntaje_raw:string, es_capitan:bool}> $rows
     *        `TitularesListParser::parse()`'s own `rows` output.
     * @param array<int, string> $parserErrors Same parser's `errors`.
     * @throws \RuntimeException When PosicionResolver::resolverParaIds()
     *         could not resolve the titulares' positions — see above.
     */
    public function planificar( array $rows, array $parserErrors, int $seasonId, int $fechaDesdeId ): TitularesListPlan {
        $errors   = $parserErrors;
        $warnings = [];

        $fechaError = $this->validateFechaDesde( $fechaDesdeId, $seasonId );
        if ( null !== $fechaError ) {
            $errors[] = $fechaError;
        }

        $teamStatuses              = $this->loadTeamStatuses();
        $playerStatuses            = $this->loadPlayerStatuses();
        $playersRegisteredInSeason = $this->loadPlayersRegisteredInSeason( $seasonId );

        // See class docblock, "A TEAM WITHOUT A GOALKEEPER CANNOT EXIST" —
        // ONE batched call over every row's titular_player_id, regardless of
        // whether that id later turns out to be invalid (an unresolved id
        // simply resolves to PosicionResolver::SIN_POSICION, which is never
        // "Arquero" — harmless).
        $titularIds = array_values( array_unique( array_map(
            static fn ( array $row ): int => $row['titular_player_id'],
            $rows
        ) ) );
        $posiciones = $this->posicionResolver->resolverParaIds( $titularIds );

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

            $teamStatus = $teamStatuses[ $teamId ] ?? null;

            if ( null === $teamStatus ) {
                $errors[]        = "Equipo {$label}: team_id {$teamId} no existe como sp_team.";
                $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => 'con_errores', 'row_count' => count( $teamRows ) ];
                continue;
            }

            if ( 'publish' !== $teamStatus ) {
                $errors[]        = "Equipo {$label}: team_id {$teamId} existe como sp_team pero esta en estado '"
                    . $this->statusLabel( $teamStatus ) . "' ({$teamStatus}), no publicado — si se borro o quedo sin publicar por error, restaurelo/publiquelo antes de reimportar.";
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

            // See class docblock, "A TEAM WITHOUT A GOALKEEPER CANNOT EXIST"
            // — same "exactly one" shape as the es_capitan check just above.
            $arcoCount = 0;
            foreach ( $teamRows as $row ) {
                $posicion = $posiciones[ $row['titular_player_id'] ] ?? PosicionResolver::SIN_POSICION;
                if ( PosicionResolver::esPosicionDelArqueroTitular( $posicion ) ) {
                    ++$arcoCount;
                }
            }

            if ( 0 === $arcoCount ) {
                $errors[] = "Equipo {$label} (team_id {$teamId}): ninguna de sus filas corresponde a un arquero titular (sp_position 'Arquero') — un equipo sin plaza de arquero no puede existir.";
            } elseif ( $arcoCount > 1 ) {
                $errors[] = "Equipo {$label} (team_id {$teamId}): {$arcoCount} filas corresponden a arquero titular (sp_position 'Arquero'), debe ser exactamente 1.";
            }

            $resolved   = [];
            $rowHasError = false;

            foreach ( $teamRows as $row ) {
                $playerId     = $row['titular_player_id'];
                $playerStatus = $playerStatuses[ $playerId ] ?? null;

                if ( null === $playerStatus ) {
                    $errors[]    = "Equipo {$label}, fila {$row['line']}: titular_player_id {$playerId} no existe como sp_player.";
                    $rowHasError = true;
                    continue;
                }

                if ( 'publish' !== $playerStatus ) {
                    $errors[]    = "Equipo {$label}, fila {$row['line']}: el jugador {$playerId} existe como sp_player pero esta en estado '"
                        . $this->statusLabel( $playerStatus ) . "' ({$playerStatus}), no publicado — si se borro o quedo sin publicar por error, restaurelo/publiquelo antes de reimportar.";
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
     * @return array<int, string> Every `sp_team` post id mapped to its OWN
     *         `post_status`, regardless of what that status is — a trashed
     *         or draft team is still a real post, but this importer must
     *         never mistake "exists, just not published" for either "does
     *         not exist" or "may as well be published" (see class docblock,
     *         "'DOES NOT EXIST' AND 'EXISTS BUT ISN'T PUBLISHED' ARE
     *         DIFFERENT PROBLEMS"). `planificar()` is the ONLY place that
     *         reads this map's values; a missing key means "no such id",
     *         present with `'publish'` means usable, and present with
     *         anything else names the exact status in the error.
     */
    private function loadTeamStatuses(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT ID AS id, post_status AS status FROM {$p}posts WHERE post_type = 'sp_team'",
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadTeamStatuses', [] );

        $statuses = [];
        foreach ( $rows as $row ) {
            $statuses[ (int) $row['id'] ] = (string) $row['status'];
        }

        return $statuses;
    }

    /**
     * @return array<int, string> Every `sp_player` post id mapped to its OWN
     *         `post_status` — see `loadTeamStatuses()`'s docblock for why
     *         this is a status map, never a bare existence set.
     */
    private function loadPlayerStatuses(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT ID AS id, post_status AS status FROM {$p}posts WHERE post_type = 'sp_player'",
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadPlayerStatuses', [] );

        $statuses = [];
        foreach ( $rows as $row ) {
            $statuses[ (int) $row['id'] ] = (string) $row['status'];
        }

        return $statuses;
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
     * WordPress's own `post_status` values, translated for an operator who
     * is not expected to know WordPress internals — used ONLY inside an
     * error message (see `loadTeamStatuses()` / `loadPlayerStatuses()`'s own
     * docblocks); the raw status is always printed alongside it too, so
     * nothing is lost for whoever DOES want the literal value. A status not
     * in this list (there are a few obscure ones, e.g. plugin-defined custom
     * statuses) falls back to printing the raw value twice rather than
     * guessing a translation.
     */
    private const STATUS_LABELS_ES = [
        'draft'      => 'borrador',
        'pending'    => 'pendiente',
        'future'     => 'programado',
        'private'    => 'privado',
        'trash'      => 'papelera',
        'auto-draft' => 'borrador automatico',
    ];

    private function statusLabel( string $status ): string {
        return self::STATUS_LABELS_ES[ $status ] ?? $status;
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
