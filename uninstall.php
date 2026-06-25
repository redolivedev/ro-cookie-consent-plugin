<?php
/**
 * Uninstall: remove all plugin data.
 *
 * @package RedOlive\CookieOptOut
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'rocoo_settings' );
delete_option( 'rocoo_consent_log' );
