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
	 * Maximum ingest requests per minute per client.
	 */
	const RATE_LIMIT = 60;

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
					'days'       => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 365,
						'sanitize_callback' => 'absint',
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
					'days'       => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'maximum'           => 365,
						'sanitize_callback' => 'absint',
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
	 * Uses header 'X-MDVRM-Nonce' or the mdvrm_token field of the JSON body
	 * (sendBeacon fallback). Restores the logged-in user from cookies before
	 * verification, because the WP REST API resets the user to 0 when cookies
	 * are sent without a standard _wpnonce header.
	 *
	 * @param WP_REST_Request|null $request Optional request for body-token fallback.
	 * @return bool
	 */
	public function verify_custom_nonce( $request = null ): bool {
		$nonce = null;

		if ( isset( $_SERVER['HTTP_X_MDVRM_NONCE'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_MDVRM_NONCE'] ) );
		} elseif ( $request instanceof WP_REST_Request ) {
			$params = $request->get_json_params();
			if ( is_array( $params ) && isset( $params['mdvrm_token'] ) && is_string( $params['mdvrm_token'] ) ) {
				$nonce = sanitize_text_field( $params['mdvrm_token'] );
			}
		}

		if ( ! $nonce ) {
			return false;
		}

		if ( 0 === get_current_user_id() && isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			// Restoring an expired cookie must not trip password-reset side effects.
			$hooks = array();
			foreach ( array( 'wp_login_failed', 'authenticate' ) as $hook ) {
				$hooks[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : null;
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Temporarily detach core hooks.
				$GLOBALS['wp_filter'][ $hook ] = new WP_Hook();
			}
			$raw_cookie     = isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) : '';
			$cookie_user_id = wp_validate_auth_cookie( $raw_cookie, 'logged_in' );
			foreach ( $hooks as $hook => $original ) {
				if ( null === $original ) {
					unset( $GLOBALS['wp_filter'][ $hook ] );
				} else {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore core hooks.
					$GLOBALS['wp_filter'][ $hook ] = $original;
				}
			}
			if ( $cookie_user_id ) {
				wp_set_current_user( $cookie_user_id );
			}
		}

		return (bool) wp_verify_nonce( $nonce, 'mdvrm_collect' );
	}

	/**
	 * Rate limit ingest requests per client.
	 *
	 * The client identifier is only used as a salted HMAC for throttling and
	 * is never stored in plain text. When no valid IP is available the bucket
	 * falls back to the session id, so clients behind a shared proxy do not
	 * exhaust one global bucket.
	 *
	 * @param string $scope Session id fallback scope when the IP is missing.
	 * @return bool True when the request is allowed.
	 */
	protected function rate_limited( string $scope = '' ): bool {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';

		if ( empty( $ip ) && 1 === (int) $this->plugin->settings()->get( 'trust_cf' ) && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				$ip = $cf;
			}
		}

		if ( empty( $ip ) && 1 === (int) $this->plugin->settings()->get( 'trust_auth_header' ) && isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$first = trim( explode( ',', $xff )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				$ip = $first;
			}
		}

		$identity = $ip ? $ip : ( $scope ? 'sess:' . $scope : 'unknown' );
		$key      = 'mdvrm_rl_' . hash_hmac( 'sha256', $identity . ':' . gmdate( 'YmdHi' ), wp_salt( 'auth' ) );
		$count    = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * Collector ingestion endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function collect( WP_REST_Request $request ) {
		if ( ! $this->verify_custom_nonce( $request ) ) {
			return new WP_Error( 'mdvrm_forbidden', __( 'Invalid security token', 'mudrava-rum' ), array( 'status' => 403 ) );
		}

		$body = $request->get_body();
		if ( '' === $body ) {
			return new WP_Error( 'mdvrm_empty', __( 'Empty payload', 'mudrava-rum' ), array( 'status' => 400 ) );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'mdvrm_json_error', __( 'Invalid JSON', 'mudrava-rum' ), array( 'status' => 400 ) );
		}

		$session_scope = isset( $payload['session_id'] ) && is_string( $payload['session_id'] )
			? substr( preg_replace( '/[^a-zA-Z0-9\-]/', '', $payload['session_id'] ), 0, 64 )
			: '';

		if ( $this->rate_limited( $session_scope ) ) {
			return new WP_Error( 'mdvrm_rate_limited', __( 'Too many requests', 'mudrava-rum' ), array( 'status' => 429 ) );
		}

		$metrics = array(
			'ttfb'        => $this->read_metric( $payload, 'ttfb' ),
			'lcp'         => $this->read_metric( $payload, 'lcp' ),
			'total_load'  => $this->read_metric( $payload, 'total_load' ),
			'server_time' => $this->read_metric( $payload, 'server_time' ),
		);

		if ( $metrics['total_load'] <= 0 ) {
			return new WP_Error( 'mdvrm_invalid', __( 'Missing page load metric', 'mudrava-rum' ), array( 'status' => 400 ) );
		}

		$row = array(
			'event_time'  => gmdate( 'Y-m-d H:i:s' ),
			'url'         => $this->sanitize_page_url( $this->read_string( $payload, 'url', 2048 ) ),
			'server_time' => $metrics['server_time'],
			'ttfb'        => $metrics['ttfb'],
			'lcp'         => $metrics['lcp'],
			'total_load'  => $metrics['total_load'],
			'memory_peak' => isset( $payload['memory_peak'] ) ? absint( $payload['memory_peak'] ) : 0,
			'device'      => $this->read_string( $payload, 'device', 10 ),
			'net'         => $this->read_string( $payload, 'net', 20 ),
			'country'     => $this->read_country( $payload ),
			'session_id'  => $this->read_string( $payload, 'session_id', 64 ),
			'user_role'   => $this->get_user_role(),
			'meta'        => array(),
		);

		$inserted = MDVRM_DB::insert( $row );

		if ( ! $inserted ) {
			return new WP_Error( 'mdvrm_db_error', __( 'Storage error', 'mudrava-rum' ), array( 'status' => 500 ) );
		}

		if ( $metrics['ttfb'] > 0 ) {
			$this->plugin->reports()->track_ttfb_sample( $metrics['ttfb'] );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 201 );
	}

	/**
	 * Read and sanitize a numeric metric from payload.
	 *
	 * @param array  $payload Payload.
	 * @param string $key Key.
	 * @return float
	 */
	protected function read_metric( array $payload, string $key ): float {
		if ( ! isset( $payload[ $key ] ) || ! is_numeric( $payload[ $key ] ) ) {
			return 0.0;
		}
		return floatval( $payload[ $key ] );
	}

	/**
	 * Read and sanitize a string field from payload.
	 *
	 * @param array  $payload Payload.
	 * @param string $key Key.
	 * @param int    $max Maximum length.
	 * @return string
	 */
	protected function read_string( array $payload, string $key, int $max ): string {
		if ( ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) ) {
			return '';
		}
		return substr( sanitize_text_field( $payload[ $key ] ), 0, $max );
	}

	/**
	 * Read a strict 2-letter ISO country code from payload.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	protected function read_country( array $payload ): string {
		if ( ! isset( $payload['country'] ) || ! is_string( $payload['country'] ) ) {
			return '';
		}
		$country = strtoupper( sanitize_text_field( $payload['country'] ) );
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * Keep only scheme, host and path of a page URL; drop query and fragment
	 * so tokens or personal data in query strings never reach the database.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected function sanitize_page_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		return $scheme . '://' . $parts['host'] . $path;
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
		$data['trend'] = MDVRM_DB::get_trend( $filter_params );

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * Trigger email report manually.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function send_report( WP_REST_Request $request ): WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API.
		$sent = $this->plugin->reports()->send_report( true );
		if ( $sent ) {
			return new WP_REST_Response( array( 'status' => 'sent' ), 200 );
		}
		return new WP_REST_Response(
			array(
				'status'  => 'failed',
				'message' => __( 'Check mail settings or recipient', 'mudrava-rum' ),
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

		$keys = array( 'page', 'per_page', 'order', 'order_by', 'days', 'session_id', 'url', 'device', 'net' );
		foreach ( $keys as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val && '' !== $val && 0 !== $val ) {
				$filter_params[ $key ] = $val;
			}
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
