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
			__( 'Mudrava RUM', 'mudrava-rum' ),
			__( 'Mudrava RUM', 'mudrava-rum' ),
			'manage_options',
			'mudrava-rum',
			array( $this, 'render_live' ),
			'dashicons-performance',
			80
		);

		add_submenu_page(
			'mudrava-rum',
			__( 'Mudrava RUM — Live Monitor', 'mudrava-rum' ),
			__( 'Live Monitor', 'mudrava-rum' ),
			'manage_options',
			'mudrava-rum',
			array( $this, 'render_live' )
		);

		add_submenu_page(
			'mudrava-rum',
			__( 'Mudrava RUM — Settings', 'mudrava-rum' ),
			__( 'Settings', 'mudrava-rum' ),
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
		if ( false === strpos( $hook, 'mudrava-rum' ) ) {
			return;
		}

		$css = 'assets/admin/mdvrm-admin.min.css';
		$js  = 'assets/admin/mdvrm-admin.min.js';
		if ( ! file_exists( MDVRM_PLUGIN_DIR . $css ) ) {
			$css = 'assets/admin/mdvrm-admin.css';
		}
		if ( ! file_exists( MDVRM_PLUGIN_DIR . $js ) ) {
			$js = 'assets/admin/mdvrm-admin.js';
		}

		wp_enqueue_style( 'mdvrm-admin-css', MDVRM_PLUGIN_URL . $css, array(), $this->asset_version( $css ) );
		wp_enqueue_script( 'mdvrm-admin-js', MDVRM_PLUGIN_URL . $js, array(), $this->asset_version( $js ), true );

		$is_live = ( 'toplevel_page_mudrava-rum' === $hook );

		wp_localize_script(
			'mdvrm-admin-js',
			'MDVRMAdminSettings',
			array(
				'live'          => $is_live ? 1 : 0,
				'restUrl'       => get_rest_url( null, 'mudrava-rum/v1/logs' ),
				'statsUrl'      => get_rest_url( null, 'mudrava-rum/v1/stats' ),
				'sendReportUrl' => get_rest_url( null, 'mudrava-rum/v1/send-report' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'version'       => MDVRM_VERSION,
				'i18n'          => array(
					/* translators: admin JS string. */
					'loading'      => __( 'Loading…', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'empty'        => __( 'No entries yet. Visit some front-end pages to collect data.', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'items'        => __( 'events', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'events'       => __( 'Events', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'views'        => __( 'Views', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'firstPage'    => __( 'First page', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'prevPage'     => __( 'Previous page', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'nextPage'     => __( 'Next page', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'lastPage'     => __( 'Last page', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'updated'      => __( 'Updated', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'error'        => __( 'Failed to load data.', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'badUrl'       => __( 'Invalid URL', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'unknown'      => __( 'unknown', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'sentOk'       => __( 'Email sent successfully.', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'sentFail'     => __( 'Could not send email. Check mail settings and recipient.', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'confirmSend'  => __( 'Send the current report to the configured email now?', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'sessionShort' => __( 'Session', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'allDevices'   => __( 'All devices', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'allNet'       => __( 'All networks', 'mudrava-rum' ),
					/* translators: admin JS string. */
					'allTime'      => __( 'All time', 'mudrava-rum' ),
				),
			)
		);
	}

	/**
	 * Threshold helper: good / needs-improvement / poor.
	 *
	 * @param float $value Value.
	 * @param float $good Good boundary.
	 * @param float $poor Poor boundary.
	 * @return string
	 */
	protected function grade( float $value, float $good, float $poor ): string {
		if ( $value <= 0 ) {
			return 'na';
		}
		if ( $value <= $good ) {
			return 'good';
		}
		if ( $value <= $poor ) {
			return 'avg';
		}
		return 'poor';
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * @param string $relative Asset path relative to the plugin directory.
	 * @return string
	 */
	protected function asset_version( string $relative ): string {
		$file = MDVRM_PLUGIN_DIR . $relative;

		if ( ! file_exists( $file ) ) {
			return MDVRM_VERSION;
		}

		$mtime = filemtime( $file );

		return false === $mtime ? MDVRM_VERSION : (string) $mtime;
	}

	/**
	 * Inline device icon markup.
	 *
	 * @param string $key Device key.
	 * @return string Escaped span with SVG.
	 */
	protected function device_svg( string $key ): string {
		$icons = array(
			'desktop' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="12" rx="1.5"/><path d="M8.5 20h7M12 16.5V20"/></svg>',
			'mobile'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M10.5 18.5h3"/></svg>',
			'tablet'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4.5" y="2.5" width="15" height="19" rx="2"/><path d="M10.5 18.5h3"/></svg>',
		);

		if ( isset( $icons[ $key ] ) ) {
			return '<span class="mdvrm-dev">' . $icons[ $key ] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG literal.
		}

		return '<span class="mdvrm-dev mdvrm-dev--unknown">?</span>';
	}

	/**
	 * Render the MUDRAVA brand logo.
	 *
	 * @param string $logo_class Extra CSS class.
	 * @return void
	 */
	protected function render_logo( string $logo_class = 'mdvrm-logo' ): void {
		?>
		<span class="<?php echo esc_attr( $logo_class ); ?>" aria-hidden="true">
			<svg width="104" height="21" viewBox="0 0 497 100" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false">
				<path d="M497 100H0V0H497V100Z" fill="#021D69"></path>
				<path d="M17.24 20.832V17.952H34.904L62.552 76.704L59.384 84H46.904L17.24 20.832ZM71.48 56.256H71.096L64.664 71.808L56.408 54.144L71.48 17.952H89.72V84H71.48V56.256ZM17.24 30.336L34.904 67.968V84H17.24V30.336Z" fill="white"></path>
				<path d="M129.057 84.768C122.337 84.768 117.153 84.224 113.505 83.136C109.857 82.048 107.169 80.288 105.441 77.856C103.841 75.616 102.849 72.768 102.465 69.312C102.081 65.856 101.889 60.704 101.889 53.856V17.952H121.089V57.696C121.089 60.064 121.153 62.336 121.281 64.512C121.409 66.24 121.697 67.488 122.145 68.256C122.593 69.024 123.361 69.504 124.449 69.696C125.409 69.952 126.945 70.08 129.057 70.08H131.266C131.777 70.08 132.354 70.016 132.993 69.888V84.672C132.546 84.736 131.905 84.768 131.073 84.768H129.057ZM137.025 17.952H156.225V53.856C156.225 60.128 156.097 64.864 155.841 68.064C155.585 71.264 154.881 73.952 153.729 76.128C152.449 78.624 150.497 80.544 147.873 81.888C145.249 83.232 141.633 84.096 137.025 84.48V17.952Z" fill="white"></path>
				<path d="M168.459 17.952H187.659V84H168.459V17.952ZM191.691 69.312H192.459C195.595 69.312 197.803 69.184 199.083 68.928C200.427 68.608 201.419 67.904 202.059 66.816C202.763 65.664 203.147 63.84 203.211 61.344C203.339 58.144 203.403 54.688 203.403 50.976C203.403 47.328 203.339 43.84 203.211 40.512C203.083 38.016 202.667 36.192 201.963 35.04C201.323 33.888 200.267 33.184 198.795 32.928C197.323 32.736 195.211 32.64 192.459 32.64H191.691V17.952H192.459C197.579 17.952 201.835 18.176 205.227 18.624C208.683 19.072 211.531 19.776 213.771 20.736C215.947 21.696 217.675 23.008 218.955 24.672C220.235 26.336 221.163 28.416 221.739 30.912C222.251 33.152 222.571 35.808 222.699 38.88C222.891 41.888 222.987 45.92 222.987 50.976C222.987 56.096 222.891 60.16 222.699 63.168C222.571 66.176 222.251 68.8 221.739 71.04C221.163 73.536 220.235 75.616 218.955 77.28C217.675 78.944 215.947 80.256 213.771 81.216C211.531 82.176 208.683 82.88 205.227 83.328C201.835 83.776 197.579 84 192.459 84H191.691V69.312Z" fill="white"></path>
				<path d="M233.709 17.952H252.909V84H233.709V17.952ZM260.781 61.536H256.941V46.848H260.205C262.189 46.848 263.693 46.784 264.717 46.656C265.741 46.464 266.541 46.144 267.117 45.696C267.629 45.248 267.981 44.576 268.173 43.68C268.365 42.784 268.461 41.472 268.461 39.744C268.461 38.016 268.365 36.704 268.173 35.808C267.981 34.848 267.629 34.144 267.117 33.696C266.605 33.248 265.837 32.96 264.813 32.832C263.853 32.704 262.317 32.64 260.205 32.64H256.941V17.952H266.829C271.373 17.952 275.053 18.4 277.869 19.296C280.685 20.192 282.861 21.568 284.397 23.424C285.805 25.152 286.733 27.296 287.181 29.856C287.693 32.416 287.949 35.712 287.949 39.744C287.949 44.928 287.469 48.928 286.509 51.744C285.165 55.328 282.797 57.856 279.405 59.328L289.389 84H269.229L260.781 61.536Z" fill="white"></path>
				<path d="M313.372 17.952H315.004L322.588 44.256L311.548 84H292.348L313.372 17.952ZM334.972 72.288H318.94L322.972 57.504H330.652L319.324 17.952H336.892L357.916 84H338.332L334.972 72.288Z" fill="white"></path>
				<path d="M373.419 81.216C372.587 78.656 371.851 76.16 371.211 73.728L368.139 63.168C367.115 59.712 366.315 57.12 365.739 55.392C365.227 53.472 364.811 52.032 364.491 51.072L354.699 17.952H374.283L392.235 84H374.283L373.419 81.216ZM388.491 54.72L398.187 17.952H417.387L407.595 51.072L404.043 63.168C402.891 66.88 401.835 70.4 400.875 73.728C400.235 76.16 399.499 78.656 398.667 81.216L397.803 84H396.459L388.491 54.72Z" fill="white"></path>
				<path d="M435.247 17.952H436.879L444.463 44.256L433.423 84H414.223L435.247 17.952ZM456.847 72.288H440.815L444.847 57.504H452.527L441.199 17.952H458.767L479.791 84H460.207L456.847 72.288Z" fill="white"></path>
			</svg>
		</span>
		<?php
	}

	/**
	 * Render live log page.
	 */
	public function render_live(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->plugin->settings()->all();
		$days     = 7;
		$stats    = MDVRM_DB::get_stats( array( 'days' => $days ) );
		$logs     = MDVRM_DB::query_logs(
			array(
				'per_page' => 20,
				'page'     => 1,
				'days'     => $days,
			)
		);

		$kpis = array(
			array(
				'label' => __( 'Events', 'mudrava-rum' ),
				'value' => number_format_i18n( $stats['count'] ),
				'unit'  => '',
				'note'  => __( 'pageviews · last 7 days', 'mudrava-rum' ),
				'grade' => 'neutral',
			),
			array(
				'label' => __( 'Avg TTFB', 'mudrava-rum' ),
				'value' => $stats['avg_ttfb'] ? $stats['avg_ttfb'] . 's' : '—',
				'unit'  => '',
				'note'  => __( 'Good ≤ 0.8s · Poor > 1.8s', 'mudrava-rum' ),
				'grade' => $this->grade( (float) $stats['avg_ttfb'], 0.8, 1.8 ),
			),
			array(
				'label' => __( 'P75 LCP', 'mudrava-rum' ),
				'value' => $stats['p75_lcp'] ? $stats['p75_lcp'] . 's' : '—',
				'unit'  => '',
				'note'  => __( 'Good ≤ 2.5s · Poor > 4s', 'mudrava-rum' ),
				'grade' => $this->grade( (float) $stats['p75_lcp'], 2.5, 4.0 ),
			),
			array(
				'label' => __( 'Avg Server', 'mudrava-rum' ),
				'value' => $stats['avg_server'] ? $stats['avg_server'] . 's' : '—',
				'unit'  => '',
				'note'  => __( 'PHP render · Good ≤ 0.5s', 'mudrava-rum' ),
				'grade' => $this->grade( (float) $stats['avg_server'], 0.5, 1.0 ),
			),
			array(
				'label' => __( 'Avg Total Load', 'mudrava-rum' ),
				'value' => $stats['avg_load'] ? $stats['avg_load'] . 's' : '—',
				'unit'  => '',
				'note'  => __( 'Full page · Good ≤ 3s', 'mudrava-rum' ),
				'grade' => $this->grade( (float) $stats['avg_load'], 3.0, 5.0 ),
			),
		);
		?>
		<div class="wrap mdvrm-wrap">
			<div class="mdvrm-topbar">
				<div class="mdvrm-topbar__brand">
					<?php $this->render_logo(); ?>
					<h1 class="mdvrm-topbar__title"><?php esc_html_e( 'Live Monitor', 'mudrava-rum' ); ?></h1>
					<span class="mdvrm-badge">v<?php echo esc_html( MDVRM_VERSION ); ?></span>
				</div>
				<div class="mdvrm-topbar__actions">
					<button type="button" class="button" id="mdvrm-export-btn"><?php esc_html_e( 'Export CSV', 'mudrava-rum' ); ?></button>
					<button type="button" class="button button-primary" id="mdvrm-report-open"><?php esc_html_e( 'Generate Report', 'mudrava-rum' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mudrava-rum-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Settings', 'mudrava-rum' ); ?></a>
				</div>
			</div>

			<div class="mdvrm-toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Monitor controls', 'mudrava-rum' ); ?>">
				<label class="mdvrm-toolbar__field">
					<span class="screen-reader-text"><?php esc_html_e( 'Time period', 'mudrava-rum' ); ?></span>
					<select id="mdvrm-period" class="mdvrm-period-select">
						<option value="1"><?php esc_html_e( 'Last 24 hours', 'mudrava-rum' ); ?></option>
						<option value="7" selected><?php esc_html_e( 'Last 7 days', 'mudrava-rum' ); ?></option>
						<option value="30"><?php esc_html_e( 'Last 30 days', 'mudrava-rum' ); ?></option>
						<option value="0"><?php esc_html_e( 'All time', 'mudrava-rum' ); ?></option>
					</select>
				</label>
				<label class="mdvrm-toolbar__auto mdvrm-switch">
					<input type="checkbox" id="mdvrm-autorefresh" />
					<span class="mdvrm-switch__track" aria-hidden="true"></span>
					<span class="mdvrm-switch__text"><?php esc_html_e( 'Auto-refresh', 'mudrava-rum' ); ?></span>
				</label>
				<span class="mdvrm-toolbar__status" id="mdvrm-status" aria-live="polite">&nbsp;</span>
			</div>

			<div class="mdvrm-kpis" id="mdvrm-kpis">
				<?php foreach ( $kpis as $kpi ) : ?>
					<div class="mdvrm-kpi mdvrm-kpi--<?php echo esc_attr( $kpi['grade'] ); ?>">
						<div class="mdvrm-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></div>
						<div class="mdvrm-kpi__value"><?php echo esc_html( $kpi['value'] ); ?></div>
						<div class="mdvrm-kpi__note"><?php echo esc_html( $kpi['note'] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mdvrm-filters" id="mdvrm-filters">
				<input type="text" id="mdvrm-f-session" class="mdvrm-filter-input" placeholder="<?php esc_attr_e( 'Session ID…', 'mudrava-rum' ); ?>" aria-label="<?php esc_attr_e( 'Filter by session ID', 'mudrava-rum' ); ?>" />
				<input type="text" id="mdvrm-f-url" class="mdvrm-filter-input mdvrm-filter-input--wide" placeholder="<?php esc_attr_e( 'URL contains…', 'mudrava-rum' ); ?>" aria-label="<?php esc_attr_e( 'Filter by URL', 'mudrava-rum' ); ?>" />
				<select id="mdvrm-f-device" aria-label="<?php esc_attr_e( 'Device', 'mudrava-rum' ); ?>">
					<option value=""><?php esc_html_e( 'All devices', 'mudrava-rum' ); ?></option>
					<option value="mobile"><?php esc_html_e( 'Mobile', 'mudrava-rum' ); ?></option>
					<option value="tablet"><?php esc_html_e( 'Tablet', 'mudrava-rum' ); ?></option>
					<option value="desktop"><?php esc_html_e( 'Desktop', 'mudrava-rum' ); ?></option>
				</select>
				<select id="mdvrm-f-net" aria-label="<?php esc_attr_e( 'Network', 'mudrava-rum' ); ?>">
					<option value=""><?php esc_html_e( 'All networks', 'mudrava-rum' ); ?></option>
					<option value="4g">4G</option>
					<option value="3g">3G</option>
					<option value="2g">2G</option>
					<option value="slow-2g">Slow 2G</option>
				</select>
				<button type="button" class="button" id="mdvrm-apply"><?php esc_html_e( 'Filter', 'mudrava-rum' ); ?></button>
				<button type="button" class="button" id="mdvrm-clear"><?php esc_html_e( 'Clear', 'mudrava-rum' ); ?></button>
				<label class="mdvrm-toolbar__per">
					<span class="screen-reader-text"><?php esc_html_e( 'Rows per page', 'mudrava-rum' ); ?></span>
					<select id="mdvrm-per-page">
						<?php foreach ( array( 20, 50, 100, 200 ) as $n ) : ?>
							<option value="<?php echo esc_attr( $n ); ?>" <?php selected( 20, $n ); ?>><?php echo esc_html( $n ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>

			<div class="mdvrm-table-shell" id="mdvrm-table-shell">
				<table class="mdvrm-table" id="mdvrm-table">
					<thead>
						<tr>
							<th scope="col" data-sort="event_time"><?php esc_html_e( 'Time', 'mudrava-rum' ); ?></th>
							<th scope="col"><?php esc_html_e( 'URL', 'mudrava-rum' ); ?></th>
							<th scope="col" data-sort="ttfb"><?php esc_html_e( 'TTFB', 'mudrava-rum' ); ?></th>
							<th scope="col" data-sort="lcp"><?php esc_html_e( 'LCP', 'mudrava-rum' ); ?></th>
							<th scope="col" data-sort="total_load"><?php esc_html_e( 'Load', 'mudrava-rum' ); ?></th>
							<th scope="col" data-sort="server_time"><?php esc_html_e( 'Server', 'mudrava-rum' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Device', 'mudrava-rum' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Net', 'mudrava-rum' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Country', 'mudrava-rum' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Session', 'mudrava-rum' ); ?></th>
						</tr>
					</thead>
					<tbody id="mdvrm-tbody">
						<?php if ( empty( $logs['data'] ) ) : ?>
							<tr class="mdvrm-empty-row"><td colspan="10"><?php esc_html_e( 'No entries yet. Visit some front-end pages to collect data.', 'mudrava-rum' ); ?></td></tr>
						<?php else : ?>
							<?php
							foreach ( $logs['data'] as $r ) :
								$u = wp_parse_url( $r['url'] );
								?>
								<tr>
									<td class="mdvrm-td-time"><?php echo esc_html( $r['event_time'] ); ?></td>
									<td class="mdvrm-td-url"><a href="<?php echo esc_url( $r['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( isset( $u['path'] ) ? $u['path'] . ( isset( $u['query'] ) ? '?' . $u['query'] : '' ) : $r['url'] ); ?></a></td>
									<td data-metric="ttfb"><?php echo esc_html( $r['ttfb'] ); ?></td>
									<td data-metric="lcp"><?php echo esc_html( $r['lcp'] ); ?></td>
									<td data-metric="total_load"><?php echo esc_html( $r['total_load'] ); ?></td>
									<td data-metric="server_time"><?php echo esc_html( $r['server_time'] ); ?></td>
						<td><span class="mdvrm-pill"><?php echo $this->device_svg( strtolower( (string) $r['device'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in device_svg(). ?><span><?php echo esc_html( $r['device'] ); ?></span></span></td>
						<td><?php echo esc_html( $r['net'] ); ?></td>
						<td><?php echo esc_html( $r['country'] ); ?></td>
						<td class="mdvrm-td-session"><span title="<?php echo esc_attr( $r['session_id'] ); ?>"><?php echo esc_html( substr( $r['session_id'], 0, 8 ) ); ?>&hellip;</span></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<div class="mdvrm-pager" id="mdvrm-pager"></div>
			<div id="mdvrm-live-status" class="screen-reader-text" aria-live="polite"></div>
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
		$error    = '';
		$settings = $this->plugin->settings()->all();

		if ( isset( $_POST['mdvrm_settings_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['mdvrm_settings_nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'mdvrm_save_settings' ) ) {
				$data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field sanitized in MDVRM_Settings::update().
				if ( ! isset( $data['excluded_roles'] ) ) {
					$data['excluded_roles'] = array();
				}
				$data['trust_cf']          = isset( $_POST['trust_cf'] ) ? 1 : 0;
				$data['trust_auth_header'] = isset( $_POST['trust_auth_header'] ) ? 1 : 0;
				$settings                  = $this->plugin->settings()->update( $data );
				$message                   = __( 'Settings saved.', 'mudrava-rum' );
			} else {
				$error = __( 'Session expired. Please try again.', 'mudrava-rum' );
			}
		}

		$wp_roles  = wp_roles();
		$all_roles = $wp_roles->get_names();
		?>
		<div class="wrap mdvrm-wrap">
			<div class="mdvrm-topbar">
				<div class="mdvrm-topbar__brand">
					<?php $this->render_logo(); ?>
					<h1 class="mdvrm-topbar__title"><?php esc_html_e( 'Settings', 'mudrava-rum' ); ?></h1>
					<span class="mdvrm-badge">v<?php echo esc_html( MDVRM_VERSION ); ?></span>
				</div>
				<div class="mdvrm-topbar__actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mudrava-rum' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Live Monitor', 'mudrava-rum' ); ?></a>
				</div>
			</div>

			<?php if ( $message ) : ?>
				<div class="notice notice-success is-dismissible mdvrm-notice"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<div class="notice notice-error is-dismissible mdvrm-notice"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<form method="post" class="mdvrm-settings-grid">
				<?php wp_nonce_field( 'mdvrm_save_settings', 'mdvrm_settings_nonce' ); ?>

				<div class="card mdvrm-card">
					<h2><?php esc_html_e( 'Retention', 'mudrava-rum' ); ?></h2>
					<label class="mdvrm-field" for="mdvrm-limit">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Max records', 'mudrava-rum' ); ?></span>
						<input id="mdvrm-limit" name="limit" type="number" min="100" step="100" value="<?php echo esc_attr( $settings['limit'] ); ?>" />
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Oldest records are removed when this limit is reached.', 'mudrava-rum' ); ?></span>
					</label>
					<label class="mdvrm-field" for="mdvrm-retention">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Retention (days)', 'mudrava-rum' ); ?></span>
						<input id="mdvrm-retention" name="retention_days" type="number" min="1" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" />
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Logs older than this are purged automatically.', 'mudrava-rum' ); ?></span>
					</label>
				</div>

				<div class="card mdvrm-card">
					<h2><?php esc_html_e( 'Tracking', 'mudrava-rum' ); ?></h2>
					<label class="mdvrm-field" for="mdvrm-sample">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Sampling rate', 'mudrava-rum' ); ?></span>
						<select id="mdvrm-sample" name="sample_rate">
							<option value="1" <?php selected( 1.0, (float) $settings['sample_rate'] ); ?>><?php esc_html_e( '100% — all traffic', 'mudrava-rum' ); ?></option>
							<option value="0.5" <?php selected( 0.5, (float) $settings['sample_rate'] ); ?>><?php esc_html_e( '50%', 'mudrava-rum' ); ?></option>
							<option value="0.1" <?php selected( 0.1, (float) $settings['sample_rate'] ); ?>><?php esc_html_e( '10%', 'mudrava-rum' ); ?></option>
						</select>
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Applied once per page render.', 'mudrava-rum' ); ?></span>
					</label>
					<label class="mdvrm-checkbox-item" for="mdvrm-trust-cf">
						<input type="checkbox" id="mdvrm-trust-cf" name="trust_cf" value="1" <?php checked( 1, (int) $settings['trust_cf'] ); ?> />
						<?php esc_html_e( 'Site runs behind Cloudflare: use CF-Connecting-IP for ingest rate limiting.', 'mudrava-rum' ); ?>
					</label>
					<label class="mdvrm-checkbox-item" for="mdvrm-trust-auth">
						<input type="checkbox" id="mdvrm-trust-auth" name="trust_auth_header" value="1" <?php checked( 1, (int) $settings['trust_auth_header'] ); ?> />
						<?php esc_html_e( 'Site runs behind a trusted proxy: use the first address from X-Forwarded-For for ingest rate limiting.', 'mudrava-rum' ); ?>
					</label>
					<fieldset class="mdvrm-field">
						<legend class="mdvrm-field__label"><?php esc_html_e( 'Excluded roles', 'mudrava-rum' ); ?></legend>
						<div class="mdvrm-checkbox-list">
							<?php foreach ( $all_roles as $role_key => $role_name ) : ?>
								<label class="mdvrm-checkbox-item">
									<input type="checkbox" name="excluded_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['excluded_roles'], true ) ); ?> />
									<?php echo esc_html( translate_user_role( $role_name ) ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Logged-in users with these roles are never tracked.', 'mudrava-rum' ); ?></span>
					</fieldset>
					<label class="mdvrm-field" for="mdvrm-blacklist">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Blacklisted paths', 'mudrava-rum' ); ?></span>
						<textarea id="mdvrm-blacklist" name="blacklist" rows="4" class="large-text code" placeholder="/cart&#10;/my-account"><?php echo esc_textarea( implode( "\n", $settings['blacklist'] ) ); ?></textarea>
						<span class="mdvrm-field__desc"><?php esc_html_e( 'URL path prefixes to ignore, one per line.', 'mudrava-rum' ); ?></span>
					</label>
				</div>

				<div class="card mdvrm-card">
					<h2><?php esc_html_e( 'Email reports', 'mudrava-rum' ); ?></h2>
					<label class="mdvrm-field" for="mdvrm-report">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Schedule', 'mudrava-rum' ); ?></span>
						<select id="mdvrm-report" name="report_schedule">
							<option value="daily" <?php selected( 'daily', $settings['report_schedule'] ); ?>><?php esc_html_e( 'Daily', 'mudrava-rum' ); ?></option>
							<option value="weekly" <?php selected( 'weekly', $settings['report_schedule'] ); ?>><?php esc_html_e( 'Weekly', 'mudrava-rum' ); ?></option>
						</select>
					</label>
					<div class="mdvrm-field">
						<label class="mdvrm-field__label" for="mdvrm-recipient"><?php esc_html_e( 'Recipient', 'mudrava-rum' ); ?></label>
						<div class="mdvrm-field__row">
							<input id="mdvrm-recipient" name="alert_recipient" type="email" value="<?php echo esc_attr( $settings['alert_recipient'] ); ?>" />
							<button type="button" class="button button-secondary" id="mdvrm-send-test-email"><?php esc_html_e( 'Send test now', 'mudrava-rum' ); ?></button>
						</div>
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Where summaries and alerts are sent.', 'mudrava-rum' ); ?></span>
					</div>
				</div>

				<div class="card mdvrm-card">
					<h2><?php esc_html_e( 'Critical alerts', 'mudrava-rum' ); ?></h2>
					<label class="mdvrm-field" for="mdvrm-ttfb">
						<span class="mdvrm-field__label"><?php esc_html_e( 'TTFB threshold (seconds)', 'mudrava-rum' ); ?></span>
						<input id="mdvrm-ttfb" name="alert_ttfb_threshold" type="number" step="0.1" min="0" value="<?php echo esc_attr( $settings['alert_ttfb_threshold'] ); ?>" />
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Alert when server response exceeds this value.', 'mudrava-rum' ); ?></span>
					</label>
					<label class="mdvrm-field" for="mdvrm-consecutive">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Consecutive slow requests', 'mudrava-rum' ); ?></span>
						<input id="mdvrm-consecutive" name="alert_consecutive" type="number" min="1" value="<?php echo esc_attr( $settings['alert_consecutive'] ); ?>" />
						<span class="mdvrm-field__desc"><?php esc_html_e( 'How many slow pageviews in a row trigger the alert.', 'mudrava-rum' ); ?></span>
					</label>
					<label class="mdvrm-field" for="mdvrm-cooldown">
						<span class="mdvrm-field__label"><?php esc_html_e( 'Cooldown (seconds)', 'mudrava-rum' ); ?></span>
						<input id="mdvrm-cooldown" name="alert_min_interval" type="number" min="300" step="60" value="<?php echo esc_attr( $settings['alert_min_interval'] ); ?>" />
						<span class="mdvrm-field__desc"><?php esc_html_e( 'Minimum gap between alert emails.', 'mudrava-rum' ); ?></span>
					</label>
				</div>

				<p class="submit mdvrm-submit">
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
			<div class="mdvrm-footer__info">
				<a class="mdvrm-footer__logo-link" href="https://mudrava.com/en/" target="_blank" rel="noopener" aria-label="MUDRAVA"><?php $this->render_logo( 'mdvrm-footer__logo' ); ?></a>
				<span class="mdvrm-footer__copy">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> MUDRAVA</span>
				<span class="mdvrm-footer__sep">&middot;</span>
				<a class="mdvrm-footer__link" href="mailto:support@mudrava.com">support@mudrava.com</a>
			</div>
		</div>
		<?php
	}
}
