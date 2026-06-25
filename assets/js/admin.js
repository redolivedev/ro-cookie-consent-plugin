/* Red Olive Cookie Opt-Out — admin settings UI (tabs + color pickers). */
(function ($) {
	'use strict';

	$(function () {
		// Color pickers.
		$('.rocoo-color').wpColorPicker();

		// Opacity slider live readout.
		$('.rocoo-range').on('input change', function () {
			$('.rocoo-range-val').text($(this).val() + '%');
		});

		// Tabs.
		var $tabs = $('.rocoo-tabs .nav-tab');
		var $panes = $('.rocoo-tabpane');

		function activate(name) {
			$tabs.removeClass('nav-tab-active');
			$tabs.filter('[data-tab="' + name + '"]').addClass('nav-tab-active');
			$panes.prop('hidden', true);
			$panes.filter('[data-pane="' + name + '"]').prop('hidden', false);
		}

		$tabs.on('click', function (e) {
			e.preventDefault();
			activate($(this).data('tab'));
			if (window.history && window.history.replaceState) {
				window.history.replaceState(null, '', '#' + $(this).data('tab'));
			}
		});

		// Open the tab named in the URL hash (e.g. after a Records redirect).
		var hash = (window.location.hash || '').replace('#', '');
		var params = new URLSearchParams(window.location.search);
		var fromQuery = params.get('tab');
		if (fromQuery && $tabs.filter('[data-tab="' + fromQuery + '"]').length) {
			activate(fromQuery);
		} else if (hash && $tabs.filter('[data-tab="' + hash + '"]').length) {
			activate(hash);
		}

		// Compliance mode cards: reflect the selected card.
		var $cards = $('.rocoo-mode-card');
		$('.rocoo-modes').on('change', 'input[type=radio]', function () {
			$cards.removeClass('is-selected');
			$(this).closest('.rocoo-mode-card').addClass('is-selected');
		});

		// First-run disclaimer gate: enable "Accept & continue" only once the box
		// is ticked. (The server also requires the checkbox, so this is just UX.)
		var $gateCheck = $('.rocoo-gate__check');
		var $gateBtn = $('.rocoo-gate__accept');
		if ($gateCheck.length && $gateBtn.length) {
			$gateCheck.on('change', function () {
				$gateBtn.prop('disabled', !this.checked);
			});
		}

		// Coexist with WAFs (e.g. Wordfence) that return a 403 when they see a raw
		// <script> in any POST field. On submit, base64-encode the custom-script
		// blobs into hidden fields (decoded server-side) and drop the raw textareas
		// from the POST, so the firewall never sees script markup.
		var $form = $('input[name="action"][value="rocoo_save"]').closest('form');
		$form.on('submit', function () {
			$form.find('textarea[name^="rocoo[scripts]"]').each(function () {
				var $t = $(this);
				var name = $t.attr('name') || '';
				var key = name.replace('rocoo[scripts][', '').replace(']', '');
				if (!key) { return; }
				var enc;
				try {
					enc = btoa(unescape(encodeURIComponent($t.val() || '')));
				} catch (e) {
					return; // leave the raw field as-is on any failure
				}
				$('<input>', { type: 'hidden', name: 'rocoo[scripts_b64][' + key + ']', value: enc }).appendTo($form);
				$t.prop('disabled', true); // keep the raw <script> out of the POST
			});
			return true;
		});
	});
})(jQuery);
