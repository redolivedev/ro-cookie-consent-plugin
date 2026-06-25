<?php
/**
 * Plugin: wire the pieces together.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	/**
	 * Boot all components.
	 */
	public function init() {
		load_plugin_textdomain(
			'red-olive-cookie-opt-out',
			false,
			dirname( plugin_basename( ROCOO_FILE ) ) . '/languages'
		);

		( new Consent() )->init();
		( new Consent_Mode() )->init();
		( new Frontend() )->init();

		if ( is_admin() ) {
			( new Admin() )->init();
		}
	}
}
