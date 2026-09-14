<?php
/**
 * Reports, alerts, cron timing and retention tests.
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


require_once __DIR__ . '/helpers.php';

mdvrm_mail_intercept();
mdvrm_block_http();
mdvrm_reset();

global $wpdb;

$nonce = wp_create_nonce( 'mdvrm_collect' );

// --- Cron wiring (regression: handler must be attached by plugins_loaded,
// before core's wp_cron() dispatches due events during init).
mdvrm_assert( 'probe installed', 'ok' === get_option( 'mdvrm_probe_pl', '' ) );
mdvrm_assert( 'no listeners registered too late during init', 'yes' !== get_option( 'mdvrm_probe_missing', '' ) );
mdvrm_assert( 'cron handler attached at plugins_loaded', has_action( MDVRM_Reports::CRON_HOOK ) !== false );
$schedules = wp_get_schedules();
mdvrm_assert( 'custom schedule registered', isset( $schedules['mdvrm_hourly'] ) );
wp_clear_scheduled_hook( MDVRM_Reports::CRON_HOOK );
MDVRM_Plugin::instance()->reports()->register_cron();
$next = wp_next_scheduled( MDVRM_Reports::CRON_HOOK );
mdvrm_assert( 'event scheduled', (bool) $next );
$crons = _get_cron_array();
$found = false;
foreach ( $crons as $ts => $hooks ) {
	if ( isset( $hooks[ MDVRM_Reports::CRON_HOOK ] ) ) {
		$ev    = reset( $hooks[ MDVRM_Reports::CRON_HOOK ] );
		$found = isset( $ev['schedule'] ) && 'mdvrm_hourly' === $ev['schedule'];
	}
}
mdvrm_assert( 'event uses custom recurring schedule', $found );

// --- Settings for this suite.
mdvrm_settings(
	array(
		'retention_days'        => 2,
		'limit'                 => 2000,
		'sample_rate'           => 1.0,
		'excluded_roles'        => array(),
		'report_schedule'       => 'daily',
		'alert_recipient'       => 'rum-alerts@example.com',
		'alert_ttfb_threshold'  => 2.0,
		'alert_consecutive'     => 3,
		'alert_min_interval'    => 300,
	)
);

// --- Retention purge.
$old = gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS );
$new = gmdate( 'Y-m-d H:i:s' );
$wpdb->query(
	$wpdb->prepare(
		'INSERT INTO ' . MDVRM_TABLE . " (event_time,url,server_time,ttfb,lcp,total_load,memory_peak,device,net,country,session_id,user_role,meta)
		VALUES (%s,'https://e.com/a',0.1,0.5,1.0,2.0,0,'desktop','4g','','x','', '{}'), (%s,'https://e.com/b',0.1,0.5,1.0,2.0,0,'desktop','4g','','x','', '{}')",
		$old,
		$new
	)
); // phpcs:ignore WordPress.DB
$total_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MDVRM_TABLE ); // phpcs:ignore WordPress.DB

do_action( MDVRM_Reports::CRON_HOOK );

$total_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MDVRM_TABLE ); // phpcs:ignore WordPress.DB
mdvrm_assert( 'old rows purged by cron', $total_after < $total_before );
$old_left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MDVRM_TABLE . ' WHERE event_time = %s', $old ) ); // phpcs:ignore WordPress.DB
mdvrm_assert( 'only fresh rows remain', 0 === $old_left );

// --- FIFO limit eviction.
mdvrm_settings( array( 'limit' => 5 ) );
for ( $i = 0; $i < 6; $i++ ) {
	$wpdb->insert(
		MDVRM_TABLE,
		array(
			'event_time'  => gmdate( 'Y-m-d H:i:s', time() + $i ),
			'url'         => 'https://e.com/f' . $i,
			'server_time' => 0.1,
			'ttfb'        => 0.4,
			'lcp'         => 1.0,
			'total_load'  => 1.5,
			'memory_peak' => 0,
			'device'      => 'desktop',
			'net'         => '4g',
			'country'     => '',
			'session_id'  => 'y' . $i,
			'user_role'   => '',
			'meta'        => '{}',
		),
		array( '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
	);
}
MDVRM_DB::enforce_limit( 5 );
$left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MDVRM_TABLE ); // phpcs:ignore WordPress.DB
mdvrm_assert( 'enforce_limit trims to cap', 5 === $left );

// --- Scheduled report on cron.
MDVRM_Plugin::instance()->reports()->register_cron();
wp_clear_scheduled_hook( MDVRM_Reports::CRON_HOOK );
wp_schedule_event( time() - 10, 'mdvrm_hourly', MDVRM_Reports::CRON_HOOK );
delete_option( 'mdvrm_last_report_ts' );
$GLOBALS['mdvrm_mail'] = array();
mdvrm_settings( array( 'limit' => 10000 ) );

MDVRM_DB::insert( array( 'url' => 'https://e.com/null-ttfb/', 'total_load' => 1, 'lcp' => 1 ) );
MDVRM_DB::insert( array( 'url' => 'https://e.com/null-ttfb/', 'total_load' => 2, 'lcp' => 1 ) );
MDVRM_DB::bump_stats_cache();

$report_subject_filter = function ( $subject, $recipient, $site_name, $stats ) {
		return 'Filtered report subject for ' . $recipient;
	};
add_filter( 'mdvrm_report_email_subject', $report_subject_filter, 10, 4 );
do_action( MDVRM_Reports::CRON_HOOK );
remove_filter( 'mdvrm_report_email_subject', $report_subject_filter, 10 );

$report = null;
foreach ( $GLOBALS['mdvrm_mail'] as $m ) {
	if ( false !== stripos( $m['subject'], 'report' ) ) {
		$report = $m;
	}
}
mdvrm_assert( 'report email sent on cron run', null !== $report );
mdvrm_assert( 'report subject filter is applied', null !== $report && 'Filtered report subject for rum-alerts@example.com' === $report['subject'] );
mdvrm_assert( 'report body contains Pageviews', null !== $report && false !== strpos( $report['message'], 'Pageviews:' ) );
mdvrm_assert( 'report shows missing URL TTFB as placeholder', null !== $report && false !== strpos( $report['message'], '[LCP: 1.000s | TTFB: —] https://e.com/null-ttfb/ (2 hits)' ) );
mdvrm_assert( 'report does not print missing TTFB as zero', null !== $report && false === strpos( $report['message'], '[LCP: 1.000s | TTFB: 0.000s] https://e.com/null-ttfb/' ) );
mdvrm_assert( 'report sent to configured recipient', null !== $report && 'rum-alerts@example.com' === $report['to'] );
mdvrm_assert( 'last_report_ts updated', (int) get_option( 'mdvrm_last_report_ts' ) > 0 );

$GLOBALS['mdvrm_mail'] = array();
do_action( MDVRM_Reports::CRON_HOOK );
$again = 0;
foreach ( $GLOBALS['mdvrm_mail'] as $m ) {
	if ( false !== stripos( $m['subject'], 'report' ) ) {
		$again++;
	}
}
mdvrm_assert( 'report not re-sent within period', 0 === $again );

// --- Manual send.
$before = (int) get_option( 'mdvrm_last_report_ts' );
$GLOBALS['mdvrm_mail'] = array();
$sent   = MDVRM_Plugin::instance()->reports()->send_report( true );
mdvrm_assert( 'manual send returns true', $sent );
mdvrm_assert( 'manual send leaves ts untouched', (int) get_option( 'mdvrm_last_report_ts' ) === $before );

// --- Alerts on ingestion.
$_SERVER['HTTP_X_MDVRM_NONCE'] = $nonce;
$_SERVER['REMOTE_ADDR']        = '198.51.100.11';
$GLOBALS['mdvrm_mail']         = array();
$alert_subject_filter = function ( $subject, $streak, $threshold ) {
		return 'Filtered alert ' . absint( $streak );
	};
add_filter( 'mdvrm_alert_email_subject', $alert_subject_filter, 10, 3 );

$hit = function ( $ttfb ) {
	$request = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
	$request->set_body( wp_json_encode( array( 'url' => 'https://e.com/slow', 'ttfb' => $ttfb, 'lcp' => 3.0, 'total_load' => 4.0 ) ) );
	rest_do_request( $request );
};

$hit( 3.0 );
$hit( 3.0 );
mdvrm_assert( 'streak counts slow samples', 2 === (int) get_option( 'mdvrm_ttfb_streak' ) );
$hit( 0.1 );
mdvrm_assert( 'fast sample resets streak', 0 === (int) get_option( 'mdvrm_ttfb_streak' ) );
$hit( 3.0 );
$hit( 3.0 );
$hit( 3.0 );
$alerts = array_values( array_filter( $GLOBALS['mdvrm_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Filtered alert ' ) ) );
mdvrm_assert( 'alert fired after 3 consecutive slow', 1 === count( $alerts ) );
mdvrm_assert( 'alert subject filter is applied', 'Filtered alert 3' === $alerts[0]['subject'] );
mdvrm_assert( 'streak reset after alert', 0 === (int) get_option( 'mdvrm_ttfb_streak' ) );
$hit( 3.0 );
$hit( 3.0 );
$hit( 3.0 );
$alerts2 = array_values( array_filter( $GLOBALS['mdvrm_mail'], fn( $m ) => 0 === strpos( $m['subject'], 'Filtered alert ' ) ) );
mdvrm_assert( 'cooldown prevents duplicate alerts', count( $alerts2 ) === count( $alerts ) );
remove_filter( 'mdvrm_alert_email_subject', $alert_subject_filter, 10 );

// --- Upgrade.
update_option( 'mdvrm_version', '0.2.0' );
MDVRM_Plugin::instance()->maybe_upgrade();
mdvrm_assert( 'maybe_upgrade bumps version to current', get_option( 'mdvrm_version' ) === MDVRM_VERSION );

// --- Permissions.
$subscriber = get_user_by( 'login', 'mdvrm_sub' );
if ( ! $subscriber ) {
	$uid        = wp_insert_user( array( 'user_login' => 'mdvrm_sub', 'user_pass' => 'secret123!', 'role' => 'subscriber' ) );
	$subscriber = new WP_User( $uid );
}
wp_set_current_user( $subscriber->ID );
$r = mdvrm_get( '/mudrava-rum/v1/logs' );
mdvrm_assert( 'subscriber logs denied', in_array( $r['status'], array( 401, 403 ), true ) );
$r = mdvrm_get( '/mudrava-rum/v1/stats' );
mdvrm_assert( 'subscriber stats denied', in_array( $r['status'], array( 401, 403 ), true ) );
$r = mdvrm_post( '/mudrava-rum/v1/send-report' );
mdvrm_assert( 'subscriber send-report denied', in_array( $r['status'], array( 401, 403 ), true ) );

// --- Report timestamp timezone.
$tz_snapshot = array(
	'timezone_string' => get_option( 'timezone_string' ),
	'gmt_offset'      => get_option( 'gmt_offset' ),
);
update_option( 'timezone_string', 'Europe/Moscow' );
update_option( 'gmt_offset', 3 );
$format = new ReflectionMethod( MDVRM_Plugin::instance()->reports(), 'format_timestamp' );
$format->setAccessible( true );
$formatted = $format->invoke( MDVRM_Plugin::instance()->reports(), strtotime( '2026-01-15 23:30:00 UTC' ) );
mdvrm_assert( 'report timestamps use site timezone', 0 === strpos( $formatted, '2026-01-16 02:30' ) );
if ( false === $tz_snapshot['timezone_string'] || '' === $tz_snapshot['timezone_string'] ) {
	update_option( 'timezone_string', '' );
} else {
	update_option( 'timezone_string', $tz_snapshot['timezone_string'] );
}
update_option( 'gmt_offset', false === $tz_snapshot['gmt_offset'] ? 0 : $tz_snapshot['gmt_offset'] );

mdvrm_summary();
