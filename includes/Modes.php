<?php
/**
 * Modes: the one-choice "compliance level" that drives the plugin's legal posture.
 *
 * Each mode is a preset over the behavioral knobs the plugin already has, plus the
 * per-category opt-out defaults and GPC scope. Selecting a mode is the only decision
 * a site owner must make; the mode resolves the rest. An "advanced override" lets a
 * power user hand-tune the governed knobs instead.
 *
 * The three tiers trade tracking data against legal risk:
 *   bare_minimum    — opt-out, everything on; owner accepts the exposure.
 *   balanced        — opt-out, but ad/marketing pixels wait for consent; Google modeled.
 *   high_compliance — opt-in everywhere; nothing non-essential fires before consent.
 *
 * Fresh installs default to high_compliance (fail-closed) so a half-configured site
 * cannot silently leak. EU/UK visitors are always strict opt-in regardless of tier.
 *
 * Categories are Necessary (locked) / Analytics / Marketing — Analytics maps to GA4,
 * Marketing to Google Ads + the Meta pixel.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Modes {

	const DEFAULT_MODE = 'high_compliance';

	/**
	 * The selectable mode keys, strongest first.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array( 'high_compliance', 'balanced', 'bare_minimum' );
	}

	/**
	 * Resolve the active mode key from settings, falling back to the default.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function current( $settings ) {
		$mode = isset( $settings['compliance_mode'] ) ? $settings['compliance_mode'] : self::DEFAULT_MODE;
		return in_array( $mode, self::keys(), true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Plain-English card copy for the admin: a tagline plus pros/cons, written so a
	 * web designer can see exactly what each level does to Google Analytics, Google
	 * Ads, and the Meta pixel — including what happens when a visitor declines or
	 * ignores the banner.
	 *
	 * @return array<string,array{label:string,tagline:string,pros:string[],cons:string[]}>
	 */
	public static function meta() {
		return array(
			'high_compliance' => array(
				'label'   => __( 'Maximum Protection', 'red-olive-cookie-opt-out' ),
				'tagline' => __( 'Nothing loads until the visitor clicks Allow.', 'red-olive-cookie-opt-out' ),
				'pros'    => array(
					__( 'Strongest legal protection of the three', 'red-olive-cookie-opt-out' ),
					__( 'Best defense against pixel / "wiretapping" (CIPA) lawsuits', 'red-olive-cookie-opt-out' ),
					__( 'Same rule for every visitor (US and EU) — simplest to reason about', 'red-olive-cookie-opt-out' ),
				),
				'cons'    => array(
					__( 'Lowest data: GA4, Google Ads, and Meta track only visitors who accept', 'red-olive-cookie-opt-out' ),
					__( 'Visitors who ignore the banner or pick "Necessary only" are not tracked', 'red-olive-cookie-opt-out' ),
					__( 'Google Ads conversions are undercounted (you get Consent Mode modeled estimates)', 'red-olive-cookie-opt-out' ),
				),
			),
			'balanced'        => array(
				'label'   => __( 'Balanced Protection', 'red-olive-cookie-opt-out' ),
				'tagline' => __( 'Analytics runs; Google Ads & the Meta pixel wait for consent.', 'red-olive-cookie-opt-out' ),
				'pros'    => array(
					__( 'Google Analytics keeps running for US visitors by default', 'red-olive-cookie-opt-out' ),
					__( 'Blocks the Meta pixel & Google Ads until consent — the main lawsuit target', 'red-olive-cookie-opt-out' ),
					__( 'Google models the conversions missed from non-consenting visitors', 'red-olive-cookie-opt-out' ),
				),
				'cons'    => array(
					__( 'Google Ads still needs consent, so Ads conversions are undercounted', 'red-olive-cookie-opt-out' ),
					__( '"Necessary only" turns analytics off too', 'red-olive-cookie-opt-out' ),
					__( 'Needs a geo source to tell US from EU; EU/UK stay opt-in', 'red-olive-cookie-opt-out' ),
				),
			),
			'bare_minimum'    => array(
				'label'   => __( 'Basic Protection', 'red-olive-cookie-opt-out' ),
				'tagline' => __( 'US visitors are tracked immediately, with an opt-out.', 'red-olive-cookie-opt-out' ),
				'pros'    => array(
					__( 'Most complete data: GA4, Google Ads & Meta fire on the first US page view', 'red-olive-cookie-opt-out' ),
					__( 'Least disruption to your current marketing measurement', 'red-olive-cookie-opt-out' ),
				),
				'cons'    => array(
					__( 'Highest exposure to pixel / "wiretapping" (CIPA) lawsuits — you accept this risk', 'red-olive-cookie-opt-out' ),
					__( 'US visitors are tracked before they consent', 'red-olive-cookie-opt-out' ),
					__( 'EU/UK visitors stay opt-in regardless; needs a geo source', 'red-olive-cookie-opt-out' ),
				),
			),
		);
	}

	/**
	 * The governed knob values for a mode.
	 *
	 * @param string $mode Mode key.
	 * @return array
	 */
	public static function preset( $mode ) {
		switch ( $mode ) {
			case 'bare_minimum':
				return array(
					'force_optin'     => false, // geo decides (US opt-out, EU opt-in).
					'consent_mode'    => 'off',
					'log_enabled'     => 0,
					'optout_defaults' => array(
						'analytics' => true,
						'marketing' => true,
					),
					'gpc_scope'       => array( 'marketing' ),
					'block_level'     => 'none', // readiness warns only.
				);
			case 'balanced':
				return array(
					'force_optin'     => false, // geo decides; but ad/marketing default off in opt-out.
					'consent_mode'    => 'advanced',
					'log_enabled'     => 1,
					'optout_defaults' => array(
						'analytics' => true,
						'marketing' => false,
					),
					'gpc_scope'       => array( 'marketing', 'analytics' ),
					'block_level'     => 'marketing', // readiness fails if an ad/marketing tracker fires pre-consent.
				);
			case 'high_compliance':
			default:
				return array(
					'force_optin'     => true, // opt-in everywhere.
					'consent_mode'    => 'advanced',
					'log_enabled'     => 1,
					'optout_defaults' => array(
						'analytics' => false,
						'marketing' => false,
					),
					'gpc_scope'       => array( 'marketing', 'analytics' ),
					'block_level'     => 'all', // readiness fails if ANY non-essential tracker fires pre-consent.
				);
		}
	}

	/**
	 * Apply the active mode's governed values onto a settings array. The mode wins
	 * for the knobs it governs, unless the owner has enabled advanced_override.
	 *
	 * @param array $settings Settings (already defaults-merged).
	 * @return array
	 */
	public static function apply( $settings ) {
		if ( ! empty( $settings['advanced_override'] ) ) {
			return $settings;
		}
		$p = self::preset( self::current( $settings ) );

		// The mode owns force_mode when advanced override is off: high compliance is
		// opt-in everywhere; the other tiers let geo decide (US opt-out, EU/UK opt-in),
		// clearing any leftover forced mode so the tier actually takes effect.
		$settings['force_mode']   = $p['force_optin'] ? 'optin' : '';
		$settings['consent_mode'] = $p['consent_mode'];
		$settings['log_enabled']  = $p['log_enabled'];

		return $settings;
	}

	/**
	 * Per-category default firing for opt-out (US) visitors under the active mode.
	 *
	 * @param array $settings Settings.
	 * @return array<string,bool>
	 */
	public static function optout_defaults( $settings ) {
		$p = self::preset( self::current( $settings ) );
		return $p['optout_defaults'];
	}

	/**
	 * Categories a GPC signal opts the visitor out of, under the active mode.
	 *
	 * @param array $settings Settings.
	 * @return string[]
	 */
	public static function gpc_scope( $settings ) {
		$p = self::preset( self::current( $settings ) );
		return $p['gpc_scope'];
	}

	/**
	 * Readiness strictness for the active mode: 'all' | 'marketing' | 'none'.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public static function block_level( $settings ) {
		$p = self::preset( self::current( $settings ) );
		return $p['block_level'];
	}

	/**
	 * The per-tier checklist of what the site must satisfy — the artifact that tells
	 * designers/developers the minimum required for each level.
	 *
	 * @param string $mode Mode key.
	 * @return string[]
	 */
	public static function requirements( $mode ) {
		$common = array(
			__( 'Every tracker (analytics, ads, pixels) must run through this plugin\'s gate — not hard-coded in the theme, another plugin, or a Tag Manager container that loads tags itself.', 'red-olive-cookie-opt-out' ),
			__( 'A current privacy policy is linked in the banner.', 'red-olive-cookie-opt-out' ),
			__( 'A "Do Not Sell or Share My Personal Information" link is present site-wide (e.g. footer).', 'red-olive-cookie-opt-out' ),
		);
		switch ( $mode ) {
			case 'bare_minimum':
				return array_merge( $common, array(
					__( 'You accept that ad/marketing pixels load before consent for US visitors (highest suit exposure).', 'red-olive-cookie-opt-out' ),
					__( 'A geo source (e.g. Cloudflare) is configured so US gets opt-out and EU/UK opt-in.', 'red-olive-cookie-opt-out' ),
				) );
			case 'balanced':
				return array_merge( $common, array(
					__( 'The Meta pixel and ad tags are gated until consent (readiness must pass).', 'red-olive-cookie-opt-out' ),
					__( 'Google tags use Consent Mode so declined visitors are modeled, not tracked.', 'red-olive-cookie-opt-out' ),
					__( 'A geo source is configured so US gets opt-out and EU/UK opt-in.', 'red-olive-cookie-opt-out' ),
				) );
			case 'high_compliance':
			default:
				return array_merge( $common, array(
					__( 'No non-essential tracker fires before consent — for any visitor (readiness must pass).', 'red-olive-cookie-opt-out' ),
					__( 'Proof-of-consent logging is on.', 'red-olive-cookie-opt-out' ),
				) );
		}
	}
}
