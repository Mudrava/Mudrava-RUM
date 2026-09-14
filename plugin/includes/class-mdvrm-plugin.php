<?php
/**
 * Core bootstrap for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-settings.php';
require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-db.php';
require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-rest.php';
require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-collector.php';
require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-admin.php';
require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-reports.php';

/**
 * Core bootstrap class for Mudrava RUM.
 */
class MDVRM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var MDVRM_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Settings handler.
	 *
	 * @var MDVRM_Settings
	 */
	protected $settings;

	/**
	 * Collector instance.
	 *
	 * @var MDVRM_Collector
	 */
	protected $collector;

	/**
	 * REST handler.
	 *
	 * @var MDVRM_REST
	 */
	protected $rest;

	/**
	 * Sampling decision for the current request.
	 *
	 * @var bool|null
	 */
	protected $track_decision = null;

	/**
	 * Admin UI handler.
	 *
	 * @var MDVRM_Admin
	 */
	protected $admin;

	/**
	 * Reports/alerts handler.
	 *
	 * @var MDVRM_Reports
	 */
	protected $reports;

	/**
	 * Get singleton.
	 *
	 * @return MDVRM_Plugin
	 */
	public static function instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * Hooks are registered here (plugins_loaded time) so that the WP-Cron
	 * handler is available before core fires wp_cron() on init.
	 */
	protected function __construct() {
		$this->settings  = new MDVRM_Settings();
		$this->collector = new MDVRM_Collector( $this );
		$this->rest      = new MDVRM_REST( $this );
		$this->admin     = new MDVRM_Admin( $this );
		$this->reports   = new MDVRM_Reports( $this );

		$this->collector->hook();
		$this->rest->hook();
		$this->admin->hook();
		$this->reports->hook();

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_content' ) );

		/**
		 * Fires after Mudrava RUM is fully loaded.
		 *
		 * @param MDVRM_Plugin $plugin Plugin instance.
		 */
		do_action( 'mdvrm_loaded', $this );
	}

	/**
	 * Activation handler.
	 */
	public static function activate(): void {
		MDVRM_DB::create_table();
		update_option( 'mdvrm_version', MDVRM_VERSION );

		$instance = self::instance();
		$instance->reports->register_cron();
	}

	/**
	 * Re-run schema migrations when the plugin version changed.
	 */
	public function maybe_upgrade(): void {
		$installed = get_option( 'mdvrm_version', '0' );
		if ( version_compare( $installed, MDVRM_VERSION, '<' ) ) {
			MDVRM_DB::create_table();
			update_option( 'mdvrm_version', MDVRM_VERSION );
		}
	}

	/**
	 * Deactivation handler.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( MDVRM_Reports::CRON_HOOK );
	}

	/**
	 * Get settings handler.
	 *
	 * @return MDVRM_Settings
	 */
	public function settings(): MDVRM_Settings {
		return $this->settings;
	}

	/**
	 * Get reports handler.
	 *
	 * @return MDVRM_Reports
	 */
	public function reports(): MDVRM_Reports {
		return $this->reports;
	}

	/**
	 * Register privacy policy suggested content.
	 */
	public function register_privacy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__( 'Mudrava RUM', 'mudrava-rum' ),
			__( 'This plugin collects performance metrics (page load times, device type, network type, country derived from Cloudflare headers, and the visited page URL without query string) from site visitors. For logged-in visitors it may also store a non-unique role label for filtering. No email address, username, IP address, or other directly identifying profile data is stored.', 'mudrava-rum' ),
			__( 'Session IDs are randomly generated per browser tab using sessionStorage and are not linked to user accounts. Role labels are stored independently of user IDs. No cookies are set by this plugin. No data is sent to external services; all collected data is stored locally in your WordPress database.', 'mudrava-rum' ),
			__( 'Collected data is automatically purged based on configured retention settings.', 'mudrava-rum' )
		);

		wp_add_privacy_policy_content( 'Mudrava RUM', wp_kses_post( $content ) );
	}

	/**
	 * Clear per-request cached decisions. Used by integration tests.
	 */
	public function reset_request_state(): void {
		$this->track_decision = null;
	}

	/**
	 * Check if request should be sampled and recorded.
	 *
	 * The decision is made once per page render. Sampling is intentionally
	 * NOT repeated at ingestion time.
	 *
	 * @return bool
	 */
	public function should_track_request(): bool {
		if ( null !== $this->track_decision ) {
			return $this->track_decision;
		}

		$settings = $this->settings->all();

		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( array_intersect( $settings['excluded_roles'], (array) $user->roles ) ) {
				$this->track_decision = false;
				return false;
			}
		}

		$raw_uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path      = (string) wp_parse_url( $raw_uri, PHP_URL_PATH );
		$blacklist = $settings['blacklist'];
		foreach ( $blacklist as $prefix ) {
			$prefix = rtrim( (string) $prefix, '/' );
			if ( '' === $prefix ) {
				continue;
			}
			if ( $path === $prefix || 0 === strpos( $path, $prefix . '/' ) ) {
				$this->track_decision = false;
				return false;
			}
		}

		$sample = $settings['sample_rate'];
		if ( $sample < 1 && wp_rand( 0, 1000 ) / 1000 >= $sample ) {
			$this->track_decision = false;
			return false;
		}

		/**
		 * Filter whether the current request should be tracked.
		 *
		 * @param bool  $track    Whether to track the request.
		 * @param array $settings Current plugin settings.
		 */
		$this->track_decision = (bool) apply_filters( 'mdvrm_should_track_request', true, $settings );
		return $this->track_decision;
	}

	/**
	 * Provide server-side metrics for JS collector.
	 *
	 * @return array
	 */
	public function get_server_context(): array {
		$server_time = $this->get_server_time();
		$memory_peak = memory_get_peak_usage( true );
		$country     = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';

		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			$country = '';
		}

		return array(
			'serverTime' => $server_time,
			'memoryPeak' => $memory_peak,
			'country'    => $country,
		);
	}

	/**
	 * Compute server render time using REQUEST_TIME_FLOAT baseline.
	 *
	 * @return float Seconds with micro precision.
	 */
	protected function get_server_time(): float {
		$now = microtime( true );

		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Numeric timestamp, cast to float.
			$start = (float) $_SERVER['REQUEST_TIME_FLOAT'];
		} elseif ( ! empty( $GLOBALS['timestart'] ) ) {
			$start = (float) $GLOBALS['timestart'];
		} else {
			$start = $now;
		}

		$elapsed = $now - $start;

		return max( 0, round( $elapsed, 4 ) );
	}
}
