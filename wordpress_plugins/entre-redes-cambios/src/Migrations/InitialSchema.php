<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Migrations;

/**
 * Creates (or upgrades) all 4 cambios_ tables.
 *
 * Uses dbDelta() for idempotent CREATE TABLE; safe to re-run on every plugin
 * upgrade — dbDelta only alters schema when columns differ.
 *
 * Important dbDelta formatting rules (mirrored from entre-redes-prode):
 *   - Each column definition on its own line.
 *   - Two spaces between the column name and its definition.
 *   - PRIMARY KEY must use the exact phrase "PRIMARY KEY".
 *   - No trailing commas on the last column before the closing KEY block.
 *
 * IMPORTANT — test harness constraint (see tests/wp-shim.php, copied literally
 * from entre-redes-prode): the SQLite DDL translator DROPS every UNIQUE KEY /
 * KEY / INDEX line. No uniqueness constraint declared below is enforced by the
 * PHPUnit in-memory DB. Every uniqueness invariant this schema relies on
 * (season+orden, match_id globally unique, fecha+match) is therefore defended
 * in application code via a SELECT-then-insert guard in
 * Calendario\FechaRepository, exactly like entre-redes-prode's FechaRepository.
 * The same translator also rewrites every `ENUM(...)` column (e.g. `estado`,
 * `estado_origen`) to `TEXT` — so a typo in an ENUM-backed value (like an
 * invalid `estado`) is silently accepted in tests too, never just in
 * non-strict MySQL. `Calendario\FechaRepository::setEstadoManual()` is what
 * actually guards `estado` against that, in code, for the same reason.
 *
 * NOTE ON IDENTITY: there is deliberately NO `UNIQUE KEY (season_id,
 * play_date)` — see Calendario\FechaRepository's class docblock, IDENTITY
 * MODEL, for why (play_date is mutable; a fecha's identity is its match_ids).
 */
class InitialSchema {

    /**
     * Values seeded into cambios_settings on activation.
     *
     * `season_id` and `timezone` are simple scalars. The four `plazo_*` keys
     * are JSON-encoded {"days":int,"time":"H:i:s"} pairs — the exact shape
     * Calendario\PlazosCalculator::compute() expects per plazo — so
     * Calendario\Settings can decode them directly without a second mapping
     * layer. Kept as a constant so Settings' fallback defaults can be asserted
     * against these seeds (see VersionConsistencyTest-style drift protection):
     * an unseeded setting must fall back to the SAME value the DB would have
     * held, or an operator who wipes cambios_settings silently gets different
     * behavior than a fresh install.
     *
     * @var array<string, string>
     */
    public const SEED_DEFAULTS = [
        'timezone'                   => 'America/Argentina/Buenos_Aires',
        'season_id'                  => '359',
        'plazo_apertura_solicitudes' => '{"days":-6,"time":"00:00:00"}',
        'plazo_cierre_regresos'      => '{"days":-4,"time":"23:59:59"}',
        'plazo_cierre_solicitudes'   => '{"days":-2,"time":"23:59:59"}',
        'plazo_publicacion'          => '{"days":-1,"time":"00:00:00"}',
    ];

    /**
     * Run all CREATE TABLE statements.
     *
     * @return string[] Array of dbDelta result messages (for logging).
     */
    public static function up(): array {
        global $wpdb;

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $charset_collate = $wpdb->get_charset_collate();
        $p               = $wpdb->prefix;

        $sqls = [
            self::sqlCambiosFecha( $p, $charset_collate ),
            self::sqlCambiosFechaPartido( $p, $charset_collate ),
            self::sqlCambiosSettings( $p, $charset_collate ),
            self::sqlCambiosCapitan( $p, $charset_collate ),
        ];

        $results = [];
        foreach ( $sqls as $sql ) {
            $results = array_merge( $results, dbDelta( $sql ) );
        }

        self::seedSettings( $p );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Table definitions
    // -------------------------------------------------------------------------

    /**
     * cambios_fecha — one row per jornada of the ENTIRE season (Apertura +
     * Clausura together, and Clasificacion when the season carries one).
     * There is no `zona` column on purpose: a jornada is never suspended for
     * a single zone, so the suspension/state lifecycle is tracked once per
     * jornada, not three times.
     *
     * IDENTITY and `orden`: see Calendario\FechaRepository's class docblock
     * (IDENTITY MODEL, INVARIANT) and README.md's "orden vs numero_en_torneo"
     * section — both authoritative. In short: identity lives in `fecha_id`,
     * never in `orden` or `play_date`; `orden` is recomputed from scratch on
     * every seed run and is the only column valid for arithmetic.
     *
     * `numero_en_torneo` is what a human reads ("Fecha 3 del Apertura") and
     * resets to 1 whenever `torneo_label` changes — NEVER use it for date
     * maths. Valid `torneo_label` values: 'Clasificacion' | 'Apertura' |
     * 'Clausura'.
     *
     * `play_date_original` is set once, at creation, and is NEVER updated
     * again — it records the day the jornada was first scheduled for.
     * `veces_postergada` counts how many times `play_date` has since been
     * pushed forward (i.e. the jornada was suspended and moved to a later
     * day). Both exist purely to make a postponement visible after the fact:
     * nothing in SportsPress or in this plugin records "this jornada moved",
     * the sp_event dates just silently change.
     */
    private static function sqlCambiosFecha( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_fecha (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL,
  torneo_liga_ids VARCHAR(64) NOT NULL,
  torneo_label VARCHAR(32) NOT NULL,
  numero_en_torneo SMALLINT UNSIGNED NOT NULL,
  play_date DATE NOT NULL,
  play_date_original DATE NOT NULL,
  veces_postergada SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  estado ENUM('programada','jugada','dirimida','suspendida') NOT NULL DEFAULT 'programada',
  estado_origen ENUM('derivado','manual') NOT NULL DEFAULT 'derivado',
  estado_actualizado_at DATETIME NULL DEFAULT NULL,
  estado_actualizado_por BIGINT UNSIGNED NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_season_orden (season_id, orden),
  KEY idx_estado (estado)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_fecha_partido — bridge to sp_event match posts. `zona` is
     * denormalized here (not just liga_id) because the human-facing label
     * ("Zona A") is cheaper to snapshot at seed time than to re-resolve later
     * from SportsPress term relationships, mirroring prode_fecha_matches'
     * snapshot strategy.
     *
     * `uq_match (match_id)` encodes the identity rule: a partido (a
     * WordPress sp_event post, globally unique by match_id) belongs to
     * EXACTLY ONE fecha, ever. `uq_fecha_match` is kept alongside it as a
     * cheap defensive constraint, but `uq_match` is the one that matters —
     * it is what makes "look up the existing fecha by its incoming
     * match_ids" a safe, unambiguous operation in
     * Calendario\FechaRepository::upsertFecha().
     */
    private static function sqlCambiosFechaPartido( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_fecha_partido (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  liga_id BIGINT UNSIGNED NOT NULL,
  zona VARCHAR(255) NOT NULL DEFAULT '',
  kickoff DATETIME NOT NULL,
  tiene_resultado TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_fecha_match (fecha_id, match_id),
  UNIQUE KEY uq_match (match_id),
  KEY idx_match (match_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_settings — operator-configurable parameters. Same key/value
     * shape as entre-redes-prode's prode_settings table.
     */
    private static function sqlCambiosSettings( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (setting_key)
) ENGINE=InnoDB $charset;";
    }

    /**
     * cambios_capitan — one row per captaincy DESIGNATION, not per team.
     * `revocado_at IS NULL` marks the currently-vigent row for a
     * `(season_id, team_id)` pair; every prior designation for that pair is
     * left in place with `revocado_at` set, so the table doubles as the
     * captaincy's audit history.
     *
     * `player_id` is the `sp_player` post id — the same value the prode JWT
     * carries as its `player_id` claim (see Auth\TokenVerifier), which is
     * exactly what makes `Capitania\CapitanAuthorizer` able to compare a
     * token's claim against this table directly, with no extra lookup.
     *
     * *** WHY THIS IS NOT A UNIQUE KEY ***
     * The business rule is "at most one VIGENT captain per (season_id,
     * team_id)" — but that is not expressible as `UNIQUE (season_id,
     * team_id)`, because the table is also the history: a revoked row for the
     * same pair must be allowed to coexist with the new vigent one. Scoping
     * the unique key to `revocado_at` (e.g. `UNIQUE (season_id, team_id,
     * revocado_at)`) does not work either — MySQL treats every NULL in a
     * UNIQUE index as distinct from every other NULL, so it would allow
     * multiple simultaneously-vigent rows for the same team, defeating the
     * whole point. The invariant is therefore defended the same way
     * Calendario\FechaRepository defends `uq_season_orden`: a SELECT-then-
     * insert guard in Capitania\CapitanRepository::designateCapitan() (find and
     * revoke the current vigent row, in a transaction, before inserting the
     * new one), verified by a test that asserts the PROPERTY ("never two
     * vigent rows for the same pair"), not a constraint. And — same as every
     * other table in this file — the SQLite test shim drops every KEY/INDEX
     * line below, so `idx_season_team` and `idx_player` are query-plan
     * optimizations only; nothing about the uniqueness invariant depends on
     * them being enforced by the test DB.
     */
    private static function sqlCambiosCapitan( string $p, string $charset ): string {
        return "CREATE TABLE {$p}cambios_capitan (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  season_id BIGINT UNSIGNED NOT NULL,
  team_id BIGINT UNSIGNED NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  designado_por BIGINT UNSIGNED NULL DEFAULT NULL,
  designado_at DATETIME NOT NULL,
  revocado_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_season_team (season_id, team_id),
  KEY idx_player (player_id)
) ENGINE=InnoDB $charset;";
    }

    // -------------------------------------------------------------------------
    // Post-migration seeds
    // -------------------------------------------------------------------------

    /**
     * Insert default settings rows (idempotent — INSERT IGNORE).
     */
    private static function seedSettings( string $p ): void {
        global $wpdb;

        $now = current_time( 'mysql' );
        foreach ( self::SEED_DEFAULTS as $key => $value ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}cambios_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $key,
                    $value,
                    $now
                )
            );
        }
    }
}
