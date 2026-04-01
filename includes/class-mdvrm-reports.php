<?php
/**
 * Reports and alerts for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports and alerts class for Mudrava RUM.
 */
class MDVRM_Reports {

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'mdvrm_reports_cron';

	/**
	 * Plugin reference.
	 *
	 * @var MDVRM_Plugin
	 */
	protected $plugin;

	/**
	 * Constructor.
	 *
	 * @param MDVRM_Plugin $plugin Plugin instance.
	 */
	public function __construct( MDVRM_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hook actions.
	 */
	public function hook(): void {
		add_action( 'init', array( $this, 'register_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
	}

	/**
	 * Register cron schedule.
	 */
	public function register_cron(): void {
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval is computed dynamically.
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		$this->ensure_scheduled();
	}

	/**
	 * Add custom cron schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_schedule( array $schedules ): array {
		$interval = $this->get_interval_seconds();

		$schedules['mdvrm_interval'] = array(
			'interval' => $interval,
			'display'  => __( 'Mudrava RUM interval', 'mudrava-rum' ),
		);

		return $schedules;
	}

	/**
	 * Ensure cron is scheduled with current interval; reschedule if changed.
	 */
	protected function ensure_scheduled(): void {
		$interval         = $this->get_interval_seconds();
		$last_interval    = (int) get_option( 'mdvrm_last_interval', 0 );
		$needs_reschedule = ( $last_interval !== $interval );

		if ( $needs_reschedule ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'mdvrm_interval', self::CRON_HOOK );
		}

		if ( $needs_reschedule ) {
			update_option( 'mdvrm_last_interval', $interval );
		}
	}

	/**
	 * Resolve current reporting interval in seconds.
	 *
	 * @return int
	 */
	protected function get_interval_seconds(): int {
		$settings = $this->plugin->settings()->all();
		return ( 'daily' === $settings['report_schedule'] ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;
	}

	/**
	 * Cron task runner.
	 */
	public function run_cron(): void {
		$settings = $this->plugin->settings()->all();
		MDVRM_DB::purge_older_than( $settings['retention_days'] );
		MDVRM_DB::enforce_limit( $settings['limit'] );

		$this->send_report();
		$this->check_alerts();
	}

	/**
	 * Send summary email.
	 *
	 * @return bool Success status.
	 */
	public function send_report(): bool {
		global $wpdb;

		$settings  = $this->plugin->settings()->all();
		$recipient = $settings['alert_recipient'];
		if ( ! $recipient || ! is_email( $recipient ) ) {
			return false;
		}

		$table = MDVRM_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avg = $wpdb->get_row( $wpdb->prepare( 'SELECT AVG(ttfb) as ttfb, AVG(lcp) as lcp, AVG(total_load) as total_load FROM %i', $table ), ARRAY_A );

		$avg_ttfb  = isset( $avg['ttfb'] ) ? floatval( $avg['ttfb'] ) : 0;
		$avg_lcp   = isset( $avg['lcp'] ) ? floatval( $avg['lcp'] ) : 0;
		$avg_total = isset( $avg['total_load'] ) ? floatval( $avg['total_load'] ) : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$top_pages = $wpdb->get_results( $wpdb->prepare( 'SELECT url, AVG(ttfb) as ttfb, AVG(lcp) as lcp, COUNT(*) as hits FROM %i GROUP BY url HAVING hits > 1 ORDER BY lcp DESC LIMIT 10', $table ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$devices = $wpdb->get_results( $wpdb->prepare( 'SELECT device, COUNT(*) as hits FROM %i GROUP BY device', $table ), ARRAY_A );

		$site_name = get_bloginfo( 'name' );
		$body      = "Mudrava RUM Report for {$site_name}\n";
		$body     .= "--------------------------------------------------\n";
		$body     .= sprintf( "Avg LCP (User Exp):  %.3fs\nAvg TTFB (Server):   %.3fs\nAvg Total Load:      %.3fs\n\n", $avg_lcp, $avg_ttfb, $avg_total );

		$body .= "Top Problematic Pages (Sort by LCP, min 2 views):\n";
		$body .= "--------------------------------------------------\n";
		if ( $top_pages ) {
			foreach ( (array) $top_pages as $row ) {
				$body .= sprintf( "[LCP: %.3fs | TTFB: %.3fs] %s (%d hits)\n", $row['lcp'], $row['ttfb'], $row['url'], $row['hits'] );
			}
		} else {
			$body .= "Not enough data yet.\n";
		}

		$body .= "\nDevice Usage:\n";
		foreach ( (array) $devices as $row ) {
			$body .= sprintf( "%s: %d\n", ( $row['device'] ? $row['device'] : 'unknown' ), $row['hits'] );
		}

		/**
		 * Filter the email report body before sending.
		 *
		 * @param string $body      Email body text.
		 * @param string $recipient Recipient email address.
		 * @param array  $avg       Average metrics.
		 */
		$body = apply_filters( 'mdvrm_report_email_body', $body, $recipient, $avg );

		return wp_mail( $recipient, "Mudrava RUM Report: {$site_name}", $body );
	}

	/**
	 * Check for TTFB alerts.
	 */
	protected function check_alerts(): void {
		global $wpdb;

		$settings    = $this->plugin->settings()->all();
		$threshold   = floatval( $settings['alert_ttfb_threshold'] );
		$consecutive = absint( $settings['alert_consecutive'] );
		$recipient   = $settings['alert_recipient'];

		if ( $threshold <= 0 || ! $recipient ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT ttfb FROM %i ORDER BY event_time DESC LIMIT %d', MDVRM_TABLE, max( 20, $consecutive ) ) );

		$streak = 0;
		foreach ( (array) $rows as $ttfb ) {
			if ( floatval( $ttfb ) > $threshold ) {
				++$streak;
			} else {
				break;
			}
		}

		if ( $streak < $consecutive ) {
			return;
		}

		$last_alert = get_option( 'mdvrm_last_alert_ts', 0 );
		$now        = time();
		if ( $now - $last_alert < absint( $settings['alert_min_interval'] ) ) {
			return;
		}

		$subject = sprintf( 'TTFB alert: %d hits over %.2fs', $streak, $threshold );
		$body    = sprintf( '%d consecutive requests exceeded %.2fs TTFB.', $streak, $threshold );

		wp_mail( $recipient, $subject, $body );
		update_option( 'mdvrm_last_alert_ts', $now );
	}
}
