<?php
/**
 * Consent: cookie naming, the AJAX proof-of-consent log, and log helpers.
 *
 * The authoritative consent state lives in a first-party cookie written by the
 * browser (see assets/js/banner.js). The server-side log is an optional,
 * minimal audit trail (no raw PII) used as proof of consent.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Consent {

	const COOKIE = 'rocoo_consent';
	const NONCE  = 'rocoo_log';
	const MAX    = 1000; // Cap stored log entries.

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_rocoo_log', array( $this, 'ajax_log' ) );
		add_action( 'wp_ajax_nopriv_rocoo_log', array( $this, 'ajax_log' ) );
	}

	/**
	 * Record a consent decision (only when logging is enabled).
	 */
	public function ajax_log() {
		$settings = Settings::all();
		if ( empty( $settings['log_enabled'] ) ) {
			wp_send_json_success( array( 'logged' => false ) );
		}

		check_ajax_referer( self::NONCE );

		$cats_raw = isset( $_POST['cats'] ) ? sanitize_text_field( wp_unslash( $_POST['cats'] ) ) : '';
		$decoded  = json_decode( $cats_raw, true );
		$cats     = array();
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $key => $val ) {
				$cats[ sanitize_key( $key ) ] = $val ? 1 : 0;
			}
		}

		$entry = array(
			'h'    => self::visitor_hash(),
			'cats' => $cats,
			'mode' => isset( $_POST['mode'] ) && 'optout' === $_POST['mode'] ? 'optout' : 'optin',
			'v'    => isset( $_POST['v'] ) ? (int) $_POST['v'] : 0,
			'ts'   => time(),
		);

		$log = get_option( ROCOO_LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = $entry;
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( ROCOO_LOG_OPTION, $log, false );

		wp_send_json_success( array( 'logged' => true ) );
	}

	/**
	 * A non-reversible visitor fingerprint (hashed IP + UA). No raw PII stored.
	 *
	 * @return string
	 */
	private static function visitor_hash() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( wp_hash( $ip . '|' . $ua ), 0, 16 );
	}

	/**
	 * Read the stored log.
	 *
	 * @return array
	 */
	public static function log() {
		$log = get_option( ROCOO_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clear the stored log.
	 */
	public static function clear_log() {
		update_option( ROCOO_LOG_OPTION, array(), false );
	}
}
