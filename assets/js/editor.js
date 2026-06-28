/* global jwpbData, jQuery */
(function ($) {
	'use strict';

	// -------------------------------------------------------------------------
	// Product type toggle — show/hide pack tabs when type dropdown changes
	// -------------------------------------------------------------------------
	function togglePackTabs() {
		var type = $('#product-type').val();
		if (type === 'pack') {
			$('.show_if_pack').show();
			$('.hide_if_pack').hide();
		} else {
			$('.show_if_pack').hide();
			$('.hide_if_pack').show(); // restore e.g. the General tab
		}
	}

	// On type change: toggle visibility. When switching to pack, apply sub-type
	// (standard/custom) layout and activate Pack Contents tab if needed.
	// When switching away from pack, restore the General tab.
	$('#product-type').on('change', function () {
		togglePackTabs();
		if ($(this).val() === 'pack') {
			togglePackType();
			if (!$('.product_data_tabs li.show_if_pack').hasClass('active')) {
				$('.jwpb_contents_tab > a').trigger('click');
			}
		}
	});

	togglePackTabs();

	// -------------------------------------------------------------------------
	// Pack type toggle — Standard / Custom sections + General tab visibility
	// -------------------------------------------------------------------------
	function togglePackType() {
		// Only relevant when the current product type is pack.
		if ($('#product-type').val() !== 'pack') {
			return;
		}

		var isCustom = $('input[name="_pack_type"]:checked').val() === 'custom';
		var $generalTab = $('.general_tab');

		// If switching to standard while the General tab is active, move focus first.
		if (!isCustom && $generalTab.hasClass('active')) {
			$('.jwpb_contents_tab > a').trigger('click');
		}

		// Custom packs use the WC General tab for base price (regular + sale).
		// Standard packs hide it — pricing lives in Pack Contents.
		$generalTab.toggle(isCustom);

		// WC hides price fields inside the General panel for non-simple types via
		// their parent .options_group.show_if_simple — toggling the inner <p> has
		// no effect. Target the container so the entire group is re-shown.
		$('#general_product_data ._regular_price_field')
			.closest('.options_group')
			.toggle(isCustom);

		$('.jwpb-standard-only').toggle(!isCustom);
		$('.jwpb-custom-only').toggle(isCustom);
		togglePricingFields();
	}

	$('input[name="_pack_type"]').on('change', togglePackType);

	// -------------------------------------------------------------------------
	// Pack Contents — product search + row management (standard packs)
	// -------------------------------------------------------------------------

	function initProductSearch() {
		var $search = $('#jwpb-product-search');
		if (!$search.length || typeof $.fn.select2 === 'undefined') {
			return;
		}

		$search.select2({
			ajax: {
				url: jwpbData.ajaxurl,
				dataType: 'json',
				delay: 250,
				data: function (params) {
					return {
						action:       'woocommerce_json_search_products',
						security:     jwpbData.searchNonce,
						term:         params.term,
						exclude_type: 'pack'
					};
				},
				processResults: function (data) {
					var results = [];
					$.each(data, function (id, name) {
						results.push({ id: id, text: name });
					});
					return { results: results };
				},
				cache: true
			},
			minimumInputLength: 2,
			placeholder: $search.data('placeholder') || '…'
		});

		$search.on('select2:select', function (e) {
			var productId = e.params.data.id;
			var $spinner  = $('#jwpb-adding-spinner');

			$(this).val(null).trigger('change');
			$spinner.show();

			$.post(jwpbData.ajaxurl, {
				action:     'jwpb_get_product_info',
				product_id: productId,
				nonce:      jwpbData.adminNonce
			}, function (response) {
				$spinner.hide();
				if (response.success) {
					addRow(response.data);
				} else {
					window.alert(response.data.message || jwpbData.i18n.error);
				}
			}).fail(function () {
				$spinner.hide();
				window.alert(jwpbData.i18n.error);
			});
		});
	}

	/**
	 * Append one row to the pack items table.
	 *
	 * @param {Object} item  {product_id, name, sku, type, price_html,
	 *                        variations[], variation_id?, quantity?}
	 */
	function addRow(item) {
		var productId  = item.product_id;
		var name       = item.name       || '';
		var sku        = item.sku        || '';
		var type       = item.type       || 'simple';
		var variations = item.variations || [];
		var varId      = parseInt(item.variation_id, 10) || 0;
		var qty        = parseInt(item.quantity,     10) || 1;
		var priceHtml  = item.price_html || '';

		var variationCell;

		if (type === 'variable' && variations.length > 0) {
			// Default to the pre-selected variation (existing item) or the first one (new item).
			var initVid = varId || variations[0].id;
			var opts    = '';

			$.each(variations, function (i, v) {
				var sel = v.id === initVid;
				if (sel) {
					priceHtml = v.price_html;
				}
				opts += '<option value="' + v.id + '"' + (sel ? ' selected' : '') + '>' +
					escHtml(v.label) + '</option>';
			});

			variationCell = '<select class="jwpb-variation-select">' + opts + '</select>';
		} else {
			variationCell = '<span style="color:#999;">&mdash;</span>';
		}

		var $tr = $(
			'<tr class="jwpb-item-row">' +
				'<td>' +
					'<strong>' + escHtml(name) + '</strong>' +
					(sku ? '<br><small class="jwpb-item-sku">' + escHtml(sku) + '</small>' : '') +
				'</td>' +
				'<td>' + variationCell + '</td>' +
				'<td><input type="number" class="jwpb-qty-input" value="' + qty + '" min="1" step="1"></td>' +
				'<td class="jwpb-price-cell">' + priceHtml + '</td>' +
				'<td><button type="button" class="button button-small jwpb-remove-item" title="' +
					escHtml(jwpbData.i18n.remove) + '">&times;</button></td>' +
			'</tr>'
		);

		$tr.data('product-id', productId);
		$tr.data('variations', variations);

		$('#jwpb-items-tbody').append($tr);
		toggleEmptyState();
	}

	// Variation dropdown change — update price cell from stored variation data.
	$(document).on('change', '.jwpb-variation-select', function () {
		var $row       = $(this).closest('tr');
		var variations = $row.data('variations') || [];
		var selected   = parseInt($(this).val(), 10);

		$.each(variations, function (i, v) {
			if (v.id === selected) {
				$row.find('.jwpb-price-cell').html(v.price_html);
				return false;
			}
		});

	});

	$(document).on('click', '.jwpb-remove-item', function () {
		$(this).closest('tr').remove();
		toggleEmptyState();
	});

	function toggleEmptyState() {
		var hasRows = $('#jwpb-items-tbody tr.jwpb-item-row').length > 0;
		$('#jwpb-items-empty').toggle(!hasRows);
	}

	// -------------------------------------------------------------------------
	// Custom Pack — addon field groups
	// -------------------------------------------------------------------------

	/**
	 * Shared Select2 ajax config for product search.
	 */
	function fieldProductSearchAjax() {
		return {
			url: jwpbData.ajaxurl,
			dataType: 'json',
			delay: 250,
			data: function (params) {
				return {
					action:       'woocommerce_json_search_products',
					security:     jwpbData.searchNonce,
					term:         params.term,
					exclude_type: 'pack'
				};
			},
			processResults: function (data) {
				var results = [];
				$.each(data, function (id, name) {
					results.push({ id: id, text: name });
				});
				return { results: results };
			},
			cache: true
		};
	}

	/**
	 * Create and append a new addon field group card.
	 *
	 * @param {Object} fieldData  Optional. { label, field_type, products[] }
	 */
	function addAddonField(fieldData) {
		fieldData = fieldData || {};
		var label     = fieldData.label      || '';
		var fieldType = fieldData.field_type || 'checkbox';

		var typeCheckboxSel = fieldType === 'input' ? '' : ' selected';
		var typeInputSel    = fieldType === 'input' ? ' selected' : '';
		var title           = label || jwpbData.i18n.addonField;

		var $field = $(
			'<div class="jwpb-addon-field">' +
				'<div class="jwpb-addon-field-header">' +
					'<div class="jwpb-addon-field-header-left">' +
						'<span class="jwpb-field-toggle-icon">&#9654;</span>' +
						'<span class="jwpb-addon-field-title">' + escHtml(title) + '</span>' +
					'</div>' +
					'<button type="button" class="button button-small jwpb-remove-addon-field">' +
						escHtml(jwpbData.i18n.removeField) +
					'</button>' +
				'</div>' +
				'<div class="jwpb-addon-field-body" style="display:none;">' +
					'<div class="jwpb-addon-field-settings">' +
						'<span class="jwpb-field-setting">' +
							'<span class="jwpb-field-setting-label">' + escHtml(jwpbData.i18n.fieldLabel) + '</span>' +
							'<input type="text" class="regular-text jwpb-addon-field-label-input" value="' + escHtml(label) + '">' +
						'</span>' +
						'<span class="jwpb-field-setting">' +
							'<span class="jwpb-field-setting-label">' + escHtml(jwpbData.i18n.type) + '</span>' +
							'<select class="jwpb-addon-field-type-select">' +
								'<option value="checkbox"' + typeCheckboxSel + '>' + escHtml(jwpbData.i18n.fieldTypeCheckbox) + '</option>' +
								'<option value="input"'    + typeInputSel    + '>' + escHtml(jwpbData.i18n.fieldTypeInput)    + '</option>' +
							'</select>' +
						'</span>' +
					'</div>' +
					'<div class="jwpb-addon-field-products">' +
						'<table class="widefat jwpb-field-products-table">' +
							'<thead><tr>' +
								'<th>' + escHtml(jwpbData.i18n.product)   + '</th>' +
								'<th>' + escHtml(jwpbData.i18n.variation) + '</th>' +
								'<th>' + escHtml(jwpbData.i18n.price)     + '</th>' +
								'<th style="width:40px;"></th>' +
							'</tr></thead>' +
							'<tbody class="jwpb-field-products-tbody"></tbody>' +
							'<tfoot class="jwpb-field-products-empty" style="display:none;"><tr>' +
								'<td colspan="4" style="color:#999; font-style:italic; text-align:center; padding:10px;">' +
									escHtml(jwpbData.i18n.noFieldProducts) +
								'</td>' +
							'</tr></tfoot>' +
						'</table>' +
						'<div class="jwpb-field-add-option-row">' +
							'<button type="button" class="button button-small jwpb-add-field-option">' +
								escHtml(jwpbData.i18n.addOption) +
							'</button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</div>'
		);

		$('#jwpb-addon-fields-container').append($field);

		var $tbody = $field.find('.jwpb-field-products-tbody');
		$.each(fieldData.products || [], function (i, item) {
			addFieldProduct($tbody, item, $field);
		});

		toggleFieldEmptyState($field);
		updateAddonFieldsEmptyState();
	}

	/**
	 * Insert a new empty option row with an embedded product search.
	 * Used by Checkbox-type fields via the "Add Option" button.
	 */
	function addOptionRow($tbody, $field) {
		var $tr = $(
			'<tr class="jwpb-field-product-row">' +
				'<td class="jwpb-row-search-cell">' +
					'<select class="jwpb-product-search jwpb-row-product-search"' +
						' data-placeholder="' + escHtml(jwpbData.i18n.searchProducts) + '">' +
					'</select>' +
					'<span class="spinner jwpb-row-spinner"' +
						' style="float:none; margin:4px 6px; visibility:visible; display:none;"></span>' +
				'</td>' +
				'<td class="jwpb-variation-cell"><span style="color:#999;">&mdash;</span></td>' +
				'<td class="jwpb-price-cell"></td>' +
				'<td><button type="button" class="button button-small jwpb-remove-field-product" title="' +
					escHtml(jwpbData.i18n.remove) + '">&times;</button></td>' +
			'</tr>'
		);

		$tbody.append($tr);
		toggleFieldEmptyState($field);

		if (typeof $.fn.select2 === 'undefined') {
			return;
		}

		var $search  = $tr.find('.jwpb-row-product-search');
		var $spinner = $tr.find('.jwpb-row-spinner');

		$search.select2({
			ajax:               fieldProductSearchAjax(),
			minimumInputLength: 2,
			placeholder:        $search.data('placeholder') || '…',
			dropdownParent:     $field
		});

		$search.on('select2:select', function (e) {
			var productId = e.params.data.id;
			$spinner.show();

			$.post(jwpbData.ajaxurl, {
				action:     'jwpb_get_product_info',
				product_id: productId,
				nonce:      jwpbData.adminNonce
			}, function (response) {
				$spinner.hide();
				if (response.success) {
					populateOptionRow($tr, response.data, $field);
				} else {
					window.alert(response.data.message || jwpbData.i18n.error);
				}
			}).fail(function () {
				$spinner.hide();
				window.alert(jwpbData.i18n.error);
			});
		});
	}

	/**
	 * Convert a search row into a product row after a product is selected.
	 */
	function populateOptionRow($tr, item, $field) {
		var productId  = item.product_id;
		var name       = item.name       || '';
		var sku        = item.sku        || '';
		var type       = item.type       || 'simple';
		var variations = item.variations || [];
		var varId      = parseInt(item.variation_id, 10) || 0;
		var priceHtml  = item.price_html || '';

		var $searchCell = $tr.find('.jwpb-row-search-cell');
		var $s2         = $searchCell.find('.jwpb-row-product-search');
		if ($s2.data('select2')) {
			$s2.select2('destroy');
		}
		$searchCell.html(
			'<strong>' + escHtml(name) + '</strong>' +
			(sku ? '<br><small class="jwpb-item-sku">' + escHtml(sku) + '</small>' : '')
		);
		$searchCell.removeClass('jwpb-row-search-cell');

		$tr.data('product-id', productId);
		$tr.data('variations', variations);

		var $varCell = $tr.find('.jwpb-variation-cell');
		if (type === 'variable' && variations.length > 0) {
			var initVid = varId || variations[0].id;
			var opts    = '';
			$.each(variations, function (i, v) {
				var sel = v.id === initVid;
				if (sel) { priceHtml = v.price_html; }
				opts += '<option value="' + v.id + '"' + (sel ? ' selected' : '') + '>' +
					escHtml(v.label) + '</option>';
			});
			$varCell.html('<select class="jwpb-variation-select jwpb-field-variation-select">' + opts + '</select>');
		} else {
			$varCell.html('<span style="color:#999;">&mdash;</span>');
		}

		$tr.find('.jwpb-price-cell').html(priceHtml);
	}

	/**
	 * Render a pre-loaded product row (existing saved items) without a search cell.
	 */
	function addFieldProduct($tbody, item, $field) {
		var productId  = item.product_id;
		var name       = item.name       || '';
		var sku        = item.sku        || '';
		var type       = item.type       || 'simple';
		var variations = item.variations || [];
		var varId      = parseInt(item.variation_id, 10) || 0;
		var priceHtml  = item.price_html || '';

		var variationCell;

		if (type === 'variable' && variations.length > 0) {
			var initVid = varId || variations[0].id;
			var opts    = '';
			$.each(variations, function (i, v) {
				var sel = v.id === initVid;
				if (sel) { priceHtml = v.price_html; }
				opts += '<option value="' + v.id + '"' + (sel ? ' selected' : '') + '>' +
					escHtml(v.label) + '</option>';
			});
			variationCell = '<select class="jwpb-variation-select jwpb-field-variation-select">' + opts + '</select>';
		} else {
			variationCell = '<span style="color:#999;">&mdash;</span>';
		}

		var $tr = $(
			'<tr class="jwpb-field-product-row">' +
				'<td>' +
					'<strong>' + escHtml(name) + '</strong>' +
					(sku ? '<br><small class="jwpb-item-sku">' + escHtml(sku) + '</small>' : '') +
				'</td>' +
				'<td class="jwpb-variation-cell">' + variationCell + '</td>' +
				'<td class="jwpb-price-cell">' + priceHtml + '</td>' +
				'<td><button type="button" class="button button-small jwpb-remove-field-product" title="' +
					escHtml(jwpbData.i18n.remove) + '">&times;</button></td>' +
			'</tr>'
		);

		$tr.data('product-id', productId);
		$tr.data('variations', variations);

		$tbody.append($tr);
		toggleFieldEmptyState($field);
	}

	function toggleFieldEmptyState($field) {
		var hasRows = $field.find('.jwpb-field-products-tbody tr.jwpb-field-product-row').length > 0;
		$field.find('.jwpb-field-products-empty').toggle(!hasRows);
	}

	function updateAddonFieldsEmptyState() {
		var hasFields = $('#jwpb-addon-fields-container .jwpb-addon-field').length > 0;
		$('#jwpb-addon-fields-empty').toggle(!hasFields);
	}

	$('#jwpb-add-addon-field-btn').on('click', function () {
		addAddonField();
	});

	// Collapse/expand field body on header click (ignore Remove button clicks).
	$(document).on('click', '.jwpb-addon-field-header', function (e) {
		if ($(e.target).closest('.jwpb-remove-addon-field').length) {
			return;
		}
		var $field = $(this).closest('.jwpb-addon-field');
		var $body  = $field.find('.jwpb-addon-field-body');
		var $icon  = $field.find('.jwpb-field-toggle-icon');
		var open   = $field.hasClass('jwpb-addon-field--open');

		$body.slideToggle(150);
		$field.toggleClass('jwpb-addon-field--open', !open);
		$icon.html(open ? '&#9654;' : '&#9660;');
	});

	// Update field heading live as the admin types the label.
	$(document).on('input', '.jwpb-addon-field-label-input', function () {
		var $field = $(this).closest('.jwpb-addon-field');
		var val    = $(this).val().trim();
		$field.find('.jwpb-addon-field-title').text(val || jwpbData.i18n.addonField);
	});

	$(document).on('click', '.jwpb-add-field-option', function () {
		var $field = $(this).closest('.jwpb-addon-field');
		addOptionRow($field.find('.jwpb-field-products-tbody'), $field);
	});

	$(document).on('click', '.jwpb-remove-addon-field', function () {
		$(this).closest('.jwpb-addon-field').remove();
		updateAddonFieldsEmptyState();
	});

	$(document).on('click', '.jwpb-remove-field-product', function () {
		var $tr    = $(this).closest('tr');
		var $field = $(this).closest('.jwpb-addon-field');
		$tr.remove();
		toggleFieldEmptyState($field);
	});

	$(document).on('change', '.jwpb-field-variation-select', function () {
		var $row       = $(this).closest('tr');
		var variations = $row.data('variations') || [];
		var selected   = parseInt($(this).val(), 10);

		$.each(variations, function (i, v) {
			if (v.id === selected) {
				$row.find('.jwpb-price-cell').html(v.price_html);
				return false;
			}
		});
	});

	// -------------------------------------------------------------------------
	// Form serialization — write JSON blobs to hidden fields before submit
	// -------------------------------------------------------------------------
	$('#post').on('submit', function () {
		var items = [];

		$('#jwpb-items-tbody tr.jwpb-item-row').each(function (i) {
			var $row  = $(this);
			var pid   = $row.data('product-id');
			var $vsel = $row.find('.jwpb-variation-select').not('.jwpb-field-variation-select');
			var vid   = $vsel.length ? (parseInt($vsel.val(), 10) || 0) : 0;
			var qty   = parseInt($row.find('.jwpb-qty-input').val(), 10) || 1;

			if (pid) {
				items.push({
					product_id:   pid,
					variation_id: vid,
					quantity:     qty,
					sort_order:   i
				});
			}
		});

		$('#jwpb-items-json').val(JSON.stringify(items));

		// Collect addon field groups (custom pack type).
		var addonFields = [];

		$('#jwpb-addon-fields-container .jwpb-addon-field').each(function () {
			var $field    = $(this);
			var label     = $field.find('.jwpb-addon-field-label-input').val() || '';
			var fieldType = $field.find('.jwpb-addon-field-type-select').val() || 'checkbox';
			var products  = [];

			$field.find('.jwpb-field-products-tbody tr.jwpb-field-product-row').each(function () {
				var $row = $(this);
				var pid  = $row.data('product-id');
				var $vs  = $row.find('.jwpb-field-variation-select');
				var vid  = $vs.length ? (parseInt($vs.val(), 10) || 0) : 0;

				if (pid) {
					products.push({ product_id: pid, variation_id: vid });
				}
			});

			if (products.length) {
				addonFields.push({ label: label, field_type: fieldType, products: products });
			}
		});

		$('#jwpb-addon-fields-json').val(JSON.stringify(addonFields));
	});

	// -------------------------------------------------------------------------
	// Pack Pricing — radio toggle + sum preview
	// -------------------------------------------------------------------------
	function togglePricingFields() {
		var mode = $('input[name="_pack_pricing_mode"]:checked').val();
		$('.jwpb-fixed-price-field').toggle(mode === 'fixed');
	}

	$('input[name="_pack_pricing_mode"]').on('change', togglePricingFields);

	// -------------------------------------------------------------------------
	// Subscription — toggle fields on checkbox change
	// -------------------------------------------------------------------------
	$('#_pack_subscription_enabled').on('change', function () {
		$('.jwpb-subscription-fields').toggle($(this).is(':checked'));
	});

	// -------------------------------------------------------------------------
	// Seasonal — toggle fields + pool count + Rotate Now
	// -------------------------------------------------------------------------
	$('#_pack_seasonal_enabled').on('change', function () {
		if ($(this).is(':checked')) {
			$('.jwpb-seasonal-fields').show();
			fetchPoolCount();
		} else {
			$('.jwpb-seasonal-fields').hide();
		}
	});

	function fetchPoolCount() {
		$.post(jwpbData.ajaxurl, {
			action: 'jwpb_get_pool_count',
			nonce:  jwpbData.adminNonce
		}, function (response) {
			if (response.success) {
				$('#jwpb-pool-count').text(response.data.count);
			}
		});
	}

	$(document).on('click', '#jwpb-rotate-btn', function () {
		var $btn    = $(this);
		var packId  = $btn.data('pack-id');
		var nonce   = $btn.data('nonce');
		var $status = $('#jwpb-rotate-status');

		$btn.prop('disabled', true);
		$status.text(jwpbData.i18n.rotating);

		$.post(jwpbData.ajaxurl, {
			action:  'jwpb_rotate_seasonal',
			pack_id: packId,
			nonce:   nonce
		}, function (response) {
			$btn.prop('disabled', false);

			if (!response.success) {
				$status.text(jwpbData.i18n.error);
				return;
			}

			$status.text(jwpbData.i18n.rotated);
			$('#jwpb-last-rotated').text(response.data.rotated_at || '');

			$('#jwpb-items-tbody').empty();
			$.each(response.data.items || [], function (i, item) {
				addRow(item);
			});
			toggleEmptyState();

			setTimeout(function () { $status.text(''); }, 4000);
		}).fail(function () {
			$btn.prop('disabled', false);
			$status.text(jwpbData.i18n.error);
		});
	});

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------
	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------
	$(function () {
		// Auto-select Pack type when arriving via the "+ Add Pack" link.
		if (jwpbData.defaultType && $('#product-type').val() !== jwpbData.defaultType) {
			$('#product-type').val(jwpbData.defaultType).trigger('change');
		}

		// Apply pack type (standard/custom) visibility state. Deferred so it runs
		// after any WC product-type init that may re-hide .show_if_simple elements.
		setTimeout(function () {
			togglePackType();
			togglePricingFields();
		}, 0);

		initProductSearch();

		// Render existing standard items pre-enriched server-side.
		$.each(jwpbData.existingItems || [], function (i, item) {
			addRow(item);
		});
		toggleEmptyState();

		// Render existing addon field groups (custom packs).
		$.each(jwpbData.existingAddonFields || [], function (i, field) {
			addAddonField(field);
		});
		updateAddonFieldsEmptyState();

		if ($('#_pack_seasonal_enabled').is(':checked')) {
			fetchPoolCount();
		}

		// Deferred: run after WooCommerce's own product-type init so our tab
		// activation wins regardless of which script initialised first.
		setTimeout(function () {
			if ($('#product-type').val() === 'pack' &&
				!$('.product_data_tabs li.show_if_pack').hasClass('active')) {
				$('.jwpb_contents_tab > a').trigger('click');
			}
		}, 0);
	});

}(jQuery));
