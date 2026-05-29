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
            $sql = $this->translateForSqlite( $sql );
            try {
                return $this->pdo->exec( $sql );
            } catch ( \PDOException $e ) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        /**
         * Rewrites the few MySQL-isms the plugin emits at runtime into their
         * SQLite equivalents (DDL is handled separately in _prode_mysql_to_sqlite).
         */
        private function translateForSqlite( string $sql ): string {
            // MySQL `INSERT IGNORE INTO` → SQLite `INSERT OR IGNORE INTO`.
            $sql = preg_replace( '/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql );
            // MySQL `START TRANSACTION` → SQLite `BEGIN`.
            $sql = preg_replace( '/^\s*START\s+TRANSACTION\b/i', 'BEGIN', $sql );
            return $sql;
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

        /**
         * Mimics wpdb::insert(). Returns false on failure, 1 on success.
         * Sets $this->insert_id.
         */
        public int $insert_id = 0;

        public function insert( string $table, array $data, mixed $format = null ): int|false {
            if ( empty( $data ) ) {
                return false;
            }
            $cols        = implode( ', ', array_keys( $data ) );
            $placeholders = implode( ', ', array_fill( 0, count( $data ), '?' ) );
            $sql         = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
            try {
                $stmt = $this->pdo->prepare( $sql );
                $stmt->execute( array_values( $data ) );
                $this->insert_id = (int) $this->pdo->lastInsertId();
                return 1;
            } catch ( \PDOException $e ) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        /**
         * Mimics wpdb::update().
         *
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update( string $table, array $data, array $where ): int|false {
            $set_parts   = array_map( static fn( $k ) => "{$k} = ?", array_keys( $data ) );
            $where_parts = array_map( static fn( $k ) => "{$k} = ?", array_keys( $where ) );
            $sql         = "UPDATE {$table} SET " . implode( ', ', $set_parts )
                         . ' WHERE ' . implode( ' AND ', $where_parts );
            try {
                $stmt = $this->pdo->prepare( $sql );
                $stmt->execute( [ ...array_values( $data ), ...array_values( $where ) ] );
                return $stmt->rowCount();
            } catch ( \PDOException $e ) {
                $this->last_error = $e->getMessage();
                return false;
            }
        }

        /**
         * Mimics wpdb::delete().
         *
         * @param array<string, mixed> $where
         */
        public function delete( string $table, array $where ): int|false {
            $where_parts = array_map( static fn( $k ) => "{$k} = ?", array_keys( $where ) );
            $sql         = "DELETE FROM {$table} WHERE " . implode( ' AND ', $where_parts );
            try {
                $stmt = $this->pdo->prepare( $sql );
                $stmt->execute( array_values( $where ) );
                return $stmt->rowCount();
            } catch ( \PDOException $e ) {
                $this->last_error = $e->getMessage();
                return false;
            }
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

        // Identify the AUTO_INCREMENT column BEFORE stripping qualifiers, so we
        // can promote it to a real SQLite rowid alias (INTEGER PRIMARY KEY) — that
        // is what makes inserts which omit the id auto-increment.
        $pk_col = null;
        if ( preg_match( '/(\w+)\s+(?:BIG)?INT\s+(?:UNSIGNED\s+)?NOT NULL\s+AUTO_INCREMENT/i', $sql, $m ) ) {
            $pk_col = $m[1];
        }

        // Remove UNSIGNED (SQLite doesn't support it).
        $sql = str_ireplace( ' UNSIGNED', '', $sql );

        // ENUM('a','b') has no SQLite equivalent — store it as TEXT.
        $sql = preg_replace( '/\bENUM\s*\([^)]*\)/i', 'TEXT', $sql );

        // Promote the AUTO_INCREMENT column to INTEGER PRIMARY KEY inline.
        if ( null !== $pk_col ) {
            $sql = preg_replace(
                '/\b' . preg_quote( $pk_col, '/' ) . '\s+(?:BIG)?INT\s+NOT NULL\s+AUTO_INCREMENT/i',
                $pk_col . ' INTEGER PRIMARY KEY',
                $sql
            );
        }

        // Remove any remaining AUTO_INCREMENT occurrences.
        $sql = str_ireplace( ' AUTO_INCREMENT', '', $sql );

        // Drop separate index lines (UNIQUE KEY / KEY / INDEX are separate DDL in
        // SQLite) and the standalone PRIMARY KEY line — its column is now an inline
        // INTEGER PRIMARY KEY.
        $lines = explode( "\n", $sql );
        $lines = array_filter( $lines, static function ( string $line ) use ( $pk_col ) {
            $trimmed = ltrim( $line );
            if ( preg_match( '/^(UNIQUE\s+KEY|KEY|INDEX)\s+/i', $trimmed ) ) {
                return false;
            }
            if ( null !== $pk_col && preg_match( '/^PRIMARY KEY\s*\(/i', $trimmed ) ) {
                return false;
            }
            return true;
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

// ─── WP constants ────────────────────────────────────────────────────────────

if ( ! defined( 'OBJECT' ) ) {
    define( 'OBJECT', 'OBJECT' );
}

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'ARRAY_N' ) ) {
    define( 'ARRAY_N', 'ARRAY_N' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

// ─── WP site URL shim ────────────────────────────────────────────────────────

if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url(): string {
        return 'http://example.com';
    }
}

// ─── WP HTTP shims (no-ops for unit tests) ───────────────────────────────────

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ): array|false {
        // In unit tests we do not make real HTTP calls.
        // Tests that need HTTP responses should mock or stub this via override.
        return [
            'response' => [ 'code' => 200 ],
            'body'     => '{"keys":[]}',
            'headers'  => [],
        ];
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( array $response ): int {
        return (int) ( $response['response']['code'] ?? 0 );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( array $response ): string {
        return (string) ( $response['body'] ?? '' );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $thing ): bool {
        return $thing instanceof WP_Error;
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public string $code;
        public string $message;
        public mixed $data;

        public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        /** @var array<string, string> */
        private array $headers = [];
        /** @var array<string, mixed> */
        private array $params = [];

        public function set_header( string $name, string $value ): void {
            $this->headers[ strtolower( $name ) ] = $value;
        }

        public function get_header( string $name ): ?string {
            return $this->headers[ strtolower( $name ) ] ?? null;
        }

        public function set_param( string $name, mixed $value ): void {
            $this->params[ $name ] = $value;
        }

        public function get_param( string $name ): mixed {
            return $this->params[ $name ] ?? null;
        }
    }
}

// ─── WP transient shims ───────────────────────────────────────────────────────

if ( ! function_exists( 'get_transient' ) ) {
    $GLOBALS['_prode_test_transients'] = [];

    function get_transient( string $key ): mixed {
        return $GLOBALS['_prode_test_transients'][ $key ] ?? false;
    }

    function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
        $GLOBALS['_prode_test_transients'][ $key ] = $value;
        return true;
    }

    function delete_transient( string $key ): bool {
        unset( $GLOBALS['_prode_test_transients'][ $key ] );
        return true;
    }
}

// ─── Misc WP functions ────────────────────────────────────────────────────────

if ( ! function_exists( 'version_compare' ) ) {
    // PHP built-in; never needed. Here only for clarity.
}

// phpcs:enable
