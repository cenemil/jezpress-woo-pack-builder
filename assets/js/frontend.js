/* global jwpbFrontend, jQuery */
(function ($) {
	'use strict';

	var $addonForm = $('#jwpb-addon-form');
	if (!$addonForm.length) { return; }

	var $form     = $('form.cart');
	var $error    = $('#jwpb-addon-error');
	var $total    = $('#jwpb-addon-total');
	var $totalRow = $('#jwpb-addon-total-row');
	var sumMode   = !!jwpbFrontend.addonSumMode;

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
	// Live subtotal preview (sum-mode packs only)
	// -------------------------------------------------------------------------
	function updateTotal() {
		if (!sumMode) { return; }

		var total = 0;
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

		if (total > 0) {
			$total.text(jwpbFrontend.currency + total.toFixed(2));
			$totalRow.show();
		} else {
			$totalRow.hide();
		}
	}

	// -------------------------------------------------------------------------
	// Form submit validation
	// -------------------------------------------------------------------------
	$form.on('submit', function (e) {
		if (!hasSelection()) {
			e.preventDefault();
			$error.show();
			$addonForm[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			return false;
		}
		$error.hide();
	});

	// -------------------------------------------------------------------------
	// Live feedback
	// -------------------------------------------------------------------------
	$addonForm.on('change input', '.jwpb-addon-checkbox, .jwpb-addon-qty', function () {
		$error.hide();
		updateTotal();
	});

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	$(function () {
		updateTotal();
	});

}(jQuery));
