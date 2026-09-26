<?php
/**
 * Uninstall script — called by WordPress only when the operator chooses
 * "Delete" from the Plugins screen (after deactivation).
 *
 * This file DROPS all cambios_ tables permanently. The operator should take
 * a DB backup before uninstalling.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    'cambios_fecha_partido',
    'cambios_fecha',
    'cambios_settings',
];

foreach ( $tables as $table ) {
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table )
    );
}

// Remove all WP options created by this plugin.
delete_option( 'cambios_db_version' );
