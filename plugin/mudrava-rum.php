<?php
/**
 * Plugin Name: Mudrava RUM
 * Plugin URI: https://wordpress.org/plugins/mudrava-rum/
 * Description: Real User Monitoring (RUM) for WordPress websites to track performance metrics and user experience.
 * Version: 1.0.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: MUDRAVA
 * Author URI: https://mudrava.com/en/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mudrava-rum
 * Domain Path: /languages
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDVRM_VERSION', '1.0.0' );
define( 'MDVRM_PLUGIN_FILE', __FILE__ );
define( 'MDVRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDVRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

global $wpdb;
define( 'MDVRM_TABLE', $wpdb->prefix . 'mdvrm_logs' );

require_once MDVRM_PLUGIN_DIR . 'includes/class-mdvrm-plugin.php';

// Bootstrap plugin.
add_action( 'plugins_loaded', array( 'MDVRM_Plugin', 'instance' ) );

// Activation / deactivation hooks.
register_activation_hook( __FILE__, array( 'MDVRM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MDVRM_Plugin', 'deactivate' ) );
