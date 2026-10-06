<?php
/**
 * Geo: hand the browser what it needs to decide opt-in vs opt-out itself.
 *
 * Nothing here may vary by visitor in cached HTML: the decision is made in
 * assets/js/geo.js from Cloudflare's /cdn-cgi/trace, so a full-page cache
 * (Varnish, WP Rocket, a CDN) serves the same page to every region.
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
	 * Config for the in-browser resolver. Identical for every anonymous visitor;
	 * only 'qa' differs, and only for logged-in admins, whose pages are never
	 * served from the page cache.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public static function client_config( $settings ) {
		$force = '';
		if ( ! empty( $settings['force_mode'] ) && in_array( $settings['force_mode'], array( 'optin', 'optout' ), true ) ) {
			$force = $settings['force_mode'];
		} elseif ( empty( $settings['geo_enabled'] ) ) {
			$force = 'optin';
		}

		/**
		 * Force a country for every visitor (staging/QA only), e.g. via the
		 * ROCOO_FORCE_COUNTRY constant in wp-config.php.
		 *
		 * @param string $code Two-letter code, or ''.
		 */
		$test = apply_filters( 'rocoo_force_country', defined( 'ROCOO_FORCE_COUNTRY' ) ? (string) ROCOO_FORCE_COUNTRY : '' );
		$test = strtoupper( (string) $test );

		return array(
			'force' => $force,
			'optin' => self::OPTIN_COUNTRIES,
			'test'  => preg_match( '/^[A-Z]{2}$/', $test ) ? $test : '',
			'qa'    => current_user_can( 'manage_options' ),
		);
	}

	/**
	 * Print the resolver inline in <head> so the country lookup starts before
	 * any tag or the footer banner script.
	 *
	 * @param array $settings Settings.
	 */
	public static function print_resolver( $settings ) {
		$js = file_get_contents( ROCOO_DIR . 'assets/js/geo.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $js ) {
			return;
		}
		echo "\n<!-- Red Olive Cookie Opt-Out: geo -->\n";
		echo '<script nowprocket data-no-optimize="1" data-cfasync="false">window.ROCOO_GEO=' . wp_json_encode( self::client_config( $settings ) ) . ";\n";
		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static plugin file.
		echo "</script>\n";
	}

	/**
	 * Best-effort ISO country code from common CDN/host headers. Admin readiness
	 * display only; never use it to shape front-end output.
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
}
