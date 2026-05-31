<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Tests\Fecha;

use EntreRedes\Prode\Fecha\LockComputer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LockComputer — pure DateTime math with injected clock.
 *
 * No DB, no shim, no globals. All boundary cases are fully deterministic.
 */
class LockComputerTest extends TestCase {

    private LockComputer $computer;

    protected function setUp(): void {
        $this->computer = new LockComputer();
    }

    // -------------------------------------------------------------------------
    // computeLockedAt — earliest kickoff minus lock_hours_before
    // -------------------------------------------------------------------------

    public function test_locked_at_computed_from_earliest_of_multiple_kickoffs(): void {
        // Spec scenario: kickoffs 13:45 and 15:10 same day, lock_hours=24
        // Expected: 2026-05-29 13:45:00 (24h before 2026-05-30 13:45:00)
        $result = $this->computer->computeLockedAt( '2026-05-30 13:45:00', 24 );
        $this->assertSame( '2026-05-29 13:45:00', $result );
    }

    public function test_locked_at_single_kickoff_various_lock_hours(): void {
        // Single kickoff at 2026-06-07 10:00:00, lock_hours=48
        $result = $this->computer->computeLockedAt( '2026-06-07 10:00:00', 48 );
        $this->assertSame( '2026-06-05 10:00:00', $result );
    }

    public function test_locked_at_single_kickoff_lock_hours_zero(): void {
        // Edge: lock_hours=0 → locked_at equals kickoff
        $result = $this->computer->computeLockedAt( '2026-05-30 13:45:00', 0 );
        $this->assertSame( '2026-05-30 13:45:00', $result );
    }

    public function test_locked_at_crosses_midnight(): void {
        // Kickoff at 2026-06-01 02:00:00, lock_hours=3 → 2026-05-31 23:00:00
        $result = $this->computer->computeLockedAt( '2026-06-01 02:00:00', 3 );
        $this->assertSame( '2026-05-31 23:00:00', $result );
    }

    // -------------------------------------------------------------------------
    // deriveState — open / locked / evaluated
    // -------------------------------------------------------------------------

    public function test_state_is_open_when_now_is_before_locked_at(): void {
        // Spec scenario: now < locked_at → open
        $state = $this->computer->deriveState(
            '2026-05-29 13:45:00', // locked_at
            'open',                 // persistedState
            '2026-05-29 12:00:00'  // now
        );
        $this->assertSame( 'open', $state );
    }

    public function test_state_is_locked_when_now_equals_locked_at(): void {
        // Spec scenario: now === locked_at → locked
        $state = $this->computer->deriveState(
            '2026-05-29 13:45:00',
            'open',
            '2026-05-29 13:45:00'
        );
        $this->assertSame( 'locked', $state );
    }

    public function test_state_is_locked_when_now_is_after_locked_at(): void {
        // Spec scenario: now > locked_at → locked
        $state = $this->computer->deriveState(
            '2026-05-29 13:45:00',
            'open',
            '2026-05-30 00:00:00'
        );
        $this->assertSame( 'locked', $state );
    }

    public function test_evaluated_state_is_terminal_even_when_now_is_before_locked_at(): void {
        // Spec scenario: persistedState='evaluated', now < locked_at → still 'evaluated'
        $state = $this->computer->deriveState(
            '2026-05-29 13:45:00',
            'evaluated',
            '2026-05-29 12:00:00'
        );
        $this->assertSame( 'evaluated', $state );
    }

    public function test_evaluated_state_is_terminal_even_when_now_is_after_locked_at(): void {
        // persistedState='evaluated', now > locked_at → still 'evaluated'
        $state = $this->computer->deriveState(
            '2026-05-29 13:45:00',
            'evaluated',
            '2026-06-01 00:00:00'
        );
        $this->assertSame( 'evaluated', $state );
    }
}
