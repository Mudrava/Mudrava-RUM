<?php
/**
 * Admin UI for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI class for Mudrava RUM.
 */
class MDVRM_Admin {

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
	 * Hook into WordPress.
	 */
	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu pages.
	 */
	public function add_menu(): void {
		add_menu_page(
			'Mudrava RUM',
			'Mudrava RUM',
			'manage_options',
			'mudrava-rum',
			array( $this, 'render_live' ),
			'dashicons-performance',
			80
		);

		add_submenu_page(
			'mudrava-rum',
			'Live Monitor',
			'Live Monitor',
			'manage_options',
			'mudrava-rum',
			array( $this, 'render_live' )
		);

		add_submenu_page(
			'mudrava-rum',
			'Settings',
			'Settings',
			'manage_options',
			'mudrava-rum-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Enqueue admin CSS and JS assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'mudrava-rum' ) === false ) {
			return;
		}

		wp_enqueue_style( 'mdvrm-admin-css', MDVRM_PLUGIN_URL . 'assets/admin/mdvrm-admin.css', array(), MDVRM_VERSION );
		wp_enqueue_script( 'mdvrm-admin-js', MDVRM_PLUGIN_URL . 'assets/admin/mdvrm-admin.js', array(), MDVRM_VERSION, true );

		wp_localize_script(
			'mdvrm-admin-js',
			'MDVRMAdminSettings',
			array(
				'restUrl'       => get_rest_url( null, 'mudrava-rum/v1/logs' ),
				'statsUrl'      => get_rest_url( null, 'mudrava-rum/v1/stats' ),
				'sendReportUrl' => get_rest_url( null, 'mudrava-rum/v1/send-report' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'i18n'          => array(
					'loading'   => __( 'Loading…', 'mudrava-rum' ),
					'empty'     => __( 'No entries yet.', 'mudrava-rum' ),
					'session'   => __( 'Filter by Session ID', 'mudrava-rum' ),
					'timestamp' => __( 'Time', 'mudrava-rum' ),
					'url'       => __( 'URL', 'mudrava-rum' ),
					'ttfb'      => __( 'TTFB', 'mudrava-rum' ),
					'lcp'       => __( 'LCP', 'mudrava-rum' ),
					'load'      => __( 'Total Load', 'mudrava-rum' ),
					'device'    => __( 'Device', 'mudrava-rum' ),
					'net'       => __( 'Net', 'mudrava-rum' ),
					'country'   => __( 'Country', 'mudrava-rum' ),
				),
			)
		);
	}

	/**
	 * Render live log page.
	 */
	public function render_live(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Preload logs for instant render.
		$preload = MDVRM_DB::query_logs(
			array(
				'per_page' => 20,
				'page'     => 1,
			)
		);
		?>
		<div class="wrap mdvrm-wrap">
			<div class="mdvrm-header">
				<div>
					<h1>Mudrava RUM</h1>
					<div class="mdvrm-header-info">
						<p>Real User Monitoring (RUM) captures performance metrics from actual visitors.</p>
					</div>
				</div>
				<div class="mdvrm-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mudrava-rum-settings' ) ); ?>" class="button button-primary">Settings</a>
				</div>
			</div>

			<div class="mdvrm-info-cards">
				<div class="mdvrm-card-metric">
					<h3>TTFB (Time to First Byte)</h3>
					<p>Time from request start until the first byte of response. High TTFB means slow server/PHP/database.</p>
					<div class="mdvrm-goal">Target: &lt; 0.8s</div>
				</div>
				<div class="mdvrm-card-metric">
					<h3>LCP (Largest Contentful Paint)</h3>
					<p>Time until the main content is visible. Affected by TTFB, render-blocking JS/CSS, and image size.</p>
					<div class="mdvrm-goal">Target: &lt; 2.5s</div>
				</div>
				<div class="mdvrm-card-metric">
					<h3>Server Gen Time</h3>
					<p>How long PHP took to generate the HTML. Pure backend execution time (excludes network latency).</p>
					<div class="mdvrm-goal">Target: &lt; 0.5s</div>
				</div>
			</div>
			
			<div id="mdvrm-live-log" class="mdvrm-live-log" data-preload="<?php echo esc_attr( wp_json_encode( $preload ) ); ?>"></div>
		</div>
		<?php
		$this->render_footer();
	}

	/**
	 * Render settings page.
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message  = '';
		$settings = $this->plugin->settings()->all();

		if ( isset( $_POST['mdvrm_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mdvrm_settings_nonce'] ) ), 'mdvrm_save_settings' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized in MDVRM_Settings::update().
			$data = wp_unslash( $_POST );
			if ( ! isset( $data['excluded_roles'] ) ) {
				$data['excluded_roles'] = array();
			}
			$settings = $this->plugin->settings()->update( $data );
			$message  = __( 'Settings saved.', 'mudrava-rum' );
		}

		$wp_roles  = wp_roles();
		$all_roles = $wp_roles->get_names();
		?>
		<div class="wrap mdvrm-wrap">
			<h1>Mudrava RUM Settings</h1>
			
			<?php if ( $message ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mdvrm_save_settings', 'mdvrm_settings_nonce' ); ?>

				<div class="mdvrm-settings-section">
					<h2>Retention Policy</h2>
					<div class="mdvrm-field-row">
						<label for="mdvrm-limit">Max Records</label>
						<div>
							<input id="mdvrm-limit" name="limit" type="number" min="100" value="<?php echo esc_attr( $settings['limit'] ); ?>" class="regular-text" />
							<p class="mdvrm-field-desc">Oldest records are removed when this limit is reached.</p>
						</div>
					</div>
					<div class="mdvrm-field-row">
						<label for="mdvrm-retention">Retention Limit (Days)</label>
						<div>
							<input id="mdvrm-retention" name="retention_days" type="number" min="1" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="regular-text" />
							<p class="mdvrm-field-desc">Logs older than this will be purged automatically.</p>
						</div>
					</div>
				</div>

				<div class="mdvrm-settings-section">
					<h2>Tracking Rules</h2>
					<div class="mdvrm-field-row">
						<label for="mdvrm-sample">Sampling Rate</label>
						<div>
							<select id="mdvrm-sample" name="sample_rate" class="regular-text">
								<option value="1" <?php selected( $settings['sample_rate'], 1.0 ); ?>>100% (All traffic)</option>
								<option value="0.5" <?php selected( $settings['sample_rate'], 0.5 ); ?>>50%</option>
								<option value="0.1" <?php selected( $settings['sample_rate'], 0.1 ); ?>>10%</option>
							</select>
						</div>
					</div>
					<div class="mdvrm-field-row">
						<label>Excluded Roles</label>
						<div class="mdvrm-checkbox-list">
							<?php foreach ( $all_roles as $role_key => $role_name ) : ?>
								<label class="mdvrm-checkbox-item">
									<input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['excluded_roles'], true ) ); ?> />
									<?php echo esc_html( $role_name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="mdvrm-field-desc">Users with these roles will not be tracked.</p>
					</div>
					<div class="mdvrm-field-row">
						<label for="mdvrm-blacklist">Blacklist URLs</label>
						<div>
							<textarea id="mdvrm-blacklist" name="blacklist" rows="4" class="large-text code" placeholder="/wp-admin&#10;/checkout"><?php echo esc_textarea( implode( "\n", $settings['blacklist'] ) ); ?></textarea>
							<p class="mdvrm-field-desc">Enter URL prefixes to ignore (one per line).</p>
						</div>
					</div>
				</div>

				<div class="mdvrm-settings-section">
					<h2>Email Reports</h2>
					<div class="mdvrm-field-row">
						<label for="mdvrm-report">Schedule</label>
						<div>
							<select id="mdvrm-report" name="report_schedule" class="regular-text">
								<option value="daily" <?php selected( $settings['report_schedule'], 'daily' ); ?>>Daily</option>
								<option value="weekly" <?php selected( $settings['report_schedule'], 'weekly' ); ?>>Weekly</option>
							</select>
						</div>
					</div>
					<div class="mdvrm-field-row">
						<label for="mdvrm-recipient">Recipient Email</label>
						<div>
							<input id="mdvrm-recipient" name="alert_recipient" type="email" value="<?php echo esc_attr( $settings['alert_recipient'] ); ?>" class="regular-text" style="vertical-align:middle;margin-right:10px;" />
							<button type="button" id="mdvrm-send-test-email" class="button button-secondary">Test Email</button>
							<p class="mdvrm-field-desc">Where to send performance summaries and alerts.</p>
						</div>
					</div>
				</div>

				<div class="mdvrm-settings-section">
					<h2>Critical Alerts</h2>
					<div class="mdvrm-field-row">
						<label for="mdvrm-ttfb">TTFB Threshold (seconds)</label>
						<div>
							<input id="mdvrm-ttfb" name="alert_ttfb_threshold" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['alert_ttfb_threshold'] ); ?>" class="small-text" />
							<p class="mdvrm-field-desc">Trigger an alert if server response time exceeds this value.</p>
						</div>
					</div>
					<div class="mdvrm-field-row">
						<label for="mdvrm-consecutive">Trigger Logic</label>
						<div>
							<input id="mdvrm-consecutive" name="alert_consecutive" type="number" min="1" value="<?php echo esc_attr( $settings['alert_consecutive'] ); ?>" class="small-text" />
							<span class="description"> consecutive requests</span>
							<p class="mdvrm-field-desc">How many slow requests in a row trigger the alert.</p>
						</div>
					</div>
					<div class="mdvrm-field-row">
						<label for="mdvrm-cooldown">Cooldown (seconds)</label>
						<div>
							<input id="mdvrm-cooldown" name="alert_min_interval" type="number" min="300" value="<?php echo esc_attr( $settings['alert_min_interval'] ); ?>" class="small-text" />
							<p class="mdvrm-field-desc">Minimum time between email alerts to avoid spamming.</p>
						</div>
					</div>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save Settings', 'mudrava-rum' ); ?></button>
				</p>
			</form>
		</div>
		<?php
		$this->render_footer();
	}

	/**
	 * Render MUDRAVA branded footer.
	 */
	private function render_footer(): void {
		?>
		<div class="mdvrm-footer">
			<div class="mdvrm-footer__brand">
				<a href="https://mudrava.com" target="_blank" rel="noopener">
					<svg class="mdvrm-footer__logo" width="120" height="24" viewBox="0 0 497 100" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M497 100H0V0H497V100Z" fill="#021D69"></path>
						<path d="M17.24 20.832V17.952H34.904L62.552 76.704L59.384 84H46.904L17.24 20.832ZM71.48 56.256H71.096L64.664 71.808L56.408 54.144L71.48 17.952H89.72V84H71.48V56.256ZM17.24 30.336L34.904 67.968V84H17.24V30.336Z" fill="white"></path>
						<path d="M129.057 84.768C122.337 84.768 117.153 84.224 113.505 83.136C109.857 82.048 107.169 80.288 105.441 77.856C103.841 75.616 102.849 72.768 102.465 69.312C102.081 65.856 101.889 60.704 101.889 53.856V17.952H121.089V57.696C121.089 60.064 121.153 62.336 121.281 64.512C121.409 66.24 121.697 67.488 122.145 68.256C122.593 69.024 123.361 69.504 124.449 69.696C125.409 69.952 126.945 70.08 129.057 70.08H131.266C131.777 70.08 132.354 70.016 132.993 69.888V84.672C132.546 84.736 131.905 84.768 131.073 84.768H129.057ZM137.025 17.952H156.225V53.856C156.225 60.128 156.097 64.864 155.841 68.064C155.585 71.264 154.881 73.952 153.729 76.128C152.449 78.624 150.497 80.544 147.873 81.888C145.249 83.232 141.633 84.096 137.025 84.48V17.952Z" fill="white"></path>
						<path d="M168.459 17.952H187.659V84H168.459V17.952ZM191.691 69.312H192.459C195.595 69.312 197.803 69.184 199.083 68.928C200.427 68.608 201.419 67.904 202.059 66.816C202.763 65.664 203.147 63.84 203.211 61.344C203.339 58.144 203.403 54.688 203.403 50.976C203.403 47.328 203.339 43.84 203.211 40.512C203.083 38.016 202.667 36.192 201.963 35.04C201.323 33.888 200.267 33.184 198.795 32.928C197.323 32.736 195.211 32.64 192.459 32.64H191.691V17.952H192.459C197.579 17.952 201.835 18.176 205.227 18.624C208.683 19.072 211.531 19.776 213.771 20.736C215.947 21.696 217.675 23.008 218.955 24.672C220.235 26.336 221.163 28.416 221.739 30.912C222.251 33.152 222.571 35.808 222.699 38.88C222.891 41.888 222.987 45.92 222.987 50.976C222.987 56.096 222.891 60.16 222.699 63.168C222.571 66.176 222.251 68.8 221.739 71.04C221.163 73.536 220.235 75.616 218.955 77.28C217.675 78.944 215.947 80.256 213.771 81.216C211.531 82.176 208.683 82.88 205.227 83.328C201.835 83.776 197.579 84 192.459 84H191.691V69.312Z" fill="white"></path>
						<path d="M233.709 17.952H252.909V84H233.709V17.952ZM260.781 61.536H256.941V46.848H260.205C262.189 46.848 263.693 46.784 264.717 46.656C265.741 46.464 266.541 46.144 267.117 45.696C267.629 45.248 267.981 44.576 268.173 43.68C268.365 42.784 268.461 41.472 268.461 39.744C268.461 38.016 268.365 36.704 268.173 35.808C267.981 34.848 267.629 34.144 267.117 33.696C266.605 33.248 265.837 32.96 264.813 32.832C263.853 32.704 262.317 32.64 260.205 32.64H256.941V17.952H266.829C271.373 17.952 275.053 18.4 277.869 19.296C280.685 20.192 282.861 21.568 284.397 23.424C285.805 25.152 286.733 27.296 287.181 29.856C287.693 32.416 287.949 35.712 287.949 39.744C287.949 44.928 287.469 48.928 286.509 51.744C285.165 55.328 282.797 57.856 279.405 59.328L289.389 84H269.229L260.781 61.536Z" fill="white"></path>
						<path d="M313.372 17.952H315.004L322.588 44.256L311.548 84H292.348L313.372 17.952ZM334.972 72.288H318.94L322.972 57.504H330.652L319.324 17.952H336.892L357.916 84H338.332L334.972 72.288Z" fill="white"></path>
						<path d="M373.419 81.216C372.587 78.656 371.851 76.16 371.211 73.728L368.139 63.168C367.115 59.712 366.315 57.12 365.739 55.392C365.227 53.472 364.811 52.032 364.491 51.072L354.699 17.952H374.283L392.235 84H374.283L373.419 81.216ZM388.491 54.72L398.187 17.952H417.387L407.595 51.072L404.043 63.168C402.891 66.88 401.835 70.4 400.875 73.728C400.235 76.16 399.499 78.656 398.667 81.216L397.803 84H396.459L388.491 54.72Z" fill="white"></path>
						<path d="M435.247 17.952H436.879L444.463 44.256L433.423 84H414.223L435.247 17.952ZM456.847 72.288H440.815L444.847 57.504H452.527L441.199 17.952H458.767L479.791 84H460.207L456.847 72.288Z" fill="white"></path>
					</svg>
				</a>
			</div>
			<div class="mdvrm-footer__info">
				<span class="mdvrm-footer__copy">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> MUDRAVA. All rights reserved.</span>
				<span class="mdvrm-footer__sep">&middot;</span>
				<a class="mdvrm-footer__link" href="https://mudrava.com" target="_blank" rel="noopener">mudrava.com</a>
				<span class="mdvrm-footer__sep">&middot;</span>
				<a class="mdvrm-footer__link" href="mailto:support@mudrava.com">support@mudrava.com</a>
			</div>
		</div>
		<?php
	}
}
