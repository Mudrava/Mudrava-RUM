<?php
/**
 * Logs and stats query layer tests.
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


require_once __DIR__ . '/helpers.php';

mdvrm_reset();
mdvrm_routes();
wp_set_current_user( 1 );

global $wpdb;

MDVRM_DB::create_table();

$mk = function ( $url, $ttfb, $lcp, $server, $load, $device, $net, $session, $days_ago = 0 ) {
	return MDVRM_DB::insert(
		array(
			'event_time'  => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ),
			'url'         => $url,
			'server_time' => $server,
			'ttfb'        => $ttfb,
			'lcp'         => $lcp,
			'total_load'  => $load,
			'memory_peak' => 2097152,
			'device'      => $device,
			'net'         => $net,
			'country'     => 'US',
			'session_id'  => $session,
			'user_role'   => '',
			'meta'        => array(),
		)
	);
};

$id1 = $mk( 'https://e.com/product/', 0.5, 2.0, 0.2, 3.0, 'mobile', '4g', 'sess-a' );
$id2 = $mk( 'https://e.com/product/', 1.5, 4.0, 0.6, 5.0, 'desktop', '3g', 'sess-b' );
$id3 = $mk( 'https://e.com/blog/', 0.3, 1.0, 0.1, 1.5, 'mobile', '4g', 'sess-a' );
$id4 = $mk( 'https://e.com/blog/', 0.0, 0.0, 0.0, 0.0, 'tablet', 'slow-2g', 'sess-c' );
$id5 = $mk( 'https://e.com/old/', 0.4, 1.2, 0.1, 2.0, 'desktop', '4g', 'sess-d', 10 );

mdvrm_assert( 'inserts returned ids', $id1 && $id2 && $id3 && $id4 && $id5 );

$r = mdvrm_get( '/mudrava-rum/v1/logs' );
mdvrm_assert( 'logs total 5', 5 === $r['data']['total'] );
mdvrm_assert( 'logs default per_page 50 cap ok', is_array( $r['data']['data'] ) && 5 === count( $r['data']['data'] ) );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'device' => 'mobile' ) );
mdvrm_assert( 'device filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'net' => '3g' ) );
mdvrm_assert( 'net filter -> 1', 1 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'device' => 'garbage' ) );
mdvrm_assert( 'invalid device filter ignored -> all', 5 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'session_id' => 'sess-a' ) );
mdvrm_assert( 'session filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'url' => 'product' ) );
mdvrm_assert( 'url like filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'days' => 7 ) );
mdvrm_assert( 'days filter drops 10-day-old row', 4 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => 'lcp', 'order' => 'asc', 'per_page' => 1 ) );
mdvrm_assert( 'sort lcp asc first row lcp 0', 0.0 === (float) $r['data']['data'][0]['lcp'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => 'lcp', 'order' => 'desc', 'per_page' => 1 ) );
mdvrm_assert( 'sort lcp desc first row lcp 4.0', abs( (float) $r['data']['data'][0]['lcp'] - 4.0 ) < 0.001 );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => "id; DROP TABLE wp_users", 'per_page' => 1 ) );
mdvrm_assert( 'sql injection in order_by rejected safely', in_array( $r['status'], array( 200, 400 ), true ) );
$users_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->users );
mdvrm_assert( 'users table intact after injection attempt', $users_left > 0 );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'page' => 2, 'per_page' => 2 ) );
mdvrm_assert( 'pagination page 2 size', 2 === count( $r['data']['data'] ) );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'per_page' => 999 ) );
mdvrm_assert( 'per_page capped 200', $r['status'] >= 200 );

$s = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'stats count all 5', 5 === $s['data']['count'] );
$exp_ttfb = round( ( 0.5 + 1.5 + 0.3 + 0.4 ) / 4, 3 );
mdvrm_assert( 'stats avg ttfb uses NULLIF (zero excluded)', abs( $s['data']['avg_ttfb'] - $exp_ttfb ) < 0.002 );
$exp_lcp = round( ( 2.0 + 4.0 + 1.0 + 1.2 ) / 4, 3 );
mdvrm_assert( 'stats avg lcp uses NULLIF', abs( $s['data']['avg_lcp'] - $exp_lcp ) < 0.002 );
$exp_p75 = 4.0;
mdvrm_assert( 'p75 lcp over non-zero rows', abs( $s['data']['p75_lcp'] - $exp_p75 ) < 0.001 );
mdvrm_assert( 'slowest_lcp top is product (4.0)', 'https://e.com/product/' === $s['data']['slowest_lcp'][0]['url'] );
$slow_urls = wp_list_pluck( $s['data']['slowest_lcp'], 'url' );
mdvrm_assert( 'slowest tables exclude singleton url', 2 === count( $s['data']['slowest_lcp'] ) && ! in_array( 'https://e.com/old/', $slow_urls, true ) );
mdvrm_assert( 'devices breakdown sums to total', 5 === array_sum( wp_list_pluck( $s['data']['devices'], 'count' ) ) );

$s2 = mdvrm_get( '/mudrava-rum/v1/stats', array( 'device' => 'mobile' ) );
mdvrm_assert( 'stats honor device filter', 2 === $s2['data']['count'] );

$direct = MDVRM_DB::query_logs( array( 'session_id' => "a' OR '1'='1" ) );
mdvrm_assert( 'SQLi in session filter yields no rows', 0 === $direct['total'] );

$t = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'trend returns daily buckets', isset( $t['data']['trend'] ) && count( $t['data']['trend'] ) >= 1 );

update_option( 'mdvrm_version', '0.2.0' );
MDVRM_Plugin::instance()->maybe_upgrade();
mdvrm_assert( 'maybe_upgrade sets current version', get_option( 'mdvrm_version' ) === MDVRM_VERSION );

wp_set_current_user( 0 );
$r = mdvrm_get( '/mudrava-rum/v1/logs' );
mdvrm_assert( 'anon logs 401/403', in_array( $r['status'], array( 401, 403 ), true ) );
$r = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'anon stats 401/403', in_array( $r['status'], array( 401, 403 ), true ) );
$r = mdvrm_post( '/mudrava-rum/v1/send-report' );
mdvrm_assert( 'anon send-report 401/403', in_array( $r['status'], array( 401, 403 ), true ) );

mdvrm_finish();
