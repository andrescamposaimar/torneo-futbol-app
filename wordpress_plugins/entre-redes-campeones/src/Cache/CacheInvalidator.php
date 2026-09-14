<?php

declare(strict_types=1);

namespace EntreRedes\Campeones\Cache;

/**
 * Invalidates the REST layer's 30-day transient cache (design §7) on every
 * admin write, so an operator correction is visible on the next app request
 * instead of up to a month later.
 *
 * Two deletion techniques for two different key shapes:
 *   - the history payload lives under one EXACT, known key
 *     (`campeones_historia_v2`) -> `delete_transient()` is correct and
 *     sufficient.
 *   - the per-player payload is parametrized by player id
 *     (`campeones_titulos_jugador_v1_{id}`) -> there is no single fixed key
 *     to hand `delete_transient()`, so every matching row is removed with a
 *     raw options-table LIKE delete. WordPress's transient API always
 *     writes a non-persistent transient as TWO rows — the value
 *     (`_transient_{key}`) and its paired expiry (`_transient_timeout_{key}`)
 *     — so any raw deletion of a parametrized transient must remove both
 *     patterns, or the timeout row silently survives to expire on its own
 *     schedule. (This plugin's sibling `entre-redes-api` plugin uses the
 *     same two-pattern technique for its own parametrized transients, but
 *     that plugin is not part of this repository, so it cannot be cited by
 *     file/line from here — the justification above stands on its own,
 *     from the WordPress transient API's documented on-disk shape.)
 *
 * Operational note (point-in-time fact, verified 2026-09-14 — this
 * plugin's slice-7 delivery date; RE-VERIFY before trusting this if
 * reading much later, host configuration can change independently of this
 * codebase): `litespeed-cache` is installed on the production host, but
 * its object-cache drop-in was NOT active at that time, so transients
 * lived in `wp_options` and the raw delete below worked. If that drop-in
 * is enabled later, transients move into the object cache and this DELETE
 * would silently affect zero rows — prefer `delete_transient()` for any
 * key whose exact name is known, which is exactly why the history key uses
 * it above while the per-player one, parametrized by id, still needs the
 * LIKE form.
 */
final class CacheInvalidator {

    // VERSION-SKEW OBLIGATION (see TitleShaper's class docblock): these two
    // literals must stay in lockstep with HistoryController::CACHE_KEY and
    // PlayerTitlesController::CACHE_PREFIX respectively — whoever bumps one
    // side's version suffix and forgets this one leaves flush() unable to
    // ever delete the newly-shaped transient.
    private const HISTORY_TRANSIENT    = 'campeones_historia_v2';
    private const PLAYER_TITLES_PREFIX = 'campeones_titulos_jugador_v1_';

    public function __construct( private readonly \wpdb $wpdb ) {
    }

    public function flush(): void {
        delete_transient( self::HISTORY_TRANSIENT );

        $p = $this->wpdb->prefix;

        $result = $this->wpdb->query(
            "DELETE FROM {$p}options
              WHERE option_name LIKE '_transient_" . self::PLAYER_TITLES_PREFIX . "%'
                 OR option_name LIKE '_transient_timeout_" . self::PLAYER_TITLES_PREFIX . "%'"
        );

        if ( false === $result ) {
            error_log( sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'entre-redes-campeones: failed to flush per-player title transients from wp_options. DB error: %s',
                (string) $this->wpdb->last_error
            ) );
        }
    }
}
