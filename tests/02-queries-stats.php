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
$id6 = $mk( 'https://e.com/slowest-page/', 1.34, 9.8, 4.1, 9.9, 'mobile', '4g', 'sess-e' );
$id7 = $mk( 'https://e.com/slowest-page/', 1.33, 9.7, 4.0, 9.8, 'desktop', '3g', 'sess-f' );
$id8 = $mk( 'https://e.com/slowest-server/', 1.8, 3.0, 9.1, 3.2, 'desktop', '4g', 'sess-g' );
$id9 = $mk( 'https://e.com/slowest-server/', 1.7, 3.1, 9.0, 3.1, 'tablet', '2g', 'sess-h' );

mdvrm_assert( 'inserts returned ids', $id1 && $id2 && $id3 && $id4 && $id5 && $id6 && $id7 && $id8 && $id9 );

$r = mdvrm_get( '/mudrava-rum/v1/logs' );
mdvrm_assert( 'logs total all seeded rows', 9 === $r['data']['total'] );
mdvrm_assert( 'logs default per_page cap ok', is_array( $r['data']['data'] ) && 9 === count( $r['data']['data'] ) );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'device' => 'mobile' ) );
mdvrm_assert( 'device filter -> 3', 3 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'net' => '3g' ) );
mdvrm_assert( 'net filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'device' => 'garbage' ) );
mdvrm_assert( 'invalid device filter ignored -> all', 9 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'session_id' => 'sess-a' ) );
mdvrm_assert( 'session filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'url' => 'product' ) );
mdvrm_assert( 'url like filter -> 2', 2 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'days' => 7 ) );
mdvrm_assert( 'days filter drops 10-day-old row', 8 === $r['data']['total'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => 'lcp', 'order' => 'asc', 'per_page' => 1 ) );
mdvrm_assert( 'sort lcp asc first row lcp 0', 0.0 === (float) $r['data']['data'][0]['lcp'] );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => 'lcp', 'order' => 'desc', 'per_page' => 1 ) );
mdvrm_assert( 'sort lcp desc returns the slowest row', abs( (float) $r['data']['data'][0]['lcp'] - 9.8 ) < 0.001 );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'order_by' => "id; DROP TABLE wp_users", 'per_page' => 1 ) );
mdvrm_assert( 'sql injection in order_by rejected safely', in_array( $r['status'], array( 200, 400 ), true ) );
$users_left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->users );
mdvrm_assert( 'users table intact after injection attempt', $users_left > 0 );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'page' => 2, 'per_page' => 2 ) );
mdvrm_assert( 'pagination page 2 size', 2 === count( $r['data']['data'] ) );

$r = mdvrm_get( '/mudrava-rum/v1/logs', array( 'per_page' => 999 ) );
mdvrm_assert( 'per_page capped 200', $r['status'] >= 200 );

$s = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'stats count all seeded rows', 9 === $s['data']['count'] );
$exp_ttfb = round( ( 0.5 + 1.5 + 0.3 + 0.4 + 1.34 + 1.33 + 1.8 + 1.7 ) / 8, 3 );
mdvrm_assert( 'stats avg ttfb excludes zero and caps outliers', abs( $s['data']['avg_ttfb'] - $exp_ttfb ) < 0.002 );
$exp_lcp = round( ( 2.0 + 4.0 + 1.0 + 1.2 + 9.8 + 9.7 + 3.0 + 3.1 ) / 8, 3 );
mdvrm_assert( 'stats avg lcp excludes zero and caps outliers', abs( $s['data']['avg_lcp'] - $exp_lcp ) < 0.002 );
$exp_server = round( ( 0.2 + 0.6 + 0.1 + 0.1 + 4.1 + 4.0 + 9.1 + 9.0 ) / 8, 3 );
mdvrm_assert( 'stats avg server time excludes zero and caps outliers', abs( $s['data']['avg_server'] - $exp_server ) < 0.002 );
$exp_load = round( ( 3.0 + 5.0 + 1.5 + 2.0 + 9.9 + 9.8 + 3.2 + 3.1 ) / 8, 3 );
mdvrm_assert( 'stats avg total load excludes zero and caps outliers', abs( $s['data']['avg_load'] - $exp_load ) < 0.002 );
$exp_p75 = 9.7;
mdvrm_assert( 'p75 lcp over valid rows', abs( $s['data']['p75_lcp'] - $exp_p75 ) < 0.001 );
mdvrm_assert( 'slowest_lcp top is the slowest page', 'https://e.com/slowest-page/' === $s['data']['slowest_lcp'][0]['url'] );
$slowest_ttfb = round( ( 1.34 + 1.33 ) / 2, 3 );
mdvrm_assert( 'slowest_lcp includes avg_ttfb', abs( (float) $s['data']['slowest_lcp'][0]['avg_ttfb'] - $slowest_ttfb ) < 0.002 );
$slow_urls = wp_list_pluck( $s['data']['slowest_lcp'], 'url' );
mdvrm_assert( 'slowest tables exclude singleton url', 4 === count( $s['data']['slowest_lcp'] ) && ! in_array( 'https://e.com/old/', $slow_urls, true ) );
mdvrm_assert( 'slowest_server identifies server-time outlier', 'https://e.com/slowest-server/' === $s['data']['slowest_srv'][0]['url'] );
mdvrm_assert( 'devices breakdown sums to total', 9 === array_sum( wp_list_pluck( $s['data']['devices'], 'count' ) ) );

$s2 = mdvrm_get( '/mudrava-rum/v1/stats', array( 'device' => 'mobile' ) );
mdvrm_assert( 'stats honor device filter', 3 === $s2['data']['count'] );

$direct = MDVRM_DB::query_logs( array( 'session_id' => "a' OR '1'='1" ) );
mdvrm_assert( 'SQLi in session filter yields no rows', 0 === $direct['total'] );

$empty_stats = MDVRM_DB::get_stats( array( 'session_id' => 'missing-session' ) );
mdvrm_assert( 'empty stats count is zero', 0 === $empty_stats['count'] );
mdvrm_assert( 'empty stats averages are null', array( null, null, null, null, null ) === array( $empty_stats['avg_ttfb'], $empty_stats['avg_lcp'], $empty_stats['avg_server'], $empty_stats['avg_load'], $empty_stats['p75_lcp'] ) );

$t = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'trend returns daily buckets', isset( $t['data']['trend'] ) && count( $t['data']['trend'] ) >= 1 );

$tz_snapshot      = array(
	'timezone_string' => get_option( 'timezone_string' ),
	'gmt_offset'      => get_option( 'gmt_offset' ),
);
$limit_snapshot   = get_option( 'mdvrm_limit' );
$utc_time         = '2099-12-31 23:30:00';

update_option( 'timezone_string', 'Europe/Moscow' );
update_option( 'gmt_offset', 3 );
$expected_local = wp_date( 'Y-m-d', strtotime( $utc_time . ' UTC' ) );

update_option( 'mdvrm_limit', 10000 );

$tz_id = MDVRM_DB::insert(
	array(
		'url'         => 'https://e.com/tz-check/',
		'event_time'  => $utc_time,
		'ttfb'        => 0.5,
		'lcp'         => 1.0,
		'total_load'  => 2.0,
		'server_time' => 0.1,
		'device'      => 'mobile',
		'net'         => '4g',
	)
);

$trend = MDVRM_DB::get_trend( array( 'url' => 'tz-check', 'days' => 365 ) );
$dates = array_column( $trend, 'date' );
mdvrm_assert( 'trend groups events by site-local calendar date', in_array( $expected_local, $dates, true ) );

global $wpdb;
$wpdb->delete( MDVRM_TABLE, array( 'id' => $tz_id ), array( '%d' ) );
MDVRM_DB::bump_stats_cache();
update_option( 'mdvrm_limit', $limit_snapshot );
if ( false === $tz_snapshot['timezone_string'] || '' === $tz_snapshot['timezone_string'] ) {
	update_option( 'timezone_string', '' );
} else {
	update_option( 'timezone_string', $tz_snapshot['timezone_string'] );
}
update_option( 'gmt_offset', false === $tz_snapshot['gmt_offset'] ? 0 : $tz_snapshot['gmt_offset'] );

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
