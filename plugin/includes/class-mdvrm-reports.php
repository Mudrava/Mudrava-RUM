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
	 *
	 * Called on plugins_loaded so the cron handler exists before core
	 * dispatches due events during init.
	 */
	public function hook(): void {
		add_action( 'init', array( $this, 'register_cron' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
	}

	/**
	 * Register cron schedule.
	 */
	public function register_cron(): void {
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
		$schedules['mdvrm_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			/* translators: %s: report schedule. */
			'display'  => sprintf( __( 'Mudrava RUM housekeeping (reports: %s)', 'mudrava-rum' ), $this->plugin->settings()->get( 'report_schedule' ) ),
		);

		return $schedules;
	}

	/**
	 * Ensure the hourly housekeeping event is scheduled.
	 */
	protected function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'mdvrm_hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Cron task runner: housekeeping every hour, emails per schedule.
	 */
	public function run_cron(): void {
		$settings = $this->plugin->settings()->all();
		MDVRM_DB::purge_older_than( $settings['retention_days'] );
		MDVRM_DB::enforce_limit( $settings['limit'] );

		if ( $this->report_due() ) {
			$this->send_report();
		}
	}

	/**
	 * Whether a scheduled report is due based on schedule and last send.
	 *
	 * @return bool
	 */
	protected function report_due(): bool {
		$settings = $this->plugin->settings()->all();
		$interval = ( 'weekly' === $settings['report_schedule'] ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
		$last     = (int) get_option( 'mdvrm_last_report_ts', 0 );

		return ( time() - $last ) >= $interval;
	}

	/**
	 * Format an optional metric value for the plain-text report.
	 *
	 * @param mixed $value Metric value or null.
	 * @return string
	 */
	protected function format_metric( $value ): string {
		if ( null === $value || '' === $value ) {
			/* translators: placeholder for a missing performance metric. */
			return __( '—', 'mudrava-rum' );
		}

		return sprintf( '%.3fs', (float) $value );
	}

	/**
	 * Format a UTC Unix timestamp in the site timezone.
	 *
	 * @param int $timestamp UTC timestamp.
	 * @return string
	 */
	protected function format_timestamp( int $timestamp ): string {
		$date = wp_date( 'Y-m-d H:i', $timestamp );
		$tz   = wp_timezone_string();

		return $tz ? $date . ' ' . $tz : $date;
	}

	/**
	 * Period length in seconds for the current report schedule.
	 *
	 * @return int
	 */
	protected function report_period_seconds(): int {
		$settings = $this->plugin->settings()->all();

		return ( 'weekly' === $settings['report_schedule'] ) ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Send summary email for the reporting period.
	 *
	 * @param bool $manual Whether triggered manually from admin UI.
	 * @return bool Success status.
	 */
	public function send_report( bool $manual = false ): bool {
		$settings  = $this->plugin->settings()->all();
		$recipient = $settings['alert_recipient'];
		if ( ! $recipient || ! is_email( $recipient ) ) {
			return false;
		}

		$period    = $this->report_period_seconds();
		$days      = max( 1, (int) ceil( $period / DAY_IN_SECONDS ) );
		$last_sent = get_option( 'mdvrm_last_report_ts' );
		$last_sent = $last_sent ? self::format_timestamp( (int) $last_sent ) : __( 'site start', 'mudrava-rum' );

		$stats = MDVRM_DB::get_stats( array( 'days' => $days ) );

		$period_label = ( 'weekly' === $settings['report_schedule'] )
			? __( 'Last 7 days', 'mudrava-rum' )
			: __( 'Last 24 hours', 'mudrava-rum' );

		$site_name = get_bloginfo( 'name' );
		/* translators: 1: site name, 2: period label. */
		$body  = sprintf( __( "Mudrava RUM report — %1\$s (%2\$s)\n", 'mudrava-rum' ), $site_name, $period_label );
		$body .= "--------------------------------------------------\n";
		/* translators: 1: count, 2: LCP, 3: P75 LCP, 4: TTFB, 5: server, 6: load. */
		$body .= sprintf( __( "Pageviews:    %1\$d\nAvg LCP:      %2\$s\nP75 LCP:      %3\$s\nAvg TTFB:     %4\$s\nAvg server:   %5\$s\nAvg load:     %6\$s\n\n", 'mudrava-rum' ), $stats['count'], $this->format_metric( $stats['avg_lcp'] ?? null ), $this->format_metric( $stats['p75_lcp'] ?? null ), $this->format_metric( $stats['avg_ttfb'] ?? null ), $this->format_metric( $stats['avg_server'] ?? null ), $this->format_metric( $stats['avg_load'] ?? null ) );

		$body .= __( "Top slow pages by LCP (min 2 views):\n", 'mudrava-rum' );
		$body .= "--------------------------------------------------\n";
		if ( $stats['slowest_lcp'] ) {
			foreach ( $stats['slowest_lcp'] as $row ) {
				/* translators: 1: LCP or placeholder, 2: TTFB or placeholder, 3: URL, 4: hits. */
				$body .= sprintf( '[LCP: %1$s | TTFB: %2$s] %3$s (%4$d hits)' . "\n", $this->format_metric( $row['avg_lcp'] ?? null ), $this->format_metric( $row['avg_ttfb'] ?? null ), $row['url'], (int) $row['count'] );
			}
		} else {
			$body .= __( "Not enough data in this period.\n", 'mudrava-rum' );
		}

		$body .= "\n" . __( "Devices:\n", 'mudrava-rum' );
		foreach ( $stats['devices'] as $row ) {
			$body .= sprintf( '%s: %d' . "\n", $row['device'] ? $row['device'] : 'unknown', (int) $row['count'] );
		}

		/* translators: %s: timestamp of last report. */
		$body .= "\n" . sprintf( __( 'Period: since %s', 'mudrava-rum' ), $last_sent ) . "\n";

		/**
		 * Filter the email report body before sending.
		 *
		 * @param string $body      Email body text.
		 * @param string $recipient Recipient email address.
		 * @param array  $stats     Aggregated stats.
		 */
		$body = apply_filters( 'mdvrm_report_email_body', $body, $recipient, $stats );

		/* translators: %s: site name. */
		$default_subject = sprintf( __( 'Mudrava RUM report: %s', 'mudrava-rum' ), $site_name );

		/**
		 * Filter the scheduled/manual report email subject.
		 *
		 * @param string $subject   Email subject.
		 * @param string $recipient Recipient email address.
		 * @param string $site_name Site name.
		 * @param array  $stats     Aggregated stats.
		 */
		$subject = apply_filters(
			'mdvrm_report_email_subject',
			$default_subject,
			$recipient,
			$site_name,
			$stats
		);

		$sent = wp_mail( $recipient, $subject, $body );

		if ( $sent && ! $manual ) {
			update_option( 'mdvrm_last_report_ts', time() );
		}

		return $sent;
	}

	/**
	 * Track a TTFB sample for consecutive-slow alerting.
	 *
	 * Called from the ingestion endpoint on every recorded pageview.
	 *
	 * @param float $ttfb Recorded TTFB in seconds.
	 */
	public function track_ttfb_sample( float $ttfb ): void {
		$settings    = $this->plugin->settings()->all();
		$threshold   = floatval( $settings['alert_ttfb_threshold'] );
		$consecutive = absint( $settings['alert_consecutive'] );
		$recipient   = $settings['alert_recipient'];

		if ( $threshold <= 0 || ! $recipient ) {
			return;
		}

		$streak = get_option( 'mdvrm_ttfb_streak', 0 );
		$streak = $ttfb > $threshold ? $streak + 1 : 0;
		update_option( 'mdvrm_ttfb_streak', $streak, false );

		if ( $streak < $consecutive ) {
			return;
		}

		$cooldown = absint( $settings['alert_min_interval'] );
		$last     = (int) get_option( 'mdvrm_last_alert_ts', 0 );
		if ( time() - $last < $cooldown ) {
			return;
		}

		update_option( 'mdvrm_last_alert_ts', time() );
		update_option( 'mdvrm_ttfb_streak', 0 );

		/* translators: 1: streak count, 2: threshold in seconds. */
		$subject = sprintf( __( '[Mudrava RUM] TTFB alert: %1$d requests over %2$s', 'mudrava-rum' ), $streak, $threshold . 's' );

		/**
		 * Filter the TTFB alert email subject.
		 *
		 * @param string $subject   Email subject.
		 * @param int    $streak    Consecutive slow-request count.
		 * @param float  $threshold Alert threshold in seconds.
		 */
		$subject = apply_filters( 'mdvrm_alert_email_subject', $subject, $streak, $threshold );

		wp_mail(
			$recipient,
			$subject,
			/* translators: 1: streak count, 2: threshold in seconds, 3: site name. */
			sprintf( __( '%1$d consecutive pageviews exceeded %2$s of server response time on %3$s.', 'mudrava-rum' ), $streak, $threshold . 's', get_bloginfo( 'name' ) )
		);
	}
}
