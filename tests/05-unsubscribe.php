<?php
/**
 * Email opt-out (unsubscribe link) tests.
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

$recipient = 'rum-unsub@example.com';

mdvrm_settings(
	array(
		'report_schedule'       => 'daily',
		'alert_recipient'       => $recipient,
		'alert_ttfb_threshold'  => 2.0,
		'alert_consecutive'     => 2,
		'alert_min_interval'    => 300,
		'limit'                 => 10000,
	)
);

$unsub = MDVRM_Plugin::instance()->unsubscribe();

/**
 * Last captured email as an array with to, subject, message, headers.
 */
function mdvrm_last_mail() {
	return $GLOBALS['mdvrm_mail'] ? end( $GLOBALS['mdvrm_mail'] ) : null;
}

/**
 * Header list of a captured email as one lowercase string.
 *
 * @param array|null $mail Mail.
 * @return string
 */
function mdvrm_header_blob( $mail ) {
	if ( ! is_array( $mail ) || ! isset( $mail['headers'] ) ) {
		return '';
	}
	$raw    = is_array( $mail['headers'] ) ? $mail['headers'] : array( (string) $mail['headers'] );
	$joined = implode( "\n", array_map( 'strval', $raw ) );

	// WP also passes associative headers through.
	if ( is_array( $mail['headers'] ) ) {
		foreach ( $mail['headers'] as $key => $value ) {
			if ( is_string( $key ) ) {
				$joined .= "\n" . $key . ': ' . $value;
			}
		}
	}

	return strtolower( $joined );
}

// --- Hook wiring: the link must work for logged-out visitors too.
mdvrm_assert( 'opt-out endpoint registered', has_action( 'admin_post_' . MDVRM_Unsubscribe::ACTION ) !== false );
mdvrm_assert( 'opt-out endpoint works without login', has_action( 'admin_post_nopriv_' . MDVRM_Unsubscribe::ACTION ) !== false );
mdvrm_assert( 'admin resume endpoint registered', has_action( 'admin_post_' . MDVRM_Unsubscribe::RESUME_ACTION ) !== false );

// --- Token shape.
$link = $unsub->link( $recipient );
mdvrm_assert( 'link points at admin-post.php', false !== strpos( $link, 'admin-post.php' ) );
mdvrm_assert( 'link carries the opt-out action', false !== strpos( $link, 'action=' . MDVRM_Unsubscribe::ACTION ) );

preg_match( '/[?&]t=([a-f0-9]+)$/', $link, $token_from_link );
mdvrm_assert( 'link carries a token', isset( $token_from_link[1] ) && 40 === strlen( $token_from_link[1] ) );
mdvrm_assert( 'link does not expose the recipient address', false === stripos( $link, 'rum-unsub' ) );

$token = MDVRM_Unsubscribe::token( $recipient );
mdvrm_assert( 'link token matches the derived token', isset( $token_from_link[1] ) && $token_from_link[1] === $token );
mdvrm_assert( 'token is 40 hex characters', 1 === preg_match( '/^[a-f0-9]{40}$/', $token ) );
mdvrm_assert( 'token is stable across calls', $token === MDVRM_Unsubscribe::token( $recipient ) );
mdvrm_assert( 'token ignores address case and spacing', $token === MDVRM_Unsubscribe::token( '  RUM-UNSUB@Example.com  ' ) );
mdvrm_assert( 'token differs per recipient', $token !== MDVRM_Unsubscribe::token( 'other@example.com' ) );
mdvrm_assert( 'secret is 64 hex characters', 1 === preg_match( '/^[a-f0-9]{64}$/', MDVRM_Unsubscribe::secret() ) );
mdvrm_assert( 'secret persists', MDVRM_Unsubscribe::secret() === MDVRM_Unsubscribe::secret() );
mdvrm_assert( 'invalid address produces no link', '' === $unsub->link( 'not-an-email' ) );

// --- Reject forged or malformed tokens.
$GLOBALS['mdvrm_mail'] = array();
$out = $unsub->process_unsubscribe( '', true, '203.0.113.10' );
mdvrm_assert( 'missing token rejected with 400', 400 === $out['status'] );
mdvrm_assert( 'missing token changes nothing', ! $unsub->is_opted_out( $recipient ) );

$out = $unsub->process_unsubscribe( str_repeat( 'f', 40 ), true, '203.0.113.10' );
mdvrm_assert( 'forged token rejected with 400', 400 === $out['status'] );
mdvrm_assert( 'forged token changes nothing', ! $unsub->is_opted_out( $recipient ) );

$out = $unsub->process_unsubscribe( substr( $token, 0, 39 ), true, '203.0.113.10' );
mdvrm_assert( 'truncated token rejected', 400 === $out['status'] );

$out = $unsub->process_unsubscribe( strtoupper( $token ), true, '203.0.113.10' );
mdvrm_assert( 'uppercase token rejected', 400 === $out['status'] );

$out = $unsub->process_unsubscribe( $token . '0', true, '203.0.113.10' );
mdvrm_assert( 'longer token rejected', 400 === $out['status'] );

// A token signed by another secret (for example after a reinstall) must not work.
$foreign = mdvrm_sign_with_test_secret( 'rum-unsub@example.com' );
$out     = $unsub->process_unsubscribe( $foreign, true, '203.0.113.10' );
mdvrm_assert( 'token from another secret rejected', 400 === $out['status'] );

// --- Automated link previews must not unsubscribe anyone.
$out = $unsub->process_unsubscribe( $token, false, '203.0.113.11' );
mdvrm_assert( 'prefetch request answers 200', 200 === $out['status'] );
mdvrm_assert( 'prefetch request does not unsubscribe', ! $out['changed'] && ! $unsub->is_opted_out( $recipient ) );

// --- A real click unsubscribes this recipient only.
$out = $unsub->process_unsubscribe( $token, true, '203.0.113.12' );
mdvrm_assert( 'click unsubscribes with 200', 200 === $out['status'] );
mdvrm_assert( 'click reports a state change', $out['changed'] );
mdvrm_assert( 'click resolves the matching address', $recipient === $out['email'] );
mdvrm_assert( 'recipient is now opted out', $unsub->is_opted_out( $recipient ) );
mdvrm_assert( 'other addresses are untouched', ! $unsub->is_opted_out( 'other@example.com' ) );
mdvrm_assert( 'opt-out stored with a timestamp', isset( $unsub->optouts()[ $recipient ] ) && $unsub->optouts()[ $recipient ] > 0 );
mdvrm_assert( 'opt-out page does not echo the address', false === stripos( $out['message'], 'rum-unsub' ) );

$out = $unsub->process_unsubscribe( $token, true, '203.0.113.12' );
mdvrm_assert( 'second click is idempotent', 200 === $out['status'] && ! $out['changed'] );
mdvrm_assert( 'second click keeps the address opted out', $unsub->is_opted_out( $recipient ) );

// --- An opted-out recipient gets no reports and no alerts.
$GLOBALS['mdvrm_mail'] = array();
delete_option( 'mdvrm_last_report_ts' );
mdvrm_assert( 'send_report refuses an opted-out recipient', false === MDVRM_Plugin::instance()->reports()->send_report( true ) );
mdvrm_assert( 'no report email captured', null === mdvrm_last_mail() );

MDVRM_Plugin::instance()->reports()->register_cron();
wp_clear_scheduled_hook( MDVRM_Reports::CRON_HOOK );
wp_schedule_event( time() - 10, 'mdvrm_hourly', MDVRM_Reports::CRON_HOOK );
do_action( MDVRM_Reports::CRON_HOOK );
mdvrm_assert( 'cron sends nothing to an opted-out recipient', null === mdvrm_last_mail() );
mdvrm_assert( 'cron does not pretend a report was sent', ! get_option( 'mdvrm_last_report_ts' ) );

$GLOBALS['mdvrm_mail'] = array();
MDVRM_Plugin::instance()->reports()->track_ttfb_sample( 9.0 );
MDVRM_Plugin::instance()->reports()->track_ttfb_sample( 9.0 );
mdvrm_assert( 'no alert email to an opted-out recipient', null === mdvrm_last_mail() );

// --- Manual report trigger reports the paused state instead of failing silently.
wp_set_current_user( 1 );
mdvrm_routes();
$res = mdvrm_post( '/mudrava-rum/v1/send-report' );
mdvrm_assert( 'manual trigger reports paused', 409 === $res['status'] );
mdvrm_assert( 'paused response carries a message', is_array( $res['data'] ) && isset( $res['data']['message'] ) );
wp_set_current_user( 0 );

// --- Resuming delivery restores everything.
mdvrm_assert( 'opt_in removes the opt-out', $unsub->opt_in( $recipient ) );
mdvrm_assert( 'opt_in is idempotent', ! $unsub->opt_in( $recipient ) );
mdvrm_assert( 'recipient receives emails again', ! $unsub->is_opted_out( $recipient ) );

$GLOBALS['mdvrm_mail'] = array();
delete_option( 'mdvrm_last_report_ts' );
mdvrm_assert( 'report sends again after resume', MDVRM_Plugin::instance()->reports()->send_report( true ) );

$mail = mdvrm_last_mail();
mdvrm_assert( 'report captured', null !== $mail );
$head = mdvrm_header_blob( $mail );
mdvrm_assert( 'email carries List-Unsubscribe', false !== strpos( $head, 'list-unsubscribe: <' ) );
mdvrm_assert( 'email carries the one-click header', false !== strpos( $head, 'list-unsubscribe-post: list-unsubscribe=one-click' ) );
mdvrm_assert( 'List-Unsubscribe points at the opt-out endpoint', null !== $mail && 1 === preg_match( '#list-unsubscribe: <https?://[^>]+admin-post\.php\?action=' . MDVRM_Unsubscribe::ACTION . '&t=[a-f0-9]{40}>#i', $head ) );
mdvrm_assert( 'body carries the unsubscribe line', null !== $mail && false !== stripos( $mail['message'], 'Unsubscribe from these emails:' ) );
mdvrm_assert( 'body unsubscribe line is a working link', null !== $mail && false !== strpos( $mail['message'], $unsub->link( $recipient ) ) );
mdvrm_assert( 'body link matches the List-Unsubscribe header', null !== $mail && 1 === preg_match( '#https?://\S+admin-post\.php\?action=' . MDVRM_Unsubscribe::ACTION . '&t=' . preg_quote( $token, '#' ) . '#', $mail['message'] ) );

$GLOBALS['mdvrm_mail'] = array();
MDVRM_Plugin::instance()->reports()->track_ttfb_sample( 9.0 );
MDVRM_Plugin::instance()->reports()->track_ttfb_sample( 9.0 );
$mail = mdvrm_last_mail();
mdvrm_assert( 'alert email sent again', null !== $mail );
mdvrm_assert( 'alert carries List-Unsubscribe', false !== strpos( mdvrm_header_blob( $mail ), 'list-unsubscribe: <' ) );
mdvrm_assert( 'alert body carries the unsubscribe line', null !== $mail && false !== stripos( $mail['message'], 'Unsubscribe from these emails:' ) );

// --- Unsubscribing one address must not stop a different recipient.
$unsub->opt_out( $recipient );
mdvrm_settings( array( 'alert_recipient' => 'second-recipient@example.com' ) );
$GLOBALS['mdvrm_mail'] = array();
delete_option( 'mdvrm_last_report_ts' );
mdvrm_assert( 'new recipient still gets reports', MDVRM_Plugin::instance()->reports()->send_report( true ) );
$mail = mdvrm_last_mail();
mdvrm_assert( 'new recipient is the addressee', null !== $mail && 'second-recipient@example.com' === $mail['to'] );
mdvrm_assert( 'opted-out address stays opted out', $unsub->is_opted_out( $recipient ) );

// A token for the old address still resolves to the old address.
$out = $unsub->process_unsubscribe( $token, true, '203.0.113.13' );
mdvrm_assert( 'old link still resolves to the opted-out address', $recipient === $out['email'] );
mdvrm_assert( 're-clicking an old link does not touch the new recipient', ! $unsub->is_opted_out( 'second-recipient@example.com' ) );

// --- Forged-token throttling per client address.
$ip = '198.51.100.77';
for ( $i = 0; $i < MDVRM_Unsubscribe::MAX_ATTEMPTS; $i++ ) {
	$unsub->process_unsubscribe( str_repeat( 'a', 40 ), true, $ip );
}
mdvrm_assert( 'attempt counter builds up', $unsub->attempt_limit_reached( $ip ) );
$out = $unsub->process_unsubscribe( MDVRM_Unsubscribe::token( 'second-recipient@example.com' ), true, $ip );
mdvrm_assert( 'throttled address is refused with 429', 429 === $out['status'] );
mdvrm_assert( 'throttling does not unsubscribe anyone', ! $unsub->is_opted_out( 'second-recipient@example.com' ) );
$out = $unsub->process_unsubscribe( MDVRM_Unsubscribe::token( 'second-recipient@example.com' ), true, '198.51.100.78' );
mdvrm_assert( 'throttle is scoped to one client address', 200 === $out['status'] );

// --- Opt-out storage stays bounded.
for ( $i = 1; $i <= MDVRM_Unsubscribe::MAX_OPTOUTS + 15; $i++ ) {
	$unsub->opt_out( 'bulk' . $i . '@example.com' );
}
mdvrm_assert( 'opt-out map is capped', count( $unsub->optouts() ) <= MDVRM_Unsubscribe::MAX_OPTOUTS );
$stored = get_option( 'mdvrm_optouts', array() );
mdvrm_assert( 'stored opt-outs are valid addresses', is_array( $stored ) && '' === mdvrm_bad_optout_keys( $stored ) );

// --- Storage survives a fresh instance (no per-request cache leak).
mdvrm_assert( 'opt-out survives a reload', ( new MDVRM_Unsubscribe( MDVRM_Plugin::instance() ) )->is_opted_out( $recipient ) );

// --- Malformed stored data is ignored, not trusted.
update_option( 'mdvrm_optouts', array( 'garbage', 'not an email' => 5, 'ok@example.com' => 12 ) );
$unsub->reset_cache();
mdvrm_assert( 'malformed opt-out entry ignored', ! $unsub->is_opted_out( 'garbage' ) );
mdvrm_assert( 'valid opt-out entry kept', $unsub->is_opted_out( 'ok@example.com' ) );
$unsub->opt_out( 'fresh@example.com' );
$stored = get_option( 'mdvrm_optouts', array() );
mdvrm_assert( 'malformed entry dropped on the next write', ! array_key_exists( 0, $stored ) && ! array_key_exists( 'garbage', $stored ) );

// --- Uninstall clears the new options.
mdvrm_assert( 'uninstall covers opt-outs', mdvrm_uninstall_covers( 'mdvrm_optouts' ) );
mdvrm_assert( 'uninstall covers the signing secret', mdvrm_uninstall_covers( 'mdvrm_unsub_secret' ) );

// Leave the stand clean for manual checks and the seed step.
delete_option( 'mdvrm_optouts' );
$unsub->reset_cache();

mdvrm_summary();

/**
 * Sign a token with a throwaway secret to simulate a different install.
 *
 * @param string $email Address.
 * @return string
 */
function mdvrm_sign_with_test_secret( $email ) {
	$stored = get_option( 'mdvrm_unsub_secret', '' );
	update_option( 'mdvrm_unsub_secret', str_repeat( '9', 64 ) );
	$token = substr( hash_hmac( 'sha256', MDVRM_Unsubscribe::ACTION . '|' . $email, str_repeat( '9', 64 ) ), 0, MDVRM_Unsubscribe::TOKEN_LENGTH );
	update_option( 'mdvrm_unsub_secret', $stored );

	return $token;
}

/**
 * Return the first entry of a stored map that is not a valid address.
 *
 * @param array $map Stored map.
 * @return string
 */
function mdvrm_bad_optout_keys( $map ) {
	foreach ( array_keys( $map ) as $key ) {
		if ( ! is_string( $key ) || ! is_email( $key ) ) {
			return 'bad';
		}
	}

	return '';
}

/**
 * Whether uninstall.php deletes an option.
 *
 * @param string $option Option name.
 * @return bool
 */
function mdvrm_uninstall_covers( $option ) {
	$code = file_get_contents( MDVRM_PLUGIN_DIR . 'uninstall.php' );

	return false !== $code && false !== strpos( $code, "delete_option( '" . $option . "' )" );
}
