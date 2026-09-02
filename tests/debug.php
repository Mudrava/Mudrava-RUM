<?php
/**
 * Diagnostics for failing assertions.
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


require_once __DIR__ . '/helpers.php';

if ( ! function_exists( 'mdvrm_get_settings' ) ) {
	echo "PLUGIN NOT ACTIVE\n";
	exit( 1 );
}

global $wpdb;

$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
$nonce                  = wp_create_nonce( 'mdvrm_collect' );

WP_CLI::line( 'is_admin=' . var_export( is_admin(), true ) );

$r = mdvrm_collect( array( 'url' => 'https://e.com/', 'total_load' => 1 ), null );
WP_CLI::line( 'missing nonce: ' . wp_json_encode( $r ) );

$r = mdvrm_collect( array( 'url' => 'https://e.com/', 'total_load' => 1 ), 'deadbeefdead' );
WP_CLI::line( 'bogus nonce: ' . wp_json_encode( $r ) );

$r = mdvrm_collect( array( 'url' => 'https://e.com/' ), $nonce );
WP_CLI::line( 'missing total_load: ' . wp_json_encode( $r ) );

$_SERVER['HTTP_X_MDVRM_NONCE'] = $nonce;
$empty                         = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$empty->set_body( '' );
$res = rest_do_request( $empty );
WP_CLI::line( 'empty body class=' . get_class( $res ) . ' ' . ( ( $res instanceof WP_Error ) ? $res->get_error_code() . ' ' . wp_json_encode( $res->get_error_data() ) : 'resp' . $res->get_status() ) );

$notjson = new WP_REST_Request( 'POST', '/mudrava-rum/v1/collect' );
$notjson->set_body( 'not json at all' );
$res = rest_do_request( $notjson );
WP_CLI::line( 'invalid json class=' . get_class( $res ) . ' ' . ( ( $res instanceof WP_Error ) ? $res->get_error_code() : 'resp' . $res->get_status() ) );

$badsql = new WP_REST_Request( 'GET', '/mudrava-rum/v1/logs' );
$badsql->set_param( 'order_by', "id; DROP TABLE wp_users" );
$res = rest_do_request( $badsql );
WP_CLI::line( 'sqli order_by class=' . get_class( $res ) . ' status=' . ( ( $res instanceof WP_Error ) ? $res->get_error_code() : $res->get_status() ) );

$_SERVER['REQUEST_URI'] = '/front/';
$_SERVER['REMOTE_ADDR'] = '198.51.100.12';
$plugin = MDVRM_Plugin::instance();
$plugin->settings()->update( array( 'sample_rate' => 1.0, 'blacklist' => array(), 'excluded_roles' => array() ) );
wp_set_current_user( 0 );
$nonce = wp_create_nonce( 'mdvrm_collect' );
WP_CLI::line( 'nonce created uid=' . get_current_user_id() );

wp_scripts( null, true );
do_action( 'wp_enqueue_scripts' );
WP_CLI::line( 'guest track=' . var_export( $plugin->should_track_request(), true ) . ' enqueued=' . var_export( wp_script_is( 'mdvrm-collector', 'enqueued' ), true ) );

wp_scripts( null, true );
$plugin->settings()->update( array( 'excluded_roles' => array( 'administrator' ) ) );
wp_set_current_user( 1 );
WP_CLI::line( 'admin track=' . var_export( $plugin->should_track_request(), true ) );
WP_CLI::line( 'user roles=' . wp_json_encode( wp_get_current_user()->roles ) );
do_action( 'wp_enqueue_scripts' );
WP_CLI::line( 'admin enqueued=' . var_export( wp_script_is( 'mdvrm-collector', 'enqueued' ), true ) );
WP_CLI::line( 'excluded now=' . wp_json_encode( $plugin->settings()->all()['excluded_roles'] ) );
WP_CLI::line( 'nonce verify=' . var_export( (bool) wp_verify_nonce( $nonce, 'mdvrm_collect' ), true ) );
WP_CLI::line( 'current user at verify=' . get_current_user_id() );
$fresh = wp_create_nonce( 'mdvrm_collect' );
WP_CLI::line( 'fresh verify=' . var_export( (bool) wp_verify_nonce( $fresh, 'mdvrm_collect' ), true ) );

$plugin = MDVRM_Plugin::instance();
$plugin->reports()->send_report( true );
$sent   = MDVRM_Plugin::instance()->reports()->send_report( true );
WP_CLI::line( 'manual send=' . var_export( $sent, true ) );
mailer_diag();

/**
 * Extra no-op guard so file has no trailing issues.
 */
function mailer_diag() {
	$m = new PHPMailer\PHPMailer\PHPMailer( true );
	WP_CLI::line( 'phpmailer ok' );
}
