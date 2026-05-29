<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Migrations;

/**
 * Creates (or upgrades) all 10 prode_ tables.
 *
 * AMENDMENT-001 compliance:
 *   - prode_users is fully standalone (no wp_user_id column).
 *   - tenant_id is a first-class column on prode_users.
 *   - All FKs point to prode_users.id, NOT wp_users.ID.
 *
 * Uses dbDelta() for idempotent CREATE TABLE; safe to re-run on every
 * plugin upgrade — dbDelta only alters schema when columns differ.
 *
 * Important dbDelta formatting rules:
 *   - Each column definition on its own line.
 *   - Two spaces between the column name and its definition.
 *   - PRIMARY KEY must use the exact phrase "PRIMARY KEY".
 *   - No trailing commas on the last column before the closing KEY block.
 */
class InitialSchema {

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

        self::assertInnoDB();

        $charset_collate = $wpdb->get_charset_collate();
        $p               = $wpdb->prefix;

        $sqls = [
            self::sqlProdeUsers( $p, $charset_collate ),
            self::sqlProdeAssociations( $p, $charset_collate ),
            self::sqlProdeRefreshTokens( $p, $charset_collate ),
            self::sqlProdeFechas( $p, $charset_collate ),
            self::sqlProdeFechaMatches( $p, $charset_collate ),
            self::sqlProdePredictions( $p, $charset_collate ),
            self::sqlProdeScores( $p, $charset_collate ),
            self::sqlProdeRankingFechaCache( $p, $charset_collate ),
            self::sqlProdeAuditLog( $p, $charset_collate ),
            self::sqlProdeSettings( $p, $charset_collate ),
        ];

        $results = [];
        foreach ( $sqls as $sql ) {
            $results = array_merge( $results, dbDelta( $sql ) );
        }

        self::ensureActiveDniIndex( $p );
        self::seedSettings( $p );

        return $results;
    }

    /**
     * Enforces "one active account per DNI per tenant" at the DB level.
     *
     * Adds a generated `active_dni` column to prode_users (equal to `dni` for
     * live rows, NULL for soft-deleted rows) plus a UNIQUE index on
     * (tenant_id, active_dni). Because MySQL treats NULLs as distinct in a
     * UNIQUE index, this closes the TOCTOU race in the application-level
     * conflict check (two concurrent /auth/dni calls for the same DNI) while
     * still letting a user who deletes their account re-register later —
     * deletion sets deleted_at, which flips active_dni to NULL and frees the
     * DNI. This is consistent with ADR-P007 (deleted rows keep `dni` as an
     * audit tombstone).
     *
     * Done outside dbDelta on purpose: dbDelta does not understand generated
     * columns (and splits column definitions on commas, which an expression
     * would break). Idempotent — skips when the column already exists, and is
     * a no-op on non-MySQL test shims (no information_schema → null).
     */
    private static function ensureActiveDniIndex( string $p ): void {
        global $wpdb;

        $table = $p . 'prode_users';

        $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = %s
                    AND COLUMN_NAME  = 'active_dni'",
                $table
            )
        );

        // null  → no information_schema (non-MySQL shim): skip silently.
        // > 0   → already provisioned: skip.
        if ( null === $exists || (int) $exists > 0 ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            "ALTER TABLE {$table}
                ADD COLUMN active_dni VARCHAR(20)
                    GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN dni END) STORED,
                ADD UNIQUE KEY uq_tenant_active_dni (tenant_id, active_dni)"
        );
    }

    // -------------------------------------------------------------------------
    // Table definitions
    // -------------------------------------------------------------------------

    /**
     * prode_users — standalone Prode user record (AMENDMENT-001).
     * No wp_users coupling. tenant_id scopes every row.
     */
    private static function sqlProdeUsers( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id VARCHAR(64) NOT NULL,
  dni VARCHAR(20) NOT NULL,
  email VARCHAR(255) NULL DEFAULT NULL,
  provider ENUM('google','apple') NOT NULL,
  provider_id VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL DEFAULT '',
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  last_login_at DATETIME NULL DEFAULT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  deleted_by ENUM('user','admin') NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_tenant_provider_id (tenant_id, provider, provider_id),
  KEY idx_tenant_email (tenant_id, email(191)),
  KEY idx_deleted (deleted_at)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_associations — provider_id <-> DNI <-> player_id link.
     * FK: user_id -> prode_users.id (application-level).
     */
    private static function sqlProdeAssociations( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_associations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  provider ENUM('google','apple') NOT NULL,
  provider_id VARCHAR(255) NOT NULL,
  dni VARCHAR(20) NOT NULL,
  player_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  deleted_at DATETIME NULL DEFAULT NULL,
  deleted_by ENUM('user','admin') NULL DEFAULT NULL,
  deleted_actor_wp_id BIGINT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_user (user_id),
  KEY idx_player (player_id),
  KEY idx_provider (provider, provider_id(191))
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_refresh_tokens — rotating refresh token store.
     * Plain token is NEVER stored; only SHA-256 hash.
     */
    private static function sqlProdeRefreshTokens( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_refresh_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  jti CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  device_label VARCHAR(120) NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL DEFAULT NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_jti (jti),
  KEY idx_user_active (user_id, revoked_at),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_fechas — a Prode round entity.
     * locked_at is snapshotted at creation and is immutable.
     */
    private static function sqlProdeFechas( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_fechas (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id VARCHAR(64) NOT NULL,
  season_id BIGINT UNSIGNED NOT NULL,
  locked_at DATETIME NOT NULL,
  state ENUM('open','locked','evaluated') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL,
  evaluated_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_state (state),
  KEY idx_locked_at (locked_at),
  KEY idx_tenant_season (tenant_id, season_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_fecha_matches — matches that belong to a fecha (M:N).
     * match_kickoff is snapshotted at creation for reprogramming resilience (ADR-P008).
     */
    private static function sqlProdeFechaMatches( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_fecha_matches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  match_kickoff DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_fecha_match (fecha_id, match_id),
  KEY idx_match (match_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_predictions — user predictions per match.
     * UPSERT target: UNIQUE KEY uq_user_match.
     */
    private static function sqlProdePredictions( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_predictions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  fecha_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  result ENUM('1','X','2') NOT NULL,
  score_home TINYINT UNSIGNED NOT NULL,
  score_away TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  locked_at_snapshot DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_match (user_id, match_id),
  KEY idx_fecha (fecha_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_scores — evaluated points per (user, match).
     * UNIQUE KEY uq_user_match enforces idempotency (ADR-P008).
     */
    private static function sqlProdeScores( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  fecha_id BIGINT UNSIGNED NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  prediction_id BIGINT UNSIGNED NULL DEFAULT NULL,
  points TINYINT UNSIGNED NOT NULL,
  evaluation_method ENUM('result_only','exact_score','no_prediction','no_match_score') NOT NULL,
  evaluated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_match (user_id, match_id),
  KEY idx_fecha (fecha_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_ranking_fecha_cache — materialized per-fecha ranking snapshot.
     */
    private static function sqlProdeRankingFechaCache( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_ranking_fecha_cache (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  total_points SMALLINT UNSIGNED NOT NULL,
  rank SMALLINT UNSIGNED NOT NULL,
  computed_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_fecha_user (fecha_id, user_id),
  KEY idx_fecha_rank (fecha_id, rank)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_audit_log — association lifecycle events.
     * DNI stored as SHA-256 hash (ADR-P005; AMENDMENT-001).
     */
    private static function sqlProdeAuditLog( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type ENUM('association_created','association_rejected_dni_not_found','association_rejected_already_associated','admin_unlink','user_account_deletion') NOT NULL,
  tenant_id VARCHAR(64) NOT NULL,
  player_id BIGINT UNSIGNED NULL DEFAULT NULL,
  player_name VARCHAR(255) NULL DEFAULT NULL,
  dni_hash CHAR(64) NOT NULL,
  provider ENUM('google','apple','system','wp_admin') NOT NULL,
  provider_id_hash CHAR(64) NULL DEFAULT NULL,
  actor_wp_user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ip_address_hash CHAR(64) NULL DEFAULT NULL,
  metadata_json TEXT NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_event_time (event_type, created_at),
  KEY idx_player (player_id),
  KEY idx_dni_hash (dni_hash),
  KEY idx_actor (actor_wp_user_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * prode_settings — operator-configurable parameters.
     * V1 keys: lock_hours_before, lock_warning_hours_before, evaluator_cron_interval_minutes.
     */
    private static function sqlProdeSettings( string $p, string $charset ): string {
        return "CREATE TABLE {$p}prode_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL,
  updated_by BIGINT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY  (setting_key)
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

        $defaults = [
            'lock_hours_before'                => '24',
            'lock_warning_hours_before'        => '2',
            'evaluator_cron_interval_minutes'  => '5',
        ];

        if ( defined( 'PRODE_TENANT_ID' ) ) {
            $defaults['tenant_id'] = (string) PRODE_TENANT_ID;
        }

        $now = current_time( 'mysql' );
        foreach ( $defaults as $key => $value ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$p}prode_settings (setting_key, setting_value, updated_at) VALUES (%s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                    $key,
                    $value,
                    $now
                )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Engine check
    // -------------------------------------------------------------------------

    /**
     * Emits an admin notice (deferred) if the DB default engine is not InnoDB.
     * We do not abort activation — dbDelta still creates the tables — but the
     * operator should know that InnoDB is required for proper FK semantics and
     * row-level locking.
     */
    private static function assertInnoDB(): void {
        global $wpdb;

        $engine = $wpdb->get_var( "SELECT @@default_storage_engine" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $engine && strtolower( $engine ) !== 'innodb' ) {
            add_action( 'admin_notices', function () use ( $engine ) {
                echo '<div class="notice notice-warning"><p>';
                printf(
                    esc_html__(
                        'Entre Redes Prode: the default MySQL storage engine is %s. InnoDB is required for correct locking behavior. Please set "default_storage_engine = InnoDB" in your MySQL configuration.',
                        'entre-redes-prode'
                    ),
                    esc_html( $engine )
                );
                echo '</p></div>';
            } );
        }
    }
}
