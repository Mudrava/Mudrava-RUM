<?php
/**
 * Uninstall cleanup for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'mdvrm_uninstall_current_site' ) ) {
	/**
	 * Remove all plugin data belonging to the current blog.
	 */
	function mdvrm_uninstall_current_site(): void {
		global $wpdb;

		$mdvrm_table = $wpdb->prefix . 'mdvrm_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $mdvrm_table ) );

		delete_option( 'mdvrm_settings' );
		delete_option( 'mdvrm_version' );
		delete_option( 'mdvrm_last_report_ts' );
		delete_option( 'mdvrm_last_alert_ts' );
		delete_option( 'mdvrm_ttfb_streak' );
		delete_option( 'mdvrm_stats_cache_version' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter.
		$mdvrm_transient_names = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				'_transient_mdvrm_%',
				'_transient_timeout_mdvrm_%'
			)
		);

		foreach ( $mdvrm_transient_names as $mdvrm_option_name ) {
			delete_option( $mdvrm_option_name );
		}

		wp_clear_scheduled_hook( 'mdvrm_reports_cron' );
	}
}

if ( is_multisite() ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$mdvrm_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( $mdvrm_blog_ids as $mdvrm_blog_id ) {
		switch_to_blog( (int) $mdvrm_blog_id );
		mdvrm_uninstall_current_site();
		restore_current_blog();
	}
} else {
	mdvrm_uninstall_current_site();
}
