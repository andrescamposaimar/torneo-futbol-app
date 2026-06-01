<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Audit;

/**
 * Hashes DNI values for storage in the audit log.
 *
 * Uses SHA-256 keyed with a per-tenant pepper stored in WP options
 * (prode_audit_dni_pepper). The pepper is generated at plugin activation
 * by MigrationRunner::generateDniPepper().
 *
 * The hash is deterministic for the same (DNI, pepper) pair, which allows
 * audit log correlation ("show all events for this DNI") without storing the
 * plain DNI in the log table.
 *
 * Pepper rotation:
 *   All existing audit rows must be re-hashed when the pepper changes.
 *   This is a destructive operation provided as a WP-CLI command (PR-11):
 *   `wp prode rotate-pepper --apply`
 *
 * Plain DNI storage:
 *   Plain DNI lives ONLY in prode_associations.dni while the association is
 *   active. After soft-deletion the association row is kept for tombstone
 *   purposes but the audit log entry only has the hash.
 */
class DniHasher {

    /**
     * Returns SHA-256(dni + pepper) as a 64-char hex string.
     *
     * @param string $dni   Plain DNI (digits only; do not normalize here — caller
     *                      must pass the canonical form as stored in the roster).
     * @return string 64-character lowercase hex digest.
     * @throws \RuntimeException If the pepper has not been provisioned.
     */
    public function hash( string $dni ): string {
        $pepper = $this->getPepper();
        return hash( 'sha256', $dni . $pepper );
    }

    /**
     * Returns the masked representation of a DNI for display in the audit log
     * viewer in wp-admin.
     *
     * Format: first 2 digits + "***" + last 2 digits.
     * Example: "12345678" → "12***78"
     *
     * For DNIs shorter than 5 characters the entire value is masked ("*****").
     *
     * @param string $dni Plain DNI
     * @return string Masked string
     */
    public static function mask( string $dni ): string {
        $len = strlen( $dni );
        if ( $len < 5 ) {
            return str_repeat( '*', $len );
        }
        return substr( $dni, 0, 2 ) . '***' . substr( $dni, -2 );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Retrieves the pepper from WP options.
     *
     * @throws \RuntimeException If the pepper has not been generated yet.
     */
    private function getPepper(): string {
        $pepper = (string) get_option( 'prode_audit_dni_pepper', '' );
        if ( '' === $pepper ) {
            throw new \RuntimeException( 'dni_pepper_not_provisioned' );
        }
        return $pepper;
    }
}
