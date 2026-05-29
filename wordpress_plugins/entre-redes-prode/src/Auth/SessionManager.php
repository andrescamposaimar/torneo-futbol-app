<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Auth;

use Ramsey\Uuid\Uuid;

/**
 * Manages user sessions: refresh token lifecycle and session_version revocation.
 *
 * Refresh tokens are opaque UUIDs stored in prode_refresh_tokens as their
 * SHA-256 hash (token_hash). The plain token value is returned ONCE to the
 * client at issuance and never stored again.
 *
 * Rotation semantics:
 *   On every /auth/refresh call, the old refresh token is revoked (revoked_at
 *   set) and a new token is issued. A replayed old token is detected because
 *   its revoked_at is non-null → 401.
 *
 * Session_version revocation (ADR-P003):
 *   Incrementing prode_users.session_version invalidates ALL existing access
 *   tokens for that user (they carry the old sv claim). It also purges ALL
 *   refresh tokens for the user. Used by admin unlink and account deletion.
 */
class SessionManager {

    private const REFRESH_TTL_DAYS = 30;

    // -------------------------------------------------------------------------
    // Refresh token lifecycle
    // -------------------------------------------------------------------------

    /**
     * Issues a new refresh token for a user.
     *
     * @param int    $user_id      prode_users.id
     * @param string $device_label Optional hint (user-agent derived), stored for audit.
     * @return string Plain refresh token UUID (returned to client; not stored).
     */
    public function issueRefreshToken( int $user_id, string $device_label = '' ): string {
        global $wpdb;

        $token       = Uuid::uuid4()->toString();
        $token_hash  = hash( 'sha256', $token );
        $jti         = Uuid::uuid4()->toString();
        $now         = current_time( 'mysql' );
        $expires_at  = gmdate( 'Y-m-d H:i:s', time() + ( self::REFRESH_TTL_DAYS * DAY_IN_SECONDS ) );

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'prode_refresh_tokens',
            [
                'user_id'      => $user_id,
                'jti'          => $jti,
                'token_hash'   => $token_hash,
                'device_label' => substr( $device_label, 0, 120 ),
                'created_at'   => $now,
                'expires_at'   => $expires_at,
            ]
        );

        return $token;
    }

    /**
     * Rotates a refresh token: validates the old one, revokes it, issues a new one.
     *
     * @param string $plain_token The refresh token received from the client.
     * @return array{token: string, user_id: int} New plain refresh token + user_id.
     * @throws \InvalidArgumentException With machine-readable code on any failure.
     */
    public function rotateRefreshToken( string $plain_token ): array {
        global $wpdb;

        $token_hash = hash( 'sha256', $plain_token );
        $table      = $wpdb->prefix . 'prode_refresh_tokens';

        // Look up the token row.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
                $token_hash
            ),
            ARRAY_A
        );

        if ( empty( $row ) ) {
            throw new \InvalidArgumentException( 'refresh_token_invalid' );
        }

        // Already revoked? Presenting a token that was already rotated away is a
        // strong refresh-token-theft signal (OAuth 2.0 Security BCP,
        // draft-ietf-oauth-security-topics §4.13.2): the legitimate client and a
        // thief now both hold tokens from the same chain. Treat it as a breach —
        // revoke the entire session family (bumps session_version, invalidating
        // every active JWT, and deletes all refresh tokens) so neither side keeps
        // access. The wire contract is unchanged: the caller still sees
        // `refresh_token_invalid` and must re-authenticate.
        if ( ! empty( $row['revoked_at'] ) ) {
            $this->revokeAllSessions( (int) $row['user_id'] );
            throw new \InvalidArgumentException( 'refresh_token_invalid' );
        }

        // Expired?
        if ( strtotime( $row['expires_at'] ) < time() ) {
            throw new \InvalidArgumentException( 'refresh_token_invalid' );
        }

        $user_id = (int) $row['user_id'];

        // Validate session_version is still valid by loading the user.
        $sv_check = $this->getUserSessionVersion( $user_id );
        if ( null === $sv_check ) {
            // User deleted — treat as revoked.
            throw new \InvalidArgumentException( 'session_revoked' );
        }

        // Revoke the old token.
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $table,
            [ 'revoked_at' => current_time( 'mysql' ), 'last_used_at' => current_time( 'mysql' ) ],
            [ 'token_hash' => $token_hash ]
        );

        // Issue a new token.
        $new_token = $this->issueRefreshToken( $user_id, (string) ( $row['device_label'] ?? '' ) );

        return [
            'token'   => $new_token,
            'user_id' => $user_id,
        ];
    }

    // -------------------------------------------------------------------------
    // Session version management
    // -------------------------------------------------------------------------

    /**
     * Increments the session_version for a user, invalidating all active JWTs
     * and purging all refresh tokens.
     *
     * Used by: admin unlink, user-initiated account deletion.
     *
     * @param int $user_id prode_users.id
     */
    public function revokeAllSessions( int $user_id ): void {
        global $wpdb;

        // Increment session_version — any JWT carrying the old sv is now invalid.
        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}prode_users
                    SET session_version = session_version + 1
                  WHERE id = %d",
                $user_id
            )
        );

        // Purge refresh tokens (hard delete; they are worthless after sv bump).
        $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'prode_refresh_tokens',
            [ 'user_id' => $user_id ]
        );
    }

    /**
     * Returns the current session_version for a user, or null if the user
     * does not exist or is soft-deleted.
     *
     * @param int $user_id prode_users.id
     * @return int|null
     */
    public function getUserSessionVersion( int $user_id ): ?int {
        global $wpdb;

        $sv = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT session_version
                   FROM {$wpdb->prefix}prode_users
                  WHERE id = %d
                    AND deleted_at IS NULL
                  LIMIT 1",
                $user_id
            )
        );

        return null !== $sv ? (int) $sv : null;
    }

    // -------------------------------------------------------------------------
    // User data helpers
    // -------------------------------------------------------------------------

    /**
     * Loads a prode_users row by ID.
     *
     * Returns null if the user does not exist or is soft-deleted.
     *
     * @param int $user_id
     * @return array<string, mixed>|null
     */
    public function getUser( int $user_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT id, tenant_id, dni, email, display_name, session_version,
                        created_at, last_login_at
                   FROM {$wpdb->prefix}prode_users
                  WHERE id = %d
                    AND deleted_at IS NULL
                  LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Loads a user and their active association by (provider, provider_id).
     *
     * Returns null if no active association exists.
     *
     * @param string $provider   "google" | "apple"
     * @param string $provider_id OAuth sub claim
     * @return array<string, mixed>|null User + association merged row.
     */
    public function findByProviderAssociation( string $provider, string $provider_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT u.id          AS user_id,
                        u.tenant_id,
                        u.dni,
                        u.email,
                        u.display_name,
                        u.session_version,
                        a.id          AS association_id,
                        a.provider,
                        a.provider_id,
                        a.player_id
                   FROM {$wpdb->prefix}prode_users u
                   INNER JOIN {$wpdb->prefix}prode_associations a
                           ON a.user_id    = u.id
                          AND a.deleted_at IS NULL
                  WHERE a.provider    = %s
                    AND a.provider_id = %s
                    AND u.deleted_at  IS NULL
                  LIMIT 1",
                $provider,
                $provider_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Checks whether a DNI is already associated (active) to a different provider.
     *
     * Returns the conflicting provider name, or null if the DNI is free.
     *
     * @param string $dni        Plain DNI
     * @param string $provider   The provider being attempted (excluded from conflict check)
     * @param string $provider_id The provider_id being attempted
     * @return string|null Conflicting provider ("google" | "apple") or null.
     */
    public function findConflictingAssociation( string $dni, string $provider, string $provider_id ): ?string {
        global $wpdb;

        // Check if this exact provider_id is already associated (returning user, no conflict).
        // This should have been caught by findByProviderAssociation, but be defensive.
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT a.provider
                   FROM {$wpdb->prefix}prode_associations a
                   INNER JOIN {$wpdb->prefix}prode_users u ON u.id = a.user_id
                  WHERE u.dni         = %s
                    AND a.deleted_at  IS NULL
                    AND u.deleted_at  IS NULL
                    AND NOT (a.provider = %s AND a.provider_id = %s)
                  LIMIT 1",
                $dni,
                $provider,
                $provider_id
            ),
            ARRAY_A
        );

        return $row ? (string) $row['provider'] : null;
    }

    /**
     * Creates a new prode_users row + prode_associations row in a transaction.
     *
     * Called from the /auth/dni endpoint after all validations pass.
     *
     * @param string $provider
     * @param string $provider_id
     * @param string $dni
     * @param string $email
     * @param string $display_name
     * @param int    $player_id
     * @return array{user_id: int, session_version: int}
     * @throws \RuntimeException On DB failure.
     */
    public function createUserWithAssociation(
        string $provider,
        string $provider_id,
        string $dni,
        string $email,
        string $display_name,
        int $player_id
    ): array {
        global $wpdb;

        $tenant_id = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';
        $now       = current_time( 'mysql' );

        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        try {
            // INSERT prode_users
            $ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'prode_users',
                [
                    'tenant_id'       => $tenant_id,
                    'dni'             => $dni,
                    'email'           => $email,
                    'provider'        => $provider,
                    'provider_id'     => $provider_id,
                    'display_name'    => $display_name,
                    'session_version' => 1,
                    'created_at'      => $now,
                ]
            );

            if ( false === $ok ) {
                // A duplicate-key failure here means the uq_tenant_active_dni
                // index caught a concurrent /auth/dni race for the same DNI that
                // slipped past findConflictingAssociation() (TOCTOU). Surface it
                // as the same friendly conflict the pre-check would have returned,
                // not a 500.
                if ( self::isDuplicateKeyError( (string) $wpdb->last_error ) ) {
                    throw new \InvalidArgumentException( 'dni_already_associated' );
                }
                throw new \RuntimeException( 'db_user_insert_failed' );
            }

            $user_id = (int) $wpdb->insert_id;

            // INSERT prode_associations
            $ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->prefix . 'prode_associations',
                [
                    'user_id'     => $user_id,
                    'provider'    => $provider,
                    'provider_id' => $provider_id,
                    'dni'         => $dni,
                    'player_id'   => $player_id,
                    'created_at'  => $now,
                ]
            );

            if ( false === $ok ) {
                throw new \RuntimeException( 'db_association_insert_failed' );
            }

            $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        } catch ( \Throwable $e ) {
            // Catch \Throwable (not just \RuntimeException) so the duplicate-key
            // \InvalidArgumentException also rolls the transaction back instead
            // of leaking an open transaction.
            $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            throw $e;
        }

        // Update last_login_at
        $this->touchLastLogin( $user_id );

        return [
            'user_id'         => $user_id,
            'session_version' => 1,
        ];
    }

    /**
     * Tells whether a DB error string is a unique/duplicate-key violation.
     *
     * Portable across the production driver (MySQL: "Duplicate entry '...' for
     * key 'uq_tenant_active_dni'") and the SQLite test shim ("UNIQUE constraint
     * failed: ...").
     *
     * @param string $error The driver error message ($wpdb->last_error).
     */
    private static function isDuplicateKeyError( string $error ): bool {
        return false !== stripos( $error, 'duplicate' )
            || false !== stripos( $error, 'unique constraint' )
            || false !== stripos( $error, 'uq_tenant_active_dni' );
    }

    /**
     * Updates last_login_at for a user.
     *
     * @param int $user_id prode_users.id
     */
    public function touchLastLogin( int $user_id ): void {
        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . 'prode_users',
            [ 'last_login_at' => current_time( 'mysql' ) ],
            [ 'id' => $user_id ]
        );
    }

    /**
     * Loads the active association for a given user ID.
     *
     * @param int $user_id prode_users.id
     * @return array<string, mixed>|null
     */
    public function getActiveAssociation( int $user_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}prode_associations
                  WHERE user_id = %d AND deleted_at IS NULL
                  LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }
}
