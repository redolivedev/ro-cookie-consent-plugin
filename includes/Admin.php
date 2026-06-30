<?php
/**
 * Admin: settings page (Setup / Appearance / Advanced / Records).
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const SLUG  = 'rocoo';
	const NONCE = 'rocoo_save_settings';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_rocoo_save', array( $this, 'save' ) );
		add_action( 'admin_post_rocoo_accept_terms', array( $this, 'accept_terms' ) );
		add_action( 'admin_post_rocoo_clear_log', array( $this, 'clear_log' ) );
		add_action( 'admin_post_rocoo_export_log', array( $this, 'export_log' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ROCOO_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add the top-level menu.
	 */
	public function menu() {
		add_menu_page(
			__( 'Cookie Opt-Out', 'red-olive-cookie-opt-out' ),
			__( 'Cookie Opt-Out', 'red-olive-cookie-opt-out' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-shield-alt',
			80
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url  = admin_url( 'admin.php?page=' . self::SLUG );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'red-olive-cookie-opt-out' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Enqueue color picker + our admin assets on our page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'rocoo-admin', ROCOO_URL . 'assets/css/admin.css', array(), ROCOO_VERSION );
		wp_enqueue_script( 'rocoo-admin', ROCOO_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), ROCOO_VERSION, true );
	}

	/**
	 * Handle the settings form submission.
	 */
	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'red-olive-cookie-opt-out' ) );
		}
		check_admin_referer( self::NONCE );

		$current = Settings::all();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw   = isset( $_POST['rocoo'] ) ? wp_unslash( $_POST['rocoo'] ) : array();
		$clean = Settings::sanitize( is_array( $raw ) ? $raw : array(), $current );
		update_option( ROCOO_OPTION, $clean );

		$reprompted = ( (int) $clean['consent_version'] !== (int) $current['consent_version'] );
		wp_safe_redirect( add_query_arg(
			array(
				'page'       => self::SLUG,
				'updated'    => '1',
				'reprompted' => $reprompted ? '1' : '0',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Record the one-time use & liability disclaimer acceptance from the first-run
	 * gate, then return to the settings page. Stores who / when / which plugin
	 * version, once, as proof the owner accepted before configuring anything.
	 */
	public function accept_terms() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'red-olive-cookie-opt-out' ) );
		}
		check_admin_referer( 'rocoo_accept_terms' );

		// Only record when the box was actually ticked, and never overwrite a
		// prior acceptance. If unticked, we fall through and the gate re-renders.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( ! empty( $_POST['terms_ack'] ) && ! get_option( 'rocoo_terms_ack' ) ) {
			$user = wp_get_current_user();
			update_option(
				'rocoo_terms_ack',
				array(
					'user'    => $user ? $user->user_login : '',
					'ts'      => time(),
					'version' => ROCOO_VERSION,
				),
				false
			);
		}

		$accepted = (bool) get_option( 'rocoo_terms_ack' );
		wp_safe_redirect( add_query_arg(
			array(
				'page'    => self::SLUG,
				'welcome' => $accepted ? '1' : '0',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Clear the consent log.
	 */
	public function clear_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'red-olive-cookie-opt-out' ) );
		}
		check_admin_referer( 'rocoo_clear_log' );
		Consent::clear_log();
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'records', 'cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Export the consent log as CSV.
	 */
	public function export_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'red-olive-cookie-opt-out' ) );
		}
		check_admin_referer( 'rocoo_export_log' );

		$log = Consent::log();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=rocoo-consent-log.csv' );

		// Neutralize spreadsheet formula injection: a cell beginning with one of
		// these is treated as a formula by Excel/Sheets, so prefix it with a quote.
		$csv_safe = function ( $value ) {
			$value = (string) $value;
			if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
				$value = "'" . $value;
			}
			return $value;
		};

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'visitor_hash', 'mode', 'consent_version', 'timestamp_utc', 'categories' ) );
		foreach ( $log as $row ) {
			$cats = array();
			if ( ! empty( $row['cats'] ) && is_array( $row['cats'] ) ) {
				foreach ( $row['cats'] as $k => $v ) {
					$cats[] = $k . '=' . ( $v ? '1' : '0' );
				}
			}
			fputcsv( $out, array_map( $csv_safe, array(
				$row['h'] ?? '',
				$row['mode'] ?? '',
				$row['v'] ?? '',
				isset( $row['ts'] ) ? gmdate( 'Y-m-d H:i:s', (int) $row['ts'] ) : '',
				implode( ' ', $cats ),
			) ) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Build the at-a-glance "Setup status" rows for the Setup tab.
	 *
	 * Each row is [ state, label, value ]. state is:
	 *   ok   — good (green check),
	 *   warn — needs attention (amber); these are what the overall badge counts,
	 *   info — neutral, an expected/optional state (e.g. Consent Mode Off on Basic).
	 *
	 * This is a configuration readiness check, computed from settings + the
	 * current request headers; the deeper live "scan the actual pages" tripwire
	 * is a separate follow-up.
	 *
	 * @param array $s Settings.
	 * @return array<int,array{state:string,label:string,value:string}>
	 */
	private function readiness_rows( $s ) {
		$mode       = Modes::current( $s );
		$meta       = Modes::meta();
		$is_cm      = Consent_Mode::is_advanced( $s );
		$country    = Geo::country();
		$relies_geo = ( 'high_compliance' !== $mode ); // Basic/Balanced need geo to tell US from EU.

		$rows = array();

		// Protection level — always selected.
		$rows[] = array(
			'state' => 'ok',
			'label' => __( 'Protection level', 'red-olive-cookie-opt-out' ),
			'value' => $meta[ $mode ]['label'],
		);

		// Use & liability disclaimer acceptance.
		$rocoo_terms = get_option( 'rocoo_terms_ack' );
		if ( is_array( $rocoo_terms ) && ! empty( $rocoo_terms['ts'] ) ) {
			$rows[] = array(
				'state' => 'ok',
				'label' => __( 'Disclaimer', 'red-olive-cookie-opt-out' ),
				/* translators: 1: admin username, 2: date. */
				'value' => sprintf( __( 'Accepted by %1$s on %2$s', 'red-olive-cookie-opt-out' ), $rocoo_terms['user'] ? $rocoo_terms['user'] : __( 'an administrator', 'red-olive-cookie-opt-out' ), gmdate( 'M j, Y', (int) $rocoo_terms['ts'] ) ),
			);
		} else {
			$rows[] = array(
				'state' => 'warn',
				'label' => __( 'Disclaimer', 'red-olive-cookie-opt-out' ),
				'value' => __( 'Not yet accepted — review and accept it at the top of Setup', 'red-olive-cookie-opt-out' ),
			);
		}

		// Google Consent Mode.
		$rows[] = $is_cm
			? array( 'state' => 'ok', 'label' => __( 'Google Consent Mode', 'red-olive-cookie-opt-out' ), 'value' => __( 'On — models the visitors who decline', 'red-olive-cookie-opt-out' ) )
			: array( 'state' => 'info', 'label' => __( 'Google Consent Mode', 'red-olive-cookie-opt-out' ), 'value' => __( 'Off — not used at this level', 'red-olive-cookie-opt-out' ) );

		// Tracking codes — chips of what's connected.
		// List only what IS connected — never show an absent tracker as a "miss".
		// A GTM container usually carries GA4 / Google Ads / Meta inside it, so
		// flagging those as missing when GTM is set would be misleading.
		$has_gtm  = ! empty( $s['gtm_id'] );
		$has_blob = ! empty( $s['scripts']['analytics'] ) || ! empty( $s['scripts']['marketing'] );
		$wc_gated = ! empty( $s['wc_enabled'] ) && ! empty( $s['wc_profile_id'] );
		$present  = array();
		if ( $has_gtm ) {
			$present[] = 'GTM';
		}
		if ( ! empty( $s['ga4_id'] ) ) {
			$present[] = 'GA4';
		}
		if ( ! empty( $s['ads_id'] ) ) {
			$present[] = __( 'Google Ads', 'red-olive-cookie-opt-out' );
		}
		if ( ! empty( $s['meta_pixel_id'] ) ) {
			$present[] = __( 'Meta', 'red-olive-cookie-opt-out' );
		}
		if ( $wc_gated ) {
			$present[] = 'WhatConverts';
		}
		if ( $has_blob ) {
			$present[] = __( 'custom scripts', 'red-olive-cookie-opt-out' );
		}
		$any_id = $has_gtm || $wc_gated || ! empty( $s['ga4_id'] ) || ! empty( $s['ads_id'] ) || ! empty( $s['meta_pixel_id'] );

		if ( ! $present ) {
			$rows[] = array( 'state' => 'warn', 'label' => __( 'Tracking codes', 'red-olive-cookie-opt-out' ), 'value' => __( 'None connected yet — add your IDs below', 'red-olive-cookie-opt-out' ) );
		} elseif ( ! $any_id && $has_blob ) {
			$rows[] = array( 'state' => 'ok', 'label' => __( 'Tracking codes', 'red-olive-cookie-opt-out' ), 'value' => __( 'Gated via custom scripts (no ID fields used)', 'red-olive-cookie-opt-out' ) );
		} else {
			/* translators: %s: comma-separated list of connected trackers. */
			$value = sprintf( __( 'Connected: %s', 'red-olive-cookie-opt-out' ), implode( ', ', $present ) );
			if ( $has_gtm ) {
				$value .= ' — ' . __( 'GA4, Google Ads & Meta tags typically run inside the GTM container', 'red-olive-cookie-opt-out' );
			}
			$rows[] = array( 'state' => 'ok', 'label' => __( 'Tracking codes', 'red-olive-cookie-opt-out' ), 'value' => $value );
		}

		// WhatConverts: catch the common gap where the standalone plugin loads it
		// ungated (before consent), or where both this plugin and the standalone
		// plugin load it (double-fire).
		$wc_native = in_array( 'whatconverts/whatconverts.php', (array) get_option( 'active_plugins', array() ), true );
		if ( $wc_native && $wc_gated ) {
			$rows[] = array( 'state' => 'warn', 'label' => 'WhatConverts', 'value' => __( 'Loading twice — this plugin now gates it, so deactivate the standalone WhatConverts plugin.', 'red-olive-cookie-opt-out' ) );
		} elseif ( $wc_native ) {
			$rows[] = array( 'state' => 'warn', 'label' => 'WhatConverts', 'value' => __( 'Active but ungated — it fires before consent. Add its Profile ID below, enable it, then deactivate the standalone WhatConverts plugin.', 'red-olive-cookie-opt-out' ) );
		} elseif ( $wc_gated && ! empty( $s['wc_essential'] ) ) {
			$rows[] = array( 'state' => 'warn', 'label' => 'WhatConverts', 'value' => __( 'Loads BEFORE consent (essential) — wc_* cookies are set for every visitor. Intended for a CRM/lead-tracking dependency; make sure your privacy policy discloses it.', 'red-olive-cookie-opt-out' ) );
		} elseif ( $wc_gated ) {
			$rows[] = array( 'state' => 'ok', 'label' => 'WhatConverts', 'value' => __( 'Gated by this plugin', 'red-olive-cookie-opt-out' ) );
		}

		// Privacy policy.
		$rows[] = ! empty( $s['privacy_url'] )
			? array( 'state' => 'ok', 'label' => __( 'Privacy policy', 'red-olive-cookie-opt-out' ), 'value' => __( 'Linked', 'red-olive-cookie-opt-out' ) )
			: array( 'state' => 'warn', 'label' => __( 'Privacy policy', 'red-olive-cookie-opt-out' ), 'value' => __( 'Add your URL below (step 3)', 'red-olive-cookie-opt-out' ) );

		// "Do Not Sell or Share" link (matters for US opt-out visitors).
		$rows[] = ! empty( $s['show_dns'] )
			? array( 'state' => 'ok', 'label' => __( '"Do Not Sell or Share" link', 'red-olive-cookie-opt-out' ), 'value' => __( 'Shown to US (opt-out) visitors', 'red-olive-cookie-opt-out' ) )
			: array( 'state' => 'warn', 'label' => __( '"Do Not Sell or Share" link', 'red-olive-cookie-opt-out' ), 'value' => __( 'Off — turn on under Advanced › Behavior', 'red-olive-cookie-opt-out' ) );

		// Geo detection.
		if ( '' !== $country ) {
			/* translators: %s: two-letter country code, e.g. US. */
			$rows[] = array( 'state' => 'ok', 'label' => __( 'Geo detection', 'red-olive-cookie-opt-out' ), 'value' => sprintf( __( 'Detected: %s', 'red-olive-cookie-opt-out' ), $country ) );
		} elseif ( $relies_geo ) {
			$rows[] = array( 'state' => 'warn', 'label' => __( 'Geo detection', 'red-olive-cookie-opt-out' ), 'value' => __( 'No country header, so this level can\'t tell US from EU — every visitor (incl. US) is treated as opt-in, the same as Maximum. Put the site behind Cloudflare (free) to enable US opt-out, or just use Maximum Protection.', 'red-olive-cookie-opt-out' ) );
		} else {
			$rows[] = array( 'state' => 'info', 'label' => __( 'Geo detection', 'red-olive-cookie-opt-out' ), 'value' => __( 'Not detected — fine at this level (opt-in everywhere).', 'red-olive-cookie-opt-out' ) );
		}

		// Proof-of-consent log.
		$rows[] = ! empty( $s['log_enabled'] )
			? array( 'state' => 'ok', 'label' => __( 'Proof-of-consent log', 'red-olive-cookie-opt-out' ), 'value' => __( 'On', 'red-olive-cookie-opt-out' ) )
			: array( 'state' => 'info', 'label' => __( 'Proof-of-consent log', 'red-olive-cookie-opt-out' ), 'value' => __( 'Off (cookie record only)', 'red-olive-cookie-opt-out' ) );

		// --- Deployment environment checks: the real-site risks that silently
		// bypass the gate. Detected from the active-plugins list (best effort —
		// host/CDN caches and per-plugin settings aren't visible from here). ---
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$detect = function ( $map ) use ( $active ) {
			$found = array();
			foreach ( $map as $file => $label ) {
				if ( in_array( $file, $active, true ) ) {
					$found[] = $label;
				}
			}
			return $found;
		};

		// #2 — tag injectors that fire OUTSIDE this plugin's gate (pre-consent),
		// which makes the banner decorative for whatever they load.
		$injectors = $detect( array(
			'google-site-kit/google-site-kit.php'                                               => 'Site Kit',
			'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => 'GTM4WP',
			'google-analytics-for-wordpress/googleanalytics.php'                                 => 'MonsterInsights',
			'ga-google-analytics/ga-google-analytics.php'                                        => 'GA Google Analytics',
			'google-analytics-dashboard-for-wp/gadwp.php'                                        => 'ExactMetrics',
			'pixelyoursite/pixelyoursite.php'                                                    => 'PixelYourSite',
			'header-footer-code-manager/header-footer-code-manager.php'                          => 'Header Footer Code Manager',
			'insert-headers-and-footers/ihaf.php'                                                => 'WPCode',
		) );
		if ( $injectors ) {
			$rows[] = array(
				'state' => 'warn',
				'label' => __( 'Trackers outside this plugin', 'red-olive-cookie-opt-out' ),
				/* translators: %s: comma-separated plugin names. */
				'value' => sprintf( __( '%s can inject tags that fire before consent — this plugin can\'t gate those, so the banner is decorative for them. Route those tags through this plugin, or remove the other plugin.', 'red-olive-cookie-opt-out' ), implode( ', ', $injectors ) ),
			);
		}

		// #1 — full-page caching vs the geo/GPC decision baked into the HTML.
		// Maximum is cache-safe (same opt-in page for everyone); Basic/Balanced
		// are not unless the cache varies by country.
		$caches = $detect( array(
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
			'sg-cachepress/sg-cachepress.php'     => 'SG Optimizer',
			'cache-enabler/cache-enabler.php'     => 'Cache Enabler',
			'breeze/breeze.php'                   => 'Breeze',
			'wp-optimize/wp-optimize.php'         => 'WP-Optimize',
		) );
		if ( $relies_geo ) {
			$rows[] = $caches
				? array(
					'state' => 'warn',
					'label' => __( 'Page caching vs geo', 'red-olive-cookie-opt-out' ),
					/* translators: %s: comma-separated cache plugin names. */
					'value' => sprintf( __( '%s detected. This level bakes the US-vs-EU (and GPC) decision into the page, which a full-page cache can serve to the wrong region. Vary the cache by country, or switch to Maximum (cache-safe).', 'red-olive-cookie-opt-out' ), implode( ', ', $caches ) ),
				)
				: array(
					'state' => 'info',
					'label' => __( 'Page caching vs geo', 'red-olive-cookie-opt-out' ),
					'value' => __( 'This level bakes the US-vs-EU (and GPC) decision into the HTML. If any full-page cache sits in front (host or CDN, not just a plugin), make it vary by country — or use Maximum.', 'red-olive-cookie-opt-out' ),
				);
		} elseif ( $caches ) {
			$rows[] = array(
				'state' => 'ok',
				'label' => __( 'Page caching', 'red-olive-cookie-opt-out' ),
				/* translators: %s: comma-separated cache plugin names. */
				'value' => sprintf( __( '%s detected — fine at Maximum: every visitor gets the same opt-in page and per-visitor state is applied client-side.', 'red-olive-cookie-opt-out' ), implode( ', ', $caches ) ),
			);
		}

		// #3 — optimizers that can "delay JS until interaction," which would stop
		// banner.js from ever running (we can't read their setting, so: advise).
		$delayers = $detect( array(
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'perfmatters/perfmatters.php'         => 'Perfmatters',
			'flying-scripts/flying-scripts.php'   => 'Flying Scripts',
			'autoptimize/autoptimize.php'         => 'Autoptimize',
			'wp-meteor/wp-meteor.php'             => 'WP Meteor',
			'sg-cachepress/sg-cachepress.php'     => 'SG Optimizer',
		) );
		if ( $delayers ) {
			$rows[] = array(
				'state' => 'info',
				'label' => __( 'JS delay / defer', 'red-olive-cookie-opt-out' ),
				/* translators: %s: comma-separated plugin names. */
				'value' => sprintf( __( '%s can delay JavaScript until interaction. If that is on, exclude assets/js/banner.js — otherwise the bar never appears and gated tags never load.', 'red-olive-cookie-opt-out' ), implode( ', ', $delayers ) ),
			);
		}

		// Same tag in two places → it will load twice.
		$blob  = (string) ( $s['scripts']['analytics'] ?? '' ) . (string) ( $s['scripts']['marketing'] ?? '' );
		$dupes = array();
		foreach ( array( 'ga4_id' => 'GA4', 'ads_id' => 'Google Ads', 'meta_pixel_id' => 'Meta Pixel' ) as $k => $name ) {
			if ( ! empty( $s[ $k ] ) && false !== strpos( $blob, (string) $s[ $k ] ) ) {
				$dupes[] = $name;
			}
		}
		if ( $dupes ) {
			$rows[] = array(
				'state' => 'warn',
				'label' => __( 'Duplicate tag', 'red-olive-cookie-opt-out' ),
				/* translators: %s: comma-separated tag names. */
				'value' => sprintf( __( '%s is in both an ID field and a custom script — it will load twice. Keep each tag in one place.', 'red-olive-cookie-opt-out' ), implode( ', ', $dupes ) ),
			);
		}

		return $rows;
	}

	/**
	 * The full use & liability disclaimer text, as escaped HTML paragraphs.
	 *
	 * @return string
	 */
	private function disclaimer_html() {
		$paras = array(
			__( 'This plugin is provided "as is," without warranty of any kind, and you use it at your own risk. Red Olive accepts no responsibility for any loss, downtime, or website issues — including conflicts or incompatibilities with your theme, other plugins, hosting, or third-party scripts — arising from its installation, configuration, or use.', 'red-olive-cookie-opt-out' ),
			__( 'It provides a cookie-consent mechanism only. It is not legal advice and does not guarantee compliance with the GDPR, CCPA/CPRA, or any other privacy law. You remain solely responsible for your site\'s legal compliance — including accurate consent categories, a current and accurate privacy policy, honoring opt-out requests, and confirming the setup meets the laws that apply to your visitors.', 'red-olive-cookie-opt-out' ),
			__( 'By enabling and configuring this plugin, you accept these terms.', 'red-olive-cookie-opt-out' ),
		);
		$html = '';
		foreach ( $paras as $p ) {
			$html .= '<p>' . esc_html( $p ) . '</p>';
		}
		return $html;
	}

	/**
	 * First-run gate: the use & liability disclaimer as a content-area modal.
	 *
	 * Rendered INSTEAD of the tabs/settings form until the disclaimer is accepted,
	 * so access to the plugin's configuration is genuinely blocked server-side
	 * (not merely covered by a CSS overlay that JS could strip). The left admin
	 * menu stays usable so the owner can navigate away without accepting.
	 *
	 * @param string $url admin-post.php endpoint.
	 */
	private function render_gate( $url ) {
		?>
		<div class="wrap rocoo-admin">
			<h1 class="rocoo-admin__title">
				<img class="rocoo-admin__logo" src="<?php echo esc_url( ROCOO_URL . 'assets/img/ro-logo-mark.svg' ); ?>" alt="Red Olive" width="34" height="34" />
				<?php esc_html_e( 'Cookie Opt-Out', 'red-olive-cookie-opt-out' ); ?>
			</h1>

			<div class="rocoo-gate" role="dialog" aria-modal="true" aria-labelledby="rocoo-gate-title">
				<div class="rocoo-gate__backdrop" aria-hidden="true"></div>
				<div class="rocoo-gate__card">
					<h2 id="rocoo-gate-title" class="rocoo-gate__title"><?php esc_html_e( 'Before you go live — please read.', 'red-olive-cookie-opt-out' ); ?></h2>
					<div class="rocoo-gate__body">
						<?php echo wp_kses_post( $this->disclaimer_html() ); ?>
					</div>
					<form method="post" action="<?php echo esc_url( $url ); ?>" class="rocoo-gate__form">
						<input type="hidden" name="action" value="rocoo_accept_terms" />
						<?php wp_nonce_field( 'rocoo_accept_terms' ); ?>
						<label class="rocoo-gate__ack">
							<input type="checkbox" name="terms_ack" value="1" class="rocoo-gate__check" />
							<?php esc_html_e( 'I have read and accept this disclaimer.', 'red-olive-cookie-opt-out' ); ?>
						</label>
						<p class="rocoo-gate__actions">
							<button type="submit" class="button button-primary button-hero rocoo-gate__accept" disabled><?php esc_html_e( 'Accept & continue to setup', 'red-olive-cookie-opt-out' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = Settings::all();
		$url = admin_url( 'admin-post.php' );

		// First-run gate: block all configuration until the disclaimer is accepted.
		// Reuses the same acceptance record as the in-Setup panel, so a site that
		// already accepted (e.g. on an earlier version) is never re-blocked.
		$rocoo_terms_ack = get_option( 'rocoo_terms_ack' );
		if ( ! is_array( $rocoo_terms_ack ) || empty( $rocoo_terms_ack['ts'] ) ) {
			$this->render_gate( $url );
			return;
		}

		$rocoo_log_count = count( Consent::log() );
		?>
		<div class="wrap rocoo-admin">
			<h1 class="rocoo-admin__title">
				<img class="rocoo-admin__logo" src="<?php echo esc_url( ROCOO_URL . 'assets/img/ro-logo-mark.svg' ); ?>" alt="Red Olive" width="34" height="34" />
				<?php esc_html_e( 'Cookie Opt-Out', 'red-olive-cookie-opt-out' ); ?>
			</h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php esc_html_e( 'Settings saved.', 'red-olive-cookie-opt-out' ); ?>
					<?php if ( isset( $_GET['reprompted'] ) && '1' === $_GET['reprompted'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
						<strong><?php esc_html_e( 'Categories changed — all visitors will be re-prompted.', 'red-olive-cookie-opt-out' ); ?></strong>
					<?php endif; ?>
				</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Consent log cleared.', 'red-olive-cookie-opt-out' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['welcome'] ) && '1' === $_GET['welcome'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Disclaimer accepted — you\'re all set to configure the plugin.', 'red-olive-cookie-opt-out' ); ?></p></div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper rocoo-tabs">
				<a href="#setup" class="nav-tab nav-tab-active" data-tab="setup"><?php esc_html_e( 'Setup', 'red-olive-cookie-opt-out' ); ?></a>
				<a href="#appearance" class="nav-tab" data-tab="appearance"><?php esc_html_e( 'Appearance', 'red-olive-cookie-opt-out' ); ?></a>
				<a href="#records" class="nav-tab" data-tab="records"><?php esc_html_e( 'Records', 'red-olive-cookie-opt-out' ); ?> <span class="rocoo-tab-count">(<?php echo esc_html( number_format( $rocoo_log_count ) ); ?>)</span></a>
			</h2>

			<form method="post" action="<?php echo esc_url( $url ); ?>">
				<input type="hidden" name="action" value="rocoo_save" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<!-- SETUP -->
				<section class="rocoo-tabpane" data-pane="setup">
					<div class="rocoo-setup-layout">
					<div class="rocoo-setup-main">

					<?php
					// Acceptance is now collected by the first-run gate (render_gate),
					// so by the time the Setup form renders it is always accepted; show
					// the dated record for reference.
					$rocoo_terms = get_option( 'rocoo_terms_ack' );
					if ( is_array( $rocoo_terms ) && ! empty( $rocoo_terms['ts'] ) ) :
						?>
						<div class="rocoo-disclaimer is-accepted">
							<span class="rocoo-disclaimer__ok"><?php
								/* translators: 1: admin username, 2: date. */
								printf( esc_html__( '✓ Disclaimer accepted by %1$s on %2$s.', 'red-olive-cookie-opt-out' ), esc_html( $rocoo_terms['user'] ? $rocoo_terms['user'] : __( 'an administrator', 'red-olive-cookie-opt-out' ) ), esc_html( gmdate( 'M j, Y', (int) $rocoo_terms['ts'] ) ) );
							?></span>
							<details class="rocoo-disclaimer__more"><summary><?php esc_html_e( 'View', 'red-olive-cookie-opt-out' ); ?></summary><?php echo wp_kses_post( $this->disclaimer_html() ); ?></details>
						</div>
					<?php endif; ?>

					<?php
					$rocoo_modes   = Modes::meta();
					$rocoo_current = Modes::current( $s );
					?>
					<div class="rocoo-callout"><p><strong><?php esc_html_e( 'How this affects your tracking data:', 'red-olive-cookie-opt-out' ); ?></strong> <?php esc_html_e( 'Visitors who choose "Necessary only" are never individually tracked, on any level. What differs is the visitor who ignores the banner: Maximum tracks no one until they click Allow; Balanced lets Google Analytics run for US visitors by default (Google Ads and the Meta pixel still wait for consent); Basic tracks US visitors in full by default until they opt out. EU/UK visitors are always opt-in. More protection means fewer tracked visitors — and Maximum and Balanced add Google Consent Mode to model the ones you do not track.', 'red-olive-cookie-opt-out' ); ?></p></div>
					<div class="rocoo-card">
						<div class="rocoo-card__head"><span class="rocoo-card__num">1</span><span class="rocoo-card__title"><?php esc_html_e( 'Choose your protection level', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<div class="rocoo-modes">
						<?php foreach ( Modes::keys() as $mk ) : $m = $rocoo_modes[ $mk ]; ?>
							<label class="rocoo-mode-card<?php echo ( $rocoo_current === $mk ) ? ' is-selected' : ''; ?>" data-mode="<?php echo esc_attr( $mk ); ?>">
								<input type="radio" name="rocoo[compliance_mode]" value="<?php echo esc_attr( $mk ); ?>" <?php checked( $rocoo_current, $mk ); ?> />
								<span class="rocoo-mode-card__title"><?php echo esc_html( $m['label'] ); ?><?php echo ( 'high_compliance' === $mk ) ? ' <span class="rocoo-pill">' . esc_html__( 'recommended', 'red-olive-cookie-opt-out' ) . '</span>' : ''; ?></span>
								<span class="rocoo-mode-card__tagline"><?php echo esc_html( $m['tagline'] ); ?></span>
								<ul class="rocoo-pros">
									<?php foreach ( $m['pros'] as $pro ) : ?><li><?php echo esc_html( $pro ); ?></li><?php endforeach; ?>
								</ul>
								<ul class="rocoo-cons">
									<?php foreach ( $m['cons'] as $con ) : ?><li><?php echo esc_html( $con ); ?></li><?php endforeach; ?>
								</ul>
								<span class="rocoo-mode-card__cm"><?php echo 'advanced' === Modes::preset( $mk )['consent_mode'] ? esc_html__( 'Google Consent Mode: On — Google models the visitors who decline', 'red-olive-cookie-opt-out' ) : esc_html__( 'Google Consent Mode: Off — tags fire normally, nothing to model', 'red-olive-cookie-opt-out' ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
					</div></div>

					<div class="rocoo-card">
						<div class="rocoo-card__head"><span class="rocoo-card__num">2</span><span class="rocoo-card__title"><?php esc_html_e( 'Connect your trackers', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<p class="description"><?php esc_html_e( 'Enter your IDs and the plugin loads each one only when your level (and the visitor) allow it. Leave blank what you do not use.', 'red-olive-cookie-opt-out' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rocoo-ga4"><?php esc_html_e( 'Google Analytics 4 ID', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" id="rocoo-ga4" class="regular-text" name="rocoo[ga4_id]" value="<?php echo esc_attr( $s['ga4_id'] ); ?>" placeholder="G-XXXXXXXXXX" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-ads"><?php esc_html_e( 'Google Ads ID', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" id="rocoo-ads" class="regular-text" name="rocoo[ads_id]" value="<?php echo esc_attr( $s['ads_id'] ); ?>" placeholder="AW-XXXXXXXXX" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-meta"><?php esc_html_e( 'Meta Pixel ID', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" id="rocoo-meta" class="regular-text" name="rocoo[meta_pixel_id]" value="<?php echo esc_attr( $s['meta_pixel_id'] ); ?>" placeholder="1234567890" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-gtm"><?php esc_html_e( 'Google Tag Manager ID', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td>
								<input type="text" id="rocoo-gtm" class="regular-text" name="rocoo[gtm_id]" value="<?php echo esc_attr( $s['gtm_id'] ); ?>" placeholder="GTM-XXXXXXX" />
								<p class="description"><?php esc_html_e( 'The whole container is gated — nothing inside it fires until the visitor consents. For any single Google tag you want Consent Mode to model, use the GA4 / Google Ads fields above instead, and don\'t also put it in GTM, or it loads twice.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<td colspan="2" class="rocoo-wc-cell">
								<div class="rocoo-wc-box">
									<img class="rocoo-wc-box__logo" src="<?php echo esc_url( ROCOO_URL . 'assets/img/whatconverts-logo.svg' ); ?>" alt="WhatConverts" width="150" height="19" />
									<p class="rocoo-wc-box__field"><label for="rocoo-wc"><strong><?php esc_html_e( 'WhatConverts Profile ID', 'red-olive-cookie-opt-out' ); ?></strong></label><br/>
										<input type="text" id="rocoo-wc" class="regular-text" name="rocoo[wc_profile_id]" value="<?php echo esc_attr( $s['wc_profile_id'] ); ?>" placeholder="102281" /></p>
									<p><label><input type="checkbox" name="rocoo[wc_enabled]" value="1" <?php checked( ! empty( $s['wc_enabled'] ), true ); ?> /> <strong><?php esc_html_e( 'Load WhatConverts through this plugin, gated by consent.', 'red-olive-cookie-opt-out' ); ?></strong> <em class="rocoo-wc-hint"><?php esc_html_e( 'Turn this on, then deactivate the standalone "WhatConverts" plugin so it does not load twice or fire before consent.', 'red-olive-cookie-opt-out' ); ?></em></label></p>
									<p><label><input type="checkbox" name="rocoo[wc_essential]" value="1" <?php checked( ! empty( $s['wc_essential'] ), true ); ?> /> <strong><?php esc_html_e( 'Load before consent (essential).', 'red-olive-cookie-opt-out' ); ?></strong> <?php esc_html_e( 'WhatConverts runs ungated for every visitor, so its first-party wc_* cookies are set immediately — use this when a CRM/HubSpot routine depends on them. Everything else (Meta, Google Ads, GA4, GTM) stays gated. Only enable for first-party lead/call tracking you treat as functional, and disclose it in your privacy policy.', 'red-olive-cookie-opt-out' ); ?></label></p>
									<p class="description"><?php esc_html_e( 'The standalone WhatConverts plugin only injects this one tracking script, ungated. This replaces it and gates it. (Profile ID: WhatConverts › your profile › Tracking › Tracking Code.)', 'red-olive-cookie-opt-out' ); ?></p>
								</div>
							</td>
						</tr>
					</table>

					<div class="rocoo-callout rocoo-cm-note"><p><strong><?php esc_html_e( 'Will my ads still work — and does Google Consent Mode help?', 'red-olive-cookie-opt-out' ); ?></strong> <?php esc_html_e( 'Maximum and Balanced turn on Google Consent Mode v2: Google tags load consent-aware, so when a visitor declines you get modeled (estimated) conversions instead of nothing — that is the solace for the data you give up. Basic does not use it because tags fire normally for US visitors. Either way, your Google Ads tag records a real conversion once a visitor consents (immediately, in Basic).', 'red-olive-cookie-opt-out' ); ?></p></div>

					</div></div>

					<div class="rocoo-card">
						<div class="rocoo-card__head"><span class="rocoo-card__num">3</span><span class="rocoo-card__title"><?php esc_html_e( 'Google Consent Mode v2', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<p class="description"><?php esc_html_e( 'Your protection level sets this automatically — Maximum and Balanced turn it On, Basic leaves it Off. To set it by hand, turn on "Manual override" further down.', 'red-olive-cookie-opt-out' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Advanced Consent Mode', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label><input type="checkbox" name="rocoo[consent_mode]" value="advanced" <?php checked( $s['consent_mode'], 'advanced' ); ?> /> <?php esc_html_e( 'Load Google tags consent-aware (recommended when clients run Google Ads).', 'red-olive-cookie-opt-out' ); ?></label>
								<p class="description"><?php esc_html_e( 'When a visitor declines, the tags fall back to cookieless pings so Google can model the lost conversions and sessions instead of recording nothing. Off = hard-block Google tags until consent, with no modeling. This is applied only when Manual override is on; otherwise your protection level decides.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
					</table>
					<div class="rocoo-catbox">
						<h3><?php esc_html_e( 'Recommended settings to retain conversion tracking', 'red-olive-cookie-opt-out' ); ?></h3>
						<ol>
							<li><?php esc_html_e( 'Keep advanced Consent Mode on (Maximum or Balanced do this for you). This is what keeps modeled conversions flowing when visitors decline; with it off, declined visitors are recorded as nothing.', 'red-olive-cookie-opt-out' ); ?></li>
							<li><?php esc_html_e( 'Enter your GA4 and Google Ads IDs in their dedicated fields above, not in a raw custom script, so they load in the consent-aware head block.', 'red-olive-cookie-opt-out' ); ?></li>
							<li><?php esc_html_e( 'Keep Geo-aware mode and Honor GPC on (Manual override, below). US (opt-out) visitors are tracked by default; EU (opt-in) visitors are modeled until they accept.', 'red-olive-cookie-opt-out' ); ?></li>
							<li><?php esc_html_e( 'Keep the Analytics and Marketing categories enabled (Appearance → Categories). Analytics controls analytics_storage; Marketing controls ad_storage, ad_user_data, and ad_personalization.', 'red-olive-cookie-opt-out' ); ?></li>
							<li><?php esc_html_e( 'In GA4 and Google Ads, confirm Consent Mode is detected and that conversion and behavioral modeling are eligible. Google needs a minimum amount of traffic before modeling activates, so low-traffic sites may see little or no modeled data.', 'red-olive-cookie-opt-out' ); ?></li>
						</ol>
						<p class="description"><?php esc_html_e( 'Declined visitors are never tracked individually. You receive modeled, aggregate estimates, which is what keeps this compliant with the opt-out.', 'red-olive-cookie-opt-out' ); ?></p>
					</div>

					</div></div>

					<div class="rocoo-card">
						<div class="rocoo-card__head"><span class="rocoo-card__num">4</span><span class="rocoo-card__title"><?php esc_html_e( 'Link your privacy policy', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<p class="description"><?php esc_html_e( 'Shown as a "Privacy policy" link in the consent bar. A current, accurate privacy policy is a compliance requirement — not optional.', 'red-olive-cookie-opt-out' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rocoo-privacy"><?php esc_html_e( 'Privacy policy URL', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="url" class="regular-text" id="rocoo-privacy" name="rocoo[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" placeholder="https://example.com/privacy" /></td>
						</tr>
					</table>

					</div></div>

					<div class="rocoo-card rocoo-card--advanced">
						<div class="rocoo-card__head"><span class="rocoo-card__title"><?php esc_html_e( 'Advanced', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<p class="description"><?php esc_html_e( 'Paste a full <script> tag to gate it under a category (blocked until the visitor consents), or change how GTM and WhatConverts are gated. Most sites only need the ID fields above.', 'red-olive-cookie-opt-out' ); ?></p>
					<?php foreach ( array( 'analytics', 'marketing' ) as $rocoo_sk ) : ?>
						<p><label><strong><?php echo esc_html( ucfirst( $rocoo_sk ) ); ?></strong> <?php esc_html_e( 'scripts', 'red-olive-cookie-opt-out' ); ?><br/>
							<textarea class="large-text code" rows="4" name="rocoo[scripts][<?php echo esc_attr( $rocoo_sk ); ?>]" placeholder="&lt;script&gt;...&lt;/script&gt;"><?php echo esc_textarea( $s['scripts'][ $rocoo_sk ] ); ?></textarea></label></p>
					<?php endforeach; ?>
					<p><label><?php esc_html_e( 'Gate the GTM container under:', 'red-olive-cookie-opt-out' ); ?>
						<select name="rocoo[gtm_cat]">
							<option value="marketing" <?php selected( $s['gtm_cat'], 'marketing' ); ?>><?php esc_html_e( 'Marketing (safest — most containers include ad pixels)', 'red-olive-cookie-opt-out' ); ?></option>
							<option value="analytics" <?php selected( $s['gtm_cat'], 'analytics' ); ?>><?php esc_html_e( 'Analytics (only if the container has no ad/marketing tags)', 'red-olive-cookie-opt-out' ); ?></option>
						</select>
					</label></p>
					<p><label><?php esc_html_e( 'Gate WhatConverts under:', 'red-olive-cookie-opt-out' ); ?>
						<select name="rocoo[wc_cat]">
							<option value="marketing" <?php selected( $s['wc_cat'], 'marketing' ); ?>><?php esc_html_e( 'Marketing (recommended — lead/ad attribution, captures PII)', 'red-olive-cookie-opt-out' ); ?></option>
							<option value="analytics" <?php selected( $s['wc_cat'], 'analytics' ); ?>><?php esc_html_e( 'Analytics (treat as first-party measurement)', 'red-olive-cookie-opt-out' ); ?></option>
						</select>
					</label></p>

					</div></div>

					<div class="rocoo-card rocoo-card--advanced">
						<div class="rocoo-card__head"><span class="rocoo-card__title"><?php esc_html_e( 'Manual override', 'red-olive-cookie-opt-out' ); ?></span></div>
						<div class="rocoo-card__body">
					<div class="notice notice-info inline rocoo-note">
						<p><?php esc_html_e( 'These settings are normally controlled by your protection level. They only take effect when "Advanced override" below is on.', 'red-olive-cookie-opt-out' ); ?></p>
					</div>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Advanced override', 'red-olive-cookie-opt-out' ); ?></th>
							<td><label><input type="checkbox" name="rocoo[advanced_override]" value="1" <?php checked( ! empty( $s['advanced_override'] ), true ); ?> /> <?php esc_html_e( 'Ignore the protection level and use these manual settings instead.', 'red-olive-cookie-opt-out' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Geo-aware mode', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label><input type="checkbox" name="rocoo[geo_enabled]" value="1" <?php checked( $s['geo_enabled'], 1 ); ?> /> <?php esc_html_e( 'Opt-in for EU/UK visitors, opt-out for US visitors (recommended).', 'red-olive-cookie-opt-out' ); ?></label>
								<p class="description"><?php esc_html_e( 'Uses your CDN/host country header. Unknown country is treated as opt-in (strict).', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-force"><?php esc_html_e( 'Force a single mode', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td>
								<select id="rocoo-force" name="rocoo[force_mode]">
									<option value="" <?php selected( $s['force_mode'], '' ); ?>><?php esc_html_e( '— Let geo decide —', 'red-olive-cookie-opt-out' ); ?></option>
									<option value="optin" <?php selected( $s['force_mode'], 'optin' ); ?>><?php esc_html_e( 'Opt-in everywhere (strict)', 'red-olive-cookie-opt-out' ); ?></option>
									<option value="optout" <?php selected( $s['force_mode'], 'optout' ); ?>><?php esc_html_e( 'Opt-out everywhere', 'red-olive-cookie-opt-out' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Overrides geo detection. Use opt-in everywhere if you are unsure.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Global Privacy Control', 'red-olive-cookie-opt-out' ); ?></th>
							<td><label><input type="checkbox" name="rocoo[honor_gpc]" value="1" <?php checked( $s['honor_gpc'], 1 ); ?> /> <?php esc_html_e( 'Automatically opt out of "sale/share" (Marketing) when the browser sends a GPC signal.', 'red-olive-cookie-opt-out' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( '"Do Not Sell" link', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label><input type="checkbox" name="rocoo[show_dns]" value="1" <?php checked( $s['show_dns'], 1 ); ?> /> <?php esc_html_e( 'Show a "Do Not Sell or Share" link for US (opt-out) visitors.', 'red-olive-cookie-opt-out' ); ?></label>
								<p><input type="text" class="regular-text" name="rocoo[dns_label]" value="<?php echo esc_attr( $s['dns_label'] ); ?>" /></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-expiry"><?php esc_html_e( 'Remember choice for (days)', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="number" min="1" max="3650" id="rocoo-expiry" name="rocoo[expiry_days]" value="<?php echo esc_attr( $s['expiry_days'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Proof-of-consent log', 'red-olive-cookie-opt-out' ); ?></th>
							<td><label><input type="checkbox" name="rocoo[log_enabled]" value="1" <?php checked( $s['log_enabled'], 1 ); ?> /> <?php esc_html_e( 'Keep a minimal server-side audit log (hashed visitor id, categories, timestamp). Off = cookie only.', 'red-olive-cookie-opt-out' ); ?></label></td>
						</tr>
					</table>
					</div></div>
					</div>

					<aside class="rocoo-setup-side">
						<?php
						$rocoo_rows = $this->readiness_rows( $s );
						$rocoo_warn = 0;
						foreach ( $rocoo_rows as $rocoo_r ) {
							if ( 'warn' === $rocoo_r['state'] ) {
								$rocoo_warn++;
							}
						}
						?>
						<details class="rocoo-status <?php echo $rocoo_warn ? 'is-warn' : 'is-ok'; ?>">
							<summary class="rocoo-status__head">
								<span class="rocoo-status__title"><?php esc_html_e( 'Setup status', 'red-olive-cookie-opt-out' ); ?></span>
								<span class="rocoo-status__badge"><?php
								if ( $rocoo_warn ) {
									/* translators: %d: number of items needing attention. */
									echo esc_html( sprintf( _n( '%d needs attention', '%d need attention', $rocoo_warn, 'red-olive-cookie-opt-out' ), $rocoo_warn ) );
								} else {
									esc_html_e( 'Ready', 'red-olive-cookie-opt-out' );
								}
								?></span>
							</summary>
							<ul class="rocoo-status__list">
								<?php foreach ( $rocoo_rows as $rocoo_r ) : ?>
									<li class="rocoo-status__row is-<?php echo esc_attr( $rocoo_r['state'] ); ?>">
										<span class="rocoo-status__icon" aria-hidden="true"></span>
										<span class="rocoo-status__label"><?php echo esc_html( $rocoo_r['label'] ); ?></span>
										<span class="rocoo-status__value"><?php echo esc_html( $rocoo_r['value'] ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						</details>
					</aside>
					</div>
				</section>

				<!-- APPEARANCE -->
				<section class="rocoo-tabpane" data-pane="appearance" hidden>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="rocoo-accent"><?php esc_html_e( 'Accent color', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td>
								<input type="text" id="rocoo-accent" class="rocoo-color" name="rocoo[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" data-default-color="#ed1c24" />
								<p class="description"><?php esc_html_e( 'Drives the "Allow all" button and the bar highlight. Pick your brand color.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-bg"><?php esc_html_e( 'Background color', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" id="rocoo-bg" class="rocoo-color" name="rocoo[bg]" value="<?php echo esc_attr( $s['bg'] ); ?>" data-default-color="#131314" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-text"><?php esc_html_e( 'Text color', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" id="rocoo-text" class="rocoo-color" name="rocoo[text]" value="<?php echo esc_attr( $s['text'] ); ?>" data-default-color="#ffffff" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-bgop"><?php esc_html_e( 'Background opacity', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td>
								<input type="range" min="0" max="100" step="5" id="rocoo-bgop" class="rocoo-range" name="rocoo[bg_opacity]" value="<?php echo esc_attr( $s['bg_opacity'] ); ?>" />
								<output class="rocoo-range-val" for="rocoo-bgop"><?php echo esc_html( $s['bg_opacity'] ); ?>%</output>
								<p class="description"><?php esc_html_e( '100% = solid. Lower values make the bar see-through to the content behind; a subtle blur keeps text readable.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Bar position', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label><input type="radio" name="rocoo[position]" value="bottom" <?php checked( $s['position'], 'bottom' ); ?> /> <?php esc_html_e( 'Bottom', 'red-olive-cookie-opt-out' ); ?></label>
								&nbsp;&nbsp;
								<label><input type="radio" name="rocoo[position]" value="top" <?php checked( $s['position'], 'top' ); ?> /> <?php esc_html_e( 'Top', 'red-olive-cookie-opt-out' ); ?></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Bar size', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label><input type="radio" name="rocoo[layout]" value="standard" <?php checked( $s['layout'], 'standard' ); ?> /> <?php esc_html_e( 'Standard', 'red-olive-cookie-opt-out' ); ?></label>
								&nbsp;&nbsp;
								<label><input type="radio" name="rocoo[layout]" value="compact" <?php checked( $s['layout'], 'compact' ); ?> /> <?php esc_html_e( 'Compact', 'red-olive-cookie-opt-out' ); ?></label>
								<p class="description"><?php esc_html_e( 'Compact is a slim, single-row bar about the height of a button: the notice text sits on one line next to the buttons and the large heading is hidden. Customize still expands the full preferences panel.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-heading"><?php esc_html_e( 'Heading', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><input type="text" class="regular-text" id="rocoo-heading" name="rocoo[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-body"><?php esc_html_e( 'Body text', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td><textarea id="rocoo-body" class="large-text" rows="2" name="rocoo[body]"><?php echo esc_textarea( $s['body'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Basic HTML allowed (links, strong, em).', 'red-olive-cookie-opt-out' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Button labels', 'red-olive-cookie-opt-out' ); ?></th>
							<td>
								<label class="rocoo-inline"><?php esc_html_e( 'Allow all', 'red-olive-cookie-opt-out' ); ?><br/><input type="text" name="rocoo[allow_label]" value="<?php echo esc_attr( $s['allow_label'] ); ?>" /></label>
								<label class="rocoo-inline"><?php esc_html_e( 'Necessary only', 'red-olive-cookie-opt-out' ); ?><br/><input type="text" name="rocoo[necessary_label]" value="<?php echo esc_attr( $s['necessary_label'] ); ?>" /></label>
								<label class="rocoo-inline"><?php esc_html_e( 'Customize', 'red-olive-cookie-opt-out' ); ?><br/><input type="text" name="rocoo[customize_label]" value="<?php echo esc_attr( $s['customize_label'] ); ?>" /></label>
								<label class="rocoo-inline"><?php esc_html_e( 'Save preferences', 'red-olive-cookie-opt-out' ); ?><br/><input type="text" name="rocoo[save_label]" value="<?php echo esc_attr( $s['save_label'] ); ?>" /></label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="rocoo-handle-label"><?php esc_html_e( 'Re-open link label', 'red-olive-cookie-opt-out' ); ?></label></th>
							<td>
								<input type="text" id="rocoo-handle-label" name="rocoo[handle_label]" value="<?php echo esc_attr( $s['handle_label'] ); ?>" />
								<p class="description"><?php esc_html_e( 'The bar goes away once a visitor chooses; nothing stays docked on screen. To let visitors change their choice later, add the [rocoo_cookie_settings] shortcode anywhere, or give a footer/menu link the class "rocoo-open". This sets the shortcode link text.', 'red-olive-cookie-opt-out' ); ?></p>
							</td>
						</tr>
					</table>
				</section>

				<!-- CATEGORIES (under Appearance) -->
				<section class="rocoo-tabpane" data-pane="appearance" hidden>
					<h2 class="rocoo-adv-h"><?php esc_html_e( 'Categories', 'red-olive-cookie-opt-out' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Enable, rename, and describe the consent categories. "Strictly necessary" is always on. Changing which categories are enabled re-prompts all visitors.', 'red-olive-cookie-opt-out' ); ?></p>
					<?php foreach ( $s['categories'] as $key => $cat ) : $locked = ( 'necessary' === $key ); ?>
						<div class="rocoo-catbox">
							<h3>
								<label>
									<input type="checkbox" name="rocoo[categories][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( ! empty( $cat['enabled'] ), true ); ?> <?php disabled( $locked, true ); ?> />
									<?php echo esc_html( ucfirst( $key ) ); ?>
									<?php if ( $locked ) : ?><input type="hidden" name="rocoo[categories][<?php echo esc_attr( $key ); ?>][enabled]" value="1" /><em>(<?php esc_html_e( 'always on', 'red-olive-cookie-opt-out' ); ?>)</em><?php endif; ?>
								</label>
							</h3>
							<p><label><?php esc_html_e( 'Display name', 'red-olive-cookie-opt-out' ); ?><br/>
								<input type="text" class="regular-text" name="rocoo[categories][<?php echo esc_attr( $key ); ?>][name]" value="<?php echo esc_attr( $cat['name'] ); ?>" /></label></p>
							<p><label><?php esc_html_e( 'Description', 'red-olive-cookie-opt-out' ); ?><br/>
								<textarea class="large-text" rows="2" name="rocoo[categories][<?php echo esc_attr( $key ); ?>][desc]"><?php echo esc_textarea( $cat['desc'] ); ?></textarea></label></p>
						</div>
					<?php endforeach; ?>
				</section>

				<!-- RECORDS -->
				<section class="rocoo-tabpane" data-pane="records" hidden>
					<?php $log = Consent::log(); ?>
					<p><?php printf( esc_html__( 'Stored consent records: %d', 'red-olive-cookie-opt-out' ), count( $log ) ); ?></p>
					<?php if ( empty( $s['log_enabled'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Logging is currently off (turn on "Proof-of-consent log" under Setup → Manual override). New decisions are stored in the visitor cookie only.', 'red-olive-cookie-opt-out' ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $log ) ) : ?>
						<p>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rocoo_export_log' ), 'rocoo_export_log' ) ); ?>"><?php esc_html_e( 'Download CSV', 'red-olive-cookie-opt-out' ); ?></a>
							<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rocoo_clear_log' ), 'rocoo_clear_log' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Clear all consent records?', 'red-olive-cookie-opt-out' ) ); ?>');"><?php esc_html_e( 'Clear log', 'red-olive-cookie-opt-out' ); ?></a>
						</p>
						<table class="widefat striped">
							<thead><tr>
								<th><?php esc_html_e( 'Visitor (hashed)', 'red-olive-cookie-opt-out' ); ?></th>
								<th><?php esc_html_e( 'Mode', 'red-olive-cookie-opt-out' ); ?></th>
								<th><?php esc_html_e( 'Version', 'red-olive-cookie-opt-out' ); ?></th>
								<th><?php esc_html_e( 'When (UTC)', 'red-olive-cookie-opt-out' ); ?></th>
								<th><?php esc_html_e( 'Categories', 'red-olive-cookie-opt-out' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_reverse( array_slice( $log, -25 ) ) as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row['h'] ?? '' ); ?></code></td>
									<td><?php echo esc_html( $row['mode'] ?? '' ); ?></td>
									<td><?php echo esc_html( $row['v'] ?? '' ); ?></td>
									<td><?php echo isset( $row['ts'] ) ? esc_html( gmdate( 'Y-m-d H:i', (int) $row['ts'] ) ) : ''; ?></td>
									<td><?php
										$parts = array();
										if ( ! empty( $row['cats'] ) && is_array( $row['cats'] ) ) {
											foreach ( $row['cats'] as $k => $v ) {
												$parts[] = esc_html( $k ) . ':' . ( $v ? '✓' : '✕' );
											}
										}
										echo esc_html( implode( '  ', $parts ) );
									?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p class="description"><?php esc_html_e( 'Showing the most recent 25. Use Download CSV for the full log.', 'red-olive-cookie-opt-out' ); ?></p>
					<?php endif; ?>
				</section>

				<?php submit_button( __( 'Save settings', 'red-olive-cookie-opt-out' ) ); ?>
			</form>

			<p class="description rocoo-legal"><?php esc_html_e( 'This plugin provides the consent mechanism; it is not legal advice. You are responsible for accurate category descriptions, a current privacy policy, and confirming the configuration meets the laws that apply to your visitors.', 'red-olive-cookie-opt-out' ); ?></p>
		</div>
		<?php
	}
}
