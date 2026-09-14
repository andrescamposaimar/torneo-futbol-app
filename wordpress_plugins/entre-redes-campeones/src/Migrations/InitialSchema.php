<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Migrations;

/**
 * Creates (or upgrades) the two campeones_ tables (design §2).
 *
 * `campeones_titulo` — one title record per (anio, zona, posicion).
 * `campeones_plantel` — one squad entry per player, FK'd to
 * campeones_titulo.id at the application level only (the SQLite test shim
 * has no FK enforcement, and REC-9 requires every assertion in this repo to
 * be expressible against that shim).
 *
 * `zona`, `posicion` and `estado_vinculo` are VARCHAR, not ENUM — REC-8
 * requires a new position value with no schema migration, and dbDelta cannot
 * reliably ALTER an ENUM in place (see entre-redes-prode's
 * InitialSchema::ensureAuditEventTypes(), a hand-written guarded ALTER that
 * exists only because of that limitation). Valid values are enforced in PHP
 * (LinkState constants, slice 2).
 *
 * Both tables declare ENGINE=InnoDB explicitly, never relying on the server
 * default: the importer's rollback design (slice 6) needs real transactional
 * semantics, and a silent fallback to a non-transactional engine would make
 * START TRANSACTION / ROLLBACK no-ops with no test able to catch it — the
 * SQLite shim strips ENGINE clauses entirely. See tableDefinitionSql() and
 * tests/Migrations/InitialSchemaTest.php for how this is pinned instead.
 *
 * Uses dbDelta() for idempotent CREATE TABLE; safe to re-run on every
 * activation/upgrade.
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

        $charset_collate = $wpdb->get_charset_collate();
        $p                = $wpdb->prefix;

        $results = [];
        foreach ( self::tableDefinitionSql( $p, $charset_collate ) as $sql ) {
            $results = array_merge( $results, dbDelta( $sql ) );
        }

        return $results;
    }

    /**
     * Returns the raw CREATE TABLE SQL for both tables, without executing
     * them. Exposed publicly so tests can assert properties of the raw SQL
     * (notably ENGINE=InnoDB) that the SQLite dbDelta shim would otherwise
     * strip before any test could observe them.
     *
     * @return string[]
     */
    public static function tableDefinitionSql( string $prefix, string $charsetCollate ): array {
        return [
            self::sqlCampeonesTitulo( $prefix, $charsetCollate ),
            self::sqlCampeonesPlantel( $prefix, $charsetCollate ),
        ];
    }

    /**
     * campeones_titulo — one title record per (anio, zona, posicion).
     */
    private static function sqlCampeonesTitulo( string $p, string $charset ): string {
        return "CREATE TABLE {$p}campeones_titulo (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  anio SMALLINT UNSIGNED NOT NULL,
  zona VARCHAR(16) NOT NULL DEFAULT 'A',
  posicion VARCHAR(32) NOT NULL DEFAULT 'campeon',
  equipo_nombre VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_anio_zona_posicion (anio, zona, posicion),
  KEY idx_anio (anio)
) ENGINE=InnoDB $charset;";
    }

    /**
     * campeones_plantel — one squad entry per player.
     *
     * jugador_id is NULL DEFAULT NULL: a title with zero linked squad
     * entries is a valid, complete record (REC-6), so the pointer is never
     * required.
     */
    private static function sqlCampeonesPlantel( string $p, string $charset ): string {
        return "CREATE TABLE {$p}campeones_plantel (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  titulo_id BIGINT UNSIGNED NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  jugador_nombre VARCHAR(255) NOT NULL,
  jugador_nombre_norm VARCHAR(255) NOT NULL DEFAULT '',
  jugador_id BIGINT UNSIGNED NULL DEFAULT NULL,
  es_capitan TINYINT(1) NOT NULL DEFAULT 0,
  estado_vinculo VARCHAR(16) NOT NULL DEFAULT 'sin_candidato',
  candidatos_json TEXT NULL,
  resolved_at DATETIME NULL,
  PRIMARY KEY  (id),
  KEY idx_titulo_orden (titulo_id, orden),
  KEY idx_jugador (jugador_id),
  KEY idx_estado (estado_vinculo)
) ENGINE=InnoDB $charset;";
    }
}
