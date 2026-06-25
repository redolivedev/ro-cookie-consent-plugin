<?php
/**
 * Script_Gate: render category-tagged scripts as inert <template> blocks that
 * banner.js activates only when the matching category is consented.
 *
 * Templates do not execute their <script> children until JS clones and
 * re-creates them, so it is safe to print them on every page load — nothing
 * runs before consent.
 *
 * @package RedOlive\CookieOptOut
 */

namespace RedOlive\CookieOptOut;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Script_Gate {

	/**
	 * Echo every gated template for the enabled categories.
	 *
	 * @param array $settings Settings.
	 */
	public static function render( $settings ) {
		$blocks = self::blocks( $settings );

		foreach ( $blocks as $category => $markup ) {
			if ( '' === trim( $markup ) ) {
				continue;
			}
			printf(
				'<template class="rocoo-gated" data-rocoo-cat="%s">%s</template>',
				esc_attr( $category ),
				// Intentionally not escaped: this is admin-authored script markup,
				// equivalent to a custom-HTML block, and lives inside an inert
				// <template> until consent.
				$markup // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}

	/**
	 * Build the per-category markup, combining presets with the owner's blobs.
	 *
	 * @param array $settings Settings.
	 * @return array<string,string> category => markup.
	 */
	private static function blocks( $settings ) {
		$enabled = Settings::active_category_keys( $settings );
		$out     = array(
			'analytics' => '',
			'marketing' => '',
		);

		// GA4 preset -> analytics. Under advanced Consent Mode the GA4 tag loads
		// consent-aware in <head> instead, so don't also gate it here.
		if ( ! empty( $settings['ga4_id'] ) && ! Consent_Mode::is_advanced( $settings ) ) {
			$out['analytics'] .= self::ga4( $settings['ga4_id'] );
		}

		// Meta Pixel preset -> marketing.
		if ( ! empty( $settings['meta_pixel_id'] ) ) {
			$out['marketing'] .= self::meta_pixel( $settings['meta_pixel_id'] );
		}

		// GTM container -> gated whole under its chosen category. Unlike GA4, GTM is
		// never loaded in the Consent Mode head block, so there's no double-load to
		// avoid: the entire container is simply hard-blocked until consent.
		if ( ! empty( $settings['gtm_id'] ) ) {
			$gtm_cat = in_array( $settings['gtm_cat'] ?? 'marketing', array( 'analytics', 'marketing' ), true ) ? $settings['gtm_cat'] : 'marketing';
			$out[ $gtm_cat ] .= self::gtm( $settings['gtm_id'] );
		}

		// WhatConverts -> gated under its chosen category. Replaces the standalone
		// WhatConverts plugin, which enqueues the same script ungated (pre-consent).
		if ( ! empty( $settings['wc_enabled'] ) && ! empty( $settings['wc_profile_id'] ) ) {
			$wc_cat = in_array( $settings['wc_cat'] ?? 'marketing', array( 'analytics', 'marketing' ), true ) ? $settings['wc_cat'] : 'marketing';
			$out[ $wc_cat ] .= self::whatconverts( $settings['wc_profile_id'] );
		}

		// Owner-pasted blobs.
		foreach ( array( 'analytics', 'marketing' ) as $key ) {
			if ( ! empty( $settings['scripts'][ $key ] ) ) {
				$out[ $key ] .= "\n" . $settings['scripts'][ $key ];
			}
		}

		// Only keep categories that are currently enabled.
		foreach ( array_keys( $out ) as $key ) {
			if ( ! in_array( $key, $enabled, true ) ) {
				$out[ $key ] = '';
			}
		}

		/**
		 * Filter the gated markup blocks before they are printed.
		 *
		 * @param array $out      category => markup.
		 * @param array $settings Settings.
		 */
		return apply_filters( 'rocoo_gated_blocks', $out, $settings );
	}

	/**
	 * Standard GA4 (gtag.js) snippet.
	 *
	 * @param string $id Measurement ID, e.g. G-XXXXXXX.
	 * @return string
	 */
	private static function ga4( $id ) {
		$id = esc_js( $id );
		return "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>"
			. "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
			. "gtag('js',new Date());gtag('config','{$id}');</script>";
	}

	/**
	 * Standard Meta (Facebook) Pixel snippet.
	 *
	 * @param string $id Pixel ID (digits).
	 * @return string
	 */
	private static function meta_pixel( $id ) {
		$id = esc_js( $id );
		return "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
			. "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;"
			. "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;"
			. "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}"
			. "(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');"
			. "fbq('init','{$id}');fbq('track','PageView');</script>"
			. "<noscript><img height=\"1\" width=\"1\" style=\"display:none\" "
			. "src=\"https://www.facebook.com/tr?id={$id}&ev=PageView&noscript=1\" alt=\"\"/></noscript>";
	}

	/**
	 * Standard Google Tag Manager container loader. Gated whole: nothing inside
	 * the container fires until banner.js re-creates this script post-consent.
	 *
	 * @param string $id Container ID, e.g. GTM-XXXXXXX.
	 * @return string
	 */
	private static function gtm( $id ) {
		$id = esc_js( $id );
		return "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':"
			. "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],"
			. "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;"
			. "j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;"
			. "f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$id}');</script>"
			. "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$id}\" "
			. "height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>";
	}

	/**
	 * WhatConverts call/lead tracking loader. Mirrors what the standalone
	 * WhatConverts plugin injects ($wc_leads bootstrap + the profile script from
	 * WhatConverts' tracking host), but gated so it does not run before consent.
	 *
	 * @param string $profile WhatConverts Profile ID (digits).
	 * @return string
	 */
	private static function whatconverts( $profile ) {
		$profile = preg_replace( '/[^0-9]/', '', (string) $profile );
		return "<script>var \$wc_load=function(a){return JSON.parse(JSON.stringify(a))},"
			. "\$wc_leads=\$wc_leads||{doc:{url:\$wc_load(document.URL),ref:\$wc_load(document.referrer),"
			. "search:\$wc_load(location.search),hash:\$wc_load(location.hash)}};</script>"
			. "<script async src=\"//s.ksrndkehqnwntyxlhgto.com/{$profile}.js\"></script>";
	}
}
