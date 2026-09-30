<?php

declare(strict_types=1);

namespace EntreRedes\Credencial\Tests\Credencial;

use EntreRedes\Credencial\Credencial\CredencialState;
use PHPUnit\Framework\TestCase;

/**
 * CredencialState — shapes the GET response body exactly per design's
 * Interfaces section: `{state, photo_request, credential}`.
 */
class CredencialStateTest extends TestCase {

    public function test_active_carries_the_credential_and_defaults_photo_request_to_null(): void {
        $state = CredencialState::active( [ 'id' => 'cred-1' ] );

        $this->assertSame(
            [ 'state' => 'active', 'photo_request' => null, 'credential' => [ 'id' => 'cred-1' ] ],
            $state->toArray()
        );
    }

    public function test_active_can_carry_a_photo_request(): void {
        $state = CredencialState::active(
            [ 'id' => 'cred-1' ],
            [ 'id' => 9, 'status' => 'rejected', 'created_at' => '2026-01-01 00:00:00' ]
        );

        $this->assertSame(
            [ 'id' => 9, 'status' => 'rejected', 'created_at' => '2026-01-01 00:00:00' ],
            $state->toArray()['photo_request']
        );
    }

    public function test_blocked_has_no_credential_and_no_photo_request(): void {
        $this->assertSame(
            [ 'state' => 'blocked', 'photo_request' => null, 'credential' => null ],
            CredencialState::blocked()->toArray()
        );
    }

    public function test_no_photo_has_no_credential_and_no_photo_request(): void {
        $this->assertSame(
            [ 'state' => 'no_photo', 'photo_request' => null, 'credential' => null ],
            CredencialState::noPhoto()->toArray()
        );
    }

    public function test_no_photo_can_carry_a_pending_photo_request(): void {
        $state = CredencialState::noPhoto(
            [ 'id' => 3, 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00' ]
        );

        $this->assertSame(
            [ 'id' => 3, 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00' ],
            $state->toArray()['photo_request']
        );
        $this->assertNull( $state->toArray()['credential'] );
    }

    public function test_not_a_player_has_no_credential_and_no_photo_request(): void {
        $this->assertSame(
            [ 'state' => 'not_a_player', 'photo_request' => null, 'credential' => null ],
            CredencialState::notAPlayer()->toArray()
        );
    }
}
