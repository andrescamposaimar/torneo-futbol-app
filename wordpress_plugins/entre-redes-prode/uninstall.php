<?php
/**
 * Uninstall script — called by WordPress only when the operator chooses
 * "Delete" from the Plugins screen (after deactivation).
 *
 * This file DROPS all prode_ tables permanently. The operator should take
 * a DB backup before uninstalling.
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    'prode_ranking_fecha_cache',
    'prode_scores',
    'prode_predictions',
    'prode_fecha_matches',
    'prode_fechas',
    'prode_audit_log',
    'prode_refresh_tokens',
    'prode_associations',
    'prode_users',
    'prode_settings',
];

foreach ( $tables as $table ) {
    $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table )
    );
}

// Remove all WP options created by this plugin.
$options = [
    'prode_db_version',
    'prode_rsa_private_key',
    'prode_rsa_public_key',
    'prode_audit_dni_pepper',
];
foreach ( $options as $option ) {
    delete_option( $option );
}
