<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

use EntreRedes\Prode\Fecha\FechaRepository;
use EntreRedes\Prode\Fecha\FechaResolver;
use EntreRedes\Prode\Fecha\LockComputer;
use EntreRedes\Prode\Fecha\Settings;

/**
 * Cron handler: creates the next-day Prode fecha if matches exist.
 *
 * Design (ADR-G0-7):
 *   The WP hook binds the STATIC run() entrypoint — that signature is frozen.
 *   All logic lives in the NON-STATIC execute() method, which accepts the four
 *   collaborators as parameters. Tests call execute() directly with stubs;
 *   run() calls execute() with real production instances.
 *
 * Idempotency:
 *   FechaRepository::upsertFecha is idempotent — a second cron run for the
 *   same play-date reuses the existing fecha row and deduplicates match rows.
 */
class FechaCreationCron {

    /**
     * WP hook entrypoint — keep this signature unchanged.
     *
     * Instantiates real collaborators and delegates to execute().
     */
    public static function run(): void {
        global $wpdb;

        $settings     = new Settings( $wpdb );
        $resolver     = new FechaResolver(); // uses real rest_do_request dispatcher
        $lockComputer = new LockComputer();
        $repository   = new FechaRepository( $wpdb );

        // The static seam delegates to the injectable instance method.
        $instance = new self();
        $instance->execute(
            $settings,
            $lockComputer,
            $repository,
            static fn() => $resolver->resolveNext( $settings->fechaWindowDays() )
        );
    }

    /**
     * Core logic — collaborators are constructor-injected for testability.
     *
     * @param Settings        $settings     Typed settings accessor.
     * @param LockComputer    $lockComputer Pure lock-window calculator.
     * @param FechaRepository $repository   wpdb-backed fecha persistence.
     * @param callable        $resolverFn   Callable returning the resolver result
     *                                      (array|null). In tests: a stub closure.
     *                                      In production: a closure wrapping
     *                                      FechaResolver::resolveNext().
     */
    public function execute(
        Settings $settings,
        LockComputer $lockComputer,
        FechaRepository $repository,
        callable $resolverFn
    ): void {
        $result = $resolverFn();

        if ( null === $result ) {
            // No upcoming matches — fire the observability hook and exit.
            do_action( 'prode_fecha_creation_cron_ran' );
            return;
        }

        $lockedAt = $lockComputer->computeLockedAt(
            $result['earliest_kickoff'],
            $settings->lockHoursBefore()
        );

        $tenantId = defined( 'PRODE_TENANT_ID' ) ? (string) PRODE_TENANT_ID : '';

        $repository->upsertFecha(
            $tenantId,
            $settings->seasonId(),
            $lockedAt,
            $result['matches']
        );

        do_action( 'prode_fecha_creation_cron_ran' );
    }
}
