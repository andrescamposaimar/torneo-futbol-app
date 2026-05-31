<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Fecha;

/**
 * Pure fecha lock-window calculator.
 *
 * Zero globals, zero DB access, zero calls to time() or current_time().
 * All inputs — including "now" — are injected, making every computation
 * fully deterministic in tests.
 */
class LockComputer {

    /**
     * Compute the datetime at which a fecha locks.
     *
     * locked_at = earliest kickoff − lock_hours_before hours.
     *
     * @param string $earliestKickoff  The earliest match_kickoff in 'Y-m-d H:i:s' format.
     * @param int    $lockHoursBefore  How many hours before the kickoff to lock.
     * @param string $tz               Timezone identifier (default 'UTC').
     * @return string                  locked_at in 'Y-m-d H:i:s' format.
     */
    public function computeLockedAt(
        string $earliestKickoff,
        int $lockHoursBefore,
        string $tz = 'UTC'
    ): string {
        $dt = new \DateTime( $earliestKickoff, new \DateTimeZone( $tz ) );
        $dt->sub( new \DateInterval( "PT{$lockHoursBefore}H" ) );
        return $dt->format( 'Y-m-d H:i:s' );
    }

    /**
     * Derive the current effective state of a fecha.
     *
     * State machine:
     *   - 'evaluated' is terminal: written by G3, respected by G0 — never overridden.
     *   - 'locked'    when now >= locked_at.
     *   - 'open'      otherwise.
     *
     * @param string $lockedAt       The fecha's locked_at datetime ('Y-m-d H:i:s').
     * @param string $persistedState The state column value from the DB.
     * @param string $now            The current datetime ('Y-m-d H:i:s'); injected, not global.
     * @return string                'open' | 'locked' | 'evaluated'
     */
    public function deriveState(
        string $lockedAt,
        string $persistedState,
        string $now
    ): string {
        if ( 'evaluated' === $persistedState ) {
            return 'evaluated';
        }

        return $now >= $lockedAt ? 'locked' : 'open';
    }
}
