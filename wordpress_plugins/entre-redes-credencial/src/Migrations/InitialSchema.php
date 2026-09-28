<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Migrations;

/**
 * Creates (or upgrades) all 3 credencial_ tables — see design D4 and D10.
 *
 * Uses dbDelta() for idempotent CREATE TABLE; safe to re-run on every plugin
 * upgrade. Formatting rules mirrored from entre-redes-prode / entre-redes-cambios
 * (each column definition on its own line, two spaces before the type,
 * "PRIMARY KEY" spelled exactly, no trailing comma before the KEY block).
 *
 * IMPORTANT — test harness constraint (see tests/wp-shim.php, copied literally
 * from entre-redes-cambios / entre-redes-prode): the SQLite DDL translator
 * DROPS every UNIQUE KEY / KEY / INDEX line, and has no information_schema at
 * all. `ensurePendingKeyIndex()` below is therefore a no-op under the test
 * suite (its information_schema probe returns null and it returns early) and
 * is only exercised against real MySQL.
 */
class InitialSchema {

    /**
     * Run all CREATE TABLE statements, plus the generated-column index that
     * dbDelta cannot express.
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
            self::sqlCredencialIssuance( $p, $charset_collate ),
            self::sqlCredencialApprovalRequest( $p, $charset_collate ),
            self::sqlCredencialApprovalBlob( $p, $charset_collate ),
        ];

        $results = [];
        foreach ( $sqls as $sql ) {
            $results = array_merge( $results, dbDelta( $sql ) );
        }

        self::ensurePendingKeyIndex( $p );

        return $results;
    }

    // -------------------------------------------------------------------------
    // Table definitions
    // -------------------------------------------------------------------------

    /**
     * credencial_issuance — one row per player, PK on player_id (see design
     * D4). `credential_id` is the server-minted, stable id the app caches;
     * it rotates ONLY when `user_id` or `photo_sha256` differs from the live
     * values (Credencial\CredencialService — a later slice), never on every
     * GET. `photo_sha256` is the sha256 of the currently-published featured
     * image, snapshotted here purely to detect that rotation trigger; it is
     * NOT the source of truth for the photo itself.
     */
    private static function sqlCredencialIssuance( string $p, string $charset ): string {
        return "CREATE TABLE {$p}credencial_issuance (
  player_id BIGINT UNSIGNED NOT NULL,
  credential_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  photo_sha256 CHAR(64) NULL DEFAULT NULL,
  minted_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (player_id)
) ENGINE=InnoDB $charset;";
    }

    /**
     * credencial_approval_request — generic approval-request table (design
     * D10): `type` distinguishes request kinds so future stages can add
     * other types to the SAME table without a schema change. Stage 1 only
     * ever writes `type = 'photo'` (see
     * Approval\ApprovalRequestRepository::TYPE_PHOTO, a later slice).
     *
     * `attachment_id` is written only by the approval CLAIM (design D11,
     * step (e)) — NULL until then. `pending_key` (added by
     * ensurePendingKeyIndex() below, outside dbDelta) enforces "at most one
     * PENDING request per (type, target_player_id)" at the DB level; dbDelta
     * cannot express a generated column, which is why this one line is
     * managed separately, exactly like entre-redes-prode's own
     * `active_dni` / `ensureActiveDniIndex()`.
     */
    private static function sqlCredencialApprovalRequest( string $p, string $charset ): string {
        return "CREATE TABLE {$p}credencial_approval_request (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(32) NOT NULL,
  target_player_id BIGINT UNSIGNED NULL DEFAULT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  payload TEXT NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
  review_note TEXT NULL DEFAULT NULL,
  reviewed_by BIGINT UNSIGNED NULL DEFAULT NULL,
  reviewed_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY idx_type_player_status (type, target_player_id, status)
) ENGINE=InnoDB $charset;";
    }

    /**
     * credencial_approval_blob — the re-encoded pending photo bytes (design
     * D8), one row per approval request, purged once the request is decided
     * and (if approved) published. Never web-reachable: this table is the
     * ONLY place a pending photo's bytes live before a decision.
     */
    private static function sqlCredencialApprovalBlob( string $p, string $charset ): string {
        return "CREATE TABLE {$p}credencial_approval_blob (
  request_id BIGINT UNSIGNED NOT NULL,
  photo_binary LONGBLOB NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (request_id)
) ENGINE=InnoDB $charset;";
    }

    // -------------------------------------------------------------------------
    // Generated column outside dbDelta
    // -------------------------------------------------------------------------

    /**
     * Enforces "at most one PENDING approval request per (type,
     * target_player_id)" at the DB level — design D10: "No replacing a
     * pending photo" (confirmed, `credencial/foto-pendiente-sin-reemplazo`).
     *
     * Adds a generated `pending_key` column to credencial_approval_request
     * (`CONCAT(type, ':', COALESCE(target_player_id, 0))` when `status =
     * 'pending'`, NULL otherwise) plus a UNIQUE index on it. Because MySQL
     * treats NULLs as distinct in a UNIQUE index, an approved/rejected
     * request never blocks a new pending one, while two concurrent pending
     * requests for the same (type, player) collide — closing the TOCTOU race
     * a plain SELECT-then-insert guard alone cannot close. Mirrors
     * entre-redes-prode/src/Migrations/InitialSchema.php's own
     * `ensureActiveDniIndex()` (active_dni / uq_tenant_active_dni).
     *
     * Done outside dbDelta on purpose: dbDelta does not understand generated
     * columns. Idempotent — skips when the column already exists, and is a
     * no-op on non-MySQL test shims (no information_schema → null).
     */
    private static function ensurePendingKeyIndex( string $p ): void {
        global $wpdb;

        $table = $p . 'credencial_approval_request';

        $exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = %s
                    AND COLUMN_NAME  = 'pending_key'",
                $table
            )
        );

        // null  → no information_schema (non-MySQL shim): skip silently.
        // > 0   → already provisioned: skip.
        if ( null === $exists || (int) $exists > 0 ) {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ok = $wpdb->query(
            "ALTER TABLE {$table}
                ADD COLUMN pending_key VARCHAR(64)
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'pending' THEN CONCAT(type, ':', COALESCE(target_player_id, 0)) END
                    ) STORED,
                ADD UNIQUE KEY uq_pending_key (pending_key)"
        );

        if ( false === $ok ) {
            // The ALTER can fail — most likely a duplicate pending row
            // already exists (data predating this constraint, or a manual
            // insert). Surface it the same tolerant way
            // MigrationRunner::checkRuntimeLimits() does: an admin_notice,
            // never a thrown exception — dbDelta already created the
            // tables, and the app-level duplicate-key handling
            // (Db\DbErrors::isDuplicateKey(), a later slice) still catches
            // a concurrent double-insert via MySQL's real error message,
            // just without this DB-level backstop.
            $error = (string) $wpdb->last_error;
            add_action( 'admin_notices', function () use ( $error ) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    esc_html__(
                        'Entre Redes Credencial: no se pudo crear el índice único pending_key (uq_pending_key), probablemente porque ya existen solicitudes pendientes duplicadas. La garantía a nivel de base de datos de "a lo sumo una solicitud pendiente por jugador" NO está activa hasta resolver esto. Error: %s',
                        'entre-redes-credencial'
                    ),
                    esc_html( $error )
                );
                echo '</p></div>';
            } );
        }
    }
}
