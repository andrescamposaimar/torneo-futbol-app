<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Support;

/**
 * A real SQLite-backed `wpdb` (extends the test shim's own class — see
 * tests/wp-shim.php) that RECORDS every query it runs and can script the
 * result of an `information_schema.COLUMNS` probe by column name.
 *
 * Exists because the SQLite shim has no `information_schema` at all
 * (InitialSchema's own docblock: "the SQLite DDL translator ... has no
 * information_schema, so the probe returns null and [it] returns early").
 * `ensurePendingKeyIndex()` accepts that as an untestable-under-the-shim
 * no-op, but `InitialSchema::dropLegacyPhotoSha256Column()` (migration
 * 0.1.0 -> 0.2.0) needs its three branches (column exists -> exactly one
 * DROP; column already gone / no information_schema -> no DROP; DROP fails
 * -> no exception) pinned directly, which this class makes possible by
 * standing in for the global `$wpdb` for the duration of one test.
 *
 * EXTENDS the shim's own `wpdb` (not FaultInjectingWpdb — this is a
 * DIFFERENT concern: scripting `get_var()` by column name, not failing a
 * `query()`/`get_row()`/`insert()` call) so every other statement
 * (dbDelta's own CREATE TABLE, ensurePendingKeyIndex's OWN probe) still runs
 * for real against a fresh in-memory SQLite database.
 */
final class RecordingWpdb extends \wpdb {

    /** @var array<int, string> Every SQL string passed to query(), in order. */
    private array $queries = [];

    /**
     * @param array<string, int|null> $columnProbeResults Column name =>
     *        scripted COUNT(*) result ($null simulates "no information_schema",
     *        i.e. what the plain shim already returns for every probe).
     *        A column NOT present in this map falls through to the real
     *        SQLite-backed get_var(), which also returns null for an
     *        information_schema query (no such table) — same end result,
     *        just via the unscripted path.
     */
    public function __construct(
        private readonly array $columnProbeResults = [],
        private readonly bool $forceAlterFailure = false
    ) {
        parent::__construct();
    }

    public function query( string $sql ): int|false {
        $this->queries[] = $sql;

        if ( $this->forceAlterFailure && 1 === preg_match( '/ALTER TABLE .*DROP COLUMN/i', $sql ) ) {
            $this->last_error = 'simulated ALTER failure (RecordingWpdb)';
            return false;
        }

        return parent::query( $sql );
    }

    public function get_var( string $sql ): ?string {
        if ( 1 === preg_match( "/COLUMN_NAME\\s*=\\s*'([^']+)'/", $sql, $m ) && array_key_exists( $m[1], $this->columnProbeResults ) ) {
            $result = $this->columnProbeResults[ $m[1] ];

            return null === $result ? null : (string) $result;
        }

        return parent::get_var( $sql );
    }

    /** @return string[] Every recorded query whose SQL matches $pattern (a PCRE pattern). */
    public function queriesMatching( string $pattern ): array {
        return array_values( array_filter(
            $this->queries,
            static fn ( string $sql ): bool => 1 === preg_match( $pattern, $sql )
        ) );
    }
}
