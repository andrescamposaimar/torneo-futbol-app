<?php

declare(strict_types=1);

namespace EntreRedes\Prode\Cron;

/**
 * Cron handler: recomputes per-fecha ranking caches after evaluation.
 * Full implementation: PR-07.
 */
class RankingCron {

    public static function run(): void {
        // Stub — implemented in PR-07.
        do_action( 'prode_ranking_cron_ran' );
    }
}
