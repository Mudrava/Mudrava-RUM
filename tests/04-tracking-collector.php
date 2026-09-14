<?php
/**
 * Tracking pipeline and asset tests.
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

$plugin = MDVRM_Plugin::instance();
$defaults = $plugin->settings()->all();

mdvrm_assert( 'default limit 1000', 1000 === $defaults['limit'] );
mdvrm_assert( 'default retention 30', 30 === $defaults['retention_days'] );
mdvrm_assert( 'default sample 1.0', 1.0 === $defaults['sample_rate'] );
mdvrm_assert( 'default excludes admin+editor', array( 'administrator', 'editor' ) === $defaults['excluded_roles'] );
mdvrm_assert( 'default alert threshold 2', 2.0 === (float) $defaults['alert_ttfb_threshold'] );

wp_set_current_user( 0 );
$_SERVER['REQUEST_URI'] = '/page/';

mdvrm_settings( array( 'sample_rate' => 1.0 ) );
$tracked = 0;
for ( $i = 0; $i < 40; $i++ ) {
	$plugin->reset_request_state();
	if ( $plugin->should_track_request() ) {
		$tracked++;
	}
}
mdvrm_assert( 'sampling 1.0 tracks all', 40 === $tracked );

mdvrm_settings( array( 'sample_rate' => 0 ) );
$tracked = 0;
for ( $i = 0; $i < 40; $i++ ) {
	$plugin->reset_request_state();
	if ( $plugin->should_track_request() ) {
		$tracked++;
	}
}
mdvrm_assert( 'sampling 0 tracks none', 0 === $tracked );

mdvrm_settings( array( 'sample_rate' => 0.5 ) );
$tracked = 0;
for ( $i = 0; $i < 200; $i++ ) {
	$plugin->reset_request_state();
	if ( $plugin->should_track_request() ) {
		$tracked++;
	}
}
mdvrm_assert( 'sampling 0.5 applied exactly once per request (not squared)', $tracked > 60 && $tracked < 140 );

mdvrm_settings( array( 'sample_rate' => 1.0, 'blacklist' => array( '/cart', '/my-account/' ) ) );
$_SERVER['REQUEST_URI'] = '/cart/';
$plugin->reset_request_state();
mdvrm_assert( 'blacklist prefix excluded', false === $plugin->should_track_request() );
$_SERVER['REQUEST_URI'] = '/my-account/?x=1';
$plugin->reset_request_state();
mdvrm_assert( 'blacklist with query excluded', false === $plugin->should_track_request() );
$_SERVER['REQUEST_URI'] = '/shop/';
$plugin->reset_request_state();
mdvrm_assert( 'non-blacklist tracked', true === $plugin->should_track_request() );
$_SERVER['REQUEST_URI'] = '/cartx/';
$plugin->reset_request_state();
mdvrm_assert( 'blacklist is prefix not substring (tracked)', true === $plugin->should_track_request() );

wp_set_current_user( 1 );
mdvrm_settings( array( 'excluded_roles' => array( 'administrator' ) ) );
$_SERVER['REQUEST_URI'] = '/page/';
$plugin->reset_request_state();
mdvrm_assert( 'admin excluded when role in list', false === $plugin->should_track_request() );
mdvrm_settings( array( 'excluded_roles' => array() ) );
$plugin->reset_request_state();
mdvrm_assert( 'admin tracked when not excluded', true === $plugin->should_track_request() );

$ctx = $plugin->get_server_context();
mdvrm_assert( 'serverTime numeric and sane', is_numeric( $ctx['serverTime'] ) && $ctx['serverTime'] >= 0 && $ctx['serverTime'] < 60 );
mdvrm_assert( 'memoryPeak positive int', $ctx['memoryPeak'] > 0 );
$_SERVER['HTTP_CF_IPCOUNTRY'] = 'us';
$ctx = $plugin->get_server_context();
mdvrm_assert( 'country normalized', 'US' === $ctx['country'] );
$_SERVER['HTTP_CF_IPCOUNTRY'] = "Zz';DROP";
$ctx = $plugin->get_server_context();
mdvrm_assert( 'bad country dropped', '' === $ctx['country'] );
unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );

$_SERVER['REQUEST_URI'] = '/front/';
$_SERVER['REMOTE_ADDR'] = '198.51.100.12';
mdvrm_settings( array( 'sample_rate' => 1.0, 'blacklist' => array(), 'excluded_roles' => array() ) );
wp_set_current_user( 0 );
$nonce = wp_create_nonce( 'mdvrm_collect' );

$GLOBALS['wp_scripts'] = null;
wp_scripts();
do_action( 'wp_enqueue_scripts' );
mdvrm_assert( 'collector enqueued for guest', wp_script_is( 'mdvrm-collector', 'enqueued' ) );

$GLOBALS['wp_scripts'] = null;
wp_scripts();
mdvrm_settings( array( 'excluded_roles' => array( 'administrator' ) ) );
wp_set_current_user( 1 );
do_action( 'wp_enqueue_scripts' );
mdvrm_assert( 'collector NOT enqueued for admin with default exclusions', ! wp_script_is( 'mdvrm-collector', 'enqueued' ) );

wp_set_current_user( 0 );
$GLOBALS['wp_scripts'] = null;
wp_scripts();
mdvrm_settings( array( 'excluded_roles' => array() ) );
do_action( 'wp_enqueue_scripts' );
ob_start();
do_action( 'wp_footer' );
wp_print_footer_scripts();
$footer = ob_get_clean();
mdvrm_assert( 'inline settings present', false !== strpos( $footer, 'MDVRMCollectorSettings' ) );
mdvrm_assert( 'nonce emitted', (bool) preg_match( '/"nonce":"[a-f0-9]{10}"/', $footer ) );
mdvrm_assert( 'no legacy timestamp key', false === strpos( $footer, '"timestamp"' ) );

$settings = $plugin->settings();

$san = $settings->update( array( 'limit' => '-5' ) );
mdvrm_assert( 'limit min clamped to 1', 1 === $san['limit'] );
$san = $settings->update( array( 'retention_days' => '0' ) );
mdvrm_assert( 'retention min clamped', 1 === $san['retention_days'] );
$san = $settings->update( array( 'sample_rate' => 'abc' ) );
mdvrm_assert( 'bad sample rate -> 0 (no pollution)', is_float( $san['sample_rate'] ) );
$san = $settings->update( array( 'sample_rate' => 0.5 ) );
mdvrm_assert( 'sample persists exactly 0.5 (no compounding)', 0.5 === $san['sample_rate'] );
$san = $settings->update( array( 'excluded_roles' => 'administrator,, editor ' ) );
mdvrm_assert( 'roles from CSV trimmed', array( 'administrator', 'editor' ) === array_values( $san['excluded_roles'] ) );
$san = $settings->update( array( 'excluded_roles' => array() ) );
mdvrm_assert( 'empty roles preserved (no unset)', array() === $san['excluded_roles'] );
$san = $settings->update( array( 'blacklist' => "/a\r\n/b\r" ) );
mdvrm_assert( 'blacklist from textarea', array( '/a', '/b' ) === array_values( $san['blacklist'] ) );
$san = $settings->update( array( 'blacklist' => array() ) );
mdvrm_assert( 'empty blacklist preserved', array() === $san['blacklist'] );
$san = $settings->update( array( 'report_schedule' => 'bogus' ) );
mdvrm_assert( 'invalid schedule rejected', 'daily' === $san['report_schedule'] );
$san = $settings->update( array( 'alert_min_interval' => 60 ) );
mdvrm_assert( 'cooldown floor 300', 300 === $san['alert_min_interval'] );
$before_email  = $settings->all()['alert_recipient'];
$san           = $settings->update( array( 'alert_recipient' => 'not-an-email' ) );
mdvrm_assert( 'invalid email not overwritten', $san['alert_recipient'] === $before_email );
$san = $settings->update( array( 'alert_recipient' => 'ok@example.com' ) );
mdvrm_assert( 'valid email accepted', 'ok@example.com' === $san['alert_recipient'] );

mdvrm_assert( 'nonce verifies for collect', (bool) wp_verify_nonce( $nonce, 'mdvrm_collect' ) );
mdvrm_assert( 'nonce scoped to action', ! (bool) wp_verify_nonce( $nonce, 'other_action' ) );

mdvrm_summary();
