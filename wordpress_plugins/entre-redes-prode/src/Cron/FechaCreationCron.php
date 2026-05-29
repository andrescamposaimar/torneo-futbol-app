<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

/**
 * Cron handler: creates the next-day Prode fecha if matches exist.
 * Full implementation: PR-07.
 */
class FechaCreationCron {

    public static function run(): void {
        // Stub — implemented in PR-07.
        do_action( 'prode_fecha_creation_cron_ran' );
    }
}
