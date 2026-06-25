<?php
/**
 * Consent_Mode: Google Consent Mode v2 integration.
 *
 * When advanced mode is enabled, this prints the consent "default" state in
 * <head> before any Google tag loads, loads gtag.js for the configured GA4 and
 * Google Ads IDs in a consent-aware state, and lets banner.js push a consent
 * "update" when the visitor chooses. On denial the Google tags fall back to
 * cookieless pings, so Google can model the lost conversions and sessions
 * instead of recording nothing.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Consent_Mode {

	/**
	 * Register hooks.
	 */
	public function init() {
		// Must run before any Google tag. Priority 1 prints it as early in <head>
		// as WordPress allows.
		add_action( 'wp_head', array( $this, 'print_head' ), 1 );
	}

	/**
	 * Whether advanced consent mode is on.
	 *
	 * @param array $settings Settings.
	 * @return bool
	 */
	public static function is_advanced( $settings ) {
		return 'advanced' === ( $settings['consent_mode'] ?? 'off' );
	}

	/**
	 * Print the consent default block (and load gtag.js) in <head>.
	 */
	public function print_head() {
		if ( is_admin() ) {
			return;
		}
		$settings = Settings::all();
		if ( ! self::is_advanced( $settings ) ) {
			return;
		}
		// Mirror Frontend::should_render() so the head block and banner stay in sync.
		if ( ! apply_filters( 'rocoo_should_render', true ) ) {
			return;
		}

		$default                    = self::initial_signals( $settings );
		$default['wait_for_update'] = 500; // ms a tag waits for our update before using the default.

		$ids = array();
		if ( ! empty( $settings['ga4_id'] ) ) {
			$ids[] = $settings['ga4_id'];
		}
		if ( ! empty( $settings['ads_id'] ) ) {
			$ids[] = $settings['ads_id'];
		}

		echo "\n<!-- Red Olive Cookie Opt-Out: Google Consent Mode v2 -->\n";
		echo "<script>\n";
		echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
		echo "gtag('consent','default'," . wp_json_encode( $default ) . ");\n";
		echo "gtag('set','url_passthrough',true);\n";
		echo "gtag('set','ads_data_redaction',true);\n";
		echo "</script>\n";

		if ( ! empty( $ids ) ) {
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_js( $ids[0] ) . "\"></script>\n";
			echo "<script>\ngtag('js',new Date());\n";
			foreach ( $ids as $id ) {
				echo "gtag('config','" . esc_js( $id ) . "');\n";
			}
			echo "</script>\n";
		}
	}

	/**
	 * Compute the initial consent signals for this request: the returning
	 * visitor's stored choice if present and current, otherwise the geo default.
	 *
	 * @param array $settings Settings.
	 * @return array<string,string> signal => granted|denied.
	 */
	public static function initial_signals( $settings ) {
		$cats = self::stored_cats( $settings );

		if ( null === $cats ) {
			// No valid stored choice: derive from geo + the active compliance mode.
			if ( 'optout' === Geo::mode( $settings ) ) {
				// US/opt-out: the mode decides which categories are on by default.
				$cats = Modes::optout_defaults( $settings );
			} else {
				// Opt-in (EU/UK, or High Compliance everywhere): nothing until chosen.
				$cats = array(
					'analytics' => false,
					'marketing' => false,
				);
			}
			// Honor GPC: opt out of the categories this mode maps the signal to.
			if ( ! empty( $settings['honor_gpc'] ) && Geo::gpc() ) {
				foreach ( Modes::gpc_scope( $settings ) as $gcat ) {
					$cats[ $gcat ] = false;
				}
			}
		}

		return self::signals_from_cats( $cats );
	}

	/**
	 * Read the visitor's stored consent cookie if it matches the current
	 * consent version.
	 *
	 * @param array $settings Settings.
	 * @return array<string,bool>|null Per-category booleans, or null when absent/stale.
	 */
	private static function stored_cats( $settings ) {
		if ( empty( $_COOKIE[ Consent::COOKIE ] ) ) {
			return null;
		}
		$data = json_decode( (string) wp_unslash( $_COOKIE[ Consent::COOKIE ] ), true );
		if ( ! is_array( $data ) || empty( $data['cats'] ) || ! is_array( $data['cats'] ) ) {
			return null;
		}
		// Ignore a stale choice from a previous category version.
		if ( (int) ( $data['v'] ?? 0 ) !== (int) $settings['consent_version'] ) {
			return null;
		}
		$out = array();
		foreach ( $data['cats'] as $key => $val ) {
			$out[ sanitize_key( $key ) ] = (bool) $val;
		}
		return $out;
	}

	/**
	 * Map category booleans to Google Consent Mode v2 signals. This mirrors the
	 * same mapping in assets/js/banner.js; keep them in step.
	 *
	 * @param array<string,bool> $cats Per-category booleans.
	 * @return array<string,string> signal => granted|denied.
	 */
	public static function signals_from_cats( $cats ) {
		$marketing = ! empty( $cats['marketing'] ) ? 'granted' : 'denied';
		$functional = ! empty( $cats['functional'] ) ? 'granted' : 'denied';

		return array(
			'ad_storage'              => $marketing,
			'ad_user_data'            => $marketing,
			'ad_personalization'      => $marketing,
			'analytics_storage'       => ! empty( $cats['analytics'] ) ? 'granted' : 'denied',
			'functionality_storage'   => $functional,
			'personalization_storage' => $functional,
			'security_storage'        => 'granted',
		);
	}
}
