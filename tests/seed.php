<?php
/**
 * Seeds demo traffic into the logs table.
 *
 * Usage: wp eval-file tests/seed.php [rows]
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$arg    = isset( $args[0] ) ? absint( $args[0] ) : 60;
$limit  = $arg > 0 ? $arg : 60;
$paths  = array( '/', '/about/', '/product/wireless-headphones/', '/blog/performance-tips/', '/checkout/', '/category/news/' );
$devices = array( 'desktop', 'mobile', 'tablet' );
$nets    = array( '4g', '4g', '4g', '3g', '2g' );
$roles   = array( '', '', 'subscriber' );

wp_delete_user( $arg );

 $started = microtime( true );
for ( $i = 0; $i < $limit; $i++ ) {
	$old     = ( $i % 15 === 0 );
	$path    = $paths[ $i % count( $paths ) ];
	$device  = $devices[ $i % count( $devices ) ];
	$net     = $nets[ $i % count( $nets ) ];
	$slow    = ( $i % 7 === 0 );
	$server  = ( $i % 3 === 0 ) ? 0.0 : round( wp_rand( 15, 380 ) / 1000, 4 );
	$ttfb    = round( wp_rand( 120, $slow ? 3400 : 1200 ) / 1000, 4 );
	$lcp     = round( wp_rand( 600, $slow ? 5200 : 3200 ) / 1000, 4 );
	$load    = round( wp_rand( 900, $slow ? 7000 : 4200 ) / 1000, 4 );
	$offset  = $old ? wp_rand( 3, 30 ) * DAY_IN_SECONDS : wp_rand( 0, 86399 );

	MDVRM_DB::insert(
		array(
			'event_time'  => gmdate( 'Y-m-d H:i:s', time() - $offset ),
			'url'         => home_url( $path ),
			'server_time' => $server,
			'ttfb'        => $ttfb,
			'lcp'         => $lcp,
			'total_load'  => $load,
			'memory_peak' => wp_rand( 2097152, 8388608 ),
			'device'      => $device,
			'net'         => $net,
			'country'     => array( 'US', 'DE', 'NL', 'GB', 'JP', 'BR' )[ $i % 6 ],
			'session_id'  => 'seed-' . substr( md5( (string) $i ), 0, 12 ),
			'user_role'   => $roles[ $i % count( $roles ) ],
			'meta'        => array(),
		)
	);
}

$wpdb->query( $wpdb->prepare( 'UPDATE ' . MDVRM_TABLE . ' SET event_time = %s WHERE id = ( SELECT id FROM ( SELECT MAX(id) as id FROM ' . MDVRM_TABLE . ' ) t )', gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB
$first = (int) $wpdb->get_var( 'SELECT MIN(id) FROM ' . MDVRM_TABLE ); // phpcs:ignore WordPress.DB
if ( $first > 0 ) {
	$wpdb->update( MDVRM_TABLE, array( 'url' => 'javascript:alert(document.cookie)' ), array( 'id' => $first ), array( '%s' ), array( '%d' ) );
	$wpdb->update( MDVRM_TABLE, array( 'lcp' => 99999 ), array( 'id' => $first ), array( '%f' ), array( '%d' ) );
	$wpdb->update( MDVRM_TABLE, array( 'device' => 'hacker-tv' ), array( 'id' => $first ), array( '%s' ), array( '%d' ) );
}

WP_CLI::success( sprintf( 'seeded %d rows in %.1fs', $limit, microtime( true ) - $started ) );
