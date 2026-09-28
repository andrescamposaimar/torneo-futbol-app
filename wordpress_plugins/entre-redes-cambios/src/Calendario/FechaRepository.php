<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

use EntreRedes\Cambios\Observability\EventLog;
use EntreRedes\Cambios\Support\OpensTransactions;

/**
 * Encapsulates all wpdb persistence for cambios_fecha + cambios_fecha_partido.
 *
 * IDENTITY MODEL (read this before touching upsertFecha()): a fecha's
 * identity is the SET OF ITS PARTIDOS (match_ids), NEVER its `play_date`.
 * When the committee suspends a jornada, a person edits EVERY sp_event of
 * that jornada directly in WordPress — normally moving the whole jornada to
 * the following Saturday. Nothing marks this anywhere: the match_ids simply
 * carry a new kickoff date. upsertFecha() therefore resolves the existing
 * fecha by looking up the incoming match_ids in cambios_fecha_partido, NEVER
 * by (season_id, play_date). If the incoming match_ids resolve to an
 * existing row, that row's play_date MOVES (update) — it never duplicates
 * into a second row for the new day.
 *
 * `play_date_original` (set once, at creation, never touched again) and
 * `veces_postergada` (incremented every time `play_date` advances on an
 * existing fecha) exist purely to make a postponement visible after the
 * fact, since nothing else records that it happened.
 *
 * *** INVARIANT A FUTURE SLICE MUST NOT BREAK ***
 * fecha_id is the stable identity; `orden` is NOT. `orden` is recomputed
 * from scratch by recalculateOrden() on every seed run, purely as a derived
 * 1..N ordering by `play_date` — nobody references it from outside this
 * class. This is exactly what makes it SAFE to recompute: any future
 * consumer that needs to remember "which fecha" (e.g. slice 2's
 * ocupaciones, which anchor the "minimum of 3 resolved fechas" rule to a
 * starting point) MUST persist `fecha_id`, and MUST NEVER persist `orden`.
 * `orden` can and will change value under a `fecha_id` that is loaded late
 * or reordered by a postponement; an ocupacion that stored an `orden`
 * snapshot would silently point at the wrong fecha the next time the
 * calendar reflows. countResolvedFechasSince() exists specifically so
 * callers never need to touch `orden` directly — they pass a `fecha_id` and
 * this class resolves its current `orden` internally, on every call.
 *
 * MERGE DETECTION: if the incoming match_ids resolve to MORE THAN ONE
 * existing fecha_id, upsertFecha() throws. Two jornadas colliding into one
 * is not something this class can safely resolve on its own — it is safer
 * to fail loud than to silently corrupt countResolvedFechasSince()'s
 * counter.
 *
 * Idempotency strategy (mirrors entre-redes-prode's FechaRepository):
 *   uq_season_orden / uq_fecha_match / uq_match are dropped by the SQLite
 *   test shim (see InitialSchema's class docblock), so the code guards
 *   below — not the DB constraints — are what tests actually exercise and
 *   what production correctness depends on in the presence of a re-seed
 *   race.
 *
 * THE MOST IMPORTANT RULE IN THIS CLASS (besides the identity model above):
 * upsertFecha() NEVER overwrites a manually-set `estado`. A fecha's
 * `estado_origen` column records who last decided its `estado` — 'derivado'
 * (the seeding/derivation pipeline) or 'manual' (a human called
 * setEstadoManual(), e.g. to mark 'suspendida' or 'dirimida'). On re-seed,
 * upsertFecha() only touches `estado` when the EXISTING row's
 * `estado_origen` is still 'derivado'. A human's call is sticky forever
 * until another human changes it again — a nightly reseed job (or a
 * postponement) must never silently flip a committee's ruling back to
 * 'programada'.
 */
class FechaRepository {

    use OpensTransactions;

    private EventLog $eventLog;

    /**
     * The only 4 values `cambios_fecha.estado` may ever hold. MySQL's
     * ENUM('programada','jugada','dirimida','suspendida') is not a reliable
     * enough guard on its own: in non-strict mode MySQL silently TRUNCATES an
     * out-of-range value to '' instead of erroring, and the SQLite test shim
     * translates every ENUM column to TEXT (see InitialSchema's class
     * docblock), so a typo like 'sospendida' passes in tests every single
     * time. setEstadoManual() is the ONLY human-facing entry point into this
     * column, so this whitelist is the actual defense against a typo reaching
     * the value that countResolvedFechasSince() counts against.
     */
    private const VALID_ESTADOS = [ 'programada', 'jugada', 'dirimida', 'suspendida' ];

    private \wpdb $wpdb;

    public function __construct( \wpdb $wpdb, EventLog $eventLog ) {
        $this->wpdb     = $wpdb;
        $this->eventLog = $eventLog;
    }

    /**
     * Idempotent upsert: create or MOVE the fecha identified by the incoming
     * partidos' match_ids, then sync its partidos table to exactly that set.
     *
     * @param array{season_id:int, orden:int, torneo_liga_ids:string, torneo_label:string, numero_en_torneo:int, play_date:string, estado?:string} $fecha
     * @param array<int, array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int}> $partidos
     * @return int fecha_id
     * @throws \RuntimeException When the incoming match_ids already belong to
     *         more than one distinct fecha (see class docblock).
     */
    public function upsertFecha( array $fecha, array $partidos ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $seasonId = (int) $fecha['season_id'];
        $playDate = (string) $fecha['play_date'];
        // `created_at`/`updated_at` are DATETIME columns, and every DATETIME
        // column this plugin persists is UTC (see the README) — `$gmt = true`
        // is mandatory, never the default `current_time('mysql')` local time.
        $now      = current_time( 'mysql', true );

        $matchIds = $this->extractMatchIds( $partidos );
        $fechaId  = $this->findFechaIdByMatchIds( $matchIds );

        if ( null !== $fechaId ) {
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT play_date, veces_postergada, estado_origen FROM {$p}cambios_fecha
                      WHERE id = %d
                      LIMIT 1",
                    $fechaId
                ),
                ARRAY_A
            );

            $update = [
                'torneo_liga_ids' => (string) $fecha['torneo_liga_ids'],
                'torneo_label'    => (string) $fecha['torneo_label'],
                'play_date'       => $playDate,
                'updated_at'      => $now,
            ];

            // A postponement is detected purely from the clock moving
            // forward — see class docblock. play_date_original is
            // intentionally absent from $update: it is set once, at
            // creation, below.
            if ( $playDate > (string) $existing['play_date'] ) {
                $update['veces_postergada'] = (int) $existing['veces_postergada'] + 1;
            }

            // THE rule: only touch `estado` when nobody has manually decided
            // it. See class docblock.
            if ( 'derivado' === (string) $existing['estado_origen'] ) {
                $update['estado'] = (string) ( $fecha['estado'] ?? 'programada' );
            }

            $wpdb->update( $p . 'cambios_fecha', $update, [ 'id' => $fechaId ] );
        } else {
            $wpdb->insert(
                $p . 'cambios_fecha',
                [
                    'season_id'          => $seasonId,
                    'orden'              => $this->nextFreeOrden( $seasonId ),
                    'torneo_liga_ids'    => (string) $fecha['torneo_liga_ids'],
                    'torneo_label'       => (string) $fecha['torneo_label'],
                    'numero_en_torneo'   => (int) ( $fecha['numero_en_torneo'] ?? 0 ),
                    'play_date'          => $playDate,
                    'play_date_original' => $playDate,
                    'veces_postergada'   => 0,
                    'estado'             => (string) ( $fecha['estado'] ?? 'programada' ),
                    'estado_origen'      => 'derivado',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]
            );
            $fechaId = (int) $wpdb->insert_id;
        }

        $this->syncPartidos( $fechaId, $partidos );

        return $fechaId;
    }

    /**
     * Resolves the existing fecha_id that owns ALL of the given match_ids,
     * or null when none of them is assigned to a fecha yet.
     *
     * @param array<int, int> $matchIds
     * @throws \RuntimeException When the match_ids resolve to more than one
     *         distinct fecha_id — see class docblock ("MERGE DETECTION").
     */
    public function findFechaIdByMatchIds( array $matchIds ): ?int {
        if ( empty( $matchIds ) ) {
            return null;
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $placeholders = implode( ', ', array_fill( 0, count( $matchIds ), '%d' ) );
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT fecha_id FROM {$p}cambios_fecha_partido WHERE match_id IN ({$placeholders})",
                $matchIds
            ),
            ARRAY_A
        );

        $fechaIds = array_values( array_unique( array_map(
            static fn( array $r ): int => (int) $r['fecha_id'],
            $rows
        ) ) );

        if ( count( $fechaIds ) > 1 ) {
            throw new \RuntimeException(
                'upsertFecha(): incoming match_ids belong to ' . count( $fechaIds ) . ' distinct fechas '
                . '(ids: ' . implode( ',', $fechaIds ) . '). Two jornadas appear to be merging into one — '
                . 'this cannot be resolved automatically; check the play_date change made in SportsPress '
                . 'for these match_ids and split or reconcile the fechas manually before re-seeding.'
            );
        }

        return $fechaIds[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById( int $fechaId ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_fecha WHERE id = %d LIMIT 1",
                $fechaId
            ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
    }

    /**
     * Recomputes `orden` (1..N by play_date ASC, tie-broken by id ASC for
     * determinism) and `numero_en_torneo` (resets to 1 whenever
     * `torneo_label` differs from the previous row's) for every fecha of a
     * season. `fecha_id` values are NEVER touched. Safe to call on every seed
     * run — see class docblock's INVARIANT section for why that is safe.
     *
     * @return int Number of cambios_fecha rows whose orden or
     *         numero_en_torneo actually changed value.
     */
    public function recalculateOrden( int $seasonId ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, torneo_label, orden, numero_en_torneo FROM {$p}cambios_fecha
                  WHERE season_id = %d
                  ORDER BY play_date ASC, id ASC",
                $seasonId
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return 0;
        }

        $targets    = [];
        $orden      = 0;
        $numero     = 0;
        $prevTorneo = null;

        foreach ( $rows as $row ) {
            $torneo = (string) $row['torneo_label'];

            $orden++;
            $numero     = ( $torneo === $prevTorneo ) ? $numero + 1 : 1;
            $prevTorneo = $torneo;

            $targets[] = [
                'id'         => (int) $row['id'],
                'orden'      => $orden,
                'numero'     => $numero,
                'old_orden'  => (int) $row['orden'],
                'old_numero' => (int) $row['numero_en_torneo'],
            ];
        }

        $dirty = array_values( array_filter(
            $targets,
            static fn( array $t ): bool =>
                $t['orden'] !== $t['old_orden'] || $t['numero'] !== $t['old_numero']
        ) );

        if ( empty( $dirty ) ) {
            return 0;
        }

        // TWO PASSES, and the reason matters: `orden` carries
        // UNIQUE(season_id, orden) in MySQL. Writing the final values row by
        // row transiently collides whenever a fecha shifts into a slot its
        // neighbour still occupies — exactly what happens when a
        // chronologically earlier fecha is loaded late, which is a normal
        // event here (the comision loads the fixture week by week, and the
        // Clasificacion is loaded before the Apertura fixture even exists).
        // So: park every moving row in a disjoint high range first, then
        // write the final values.
        //
        // The test harness CANNOT catch a single-pass regression on its own.
        // tests/wp-shim.php strips every UNIQUE KEY when translating the DDL
        // to SQLite, so a single-pass implementation passes green here and
        // fails on real MySQL. Do not "simplify" this back into one pass.
        //
        // The two passes are also not durable on their own: if the process
        // dies between the parking pass and the final-values pass, every
        // dirty row is left sitting at a parked `orden` and a stale
        // `numero_en_torneo`. That state is self-healing — the NEXT seed run
        // recomputes everything from `play_date`, the actual source of truth
        // — but the window is invisible in the meantime, and nothing in this
        // slice schedules that next run (no cron yet — see README's "Scope of
        // this slice"). START TRANSACTION/COMMIT below does not change the
        // recovery story; it makes the atomicity of the two passes a fact the
        // code enforces, not just prose the docblock asserts. On any
        // exception, ROLLBACK restores every dirty row to its pre-call value
        // instead of leaving it parked. Both tables are InnoDB (transactional
        // in production), and tests/wp-shim.php maps START TRANSACTION to
        // SQLite's BEGIN, so this is exercised here too.
        $this->beginTransaction( __FUNCTION__ );

        try {
            $park = $this->nextFreeOrden( $seasonId );

            foreach ( $dirty as $i => $t ) {
                $wpdb->update( $p . 'cambios_fecha', [ 'orden' => $park + $i ], [ 'id' => $t['id'] ] );
            }

            foreach ( $dirty as $t ) {
                $wpdb->update(
                    $p . 'cambios_fecha',
                    [
                        'orden'            => $t['orden'],
                        'numero_en_torneo' => $t['numero'],
                    ],
                    [ 'id' => $t['id'] ]
                );
            }
        } catch ( \Throwable $e ) {
            $this->rollbackTransaction( __FUNCTION__, $e );
            throw $e;
        }

        $this->commitTransaction( __FUNCTION__ );

        return count( $dirty );
    }

    /**
     * The lowest `orden` value guaranteed to be free for this season, i.e.
     * MAX(orden) + 1.
     *
     * Used for two things, both of which exist to respect
     * UNIQUE(season_id, orden) on MySQL:
     *   - the provisional `orden` of a freshly inserted fecha, before
     *     recalculateOrden() assigns the real one. A fixed placeholder (0, say)
     *     would collide on the second insert of a season.
     *   - the base of the parking range in recalculateOrden()'s first pass.
     *
     * Not concurrency-safe by itself, and deliberately so: the seeder runs
     * single-threaded from cron or WP-CLI, never from two requests at once.
     */
    private function nextFreeOrden( int $seasonId ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $max = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(orden) FROM {$p}cambios_fecha WHERE season_id = %d",
                $seasonId
            )
        );

        return $max + 1;
    }

    /**
     * Mark a fecha's estado by human decision. This is the ONLY way
     * 'dirimida' or 'suspendida' ever enter the table — EstadoDeriver never
     * produces them (see its class docblock).
     *
     * @throws \InvalidArgumentException When $estado is not one of
     *         self::VALID_ESTADOS — see that constant's docblock for why this
     *         check cannot be delegated to the column's MySQL ENUM.
     */
    public function setEstadoManual( int $fechaId, string $estado, ?int $userId, string $now ): bool {
        if ( ! in_array( $estado, self::VALID_ESTADOS, true ) ) {
            throw new \InvalidArgumentException(
                "setEstadoManual(): '{$estado}' is not a valid estado. "
                . 'Valid values are: ' . implode( ', ', self::VALID_ESTADOS ) . '.'
            );
        }

        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $affected = $wpdb->update(
            $p . 'cambios_fecha',
            [
                'estado'                 => $estado,
                'estado_origen'          => 'manual',
                'estado_actualizado_at'  => $now,
                'estado_actualizado_por' => $userId,
                'updated_at'             => $now,
            ],
            [ 'id' => $fechaId ]
        );

        return false !== $affected;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByOrden( int $seasonId, int $orden ): ?array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_fecha
                  WHERE season_id = %d AND orden = %d
                  LIMIT 1",
                $seasonId,
                $orden
            ),
            ARRAY_A
        );

        return empty( $row ) ? null : $row;
    }

    /**
     * All fechas of a season, ordered by `orden` ASC (the continuous,
     * arithmetic-safe field — see cambios_fecha's column docs).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listBySeason( int $seasonId ): array {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$p}cambios_fecha
                  WHERE season_id = %d
                  ORDER BY orden ASC",
                $seasonId
            ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    /**
     * Count RESOLVED fechas (estado IN ('jugada','dirimida')) at or after
     * the `orden` of a given fecha_id. This is the method the rest of the
     * "cambios" feature uses to check the "at least 3 resolved fechas"
     * business minimum — see the slice task description's domain fact #4
     * ("el contador que importa al negocio es cuántas fechas RESUELTAS
     * pasaron").
     *
     * Takes a fecha_id — NEVER an `orden` — on purpose: see class docblock's
     * INVARIANT section. `orden` is resolved internally, fresh, on every
     * call, so callers never risk holding a stale snapshot of it.
     *
     * @throws \InvalidArgumentException When fechaId does not exist, or
     *         does not belong to seasonId.
     */
    public function countResolvedFechasSince( int $seasonId, int $fechaId ): int {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT orden FROM {$p}cambios_fecha
                  WHERE id = %d AND season_id = %d
                  LIMIT 1",
                $fechaId,
                $seasonId
            ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            throw new \InvalidArgumentException(
                "countResolvedFechasSince(): fecha_id {$fechaId} does not exist in season {$seasonId}."
            );
        }

        $ordenDesde = (int) $row['orden'];

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}cambios_fecha
                  WHERE season_id = %d
                    AND orden >= %d
                    AND estado IN ('jugada','dirimida')",
                $seasonId,
                $ordenDesde
            )
        );

        return (int) $count;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int, array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int}> $partidos
     * @return array<int, int>
     */
    private function extractMatchIds( array $partidos ): array {
        return array_values( array_unique( array_map(
            static fn( array $partido ): int => (int) $partido['match_id'],
            $partidos
        ) ) );
    }

    /**
     * Upserts every incoming partido, then deletes any partido row still
     * pointing at $fechaId that is no longer in the incoming set — the
     * table always mirrors exactly the match_ids passed to upsertFecha().
     *
     * @param array<int, array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int}> $partidos
     */
    private function syncPartidos( int $fechaId, array $partidos ): void {
        $partidos = $this->dedupeByMatchId( $partidos );

        foreach ( $partidos as $partido ) {
            $this->upsertPartido( $fechaId, $partido );
        }

        $this->removeStalePartidos( $fechaId, $this->extractMatchIds( $partidos ) );
    }

    /**
     * Defends the same invariant PartidosApiClient::fetchAll() enforces at
     * fetch time — see its docblock for the Saturday-night scenario in full:
     * a partido's result can get loaded into SportsPress between the
     * `/partidos` and `/partidos-programados` calls, so its `match_id` comes
     * back twice in one `$fetcherFn` result, once resolved and once not.
     * upsertPartido() below is a plain SELECT-then-update keyed by `match_id`,
     * so without this guard the loop in syncPartidos() would simply apply
     * both rows in array order and let whichever one is LAST win — silently
     * downgrading an already-played partido back to `tiene_resultado = 0` if
     * the stale duplicate happened to be appended after the resolved one.
     *
     * This guard exists here, not only in PartidosApiClient, because
     * `$fetcherFn` is an injected seam: any future fetcher (a different REST
     * client, a direct-from-`sp_results` implementation, a test stub) could
     * reintroduce the same duplicate, and this is the point where the
     * duplicate is actually persisted. `tiene_resultado = true` always wins,
     * never downgraded by a later duplicate, regardless of array order.
     *
     * @param array<int, array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int}> $partidos
     * @return array<int, array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int}>
     */
    private function dedupeByMatchId( array $partidos ): array {
        $byMatchId = [];

        foreach ( $partidos as $partido ) {
            $matchId  = (int) $partido['match_id'];
            $existing = $byMatchId[ $matchId ] ?? null;

            if ( null !== $existing && ! empty( $existing['tiene_resultado'] ) ) {
                continue;
            }

            $byMatchId[ $matchId ] = $partido;
        }

        return array_values( $byMatchId );
    }

    /**
     * @param array{match_id:int, liga_id:int, zona?:string, kickoff:string, tiene_resultado?:bool|int} $partido
     */
    private function upsertPartido( int $fechaId, array $partido ): void {
        $wpdb    = $this->wpdb;
        $p       = $wpdb->prefix;
        $matchId = (int) $partido['match_id'];

        // SELECT-then-insert guard keyed by match_id ALONE — match_id is a
        // WordPress post id, globally unique, and a partido belongs to
        // exactly one fecha (see class docblock). uq_match enforces this in
        // production; the SQLite test shim drops it, so this guard is what
        // tests actually exercise.
        $existingId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$p}cambios_fecha_partido
                  WHERE match_id = %d
                  LIMIT 1",
                $matchId
            )
        );

        $data = [
            'fecha_id'        => $fechaId,
            'liga_id'         => (int) $partido['liga_id'],
            'zona'            => (string) ( $partido['zona'] ?? '' ),
            'kickoff'         => (string) $partido['kickoff'],
            'tiene_resultado' => empty( $partido['tiene_resultado'] ) ? 0 : 1,
        ];

        if ( null !== $existingId ) {
            $wpdb->update( $p . 'cambios_fecha_partido', $data, [ 'id' => (int) $existingId ] );
            return;
        }

        $data['match_id'] = $matchId;
        $wpdb->insert( $p . 'cambios_fecha_partido', $data );
    }

    /**
     * @param array<int, int> $incomingMatchIds
     */
    private function removeStalePartidos( int $fechaId, array $incomingMatchIds ): void {
        $wpdb = $this->wpdb;
        $p    = $wpdb->prefix;

        if ( empty( $incomingMatchIds ) ) {
            $wpdb->query(
                $wpdb->prepare( "DELETE FROM {$p}cambios_fecha_partido WHERE fecha_id = %d", $fechaId )
            );
            return;
        }

        $placeholders = implode( ', ', array_fill( 0, count( $incomingMatchIds ), '%d' ) );
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$p}cambios_fecha_partido
                  WHERE fecha_id = %d AND match_id NOT IN ({$placeholders})",
                array_merge( [ $fechaId ], $incomingMatchIds )
            )
        );
    }
}
