<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Support;

/**
 * A real SQLite-backed `wpdb` (the test shim's own class — see
 * tests/wp-shim.php) that can be told to fail its NEXT query/get_row/insert
 * call matching a given SQL pattern, with a caller-chosen `last_error`
 * message — used by IssuanceRepositoryTest to exercise failure branches
 * (design's Testing Strategy: "other errors -> 500"; "issuance failure / no
 * row -> 500") that a healthy SQLite database can never produce on its own,
 * and by future slices (2a's duplicate-key tests) for the same reason.
 *
 * EXTENDS the shim's own `wpdb`, never wraps/decorates it: every repository
 * in this plugin type-hints its constructor as `\wpdb` (design convention,
 * mirrored from entre-redes-cambios/entre-redes-prode), so this class must
 * BE a `\wpdb` to be a drop-in replacement in a test, exactly like the real
 * SQLite shim itself already is.
 *
 * Each scripted failure is consumed EXACTLY ONCE (queued, FIFO per method) —
 * so a test can script "fail the 2nd query, then let everything else run
 * normally" without the failure leaking into unrelated calls later in the
 * same test.
 */
final class FaultInjectingWpdb extends \wpdb {

    /** @var array<int, array{pattern: string, error: string}> */
    private array $queryFailures = [];

    /** @var array<int, string> */
    private array $getRowNullMatches = [];

    /** @var array<int, array{pattern: string, sql: string}> */
    private array $sideEffectsBeforeQuery = [];

    /**
     * The NEXT call to query() whose SQL matches $pattern (a PCRE pattern,
     * e.g. '/INSERT INTO wp_credencial_issuance/i') returns false and sets
     * last_error to $errorMessage, instead of actually running.
     */
    public function failNextQueryMatching( string $pattern, string $errorMessage ): void {
        $this->queryFailures[] = [ 'pattern' => $pattern, 'error' => $errorMessage ];
    }

    /**
     * Runs $sideEffectSql (a real statement against this SAME connection)
     * immediately BEFORE the NEXT query() call whose SQL matches $pattern,
     * then lets that query run normally against the now-mutated data —
     * simulates a concurrent writer committing its change in the exact
     * window between this repository's own SELECT and its own UPDATE, which
     * a single-threaded test can otherwise never reproduce. Used by
     * IssuanceRepositoryTest's 0-row-rotation race test.
     */
    public function runSqlBeforeNextQueryMatching( string $pattern, string $sideEffectSql ): void {
        $this->sideEffectsBeforeQuery[] = [ 'pattern' => $pattern, 'sql' => $sideEffectSql ];
    }

    /**
     * The NEXT call to get_row() whose SQL matches $pattern returns null
     * (simulating "no row", including "a row the caller just inserted is
     * somehow unreadable back") instead of actually querying.
     */
    public function forceNextGetRowNullMatching( string $pattern ): void {
        $this->getRowNullMatches[] = $pattern;
    }

    public function query( string $sql ): int|false {
        foreach ( $this->queryFailures as $i => $failure ) {
            if ( 1 === preg_match( $failure['pattern'], $sql ) ) {
                unset( $this->queryFailures[ $i ] );
                $this->last_error = $failure['error'];
                return false;
            }
        }

        foreach ( $this->sideEffectsBeforeQuery as $i => $effect ) {
            if ( 1 === preg_match( $effect['pattern'], $sql ) ) {
                unset( $this->sideEffectsBeforeQuery[ $i ] );
                parent::query( $effect['sql'] );
                break;
            }
        }

        return parent::query( $sql );
    }

    public function get_row( string $sql, string $output = OBJECT ): ?array {
        foreach ( $this->getRowNullMatches as $i => $pattern ) {
            if ( 1 === preg_match( $pattern, $sql ) ) {
                unset( $this->getRowNullMatches[ $i ] );
                return null;
            }
        }

        return parent::get_row( $sql, $output );
    }
}
