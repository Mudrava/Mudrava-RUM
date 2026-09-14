<?php
/**
 * Ingestion endpoint and REST boundary tests.
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


require_once __DIR__ . '/helpers.php';

mdvrm_block_http();
mdvrm_reset();
mdvrm_routes();

$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
$nonce                  = wp_create_nonce( 'mdvrm_collect' );

$r = mdvrm_collect( array( 'url' => 'https://e.com/', 'total_load' => 1 ), null );
mdvrm_assert( 'missing nonce -> 403', 403 === $r['status'] && 'mdvrm_forbidden' === $r['code'] );

$r = mdvrm_collect( array( 'url' => 'https://e.com/', 'total_load' => 1 ), 'deadbeefdead' );
mdvrm_assert( 'bogus nonce -> 403', 403 === $r['status'] && 'mdvrm_forbidden' === $r['code'] );

$r = mdvrm_collect(
	array(
		'url'         => 'https://e.com:8443/post-1/?utm_source=x#frag',
		'ttfb'        => 0.5123,
		'lcp'         => 2.1234,
		'server_time' => 0.1555,
		'total_load'  => 3.9999,
		'memory_peak' => 4194304,
		'device'      => 'mobile',
		'net'         => '4g',
		'country'     => 'nl',
		'session_id'  => 'sess-alpha',
	),
	$nonce
);
mdvrm_assert( 'valid payload -> 201', 201 === $r['status'] && 'ok' === $r['data']['status'] );

$row = mdvrm_last_row();
mdvrm_assert( 'url stored without query and fragment', 'https://e.com:8443/post-1/' === $row['url'] );
mdvrm_assert( 'url port is preserved', false !== strpos( $row['url'], 'e.com:8443' ) );
mdvrm_assert( 'ttfb rounded 4dp', '0.5123' === sprintf( '%.4f', (float) $row['ttfb'] ) );
mdvrm_assert( 'lcp stored', abs( $row['lcp'] - 2.1234 ) < 0.0002 );
mdvrm_assert( 'total_load stored', abs( $row['total_load'] - 3.9999 ) < 0.0002 );
mdvrm_assert( 'server_time stored', abs( $row['server_time'] - 0.1555 ) < 0.0002 );
mdvrm_assert( 'device stored', 'mobile' === $row['device'] );
mdvrm_assert( 'net stored', '4g' === $row['net'] );
mdvrm_assert( 'country uppercased', 'NL' === $row['country'] );
mdvrm_assert( 'session_id stored', 'sess-alpha' === $row['session_id'] );
mdvrm_assert( 'event_time is server UTC datetime', (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['event_time'] ) );
mdvrm_assert( 'meta encoded json object', '{}' === $row['meta'] );

$r = mdvrm_collect(
	array(
		'url'         => 'javascript:alert(1)',
		'total_load'  => 2.0,
		'ttfb'        => 'NaN',
		'lcp'         => -5,
		'server_time' => 'INF',
		'memory_peak' => 999999999999,
		'device'      => 'smarttv',
		'net'         => 'carrier>weird',
		'country'     => 'ZZZ',
		'session_id'  => "sess'\"<>fallback",
		'event_time'  => '1999-99-99 nonsense',
		'unknown_key' => 'ignored',
	),
	$nonce
);
mdvrm_assert( 'hostile payload -> 201', 201 === $r['status'] );
$row = mdvrm_last_row();
mdvrm_assert( 'javascript url blanked', '' === $row['url'] );
mdvrm_assert( 'over-cap ttfb zeroed', abs( $row['ttfb'] ) < 0.0001 );
mdvrm_assert( 'negative lcp zeroed', abs( $row['lcp'] ) < 0.0001 );
mdvrm_assert( 'valid total_load stored with hostile peers', abs( $row['total_load'] - 2.0 ) < 0.0001 );
mdvrm_assert( 'non-finite server_time zeroed', abs( $row['server_time'] ) < 0.0001 );
mdvrm_assert( 'memory_peak capped', (int) $row['memory_peak'] <= MDVRM_REST::MAX_MEMORY_BYTES );
mdvrm_assert( 'invalid device blanked', '' === $row['device'] );
mdvrm_assert( 'invalid net blanked', '' === $row['net'] );
mdvrm_assert( 'invalid country blanked', '' === $row['country'] );
mdvrm_assert( 'session sanitized charset', 'sessfallback' === $row['session_id'] );
mdvrm_assert( 'client event_time replaced by server time', false === strpos( $row['event_time'], '1999' ) );

$r = mdvrm_collect( array( 'url' => 'https://e.com/' ), $nonce );
mdvrm_assert( 'missing total_load -> 400', 400 === $r['status'] && 'mdvrm_invalid' === $r['code'] );

$r = mdvrm_collect( array( 'url' => 'https://e.com/', 'total_load' => 'abc' ), $nonce );
mdvrm_assert( 'non-numeric total_load -> 400', 400 === $r['status'] );

$_SERVER['HTTP_X_MDVRM_NONCE'] = $nonce;
$empty                        = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$empty->set_body( '' );
$res = rest_do_request( $empty );
mdvrm_assert( 'empty body -> 400', 400 === (int) $res->get_status() );

$notjson = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$notjson->set_body( 'not json at all' );
$res = rest_do_request( $notjson );
$data = $res->get_data();
mdvrm_assert( 'invalid json -> 400', 400 === (int) $res->get_status() && isset( $data['code'] ) && 'mdvrm_json_error' === $data['code'] );

$r = mdvrm_collect( array( 'url' => 'https://e.com/long', 'total_load' => str_repeat( '9', 700 ) . '.0' ), $nonce );
mdvrm_assert( 'non-finite huge total_load -> 400', 400 === $r['status'] );

$key = 'mdvrm_rl_' . hash_hmac( 'sha256', '198.51.100.10:' . gmdate( 'YmdHi' ), wp_salt( 'auth' ) );
delete_transient( $key );
set_transient( $key, 60, 120 );
$r = mdvrm_collect( array( 'url' => 'https://e.com/rl/', 'total_load' => 1 ), $nonce );
mdvrm_assert( 'rate limiter engages at 60/min', 429 === $r['status'] );
delete_transient( $key );

// --- Cookie restore must not trip wp_login_failed.
$failed = 0;
add_action(
	'wp_login_failed',
	function () use ( &$failed ) {
		$failed++;
	}
);

$uid   = 1;
$admin = get_userdata( $uid );
$remote = '127.0.0.1';

wp_set_current_user( $uid );
$logged_cookie = wp_generate_auth_cookie( $uid, time() + DAY_IN_SECONDS, 'logged_in' );
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_cookie;
$admin_nonce                 = wp_create_nonce( 'mdvrm_collect' );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_set_current_user( 0 );
$anon_nonce = wp_create_nonce( 'mdvrm_collect' );

// An anonymous token cannot authenticate an existing logged-in cookie.
$_SERVER['REMOTE_ADDR']         = $remote;
$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';
$_COOKIE[ LOGGED_IN_COOKIE ]     = $logged_cookie;
$r = mdvrm_collect(
	array(
		'url'        => 'https://e.com/ck-invalid/',
		'total_load' => 1,
		'session_id' => 'ck-invalid-' . wp_rand(),
	),
	$anon_nonce
);
mdvrm_assert( 'anonymous token cannot authenticate cookie', 403 === $r['status'] && 'mdvrm_forbidden' === $r['code'] );
mdvrm_assert( 'invalid restore does not fire wp_login_failed', 0 === $failed );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_set_current_user( 0 );

// A matching logged-in token restores the user without logout side effects.
$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_cookie;
$r = mdvrm_collect(
	array(
		'url'        => 'https://e.com/ck-valid/',
		'total_load' => 1,
		'session_id' => 'ck-valid-' . wp_rand(),
	),
	$admin_nonce
);
mdvrm_assert( 'valid cookie response accepted', 201 === $r['status'] );
mdvrm_assert( 'valid cookie restores user', $admin->ID === get_current_user_id() );
mdvrm_assert( 'valid cookie does not fire wp_login_failed', 0 === $failed );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
wp_set_current_user( 0 );

// --- Forwarded client IPs require a local/configured proxy and valid option.
$minute   = gmdate( 'YmdHi' );
$cf_valid = '198.51.100.77';
$_SERVER['REMOTE_ADDR']           = '203.0.113.50';
$_SERVER['HTTP_CF_CONNECTING_IP'] = $cf_valid;
$cf = mdvrm_collect(
	array(
		'url'        => 'https://e.com/cf-off/',
		'total_load' => 1,
		'session_id' => 'cf-off-' . wp_rand(),
	),
	$anon_nonce
);
mdvrm_assert( 'CF header ignored when option off', 201 === $cf['status'] );
$key_public = 'mdvrm_rl_' . hash_hmac( 'sha256', '203.0.113.50:' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'untrusted peer does not use CF header', (int) get_transient( $key_public ) >= 1 );
$key_cf = 'mdvrm_rl_' . hash_hmac( 'sha256', $cf_valid . ':' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'CF header not counted when option off', 0 === (int) get_transient( $key_cf ) );
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

$plugin = MDVRM_Plugin::instance();
$plugin->settings()->update( array( 'trust_cf' => 1 ) );
$_SERVER['REMOTE_ADDR']           = '127.0.0.1';
$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
$sess_cf  = 'cf-fb-' . wp_rand();
$key_sess = 'mdvrm_rl_' . hash_hmac( 'sha256', 'sess:' . $sess_cf . ':' . $minute, wp_salt( 'auth' ) );
delete_transient( $key_sess );
$cf = mdvrm_collect(
	array(
		'url'        => 'https://e.com/cf-invalid/',
		'total_load' => 1,
		'session_id' => $sess_cf,
	),
	$anon_nonce
);
$minute = gmdate( 'YmdHi' );
$key_sess = 'mdvrm_rl_' . hash_hmac( 'sha256', 'sess:' . $sess_cf . ':' . $minute, wp_salt( 'auth' ) );
$key_127  = 'mdvrm_rl_' . hash_hmac( 'sha256', '127.0.0.1:' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'invalid CF falls back to session bucket', 201 === $cf['status'] );
mdvrm_assert( 'session scope bucket counted', (int) get_transient( $key_sess ) >= 1 );
delete_transient( $key_sess );

$_SERVER['HTTP_CF_CONNECTING_IP'] = $cf_valid;
$key_cf = 'mdvrm_rl_' . hash_hmac( 'sha256', $cf_valid . ':' . $minute, wp_salt( 'auth' ) );
delete_transient( $key_cf );
$cf = mdvrm_collect(
	array(
		'url'        => 'https://e.com/cf-valid/',
		'total_load' => 1,
		'session_id' => 'cf-' . wp_rand(),
	),
	$anon_nonce
);
$minute = gmdate( 'YmdHi' );
$key_cf = 'mdvrm_rl_' . hash_hmac( 'sha256', $cf_valid . ':' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'valid CF accepted from local proxy', 201 === $cf['status'] );
mdvrm_assert( 'CF header supplied peer identity', (int) get_transient( $key_cf ) >= 1 );
delete_transient( $key_cf );
unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
$plugin->settings()->update( array( 'trust_cf' => 0 ) );

// --- X-Forwarded-For requires a local or configured proxy.
$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 10.0.0.1';
$r = mdvrm_collect(
	array(
		'url'        => 'https://e.com/xff-off/',
		'total_load' => 1,
		'session_id' => 'xff-' . wp_rand(),
	),
	$anon_nonce
);
$minute = gmdate( 'YmdHi' );
$key_127 = 'mdvrm_rl_' . hash_hmac( 'sha256', '127.0.0.1:' . $minute, wp_salt( 'auth' ) );
$key_xff = 'mdvrm_rl_' . hash_hmac( 'sha256', '198.51.100.99:' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'XFF ignored when option off', 201 === $r['status'] );
mdvrm_assert( 'local peer bucket used when XFF disabled', (int) get_transient( $key_127 ) >= 1 );
mdvrm_assert( 'XFF option off does not use header identity', 0 === (int) get_transient( $key_xff ) );

$plugin->settings()->update( array( 'trust_auth_header' => 1, 'trust_proxies' => array() ) );
delete_transient( $key_xff );
$r = mdvrm_collect(
	array(
		'url'        => 'https://e.com/xff-local/',
		'total_load' => 1,
		'session_id' => 'xff-' . wp_rand(),
	),
	$anon_nonce
);
$minute = gmdate( 'YmdHi' );
$key_xff = 'mdvrm_rl_' . hash_hmac( 'sha256', '198.51.100.99:' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'XFF accepted from local proxy', 201 === $r['status'] );
mdvrm_assert( 'XFF identity supplied to limiter', (int) get_transient( $key_xff ) >= 1 );
delete_transient( $key_xff );

$plugin->settings()->update( array( 'trust_proxies' => array( '203.0.113.20' ) ) );
$_SERVER['REMOTE_ADDR']             = '203.0.113.20';
$_SERVER['HTTP_X_FORWARDED_FOR']    = '203.0.113.9, 198.51.100.10';
$key_config                         = 'mdvrm_rl_' . hash_hmac( 'sha256', '203.0.113.9:' . gmdate( 'YmdHi' ), wp_salt( 'auth' ) );
delete_transient( $key_config );
$r = mdvrm_collect(
	array(
		'url'        => 'https://e.com/xff-configured/',
		'total_load' => 1,
		'session_id' => 'xff-' . wp_rand(),
	),
	$anon_nonce
);
$minute     = gmdate( 'YmdHi' );
$key_config = 'mdvrm_rl_' . hash_hmac( 'sha256', '203.0.113.9:' . $minute, wp_salt( 'auth' ) );
$key_public = 'mdvrm_rl_' . hash_hmac( 'sha256', '203.0.113.20:' . $minute, wp_salt( 'auth' ) );
mdvrm_assert( 'XFF accepted from configured proxy', 201 === $r['status'] );
mdvrm_assert( 'configured proxy identity used', (int) get_transient( $key_config ) >= 1 );
delete_transient( $key_config );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
$plugin->settings()->update( array( 'trust_auth_header' => 0, 'trust_proxies' => array() ) );

// --- Oversized payload is rejected before decoding.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_MDVRM_NONCE'] = $anon_nonce;
$big = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$big->set_body(
	wp_json_encode(
		array(
			'url'        => 'https://e.com/big/',
			'total_load' => 1,
			'session_id' => 'big-' . wp_rand(),
			'device'     => str_repeat( 'x', 70000 ),
		)
	)
);
$big_res = rest_do_request( $big );
mdvrm_assert( 'oversized payload -> 413', 413 === (int) $big_res->get_status() );

// --- Sampling is decided once and cached per request.
$ref = new ReflectionProperty( 'MDVRM_Plugin', 'track_decision' );
$ref->setAccessible( true );
wp_set_current_user( 0 );
$_SERVER['REQUEST_URI'] = '/';
$plugin->settings()->update(
	array(
		'sample_rate'    => 1.0,
		'excluded_roles' => array(),
		'blacklist'      => array(),
	)
);
add_filter( 'mdvrm_should_track_request', '__return_false' );
$first = $plugin->should_track_request();
remove_filter( 'mdvrm_should_track_request', '__return_false' );
$second = $plugin->should_track_request();
$ref->setValue( $plugin, null );
$third = $plugin->should_track_request();
mdvrm_assert( 'filter controls first sampling decision', false === $first );
mdvrm_assert( 'sampling decision cached per request', false === $second );
mdvrm_assert( 'sampling cache can be reset', true === $third );

// --- Post-insert action.
$_SERVER['REMOTE_ADDR']         = '127.0.0.1';
$_SERVER['HTTP_X_MDVRM_NONCE']  = $anon_nonce;
$inserted                       = array();
$listener                       = function ( $id, $row ) use ( &$inserted ) {
	$inserted[] = array(
		'id'  => $id,
		'url' => $row['url'],
	);
};
add_action( 'mdvrm_log_inserted', $listener, 10, 2 );

$hook_url = 'https://e.com/inserted-' . wp_rand() . '/';
$hook     = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$hook->set_body(
	wp_json_encode(
		array(
			'url'        => $hook_url,
			'total_load' => 1,
			'session_id' => 'hook-' . wp_rand(),
		)
	)
);
$hook_res = rest_do_request( $hook );
remove_action( 'mdvrm_log_inserted', $listener, 10 );

mdvrm_assert( 'valid hook collect -> 201', 201 === (int) $hook_res->get_status() );
mdvrm_assert( 'mdvrm_log_inserted fires once', 1 === count( $inserted ) );
mdvrm_assert( 'mdvrm_log_inserted provides row id', ! empty( $inserted[0]['id'] ) );
mdvrm_assert( 'mdvrm_log_inserted provides stored url', $hook_url === $inserted[0]['url'] );

mdvrm_summary();
