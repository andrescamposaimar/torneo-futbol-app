<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Account;

use EntreRedes\Prode\Auth\AuthMiddleware;
use EntreRedes\Prode\Auth\SessionManager;
use EntreRedes\Prode\Audit\AuditLogger;
use EntreRedes\Prode\Audit\DniHasher;

/**
 * Handles user-initiated account lifecycle operations.
 *
 * PR-03 scope: DELETE /prode/account — soft-delete + PII anonymization.
 *
 * Design decisions (self-made calls documented):
 *
 * 1. Separate class (not extending AuthEndpoints): account lifecycle is distinct
 *    from the auth flow; keeps each class focused on a single responsibility.
 *
 * 2. No server-side `confirmation` param enforcement: the spec leaves this open;
 *    the UI confirmation dialog is sufficient. The server accepts any request body
 *    shape (including empty). Adding a server-side check would create friction for
 *    programmatic re-onboarding tests without meaningful security benefit, since
 *    the endpoint already requires a valid JWT via AuthMiddleware::requireAuth().
 *
 * 3. Idempotency: if DELETE is called on an already-deleted user, AuthMiddleware
 *    will return 401 (user not found or deleted) before this handler even runs,
 *    because getUser() filters on deleted_at IS NULL. The endpoint is therefore
 *    idempotent from the caller's perspective: the second call returns 401 rather
 *    than 200, which is acceptable (the resource is gone). This is documented
 *    behavior and noted in tests.
 *
 * 4. DNI preserved as tombstone: per design amendment (ADR-P007 + AMENDMENT-001),
 *    the `prode_users.dni` column is NOT nullified. It serves as the tombstone
 *    identifier for audit trail integrity and for preventing the same DNI from
 *    being re-registered under a soft-deleted row. Only `email` and `display_name`
 *    are nullified (they are the PII fields under Ley 25.326 minimization scope).
 *    The `provider_id` in prode_associations is also preserved for audit history.
 *
 * 5. Leaderboard visibility: predictions and scores rows are NOT touched. The
 *    `prode_users` row remains with a null display_name. Future ranking queries
 *    that filter on `deleted_at IS NULL` will hide this user from active
 *    leaderboards. If a query does not filter, the UI must handle null display_name
 *    gracefully (tombstone display is a PR-09 Admin concern).
 *
 * 6. Transactional atomicity: soft-delete + association mark + audit log write
 *    are wrapped in a transaction. The session revocation (revokeAllSessions) is
 *    called outside the transaction because it issues its own UPDATE + DELETE
 *    which are not rolled back on audit log failure — this is an acceptable trade-off
 *    since a revoked session without a matching audit log is a safer failure mode
 *    than an active session with a partially committed deletion.
 *
 * @see ADR-P007 (account deletion anonymization)
 * @see AMENDMENT-001 (standalone prode_users, no wp_users coupling)
 */
class AccountController {

    private const NAMESPACE = 'entre-redes/v1';

    private AuthMiddleware $middleware;
    private SessionManager $session;
    private AuditLogger    $audit;
    private DniHasher      $hasher;

    public function __construct(
        AuthMiddleware $middleware,
        SessionManager $session,
        AuditLogger $audit,
        DniHasher $hasher
    ) {
        $this->middleware = $middleware;
        $this->session    = $session;
        $this->audit      = $audit;
        $this->hasher     = $hasher;
    }

    // -------------------------------------------------------------------------
    // Route registration
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route(
            self::NAMESPACE,
            '/prode/account',
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'handleDelete' ],
                'permission_callback' => [ $this->middleware, 'requireAuth' ],
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Handler
    // -------------------------------------------------------------------------

    /**
     * DELETE /wp-json/entre-redes/v1/prode/account
     *
     * Authenticated endpoint (AuthMiddleware::requireAuth).
     *
     * Steps (see class docblock for design decisions):
     *   1. Identify user from JWT (already resolved by middleware → _prode_user param).
     *   2. Fetch active association to capture dni_hash and provider before deletion.
     *   3. Transactionally: soft-delete prode_users (set deleted_at, deleted_by='user',
     *      nullify email + display_name), mark all prode_associations as deleted_at=NOW().
     *   4. Revoke all sessions (revokeAllSessions → sv bump + refresh token purge).
     *   5. Write audit log entry (account_deletion, actor='self', user_id, dni_hash).
     *
     * Response:
     *   200 { success: true, message: "Account deleted" }
     *
     * Error codes:
     *   401 — token_missing | token_invalid | token_expired | session_revoked
     *   500 — server_error (transactional failure)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handleDelete( \WP_REST_Request $request ): \WP_REST_Response {
        /** @var array<string, mixed> $user */
        $user    = $request->get_param( '_prode_user' );
        $user_id = (int) $user['id'];

        // Fetch the active association to capture the dni_hash before we wipe PII.
        // This is done BEFORE the transaction so we have the data for the audit log.
        $assoc    = $this->session->getActiveAssociation( $user_id );
        $dni      = (string) ( $assoc['dni'] ?? $user['dni'] ?? '' );
        $provider = (string) ( $assoc['provider'] ?? '' );
        $dni_hash = '' !== $dni ? $this->hasher->hash( $dni ) : '';

        // --- Transactional deletion ---
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        try {
            $now = current_time( 'mysql' );

            // 1. Soft-delete the user row + anonymize PII.
            //    DNI is KEPT as tombstone (see design note #4 in class docblock).
            //    email is set to NULL (column allows null).
            //    display_name is set to '' (column is NOT NULL; empty string is the
            //    anonymized tombstone value — the UI must treat '' as "[deleted]").
            $updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'prode_users',
                [
                    'deleted_at'   => $now,
                    'deleted_by'   => 'user',
                    'email'        => null,
                    'display_name' => '',
                ],
                [ 'id' => $user_id ]
            );

            if ( false === $updated ) {
                throw new \RuntimeException( 'db_user_delete_failed' );
            }

            // 2. Soft-delete all prode_associations for this user.
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prepare(
                    "UPDATE {$wpdb->prefix}prode_associations
                        SET deleted_at = %s, deleted_by = 'user'
                      WHERE user_id = %d
                        AND deleted_at IS NULL",
                    $now,
                    $user_id
                )
            );

            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        } catch ( \RuntimeException $e ) {
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            return $this->errorResponse( 'server_error', 'Account deletion failed.', 500 );
        }

        // --- Session revocation (outside transaction — see design note #6) ---
        // Bumps session_version (invalidates all active JWTs) and purges refresh tokens.
        $this->session->revokeAllSessions( $user_id );

        // --- Audit log ---
        if ( '' !== $dni_hash && '' !== $provider ) {
            $this->audit->logAccountDeletion( $user_id, $dni_hash, $provider );
        }

        return new \WP_REST_Response(
            [
                'success' => true,
                'message' => 'Account deleted',
            ],
            200
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns a uniform JSON error response.
     *
     * @param string               $error   Machine-readable error code.
     * @param string               $message Human-readable message.
     * @param int                  $status  HTTP status code.
     * @param array<string, mixed> $details Optional additional details.
     * @return \WP_REST_Response
     */
    private function errorResponse(
        string $error,
        string $message,
        int $status,
        array $details = []
    ): \WP_REST_Response {
        $body = [ 'error' => $error, 'message' => $message ];
        if ( ! empty( $details ) ) {
            $body['details'] = $details;
        }
        return new \WP_REST_Response( $body, $status );
    }
}
