<?php
/**
 * Plugin Name:       Red Olive Cookie Opt-Out
 * Plugin URI:        https://redolive.com/
 * Description:        A handsome, on-brand cookie-consent bar with real script gating. Geo-aware: opt-in for EU/UK visitors, opt-out for US visitors (honors Global Privacy Control). Reusable across client sites with a settings page and an owner-selectable accent color.
 * Version:           1.5.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            Red Olive
 * Author URI:        https://redolive.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       red-olive-cookie-opt-out
 * Domain Path:       /languages
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ROCOO_VERSION', '1.5.0' );
define( 'ROCOO_FILE', __FILE__ );
define( 'ROCOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROCOO_URL', plugin_dir_url( __FILE__ ) );
define( 'ROCOO_OPTION', 'rocoo_settings' );
define( 'ROCOO_LOG_OPTION', 'rocoo_consent_log' );

require_once ROCOO_DIR . 'includes/Settings.php';
require_once ROCOO_DIR . 'includes/Modes.php';
require_once ROCOO_DIR . 'includes/Geo.php';
require_once ROCOO_DIR . 'includes/Consent.php';
require_once ROCOO_DIR . 'includes/Consent_Mode.php';
require_once ROCOO_DIR . 'includes/Script_Gate.php';
require_once ROCOO_DIR . 'includes/Frontend.php';
require_once ROCOO_DIR . 'includes/Admin.php';
require_once ROCOO_DIR . 'includes/Plugin.php';

/**
 * Self-hosted auto-updates from the public GitHub repo, via Plugin Update Checker.
 * Every install then sees the normal "update available" notice and can one-click
 * (or auto-) update. Guarded so a partial deploy can never fatal the site.
 */
if ( file_exists( ROCOO_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once ROCOO_DIR . 'plugin-update-checker/plugin-update-checker.php';
	$rocoo_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/redolivedev/ro-cookie-consent-plugin/',
		ROCOO_FILE,
		'red-olive-cookie-opt-out'
	);
	$rocoo_update_checker->setBranch( 'main' );
}

/**
 * On activation, seed default settings if none exist.
 */
function activate() {
	if ( false === get_option( ROCOO_OPTION ) ) {
		add_option( ROCOO_OPTION, Settings::defaults() );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

// Boot.
add_action( 'plugins_loaded', function () {
	( new Plugin() )->init();
} );
