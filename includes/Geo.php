<?php
/**
 * Geo: resolve the visitor's consent mode (opt-in vs opt-out) and read the
 * Global Privacy Control signal.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Geo {

	/**
	 * Countries treated as opt-in (EU/EEA + UK).
	 *
	 * @var string[]
	 */
	const OPTIN_COUNTRIES = array(
		// EU.
		'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
		'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK',
		'SI', 'ES', 'SE',
		// EEA (non-EU).
		'IS', 'LI', 'NO',
		// UK + Switzerland (FADP mirrors GDPR closely).
		'GB', 'CH',
	);

	/**
	 * Resolve the effective consent mode for this request.
	 *
	 * @param array $settings Settings.
	 * @return string 'optin' | 'optout'.
	 */
	public static function mode( $settings ) {
		// Explicit override wins.
		if ( ! empty( $settings['force_mode'] ) && in_array( $settings['force_mode'], array( 'optin', 'optout' ), true ) ) {
			return $settings['force_mode'];
		}

		// Geo disabled and no override => strict opt-in everywhere.
		if ( empty( $settings['geo_enabled'] ) ) {
			return 'optin';
		}

		$country = self::country();

		// Unknown country => strict (opt-in).
		if ( '' === $country ) {
			return 'optin';
		}

		if ( in_array( $country, self::OPTIN_COUNTRIES, true ) ) {
			return 'optin';
		}

		// Everyone else (incl. US) gets opt-out.
		return 'optout';
	}

	/**
	 * Best-effort ISO country code from common CDN/host headers.
	 *
	 * @return string Two-letter uppercase code, or '' if unknown.
	 */
	public static function country() {
		$headers = array(
			'HTTP_CF_IPCOUNTRY',      // Cloudflare.
			'HTTP_X_GEO_COUNTRY',
			'HTTP_X_COUNTRY_CODE',
			'HTTP_GEOIP_COUNTRY_CODE',
			'GEOIP_COUNTRY_CODE',     // Apache mod_geoip / some hosts.
			'HTTP_X_APPENGINE_COUNTRY',
		);

		foreach ( $headers as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', wp_unslash( $_SERVER[ $key ] ) ), 0, 2 ) );
				// Cloudflare sends 'XX' or 'T1' for unknown/Tor.
				if ( 2 === strlen( $code ) && 'XX' !== $code && 'T1' !== $code ) {
					/**
					 * Filter the detected country code.
					 *
					 * @param string $code Two-letter code.
					 */
					return apply_filters( 'rocoo_country', $code );
				}
			}
		}

		return apply_filters( 'rocoo_country', '' );
	}

	/**
	 * Whether the request carries a Global Privacy Control opt-out signal.
	 *
	 * @return bool
	 */
	public static function gpc() {
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC'];
	}
}
