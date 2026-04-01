<?php
/**
 * Uninstall cleanup for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'mdvrm_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );

delete_option( 'mdvrm_settings' );
delete_option( 'mdvrm_last_interval' );
delete_option( 'mdvrm_last_alert_ts' );

wp_clear_scheduled_hook( 'mdvrm_reports_cron' );
