<?php
/**
 * Telemetry: a small daily check-in to Red Olive's fleet inventory.
 *
 * Sends one non-blocking POST per day (plus one immediately after a version
 * change) to Red Olive's inventory so Red Olive knows which sites run this
 * plugin, what version they're on, and which page optimizers are active — so
 * a fleet incident (e.g. the v1.5.9 WP Rocket consent breakage) can be scoped
 * by query instead of guesswork.
 *
 * No visitor data is ever sent: the payload is site + environment facts only.
 * Disable with `define( 'ROCOO_DISABLE_TELEMETRY', true );` or the
 * `rocoo_telemetry_enabled` filter.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Telemetry {

	const ENDPOINT    = 'https://sign.redolive.com/rocoo-ping.php';
	const OPTION_LAST = 'rocoo_telemetry_last';
	const OPTION_VER  = 'rocoo_telemetry_ver';
	// Spam deterrence only — the plugin repo is public, so this token is not a
	// secret. The endpoint validates payload shape and rate-limits regardless.
	const TOKEN = 'rocoo-fleet-2026';

	/**
	 * Hook up. Runs on shutdown so it can never delay page output.
	 */
	public function init() {
		add_action( 'shutdown', array( $this, 'maybe_ping' ) );
	}

	/**
	 * Send the daily ping if due (or immediately after a version change).
	 */
	public function maybe_ping() {
		if ( defined( 'ROCOO_DISABLE_TELEMETRY' ) && ROCOO_DISABLE_TELEMETRY ) {
			return;
		}
		/**
		 * Filter whether telemetry pings are sent at all.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'rocoo_telemetry_enabled', true ) ) {
			return;
		}
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$last        = (int) get_option( self::OPTION_LAST, 0 );
		$last_ver    = (string) get_option( self::OPTION_VER, '' );
		$ver_changed = ROCOO_VERSION !== $last_ver;

		if ( ! $ver_changed && ( time() - $last ) < DAY_IN_SECONDS ) {
			return;
		}

		// Mark first so concurrent requests don't stampede the endpoint.
		update_option( self::OPTION_LAST, time(), false );
		update_option( self::OPTION_VER, ROCOO_VERSION, false );

		wp_remote_post(
			self::ENDPOINT,
			array(
				'blocking' => false,
				'timeout'  => 2,
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'X-Rocoo-Token' => self::TOKEN,
				),
				'body'     => wp_json_encode( self::payload() ),
			)
		);
	}

	/**
	 * Site + environment facts. Never anything about visitors.
	 *
	 * @return array
	 */
	public static function payload() {
		$settings = Settings::all();

		return array(
			'site_url'       => home_url(),
			'plugin_version' => ROCOO_VERSION,
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'multisite'      => is_multisite() ? 1 : 0,
			'optimizers'     => self::optimizers(),
			'settings'       => array(
				'compliance_mode' => (string) ( $settings['compliance_mode'] ?? '' ),
				'consent_mode'    => (string) ( $settings['consent_mode'] ?? '' ),
				'force_mode'      => (string) ( $settings['force_mode'] ?? '' ),
				'geo_enabled'     => (int) ! empty( $settings['geo_enabled'] ),
				'log_enabled'     => (int) ! empty( $settings['log_enabled'] ),
				'wc_enabled'      => (int) ! empty( $settings['wc_enabled'] ),
				'wc_essential'    => (int) ! empty( $settings['wc_essential'] ),
			),
		);
	}

	/**
	 * Which page-optimizer / caching plugins are active — the field that lets
	 * an incident like the WP Rocket one be scoped with a single query.
	 *
	 * @return array<string,int>
	 */
	private static function optimizers() {
		return array(
			'wp_rocket'    => (int) defined( 'WP_ROCKET_VERSION' ),
			'litespeed'    => (int) defined( 'LSCWP_V' ),
			'w3tc'         => (int) defined( 'W3TC' ),
			'autoptimize'  => (int) defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
			'sg_optimizer' => (int) defined( 'SiteGround_Optimizer\VERSION' ),
			'wp_optimize'  => (int) defined( 'WPO_VERSION' ),
			'breeze'       => (int) defined( 'BREEZE_VERSION' ),
			'flying_press' => (int) defined( 'FLYING_PRESS_VERSION' ),
		);
	}
}
