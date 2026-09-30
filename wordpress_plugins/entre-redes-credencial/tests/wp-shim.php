<?php

declare(strict_types=1);

/**
 * Copied literally from entre-redes-cambios/tests/wp-shim.php, itself copied
 * from entre-redes-prode/tests/wp-shim.php (this shim is WP-generic, not
 * plugin-specific — do not reimplement it per-plugin). Internal globals are
 * intentionally left with their original `_prode_*` naming; do not rename
 * them.
 *
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
 *   - add_action() / do_action() / add_filter() / remove_action() — no-ops in test context
 *   - current_user_can() / check_admin_referer() / wp_verify_nonce() — each
 *     controllable via a $GLOBALS['wp_test_*'] switch (see each function's
 *     own docblock below, and $wp_test_postmeta above for the same
 *     data-driven-global convention); default to the fail-closed behavior
 *     they always had when a test sets nothing
 */

// ─── SQLite-backed wpdb shim ─────────────────────────────────────────────────

if ( ! class_exists( 'wpdb' ) ) {
    /**
     * Minimal wpdb stand-in backed by SQLite in-memory.
     */
    class wpdb {
        public string $prefix      = 'wp_';
        public string $options     = 'wp_options';
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
            // Credencial-specific (design D4): IssuanceRepository's
            // "ensure a row exists" idiom is EXACTLY
            // `INSERT ... ON DUPLICATE KEY UPDATE player_id = player_id` — a
            // deliberate no-op update used only to make the statement
            // succeed on a duplicate key. SQLite's equivalent is
            // `ON CONFLICT DO NOTHING` (conflict target is optional for
            // DO NOTHING since SQLite 3.24). Matched narrowly on this exact
            // no-op shape so a future call site with a REAL update clause is
            // never silently swallowed by this translation.
            $sql = preg_replace(
                '/\bON DUPLICATE KEY UPDATE\s+player_id\s*=\s*player_id\b/i',
                'ON CONFLICT DO NOTHING',
                $sql
            );
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
    /**
     * WHY THIS SHIM MUST BE FAITHFUL TO WORDPRESS, NOT A UTC PASSTHROUGH.
     *
     * Real WordPress's `current_time( $type, $gmt = false )` hands out the
     * SITE'S LOCAL civil time by default, and UTC only when the caller
     * explicitly passes `$gmt = true`. An earlier version of this shim
     * ignored that second parameter entirely (its signature did not even
     * declare it) and always returned UTC — which meant no test running
     * against this shim could ever catch a caller that confused "local" and
     * "UTC", because both frames collapsed onto the identical string. A
     * plugin bug that mixes `current_time('mysql')` (local) with
     * `time()`/`gmdate()` (UTC) is therefore invisible to any assertion, no
     * matter how carefully written, until it reaches real WordPress.
     *
     * This shim simulates the torneo's own site timezone,
     * America/Argentina/Buenos_Aires — a FIXED, NON-ZERO offset from UTC
     * (UTC-3, no DST since 2009, so the offset never itself becomes a
     * moving target). That is deliberate: if `current_time('mysql')` and
     * `current_time('mysql', true)` ever come back equal in a test, that is
     * a real bug to investigate, never an artifact of the simulated site
     * happening to sit on UTC+0 the way the old shim effectively did.
     *
     * Only `'mysql'` and `'timestamp'` are modeled — the only two `$type`
     * values this plugin actually calls `current_time()` with.
     */
    function current_time( string $type, bool $gmt = false ): string {
        $zone = new \DateTimeZone( $gmt ? 'UTC' : 'America/Argentina/Buenos_Aires' );
        $now  = new \DateTime( 'now', $zone );

        if ( 'timestamp' === $type ) {
            return (string) $now->getTimestamp();
        }

        return $now->format( 'Y-m-d H:i:s' );
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
    /**
     * Records the callback (unlike a pure no-op) so tests can invoke deferred
     * hooks — e.g. admin_notices closures registered by InitialSchema /
     * MigrationRunner — the same way WordPress would when rendering wp-admin.
     * do_action() below fires them; did_action()'s counter is unaffected.
     *
     * Also records priority/accepted_args per registration in
     * _prode_test_action_registrations — separate from the plain callback list
     * above so existing do_action() dispatch is unaffected — so tests can
     * assert WHICH hook and priority a callback was bound to. This matters for
     * regressions like ADR-G7-1 (save_post priority 20, never save_post_sp_event).
     */
    function add_action( string $tag, callable $fn, int $priority = 10, int $accepted_args = 1 ): true {
        $GLOBALS['_prode_test_action_callbacks'][ $tag ][] = $fn;
        $GLOBALS['_prode_test_action_registrations'][ $tag ][] = [
            'callback'      => $fn,
            'priority'      => $priority,
            'accepted_args' => $accepted_args,
        ];
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    $GLOBALS['_prode_test_actions'] = [];

    function do_action( string $tag, mixed ...$args ): void {
        $GLOBALS['_prode_test_actions'][ $tag ] = ( $GLOBALS['_prode_test_actions'][ $tag ] ?? 0 ) + 1;
        foreach ( $GLOBALS['_prode_test_action_callbacks'][ $tag ] ?? [] as $fn ) {
            $fn( ...$args );
        }
    }
}

if ( ! function_exists( 'did_action' ) ) {
    function did_action( string $tag ): int {
        return $GLOBALS['_prode_test_actions'][ $tag ] ?? 0;
    }
}

if ( ! function_exists( 'remove_action' ) ) {
    /**
     * Mirrors WordPress's remove_action(): removes a callback previously
     * registered on $tag via add_action(), matched by identity ($fn === the
     * registered callback) and $priority. Needed by
     * RecomputeRankingsController, which registers a temporary listener on
     * 'prode_ranking_cron_ran' to capture RankingCron's counters and MUST
     * remove it again afterwards so repeated requests don't stack listeners
     * (each stacked listener would double-count on the next call).
     *
     * Cleans both bookkeeping arrays add_action() writes to, so a removed
     * callback disappears from do_action() dispatch AND from any test
     * assertion made against _prode_test_action_registrations.
     */
    function remove_action( string $tag, callable $fn, int $priority = 10 ): bool {
        $matched = 0;

        foreach ( $GLOBALS['_prode_test_action_registrations'][ $tag ] ?? [] as $i => $reg ) {
            if ( $reg['callback'] === $fn && $reg['priority'] === $priority ) {
                unset( $GLOBALS['_prode_test_action_registrations'][ $tag ][ $i ] );
                ++$matched;
            }
        }
        if ( isset( $GLOBALS['_prode_test_action_registrations'][ $tag ] ) ) {
            $GLOBALS['_prode_test_action_registrations'][ $tag ] = array_values(
                $GLOBALS['_prode_test_action_registrations'][ $tag ]
            );
        }

        // _prode_test_action_callbacks stores bare callables with no priority,
        // so mirror WordPress by removing only as many occurrences as matched a
        // registration at THIS priority. Removing every identical callable would
        // also detach copies registered at other priorities, which the real
        // remove_action() leaves alone.
        $toRemove = $matched;
        foreach ( $GLOBALS['_prode_test_action_callbacks'][ $tag ] ?? [] as $i => $cb ) {
            if ( 0 === $toRemove ) {
                break;
            }
            if ( $cb === $fn ) {
                unset( $GLOBALS['_prode_test_action_callbacks'][ $tag ][ $i ] );
                --$toRemove;
            }
        }
        if ( isset( $GLOBALS['_prode_test_action_callbacks'][ $tag ] ) ) {
            $GLOBALS['_prode_test_action_callbacks'][ $tag ] = array_values(
                $GLOBALS['_prode_test_action_callbacks'][ $tag ]
            );
        }

        return $matched > 0;
    }
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

        // Credencial-plugin addition (slice 1b, Rest\CredencialController):
        // the real WP_Error stores code/message/data behind these accessor
        // methods, not public properties — CredencialController must call
        // them to behave correctly against REAL WordPress, not just this
        // shim's simplified public-property model (which only ever holds
        // the single error CredencialAuthorizer::authorize() constructs).
        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message( string $code = '' ): string {
            return $this->message;
        }

        public function get_error_data( string $code = '' ): mixed {
            return $this->data;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        /** @var array<string, string> */
        private array $headers = [];
        /** @var array<string, mixed> */
        private array $params = [];
        private string $route = '';

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

        public function set_route( string $route ): void {
            $this->route = $route;
        }

        public function get_route(): string {
            return $this->route;
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

// ─── WP REST API stubs ────────────────────────────────────────────────────────

if ( ! function_exists( 'register_rest_route' ) ) {
    /**
     * Records every call (namespace, route, full $args) into
     * _prode_test_registered_routes so tests can assert a controller's
     * register_routes() wired up the expected path and HTTP method — e.g.
     * RecomputeRankingsControllerTest asserting CREATABLE on
     * 'prode/recompute-rankings' — without a real WP REST server.
     */
    function register_rest_route( string $namespace, string $route, array $args ): bool {
        $GLOBALS['_prode_test_registered_routes'][] = [
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        ];
        return true;
    }
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        public const READABLE  = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE  = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private mixed $data;
        private int $status;
        /** @var array<string, string> */
        private array $headers = [];

        public function __construct( mixed $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }

        /**
         * Mirrors WP_HTTP_Response::header(): $replace = false concatenates
         * onto the existing value with ', ' instead of overwriting it.
         */
        public function header( string $key, string $value, bool $replace = true ): void {
            if ( $replace || ! isset( $this->headers[ $key ] ) ) {
                $this->headers[ $key ] = $value;
            } else {
                $this->headers[ $key ] .= ', ' . $value;
            }
        }

        /** @return array<string, string> */
        public function get_headers(): array {
            return $this->headers;
        }
    }
}

// ─── WP escaping and i18n shims ──────────────────────────────────────────────

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $text ): string {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url ): string {
        return $url;
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = '' ): string {
        return $text;
    }
}

if ( ! function_exists( 'esc_html_e' ) ) {
    function esc_html_e( string $text, string $domain = '' ): void {
        echo $text;
    }
}

if ( ! function_exists( 'esc_attr__' ) ) {
    function esc_attr__( string $text, string $domain = '' ): string {
        return $text;
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = '' ): string {
        return $text;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( mixed $v ): int {
        return abs( (int) $v );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $text ): string {
        return trim( strip_tags( $text ) );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int {
        return 1;
    }
}

if ( ! function_exists( 'check_admin_referer' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_check_admin_referer'] — WHY
     * FIDELITY MATTERS HERE: the real check_admin_referer() calls wp_die()
     * (via wp_nonce_ays()) the moment the nonce is missing or invalid. A
     * hardcoded `1` return — this function's ENTIRE previous body — meant no
     * test in this plugin could ever prove that a mutating admin action
     * (aprobar/rechazar/publicar el lote) actually REJECTS a forged or
     * missing nonce; every such test would pass whether or not the check was
     * even wired up. Defaults to valid (true) when the test sets nothing, so
     * every OTHER existing test that never touches this global keeps the
     * exact behavior it had before (a no-op pass). A test that needs to
     * prove rejection sets the global to `false` first.
     */
    function check_admin_referer( string $action = '-1', string $query_arg = '_wpnonce' ): int|false {
        global $wp_test_check_admin_referer;

        if ( false === ( $wp_test_check_admin_referer ?? true ) ) {
            wp_die( 'Verificacion de seguridad fallida (nonce invalido o ausente).' );
        }

        return 1;
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( string $action, string $name, bool $referer = true, bool $echo = true ): string {
        return '';
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_wp_verify_nonce'] — same
     * fidelity rationale as check_admin_referer() above: a hardcoded `1`
     * meant no caller of wp_verify_nonce() directly (rather than through
     * check_admin_referer()) could ever be proven to reject a bad nonce
     * either. Defaults to valid (1) so every test that never sets this
     * global is unaffected.
     */
    function wp_verify_nonce( string $nonce, string $action ): int|false {
        global $wp_test_wp_verify_nonce;

        return $wp_test_wp_verify_nonce ?? 1;
    }
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_current_user_display_name'] (and
     * optionally ['user_login']) — BandejaPage snapshots this value into
     * `cambios_decision.decidida_por_nombre` at the exact moment of each
     * decision (see SolicitudRepository's "cambios_decision" docblock).
     * Tests change this global BETWEEN two decisions to prove the snapshot
     * is never re-derived later from a "current" name.
     */
    function wp_get_current_user(): object {
        global $wp_test_current_user_display_name, $wp_test_current_user_login;

        return (object) [
            'display_name' => $wp_test_current_user_display_name ?? 'Admin',
            'user_login'   => $wp_test_current_user_login ?? 'admin',
        ];
    }
}

if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( string $location, int $status = 302 ): bool {
        return true;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( mixed ...$args ): string {
        return '';
    }
}

// ─── SportsPress / WP post-meta stubs ─────────────────────────────────────────
// These are no-ops in test context. The WpRosterResolver is tested indirectly
// through the RosterResolverInterface seam (fake resolver injected in tests).

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
    // Credencial-plugin addition (slice 1b, CredencialService's photo gate):
    // data-driven via $wp_test_post_thumbnail_urls, keyed by post id, instead
    // of the shared shim's hardcoded `false` — that default is preserved for
    // any post id the test never sets. Kept in the SAME global as
    // has_post_thumbnail() below so a test only ever has to set one array to
    // control both "does this player have an approved photo" and "what is
    // its URL".
    function get_the_post_thumbnail_url( int|string $post = 0, mixed $size = 'post-thumbnail' ): string|false {
        global $wp_test_post_thumbnail_urls;

        return $wp_test_post_thumbnail_urls[ (int) $post ] ?? false;
    }
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
    // Credencial-plugin addition (slice 1b): a player "has an approved
    // photo" exactly when get_the_post_thumbnail_url() would return a real
    // URL for it — same $wp_test_post_thumbnail_urls global, so a test can
    // never set one without the other silently disagreeing.
    function has_post_thumbnail( int|string $post = 0 ): bool {
        global $wp_test_post_thumbnail_urls;

        return false !== ( $wp_test_post_thumbnail_urls[ (int) $post ] ?? false );
    }
}

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
        // Data-driven so tests can reproduce real postmeta shapes — notably the
        // multi-row sp_current_team that WpRosterResolver has to survive. Empty
        // globals reproduce the previous stub exactly.
        global $wp_test_postmeta;
        $rows = $wp_test_postmeta[ $post_id ][ $key ] ?? [];

        return $single ? ( $rows[0] ?? '' ) : $rows;
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    // Credencial-plugin addition (slice 2b, Photo\WpMediaWriter): writes into
    // the SAME $wp_test_postmeta global get_post_meta() already reads, so a
    // test never has to reason about two disagreeing meta stores. Used for
    // attachment meta (_credencial_request_id) — attachment ids ARE post ids
    // in real WordPress, so one global covers both attachments and players.
    function update_post_meta( int $post_id, string $key, mixed $value ): bool {
        global $wp_test_postmeta;
        $wp_test_postmeta[ $post_id ][ $key ] = [ $value ];
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( int $post_id, string $key ): bool {
        global $wp_test_postmeta;
        unset( $wp_test_postmeta[ $post_id ][ $key ] );
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta_by_key' ) ) {
    /**
     * Credencial-plugin addition (migration 0.1.0 -> 0.2.0,
     * Migrations\MigrationRunner): deletes a meta key across EVERY post,
     * mirroring real WordPress's own `delete_post_meta_by_key()`. Used for
     * the one-time cleanup of the retired `_credencial_sha256` meta (design
     * rev 9's removed publish-tail step) — production data predates this
     * migration and is scattered across every player post, not one caller-
     * supplied id.
     */
    function delete_post_meta_by_key( string $key ): bool {
        global $wp_test_postmeta;

        foreach ( array_keys( $wp_test_postmeta ?? [] ) as $post_id ) {
            unset( $wp_test_postmeta[ $post_id ][ $key ] );
        }

        return true;
    }
}

if ( ! function_exists( 'get_the_title' ) ) {
    function get_the_title( int|string $post = 0 ): string {
        global $wp_test_post_titles;

        return (string) ( $wp_test_post_titles[ (int) $post ] ?? '' );
    }
}

// ─── WP media / attachment shims (Photo\WpMediaWriter, slice 2b) ─────────────
// Faithful enough to real WordPress for design D11's approve pipeline: upload
// an unlinked attachment, tag it, dedupe, generate metadata, and publish it as
// a player's featured image. $_prode_test_attachments is keyed by a fake
// auto-incrementing attachment (post) id; meta for that same id lives in the
// EXISTING $wp_test_postmeta global (get_post_meta()/update_post_meta()
// above) — attachment ids ARE post ids in real WordPress.

if ( ! function_exists( 'wp_upload_bits' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_upload_bits_fails']. Records every
     * written "file" path (never actually touching disk) in
     * $GLOBALS['_prode_test_uploaded_files'] so a test can assert
     * WpMediaWriter deletes the file it just wrote when the following
     * wp_insert_attachment() call fails (design D11 step (b)).
     */
    function wp_upload_bits( string $filename, mixed $deprecated, string $bits ): array {
        global $wp_test_upload_bits_fails;

        if ( $wp_test_upload_bits_fails ?? false ) {
            return [ 'file' => '', 'url' => '', 'type' => '', 'error' => 'simulated upload failure' ];
        }

        $file = '/tmp/wp-uploads/' . $filename;
        $GLOBALS['_prode_test_uploaded_files'][ $file ] = $bits;

        return [ 'file' => $file, 'url' => 'http://example.com/wp-content/uploads/' . $filename, 'type' => 'image/jpeg', 'error' => false ];
    }
}

if ( ! function_exists( 'wp_insert_attachment' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_insert_attachment_fails']. Assigns a
     * fake auto-incrementing id via $GLOBALS['_prode_test_next_attachment_id']
     * — deliberately a SEPARATE counter from any other post id sequence in
     * this shim, since real WordPress attachment ids share the wp_posts
     * sequence with every other post type, and no test in this plugin ever
     * asserts a specific numeric id, only relative behavior (lowest id wins,
     * ids differ across uploads).
     */
    function wp_insert_attachment( array $args, string $file = '', int $parent = 0 ): int|WP_Error {
        global $wp_test_insert_attachment_fails;

        if ( $wp_test_insert_attachment_fails ?? false ) {
            return new WP_Error( 'db_insert_error', 'Could not insert attachment into the database.' );
        }

        $id = ( $GLOBALS['_prode_test_next_attachment_id'] ?? 0 ) + 1;
        $GLOBALS['_prode_test_next_attachment_id'] = $id;

        $GLOBALS['_prode_test_attachments'][ $id ] = [
            'file'           => $file,
            'post_mime_type' => (string) ( $args['post_mime_type'] ?? '' ),
        ];

        // Also registered in $wp_test_posts (the SAME global get_post() reads,
        // see the WP_Post/get_post block above) so WpMediaWriter::attachmentExists()
        // can use the real get_post() function like any other caller, rather
        // than reaching into this shim's internal bookkeeping.
        $GLOBALS['wp_test_posts'][ $id ] = [ 'post_type' => 'attachment', 'post_status' => 'inherit' ];

        return $id;
    }
}

if ( ! function_exists( 'wp_delete_attachment' ) ) {
    function wp_delete_attachment( int $attachment_id, bool $force_delete = false ): mixed {
        $existed = isset( $GLOBALS['_prode_test_attachments'][ $attachment_id ] );

        if ( $existed ) {
            $file = $GLOBALS['_prode_test_attachments'][ $attachment_id ]['file'];
            unset( $GLOBALS['_prode_test_uploaded_files'][ $file ] );
        }

        unset( $GLOBALS['_prode_test_attachments'][ $attachment_id ] );
        unset( $GLOBALS['wp_test_posts'][ $attachment_id ] );
        unset( $GLOBALS['wp_test_postmeta'][ $attachment_id ] );
        unset( $GLOBALS['wp_test_attachment_metadata'][ $attachment_id ] );

        return $existed ? (object) [ 'ID' => $attachment_id ] : false;
    }
}

if ( ! function_exists( 'wp_delete_file' ) ) {
    function wp_delete_file( string $file ): void {
        unset( $GLOBALS['_prode_test_uploaded_files'][ $file ] );
    }
}

if ( ! function_exists( 'get_attached_file' ) ) {
    function get_attached_file( int $attachment_id ): string|false {
        return $GLOBALS['_prode_test_attachments'][ $attachment_id ]['file'] ?? false;
    }
}

// wp_generate_attachment_metadata() is deliberately NOT pre-defined here.
// In real WordPress it lives in wp-admin/includes/image.php, which is not
// part of the standard bootstrap — WpMediaWriter::ensureMetadataGenerated()
// must require_once it itself (see the require guard there). Pre-defining it
// in this always-loaded shim would hide a missing-require regression from
// the whole test suite (see tests/Photo/WpMediaWriterTest.php's
// test_ensureMetadataGenerated_only_works_because_it_loads_wp_admin_includes_image_php).
// The fixture that stands in for the real file lives at the resolved test
// ABSPATH: tests/fixtures/wordpress/wp-admin/includes/image.php.

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
    function wp_update_attachment_metadata( int $attachment_id, array $data ): bool {
        $GLOBALS['wp_test_attachment_metadata'][ $attachment_id ] = $data;
        return true;
    }
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
    function wp_get_attachment_metadata( int $attachment_id ): array|false {
        return $GLOBALS['wp_test_attachment_metadata'][ $attachment_id ] ?? false;
    }
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
    function get_post_thumbnail_id( int|string $post = 0 ): int|false {
        global $wp_test_post_thumbnail_ids;

        return $wp_test_post_thumbnail_ids[ (int) $post ] ?? false;
    }
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
    /**
     * Keeps $wp_test_post_thumbnail_ids AND the pre-existing
     * $wp_test_post_thumbnail_urls (get_the_post_thumbnail_url() /
     * has_post_thumbnail(), slice 1b) in agreement — a test must never be
     * able to set one without the other silently disagreeing, same
     * rationale as those two functions' own docblocks.
     */
    function set_post_thumbnail( int|string $post, int $attachment_id ): bool {
        global $wp_test_post_thumbnail_ids, $wp_test_post_thumbnail_urls;

        $wp_test_post_thumbnail_ids[ (int) $post ]  = $attachment_id;
        $wp_test_post_thumbnail_urls[ (int) $post ] = 'http://example.com/wp-content/uploads/attachment-' . $attachment_id . '.jpg';

        return true;
    }
}

if ( ! function_exists( 'get_posts' ) ) {
    /**
     * Minimal subset of real WordPress's get_posts(): only the shape
     * WpMediaWriter::findAttachmentsTaggedWithRequest() uses —
     * `post_type => 'attachment'`, a single `meta_key`/`meta_value` pair, and
     * `fields => 'ids'`. Scans $_prode_test_attachments joined against the
     * shared $wp_test_postmeta global.
     *
     * @param array<string, mixed> $args
     * @return array<int, int>
     */
    function get_posts( array $args = [] ): array {
        if ( ( $args['post_type'] ?? '' ) !== 'attachment' || ! isset( $args['meta_key'], $args['meta_value'] ) ) {
            return [];
        }

        $metaKey   = (string) $args['meta_key'];
        $metaValue = $args['meta_value'];
        $matches   = [];

        foreach ( array_keys( $GLOBALS['_prode_test_attachments'] ?? [] ) as $attachmentId ) {
            $stored = $GLOBALS['wp_test_postmeta'][ $attachmentId ][ $metaKey ][0] ?? null;
            if ( null !== $stored && (string) $stored === (string) $metaValue ) {
                $matches[] = $attachmentId;
            }
        }

        return $matches;
    }
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
    function wp_create_nonce( int|string $action = -1 ): string {
        return 'nonce_' . md5( (string) $action );
    }
}

// ─── WP_Post / get_post / revision shims (ResultChangeListener, ADR-G7-1) ─────
// Data-driven via $wp_test_posts, keyed by post ID, so tests can reproduce the
// exact shapes ResultChangeListener::onSavePost() reads: post_type, post_status.

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post {
        public int $ID;
        public string $post_type;
        public string $post_status;
        public string $post_title;
        public string $post_date;

        public function __construct(
            int $id,
            string $post_type,
            string $post_status,
            string $post_title = '',
            string $post_date = ''
        ) {
            $this->ID          = $id;
            $this->post_type   = $post_type;
            $this->post_status = $post_status;
            $this->post_title  = $post_title;
            $this->post_date   = $post_date;
        }
    }
}

if ( ! function_exists( 'get_post' ) ) {
    // post_title / post_date are credencial-plugin additions (PlayerReader,
    // slice 1b) on top of the shared shim this file was copied from —
    // defaulted to '' so the pre-existing ResultChangeListener-style fixtures
    // (post_type/post_status only) keep working unchanged.
    function get_post( int $post_id ): ?WP_Post {
        global $wp_test_posts;

        $row = $wp_test_posts[ $post_id ] ?? null;
        if ( null === $row ) {
            return null;
        }

        return new WP_Post(
            $post_id,
            $row['post_type'],
            $row['post_status'],
            (string) ( $row['post_title'] ?? '' ),
            (string) ( $row['post_date'] ?? '' )
        );
    }
}

if ( ! function_exists( 'wp_is_post_revision' ) ) {
    function wp_is_post_revision( int $post_id ): bool {
        global $wp_test_post_revisions;

        return (bool) ( $wp_test_post_revisions[ $post_id ] ?? false );
    }
}

// ─── WP-Cron scheduling shim ──────────────────────────────────────────────────
// Records every call so tests can assert what was scheduled — hook, args, and
// approximate fire time — without a real WP-Cron runtime.

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
    $GLOBALS['_prode_test_scheduled_events'] = [];

    function wp_schedule_single_event( int $timestamp, string $hook, array $args = [] ): bool {
        $GLOBALS['_prode_test_scheduled_events'][] = [
            'timestamp' => $timestamp,
            'hook'      => $hook,
            'args'      => $args,
        ];
        return true;
    }
}

// ─── Misc WP functions ────────────────────────────────────────────────────────

if ( ! function_exists( 'version_compare' ) ) {
    // PHP built-in; never needed. Here only for clarity.
}

// ─── WP_List_Table stub ───────────────────────────────────────────────────────
// Minimal stub so subclasses (PredictionsListTable, AuditLogListTable, etc.)
// can be loaded and their non-rendering methods tested headlessly.

if ( ! class_exists( 'WP_List_Table' ) ) {
    class WP_List_Table {
        /** @var array<string, mixed> */
        protected array $_column_headers = [];
        /** @var array<int, mixed> */
        public array $items = [];

        /** @param array<string, mixed> $args */
        public function __construct( array $args = [] ) {}

        /** @param array<string, mixed> $args */
        protected function set_pagination_args( array $args ): void {}

        public function prepare_items(): void {}

        public function display(): void {}

        public function no_items(): void {}

        /** @return array<string, string> */
        public function get_columns(): array {
            return [];
        }
    }
}

// ─── WP admin URL shim ───────────────────────────────────────────────────────

if ( ! function_exists( 'admin_url' ) ) {
    function admin_url( string $path = '' ): string {
        return 'http://example.com/wp-admin/' . ltrim( $path, '/' );
    }
}

if ( ! function_exists( 'get_admin_page_title' ) ) {
    function get_admin_page_title(): string {
        return 'Admin Page';
    }
}

if ( ! function_exists( 'current_user_can' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_current_user_can'] — WHY
     * FIDELITY MATTERS HERE: a hardcoded `false` return (this function's
     * ENTIRE previous body) means no test could ever prove that a permission
     * check ADMITS an authorized user — only ever that it rejects, and even
     * that "proof" was really just this function always saying no, not the
     * check itself doing anything. ProcessOwnerAuthorizer's contract has TWO
     * directions (grants `gestionar_cambios`, denies everything else) and a
     * fixed response can only ever exercise one of them.
     *
     * Accepts either a bare bool (blanket answer for every capability) or an
     * array keyed by capability string, so a test can grant `gestionar_cambios`
     * specifically without having to reason about every other capability
     * this shim might be asked about. Defaults to `false` when the test sets
     * nothing — the exact same fail-closed default this function always had,
     * so every OTHER existing test that never touches this global is
     * unaffected.
     */
    function current_user_can( string $capability ): bool {
        global $wp_test_current_user_can;

        if ( is_array( $wp_test_current_user_can ) ) {
            return (bool) ( $wp_test_current_user_can[ $capability ] ?? false );
        }

        return (bool) ( $wp_test_current_user_can ?? false );
    }
}

if ( ! function_exists( 'wp_die' ) ) {
    function wp_die( string $message = '', string $title = '', mixed $args = [] ): void {
        throw new \RuntimeException( $message );
    }
}

if ( ! function_exists( 'selected' ) ) {
    function selected( mixed $selected, mixed $current = true, bool $echo = true ): string {
        $result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
        if ( $echo ) {
            echo $result;
        }
        return $result;
    }
}

if ( ! function_exists( 'submit_button' ) ) {
    function submit_button( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, mixed $other_attributes = null ): void {
        echo '<input type="submit" name="' . $name . '" value="' . $text . '">';
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( string $location, int $status = 302 ): bool {
        return true;
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}

// ─── WP admin menu / hook stubs ───────────────────────────────────────────────

if ( ! function_exists( 'is_admin' ) ) {
    /**
     * Controllable via $GLOBALS['wp_test_is_admin'] — Plugin::boot() only
     * builds and registers the process-owner admin bandeja `if ( is_admin() )`
     * (see Plugin.php); a wiring test needs to simulate an actual wp-admin
     * request to prove that registration happens at all. Defaults to `false`
     * — the exact previous, hardcoded behavior — so every OTHER existing
     * test that never touches this global is unaffected.
     */
    function is_admin(): bool {
        global $wp_test_is_admin;

        return (bool) ( $wp_test_is_admin ?? false );
    }
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
    // Return a future timestamp so Plugin::boot() does NOT call scheduleCrons(),
    // which would require wp_schedule_event() and other cron functions not
    // needed for admin wiring tests.
    function wp_next_scheduled( string $hook, array $args = [] ): int|false {
        return time() + 3600;
    }
}

if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, mixed $deprecated = false, mixed $plugin_rel_path = false ): bool {
        return true;
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string {
        return basename( $file );
    }
}

if ( ! defined( 'WP_CLI' ) ) {
    define( 'WP_CLI', false );
}

if ( ! function_exists( 'add_menu_page' ) ) {
    /**
     * Records every call into _prode_test_registered_admin_menus so a wiring
     * test can assert Plugin::boot() actually registered the admin menu with
     * the expected slug AND capability — same "prove the CABLE, not just the
     * pieces" rationale as _prode_test_registered_routes for REST routes
     * (see tests/PluginTest.php).
     */
    function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $function = null, string $icon_url = '', ?int $position = null ): string {
        $GLOBALS['_prode_test_registered_admin_menus'][] = [
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug'  => $menu_slug,
            'function'   => $function,
        ];

        return $menu_slug;
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $function = null, ?int $position = null ): string|false {
        $GLOBALS['_prode_test_registered_admin_menus'][] = [
            'parent_slug' => $parent_slug,
            'page_title'  => $page_title,
            'menu_title'  => $menu_title,
            'capability'  => $capability,
            'menu_slug'   => $menu_slug,
            'function'    => $function,
        ];

        return $menu_slug;
    }
}

// phpcs:enable
