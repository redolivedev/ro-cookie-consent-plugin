<?php
/**
 * Consent_Mode: Google Consent Mode v2 integration.
 *
 * When advanced mode is enabled, this prints an all-denied consent "default"
 * in <head> before any Google tag loads, loads gtag.js for the configured GA4 and
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

		// Denied for everyone: the HTML is cached, so it cannot know the visitor's
		// region or stored choice. banner.js sends the real state as an update
		// once geo.js resolves; the wait covers geo.js's 2s lookup timeout.
		$default                    = self::signals_from_cats( array() );
		$default['wait_for_update'] = 2500;

		$ids = array();
		if ( ! empty( $settings['ga4_id'] ) ) {
			$ids[] = $settings['ga4_id'];
		}
		if ( ! empty( $settings['ads_id'] ) ) {
			$ids[] = $settings['ads_id'];
		}

		// nowprocket / data-no-optimize / data-cfasync opt these tags out of
		// page-optimizer deferral (WP Rocket, LiteSpeed, Cloudflare Rocket
		// Loader). The consent default MUST run before any Google tag and
		// before banner.js pushes its update; a deferred shim inverts that
		// order and Google keeps the denied default after the visitor accepts.
		$attrs = 'nowprocket data-no-optimize="1" data-cfasync="false"';

		echo "\n<!-- Red Olive Cookie Opt-Out: Google Consent Mode v2 -->\n";
		echo '<script ' . $attrs . ">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
		echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
		echo "gtag('consent','default'," . wp_json_encode( $default ) . ");\n";
		echo "gtag('set','url_passthrough',true);\n";
		echo "gtag('set','ads_data_redaction',true);\n";
		echo "</script>\n";

		if ( ! empty( $ids ) ) {
			echo '<script ' . $attrs . ' async src="https://www.googletagmanager.com/gtag/js?id=' . esc_js( $ids[0] ) . "\"></script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
			echo '<script ' . $attrs . ">\ngtag('js',new Date());\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute string.
			foreach ( $ids as $id ) {
				echo "gtag('config','" . esc_js( $id ) . "');\n";
			}
			echo "</script>\n";
		}
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
