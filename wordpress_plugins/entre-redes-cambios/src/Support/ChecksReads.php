<?php

declare(strict_types=1);

namespace EntreRedes\Cambios\Support;

/**
 * Shared guard for "did this `$wpdb->get_results()` call actually succeed,
 * or did a failure quietly read as an empty collection" — the same check
 * `Plazas\PlazaRepository::assertReadSucceeded()` established first (see that
 * class's docblock, "READ FAILURES MUST NEVER READ AS 'NO ROWS'"), extracted
 * here so every OTHER class that reads directly against `$wpdb` and cannot
 * afford to misread a failure as "zero rows" can reuse the exact same
 * semantics instead of re-deriving them.
 *
 * `PlazaRepository`'s own private `assertReadSucceeded()` is left untouched
 * and does NOT use this trait — it predates this extraction and nothing
 * about its behaviour needs to change; this trait exists for classes built
 * AFTER it that need the identical guard.
 *
 * A genuine "no matching rows" result is `$rows === []` with
 * `$this->wpdb->last_error` empty — that passes through untouched. Anything
 * else (`$rows === null`, wpdb's own documented failure return, OR a
 * non-empty `$this->wpdb->last_error` left over from THIS call) is a query
 * failure: logged as `lectura.fallida` on the using class's EventLog first,
 * then thrown — so the caller can never mistake it for "confirmado, sin
 * conflicto".
 *
 * REQUIRES the using class to have `\wpdb $wpdb` and
 * `Observability\EventLog $eventLog` properties, exactly like
 * `OpensTransactions`'s own requirement.
 */
trait ChecksReads {

    /**
     * @param array<int, array<string, mixed>>|null $rows
     * @param array<string, mixed>                   $contexto
     * @throws \RuntimeException
     */
    private function assertReadSucceeded( ?array $rows, string $operacion, array $contexto ): void {
        $lastError = (string) ( $this->wpdb->last_error ?? '' );

        if ( null !== $rows && '' === $lastError ) {
            return;
        }

        $this->eventLog->record( 'lectura.fallida', array_merge( $contexto, [
            'operacion'  => $operacion,
            'last_error' => $this->wpdb->last_error,
        ] ) );

        throw new \RuntimeException(
            sprintf(
                '%s::%s(): the query failed at the wpdb level%s.',
                static::class,
                $operacion,
                '' !== $lastError ? " ({$lastError})" : ''
            )
        );
    }
}
