<?php

declare(strict_types=1);

/**
 * Minimal WordPress shim for PHPUnit standalone execution.
 *
 * Provides just enough WP globals and functions to let InitialSchema and
 * MigrationRunner run against an in-memory SQLite database.
 *
 * This is NOT a full WP emulation. It handles:
 *   - $wpdb with SQLite-backed get_charset_collate() and query()/get_var()
 *   - dbDelta() that maps MySQL DDL to SQLite CREATE TABLE (schema-only check)
 *   - get_option() / update_option() backed by a static array
 *   - current_time() / wp_generate_uuid4() / wp_salt() / wp_generate_password()
 *   - add_action() / do_action() / add_filter() — no-ops in test context
 *   - current_user_can() — returns false (admin tests are manual)
 */

// ─── SQLite-backed wpdb shim ─────────────────────────────────────────────────

if ( ! class_exists( 'wpdb' ) ) {
    /**
     * Minimal wpdb stand-in backed by SQLite in-memory.
     */
    class wpdb {
        public string $prefix      = 'wp_';
        public ?string $last_error = null;

        private \PDO $pdo;

        public function __construct() {
            $this->pdo = new \PDO( 'sqlite::memory:' );
            $this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
        }

        public function get_charset_collate(): string {
            // SQLite ignores charset; return empty string.
            return '';
        }

        /**
         * Executes a raw SQL string. Returns number of affected rows or false.
         */
        public function query( string $sql ): int|false {
            try {
                return $this->pdo->exec( $sql );
            } catch ( \PDOException $e ) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        /**
         * Returns first column of first row, or null.
         */
        public function get_var( string $sql ): ?string {
            try {
                $stmt = $this->pdo->query( $sql );
                $row  = $stmt->fetch( \PDO::FETCH_NUM );
                return $row ? (string) $row[0] : null;
            } catch ( \PDOException $e ) {
                return null;
            }
        }

        /**
         * Returns all rows as associative arrays.
         *
         * @return array<int, array<string, mixed>>
         */
        public function get_results( string $sql, string $output = OBJECT ): array {
            try {
                $stmt = $this->pdo->query( $sql );
                return $stmt->fetchAll( \PDO::FETCH_ASSOC );
            } catch ( \PDOException $e ) {
                return [];
            }
        }

        /**
         * Minimal prepare() — handles %s, %d, %i placeholders.
         * NOT a full security shim; only for unit tests.
         */
        public function prepare( string $query, ...$args ): string {
            // Flatten variadic args if first arg is an array.
            if ( count( $args ) === 1 && is_array( $args[0] ) ) {
                $args = $args[0];
            }
            $i = 0;
            return preg_replace_callback(
                '/%[sdi]/',
                function ( array $match ) use ( &$i, $args ) {
                    $val = $args[ $i++ ] ?? '';
                    if ( $match[0] === '%d' || $match[0] === '%i' ) {
                        return (string) (int) $val;
                    }
                    return "'" . str_replace( "'", "''", (string) $val ) . "'";
                },
                $query
            );
        }

        public function get_row( string $sql, string $output = OBJECT ): ?array {
            $rows = $this->get_results( $sql );
            return $rows[0] ?? null;
        }

        public function getPdo(): \PDO {
            return $this->pdo;
        }
    }
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
global $wpdb;
if ( ! isset( $wpdb ) ) {
    $wpdb = new wpdb();
}

// ─── dbDelta shim ────────────────────────────────────────────────────────────

if ( ! function_exists( 'dbDelta' ) ) {
    /**
     * Translates a MySQL CREATE TABLE statement to SQLite-compatible DDL and
     * executes it.
     *
     * Key translations applied:
     *   - Remove ENGINE=InnoDB, CHARSET, COLLATE clauses.
     *   - Replace BIGINT UNSIGNED AUTO_INCREMENT with INTEGER (SQLite PK).
     *   - Remove UNSIGNED qualifier (SQLite has no typed unsigned).
     *   - Remove column-level DEFAULT '' (SQLite uses DEFAULT '').
     *   - Drop UNIQUE KEY / KEY / INDEX lines (SQLite requires separate statements).
     *   - Remove AUTO_INCREMENT from non-PK columns.
     *
     * Returns an array of result messages (empty on success, error string on failure).
     *
     * @param string|string[] $queries
     * @return string[]
     */
    function dbDelta( $queries ): array {
        global $wpdb;

        if ( is_string( $queries ) ) {
            $queries = [ $queries ];
        }

        $results = [];
        foreach ( $queries as $sql ) {
            $sqlite_sql = _prode_mysql_to_sqlite( $sql );
            if ( null === $sqlite_sql ) {
                continue; // Not a CREATE TABLE — skip.
            }
            $ret = $wpdb->query( $sqlite_sql );
            if ( false === $ret && $wpdb->last_error ) {
                // "table already exists" is fine (idempotency).
                if ( strpos( $wpdb->last_error, 'already exists' ) === false ) {
                    $results[] = 'Error: ' . $wpdb->last_error;
                }
            }
        }

        return $results;
    }

    function _prode_mysql_to_sqlite( string $sql ): ?string {
        if ( ! preg_match( '/^\s*CREATE\s+TABLE/i', $sql ) ) {
            return null;
        }

        // Add IF NOT EXISTS for idempotency.
        $sql = preg_replace( '/CREATE\s+TABLE\s+(?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $sql );

        // Remove trailing ENGINE=..., DEFAULT CHARSET=..., COLLATE=... options.
        $sql = preg_replace( '/\)\s*(ENGINE|DEFAULT CHARSET|COLLATE|AUTO_INCREMENT)\s*[=\w]*[^;]*/i', ')', $sql );
        $sql = preg_replace( '/\s*(ENGINE|DEFAULT CHARSET|COLLATE|CHARACTER SET)\s*=\s*\w+/i', '', $sql );

        // Remove UNSIGNED (SQLite doesn't support it).
        $sql = str_ireplace( ' UNSIGNED', '', $sql );

        // Translate AUTO_INCREMENT primary key to SQLite INTEGER PRIMARY KEY.
        $sql = preg_replace( '/BIGINT\s+NOT NULL\s+AUTO_INCREMENT/i', 'INTEGER NOT NULL', $sql );
        $sql = preg_replace( '/BIGINT\s+AUTO_INCREMENT/i', 'INTEGER', $sql );
        $sql = preg_replace( '/INT\s+NOT NULL\s+AUTO_INCREMENT/i', 'INTEGER NOT NULL', $sql );

        // Remove remaining AUTO_INCREMENT occurrences.
        $sql = str_ireplace( ' AUTO_INCREMENT', '', $sql );

        // Remove KEY / INDEX / UNIQUE KEY lines (they are separate DDL in SQLite).
        $lines = explode( "\n", $sql );
        $lines = array_filter( $lines, static function ( string $line ) {
            $trimmed = ltrim( $line );
            return ! preg_match( '/^(UNIQUE\s+KEY|KEY|INDEX)\s+/i', $trimmed );
        } );
        $sql = implode( "\n", $lines );

        // Clean up trailing commas before closing parenthesis.
        $sql = preg_replace( '/,\s*\)/', ')', $sql );

        // Remove double-space that dbDelta uses (not needed for SQLite).
        $sql = preg_replace( '/  +/', ' ', $sql );

        return $sql;
    }
}

// ─── WP options shim ─────────────────────────────────────────────────────────

if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['_prode_test_options'] = [];

    function get_option( string $key, mixed $default = false ): mixed {
        return $GLOBALS['_prode_test_options'][ $key ] ?? $default;
    }

    function update_option( string $key, mixed $value, bool $autoload = true ): bool {
        $GLOBALS['_prode_test_options'][ $key ] = $value;
        return true;
    }

    function delete_option( string $key ): bool {
        unset( $GLOBALS['_prode_test_options'][ $key ] );
        return true;
    }
}

// ─── WP time / crypto shims ──────────────────────────────────────────────────

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string {
        return ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000,
            mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }
}

if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( string $scheme = 'auth' ): string {
        return hash( 'sha256', 'test_salt_' . $scheme );
    }
}

if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr( str_shuffle( str_repeat( $chars, (int) ceil( $length / strlen( $chars ) ) ) ), 0, $length );
    }
}

// ─── WP hook shims (no-ops) ───────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $tag, callable $fn, int $priority = 10, int $accepted_args = 1 ): true {
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $tag, mixed ...$args ): void {}
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $tag, callable $fn, int $priority = 10, int $accepted_args = 1 ): true {
        return true;
    }
}

// ─── Misc WP functions ────────────────────────────────────────────────────────

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'version_compare' ) ) {
    // PHP built-in; never needed. Here only for clarity.
}

// phpcs:enable
