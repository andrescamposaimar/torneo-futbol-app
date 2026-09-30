<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Db;

use EntreRedes\Credencial\Db\DbErrors;
use PHPUnit\Framework\TestCase;

/**
 * DbErrors::isDuplicateKey() — design D10: "detected through
 * Db\DbErrors::isDuplicateKey($wpdb->last_error) (prefix `Duplicate
 * entry`)". Portable across the production driver (MySQL) and the SQLite
 * test shim, same convention as entre-redes-prode's own
 * SessionManager::isDuplicateKeyError().
 */
class DbErrorsTest extends TestCase {

    public function test_recognizes_a_real_mysql_duplicate_entry_message(): void {
        $this->assertTrue(
            DbErrors::isDuplicateKey( "Duplicate entry 'photo:7' for key 'uq_pending_key'" )
        );
    }

    public function test_recognizes_the_sqlite_test_shim_message(): void {
        $this->assertTrue(
            DbErrors::isDuplicateKey( 'UNIQUE constraint failed: wp_credencial_approval_request.pending_key' )
        );
    }

    public function test_is_case_insensitive(): void {
        $this->assertTrue( DbErrors::isDuplicateKey( 'duplicate entry for key x' ) );
    }

    public function test_rejects_an_unrelated_error(): void {
        $this->assertFalse( DbErrors::isDuplicateKey( 'Deadlock found; try restarting transaction' ) );
    }

    public function test_rejects_an_empty_string(): void {
        $this->assertFalse( DbErrors::isDuplicateKey( '' ) );
    }
}
