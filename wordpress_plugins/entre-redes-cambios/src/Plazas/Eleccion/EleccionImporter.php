<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Plazas\Eleccion;

use EntreRedes\Cambios\Calendario\FechaRepository;
use EntreRedes\Cambios\Capitania\CapitanRepository;
use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Plazas\PlazaRepository;
use EntreRedes\Cambios\Plazas\Puntaje;
use EntreRedes\Cambios\Support\ChecksReads;
use EntreRedes\Cambios\Support\OpensTransactions;

/**
 * Backfills a season's `cambios_plaza` roster AND `cambios_capitan`
 * designations from the March 2026 election spreadsheet — replacing
 * `Plazas\PlazaImporter`/`Plazas\PlazaImportCsvParser` (removed; see this
 * feature's own task brief for why hand-transcribing a CSV was the wrong
 * design when the real data already exists in two places: the election
 * spreadsheet, and WordPress itself). `tools/importar-eleccion.php` reads
 * the spreadsheet via `Plazas\Eleccion\EleccionSheetParser` and this
 * plugin's own WordPress bootstrap, and hands both to this class; this class
 * never touches a file itself.
 *
 * *** THE TWO SOURCES, AND WHAT EACH ONE IS TRUSTED FOR ***
 * The "x Equipo" sheet (`EleccionSheetParser::parseEquipoSheet()`) names,
 * per team, the 11 official titulares for the WHOLE year and which one is
 * captain (`Vuelta` = `CAP`). The "Titulares eleccion con datos" sheet
 * (`EleccionSheetParser::parseTitularesSheet()`) gives each titular's
 * election-time puntaje — verified against a 90-player `sp_metrics` sample
 * with zero differences (see task brief) — which is the correct SNAPSHOT
 * CEILING for their plaza (see `Plazas\Puntaje` for the ×2 half-points
 * encoding `openPlazaWithinTransaction()` expects). Neither sheet's `id`
 * column is trusted for anything: a titular is resolved to a WordPress
 * `sp_player` post id by NAME (`NombreMatcher`), never by an id the
 * spreadsheet itself supplies (see class docblock on `NombreMatcher` for
 * why exact-string equality is not enough, and "THE CANDIDATE POOL" below
 * for what pool a name is matched against).
 *
 * *** THE CANDIDATE POOL: A TEAM'S OWN WORDPRESS ROSTER, THIS SEASON ***
 * A titular's name is matched only against `sp_player` posts that are BOTH
 * registered in `$seasonId` (the `sp_season` taxonomy — same convention as
 * `Plazas\CandidatosResolver`) AND currently associated with that SAME team
 * in WordPress via the `sp_current_team` postmeta (SportsPress's own roster
 * meta key — see `equipoRoster()`). Scoping the pool this tightly is what
 * keeps the matcher's ambiguity low: matching a name against the WHOLE
 * season's ~330 players would multiply false ties. `resolveTitularId()`
 * never widens this pool automatically — a name that matches nothing in the
 * team's own roster is unresolved (see "THE OVERRIDE FILE" below), never
 * silently searched for elsewhere.
 *
 * *** THE OVERRIDE FILE: NEVER GUESS, NEVER SKIP A PLAZA SILENTLY ***
 * A handful of titulares do not resolve automatically no matter how the
 * pool is scoped — the name on the spreadsheet and the name in WordPress
 * differ by more than this matcher is willing to bridge on its own (see
 * `NombreMatcher`'s class docblock for the token-overlap rule, and
 * `Plazas\Eleccion\EleccionOverrides` for the override file's format). A
 * titular with NO match and NO override entry is a HARD ERROR naming the
 * team and the vuelta — `planificar()` NEVER opens 10 of 11 plazas for a
 * team and calls it done; a plausible-but-incomplete roster is worse than an
 * explicit refusal (same discipline `Plazas\PlazaImporter` established, see
 * its own former class docblock in version control history).
 *
 * *** NO `tipo` COLUMN — EVERY PLAZA IS A TITULAR PLAZA ***
 * `cambios_plaza.tipo` (a `campo`/`suplente` distinction the tournament
 * reglamento does not actually make) was removed from the schema and from
 * `PlazaRepository::openPlaza()` / `::openPlazaWithinTransaction()` in
 * `refactor/cambios-plaza-sin-tipo` — every one of the 11 official plazas a
 * team has IS a titular plaza; this importer never reads, writes, or
 * branches on any such distinction.
 *
 * *** WHAT THIS IMPORTER DELIBERATELY DOES NOT TOUCH ***
 * Who currently OCCUPIES each plaza (the `cambios_ocupacion` chain a
 * captain-facing screen actually reads) is out of scope — see
 * `reportarReemplazos()`'s own docblock for the read-only diagnostic this
 * class offers INSTEAD, and `ReemplazoReport`'s class docblock for exactly
 * what it does and does not claim.
 */
class EleccionImporter {

    use ChecksReads;
    use OpensTransactions;

    /** `sp_position` term id — see entre-redes-api's own player endpoint. */
    private const TERM_REEMPLAZO_ALTA = 155;

    /** `sp_position` term id — see entre-redes-api's own player endpoint. */
    private const TERM_REEMPLAZO_BAJA = 156;

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
     * Resolves and validates the ENTIRE election import — every team's 11
     * titulares, their puntajes, and the captain — against `$seasonId` /
     * `$fechaDesdeId`. READ-ONLY, exactly like `Plazas\PlazaImporter`'s own
     * `planificar()` was: never writes a single row. Always returns a plan;
     * a plan with `hasErrors()` true is the honest, complete report of
     * everything wrong, never a partial one that stopped at the first
     * problem — see `EleccionPlazasPlan`'s own class docblock.
     *
     * @param array<int, array{equipo:string, line:int, titulares:array<string,string>}> $equipoTeams
     *        `EleccionSheetParser::parseEquipoSheet()`'s own `teams` output.
     * @param array<int, string> $equipoParserErrors Same parser's `errors`.
     * @param array<string, string> $puntajes `EleccionSheetParser::parseTitularesSheet()`'s
     *        own `puntajes` output (normalized name => raw puntaje string).
     * @param array<int, string> $puntajeParserErrors Same parser's `errors`.
     * @param array<string, int> $overrides `EleccionOverrides::parse()`'s output.
     */
    public function planificar(
        array $equipoTeams,
        array $equipoParserErrors,
        array $puntajes,
        array $puntajeParserErrors,
        array $overrides,
        int $seasonId,
        int $fechaDesdeId
    ): EleccionPlazasPlan {
        $errors = array_merge( $equipoParserErrors, $puntajeParserErrors );

        $fechaError = $this->validateFechaDesde( $fechaDesdeId, $seasonId );
        if ( null !== $fechaError ) {
            $errors[] = $fechaError;
        }

        $seasonPlayers  = $this->loadJugadoresRegistrados( $seasonId );
        $publishedTeams = $this->loadEquiposPublicados();

        $teamSummaries           = [];
        $rowsToOpen              = [];
        $capitanes               = [];
        $officialTitularesByTeam = [];

        foreach ( $equipoTeams as $team ) {
            $rawEquipo = (string) $team['equipo'];
            $label     = '' !== trim( $rawEquipo ) ? $rawEquipo : '(sin nombre, linea ' . $team['line'] . ')';

            $teamId = $this->resolveEquipoId( $rawEquipo, $publishedTeams, $errors, (int) $team['line'] );

            if ( null === $teamId ) {
                $teamSummaries[] = [ 'team_id' => 0, 'team_label' => $label, 'estado' => 'con_errores', 'titular_count' => 0 ];
                continue;
            }

            $roster = $this->equipoRoster( $teamId );
            $pool   = array_intersect_key( $roster, $seasonPlayers );

            $resolved     = []; // vuelta => ['player_id'=>int,'puntaje'=>Puntaje]
            $rowHasError  = false;

            foreach ( $team['titulares'] as $vuelta => $nombreExcel ) {
                $playerId = $this->resolveTitularId( (string) $nombreExcel, $pool, $seasonPlayers, $overrides, $errors, $label, (string) $vuelta );

                if ( null === $playerId ) {
                    $rowHasError = true;
                    continue;
                }

                $normalized = TextNormalizer::normalize( (string) $nombreExcel );
                $puntajeRaw = $puntajes[ $normalized ] ?? null;

                if ( null === $puntajeRaw ) {
                    $errors[]    = "Equipo '{$label}', vuelta {$vuelta} ('{$nombreExcel}'): no se encontro puntaje en la hoja 'Titulares eleccion con datos'.";
                    $rowHasError = true;
                    continue;
                }

                try {
                    $puntaje = Puntaje::fromDecimal( $puntajeRaw );
                } catch ( \InvalidArgumentException $e ) {
                    $errors[]    = "Equipo '{$label}', vuelta {$vuelta} ('{$nombreExcel}'): {$e->getMessage()}";
                    $rowHasError = true;
                    continue;
                }

                $resolved[ $vuelta ] = [ 'player_id' => $playerId, 'puntaje' => $puntaje ];
            }

            if ( $rowHasError ) {
                $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => 'con_errores', 'titular_count' => count( $resolved ) ];
                continue;
            }

            $playerIdsThisTeam = array_map( static fn ( array $r ): int => $r['player_id'], $resolved );

            if ( count( $playerIdsThisTeam ) !== count( array_unique( $playerIdsThisTeam ) ) ) {
                $errors[]        = "Equipo '{$label}': el mismo jugador aparece como titular en mas de una vuelta.";
                $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => 'con_errores', 'titular_count' => count( $resolved ) ];
                continue;
            }

            $officialTitularesByTeam[ $teamId ] = array_values( $playerIdsThisTeam );

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

            $teamSummaries[] = [ 'team_id' => $teamId, 'team_label' => $label, 'estado' => $estado, 'titular_count' => count( $resolved ) ];

            $capId = $resolved['CAP']['player_id'] ?? null;
            if ( null !== $capId ) {
                $capitanes[] = [ 'team_id' => $teamId, 'team_label' => $label, 'player_id' => $capId ];
            }
        }

        if ( [] !== $errors ) {
            $rowsToOpen = [];
            $capitanes  = [];
        }

        return new EleccionPlazasPlan( $errors, [], $teamSummaries, $rowsToOpen, $capitanes, $officialTitularesByTeam );
    }

    /**
     * Opens every plaza `$plan->rowsToOpen()` describes, as ONE atomic
     * transaction — same "half-applied is worse than none" discipline
     * `Plazas\PlazaImporter::aplicar()` established (see its own former
     * class docblock in version control history, and
     * `PlazaRepository::openPlazaWithinTransaction()`'s docblock for why a
     * nested transaction is unsafe).
     *
     * @throws \LogicException When `$plan->hasErrors()` is true.
     * @return int The number of plazas actually opened.
     */
    public function aplicarPlazas( EleccionPlazasPlan $plan, int $seasonId, int $fechaDesdeId, string $now ): int {
        if ( $plan->hasErrors() ) {
            throw new \LogicException(
                'EleccionImporter::aplicarPlazas(): refusing to write a plan that has validation errors. '
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
                'origen'            => 'importar-eleccion',
            ] );
        }

        $this->eventLog->record( 'eleccion.plazas_importadas', [
            'season_id'       => $seasonId,
            'fecha_desde_id'  => $fechaDesdeId,
            'plazas_abiertas' => count( $opened ),
        ] );

        return count( $opened );
    }

    /**
     * Designates every captain `$plan->capitanes()` describes via
     * `CapitanRepository::designateCapitan()` — never writes to
     * `cambios_capitan` directly (see that class's own class docblock for
     * the identity model it defends). Independent of `aplicarPlazas()`: a
     * caller may run this without ever calling `aplicarPlazas()`, and
     * vice versa — see `EleccionPlazasPlan`'s class docblock, "ONE PLAN, TWO
     * INDEPENDENT WRITES". Each designation is independently idempotent
     * (see `designateCapitan()`'s own docblock); this method does not wrap
     * them in one shared transaction — a captain, unlike a plaza, is not
     * part of an all-or-nothing roster.
     *
     * @throws \LogicException When `$plan->hasErrors()` is true.
     * @return int The number of teams processed (designateCapitan() calls
     *         made) — includes idempotent no-ops (see that method).
     */
    public function aplicarCapitanes( EleccionPlazasPlan $plan, int $seasonId, string $now ): int {
        if ( $plan->hasErrors() ) {
            throw new \LogicException(
                'EleccionImporter::aplicarCapitanes(): refusing to write a plan that has validation errors. '
                . 'Call planificar() again after fixing the input, and only call aplicarCapitanes() when hasErrors() is false.'
            );
        }

        $capitanes = $plan->capitanes();

        foreach ( $capitanes as $c ) {
            $this->capitanRepository->designateCapitan( $seasonId, $c['team_id'], $c['player_id'], null, $now );
        }

        $this->eventLog->record( 'eleccion.capitanes_importados', [
            'season_id' => $seasonId,
            'cantidad'  => count( $capitanes ),
        ] );

        return count( $capitanes );
    }

    /**
     * READ-ONLY diagnostic — see `ReemplazoReport`'s own class docblock for
     * exactly what the two counts it compares mean and why comparing them
     * is as far as this importer goes (the replacement CHAIN itself is
     * deliberately out of scope — see this class's own docblock, "WHAT THIS
     * IMPORTER DELIBERATELY DOES NOT TOUCH"). Reads
     * `$plan->officialTitularesByTeam()`, so call this AFTER `planificar()`,
     * with either the same plan or a later one — never writes anything, and
     * may be called whether or not `$plan->hasErrors()` (a team with its own
     * resolution errors is simply omitted — see `ReemplazoReport::omitidos()`).
     */
    public function reportarReemplazos( EleccionPlazasPlan $plan ): ReemplazoReport {
        $officialByTeam = $plan->officialTitularesByTeam();

        $porEquipo = [];
        $extras    = [];
        $omitidos  = [];

        $seenTeamIds = [];

        foreach ( $plan->teamSummaries() as $summary ) {
            $teamId = (int) $summary['team_id'];

            if ( isset( $seenTeamIds[ $teamId ] ) && 0 !== $teamId ) {
                continue; // Same team already reported (should not happen, defensive).
            }
            $seenTeamIds[ $teamId ] = true;

            if ( ! isset( $officialByTeam[ $teamId ] ) ) {
                $omitidos[] = $summary['team_label'] . ' (no se pudieron resolver sus 11 titulares oficiales).';
                continue;
            }

            $titulares = $officialByTeam[ $teamId ];
            $roster    = $this->equipoRoster( $teamId );

            $allIds  = array_values( array_unique( array_merge( $titulares, array_keys( $roster ) ) ) );
            $bajaSet = $this->positionFlags( $allIds, self::TERM_REEMPLAZO_BAJA );
            $altaSet = $this->positionFlags( $allIds, self::TERM_REEMPLAZO_ALTA );

            $titularSet = array_flip( $titulares );

            $titularesConBaja = 0;
            foreach ( $titulares as $tid ) {
                if ( isset( $bajaSet[ $tid ] ) ) {
                    ++$titularesConBaja;
                }
            }

            $altasNoTitulares = 0;
            foreach ( $roster as $playerId => $playerTitle ) {
                if ( isset( $titularSet[ $playerId ] ) ) {
                    continue;
                }
                if ( isset( $altaSet[ $playerId ] ) ) {
                    ++$altasNoTitulares;
                    continue;
                }
                $extras[] = [
                    'team_id'      => $teamId,
                    'team_label'   => $summary['team_label'],
                    'player_id'    => $playerId,
                    'player_label' => $playerTitle,
                ];
            }

            $porEquipo[] = [
                'team_id'            => $teamId,
                'team_label'         => $summary['team_label'],
                'titulares_con_baja' => $titularesConBaja,
                'altas_no_titulares' => $altasNoTitulares,
                'difieren'           => $titularesConBaja !== $altasNoTitulares,
            ];
        }

        return new ReemplazoReport( $porEquipo, $extras, $omitidos );
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * @param array<int, string> $publishedTeams id => post_title
     * @param array<int, string> $errors
     */
    private function resolveEquipoId( string $rawEquipo, array $publishedTeams, array &$errors, int $line ): ?int {
        $normalized = EquipoAliasMap::resolve( TextNormalizer::normalize( $rawEquipo ) );

        $matches = [];
        foreach ( $publishedTeams as $id => $title ) {
            if ( TextNormalizer::normalize( $title ) === $normalized ) {
                $matches[] = $id;
            }
        }

        if ( [] === $matches ) {
            $errors[] = "Hoja 'x Equipo', equipo '{$rawEquipo}' (linea {$line}): no existe un equipo publicado (sp_team) con ese nombre, ni un alias conocido (ver EquipoAliasMap).";
            return null;
        }

        if ( count( $matches ) > 1 ) {
            $errors[] = "Hoja 'x Equipo', equipo '{$rawEquipo}' (linea {$line}): el nombre coincide con " . count( $matches ) . ' equipos publicados; es ambiguo.';
            return null;
        }

        return $matches[0];
    }

    /**
     * @param array<int, string> $pool player_id => post_title, scoped to
     *        this team's WordPress roster AND this season (see class
     *        docblock, "THE CANDIDATE POOL").
     * @param array<int, string> $seasonPlayers player_id => post_title, every
     *        player registered this season — used ONLY to validate an
     *        override points at a real, in-season player, never to widen the
     *        matching pool itself.
     * @param array<string, int> $overrides normalized name => player_id.
     * @param array<int, string> $errors
     */
    private function resolveTitularId( string $rawNombre, array $pool, array $seasonPlayers, array $overrides, array &$errors, string $teamLabel, string $vuelta ): ?int {
        $normalized = TextNormalizer::normalize( $rawNombre );

        if ( isset( $overrides[ $normalized ] ) ) {
            $playerId = $overrides[ $normalized ];

            if ( ! isset( $seasonPlayers[ $playerId ] ) ) {
                $errors[] = "Equipo '{$teamLabel}', vuelta {$vuelta}: el override para '{$rawNombre}' apunta al jugador {$playerId}, que no existe publicado y registrado en la temporada.";
                return null;
            }

            return $playerId;
        }

        $candidates = NombreMatcher::candidatosCoincidentes( $rawNombre, $pool );

        if ( [] === $candidates ) {
            $errors[] = "Equipo '{$teamLabel}', vuelta {$vuelta}: el titular '{$rawNombre}' no se pudo resolver contra el plantel de WordPress de ese equipo; agregue un override (ver --overrides).";
            return null;
        }

        if ( count( $candidates ) > 1 ) {
            sort( $candidates );
            $errors[] = "Equipo '{$teamLabel}', vuelta {$vuelta}: el titular '{$rawNombre}' es ambiguo, coincide con los jugadores " . implode( ', ', $candidates ) . '; agregue un override.';
            return null;
        }

        return $candidates[0];
    }

    /**
     * @param array<string, array{player_id:int, puntaje:Puntaje}> $resolved vuelta => fields.
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
            "Equipo '{$label}' (id={$teamId}) ya tiene " . count( $existing )
                . " plaza(s) abierta(s) para la temporada {$seasonId}, y la eleccion agregaria o modificaria mas — "
                . 'revise manualmente antes de reimportar.',
        ];
    }

    // -------------------------------------------------------------------------
    // Raw SQL lookups (never WP_Query — see Plazas\CandidatosResolver's own
    // class docblock for why this whole plugin avoids it)
    // -------------------------------------------------------------------------

    /**
     * @return array<int, string> player_id => post_title.
     */
    private function loadJugadoresRegistrados( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id, posts.post_title AS title
                   FROM {$p}posts posts
                   INNER JOIN {$p}term_relationships tr ON tr.object_id = posts.ID
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND tt.taxonomy = 'sp_season'
                    AND tt.term_id = %d
                  ORDER BY posts.ID ASC",
                $seasonId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadJugadoresRegistrados', [ 'season_id' => $seasonId ] );

        $byId = [];
        foreach ( $rows as $row ) {
            $byId[ (int) $row['id'] ] = (string) $row['title'];
        }

        return $byId;
    }

    /**
     * @return array<int, string> team_id => post_title, every published sp_team.
     */
    private function loadEquiposPublicados(): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            "SELECT ID AS id, post_title AS title FROM {$p}posts WHERE post_type = 'sp_team' AND post_status = 'publish'",
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'loadEquiposPublicados', [] );

        $byId = [];
        foreach ( $rows as $row ) {
            $byId[ (int) $row['id'] ] = (string) $row['title'];
        }

        return $byId;
    }

    /**
     * A team's WordPress roster: every published `sp_player` post whose
     * `sp_current_team` postmeta names `$teamId` — SportsPress's own roster
     * meta key (one row per player-team association; a player on more than
     * one team has more than one row). This is a SIMPLIFICATION of
     * `entre-redes-api`'s own `obtener_equipo_desde_rest()`, which falls
     * back through an `sp_list` taxonomy lookup and a legacy `sp_team`
     * postmeta before ever reaching `sp_current_team` — replicating that
     * entire fallback chain in raw SQL was judged not worth it for a
     * DIAGNOSTIC-ONLY report (see `reportarReemplazos()`); this method is
     * never used to resolve a plaza or a captain, only to build the
     * candidate pool for name matching and the read-only reemplazo report.
     * If a production run's `--reporte-reemplazos` numbers look off,
     * checking whether a team's roster relies on the `sp_list`/legacy
     * fallback instead of `sp_current_team` is the first thing to verify.
     *
     * @return array<int, string> player_id => post_title.
     */
    private function equipoRoster( int $teamId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT posts.ID AS id, posts.post_title AS title
                   FROM {$p}posts posts
                   INNER JOIN {$p}postmeta pm ON pm.post_id = posts.ID
                  WHERE posts.post_type = 'sp_player'
                    AND posts.post_status = 'publish'
                    AND pm.meta_key = 'sp_current_team'
                    AND pm.meta_value = %d",
                $teamId
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'equipoRoster', [ 'team_id' => $teamId ] );

        $byId = [];
        foreach ( $rows as $row ) {
            $byId[ (int) $row['id'] ] = (string) $row['title'];
        }

        return $byId;
    }

    /**
     * @param array<int, int> $playerIds
     * @return array<int, true> The subset of $playerIds carrying $termId on
     *         the `sp_position` taxonomy, as a set (isset() check).
     */
    private function positionFlags( array $playerIds, int $termId ): array {
        if ( [] === $playerIds ) {
            return [];
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $placeholders = implode( ', ', array_fill( 0, count( $playerIds ), '%d' ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT tr.object_id AS id
                   FROM {$p}term_relationships tr
                   INNER JOIN {$p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  WHERE tt.taxonomy = 'sp_position'
                    AND tt.term_id = %d
                    AND tr.object_id IN ({$placeholders})",
                array_merge( [ $termId ], $playerIds )
            ),
            ARRAY_A
        );

        $this->assertReadSucceeded( $rows, 'positionFlags', [ 'term_id' => $termId ] );

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
}
