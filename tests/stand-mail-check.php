<?php
/**
 * Stand helper: send one real report through the configured mailer and print
 * the unsubscribe link so it can be clicked in a browser or curl.
 *
 *   docker exec -w /var/www/html mdvrm-stand-wp-1 wp eval-file /var/www/dev/tests/stand-mail-check.php --allow-root
 *
 * @package MudravaRUM
 */
// phpcs:ignoreFile

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recipient = isset( $args[0] ) ? $args[0] : 'owner@mudrava.local';

$settings                 = get_option( 'mdvrm_settings', array() );
$settings['alert_recipient'] = $recipient;
update_option( 'mdvrm_settings', $settings );
delete_option( 'mdvrm_last_report_ts' );

$unsub = MDVRM_Plugin::instance()->unsubscribe();
$unsub->reset_cache();
$unsub->opt_in( $recipient );

$link = $unsub->link( $recipient );
$sent = MDVRM_Plugin::instance()->reports()->send_report( true );

echo 'recipient: ' . $recipient . "\n";
echo 'opted_out: ' . ( $unsub->is_opted_out( $recipient ) ? 'yes' : 'no' ) . "\n";
echo 'sent: ' . ( $sent ? 'yes' : 'no' ) . "\n";
echo 'link: ' . $link . "\n";
