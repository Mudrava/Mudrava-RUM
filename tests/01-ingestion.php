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
		'url'         => 'https://e.com/post-1/?utm_source=x',
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
mdvrm_assert( 'url stored (query stripped)', 'https://e.com/post-1/' === $row['url'] );
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
		'total_load'  => 99999,
		'ttfb'        => 1e12,
		'lcp'         => -5,
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
mdvrm_assert( 'over-cap total_load zeroed', abs( $row['total_load'] ) < 0.0001 );
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
mdvrm_assert( 'huge numeric total_load -> 201', 201 === $r['status'] );

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
$_COOKIE[ LOGGED_IN_COOKIE ] = 'bogus-cookie';
$r = mdvrm_collect( array( 'url' => 'https://e.com/ck/' ), $nonce );
mdvrm_assert( 'invalid logged-in cookie does not fire wp_login_failed', 0 === $failed );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

// --- trust_auth_header only honored when enabled and valid.
$bucket_off = 'mdvrm_rl_' . hash_hmac( 'sha256', '10.0.0.9:sess-' . gmdate( 'YmdHi' ), wp_salt( 'auth' ) );
mdvrm_settings( array( 'trust_auth_header' => 0 ) );
$_SERVER['REMOTE_ADDR']         = 'invalid-ip';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99, 10.0.0.1';
$got = array();
for ( $i = 0; $i < 3; $i++ ) {
	$got[] = mdvrm_collect( array( 'url' => 'https://e.com/xff-off/', 'total_load' => 1 ), 'no-nonce-' . $i )['status'];
}
mdvrm_assert( 'spoofed XFF ignored when option off', 403 === $got[0] );
mdvrm_settings( array( 'trust_auth_header' => 1 ) );
$key2 = 'mdvrm_rl_' . hash_hmac( 'sha256', '198.51.100.99:' . gmdate( 'YmdHi' ), wp_salt( 'auth' ) );
delete_transient( $key2 );
$ok = mdvrm_collect( array( 'url' => 'https://e.com/xff-on/', 'total_load' => 1 ), $nonce )['status'];
mdvrm_assert( 'valid XFF used when option on', 201 === $ok );
$count = (int) get_transient( $key2 );
mdvrm_assert( 'XFF ip forms rate bucket', $count >= 1 );
delete_transient( $key2 );
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
mdvrm_settings( array( 'trust_auth_header' => 0 ) );

wp_set_current_user( 1 );
mdvrm_assert( 'logs allowed for admin', 200 === mdvrm_request( 'GET', '/mudrava-rum/v1/logs' )['status'] );

mdvrm_summary();
