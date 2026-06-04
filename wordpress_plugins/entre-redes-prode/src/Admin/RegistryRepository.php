<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Admin;

/**
 * Encapsulates all wpdb persistence for the player registry admin page.
 *
 * Queries prode_users + prode_associations only — NO JOIN to wp_users
 * (CC-06, AMENDMENT-001). Association soft-delete runs inside a transaction
 * with a deleted_at IS NULL guard for idempotency (D7, REG-06, EDGE-03).
 */
class RegistryRepository {

    public function __construct( private \wpdb $wpdb ) {}

    // -------------------------------------------------------------------------
    // Table helpers
    // -------------------------------------------------------------------------

    private function tableUsers(): string {
        return $this->wpdb->prefix . 'prode_users';
    }

    private function tableAssociations(): string {
        return $this->wpdb->prefix . 'prode_associations';
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Returns a paginated list of users (+ their latest association) for the
     * given tenant.
     *
     * JOIN is prode_users LEFT JOIN prode_associations on user_id — no wp_users.
     * The LEFT JOIN returns users that have no association at all (possible
     * edge state); the caller renders the Acciones cell conditionally.
     *
     * @param string $tenantId
     * @param bool   $activeOnly true → WHERE prode_users.deleted_at IS NULL
     * @param int    $perPage    rows per page (REG-03: 25)
     * @param int    $offset     SQL OFFSET
     * @return array<int, array<string, mixed>>
     */
    public function listUsers( string $tenantId, bool $activeOnly, int $perPage, int $offset ): array {
        $tu = $this->tableUsers();
        $ta = $this->tableAssociations();

        $deletedClause = $activeOnly ? 'AND u.deleted_at IS NULL' : 'AND u.deleted_at IS NOT NULL';

        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT u.id,
                    u.display_name,
                    u.email,
                    u.created_at,
                    u.last_login_at,
                    u.deleted_at,
                    a.id AS assoc_id,
                    a.provider,
                    a.dni,
                    a.player_id,
                    a.deleted_at AS assoc_deleted_at
               FROM {$tu} u
               LEFT JOIN {$ta} a ON a.user_id = u.id AND a.deleted_at IS NULL
              WHERE u.tenant_id = %s
                {$deletedClause}
              ORDER BY u.id ASC
              LIMIT %d OFFSET %d",
            $tenantId,
            $perPage,
            $offset
        );

        return $this->wpdb->get_results( $sql, ARRAY_A ) ?: [];
    }

    /**
     * Returns the total count of users matching the filter for pagination
     * (REG-03, REG-04).
     */
    public function countUsers( string $tenantId, bool $activeOnly ): int {
        $tu = $this->tableUsers();

        $deletedClause = $activeOnly ? 'AND deleted_at IS NULL' : 'AND deleted_at IS NOT NULL';

        $sql = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT COUNT(*) FROM {$tu} WHERE tenant_id = %s {$deletedClause}",
            $tenantId
        );

        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Fetches the data needed to perform an unlink for a given user.
     *
     * Returns the active association row (with user_id, player_id, provider,
     * dni) or null when no active association exists (EDGE-03: already unlinked).
     *
     * @return array<string, mixed>|null
     */
    public function findUserForUnlink( string $tenantId, int $userId ): ?array {
        $tu = $this->tableUsers();
        $ta = $this->tableAssociations();

        $sql = $this->wpdb->prepare(
            "SELECT a.id AS assoc_id,
                    a.user_id,
                    a.provider,
                    a.provider_id,
                    a.dni,
                    a.player_id
               FROM {$ta} a
               INNER JOIN {$tu} u ON u.id = a.user_id
              WHERE a.user_id = %d
                AND u.tenant_id = %s
                AND a.deleted_at IS NULL
              LIMIT 1",
            $userId,
            $tenantId
        );

        return $this->wpdb->get_row( $sql, ARRAY_A );
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * Soft-deletes the active association for $userId inside a transaction.
     *
     * Sets deleted_at = NOW(), deleted_by = 'admin', deleted_actor_wp_id = $actorWpId
     * WHERE user_id = $userId AND deleted_at IS NULL.
     *
     * Returns true when exactly 1 row was affected.
     * Returns false when 0 rows affected (already unlinked — EDGE-03 detection
     * is the caller's responsibility to handle as an informational notice).
     *
     * @param int $userId    prode_users.id of the user to unlink.
     * @param int $actorWpId WP user ID of the admin performing the action.
     */
    public function unlinkAssociation( int $userId, int $actorWpId ): bool {
        $ta  = $this->tableAssociations();
        $now = current_time( 'mysql' );

        $this->wpdb->query( 'START TRANSACTION' );

        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$ta}
                    SET deleted_at = %s,
                        deleted_by = 'admin',
                        deleted_actor_wp_id = %d
                  WHERE user_id = %d
                    AND deleted_at IS NULL",
                $now,
                $actorWpId,
                $userId
            )
        );

        $this->wpdb->query( 'COMMIT' );

        // result is the number of affected rows (int) or false on error.
        return is_int( $result ) && $result > 0;
    }
}
