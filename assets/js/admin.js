/* global jwpbAdmin, jQuery */
(function ($) {
	'use strict';

	var $panel   = $('#jwpb-pack-form-panel');
	var $toggle  = $('#jwpb-toggle-create-form');
	var editMode = false;  // false = create, true = edit

	// -------------------------------------------------------------------------
	// + Add New Pack button — opens form in create mode
	// -------------------------------------------------------------------------
	$toggle.on('click', function () {
		if ($panel.is(':visible') && !editMode) {
			closePanel();
		} else {
			switchToCreateMode();
			$panel.slideDown(150);
			$toggle.addClass('active');
			$('#jwpb-new-pack-name').trigger('focus');
		}
	});

	// -------------------------------------------------------------------------
	// Edit Pack button — fetches pack data, opens form in edit mode
	// -------------------------------------------------------------------------
	$(document).on('click', '.jwpb-edit-pack-btn', function () {
		var packId  = $(this).data('pack-id');
		var $btn    = $(this);
		var $err    = $('#jwpb-form-error');

		$btn.prop('disabled', true);
		$err.hide();

		$.post(jwpbAdmin.ajaxurl, {
			action:  'jwpb_get_pack_data',
			pack_id: packId,
			nonce:   jwpbAdmin.nonce
		}, function (response) {
			$btn.prop('disabled', false);

			if (!response.success) {
				showFormError(response.data.message || jwpbAdmin.i18n.loadError);
				return;
			}

			var d = response.data;
			switchToEditMode(d);
			$panel.slideDown(150);
			$toggle.removeClass('active');
			$panel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
			$('#jwpb-new-pack-name').trigger('focus');
		}).fail(function () {
			$btn.prop('disabled', false);
			showFormError(jwpbAdmin.i18n.loadError);
		});
	});

	// -------------------------------------------------------------------------
	// Cancel
	// -------------------------------------------------------------------------
	$('#jwpb-form-cancel').on('click', function () {
		closePanel();
	});

	// -------------------------------------------------------------------------
	// Pricing mode toggle — show/hide fixed-price field
	// -------------------------------------------------------------------------
	$(document).on('change', 'input[name="jwpb_new_pricing_mode"]', function () {
		$('#jwpb-new-price-row').toggle($(this).val() === 'fixed');
	});

	// -------------------------------------------------------------------------
	// Submit — create or update depending on current mode
	// -------------------------------------------------------------------------
	$('#jwpb-form-submit').on('click', submitForm);

	$('#jwpb-new-pack-name').on('keydown', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			submitForm();
		}
	});

	function submitForm() {
		var name  = $('#jwpb-new-pack-name').val().trim();
		var mode  = $('input[name="jwpb_new_pricing_mode"]:checked').val();
		var price = $('#jwpb-new-pack-price').val().trim();
		var item  = $('#jwpb-new-item-label').val().trim();
		var qty   = $('#jwpb-new-qty-label').val().trim();
		var pid   = parseInt($('#jwpb-form-pack-id').val(), 10) || 0;
		var $btn  = $('#jwpb-form-submit');

		$('#jwpb-form-error').hide();

		if (!name) {
			showFormError(jwpbAdmin.i18n.nameRequired);
			$('#jwpb-new-pack-name').trigger('focus');
			return;
		}

		var isEdit  = pid > 0;
		var action  = isEdit ? 'jwpb_update_pack' : 'jwpb_create_pack';
		var busyMsg = isEdit ? jwpbAdmin.i18n.updating : jwpbAdmin.i18n.creating;
		var doneMsg = isEdit ? jwpbAdmin.i18n.updated  : jwpbAdmin.i18n.created;

		$btn.prop('disabled', true).text(busyMsg);
		$('#jwpb-form-spinner').show();

		var payload = {
			action:       action,
			nonce:        jwpbAdmin.nonce,
			pack_name:    name,
			pricing_mode: mode,
			pack_price:   price,
			item_label:   item,
			qty_label:    qty
		};

		if (isEdit) {
			payload.pack_id = pid;
		}

		$.post(jwpbAdmin.ajaxurl, payload, function (response) {
			if (!response.success) {
				$btn.prop('disabled', false)
					.text(isEdit ? jwpbAdmin.i18n.updatePack : jwpbAdmin.i18n.createPack);
				$('#jwpb-form-spinner').hide();
				showFormError(response.data.message || jwpbAdmin.i18n.error);
				return;
			}

			$btn.text(doneMsg);
			window.location.href = response.data.edit_url || response.data.redirect_url;
		}).fail(function () {
			$btn.prop('disabled', false)
				.text(isEdit ? jwpbAdmin.i18n.updatePack : jwpbAdmin.i18n.createPack);
			$('#jwpb-form-spinner').hide();
			showFormError(jwpbAdmin.i18n.error);
		});
	}

	// -------------------------------------------------------------------------
	// Mode helpers
	// -------------------------------------------------------------------------
	function switchToCreateMode() {
		editMode = false;
		$('#jwpb-form-pack-id').val('0');
		$('#jwpb-form-title').text(jwpbAdmin.i18n.newPackTitle);
		$('#jwpb-form-submit').text(jwpbAdmin.i18n.createPack);
		resetFields();
	}

	function switchToEditMode(data) {
		editMode = true;
		$toggle.removeClass('active');

		$('#jwpb-form-pack-id').val(data.pack_id);
		$('#jwpb-form-title').text(jwpbAdmin.i18n.editPackTitle + ': ' + data.name);
		$('#jwpb-form-submit').text(jwpbAdmin.i18n.updatePack);

		$('#jwpb-new-pack-name').val(data.name);
		$('#jwpb-new-item-label').val(data.item_label || '');
		$('#jwpb-new-qty-label').val(data.qty_label || '');

		var mode = data.pricing_mode || 'sum';
		$('input[name="jwpb_new_pricing_mode"][value="' + mode + '"]').prop('checked', true);
		$('#jwpb-new-pack-price').val(data.pack_price || '');
		$('#jwpb-new-price-row').toggle(mode === 'fixed');

		$('#jwpb-form-error').hide();
		$('#jwpb-form-spinner').hide();
		$('#jwpb-form-submit').prop('disabled', false);
	}

	function closePanel() {
		$panel.slideUp(150);
		$toggle.removeClass('active');
		switchToCreateMode();
	}

	function resetFields() {
		$('#jwpb-new-pack-name').val('');
		$('#jwpb-new-pack-price').val('');
		$('#jwpb-new-item-label').val('');
		$('#jwpb-new-qty-label').val('');
		$('input[name="jwpb_new_pricing_mode"][value="sum"]').prop('checked', true);
		$('#jwpb-new-price-row').hide();
		$('#jwpb-form-error').hide();
		$('#jwpb-form-spinner').hide();
		$('#jwpb-form-submit').prop('disabled', false).text(jwpbAdmin.i18n.createPack);
	}

	function showFormError(msg) {
		$('#jwpb-form-error').text(msg).show();
	}

}(jQuery));
