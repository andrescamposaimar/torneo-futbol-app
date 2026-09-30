<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Db;

/**
 * Tells whether a raw `$wpdb->last_error` string is a unique/duplicate-key
 * violation, portable across the production driver (MySQL: "Duplicate
 * entry '...' for key '...'") and the SQLite test shim ("UNIQUE constraint
 * failed: ..."). Same convention as entre-redes-prode's own
 * Auth\SessionManager::isDuplicateKeyError() — kept here as a small, shared
 * utility because this plugin's Approval\ApprovalRequestRepository (design
 * D10) needs the exact same check, and future request types added to the
 * same generic table will too.
 */
final class DbErrors {

    public static function isDuplicateKey( string $error ): bool {
        return false !== stripos( $error, 'duplicate entry' )
            || false !== stripos( $error, 'unique constraint' );
    }
}
