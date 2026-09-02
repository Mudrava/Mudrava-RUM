<?php
/**
 * Frontend collector enqueuer.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend collector enqueuer class.
 */
class MDVRM_Collector {

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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'print_settings' ), 1 );
	}

	/**
	 * Enqueue collector script on frontend.
	 */
	public function enqueue(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->plugin->should_track_request() ) {
			return;
		}

		$handle = 'mdvrm-collector';
		$src    = 'assets/js/mdvrm-collector.min.js';
		if ( ! file_exists( MDVRM_PLUGIN_DIR . $src ) ) {
			$src = 'assets/js/mdvrm-collector.js';
		}

		wp_register_script(
			$handle,
			MDVRM_PLUGIN_URL . $src,
			array(),
			(string) ( file_exists( MDVRM_PLUGIN_DIR . $src ) ? filemtime( MDVRM_PLUGIN_DIR . $src ) : MDVRM_VERSION ),
			true
		);

		wp_enqueue_script( $handle );
	}

	/**
	 * Inject collector settings as inline script to capture server time.
	 */
	public function print_settings(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! $this->plugin->should_track_request() ) {
			return;
		}

		$context = $this->plugin->get_server_context();

		$localize = array(
			'restUrl'    => esc_url_raw( rest_url( 'mudrava-rum/v1/collect' ) ),
			'nonce'      => wp_create_nonce( 'mdvrm_collect' ),
			'server'     => array(
				'time'       => $context['serverTime'],
				'memoryPeak' => $context['memoryPeak'],
				'country'    => $context['country'],
			),
			'sessionKey' => 'mdvrm_session_id',
		);

		/**
		 * Filter collector settings passed to the frontend JS.
		 *
		 * @param array $localize Collector settings.
		 */
		$localize = apply_filters( 'mdvrm_collector_settings', $localize );

		wp_add_inline_script(
			'mdvrm-collector',
			'var MDVRMCollectorSettings = ' . wp_json_encode( $localize ) . ';',
			'before'
		);
	}
}
