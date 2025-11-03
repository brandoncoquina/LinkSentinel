<?php
/**
 * Uninstall script for LinkSentinel.
 *
 * This file is called automatically by WordPress when the plugin is
 * uninstalled via the Plugins screen.  It removes plugin options but
 * intentionally leaves the `rfx_link_monitor` table intact so that users
 * retain a record of past link issues.
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Remove plugin options.
delete_option( 'rfx_scan_last_started' );
delete_option( 'rfx_scan_last_type' );
delete_option( 'rfx_settings' );

// If you wish to drop the custom table on uninstall, uncomment the
// following lines.  Leaving historical scan data is often desirable.
/*
global $wpdb;
$table_name = $wpdb->prefix . 'rfx_link_monitor';
$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
*/