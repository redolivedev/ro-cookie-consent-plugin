/* Red Olive Cookie Opt-Out — resolve opt-in vs opt-out in the browser.
 *
 * Printed inline in <head> (after window.ROCOO_GEO) so the lookup starts before
 * anything else. The page HTML is identical for every visitor; the country comes
 * from Cloudflare's /cdn-cgi/trace, which is answered at the edge and never
 * cached by the origin. Anything unexpected resolves to opt-in (fail closed).
 * banner.js waits on window.rocooGeo.onResolve() before activating any tag.
 */
(function (G) {
	'use strict';

	var KEY = 'ro_cc';
	var CC = /^[A-Z]{2}$/;
	var waiting = [];
	var api = window.rocooGeo = {
		mode: null,
		country: null,
		source: null,
		onResolve: function (fn) {
			if (api.mode) { fn(api); } else { waiting.push(fn); }
		}
	};

	function finish(country, source) {
		if (api.mode) { return; }
		var cc = CC.test(country || '') ? country : '';
		var optout = !!(G.optin && cc && cc !== 'XX' && cc !== 'T1' && G.optin.indexOf(cc) === -1);
		api.country = cc;
		api.source = source;
		api.mode = G.force || (optout ? 'optout' : 'optin');
		var list = waiting;
		waiting = [];
		list.forEach(function (fn) {
			try { fn(api); } catch (e) { /* one bad listener must not block the rest */ }
		});
	}

	if (G.force) {
		finish('', 'forced');
		return;
	}

	if (G.qa) {
		var q = /[?&]ro_cc_test=([A-Za-z]{2})(?:&|#|$)/.exec(location.search);
		if (q) {
			finish(q[1].toUpperCase(), 'qa');
			return;
		}
	}

	if (G.test) {
		finish(G.test, 'constant');
		return;
	}

	try {
		var cached = window.sessionStorage.getItem(KEY);
		if (cached && CC.test(cached)) {
			finish(cached, 'session');
			return;
		}
	} catch (e) { /* storage blocked: fall through to the lookup */ }

	if (!window.fetch || !window.AbortController) {
		finish('', 'unsupported');
		return;
	}

	var ctrl = new AbortController();
	var timer = setTimeout(function () {
		ctrl.abort();
		finish('', 'timeout');
	}, 2000);

	fetch('/cdn-cgi/trace', { cache: 'no-store', credentials: 'omit', signal: ctrl.signal })
		.then(function (r) {
			if (!r.ok) { throw new Error('trace ' + r.status); }
			return r.text();
		})
		.then(function (text) {
			clearTimeout(timer);
			var m = /^loc=([A-Z]{2})$/m.exec(text);
			if (!m) {
				finish('', 'unparseable');
				return;
			}
			try { window.sessionStorage.setItem(KEY, m[1]); } catch (e) { /* non-fatal */ }
			finish(m[1], 'trace');
		})['catch'](function () {
			clearTimeout(timer);
			finish('', 'error');
		});
})(window.ROCOO_GEO || {});
