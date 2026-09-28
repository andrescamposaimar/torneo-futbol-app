<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Calendario;

/**
 * Pure plazo (deadline) calculator for a fecha.
 *
 * Zero globals, zero DB access, zero calls to time() / current_time() / date()
 * without an explicit timezone. Every input — including "now" where relevant
 * to a caller — is injected, making every computation fully deterministic in
 * tests. This is the same design discipline as entre-redes-prode's
 * Fecha\LockComputer.
 *
 * WHY $tz HAS NO DEFAULT (unlike LockComputer::computeLockedAt's `$tz = 'UTC'`):
 * prode's LockComputer took a defaulted `$tz = 'UTC'` parameter, and both of
 * its production call sites simply never pass it — so that plugin computes
 * lock times in UTC today without anyone having decided so. It survives there
 * mostly by luck: a lock window measured in hours-before-kickoff is a fixed
 * DURATION, so a consistent zone offset cancels out on both sides of the
 * comparison. The four plazos here are NOT durations, so the same omission
 * would change the answer. The caller must choose; the system default
 * ('America/Argentina/Buenos_Aires') lives in Calendario\Settings, not in a
 * method signature.
 *
 * DESIGN NOTE — plazos are CIVIL deadlines, not elapsed time.
 * "Martes 23:59:59" means 23:59:59 on the wall clock, always. So compute()
 * does calendar-day arithmetic: shift the play_date by N days, then set the
 * time of day. DateTimeImmutable::modify() on a zoned instance preserves wall
 * time across a DST transition, which is exactly the semantics a civil
 * deadline needs.
 *
 * A consequence worth stating plainly, because it looks like a bug and is not:
 * compute() returns the SAME civil string for every timezone. A civil deadline
 * is timezone-invariant by construction — that is what makes it civil. The
 * timezone becomes load-bearing at computeUtc(), which resolves those same
 * plazos to absolute instants. That is the method to use when comparing
 * against a clock.
 *
 * A CORRECTION WORTH FLAGGING EXPLICITLY, because an earlier version of this
 * docblock got it backwards: WordPress's `current_time('mysql')` hands out
 * the SITE'S LOCAL civil time, NOT UTC — UTC only comes back when the
 * caller passes the second argument, `current_time('mysql', true)`. See
 * `Auth\TokenVerifier::verify()`'s own docblock for the concrete incident
 * that exact confusion caused elsewhere in this codebase (`current_time
 * ('mysql')` read as UTC, silently shifting "now" by the site's offset).
 * Comparing a Buenos Aires civil deadline against a *local* `current_time
 * ('mysql')` string that happens to ALSO be expressed in Buenos Aires time
 * would actually agree by coincidence; the classic three-hour off-by-one
 * this class defends against is comparing it against a value that is
 * genuinely UTC — `time()`, `gmdate()`, or `current_time('mysql', true)` —
 * without first calling computeUtc().
 *
 * An earlier implementation of this class computed plazos as elapsed seconds
 * from midnight so that $tz would visibly change compute()'s output. It did,
 * but at the cost of the semantics: across a DST boundary a "Tuesday 23:59:59"
 * deadline silently landed at 22:59:59 or 00:59:59. Argentina has not observed
 * DST since 2009, so it would not bite today — but that is a policy decision,
 * not a physical law, and it has been revisited more than once. The civil
 * reading is the correct one and does not depend on that bet.
 *
 * OPEN QUESTION LEFT OUT OF SCOPE (C11): whether plazos are recomputed when a
 * fecha is postponed (a new play_date is chosen) is a business decision this
 * class does not make. compute() is pure — it always computes from whatever
 * play_date it is given. The caller decides whether to pass the original
 * play_date (freezing the original plazos) or the new one (recomputing them);
 * that policy belongs outside this class.
 */
final class PlazosCalculator {

    /**
     * The four plazo keys, in chronological order. Offsets themselves are NOT
     * hardcoded here — they are supplied by the caller (from
     * Calendario\Settings); this array only documents and orders the keys that
     * compute() and isWithinSolicitudWindow() expect to find.
     */
    private const PLAZO_KEYS = [
        'apertura_solicitudes',
        'cierre_regresos',
        'cierre_solicitudes',
        'publicacion',
    ];

    /**
     * Compute the four plazos for a fecha from its play_date, as civil
     * ("wall clock") datetimes in $tz.
     *
     * @param string $playDate The Saturday the fecha is played, 'Y-m-d'.
     * @param array<string, array{days: int, time: string}> $offsets Keyed by
     *        the four PLAZO_KEYS; each value is {days: negative int offset
     *        from play_date, time: 'H:i:s' civil time of day}. Typically from
     *        Settings::plazosOffsets().
     * @param string $tz IANA timezone identifier. Mandatory — see class
     *        docblock. Note this does not change the returned civil strings;
     *        it defines the zone those wall-clock times belong to, which
     *        computeUtc() then resolves.
     * @return array<string, string> The four plazos, keyed by PLAZO_KEYS,
     *         each formatted 'Y-m-d H:i:s' in $tz.
     */
    public static function compute( string $playDate, array $offsets, string $tz ): array {
        $result = [];

        foreach ( self::PLAZO_KEYS as $key ) {
            $result[ $key ] = self::civilPlazo( $playDate, self::offsetFor( $offsets, $key ), $tz )
                ->format( 'Y-m-d H:i:s' );
        }

        return $result;
    }

    /**
     * The same four plazos as compute(), resolved to absolute UTC instants.
     *
     * Use this — not compute() — whenever a plazo is compared against a clock
     * or persisted next to UTC timestamps: every DATETIME column this plugin
     * persists is UTC (see the README), and `time()` / `gmdate()` /
     * `current_time('mysql', true)` are all UTC too — comparing any of them
     * against compute()'s civil output would be wrong by the zone's offset.
     * (`current_time('mysql')` WITHOUT that second argument is NOT UTC — it
     * is the site's local civil time — see class docblock's correction.)
     *
     * Unlike compute(), this output DOES differ per timezone: the same civil
     * deadline is a different real-world instant in each zone.
     *
     * @param array<string, array{days: int, time: string}> $offsets
     * @return array<string, string> Keyed by PLAZO_KEYS, formatted
     *         'Y-m-d H:i:s' in UTC.
     */
    public static function computeUtc( string $playDate, array $offsets, string $tz ): array {
        $utc    = new \DateTimeZone( 'UTC' );
        $result = [];

        foreach ( self::PLAZO_KEYS as $key ) {
            $result[ $key ] = self::civilPlazo( $playDate, self::offsetFor( $offsets, $key ), $tz )
                ->setTimezone( $utc )
                ->format( 'Y-m-d H:i:s' );
        }

        return $result;
    }

    /**
     * Whether $now falls within the solicitud window: from apertura_solicitudes
     * (inclusive) through cierre_solicitudes (inclusive). cierre_regresos and
     * publicacion are separate sub-deadlines that live INSIDE that window —
     * they do not bound it, so they are intentionally not consulted here.
     *
     * $now and $plazos MUST be expressed in the same frame: either both civil
     * (compute()) or both UTC (computeUtc()). Mixing them is the bug this
     * class's docblock warns about, and no signature can catch it, so the
     * caller owns that pairing.
     *
     * @param string                $now    'Y-m-d H:i:s', injected — never read
     *                                      from a clock inside this method.
     * @param array<string, string> $plazos Output of compute() or computeUtc().
     */
    public static function isWithinSolicitudWindow( string $now, array $plazos ): bool {
        return $now >= $plazos['apertura_solicitudes'] && $now <= $plazos['cierre_solicitudes'];
    }

    /**
     * @param array<string, array{days: int, time: string}> $offsets
     * @return array{days: int, time: string}
     */
    private static function offsetFor( array $offsets, string $key ): array {
        if ( ! isset( $offsets[ $key ] ) ) {
            throw new \InvalidArgumentException( "Missing offset for plazo '{$key}'." );
        }

        return $offsets[ $key ];
    }

    /**
     * Shift play_date by N calendar days in $tz and set the civil time of day.
     *
     * modify('%+d days') on a zoned DateTimeImmutable preserves wall-clock
     * time across DST transitions — the correct semantics for a civil
     * deadline. setTime() then pins the time of day, so even a DST gap
     * (a wall-clock time that does not exist that day) normalizes the same way
     * PHP normalizes it everywhere else, rather than drifting by the offset.
     *
     * @param array{days: int, time: string} $offset
     */
    private static function civilPlazo( string $playDate, array $offset, string $tz ): \DateTimeImmutable {
        $zone    = new \DateTimeZone( $tz );
        $shifted = ( new \DateTimeImmutable( $playDate . ' 00:00:00', $zone ) )
            ->modify( sprintf( '%+d days', (int) $offset['days'] ) );

        $parts = explode( ':', (string) $offset['time'] );

        return $shifted->setTime(
            (int) ( $parts[0] ?? 0 ),
            (int) ( $parts[1] ?? 0 ),
            (int) ( $parts[2] ?? 0 )
        );
    }
}
