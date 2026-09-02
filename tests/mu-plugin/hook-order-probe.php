<?php
/**
 * Must-use test probe: records whether the plugin's cron listener is
 * attached before core dispatches due events during init.
 *
 * Install into wp-content/mu-plugins/ on the test stand only.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	function () {
		if ( has_action( 'mdvrm_reports_cron' ) ) {
			update_option( 'mdvrm_probe_pl', 'ok' );
		}
		if ( has_action( 'rest_api_init' ) ) {
			update_option( 'mdvrm_probe_rest', 'ok' );
		}
	},
	999
);

add_action(
	'init',
	function () {
		if ( ! has_action( 'mdvrm_reports_cron' ) ) {
			update_option( 'mdvrm_probe_missing', 'yes' );
		}
	},
	5
);

delete_option( 'mdvrm_probe_pl' );
delete_option( 'mdvrm_probe_rest' );
delete_option( 'mdvrm_probe_missing' );
