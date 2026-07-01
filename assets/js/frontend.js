/* global jwpbFrontend, jQuery */
(function ($) {
	'use strict';

	var $addonForm     = $('#jwpb-addon-form');
	if (!$addonForm.length) { return; }

	var $form          = $('form.cart');
	var $error         = $('#jwpb-addon-error');
	var $total         = $('#jwpb-addon-total');
	var $totalRow      = $('#jwpb-addon-total-row');
	var $summary       = $('#jwpb-addon-summary');
	var $summaryAddons = $('#jwpb-summary-addons');
	var sumMode        = !!jwpbFrontend.addonSumMode;
	var basePrice      = parseFloat(jwpbFrontend.basePrice) || 0;

	// -------------------------------------------------------------------------
	// Check whether at least one addon is selected / has qty > 0
	// -------------------------------------------------------------------------
	function hasSelection() {
		var selected = false;
		$addonForm.find('.jwpb-addon-item').each(function () {
			var $cb  = $(this).find('.jwpb-addon-checkbox');
			var $qty = $(this).find('.jwpb-addon-qty');
			if ($cb.length && $cb.is(':checked')) {
				selected = true;
				return false; // break
			}
			if ($qty.length && parseInt($qty.val(), 10) > 0) {
				selected = true;
				return false;
			}
		});
		return selected;
	}

	// -------------------------------------------------------------------------
	// Selection summary (sum-mode packs only)
	// -------------------------------------------------------------------------
	function updateSummary() {
		if (!sumMode) { return; }

		var rows = '';
		$addonForm.find('.jwpb-addon-item').each(function () {
			var $item = $(this);
			var $cb   = $item.find('.jwpb-addon-checkbox');
			var $qty  = $item.find('.jwpb-addon-qty');
			var name  = $item.data('name') || '';
			var price = parseFloat($item.data('price')) || 0;
			var qty   = 0;

			if ($cb.length && $cb.is(':checked')) {
				qty = 1;
			} else if ($qty.length) {
				qty = parseInt($qty.val(), 10) || 0;
			}

			if (qty < 1) { return; }

			var nameHtml  = $('<span>').text(name).html();
			var label     = qty > 1 ? nameHtml + ' &times; ' + qty : nameHtml;
			var linePrice = price * qty;

			rows += '<div class="jwpb-summary-row jwpb-summary-addon">' +
				'<span class="jwpb-summary-label">' + label + '</span>' +
				'<span class="jwpb-summary-price">+' + jwpbFrontend.currency + linePrice.toFixed(2) + '</span>' +
				'</div>';
		});

		$summaryAddons.html(rows);
		if (hasSelection()) { $summary.show(); } else { $summary.hide(); }
	}

	// -------------------------------------------------------------------------
	// Live subtotal preview (sum-mode packs only)
	// -------------------------------------------------------------------------
	function updateTotal() {
		if (!sumMode) { return; }

		var total = basePrice;
		$addonForm.find('.jwpb-addon-item').each(function () {
			var price = parseFloat($(this).data('price')) || 0;
			var $cb   = $(this).find('.jwpb-addon-checkbox');
			var $qty  = $(this).find('.jwpb-addon-qty');

			if ($cb.length) {
				if ($cb.is(':checked')) { total += price; }
			} else if ($qty.length) {
				var qty = parseInt($qty.val(), 10) || 0;
				total += price * qty;
			}
		});

		$total.text(jwpbFrontend.currency + total.toFixed(2));
		if (hasSelection()) { $totalRow.show(); } else { $totalRow.hide(); }
	}

	// -------------------------------------------------------------------------
	// Form submit: validate, then sync addon inputs that live outside the cart
	// form (any placement other than 'before_add_to_cart') into hidden fields.
	// -------------------------------------------------------------------------
	$form.on('submit', function (e) {
		if (!hasSelection()) {
			e.preventDefault();
			$error.show();
			$addonForm[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			return false;
		}
		$error.hide();

		// If the addon form is outside <form class="cart">, its inputs won't
		// submit automatically — copy the current values as hidden fields.
		var $outside = $addonForm.find('[name^="jwpb_addon_sel"]').not($form.find('[name^="jwpb_addon_sel"]'));
		if ($outside.length) {
			$form.find('.jwpb-synced-addon-inputs').remove();
			var $container = $('<div class="jwpb-synced-addon-inputs" style="display:none">');
			$outside.each(function () {
				var $inp = $(this);
				var val;
				if ($inp.is(':checkbox')) {
					if (!$inp.is(':checked')) { return; }
					val = '1';
				} else {
					val = $inp.val();
					if (!parseInt(val, 10)) { return; }
				}
				$container.append($('<input type="hidden">').attr('name', $inp.attr('name')).val(val));
			});
			$form.append($container);
		}
	});

	// -------------------------------------------------------------------------
	// Live feedback
	// -------------------------------------------------------------------------
	$addonForm.on('change input', '.jwpb-addon-checkbox, .jwpb-addon-qty', function () {
		$error.hide();
		updateSummary();
		updateTotal();
	});

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	$(function () {
		updateSummary();
		updateTotal();
	});

}(jQuery));
