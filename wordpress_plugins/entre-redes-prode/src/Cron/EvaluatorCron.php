<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

/**
 * Cron handler: evaluates match predictions and writes prode_scores rows.
 * Full implementation: PR-07.
 */
class EvaluatorCron {

    public static function run(): void {
        // Stub — implemented in PR-07.
        do_action( 'prode_evaluator_cron_ran' );
    }
}
