<?php

declare(strict_types=1);

/**
 * Minimal WordPress shim for PHPUnit standalone execution.
 *
 * Trimmed from entre-redes-prode/tests/wp-shim.php (design §1 / §9). Keeps
 * only what this plugin's tests actually exercise: wpdb/PDO, dbDelta,
 * options, transients, escaping, WP_List_Table, add_menu_page /
 * add_submenu_page, WP_REST_*. Dropped from the prode original: the JWT
 * signing helpers (wp_salt, wp_generate_password, wp_generate_uuid4), the
 * cron shims (wp_next_scheduled — this plugin schedules no cron and calls
 * no wp_schedule_event, see PluginNoCronTest), and the OAuth/HTTP auth
 * shims (wp_remote_*, is_wp_error, WP_Error, get_site_url).
 *
 * This is NOT a full WP emulation. It handles:
 *   - $wpdb with SQLite-backed get_charset_collate() and query()/get_var()
 *   - dbDelta() that maps MySQL DDL to SQLite CREATE TABLE (schema-only check)
 *   - get_option() / update_option() / delete_option() backed by a static array
 *   - get_transient() / set_transient() / delete_transient() backed by a static array
 *   - add_action() / do_action() / did_action() / add_filter()
 *   - esc_html() / esc_attr() / esc_url() / esc_html__() / esc_html_e() / esc_attr__() / __()
 *   - WP_List_Table, add_menu_page(), add_submenu_page()
 *   - WP_REST_Request, WP_REST_Response, WP_REST_Server, register_rest_route()
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
         * SQLite equivalents (DDL is handled separately in _campeones_mysql_to_sqlite).
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
         * Returns all rows, honouring $output exactly like real WordPress:
         * ARRAY_A returns associative arrays, anything else (including the
         * OBJECT default) returns stdClass rows. Previously this ignored
         * $output entirely and always returned arrays, which hid TypeError
         * bugs in callers that forgot to pass ARRAY_A and rely on the real
         * wpdb::get_row()/get_results() OBJECT default (see
         * TitleRepository::find()/findByKey()).
         *
         * @return array<int, array<string, mixed>|\stdClass>
         */
        public function get_results( string $sql, string $output = OBJECT ): array {
            try {
                $stmt = $this->pdo->query( $sql );
                $rows = $stmt->fetchAll( \PDO::FETCH_ASSOC );
            } catch ( \PDOException $e ) {
                return [];
            }

            if ( ARRAY_A === $output ) {
                return $rows;
            }

            return array_map( static fn( array $row ): \stdClass => (object) $row, $rows );
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

        public function get_row( string $sql, string $output = OBJECT ): object|array|null {
            $rows = $this->get_results( $sql, $output );
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
            $cols         = implode( ', ', array_keys( $data ) );
            $placeholders = implode( ', ', array_fill( 0, count( $data ), '?' ) );
            $sql          = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";
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
            $sqlite_sql = _campeones_mysql_to_sqlite( $sql );
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

    function _campeones_mysql_to_sqlite( string $sql ): ?string {
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
    $GLOBALS['_campeones_test_options'] = [];

    function get_option( string $key, mixed $default = false ): mixed {
        return $GLOBALS['_campeones_test_options'][ $key ] ?? $default;
    }

    function update_option( string $key, mixed $value, bool $autoload = true ): bool {
        $GLOBALS['_campeones_test_options'][ $key ] = $value;
        return true;
    }

    function delete_option( string $key ): bool {
        unset( $GLOBALS['_campeones_test_options'][ $key ] );
        return true;
    }
}

// ─── WP time shim ────────────────────────────────────────────────────────────

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string {
        return ( new \DateTime( 'now', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
    }
}

// ─── WP hook shims ────────────────────────────────────────────────────────────

if ( ! function_exists( 'add_action' ) ) {
    /**
     * Records the callback (unlike a pure no-op) so tests can invoke deferred
     * hooks — e.g. admin_notices closures — the same way WordPress would when
     * rendering wp-admin. do_action() below fires them; did_action()'s
     * counter is unaffected.
     */
    function add_action( string $tag, callable $fn, int $priority = 10, int $accepted_args = 1 ): true {
        $GLOBALS['_campeones_test_action_callbacks'][ $tag ][] = $fn;
        return true;
    }
}

if ( ! function_exists( 'do_action' ) ) {
    $GLOBALS['_campeones_test_actions'] = [];

    function do_action( string $tag, mixed ...$args ): void {
        $GLOBALS['_campeones_test_actions'][ $tag ] = ( $GLOBALS['_campeones_test_actions'][ $tag ] ?? 0 ) + 1;
        foreach ( $GLOBALS['_campeones_test_action_callbacks'][ $tag ] ?? [] as $fn ) {
            $fn( ...$args );
        }
    }
}

if ( ! function_exists( 'did_action' ) ) {
    function did_action( string $tag ): int {
        return $GLOBALS['_campeones_test_actions'][ $tag ] ?? 0;
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

// ─── WP transient shims ───────────────────────────────────────────────────────

if ( ! function_exists( 'get_transient' ) ) {
    $GLOBALS['_campeones_test_transients'] = [];

    function get_transient( string $key ): mixed {
        return $GLOBALS['_campeones_test_transients'][ $key ] ?? false;
    }

    function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
        $GLOBALS['_campeones_test_transients'][ $key ] = $value;
        return true;
    }

    function delete_transient( string $key ): bool {
        unset( $GLOBALS['_campeones_test_transients'][ $key ] );
        return true;
    }
}

// ─── WP REST API stubs ────────────────────────────────────────────────────────

if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( string $namespace, string $route, array $args ): bool {
        return true;
    }
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        public const READABLE   = 'GET';
        public const CREATABLE  = 'POST';
        public const EDITABLE   = 'POST, PUT, PATCH';
        public const DELETABLE  = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
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

if ( ! function_exists( 'check_admin_referer' ) ) {
    function check_admin_referer( string $action = '-1' ): int {
        return 1;
    }
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
    function wp_nonce_field( string $action, string $name, bool $referer = true, bool $echo = true ): string {
        return '';
    }
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
    function wp_verify_nonce( string $nonce, string $action ): int|false {
        return 1;
    }
}

if ( ! function_exists( 'check_ajax_referer' ) ) {
    function check_ajax_referer( int|string $action = -1, mixed $query_arg = false, bool $die = true ): int|false {
        return 1;
    }
}

if ( ! function_exists( 'wp_redirect' ) ) {
    function wp_redirect( string $location, int $status = 302 ): bool {
        return true;
    }
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
    function wp_safe_redirect( string $location, int $status = 302 ): bool {
        return true;
    }
}

if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( mixed ...$args ): string {
        return '';
    }
}

// ─── WP_List_Table stub ───────────────────────────────────────────────────────
// Minimal stub so subclasses (TitlesListTable, ReviewQueueListTable, etc. —
// slices 3-4) can be loaded and their non-rendering methods tested headlessly.

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

// ─── WP admin shims ───────────────────────────────────────────────────────────

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
    function current_user_can( string $capability ): bool {
        return false;
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

if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool {
        return false;
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

if ( ! function_exists( 'add_menu_page' ) ) {
    function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $function = null, string $icon_url = '', ?int $position = null ): string {
        return $menu_slug;
    }
}

if ( ! function_exists( 'add_submenu_page' ) ) {
    function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $function = null, ?int $position = null ): string|false {
        return $menu_slug;
    }
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}

// phpcs:enable
