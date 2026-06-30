<?php
/**
 * Frontend: enqueue assets, pass config to JS, render the banner and gated
 * script templates.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Print templates + banner near the end of the body, before footer scripts.
		add_action( 'wp_footer', array( $this, 'render' ), 20 );
		// A re-open link site owners can drop in a footer/menu instead of a dock.
		add_shortcode( 'rocoo_cookie_settings', array( $this, 'shortcode' ) );
	}

	/**
	 * Shortcode: a "Cookie settings" link that re-opens the preferences panel.
	 * Usage: [rocoo_cookie_settings text="Cookie settings"].
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'text' => Settings::all()['handle_label'] ),
			$atts,
			'rocoo_cookie_settings'
		);
		return sprintf(
			'<button type="button" class="rocoo-open" data-rocoo-open>%s</button>',
			esc_html( $atts['text'] )
		);
	}

	/**
	 * Don't show on wp-admin or the login screen; allow filtering.
	 *
	 * @return bool
	 */
	private function should_render() {
		$show = ! is_admin();
		/**
		 * Filter whether the consent UI renders for this request.
		 *
		 * @param bool $show Whether to render.
		 */
		return (bool) apply_filters( 'rocoo_should_render', $show );
	}

	/**
	 * Enqueue CSS/JS and localize the runtime config.
	 */
	public function enqueue() {
		if ( ! $this->should_render() ) {
			return;
		}

		$settings = Settings::all();

		wp_enqueue_style(
			'rocoo-banner',
			ROCOO_URL . 'assets/css/banner.css',
			array(),
			ROCOO_VERSION
		);

		// Inline the owner-chosen colors as CSS variables. The bar background is
		// emitted as rgba() so the opacity slider can make it see-through; a blur
		// kicks in when translucent so text stays legible over page content.
		$alpha   = max( 0, min( 100, (int) $settings['bg_opacity'] ) ) / 100;
		$bg_rgba = self::hex_to_rgba( $settings['bg'], $alpha );
		$blur    = $alpha < 1 ? '10px' : '0px';
		$vars    = sprintf(
			':root{--rocoo-accent:%s;--rocoo-bg:%s;--rocoo-bg-solid:%s;--rocoo-text:%s;--rocoo-blur:%s;}',
			esc_html( $settings['accent'] ),
			esc_html( $bg_rgba ),
			esc_html( $settings['bg'] ),
			esc_html( $settings['text'] ),
			esc_html( $blur )
		);
		wp_add_inline_style( 'rocoo-banner', $vars );

		wp_enqueue_script(
			'rocoo-banner',
			ROCOO_URL . 'assets/js/banner.js',
			array(),
			ROCOO_VERSION,
			true
		);

		$cats = array();
		foreach ( $settings['categories'] as $key => $cat ) {
			if ( 'necessary' !== $key && empty( $cat['enabled'] ) ) {
				continue;
			}
			$cats[] = array(
				'key'    => $key,
				'name'   => $cat['name'],
				'desc'   => $cat['desc'],
				'locked' => ( 'necessary' === $key ),
			);
		}

		wp_localize_script(
			'rocoo-banner',
			'ROCOO',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( Consent::NONCE ),
				'cookie'     => Consent::COOKIE,
				'days'       => (int) $settings['expiry_days'],
				'version'    => (int) $settings['consent_version'],
				'mode'       => Geo::mode( $settings ),
				'gpc'        => Geo::gpc(),
				'honorGpc'   => ! empty( $settings['honor_gpc'] ),
				'logEnabled' => ! empty( $settings['log_enabled'] ),
				'consentMode' => Consent_Mode::is_advanced( $settings ) ? 'advanced' : 'off',
				'optoutDefaults' => Modes::optout_defaults( $settings ),
				'gpcScope'    => array_values( Modes::gpc_scope( $settings ) ),
				'blockLevel'  => Modes::block_level( $settings ),
				'isAdmin'     => current_user_can( 'manage_options' ),
				'cats'       => $cats,
			)
		);
	}

	/**
	 * Convert a hex color + alpha (0–1) into a CSS rgba() string.
	 *
	 * @param string $hex   Hex color, e.g. #131314 or #abc.
	 * @param float  $alpha Alpha 0–1.
	 * @return string
	 */
	private static function hex_to_rgba( $hex, $alpha ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			$hex = '131314';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$a = max( 0, min( 1, (float) $alpha ) );
		// Trim trailing zeros for a tidy value (1, 0.5, 0.85...).
		$a = rtrim( rtrim( number_format( $a, 2, '.', '' ), '0' ), '.' );
		if ( '' === $a ) {
			$a = '0';
		}
		return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, $a );
	}

	/**
	 * Render the gated templates and the banner markup.
	 */
	public function render() {
		if ( ! $this->should_render() ) {
			return;
		}

		$settings = Settings::all();

		// Inert, consent-gated script templates.
		Script_Gate::render( $settings );

		// Trackers the owner explicitly marked "essential" load ungated (before consent).
		Script_Gate::render_essential( $settings );

		$mode     = Geo::mode( $settings );
		$pos      = 'top' === $settings['position'] ? 'rocoo--top' : 'rocoo--bottom';
		$layout   = 'compact' === $settings['layout'] ? ' rocoo--compact' : '';
		$show_dns = ( ! empty( $settings['show_dns'] ) && 'optout' === $mode );

		$body = wp_kses_post( $settings['body'] );
		if ( ! empty( $settings['privacy_url'] ) ) {
			$body .= ' <a class="rocoo-privacy" href="' . esc_url( $settings['privacy_url'] ) . '">' . esc_html__( 'Privacy policy', 'red-olive-cookie-opt-out' ) . '</a>';
		}
		?>
		<div id="rocoo" class="rocoo <?php echo esc_attr( $pos . $layout ); ?>" hidden>
			<div class="rocoo-bar" role="region" aria-label="<?php esc_attr_e( 'Cookie consent', 'red-olive-cookie-opt-out' ); ?>">
				<div class="rocoo-bar__text">
					<h2 class="rocoo-bar__h"><?php echo esc_html( $settings['heading'] ); ?></h2>
					<p class="rocoo-bar__p"><?php echo wp_kses_post( $body ); ?></p>
				</div>
				<div class="rocoo-bar__actions">
					<button type="button" class="rocoo-link" data-rocoo="customize"><?php echo esc_html( $settings['customize_label'] ); ?></button>
					<button type="button" class="rocoo-btn rocoo-btn--ghost" data-rocoo="necessary"><?php echo esc_html( $settings['necessary_label'] ); ?></button>
					<button type="button" class="rocoo-btn rocoo-btn--primary" data-rocoo="allow"><?php echo esc_html( $settings['allow_label'] ); ?></button>
				</div>
			</div>

			<div class="rocoo-panel" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Cookie preferences', 'red-olive-cookie-opt-out' ); ?>">
				<div class="rocoo-panel__cats">
					<?php foreach ( $settings['categories'] as $key => $cat ) : ?>
						<?php
						if ( 'necessary' !== $key && empty( $cat['enabled'] ) ) {
							continue;
						}
						$locked = ( 'necessary' === $key );
						?>
						<div class="rocoo-cat">
							<label class="rocoo-cat__head">
								<input type="checkbox" data-cat="<?php echo esc_attr( $key ); ?>" <?php echo $locked ? 'checked disabled' : ''; ?> />
								<span class="rocoo-switch" aria-hidden="true"></span>
								<span class="rocoo-cat__name" style="color:<?php echo esc_attr( $settings['text'] ); ?> !important;opacity:1 !important;"><?php echo esc_html( $cat['name'] ); ?><?php echo $locked ? ' <em>(' . esc_html__( 'always on', 'red-olive-cookie-opt-out' ) . ')</em>' : ''; ?></span>
							</label>
							<p class="rocoo-cat__desc"><?php echo esc_html( $cat['desc'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="rocoo-panel__foot">
					<?php if ( $show_dns ) : ?>
						<button type="button" class="rocoo-link rocoo-dns" data-rocoo="donotsell"><?php echo esc_html( $settings['dns_label'] ); ?></button>
					<?php endif; ?>
					<span class="rocoo-panel__foot-actions">
						<button type="button" class="rocoo-btn rocoo-btn--ghost" data-rocoo="save"><?php echo esc_html( $settings['save_label'] ); ?></button>
						<button type="button" class="rocoo-btn rocoo-btn--primary" data-rocoo="allow"><?php echo esc_html( $settings['allow_label'] ); ?></button>
					</span>
				</div>
			</div>
		</div>
		<?php
	}
}
