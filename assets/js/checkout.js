/* Nova Express — чекаут (сумісність із WooCommerce Classic, Blocks та темою Woodmart) */
jQuery(function ($) {
	'use strict';

	var cityTimer = null;
	var warehouseTimer = null;
	var streetTimer = null;

	var foreignSelectors = [
		'#wcus-billing-fields',
		'#wcus-shipping-fields',
		'.wcus-checkout-ukrposhta-fields',
		'.wcus-checkout-np-fields',
		'.wcus-checkout-meest-fields',
		'.wcus-checkout-rozetka-fields',
		'.wcus-checkout-fields',
		'[id*="ukrposhta"]',
		'[class*="ukrposhta"]',
		'[id*="ukr-poshta"]',
		'[class*="ukr-poshta"]',
		'.ukrposhta-fields',
		'.ukr-poshta-fields'
	].join(', ');

	// Стандартні поля WC, зайві для доставки у відділення НП.
	var wcAddressSelectors = [
		'#billing_state_field',
		'#billing_city_field',
		'#billing_postcode_field',
		'#billing_address_1_field',
		'#billing_address_2_field',
		'#shipping_state_field',
		'#shipping_city_field',
		'#shipping_postcode_field',
		'#shipping_address_1_field',
		'#shipping_address_2_field',
		'#billing_state',
		'#billing_city',
		'#billing_postcode',
		'#billing_address_1',
		'#billing_address_2',
		'#shipping_state',
		'#shipping_city',
		'#shipping_postcode',
		'#shipping_address_1',
		'#shipping_address_2',
		'.woocommerce-billing-fields #billing_state_field',
		'.woocommerce-billing-fields #billing_city_field',
		'.woocommerce-billing-fields #billing_postcode_field',
		'p#billing_state_field',
		'p#billing_city_field',
		'p#billing_postcode_field',
		'p#shipping_state_field',
		'p#shipping_city_field',
		'p#shipping_postcode_field'
	].join(', ');

	function isNovaExpressChosen() {
		var chosen = false;
		$('input[name^="shipping_method"]:checked, select[name^="shipping_method"], .shipping_method:checked, input.shipping_method:checked').each(function () {
			var val = String($(this).val() || '');
			if (val.indexOf('nova_express') !== -1) {
				chosen = true;
			}
		});
		return chosen;
	}

	function hideForeignCarrierFields() {
		$(foreignSelectors).each(function () {
			if ($(this).closest('#nvx-checkout-fields').length) {
				return;
			}
			$(this).hide();
		});
	}

	function toggleWcAddressFields(np) {
		var $fields = $(wcAddressSelectors);
		if (np) {
			$fields.each(function () {
				var $el = $(this);
				var $row = $el.is('.form-row') || $el.is('p') ? $el : $el.closest('.form-row, p.form-row, .wd-form-field');
				if ($row.length) {
					$row.hide();
				} else {
					$el.hide();
				}
				$el.find('input, select').addBack('input, select').prop('required', false).removeAttr('required').removeClass('validate-required');
				$el.find('label .required, label abbr.required').hide();
			});
		} else {
			$fields.each(function () {
				var $el = $(this);
				var $row = $el.is('.form-row') || $el.is('p') ? $el : $el.closest('.form-row, p.form-row, .wd-form-field');
				if ($row.length) {
					$row.show();
				} else {
					$el.show();
				}
			});
		}
	}

	function toggleNvxBlock() {
		var np = isNovaExpressChosen();
		var $wrap = $('#nvx-checkout-fields');
		if ($wrap.length) {
			$wrap.toggle(np);
		}
		hideForeignCarrierFields();
		toggleWcAddressFields(np);
		toggleServiceBlocks();
	}

	function toggleServiceBlocks() {
		var $type = $('#nvx_service_type');
		if (!$type.length) {
			return;
		}
		var type = $type.val();
		if (type === 'doors_doors') {
			// Кур'єр: повністю ховаємо відділення/поштомат
			$('#nvx_warehouse_block').hide();
			$('#nvx_point_type_row').hide();
			$('#nvx_warehouse_search_row').hide();
			$('#nvx_street_block').show();
		} else {
			// Відділення або поштомат: показуємо вибір куди доставити і поле пошуку
			$('#nvx_warehouse_block').show();
			$('#nvx_point_type_row').show();
			$('#nvx_warehouse_search_row').show();
			$('#nvx_street_block').hide();
		}
	}

	function fetchCheckoutWarehouses(q) {
		var cityRef = $('#nvx_city_ref').val();
		if (!cityRef) {
			$('#nvx_warehouse_suggest').empty();
			return;
		}
		if (typeof NVX_CHECKOUT === 'undefined' || !NVX_CHECKOUT.ajaxUrl) {
			return;
		}
		var pointType = $('#nvx_point_type').val() || 'warehouse';
		$.get(NVX_CHECKOUT.ajaxUrl, {
			action: 'nvx_local_search_warehouses',
			nonce: NVX_CHECKOUT.nonce,
			city_ref: cityRef,
			type: pointType,
			q: q || ''
		}).done(function (res) {
			var $box = $('#nvx_warehouse_suggest').empty();
			if (!res || !res.success || !res.data.length) {
				var emptyMsg = pointType === 'postomat' ? 'Поштоматів не знайдено' : 'Відділень не знайдено';
				$box.append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text(emptyMsg));
				return;
			}
			res.data.forEach(function (w) {
				var $item = $('<div class="nvx-suggest-item">').text(w.label);
				$item.data('warehouse-ref', w.ref);
				$item.data('warehouse-label', w.label);
				$box.append($item);
			});
		});
	}

	// Делеговані обробники подій — не зникають при перемальовуванні чекауту Woodmart чи WooCommerce
	$(document).on('change', '#nvx_service_type', toggleServiceBlocks);

	$(document).on('change', '#nvx_point_type', function () {
		var pointType = $(this).val();
		var $label = $('#nvx_warehouse_search_label');
		var $input = $('#nvx_warehouse_search');

		if (pointType === 'postomat') {
			$label.html('Поштомат <abbr class="required">*</abbr>');
			$input.attr('placeholder', 'Почніть вводити назву або номер поштомата…');
		} else {
			$label.html('Відділення <abbr class="required">*</abbr>');
			$input.attr('placeholder', 'Почніть вводити назву або номер відділення…');
		}

		$('#nvx_warehouse_ref').val('');
		$('#nvx_warehouse_label').val('');
		$input.val('');
		$('#nvx_warehouse_suggest').empty();

		if ($('#nvx_city_ref').val()) {
			fetchCheckoutWarehouses('');
		}
	});

	$(document).on('input', '#nvx_city_search', function () {
		var q = $(this).val();
		clearTimeout(cityTimer);
		if (q.length < 2) {
			$('#nvx_city_suggest').empty();
			return;
		}
		if (typeof NVX_CHECKOUT === 'undefined') {
			return;
		}
		cityTimer = setTimeout(function () {
			$.get(NVX_CHECKOUT.ajaxUrl, { action: 'nvx_local_search_cities', nonce: NVX_CHECKOUT.nonce, q: q }).done(function (res) {
				var $box = $('#nvx_city_suggest').empty();
				if (!res || !res.success || !res.data || !res.data.length) {
					$box.append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text((NVX_CHECKOUT.i18n && NVX_CHECKOUT.i18n.noResults) || 'Нічого не знайдено'));
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					$item.attr('data-ref', item.ref);
					$item.attr('data-label', item.label);
					$item.data('item-ref', item.ref);
					$item.data('item-label', item.label);
					$box.append($item);
				});
			});
		}, 200);
	});

	function selectCheckoutCity($item) {
		var ref = $item.data('item-ref') || $item.attr('data-ref');
		var label = $item.data('item-label') || $item.attr('data-label') || $item.text();
		if (!ref) {
			return;
		}
		$('#nvx_city_ref').val(ref);
		$('#nvx_city_name').val(label);
		$('#nvx_city_search').val(label);
		$('#nvx_city_suggest').empty();

		$('#nvx_warehouse_ref').val('');
		$('#nvx_warehouse_label').val('');
		$('#nvx_warehouse_search').val('');
		$('#nvx_warehouse_suggest').empty();

		var pointType = $('#nvx_point_type').val() || 'warehouse';
		if ($('#nvx_delivery_type').val() !== 'courier') {
			fetchCheckoutWarehouses('');
		}
	}

	$(document).on('mousedown', '#nvx_city_suggest .nvx-suggest-item', function (e) {
		e.preventDefault();
		selectCheckoutCity($(this));
	});

	$(document).on('click', '#nvx_city_suggest .nvx-suggest-item', function () {
		selectCheckoutCity($(this));
	});

	$(document).on('focus', '#nvx_warehouse_search', function () {
		fetchCheckoutWarehouses($(this).val() || '');
	});

	$(document).on('input', '#nvx_warehouse_search', function () {
		var q = $(this).val();
		clearTimeout(warehouseTimer);
		$('#nvx_warehouse_ref').val('');
		$('#nvx_warehouse_label').val('');
		warehouseTimer = setTimeout(function () {
			fetchCheckoutWarehouses(q);
		}, 250);
	});

	$(document).on('click', '#nvx_warehouse_suggest .nvx-suggest-item', function () {
		var ref = $(this).data('warehouse-ref');
		var label = $(this).data('warehouse-label');
		if (!ref) {
			return;
		}
		$('#nvx_warehouse_ref').val(ref);
		$('#nvx_warehouse_label').val(label);
		$('#nvx_warehouse_search').val(label);
		$('#nvx_warehouse_suggest').empty();
	});

	$(document).on('input', '#nvx_street_search', function () {
		var q = $(this).val();
		var cityRef = $('#nvx_city_ref').val();
		clearTimeout(streetTimer);
		if (q.length < 2 || !cityRef || typeof NVX_CHECKOUT === 'undefined') {
			$('#nvx_street_suggest').empty();
			return;
		}
		streetTimer = setTimeout(function () {
			$.get(NVX_CHECKOUT.ajaxUrl, { action: 'nvx_search_streets', nonce: NVX_CHECKOUT.nonce, city_ref: cityRef, q: q }).done(function (res) {
				var $box = $('#nvx_street_suggest').empty();
				if (!res || !res.success) {
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					$item.data('street-ref', item.ref);
					$item.data('street-label', item.label);
					$box.append($item);
				});
			});
		}, 300);
	});

	$(document).on('click', '#nvx_street_suggest .nvx-suggest-item', function () {
		var ref = $(this).data('street-ref');
		var label = $(this).data('street-label');
		if (!ref) {
			return;
		}
		$('#nvx_street_ref').val(ref);
		$('#nvx_street_search').val(label);
		$('#nvx_street_suggest').empty();
	});

	// Закриття підказок при кліку ззовні
	$(document).on('click', function (e) {
		if (!$(e.target).closest('#nvx_city_search, #nvx_city_suggest').length) {
			$('#nvx_city_suggest').empty();
		}
		if (!$(e.target).closest('#nvx_warehouse_search, #nvx_warehouse_suggest').length) {
			$('#nvx_warehouse_suggest').empty();
		}
		if (!$(e.target).closest('#nvx_street_search, #nvx_street_suggest').length) {
			$('#nvx_street_suggest').empty();
		}
	});

	// Закриття по Esc
	$(document).on('keydown', function (e) {
		if (e.key === 'Escape' || e.keyCode === 27) {
			$('.nvx-suggest').empty();
		}
	});

	// Початкова ініціалізація та відслідковування оновлень чекауту (в т.ч. Woodmart AJAX)
	toggleNvxBlock();
	[150, 400, 1000, 2000].forEach(function (ms) {
		setTimeout(toggleNvxBlock, ms);
	});

	$(document.body).on('updated_checkout init_checkout checkout_error payment_method_selected', function () {
		toggleNvxBlock();
		setTimeout(toggleNvxBlock, 100);
	});

	$(document.body).on('change', 'input[name^="shipping_method"], select[name^="shipping_method"]', function () {
		toggleNvxBlock();
		setTimeout(toggleNvxBlock, 150);
	});

	// Специфічні події теми Woodmart
	$(document).on('woodmart-ajax-load woodmart_theme_init wd_checkout_update', function () {
		toggleNvxBlock();
	});
});

	// --- Автоматична маска та нормалізація телефону (+380...) ---
	function formatUkrainianPhone(val) {
		var digits = val.replace(/\D/g, '');
		if (!digits) return '';

		if (digits.indexOf('380') === 0) {
			digits = digits.substring(2); // Лишаємо 0XXXXXXXXX
		} else if (digits.indexOf('38') === 0 && digits.length > 2) {
			digits = digits.substring(2);
		} else if (digits.charAt(0) !== '0') {
			digits = '0' + digits;
		}

		digits = digits.substring(0, 10); // макс 10 цифр (0XXXXXXXXX)

		var res = '+38 (';
		if (digits.length > 0) {
			res += digits.substring(0, Math.min(3, digits.length));
		}
		if (digits.length >= 3) {
			res += ') ' + digits.substring(3, Math.min(6, digits.length));
		}
		if (digits.length >= 6) {
			res += '-' + digits.substring(6, Math.min(8, digits.length));
		}
		if (digits.length >= 8) {
			res += '-' + digits.substring(8, Math.min(10, digits.length));
		}
		return res;
	}

	function initPhoneMask() {
		if (typeof window.NVX_CHECKOUT !== 'undefined' && !window.NVX_CHECKOUT.enablePhoneMask) {
			return;
		}

		var $phone = ;
		if (!$phone.length) return;

		$phone.attr('placeholder', '+38 (0__) ___-__-__');

		$phone.off('input.nvx_mask blur.nvx_mask').on('input.nvx_mask', function(e) {
			var input = this;
			var val = input.value;
			if (!val) return;
			var formatted = formatUkrainianPhone(val);
			if (formatted && formatted !== val) {
				input.value = formatted;
			}
		});

		// Якщо поле вже заповнене при завантаженні (збережений профіль/autofill)
		if ($phone.val()) {
			var formattedInit = formatUkrainianPhone($phone.val());
			if (formattedInit) $phone.val(formattedInit);
		}
	}

	initPhoneMask();
	.on('updated_checkout', initPhoneMask);
