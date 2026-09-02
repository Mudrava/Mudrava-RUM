<?php
/**
 * Stand-only mailer config: route wp_mail through Mailpit SMTP.
 *
 * @package dev-stand
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_mail_from', function () {
	return 'monitor@mudrava.local';
} );

add_action( 'phpmailer_init', function ( $mailer ) {
	$mailer->isSMTP();
	$mailer->Host = 'mdvrm-stand-mailpit-1';
	$mailer->Port = 1025;
	$mailer->SMTPAutoTLS = false;
	$mailer->SMTPSecure  = '';
}, 20 );
