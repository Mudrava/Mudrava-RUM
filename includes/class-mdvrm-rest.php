<?php
/**
 * REST API endpoints for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API endpoints class for Mudrava RUM.
 */
class MDVRM_REST {

	/**
	 * REST namespace.
	 */
	const NS = 'mudrava-rum/v1';

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
	 * Hook routes.
	 */
	public function hook(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/collect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'event_time'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'         => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'server_time' => array(
						'type' => 'number',
					),
					'ttfb'        => array(
						'type' => 'number',
					),
					'lcp'         => array(
						'type' => 'number',
					),
					'total_load'  => array(
						'type' => 'number',
					),
					'memory_peak' => array(
						'type' => 'integer',
					),
					'device'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'net'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'country'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'session_id'  => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page'       => array(
						'default'           => 1,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'per_page'   => array(
						'default'           => 50,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'order'      => array(
						'default' => 'desc',
						'type'    => 'string',
						'enum'    => array( 'asc', 'desc' ),
					),
					'order_by'   => array(
						'default' => 'event_time',
						'type'    => 'string',
						'enum'    => array( 'event_time', 'ttfb', 'lcp', 'total_load', 'server_time' ),
					),
					'session_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'device'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'net'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'session_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'url'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'device'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'net'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/send-report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_report' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(),
			)
		);
	}

	/**
	 * Nonce verification for public collector.
	 *
	 * Uses Header 'X-MDVRM-Nonce' or query param 'mdvrm_token'.
	 * Avoids '_wpnonce' to bypass Core's premature cookie auth checks.
	 * Restores the logged-in user from cookies before verification,
	 * because WP REST API resets user to 0 when cookies are sent
	 * without a standard _wpnonce header.
	 *
	 * @return bool
	 */
	public function verify_custom_nonce(): bool {
		$nonce = null;

		if ( isset( $_SERVER['HTTP_X_MDVRM_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MDVRM_NONCE'] ) );
		} elseif ( isset( $_GET['mdvrm_token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the nonce check.
			$nonce = sanitize_text_field( wp_unslash( $_GET['mdvrm_token'] ) );
		}

		if ( ! $nonce ) {
			return false;
		}

		// Restore logged-in user context from cookie when WP REST API
		// has reset the user to anonymous (no _wpnonce sent).
		if ( 0 === get_current_user_id() && function_exists( 'wp_validate_auth_cookie' ) ) {
			$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $cookie_user_id ) {
				wp_set_current_user( $cookie_user_id );
			}
		}

		return (bool) wp_verify_nonce( $nonce, 'mdvrm_collect' );
	}

	/**
	 * Collector ingestion endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function collect( WP_REST_Request $request ) {
		// Validation of custom header manual to avoid core rest_cookie_invalid_nonce interference.
		if ( ! $this->verify_custom_nonce() ) {
			return new WP_Error( 'mdvrm_forbidden', __( 'Invalid security token', 'mudrava-rum' ), array( 'status' => 403 ) );
		}

		if ( ! $this->plugin->should_track_request() ) {
			return new WP_REST_Response( array( 'status' => 'skipped' ), 202 );
		}

		$body = $request->get_body();
		if ( empty( $body ) ) {
			return new WP_Error( 'mdvrm_empty', __( 'Empty payload', 'mudrava-rum' ), array( 'status' => 400 ) );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mdvrm_json_error', __( 'Invalid JSON', 'mudrava-rum' ), array( 'status' => 400 ) );
		}

		$row = array(
			'event_time'  => isset( $payload['event_time'] ) ? sanitize_text_field( $payload['event_time'] ) : current_time( 'mysql', true ),
			'url'         => isset( $payload['url'] ) ? sanitize_text_field( $payload['url'] ) : '',
			'server_time' => isset( $payload['server_time'] ) ? floatval( $payload['server_time'] ) : 0,
			'ttfb'        => isset( $payload['ttfb'] ) ? floatval( $payload['ttfb'] ) : 0,
			'lcp'         => isset( $payload['lcp'] ) ? floatval( $payload['lcp'] ) : 0,
			'total_load'  => isset( $payload['total_load'] ) ? floatval( $payload['total_load'] ) : 0,
			'memory_peak' => isset( $payload['memory_peak'] ) ? absint( $payload['memory_peak'] ) : 0,
			'device'      => isset( $payload['device'] ) ? sanitize_text_field( $payload['device'] ) : '',
			'net'         => isset( $payload['net'] ) ? sanitize_text_field( $payload['net'] ) : '',
			'country'     => isset( $payload['country'] ) ? sanitize_text_field( $payload['country'] ) : '',
			'session_id'  => isset( $payload['session_id'] ) ? sanitize_text_field( $payload['session_id'] ) : '',
			'user_role'   => $this->get_user_role(),
			'meta'        => array(),
		);

		MDVRM_DB::insert( $row );

		return new WP_REST_Response( array( 'status' => 'ok' ), 201 );
	}

	/**
	 * Admin logs endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function logs( WP_REST_Request $request ): WP_REST_Response {
		$filter_params = $this->get_filter_params( $request );
		$data          = MDVRM_DB::query_logs( $filter_params );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Admin stats endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function stats( WP_REST_Request $request ): WP_REST_Response {
		$filter_params = $this->get_filter_params( $request );
		$data          = MDVRM_DB::get_stats( $filter_params );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Trigger email report manually.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function send_report( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by REST API.
		$sent = $this->plugin->reports()->send_report();
		if ( $sent ) {
			return new WP_REST_Response( array( 'status' => 'sent' ), 200 );
		}
		return new WP_REST_Response(
			array(
				'status'  => 'failed',
				'message' => 'Check mail settings or recipient',
			),
			500
		);
	}

	/**
	 * Helper to extract filters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	protected function get_filter_params( WP_REST_Request $request ): array {
		$filter_params = array();

		$keys = array( 'page', 'per_page', 'order', 'order_by', 'session_id', 'url', 'device', 'net' );
		foreach ( $keys as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val ) {
				$filter_params[ $key ] = $val;
			}
		}

		if ( isset( $filter_params['url'] ) && '' === $filter_params['url'] ) {
			unset( $filter_params['url'] );
		}
		if ( isset( $filter_params['device'] ) && '' === $filter_params['device'] ) {
			unset( $filter_params['device'] );
		}
		if ( isset( $filter_params['net'] ) && '' === $filter_params['net'] ) {
			unset( $filter_params['net'] );
		}

		return $filter_params;
	}

	/**
	 * Resolve current user role for logging.
	 *
	 * @return string
	 */
	protected function get_user_role(): string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user = wp_get_current_user();
		return isset( $user->roles[0] ) ? sanitize_text_field( $user->roles[0] ) : '';
	}
}
