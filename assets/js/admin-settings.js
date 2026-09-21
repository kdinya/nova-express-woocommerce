/* Nova Express — сторінка налаштувань: пошук міста/відділення відправника, ручний запуск трекінгу. */
jQuery(function ($) {
	'use strict';

	var $citySearch = $('#nvx-sender-city-search');
	if (!$citySearch.length) {
		// Все одно можемо бути на сторінці з кнопкою "Перевірити зараз" без полів адреси.
	}

	var cityTimer = null;

	$citySearch.on('input', function () {
		var q = $(this).val();
		clearTimeout(cityTimer);
		if (q.length < 2) {
			$('#nvx-sender-city-suggest').empty();
			return;
		}
		cityTimer = setTimeout(function () {
			$.get(NVX_ADMIN.ajaxUrl, { action: 'nvx_local_search_cities', nonce: NVX_ADMIN.nonce, q: q }).done(function (res) {
				var $box = $('#nvx-sender-city-suggest').empty();
				if (!res || !res.success) {
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					$item.on('click', function () {
						$('#nvx-sender-city-ref').val(item.ref);
						$('#nvx-sender-city-name').val(item.label);
						$citySearch.val(item.label);
						$box.empty();
						$('#nvx-sender-warehouse-ref').val('');
						$('#nvx-sender-warehouse-label').val('');
						$('#nvx-sender-warehouse-search').val('');
						$('#nvx-sender-warehouse-suggest').empty();
					});
					$box.append($item);
				});
			});
		}, 300);
	});

	// Відділення відправника: фокус → усі; введення → фільтр
	var senderWhTimer = null;
	function fetchSenderWarehouses(q) {
		var cityRef = $('#nvx-sender-city-ref').val();
		if (!cityRef) {
			$('#nvx-sender-warehouse-suggest').empty();
			return;
		}
		$.get(NVX_ADMIN.ajaxUrl, {
			action: 'nvx_local_search_warehouses',
			nonce: NVX_ADMIN.nonce,
			city_ref: cityRef,
			q: q || ''
		}).done(function (res) {
			var $box = $('#nvx-sender-warehouse-suggest').empty();
			if (!res || !res.success || !res.data.length) {
				$box.append($('<div class="nvx-suggest-item">').text('Нічого не знайдено'));
				return;
			}
			res.data.forEach(function (w) {
				var $item = $('<div class="nvx-suggest-item">').text(w.label);
				$item.on('mousedown', function (e) {
					e.preventDefault();
					e.stopPropagation();
					$('#nvx-sender-warehouse-ref').val(w.ref);
					$('#nvx-sender-warehouse-label').val(w.label);
					$('#nvx-sender-warehouse-search').val(w.label);
					$box.empty();
				});
				$box.append($item);
			});
		});
	}
	$('#nvx-sender-warehouse-search').on('focus click', function () {
		fetchSenderWarehouses('');
	});
	$('#nvx-sender-warehouse-search').on('input', function () {
		var q = $(this).val();
		clearTimeout(senderWhTimer);
		$('#nvx-sender-warehouse-ref').val('');
		$('#nvx-sender-warehouse-label').val('');
		senderWhTimer = setTimeout(function () {
			fetchSenderWarehouses(q);
		}, 250);
	});

	// ---- Автоматичне отримання контрагента-відправника ----
	$('#nvx-fetch-sender').on('click', function () {
		var $btn = $(this);
		var $status = $('#nvx-sender-cp-status');

		$btn.prop('disabled', true);
		$status.text('Запитуємо дані…');

		NvxCore.post('nvx_fetch_sender_counterparty', {})
			.done(function (res) {
				if (res && res.success) {
					$('input[name="sender_last_name"]').val(res.data.last_name);
					$('input[name="sender_first_name"]').val(res.data.first_name);
					$('input[name="sender_middle_name"]').val(res.data.middle_name);
					if (res.data.phone) {
						$('#nvx-sender-phone').val(res.data.phone);
					}
					$status.text('✓ Збережено: ' + (res.data.contact_name || 'контрагента отримано'));
				} else {
					$status.text('Помилка: ' + ((res && res.data && res.data.message) || 'невідома'));
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка з\'єднання.';
				$status.text('Помилка: ' + msg);
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	// ---- Синхронізація бази відділень ----
	function logLine(type, text) {
		var $line = $('<div class="nvx-sync-log__item nvx-sync-log__item--' + type + '">').text(text);
		$('#nvx-sync-log').append($line);
		$line[0].scrollIntoView({ block: 'nearest' });
		return $line;
	}

	$('#nvx-sync-warehouses').on('click', function () {
		var $btn = $(this);

		$btn.prop('disabled', true);
		$('#nvx-sync-log').empty();

		logLine('info', '1/3 Завантаження списку областей…');
		logLine('info', '2/3 Завантаження списку міст…');
		var $loading = logLine('info', '3/3 Завантаження відділень… сторінка 1 (це може зайняти кілька хвилин)');
		var retryCount = 0;

		function syncPage(page) {
			NvxCore.post('nvx_sync_warehouses_page', { page: page })
				.done(function (res) {
					if (!res || !res.success) {
						var msg = (res && res.data && res.data.message) || 'Невідома помилка синхронізації.';
						var isRetryable = /too many|ліміт|лимит|превыш|перевищ|timed out|timeout|cURL error 28/i.test(msg);

						if (isRetryable && retryCount < 6) {
							retryCount++;
							var wait = retryCount * 6;
							$loading.text('Пауза ' + wait + 'с і повтор стор. ' + page + ' (спроба ' + retryCount + '/6): ' + msg);
							setTimeout(function () { syncPage(page); }, wait * 1000);
							return;
						}

						$loading.remove();
						logLine('error', 'Помилка: ' + msg);
						$btn.prop('disabled', false);
						return;
					}

					retryCount = 0;
					var d = res.data;
					$('#nvx-wh-count').text(d.total_in_db.toLocaleString('uk-UA'));
					$loading.text('Завантаження відділень… сторінка ' + d.page + ' · у базі: ' + d.total_in_db.toLocaleString('uk-UA'));

					if (d.has_more) {
						// Пауза між сторінками — менше навантаження на API і менше timeout.
						// 300мс (було 900мс) — разом зі збільшеним розміром сторінки (500 замість
						// 150 записів) це суттєво скорочує загальний час синхронізації, лишаючись
						// достатньо м'яким для API Нової Пошти при послідовних запитах.
						setTimeout(function () { syncPage(page + 1); }, 300);
					} else {
						$loading.remove();
						logLine('success', 'База даних відділень успішно оновлена (' + d.total_in_db.toLocaleString('uk-UA') + ' записів).');
						$btn.prop('disabled', false);
					}
				})
				.fail(function (xhr) {
					var msg = 'Запит не вдався (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					} else if (xhr && xhr.responseText) {
						// Сервер міг повернути не-JSON (PHP-помилку) — покажемо початок тексту, щоб було видно причину.
						msg += ' ' + xhr.responseText.replace(/<[^>]+>/g, ' ').trim().slice(0, 300);
					}
					$loading.remove();
					logLine('error', msg);
					$btn.prop('disabled', false);
				});
		}

		syncPage(1);
	});

	function renderTtnList(items) {
		if (!items || !items.length) {
			return '<p class="nvx-empty">Немає записів.</p>';
		}
		var html = '<ul class="nvx-ttn-list">';
		items.forEach(function (it) {
			html += '<li class="nvx-ttn-list__item"><a class="nvx-ttn-list__link" href="' + (it.url || '#') + '">' +
				'<span class="nvx-ttn-list__ttn">' + (it.waybill || '') + '</span>' +
				'<span class="nvx-ttn-list__sep">→</span>' +
				'<span class="nvx-ttn-list__order">№' + (it.order_num || '') + '</span>' +
				'<span class="nvx-ttn-list__sep">—</span>' +
				'<span class="nvx-ttn-list__name">' + (it.recipient || '—') + '</span>' +
				'<span class="nvx-ttn-list__sep">—</span>' +
				'<span class="nvx-ttn-list__total">(' + (it.total || '—') + ')</span>' +
				'<span class="nvx-ttn-list__sep">→</span>' +
				'<span class="nvx-ttn-list__status">' + (it.status || '—') + '</span>' +
				'</a></li>';
		});
		html += '</ul>';
		return html;
	}

	$('#nvx-run-tracking-now').on('click', function () {
		var $btn = $(this);
		var $status = $('#nvx-run-tracking-result');
		$btn.prop('disabled', true);
		NvxCore.setStatus($status, NVX_ADMIN.i18n.checking || 'Перевіряємо…');

		NvxCore.post('nvx_run_tracking_now', {}).done(function (res) {
			if (res && res.success) {
				var d = res.data || {};
				var msg = d.message || ('Перевірено: ' + (d.checked || 0) + ', змінено: ' + (d.changed || 0) + ', доставлено: ' + (d.delivered || 0));
				NvxCore.setStatus($status, msg, 'success');

				// Оновлюємо списки на вкладці моніторингу без reload.
				var $mon = $('#nvx-ttn-monitor');
				if ($mon.length) {
					$mon.find('h2').first().text('ТТН у моніторингу (' + (d.active_count || 0) + ')');
					var $active = $mon.find('[data-list="active"]');
					var $done = $mon.find('[data-list="delivered"]');
					if ($active.length) {
						$active.html(renderTtnList(d.active_items || []));
					}
					if ($done.length) {
						$done.html(renderTtnList(d.delivered_items || []));
					}
				}
				if (d.last_poll_text) {
					$('#nvx-ttn-last-poll').text(d.last_poll_text);
				}
			} else {
				NvxCore.setStatus($status, (res && res.data && res.data.message) || NVX_ADMIN.i18n.error, 'error');
			}
		}).fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка запиту';
			NvxCore.setStatus($status, msg, 'error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
});


	// ---- Додаткові відділення відправника (пошук міста + select) ----
	var extraCityTimers = {};

	$(document).on('input', '.nvx-extra-city-search', function () {
		var $wrap = $(this).closest('.nvx-extra-wh');
		var idx = $wrap.data('index') || 0;
		var q = $(this).val();
		clearTimeout(extraCityTimers[idx]);
		if (q.length < 2) {
			$wrap.find('.nvx-extra-city-suggest').empty();
			return;
		}
		extraCityTimers[idx] = setTimeout(function () {
			$.get(NVX_ADMIN.ajaxUrl, { action: 'nvx_local_search_cities', nonce: NVX_ADMIN.nonce, q: q }).done(function (res) {
				var $box = $wrap.find('.nvx-extra-city-suggest').empty();
				if (!res || !res.success) {
					return;
				}
				res.data.forEach(function (item) {
					var $item = $('<div class="nvx-suggest-item">').text(item.label);
					$item.on('click', function () {
						$wrap.find('.nvx-extra-city-ref').val(item.ref);
						$wrap.find('.nvx-extra-city-name').val(item.label);
						$wrap.find('.nvx-extra-city-search').val(item.label);
						$box.empty();
						$wrap.find('.nvx-extra-warehouse-ref').val('');
						$wrap.find('.nvx-extra-warehouse-label').val('');
						$wrap.find('.nvx-extra-warehouse-search').val('');
						$wrap.find('.nvx-extra-warehouse-suggest').empty();
					});
					$box.append($item);
				});
			});
		}, 300);
	});






	// Додати / видалити додаткове відділення
	function buildExtraWhBlock() {
		return $(
			'<div class="nvx-extra-wh" data-index="new">' +
				'<label class="nvx-field"><span>Назва (для себе)</span>' +
				'<input type="text" name="extra_wh_label[]" value="" placeholder="Напр. Склад Київ" /></label>' +
				'<div class="nvx-field-row">' +
					'<label class="nvx-field"><span>Місто</span>' +
					'<input type="text" class="nvx-extra-city-search" value="" placeholder="Почніть вводити назву…" autocomplete="off" />' +
					'<input type="hidden" name="extra_wh_city_ref[]" class="nvx-extra-city-ref" value="" />' +
					'<input type="hidden" name="extra_wh_city_name[]" class="nvx-extra-city-name" value="" />' +
					'<div class="nvx-suggest nvx-extra-city-suggest"></div></label>' +
					'<label class="nvx-field"><span>Відділення / поштомат</span>' +
					'<input type="text" class="nvx-extra-warehouse-search" value="" placeholder="Почніть вводити назву або номер…" autocomplete="off" />' +
					'<input type="hidden" name="extra_wh_ref[]" class="nvx-extra-warehouse-ref" value="" />' +
					'<input type="hidden" name="extra_wh_warehouse_label[]" class="nvx-extra-warehouse-label" value="" />' +
					'<div class="nvx-suggest nvx-extra-warehouse-suggest"></div></label>' +
				'</div>' +
				'<button type="button" class="nvx-btn nvx-btn--ghost nvx-extra-wh-remove" style="margin-top:8px;">Видалити</button>' +
			'</div>'
		);
	}

	$(document).on('click', '#nvx-extra-wh-add', function (e) {
		e.preventDefault();
		e.stopPropagation();
		var $list = $('#nvx-extra-wh-list');
		if (!$list.length) {
			console.warn('nvx-extra-wh-list not found');
			return;
		}
		$list.append(buildExtraWhBlock());
	});
	$(document).on('click', '.nvx-extra-wh-remove', function (e) {
		e.preventDefault();
		$(this).closest('.nvx-extra-wh').remove();
	});


	// Додаткові відділення: клік/фокус → усі; введення → фільтр; вибір → закрити список
	var extraWhTimers = {};
	function fetchExtraWarehouses($wrap, q) {
		var cityRef = $wrap.find('.nvx-extra-city-ref').val();
		var $box = $wrap.find('.nvx-extra-warehouse-suggest');
		if (!cityRef) {
			$box.empty().append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text('Спочатку оберіть місто'));
			return;
		}
		$box.html('<div class="nvx-suggest-item nvx-suggest-item--muted">Завантаження…</div>');
		$.get(NVX_ADMIN.ajaxUrl, {
			action: 'nvx_local_search_warehouses',
			nonce: NVX_ADMIN.nonce,
			city_ref: cityRef,
			q: q || ''
		}).done(function (res) {
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
					$wrap.find('.nvx-extra-warehouse-ref').val(w.ref);
					$wrap.find('.nvx-extra-warehouse-label').val(w.label);
					$wrap.find('.nvx-extra-warehouse-search').val(w.label);
					$box.empty();
				});
				$box.append($item);
			});
		}).fail(function () {
			$box.empty().append($('<div class="nvx-suggest-item nvx-suggest-item--muted">').text('Помилка завантаження'));
		});
	}
	$(document).on('focus click', '.nvx-extra-warehouse-search', function () {
		var $wrap = $(this).closest('.nvx-extra-wh');
		fetchExtraWarehouses($wrap, '');
	});
	$(document).on('input', '.nvx-extra-warehouse-search', function () {
		var $wrap = $(this).closest('.nvx-extra-wh');
		var q = $(this).val();
		var key = $wrap.index();
		clearTimeout(extraWhTimers[key]);
		$wrap.find('.nvx-extra-warehouse-ref').val('');
		$wrap.find('.nvx-extra-warehouse-label').val('');
		extraWhTimers[key] = setTimeout(function () {
			fetchExtraWarehouses($wrap, q);
		}, 200);
	});
