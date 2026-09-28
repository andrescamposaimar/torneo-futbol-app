<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Tests\Admin;

use EntreRedes\Cambios\Admin\ProcessOwnerAuthorizer;
use PHPUnit\Framework\TestCase;

/**
 * Both directions of ProcessOwnerAuthorizer::autorizado() — proving BOTH
 * requires the shim's current_user_can() to be controllable (see
 * tests/wp-shim.php's own docblock on that function): a hardcoded `false`
 * could only ever prove rejection, never that granting the capability
 * actually admits the user.
 */
class ProcessOwnerAuthorizerTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wp_test_current_user_can'] );
    }

    public function test_deniega_sin_la_capacidad(): void {
        $GLOBALS['wp_test_current_user_can'] = false;

        $this->assertFalse( ( new ProcessOwnerAuthorizer() )->autorizado() );
    }

    public function test_admite_con_la_capacidad(): void {
        $GLOBALS['wp_test_current_user_can'] = [ ProcessOwnerAuthorizer::CAPABILITY => true ];

        $this->assertTrue( ( new ProcessOwnerAuthorizer() )->autorizado() );
    }

    public function test_no_admite_por_tener_otra_capacidad_cualquiera(): void {
        $GLOBALS['wp_test_current_user_can'] = [ 'manage_options' => true ];

        $this->assertFalse( ( new ProcessOwnerAuthorizer() )->autorizado(), 'gestionar_cambios must be its own capability, not implied by manage_options.' );
    }
}
