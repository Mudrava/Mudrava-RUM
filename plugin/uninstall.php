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
$mdvrm_table = $wpdb->prefix . 'mdvrm_logs';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $mdvrm_table ) );

delete_option( 'mdvrm_settings' );
delete_option( 'mdvrm_version' );
delete_option( 'mdvrm_last_report_ts' );
delete_option( 'mdvrm_last_alert_ts' );
delete_option( 'mdvrm_ttfb_streak' );

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter.
$mdvrm_transient_names = $wpdb->get_col(
	$wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, '%' . $wpdb->esc_like( '_mdvrm_rl_' ) . '%' )
);
foreach ( $mdvrm_transient_names as $mdvrm_option_name ) {
	delete_option( $mdvrm_option_name );
}

wp_clear_scheduled_hook( 'mdvrm_reports_cron' );
