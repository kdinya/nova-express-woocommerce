/* Nova Express — сторінка створення ТТН: місця, пошук адреси, відправка форми. */
jQuery(function ($) {
	'use strict';

	var $app = $('.nvx-cw-app');
	var ajaxUrl = $app.data('ajax-url');
	var nonce = $app.data('nonce');
	var orderId = $app.data('order-id');

	var placeSeq = 0;

	function post(action, data) {
		return $.post(ajaxUrl, Object.assign({ action: action, nonce: nonce }, data));
	}

	function get(action, data) {
		return $.get(ajaxUrl, Object.assign({ action: action, nonce: nonce }, data));
	}

	// ---- Місця (вага/габарити) ----
	var currentWarehouseIsPostomat = parseInt($app.data('is-postomat'), 10) === 1 ||
		($('#nvx-cw-warehouse-search').val() && $('#nvx-cw-warehouse-search').val().toLowerCase().indexOf('поштомат') !== -1);
	var warehouseLimits = null;

	function checkIsPostomat() {
		var searchVal = ($('#nvx-cw-warehouse-search').val() || '').toLowerCase();
		var labelVal  = ($('#nvx-cw-warehouse-label').val() || '').toLowerCase();
		return currentWarehouseIsPostomat || searchVal.indexOf('поштомат') !== -1 || labelVal.indexOf('поштомат') !== -1;
	}

	function validatePostomatLimits() {
		var isPostomat = checkIsPostomat();
		var $alert = $('#nvx-cw-postomat-alert');
		var errors = [];

		// Очищаємо підсвічування помилок
		$('#nvx-cw-places input').css({ 'border-color': '', 'background': '' });
		$('#nvx-cw-declared-cost').css({ 'border-color': '', 'background': '' });

		if (!isPostomat) {
			$alert.hide().empty();
			$('#nvx-cw-submit').prop('disabled', false).removeClass('nvx-btn--disabled');
			return true;
		}

		var $places = $('#nvx-cw-places .nvx-cw-place');
		if ($places.length > 1) {
			errors.push('⚠ <strong>Увага (поштомат):</strong> у комірку поштомата можна помістити лише <strong>1 місце</strong> (зараз додано ' + $places.length + '). Об’єднайте вантаж або оберіть вантажне відділення.');
		}

		$places.each(function (idx) {
			var $p = $(this);
			var num = idx + 1;
			var $w = $p.find('.nvx-p-weight');
			var $width = $p.find('.nvx-p-width');
			var $height = $p.find('.nvx-p-height');
			var $length = $p.find('.nvx-p-length');

			var weight = parseFloat($w.val()) || 0;
			var w = parseFloat($width.val()) || 0;
			var h = parseFloat($height.val()) || 0;
			var l = parseFloat($length.val()) || 0;

			// Вага до 20 кг для поштомата
			if (weight > 20) {
				$w.css({ 'border-color': '#dc2626', 'background': '#fef2f2' });
				errors.push('⚠ <strong>Місце №' + num + ':</strong> вага ' + weight + ' кг перевищує ліміт поштомата (максимум <strong>20 кг</strong>).');
			}

			// Габарити комірки: довжина до 60 см, ширина до 40 см, висота до 30 см (або в будь-якій орієнтації)
			var dims = [l, w, h].sort(function(a, b) { return b - a; }); // спадання
			var maxLimit1 = 60, maxLimit2 = 40, maxLimit3 = 30;

			if (warehouseLimits) {
				var custom = [
					warehouseLimits.length || 60,
					warehouseLimits.width || 40,
					warehouseLimits.height || 30
				].sort(function(a, b) { return b - a; });
				maxLimit1 = custom[0];
				maxLimit2 = custom[1];
				maxLimit3 = custom[2];
			}

			if (dims[0] > maxLimit1 || dims[1] > maxLimit2 || dims[2] > maxLimit3) {
				[$width, $height, $length].forEach(function ($f) {
					var val = parseFloat($f.val()) || 0;
					if (val > 60 || (val > 40 && dims[1] > 40) || (val > 30 && dims[2] > 30)) {
						$f.css({ 'border-color': '#dc2626', 'background': '#fef2f2' });
					}
				});
				errors.push('⚠ <strong>Місце №' + num + ':</strong> габарити (' + l + '×' + w + '×' + h + ' см) перевищують розмір комірки поштомата (максимум <strong>40×60×30 см</strong>).');
			}
		});

		// Оголошена вартість у поштоматах до 29 000 грн
		var declaredCost = parseFloat($('#nvx-cw-declared-cost').val()) || 0;
		if (declaredCost > 29000) {
			$('#nvx-cw-declared-cost').css({ 'border-color': '#dc2626', 'background': '#fef2f2' });
			errors.push('⚠ <strong>Оголошена вартість:</strong> ' + declaredCost + ' грн перевищує ліміт для поштоматів (максимум <strong>29 000 грн</strong>).');
		}

		if (errors.length > 0) {
			$alert.html(errors.join('<br>')).show();
			$('#nvx-cw-submit').prop('disabled', true).addClass('nvx-btn--disabled');
			return false;
		} else {
			$alert.hide().empty();
			$('#nvx-cw-submit').prop('disabled', false).removeClass('nvx-btn--disabled');
			return true;
		}
	}

	function addPlace(prefill) {
		placeSeq++;
		var id = 'nvx-place-' + placeSeq;
		var w = (prefill && prefill.weight) || $app.data('order-weight') || 0.1;

		var $place = $(
			'<div class="nvx-cw-place" id="' + id + '">' +
				'<div class="nvx-cw-place__head"><strong>Місце</strong><button type="button" class="nvx-icon-btn nvx-cw-place-remove">✕</button></div>' +
				'<div class="nvx-cw-place__grid">' +
					'<label>Вага (кг)<input type="number" step="0.1" min="0.1" class="nvx-p-weight" value="' + w + '"></label>' +
					'<label>Ширина (см)<input type="number" min="1" max="120" step="0.1" class="nvx-p-width" value="10"></label>' +
					'<label>Висота (см)<input type="number" min="1" max="120" step="0.1" class="nvx-p-height" value="10"></label>' +
					'<label>Довжина (см)<input type="number" min="1" max="120" step="0.1" class="nvx-p-length" value="10"></label>' +
				'</div>' +
				'<div class="nvx-cw-place__volume">Об\u2019ємна вага: <span class="nvx-p-volume">0.25</span></div>' +
			'</div>'
		);

		$place.find('input').on('input', function () {
			recalcVolume($place);
		});
		$place.find('.nvx-cw-place-remove').on('click', function () {
			if ($('#nvx-cw-places .nvx-cw-place').length > 1) {
				$place.remove();
			}
		});

		$('#nvx-cw-places').append($place);
		recalcVolume($place);
	}

	setTimeout(validatePostomatLimits, 200);

	function recalcVolume($place) {
		var w = parseFloat($place.find('.nvx-p-width').val()) || 0;
		var h = parseFloat($place.find('.nvx-p-height').val()) || 0;
		var l = parseFloat($place.find('.nvx-p-length').val()) || 0;
		var vol = (w * h * l) / 4000;
		$place.find('.nvx-p-volume').text(vol.toFixed(2));
	}

	function collectPlaces() {
		var places = [];
		$('#nvx-cw-places .nvx-cw-place').each(function () {
			var $p = $(this);
			places.push({
				weight: parseFloat($p.find('.nvx-p-weight').val()) || 0.1,
				width: parseFloat($p.find('.nvx-p-width').val()) || 10,
				height: parseFloat($p.find('.nvx-p-height').val()) || 10,
				length: parseFloat($p.find('.nvx-p-length').val()) || 10
			});
		});
		return places;
	}

	addPlace();
	$('#nvx-cw-add-place').on('click', function () {
		addPlace();
		validatePostomatLimits();
	});

	// ---- Тип доставки: відділення / адреса ----
	$('input[name=nvx-cw-delivery-type]').on('change', function () {
		var isAddress = $(this).val() === 'address' && $(this).is(':checked');
		if ($(this).is(':checked')) {
			$('#nvx-cw-warehouse-block').toggle(!isAddress);
			$('#nvx-cw-address-block').toggle(isAddress);
		}
	});

	// ---- Пошук міста (локальна база + fallback до API) ----
	var cityTimer = null;
	$('#nvx-cw-city-search').on('input', function () {
		var q = $(this).val();
		clearTimeout(cityTimer);
		if (q.length < 1) {
			$('#nvx-cw-city-suggest').empty();
			return;
		}
		cityTimer = setTimeout(function () {
			get('nvx_local_search_cities', { q: q }).done(function (res) {
				var $box = $('#nvx-cw-city-suggest').empty();
				if (!res || !res.success || !res.data || !res.data.length) {
					$box.append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text('Нічого не знайдено'));
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					function chooseCity(e) {
						if (e) {
							e.preventDefault();
							e.stopPropagation();
						}
						$('#nvx-cw-city-ref').val(item.ref);
						$('#nvx-cw-city-search').val(item.label);
						$box.empty();
						$('#nvx-cw-warehouse-ref').val('');
						$('#nvx-cw-warehouse-label').val('');
						$('#nvx-cw-warehouse-search').val('').attr('placeholder', 'Почніть вводити назву або номер відділення…');
						$('#nvx-cw-warehouse-suggest').empty();
					}
					$item.on('mousedown', chooseCity);
					$item.on('click', chooseCity);
					$box.append($item);
				});
			});
		}, 200);
	});

	$(document).on('click', function (e) {
		if (!$(e.target).closest('#nvx-cw-city-search, #nvx-cw-city-suggest').length) {
			$('#nvx-cw-city-suggest').empty();
		}
		if (!$(e.target).closest('#nvx-cw-warehouse-search, #nvx-cw-warehouse-suggest').length) {
			$('#nvx-cw-warehouse-suggest').empty();
		}
		if (!$(e.target).closest('#nvx-cw-street-search, #nvx-cw-street-suggest').length) {
			$('#nvx-cw-street-suggest').empty();
		}
	});

	// Відділення: клік/фокус без тексту → усі по місту; введення → фільтр
	var warehouseTimer = null;
	function fetchWarehousesSuggest(q) {
		var cityRef = $('#nvx-cw-city-ref').val() || $app.data('default-city-ref');
		var $box = $('#nvx-cw-warehouse-suggest');
		if (!cityRef) {
			$box.empty().append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text('Спочатку оберіть місто'));
			return;
		}
		$box.html('<div class="nvx-suggest-item nvx-suggest-item--muted">Завантаження…</div>');
		get('nvx_local_search_warehouses', { city_ref: cityRef, q: q || '' }).done(function (res) {
			$box.empty();
			if (!res || !res.success || !res.data.length) {
				$box.append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text('Нічого не знайдено'));
				return;
			}
			res.data.forEach(function (w) {
				var $item = $('<div class="nvx-suggest-item">').text(w.label);
				$item.on('mousedown', function (e) {
					e.preventDefault();
					e.stopPropagation();
					$('#nvx-cw-warehouse-ref').val(w.ref);
					$('#nvx-cw-warehouse-label').val(w.label);
					$('#nvx-cw-warehouse-search').val(w.label);
					$box.empty();
					currentWarehouseIsPostomat = !!w.is_postomat || (w.label && w.label.toLowerCase().indexOf('поштомат') !== -1);
					warehouseLimits = (w.max_dim_width || w.max_dim_height || w.max_dim_length) ? {
						width: w.max_dim_width,
						height: w.max_dim_height,
						length: w.max_dim_length
					} : null;
					validatePostomatLimits();
				});
				$box.append($item);
			});
		});
	}
	$('#nvx-cw-warehouse-search').on('focus click', function () {
		fetchWarehousesSuggest('');
	});
	
	$('#nvx-cw-declared-cost').on('input', function () {
		validatePostomatLimits();
	});
	$('#nvx-cw-warehouse-search').on('input', function () {
		currentWarehouseIsPostomat = ($(this).val() || '').toLowerCase().indexOf('поштомат') !== -1;
		validatePostomatLimits();
		var q = $(this).val();
		clearTimeout(warehouseTimer);
		$('#nvx-cw-warehouse-ref').val('');
		$('#nvx-cw-warehouse-label').val('');
		warehouseTimer = setTimeout(function () {
			fetchWarehousesSuggest(q);
		}, 200);
	});

	// ---- Пошук вулиці (живий API, локально вулиці не кешуються) ----
	var streetTimer = null;
	$('#nvx-cw-street-search').on('input', function () {
		var q = $(this).val();
		var cityRef = $('#nvx-cw-city-ref').val();
		clearTimeout(streetTimer);
		if (q.length < 2 || !cityRef) {
			$('#nvx-cw-street-suggest').empty();
			return;
		}
		streetTimer = setTimeout(function () {
			get('nvx_search_streets', { city_ref: cityRef, q: q }).done(function (res) {
				var $box = $('#nvx-cw-street-suggest').empty();
				if (!res || !res.success) {
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					$item.on('click', function () {
						$('#nvx-cw-street-ref').val(item.ref);
						$('#nvx-cw-street-search').val(item.label);
						$box.empty();
					});
					$box.append($item);
				});
			});
		}, 300);
	});

	// ---- Відправка форми ----
	function showAlert(type, message, isHtml) {
		var $box = $('#nvx-cw-alert').removeClass('nvx-alert--success nvx-alert--error').addClass('nvx-alert--' + type).empty();
		if (isHtml) {
			$box.html(message);
		} else {
			$box.text(message);
		}
		$box.show();
	}

	$('#nvx-cw-submit').on('click', function () {
		var $btn = $(this);
		var $status = $('#nvx-cw-status');
		var deliveryType = $('input[name=nvx-cw-delivery-type]:checked').val();

		if (!$('#nvx-cw-city-ref').val()) {
			showAlert('error', 'Оберіть місто отримувача.');
			return;
		}
		if (deliveryType === 'warehouse' && !$('#nvx-cw-warehouse-ref').val()) {
			showAlert('error', 'Оберіть відділення отримувача.');
			return;
		}
		var dimOk = true;
		$('#nvx-cw-places .nvx-cw-place').each(function () {
			['.nvx-p-width', '.nvx-p-height', '.nvx-p-length'].forEach(function (sel) {
				var v = parseFloat($(this).find(sel).val()) || 0;
				if (v < 1 || v > 120) { dimOk = false; }
			}.bind(this));
		});
		if (!dimOk) {
			showAlert('error', 'Габарити кожного місця: від 1 до 120 см (ліміт відділення НП). Для поштомата зазвичай до 40×60×30 см.');
			return;
		}
		if (deliveryType === 'address' && !$('#nvx-cw-building').val()) {
			showAlert('error', 'Вкажіть номер будинку отримувача.');
			return;
		}

		$btn.prop('disabled', true);
		$status.text('Створюємо ТТН…');

		var payload = {
			sender_id: $('#nvx-cw-sender-id').val() || 'primary',
			order_id: orderId,
			service_type: $('#nvx-cw-service-type').val(),
			payer_type: $('#nvx-cw-payer-type').val(),
			cargo_type: $('#nvx-cw-cargo-type').val() || 'Parcel',
			payment_method: $('#nvx-cw-payment-method').val(),
			date: formatDate($('#nvx-cw-date').val()),
			places: JSON.stringify(collectPlaces()),
			declared_cost: $('#nvx-cw-declared-cost').val(),
			description: $('#nvx-cw-description').val(),
			internal_number: $('#nvx-cw-internal-number').val(),
			additional_info: $('#nvx-cw-additional-info').val(),
			recipient_last_name: $('#nvx-cw-last-name').val(),
			recipient_first_name: $('#nvx-cw-first-name').val(),
			recipient_middle_name: $('#nvx-cw-middle-name').val(),
			recipient_email: $('#nvx-cw-email').val(),
			recipient_phone: $('#nvx-cw-phone').val(),
			recipient_city_ref: $('#nvx-cw-city-ref').val(),
			recipient_city_name: $('#nvx-cw-city-search').val()
		};

		if (deliveryType === 'warehouse') {
			payload.recipient_warehouse_ref = $('#nvx-cw-warehouse-ref').val();
		} else {
			payload.recipient_street_ref = $('#nvx-cw-street-ref').val();
			payload.recipient_building = $('#nvx-cw-building').val();
			payload.recipient_apartment = $('#nvx-cw-apartment').val();
		}

		post('nvx_create_waybill', payload)
			.done(function (res) {
				if (res && res.success) {
					var ttnId = res.data.id || 0;
					var printBase = $app.data('print-base') || '';
					var customUrl = printBase + '&ttn_id=' + encodeURIComponent(ttnId) + '&order_id=' + encodeURIComponent(orderId) + '&format=custom';
					var html = 'ТТН №<strong class="nvx-cw-success-ttn">' + res.data.waybill_number + '</strong> успішно створено.' +
						'<div class="nvx-cw-print-actions" style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;">' +
						'<a class="nvx-btn nvx-btn--primary" href="' + customUrl + '" target="_blank" rel="noopener">Друкувати</a>' +
						'</div>';
					showAlert('success', html, true);
					$status.text('Готово ✓');
					$btn.prop('disabled', true).text('Створено');
					// До самого верху сторінки (кілька спроб — WP admin / sticky bar)
					(function scrollTopHard() {
						try {
							window.scrollTo(0, 0);
							document.documentElement.scrollTop = 0;
							document.body.scrollTop = 0;
							$('html, body').stop(true).animate({ scrollTop: 0 }, 400);
						} catch (err) {}
						setTimeout(function () {
							window.scrollTo(0, 0);
							document.documentElement.scrollTop = 0;
							document.body.scrollTop = 0;
						}, 50);
						setTimeout(function () {
							window.scrollTo({ top: 0, behavior: 'smooth' });
						}, 120);
					})();

					if (window.opener && !window.opener.closed) {
						try {
							window.opener.postMessage({ type: 'nvx_waybill_created', orderId: orderId, waybillNumber: res.data.waybill_number, ttnId: ttnId }, window.location.origin);
						} catch (e) { /* різні джерела — ігноруємо */ }
					}
				} else {
					var msg = (res && res.data && res.data.message) || 'Помилка створення ТТН.';
					showAlert('error', msg);
					$status.text('');
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка з\'єднання.';
				showAlert('error', msg);
				$status.text('');
				$btn.prop('disabled', false);
			});
	});

	function formatDate(isoDate) {
		if (!isoDate) {
			return '';
		}
		var parts = isoDate.split('-');
		return parts.length === 3 ? parts[2] + '.' + parts[1] + '.' + parts[0] : '';
	}


	// Оновлення картки адреси відправника при зміні відділення
	function updateSenderCard() {
		var $opt = $('#nvx-cw-sender-id option:selected');
		if (!$opt.length) return;
		var city = $opt.data('city') || '';
		var label = $opt.data('label') || $opt.text();
		var contact = $opt.data('contact') || '';
		var phone = $opt.data('phone') || '';
		var $card = $('.nvx-cw-sender-card');
		if (!$card.length) return;
		var html = '<span class="nvx-cw-sender-card__label">Адреса відправлення</span>';
		if (city) {
			html += '<strong>' + city + '</strong>';
		}
		html += '<span class="nvx-cw-sender-card__ref">' + label + '</span>';
		if (contact) {
			html += '<span class="nvx-cw-sender-card__contact">' + contact + (phone ? ' · ' + phone : '') + '</span>';
		}
		$card.html(html);
	}
	$('#nvx-cw-sender-id').on('change', function () {
		updateSenderCard();
	});
	updateSenderCard();


	// Розрахунок вартості доставки — лише по кнопці (без автозапитів).
	function totalWeight() {
		var w = 0;
		$('#nvx-cw-places .nvx-cw-place').each(function () {
			w += parseFloat($(this).find('.nvx-p-weight').val()) || 0;
		});
		return Math.max(0.1, w);
	}

	function updateDeliveryPrice() {
		var $val = $('#nvx-cw-price-value');
		var $hint = $('#nvx-cw-price-hint');
		var $btn = $('#nvx-cw-calc-price');
		if (!$val.length) return;

		var cityRef = $('#nvx-cw-city-ref').val();
		if (!cityRef) {
			$val.text('—');
			$hint.text('Спочатку оберіть місто отримувача');
			return;
		}

		$btn.prop('disabled', true).text('Розрахунок…');
		$val.text('…');
		$hint.text('Запит до Nova Poshta…');

		var declaredCost = $('#nvx-cw-declared-cost').val() || $('#nvx-cw-declared-cost').attr('placeholder') || '200';
		post('nvx_calculate_delivery_price', {
			sender_id: $('#nvx-cw-sender-id').val() || 'primary',
			recipient_city_ref: cityRef,
			service_type: $('#nvx-cw-service-type').val() || 'warehouse_warehouse',
			cargo_type: $('#nvx-cw-cargo-type').val() || 'Parcel',
			weight: totalWeight(),
			declared_cost: declaredCost,
			seats: $('#nvx-cw-places .nvx-cw-place').length || 1
		}).done(function (res) {
			if (res && res.success) {
				$val.text(res.data.cost_fmt);
				$hint.text('Орієнтовна сума за тарифами НП (вага, міста, сервіс)');
			} else {
				$val.text('—');
				$hint.text((res && res.data && res.data.message) || 'Не вдалося розрахувати');
			}
		}).fail(function (xhr) {
			$val.text('—');
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка розрахунку або таймаут API';
			$hint.text(msg);
		}).always(function () {
			$btn.prop('disabled', false).text('Розрахувати');
		});
	}

		$('#nvx-cw-cargo-type').on('change', function () {
		updateDeliveryPrice();
	});
	$('#nvx-cw-calc-price').on('click', function (e) {
		e.preventDefault();
		updateDeliveryPrice();
	});
});
