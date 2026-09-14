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
 *     raw options-table LIKE delete, following the entre-redes-api
 *     precedent (entre-redes-api.php:51-64) of deleting BOTH the value row
 *     (`_transient_...`) and its paired timeout row
 *     (`_transient_timeout_...`) — a single-pattern delete could only ever
 *     match one of the two, silently leaving the other to expire on its
 *     own schedule.
 *
 * Operational note: `litespeed-cache` is installed on the production host,
 * but its object-cache drop-in is NOT active, so transients still live in
 * `wp_options` today and the raw delete below works. If that drop-in is
 * ever enabled, transients move into the object cache and this DELETE would
 * silently affect zero rows — prefer `delete_transient()` for any key whose
 * exact name is known, which is exactly why the history key uses it above
 * while the per-player one, parametrized by id, still needs the LIKE form.
 */
final class CacheInvalidator {

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
