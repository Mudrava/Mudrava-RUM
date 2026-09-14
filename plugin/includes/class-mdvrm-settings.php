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
		'trust_cf'             => 0,
		'trust_proxies'        => array(),
		'trust_auth_header'    => 0,
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
	/**
	 * Coerce loose user input to a boolean.
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $fallback Fallback.
	 * @return bool
	 */
	public static function to_bool( $value, bool $fallback = false ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			$v = strtolower( trim( $value ) );
			if ( in_array( $v, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $v, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}
		return $fallback;
	}

	/**
	 * Get a single setting value.
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
			$settings['limit'] = max( 1, intval( $data['limit'] ) );
		}

		if ( isset( $data['retention_days'] ) ) {
			$settings['retention_days'] = max( 1, intval( $data['retention_days'] ) );
		}

		if ( isset( $data['sample_rate'] ) ) {
			$rate                    = floatval( $data['sample_rate'] );
			$settings['sample_rate'] = (float) max( 0, min( 1, $rate ) );
		}

		if ( isset( $data['excluded_roles'] ) ) {
			$roles                      = is_array( $data['excluded_roles'] ) ? $data['excluded_roles'] : explode( ',', $data['excluded_roles'] );
			$roles                      = array_map( 'sanitize_text_field', array_map( 'trim', $roles ) );
			$settings['excluded_roles'] = array_filter( $roles );
		}

		if ( isset( $data['trust_cf'] ) ) {
			$settings['trust_cf'] = self::to_bool( $data['trust_cf'], false ) ? 1 : 0;
		}

		if ( isset( $data['trust_auth_header'] ) ) {
			$settings['trust_auth_header'] = self::to_bool( $data['trust_auth_header'], false ) ? 1 : 0;
		}

		if ( isset( $data['trust_proxies'] ) ) {
			$raw     = is_array( $data['trust_proxies'] ) ? $data['trust_proxies'] : preg_split( '/[\r\n,]+/', (string) $data['trust_proxies'] );
			$proxies = array();
			foreach ( $raw as $proxy ) {
				$proxy = trim( sanitize_text_field( $proxy ) );
				if ( '' !== $proxy && ( filter_var( $proxy, FILTER_VALIDATE_IP ) || self::valid_cidr( $proxy ) ) ) {
					$proxies[] = $proxy;
				}
			}
			$settings['trust_proxies'] = array_values( array_unique( $proxies ) );
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
	/**
	 * Validate an IPv4/IPv6 CIDR notation.
	 *
	 * @param string $cidr CIDR string.
	 * @return bool
	 */
	public static function valid_cidr( string $cidr ): bool {
		if ( ! preg_match( '#^([^/]+)/([0-9]{1,3})$#', $cidr, $match ) ) {
			return false;
		}
		$network = filter_var( $match[1], FILTER_VALIDATE_IP );
		$bits    = (int) $match[2];
		$is_v6   = ( false !== strpos( $match[1], ':' ) );
		$max     = $is_v6 ? 128 : 32;
		return false !== $network && $bits >= 0 && $bits <= $max;
	}

	/**
	 * Decide whether a connecting address is configured or local/private.
	 *
	 * @param string $ip Connecting IP address.
	 * @return bool
	 */
	public function is_trusted_proxy( string $ip ): bool {
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			$configured = $this->get( 'trust_proxies', array() );
			foreach ( (array) $configured as $proxy ) {
				if ( $ip === $proxy ) {
					return true;
				}
				if ( $this->ip_in_cidr( $ip, (string) $proxy ) ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/**
	 * Check whether an address belongs to a CIDR range.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr CIDR range.
	 * @return bool
	 */
	protected function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! preg_match( '#^([^/]+)/([0-9]{1,3})$#', $cidr, $match ) ) {
			return false;
		}
		$network = inet_pton( $match[1] );
		$client  = inet_pton( $ip );
		if ( false === $network || false === $client || strlen( $network ) !== strlen( $client ) ) {
			return false;
		}
		$bits      = (int) $match[2];
		$bytes     = intdiv( $bits, 8 );
		$remainder = $bits % 8;
		if ( 0 !== $remainder ) {
			$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;
			if ( 0 !== ( ( ord( $client[ $bytes ] ) & $mask ) ^ ( ord( $network[ $bytes ] ) & $mask ) ) ) {
				return false;
			}
		}
		return substr( $client, 0, $bytes ) === substr( $network, 0, $bytes );
	}
}
