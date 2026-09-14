<?php
/**
 * Shared helpers for wp eval-file integration suites.
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['mdvrm_mail'] = array();
$GLOBALS['mdvrm_fail'] = 0;

add_filter(
	'wp_mail',
	function ( $args ) {
		$GLOBALS['mdvrm_mail'][] = $args;
		return $args;
	}
);

add_action(
	'wp_mail_failed',
	function ( $wp_error ) {
		WP_CLI::line( 'MAILFAIL: ' . $wp_error->get_error_message() );
	}
);

add_action(
	'phpmailer_init',
	function ( $mailer ) {
		$sendmail = file_exists( '/bin/true' ) ? '/bin/true' : '/usr/bin/true';
		$mailer->Mailer   = 'sendmail';
		$mailer->Sendmail = $sendmail;
	}
);

add_filter(
	'wp_mail_from',
	function ( $email ) {
		return is_email( $email ) ? $email : 'wordpress@example.test';
	}
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		WP_CLI::line( 'NOTE: outbound http blocked: ' . $url );
		return is_wp_error( $pre ) ? $pre : array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 200 ),
			'cookies'  => array(),
		);
	},
	10,
	3
);

/**
 * Assert.
 *
 * @param string $label Label.
 * @param bool   $cond Condition.
 */
function mdvrm_assert( $label, $cond ) {
	if ( $cond ) {
		WP_CLI::log( 'PASS ' . $label );
	} else {
		WP_CLI::line( 'FAIL ' . $label );
		$GLOBALS['mdvrm_fail']++;
	}
}

/**
 * Finish suite with exit code.
 */
function mdvrm_finish() {
	if ( $GLOBALS['mdvrm_fail'] > 0 ) {
		WP_CLI::halt( 1 );
	}
	WP_CLI::success( 'suite ok' );
}

/**
 * Alias.
 */
function mdvrm_summary() {
	mdvrm_finish();
}

/**
 * Installed on load.
 */
function mdvrm_mail_intercept() {}

/**
 * Installed on load.
 */
function mdvrm_block_http() {}

/**
 * Clean slate.
 */
function mdvrm_reset() {
	global $wpdb;
	$wpdb->query( 'TRUNCATE TABLE ' . MDVRM_TABLE ); // phpcs:ignore WordPress.DB
	if ( class_exists( 'MDVRM_DB' ) ) {
		MDVRM_DB::bump_stats_cache();
	}
	if ( class_exists( 'MDVRM_Plugin' ) ) {
		MDVRM_Plugin::instance()->reset_request_state();
	}
	delete_option( 'mdvrm_settings' );
	delete_option( 'mdvrm_ttfb_streak' );
	delete_option( 'mdvrm_last_alert_ts' );
	delete_option( 'mdvrm_last_report_ts' );
	delete_option( 'mdvrm_version' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mdvrm\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_mdvrm\\_rl\\_%'" ); // phpcs:ignore WordPress.DB
	$GLOBALS['mdvrm_mail'] = array();
}

/**
 * Patch settings.
 *
 * @param array $patch Patch.
 */
function mdvrm_settings( array $patch ) {
	$plugin = MDVRM_Plugin::instance();
	$plugin->settings()->update( $patch );
	$plugin->reset_request_state();
}

/**
 * Alias.
 *
 * @param array $patch Patch.
 */
function mdvrm_patch_settings( array $patch ) {
	mdvrm_settings( $patch );
}

/**
 * Register routes.
 */
function mdvrm_routes() {
	do_action( 'rest_api_init' );
}

/**
 * In-process REST call.
 *
 * @param string $method Method.
 * @param string $route Route.
 * @param array  $params Params (JSON body for POST).
 * @return array
 */
function mdvrm_request( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
	} elseif ( $params ) {
		$request->set_body( wp_json_encode( $params ) );
	}
	$response = rest_do_request( $request );
	if ( $response instanceof WP_Error ) {
		$data   = (array) $response->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 500;
		return array(
			'status' => $status,
			'code'   => $response->get_error_code(),
			'data'   => null,
		);
	}
	$data   = $response->get_data();
	$code   = ( is_array( $data ) && isset( $data['code'] ) ) ? $data['code'] : null;
	return array(
		'status' => (int) $response->get_status(),
		'code'   => $code,
		'data'   => $data,
	);
}

/**
 * Collect endpoint call.
 *
 * @param array       $payload Body payload.
 * @param string|null $nonce   Nonce or null.
 * @return array
 */
function mdvrm_collect( array $payload, $nonce = null ) {
	if ( null !== $nonce ) {
		$_SERVER['HTTP_X_MDVRM_NONCE'] = $nonce;
	} else {
		unset( $_SERVER['HTTP_X_MDVRM_NONCE'] );
	}
	$request = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
	$request->set_body( wp_json_encode( $payload ) );
	$response = rest_do_request( $request );
	if ( $response instanceof WP_Error ) {
		$data   = (array) $response->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 500;
		return array(
			'status' => $status,
			'code'   => $response->get_error_code(),
			'data'   => null,
		);
	}
	$data   = $response->get_data();
	$code   = ( is_array( $data ) && isset( $data['code'] ) ) ? $data['code'] : null;
	return array(
		'status' => (int) $response->get_status(),
		'code'   => $code,
		'data'   => $data,
	);
}

/**
 * In-process GET.
 *
 * @param string $route  Route.
 * @param array  $params Query params.
 * @return array
 */
function mdvrm_get( $route, array $params = array() ) {
	return mdvrm_request( 'GET', $route, $params );
}

/**
 * In-process POST without body.
 *
 * @param string $route Route.
 * @return array
 */
function mdvrm_post( $route ) {
	return mdvrm_request( 'POST', $route );
}

/**
 * Last stored log row.
 *
 * @return array|null
 */
function mdvrm_last_row() {
	global $wpdb;
	$row = $wpdb->get_row( 'SELECT * FROM ' . MDVRM_TABLE . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB
	return is_array( $row ) ? $row : null;
}
