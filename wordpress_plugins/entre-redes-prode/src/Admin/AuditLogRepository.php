<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Encapsulates all wpdb reads for the prode_audit_log admin page.
 *
 * Read-only repository — no write methods (BIT-04). Queries only
 * prode_audit_log; no JOINs to any other table (BIT-01).
 *
 * Date filtering (BIT-06):
 *   - Invalid / non-parseable date strings are silently ignored (the filter
 *     is simply not applied for that bound). Validated via DateTime::createFromFormat.
 *   - $from only → created_at >= '$from 00:00:00'.
 *   - $to only   → created_at <= '$to 23:59:59'.
 *   - Both       → bounded range.
 */
class AuditLogRepository {

    public function __construct( private \wpdb $wpdb ) {}

    // -------------------------------------------------------------------------
    // Table helper
    // -------------------------------------------------------------------------

    private function tableAuditLog(): string {
        return $this->wpdb->prefix . 'prode_audit_log';
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Returns a paginated list of audit log events matching the given filters.
     *
     * @param string|null $eventType One of the 5 ENUM values, or null for no filter.
     * @param string|null $from      Date string 'Y-m-d'; invalid strings silently ignored.
     * @param string|null $to        Date string 'Y-m-d'; invalid strings silently ignored.
     * @param int         $perPage   Rows per page (BIT-07: 25).
     * @param int         $offset    SQL OFFSET.
     * @return array<int, array<string, mixed>>
     */
    public function listEvents( ?string $eventType, ?string $from, ?string $to, int $perPage, int $offset ): array {
        [ $whereClauses, $bindings ] = $this->buildWhere( $eventType, $from, $to );

        $ta          = $this->tableAuditLog();
        $whereStr    = $whereClauses ? 'WHERE ' . implode( ' AND ', $whereClauses ) : '';
        $bindings[]  = $perPage;
        $bindings[]  = $offset;

        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT * FROM {$ta} {$whereStr} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$bindings
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Returns the total count of audit log events matching the given filters.
     * Use this alongside listEvents() for pagination (BIT-07).
     */
    public function countEvents( ?string $eventType, ?string $from, ?string $to ): int {
        [ $whereClauses, $bindings ] = $this->buildWhere( $eventType, $from, $to );

        $ta       = $this->tableAuditLog();
        $whereStr = $whereClauses ? 'WHERE ' . implode( ' AND ', $whereClauses ) : '';

        if ( $bindings ) {
            $sql = $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT COUNT(*) FROM {$ta} {$whereStr}",
                ...$bindings
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $sql = "SELECT COUNT(*) FROM {$ta}";
        }

        return (int) $this->wpdb->get_var( $sql );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Builds the WHERE clauses and parameter bindings from the filter arguments.
     *
     * Returns [ string[] $clauses, mixed[] $bindings ].
     *
     * @return array{0: string[], 1: mixed[]}
     */
    private function buildWhere( ?string $eventType, ?string $from, ?string $to ): array {
        $clauses  = [];
        $bindings = [];

        if ( null !== $eventType && '' !== $eventType ) {
            $clauses[]  = 'event_type = %s';
            $bindings[] = $eventType;
        }

        $validFrom = $this->parseDate( $from );
        if ( null !== $validFrom ) {
            $clauses[]  = "created_at >= %s";
            $bindings[] = $validFrom . ' 00:00:00';
        }

        $validTo = $this->parseDate( $to );
        if ( null !== $validTo ) {
            $clauses[]  = "created_at <= %s";
            $bindings[] = $validTo . ' 23:59:59';
        }

        return [ $clauses, $bindings ];
    }

    /**
     * Validates a date string in 'Y-m-d' format.
     *
     * Returns the sanitised date string on success or null if invalid / empty
     * (BIT-06: invalid strings silently ignored).
     */
    private function parseDate( ?string $date ): ?string {
        if ( null === $date || '' === $date ) {
            return null;
        }

        $dt = \DateTime::createFromFormat( 'Y-m-d', $date );
        if ( false === $dt ) {
            return null;
        }

        // Confirm createFromFormat did not silently overflow (e.g. "2026-13-40").
        $errors = \DateTime::getLastErrors();
        if ( $errors && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
            return null;
        }

        return $dt->format( 'Y-m-d' );
    }
}
