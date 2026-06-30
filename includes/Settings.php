<?php
/**
 * Settings: option schema, defaults, retrieval, and sanitization.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	/**
	 * Default settings. Used on activation and merged over saved values so new
	 * keys always have a value.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Consent version. Bumping this re-prompts every visitor.
			'consent_version' => 1,

			// Compliance level: the one choice that drives the legal posture.
			// See Modes. Fresh installs are high_compliance (fail-closed).
			'compliance_mode'   => Modes::DEFAULT_MODE,
			'advanced_override' => 0, // When 1, the governed knobs below are hand-set.

			// Appearance.
			'accent'          => '#ed1c24',
			'bg'              => '#131314',
			'bg_opacity'      => 100, // 0–100. <100 makes the bar see-through (with a blur).
			'text'            => '#ffffff',
			'position'        => 'bottom', // bottom | top.
			'layout'          => 'standard', // standard | compact. Compact is a slim, single-row bar about the height of a button.
			'heading'         => __( 'We value your privacy', 'red-olive-cookie-opt-out' ),
			'body'            => __( 'We use cookies to improve your experience and measure traffic. You choose what is on.', 'red-olive-cookie-opt-out' ),
			'allow_label'     => __( 'Allow all', 'red-olive-cookie-opt-out' ),
			'necessary_label' => __( 'Necessary only', 'red-olive-cookie-opt-out' ),
			'customize_label' => __( 'Customize', 'red-olive-cookie-opt-out' ),
			'save_label'      => __( 'Save preferences', 'red-olive-cookie-opt-out' ),
			'privacy_url'     => '',

			// Re-open label. The bar simply goes away once a choice is made; nothing
			// stays docked on screen. Let visitors re-open preferences via the
			// [rocoo_cookie_settings] shortcode, a menu/footer link with class
			// "rocoo-open", or window.roConsent.open(). This is that link's text.
			'handle_label'    => __( 'Cookie settings', 'red-olive-cookie-opt-out' ),

			// US opt-out affordance.
			'show_dns'        => 1,
			'dns_label'       => __( 'Do Not Sell or Share My Personal Information', 'red-olive-cookie-opt-out' ),

			// Behavior.
			'geo_enabled'     => 1,
			'force_mode'      => '', // '' (geo decides) | optin | optout.
			'honor_gpc'       => 1,
			'expiry_days'     => 180,

			// Google Consent Mode v2. 'off' = hard-block Google tags until consent.
			// 'advanced' = load tags consent-aware; declines fall back to cookieless
			// pings so Google can model the lost conversions/sessions.
			'consent_mode'    => 'off', // off | advanced.

			// Proof-of-consent server log (off by default; cookie-only otherwise).
			'log_enabled'     => 0,

			// Categories. 'necessary' is always present and locked on.
			'categories'      => array(
				'necessary'  => array(
					'enabled' => 1,
					'name'    => __( 'Strictly necessary', 'red-olive-cookie-opt-out' ),
					'desc'    => __( 'Required for the site to function. Always on.', 'red-olive-cookie-opt-out' ),
				),
				'analytics'  => array(
					'enabled' => 1,
					'name'    => __( 'Analytics', 'red-olive-cookie-opt-out' ),
					'desc'    => __( 'Help us understand how visitors use the site so we can improve it.', 'red-olive-cookie-opt-out' ),
				),
				'marketing'  => array(
					'enabled' => 1,
					'name'    => __( 'Marketing', 'red-olive-cookie-opt-out' ),
					'desc'    => __( 'Used to deliver and measure relevant ads (Google Ads, Meta pixel).', 'red-olive-cookie-opt-out' ),
				),
			),

			// Raw markup blobs gated per category (pasted by the site owner).
			'scripts'         => array(
				'analytics' => '',
				'marketing' => '',
			),

			// Presets.
			'ga4_id'          => '',
			'ads_id'          => '', // Google Ads conversion ID (AW-XXXXXXXXX); used by Consent Mode.
			'meta_pixel_id'   => '',
			'gtm_id'          => '', // Google Tag Manager container (GTM-XXXXXXX); the whole container is gated.
			'gtm_cat'         => 'marketing', // Category the GTM container is gated under (analytics | marketing).

			// WhatConverts call/lead tracking. When enabled, this plugin injects the
			// WhatConverts tracking script (gated) so it can replace the standalone
			// WhatConverts plugin, which loads the same script ungated (pre-consent).
			'wc_enabled'      => 0,
			'wc_profile_id'   => '', // WhatConverts Profile ID (digits).
			'wc_essential'    => 0, // When 1, WhatConverts loads UNGATED (before consent) so wc_* cookies set for every visitor — for a functional/CRM dependency. Must be disclosed in the privacy policy.
		);
	}

	/**
	 * Get merged settings (defaults <- saved).
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( ROCOO_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$merged = array_merge( self::defaults(), $saved );

		// Deep-merge the two nested arrays we care about.
		$merged['categories'] = self::merge_categories(
			self::defaults()['categories'],
			isset( $saved['categories'] ) && is_array( $saved['categories'] ) ? $saved['categories'] : array()
		);
		$merged['scripts'] = array_merge( self::defaults()['scripts'], isset( $saved['scripts'] ) && is_array( $saved['scripts'] ) ? $saved['scripts'] : array() );

		// Necessary is always enabled.
		$merged['categories']['necessary']['enabled'] = 1;

		// The chosen compliance level governs force_mode / consent_mode / logging.
		$merged = Modes::apply( $merged );

		return $merged;
	}

	/**
	 * The non-necessary category keys that are currently enabled.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function active_category_keys( $settings ) {
		$keys = array();
		foreach ( $settings['categories'] as $key => $cat ) {
			if ( 'necessary' === $key ) {
				continue;
			}
			if ( ! empty( $cat['enabled'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Merge saved category text/enabled over defaults without losing keys.
	 *
	 * @param array $defaults Default categories.
	 * @param array $saved    Saved categories.
	 * @return array
	 */
	private static function merge_categories( $defaults, $saved ) {
		$out = $defaults;
		foreach ( $saved as $key => $cat ) {
			if ( ! is_array( $cat ) ) {
				continue;
			}
			if ( ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array(
					'enabled' => 1,
					'name'    => $key,
					'desc'    => '',
				);
			}
			$out[ $key ]['enabled'] = empty( $cat['enabled'] ) ? 0 : 1;
			if ( isset( $cat['name'] ) ) {
				$out[ $key ]['name'] = $cat['name'];
			}
			if ( isset( $cat['desc'] ) ) {
				$out[ $key ]['desc'] = $cat['desc'];
			}
		}
		return $out;
	}

	/**
	 * Sanitize a posted settings array into a storable one.
	 *
	 * @param array $in      Raw posted values (already unslashed by caller).
	 * @param array $current Current settings (for the consent_version baseline).
	 * @return array
	 */
	public static function sanitize( $in, $current ) {
		$d   = self::defaults();
		$out = $current; // Start from current so unsent keys persist.

		$out['accent']     = self::hex( $in['accent'] ?? '', $d['accent'] );
		$out['bg']         = self::hex( $in['bg'] ?? '', $d['bg'] );
		$out['bg_opacity'] = max( 0, min( 100, (int) ( $in['bg_opacity'] ?? $d['bg_opacity'] ) ) );
		$out['text']       = self::hex( $in['text'] ?? '', $d['text'] );

		$out['position']        = ( isset( $in['position'] ) && 'top' === $in['position'] ) ? 'top' : 'bottom';
		$out['layout']          = ( isset( $in['layout'] ) && 'compact' === $in['layout'] ) ? 'compact' : 'standard';
		$out['heading']         = sanitize_text_field( $in['heading'] ?? $d['heading'] );
		$out['body']            = wp_kses( $in['body'] ?? $d['body'], self::body_allowed_html() );
		$out['allow_label']     = sanitize_text_field( $in['allow_label'] ?? $d['allow_label'] );
		$out['necessary_label'] = sanitize_text_field( $in['necessary_label'] ?? $d['necessary_label'] );
		$out['customize_label'] = sanitize_text_field( $in['customize_label'] ?? $d['customize_label'] );
		$out['save_label']      = sanitize_text_field( $in['save_label'] ?? $d['save_label'] );
		$out['privacy_url']     = esc_url_raw( $in['privacy_url'] ?? '' );

		$out['handle_label'] = sanitize_text_field( $in['handle_label'] ?? $d['handle_label'] );

		$out['show_dns']  = empty( $in['show_dns'] ) ? 0 : 1;
		$out['dns_label'] = sanitize_text_field( $in['dns_label'] ?? $d['dns_label'] );

		$out['geo_enabled'] = empty( $in['geo_enabled'] ) ? 0 : 1;
		$force              = $in['force_mode'] ?? '';
		$out['force_mode']  = in_array( $force, array( 'optin', 'optout' ), true ) ? $force : '';
		$out['honor_gpc']   = empty( $in['honor_gpc'] ) ? 0 : 1;
		$out['expiry_days'] = max( 1, min( 3650, (int) ( $in['expiry_days'] ?? $d['expiry_days'] ) ) );
		$out['log_enabled'] = empty( $in['log_enabled'] ) ? 0 : 1;

		$out['consent_mode'] = ( isset( $in['consent_mode'] ) && 'advanced' === $in['consent_mode'] ) ? 'advanced' : 'off';

		$mode                     = $in['compliance_mode'] ?? Modes::DEFAULT_MODE;
		$out['compliance_mode']   = in_array( $mode, Modes::keys(), true ) ? $mode : Modes::DEFAULT_MODE;
		$out['advanced_override'] = empty( $in['advanced_override'] ) ? 0 : 1;

		// Categories.
		$cats = self::all()['categories'];
		if ( isset( $in['categories'] ) && is_array( $in['categories'] ) ) {
			foreach ( $cats as $key => $cat ) {
				$posted               = $in['categories'][ $key ] ?? array();
				$cats[ $key ]['name'] = sanitize_text_field( $posted['name'] ?? $cat['name'] );
				$cats[ $key ]['desc'] = sanitize_textarea_field( $posted['desc'] ?? $cat['desc'] );
				if ( 'necessary' === $key ) {
					$cats[ $key ]['enabled'] = 1;
				} else {
					$cats[ $key ]['enabled'] = empty( $posted['enabled'] ) ? 0 : 1;
				}
			}
		}
		$out['categories'] = $cats;

		// Script blobs. Admin-only (manage_options); stored raw like a custom-HTML
		// widget. The admin form base64-encodes these on submit (scripts_b64) so a
		// WAF such as Wordfence does not 403 the save on seeing a raw <script> in
		// the request; we decode here. Plain 'scripts' is still accepted (fallback
		// when JS is unavailable).
		$scripts = self::all()['scripts'];
		$enc     = ( isset( $in['scripts_b64'] ) && is_array( $in['scripts_b64'] ) ) ? $in['scripts_b64'] : array();
		foreach ( array( 'analytics', 'marketing' ) as $key ) {
			if ( array_key_exists( $key, $enc ) ) {
				$decoded = base64_decode( (string) $enc[ $key ], true );
				if ( false !== $decoded ) {
					$scripts[ $key ] = trim( $decoded );
				}
			} elseif ( isset( $in['scripts'][ $key ] ) ) {
				$scripts[ $key ] = trim( (string) $in['scripts'][ $key ] );
			}
		}
		$out['scripts'] = $scripts;

		$out['ga4_id']        = preg_replace( '/[^A-Za-z0-9\-]/', '', $in['ga4_id'] ?? '' );
		$out['ads_id']        = preg_replace( '/[^A-Za-z0-9\-]/', '', $in['ads_id'] ?? '' );
		$out['meta_pixel_id'] = preg_replace( '/[^0-9]/', '', $in['meta_pixel_id'] ?? '' );
		$out['gtm_id']        = preg_replace( '/[^A-Za-z0-9\-]/', '', $in['gtm_id'] ?? '' );
		$gtm_cat              = $in['gtm_cat'] ?? 'marketing';
		$out['gtm_cat']       = in_array( $gtm_cat, array( 'analytics', 'marketing' ), true ) ? $gtm_cat : 'marketing';

		$out['wc_enabled']    = empty( $in['wc_enabled'] ) ? 0 : 1;
		$out['wc_profile_id'] = preg_replace( '/[^0-9]/', '', $in['wc_profile_id'] ?? '' );
		$out['wc_essential']  = empty( $in['wc_essential'] ) ? 0 : 1;

		// Bump the consent version whenever the set of enabled categories changes,
		// so returning visitors are re-prompted.
		$old_sig      = self::category_signature( $current );
		$new_sig      = self::category_signature( $out );
		$mode_changed = ( ( $current['compliance_mode'] ?? Modes::DEFAULT_MODE ) !== $out['compliance_mode'] );
		$out['consent_version'] = (int) ( $current['consent_version'] ?? 1 );
		if ( $old_sig !== $new_sig || $mode_changed ) {
			$out['consent_version']++;
		}

		return $out;
	}

	/**
	 * A stable signature of which categories are enabled (drives re-prompting).
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	private static function category_signature( $settings ) {
		$on = array();
		if ( ! empty( $settings['categories'] ) && is_array( $settings['categories'] ) ) {
			foreach ( $settings['categories'] as $key => $cat ) {
				if ( ! empty( $cat['enabled'] ) ) {
					$on[] = $key;
				}
			}
		}
		sort( $on );
		return implode( ',', $on );
	}

	/**
	 * Validate a hex color, falling back to a default.
	 *
	 * @param string $value    Candidate.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function hex( $value, $fallback ) {
		$clean = sanitize_hex_color( $value );
		return $clean ? $clean : $fallback;
	}

	/**
	 * Allowed inline HTML for the banner body text.
	 *
	 * @return array
	 */
	private static function body_allowed_html() {
		return array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}
}
