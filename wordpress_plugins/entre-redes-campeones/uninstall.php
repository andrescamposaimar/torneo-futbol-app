<?php
/**
 * Uninstall script — called by WordPress only when the operator chooses
 * "Delete" from the Plugins screen (after deactivation).
 *
 * This file DROPS both campeones_ tables permanently. The operator should
 * take a DB backup before uninstalling.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    'campeones_plantel',
    'campeones_titulo',
];

foreach ( $tables as $table ) {
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table )
    );
}

delete_option( 'campeones_db_version' );
