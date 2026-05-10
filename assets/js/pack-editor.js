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

	// On type change: toggle visibility then, if switching to pack, activate
	// Pack Contents tab in case no pack tab is already active (e.g. General was active).
	$('#product-type').on('change', function () {
		togglePackTabs();
		if ($('#product-type').val() === 'pack' &&
			!$('.product_data_tabs li.show_if_pack').hasClass('active')) {
			$('.jwpb_contents_tab > a').trigger('click');
		}
	});

	togglePackTabs();

	// -------------------------------------------------------------------------
	// Pack Contents — product search + row management
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
				'<td><button type="button" class="button jwpb-remove-item" title="' +
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
	// Form serialization — write JSON blob to hidden field before submit
	// -------------------------------------------------------------------------
	$('#post').on('submit', function () {
		var items = [];

		$('#jwpb-items-tbody tr.jwpb-item-row').each(function (i) {
			var $row  = $(this);
			var pid   = $row.data('product-id');
			var $vsel = $row.find('.jwpb-variation-select');
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
	});

	// -------------------------------------------------------------------------
	// Pack Pricing — radio toggle + sum preview
	// -------------------------------------------------------------------------
	$('input[name="_pack_pricing_mode"]').on('change', function () {
		if ($(this).val() === 'fixed') {
			$('.jwpb-fixed-price-field').show();
		} else {
			$('.jwpb-fixed-price-field').hide();
		}
	});

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

		initProductSearch();

		// Render existing items pre-enriched server-side — no AJAX needed on load.
		$.each(jwpbData.existingItems || [], function (i, item) {
			addRow(item);
		});

		toggleEmptyState();

		if ($('#_pack_seasonal_enabled').is(':checked')) {
			fetchPoolCount();
		}

		// Deferred: run after WooCommerce's own product-type init so our tab
		// activation wins regardless of which script initialised first.
		// If the page loaded as a pack product and no pack tab is yet active
		// (General tab was active and is now hidden), activate Pack Contents.
		setTimeout(function () {
			if ($('#product-type').val() === 'pack' &&
				!$('.product_data_tabs li.show_if_pack').hasClass('active')) {
				$('.jwpb_contents_tab > a').trigger('click');
			}
		}, 0);
	});

}(jQuery));
