/* Red Olive Cookie Opt-Out — front-end consent logic.
 *
 * Reads config from window.ROCOO and the opt-in/opt-out mode from
 * window.rocooGeo (geo.js, resolved in the browser), manages the
 * first-party consent cookie, and activates gated <template> scripts only for
 * consented categories. Exposes window.roConsent for theme/GTM integration.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	ready(function () {
		var C = window.ROCOO || {};
		var root = document.getElementById('rocoo');
		if (!root || !C.cats) {
			return;
		}
		var panel = root.querySelector('.rocoo-panel');
		var dns = root.querySelector('[data-rocoo="donotsell"]');
		// Opt-in until geo.js resolves (fail closed).
		var mode = 'optin';
		var catKeys = C.cats.map(function (c) { return c.key; });

		/* ---------- cookie ---------- */
		function readCookie() {
			var m = document.cookie.match('(?:^|; )' + C.cookie + '=([^;]*)');
			if (!m) { return null; }
			try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
		}
		function writeCookie(state) {
			var d = new Date();
			d.setTime(d.getTime() + (C.days || 180) * 86400000);
			var secure = location.protocol === 'https:' ? '; Secure' : '';
			document.cookie = C.cookie + '=' + encodeURIComponent(JSON.stringify(state)) +
				'; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + secure;
		}

		/* ---------- consent state ---------- */
		function defaults(value) {
			var cats = {};
			C.cats.forEach(function (c) { cats[c.key] = c.locked ? true : !!value; });
			return cats;
		}

		var stored = readCookie();
		var hasChoice = !!(stored && stored.v === C.version && stored.cats);
		var state;

		if (hasChoice) {
			state = {};
			C.cats.forEach(function (c) {
				state[c.key] = c.locked ? true : !!stored.cats[c.key];
			});
		} else {
			// Nothing non-essential until the mode is known (see onGeo).
			state = defaults(false);
		}

		function geoDefaults() {
			if (mode !== 'optout') {
				// EU/strict / High Compliance: nothing non-essential until they choose.
				return defaults(false);
			}
			// US: the active compliance mode decides which categories are on by default.
			var od = C.optoutDefaults || {};
			var s = {};
			C.cats.forEach(function (c) {
				s[c.key] = c.locked ? true : !!od[c.key];
			});
			// ...unless the browser sends Global Privacy Control: opt out of the
			// categories this mode maps the signal to.
			if (C.honorGpc && navigator.globalPrivacyControl === true && C.gpcScope) {
				C.gpcScope.forEach(function (cat) { if (cat in s) { s[cat] = false; } });
			}
			return s;
		}

		/* ---------- activate gated scripts ---------- */
		function activate(cat) {
			var tpls = document.querySelectorAll('template.rocoo-gated[data-rocoo-cat="' + cat + '"]');
			Array.prototype.forEach.call(tpls, function (tpl) {
				if (tpl.getAttribute('data-rocoo-done')) { return; }
				tpl.setAttribute('data-rocoo-done', '1');
				var frag = tpl.content.cloneNode(true);
				// Re-create <script> nodes so the browser executes them.
				var scripts = frag.querySelectorAll('script');
				Array.prototype.forEach.call(scripts, function (old) {
					var s = document.createElement('script');
					for (var i = 0; i < old.attributes.length; i++) {
						s.setAttribute(old.attributes[i].name, old.attributes[i].value);
					}
					// Page optimizers (WP Rocket, LiteSpeed) rewrite script tags even
					// inside the inert template — src moved to a data-* attribute, type
					// swapped to a non-JS value — but their loaders never restore
					// template content, so undo the rewrite or the script stays dead
					// after consent.
					if (!s.getAttribute('src')) {
						var lazySrc = s.getAttribute('data-rocket-src') || s.getAttribute('data-src');
						if (lazySrc) { s.setAttribute('src', lazySrc); }
					}
					var type = (s.getAttribute('type') || '').toLowerCase();
					if (type && type !== 'module' && type !== 'text/javascript' && type !== 'application/javascript') {
						s.setAttribute('type', 'text/javascript');
					}
					s.text = old.textContent;
					old.parentNode.replaceChild(s, old);
				});
				document.body.appendChild(frag);
			});
		}

		// Google Consent Mode v2: translate our categories into consent signals and
		// push an update. Mirrors Consent_Mode::signals_from_cats() in PHP; keep in
		// step. On denial the Google tags fall back to cookieless pings.
		function pushConsentMode(s) {
			if (C.consentMode !== 'advanced') { return; }
			function g(b) { return b ? 'granted' : 'denied'; }
			var sig = {
				ad_storage: g(!!s.marketing),
				ad_user_data: g(!!s.marketing),
				ad_personalization: g(!!s.marketing),
				analytics_storage: g(!!s.analytics),
				functionality_storage: g(!!s.functional),
				personalization_storage: g(!!s.functional),
				security_storage: 'granted'
			};
			// Google's consent layer ignores commands pushed as plain arrays — only
			// an `arguments` object (what a real gtag() shim pushes) is recognized.
			// window.gtag can legitimately be undefined here when a page optimizer
			// (WP Rocket Delay JS) defers the head gtag shim past this script.
			window.dataLayer = window.dataLayer || [];
			var push = (typeof window.gtag === 'function')
				? window.gtag
				: function () { window.dataLayer.push(arguments); };
			push('consent', 'update', sig);
		}

		function apply(s) {
			pushConsentMode(s);
			catKeys.forEach(function (k) { if (s[k]) { activate(k); } });
			try {
				window.dispatchEvent(new CustomEvent('roConsentChange', { detail: { consent: s, mode: mode } }));
			} catch (e) { /* old browsers */ }
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push({ event: 'rocoo_consent', rocoo_consent: s });
		}

		/* ---------- UI ---------- */
		function show() { root.hidden = false; }
		function hide() { root.hidden = true; }
		function syncInputs(s) {
			Array.prototype.forEach.call(root.querySelectorAll('input[data-cat]'), function (inp) {
				inp.checked = !!s[inp.getAttribute('data-cat')];
			});
		}
		function openPanel() {
			if (!panel) { return; }
			panel.hidden = false;
			show();
			syncInputs(state);
			var f = panel.querySelector('input:not([disabled]), button');
			if (f) { f.focus(); }
		}
		function closePanel() { if (panel) { panel.hidden = true; } }

		function save(newState) {
			hasChoice = true;
			writeCookie({ v: C.version, mode: mode, ts: Date.now(), cats: newState });

			if (C.logEnabled && C.ajaxUrl) {
				try {
					fetch(C.ajaxUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						credentials: 'same-origin',
						body: 'action=rocoo_log&_wpnonce=' + encodeURIComponent(C.nonce) +
							'&cats=' + encodeURIComponent(JSON.stringify(newState)) +
							'&mode=' + encodeURIComponent(mode) +
							'&v=' + encodeURIComponent(C.version)
					});
				} catch (e) { /* non-fatal */ }
			}

			// If a previously-active category is now off (opt-out downgrade), reload
			// so already-loaded trackers are removed cleanly.
			var needReload = false;
			catKeys.forEach(function (k) { if (state[k] && !newState[k]) { needReload = true; } });

			state = newState;
			closePanel();
			hide();

			if (needReload) {
				window.location.reload();
			} else {
				apply(newState);
			}
		}

		/* ---------- wire events ---------- */
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-rocoo]');
			if (!btn) { return; }
			var act = btn.getAttribute('data-rocoo');
			if (act === 'allow') {
				save(defaults(true));
			} else if (act === 'necessary') {
				save(defaults(false));
			} else if (act === 'customize') {
				openPanel();
			} else if (act === 'save') {
				var s = {};
				C.cats.forEach(function (c) {
					var inp = root.querySelector('input[data-cat="' + c.key + '"]');
					s[c.key] = c.locked ? true : !!(inp && inp.checked);
				});
				save(s);
			} else if (act === 'donotsell') {
				var d = {};
				catKeys.forEach(function (k) { d[k] = state[k]; });
				if ('marketing' in d) { d.marketing = false; }
				save(d);
			}
		});
		// Let any element re-open preferences: the shortcode button, or a footer/
		// menu link given class "rocoo-open" or attribute data-rocoo-open.
		document.addEventListener('click', function (e) {
			var opener = e.target.closest('[data-rocoo-open], .rocoo-open');
			if (opener) { e.preventDefault(); openPanel(); }
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && panel && !panel.hidden) { closePanel(); }
		});

		/* ---------- public API ---------- */
		window.roConsent = {
			get: function () {
				var copy = {};
				catKeys.forEach(function (k) { copy[k] = state[k]; });
				return copy;
			},
			set: function (cat, val) {
				var s = this.get();
				s[cat] = !!val;
				save(s);
			},
			acceptAll: function () { save(defaults(true)); },
			open: openPanel,
			mode: mode
		};

		function onGeo(g) {
			mode = g.mode === 'optout' ? 'optout' : 'optin';
			window.roConsent.mode = mode;
			if (dns) { dns.hidden = mode !== 'optout'; }
			// A choice made before the mode resolved (or stored earlier) stands.
			if (hasChoice) { return; }
			state = geoDefaults();
			apply(state);
			syncInputs(state);
		}

		/* ---------- run ---------- */
		// A stored choice is explicit consent and doesn't depend on region.
		if (hasChoice) { apply(state); }
		syncInputs(state);
		if (!hasChoice) { show(); }
		if (window.rocooGeo && typeof window.rocooGeo.onResolve === 'function') {
			window.rocooGeo.onResolve(onGeo);
		} else {
			onGeo({ mode: 'optin' });
		}
	});
})();
