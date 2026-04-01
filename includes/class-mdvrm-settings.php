<?php
/**
 * Settings manager for Mudrava RUM.
 *
 * @package MudravaRUM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings manager class for Mudrava RUM.
 */
class MDVRM_Settings {

	/**
	 * Option key.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'mdvrm_settings';

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	protected $defaults = array(
		'limit'                => 1000,
		'retention_days'       => 30,
		'sample_rate'          => 1.0,
		'excluded_roles'       => array( 'administrator', 'editor' ),
		'blacklist'            => array(),
		'report_schedule'      => 'daily',
		'alert_ttfb_threshold' => 2.0,
		'alert_consecutive'    => 5,
		'alert_min_interval'   => 3600,
		'alert_recipient'      => '',
	);

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all(): array {
		$stored   = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $stored, $this->defaults );

		if ( empty( $settings['alert_recipient'] ) ) {
			$settings['alert_recipient'] = get_option( 'admin_email' );
		}

		return $settings;
	}

	/**
	 * Get single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public function get( string $key ) {
		$settings = $this->all();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Update settings safely.
	 *
	 * @param array $data Incoming data.
	 * @return array Saved settings.
	 */
	public function update( array $data ): array {
		$settings = $this->all();

		if ( isset( $data['limit'] ) ) {
			$settings['limit'] = max( 1, absint( $data['limit'] ) );
		}

		if ( isset( $data['retention_days'] ) ) {
			$settings['retention_days'] = max( 1, absint( $data['retention_days'] ) );
		}

		if ( isset( $data['sample_rate'] ) ) {
			$rate                    = floatval( $data['sample_rate'] );
			$settings['sample_rate'] = max( 0, min( 1, $rate ) );
		}

		if ( isset( $data['excluded_roles'] ) ) {
			$roles                      = is_array( $data['excluded_roles'] ) ? $data['excluded_roles'] : explode( ',', $data['excluded_roles'] );
			$roles                      = array_map( 'sanitize_text_field', array_map( 'trim', $roles ) );
			$settings['excluded_roles'] = array_filter( $roles );
		}

		if ( isset( $data['blacklist'] ) ) {
			$blacklist             = is_array( $data['blacklist'] ) ? $data['blacklist'] : explode( "\n", str_replace( "\r", '', $data['blacklist'] ) );
			$blacklist             = array_map( 'sanitize_text_field', array_map( 'trim', $blacklist ) );
			$settings['blacklist'] = array_filter( $blacklist );
		}

		if ( isset( $data['report_schedule'] ) && in_array( $data['report_schedule'], array( 'daily', 'weekly' ), true ) ) {
			$settings['report_schedule'] = $data['report_schedule'];
		}

		if ( isset( $data['alert_ttfb_threshold'] ) ) {
			$settings['alert_ttfb_threshold'] = max( 0, floatval( $data['alert_ttfb_threshold'] ) );
		}

		if ( isset( $data['alert_consecutive'] ) ) {
			$settings['alert_consecutive'] = max( 1, absint( $data['alert_consecutive'] ) );
		}

		if ( isset( $data['alert_min_interval'] ) ) {
			$settings['alert_min_interval'] = max( 300, absint( $data['alert_min_interval'] ) );
		}

		if ( isset( $data['alert_recipient'] ) ) {
			$email = sanitize_email( $data['alert_recipient'] );
			if ( is_email( $email ) ) {
				$settings['alert_recipient'] = $email;
			}
		}

		update_option( self::OPTION_KEY, $settings );

		return $settings;
	}
}
