/* Nova Express — сторінка налаштувань: пошук міста/відділення відправника, ручний запуск трекінгу. */
jQuery(function ($) {
	'use strict';
	// Показати / приховати API ключ
	$(document).on('click', '#nvx-toggle-api-key', function (e) {
		e.preventDefault();
		var $input = $('#nvx_api_key');
		var isPass = $input.attr('type') === 'password';
		$input.attr('type', isPass ? 'text' : 'password');
		$(this).html(isPass ? '🔒 Приховати' : '👁️ Показати');
	});


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

		NvxCore.post('nvx_fetch_sender_counterparty', { api_key: $('#nvx_api_key').val() || '' })
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

	function getApiWhCount() {
		var raw = $('#nvx-api-wh-count').text() || '';
		var num = parseInt(raw.replace(/[^\d]/g, ''), 10);
		return isNaN(num) ? 0 : num;
	}

	var WH_PAGE_SIZE = 500;

	function updateSyncProgress(page, totalApiCount, statusText, isDone) {
		var $progress = $('#nvx-sync-progress');
		if (!$progress.length) {
			return;
		}
		$progress.show();

		var totalApi = totalApiCount || getApiWhCount() || 54000;
		var totalPages = Math.max(1, Math.ceil(totalApi / WH_PAGE_SIZE));
		var currentPage = Math.max(0, page || 0);

		var pct = 0;
		if (isDone || currentPage >= totalPages) {
			pct = 100;
		} else if (totalPages > 0 && currentPage > 0) {
			pct = Math.min(99, Math.round((currentPage / totalPages) * 100));
		}

		var processedEst = isDone ? totalApi : Math.min(totalApi, currentPage * WH_PAGE_SIZE);
		var remainingEst = Math.max(0, totalApi - processedEst);

		$('#nvx-sync-progress-fill').css('width', pct + '%');
		$('#nvx-sync-progress-percent').text(pct + '%');

		if (statusText) {
			$('#nvx-sync-progress-label').text(statusText);
		}

		var subText = '';
		if (isDone) {
			subText = 'Опрацьовано всі ' + totalApi.toLocaleString('uk-UA') + ' відділень (100%)';
		} else if (currentPage > 0) {
			subText = 'Сторінка ' + currentPage + ' з ~' + totalPages + ' · опрацьовано ~' + processedEst.toLocaleString('uk-UA') + ' з ' + totalApi.toLocaleString('uk-UA');
			if (remainingEst > 0) {
				subText += ' (залишилось ~' + remainingEst.toLocaleString('uk-UA') + ')';
			}
		} else {
			subText = 'Очікування запуску… 0 з ' + totalApi.toLocaleString('uk-UA');
		}
		$('#nvx-sync-progress-sub').text(subText);
	}


	var syncSavedPage = parseInt(NVX_ADMIN.syncSavedPage, 10) || 0;
	var syncResumePage = parseInt(NVX_ADMIN.syncResumePage, 10) || 1;

	function updateSyncButtonUI() {
		if (syncSavedPage > 1) {
			$('#nvx-sync-warehouses').text('Продовжити синхронізацію (зі стор. ' + syncResumePage + ')');
			$('#nvx-reset-sync-link').show();
		} else {
			$('#nvx-sync-warehouses').text('Синхронізувати базу відділень');
			$('#nvx-reset-sync-link').hide();
		}
	}
	$(document).on('click', '#nvx-refresh-api-count', function (e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.css('opacity', '0.5');
		NvxCore.post('nvx_check_warehouses_count', { api_key: $('#nvx_api_key').val() || '' })
			.done(function (res) {
				if (res && res.success && res.data) {
					if (typeof res.data.total_in_db !== 'undefined') {
						$('#nvx-wh-count').text(res.data.total_in_db.toLocaleString('uk-UA'));
					}
					if (res.data.total_in_api) {
						$('#nvx-api-wh-count').text(res.data.total_in_api.toLocaleString('uk-UA'));
					}
				}
			})
			.always(function () {
				$btn.css('opacity', '1');
			});
	});

	updateSyncButtonUI();

	$(document).on('click', '#nvx-reset-sync-link', function (e) {
		e.preventDefault();
		if (!confirm('Скинути збережену сторінку і почати синхронізацію спочатку з 1 сторінки?')) {
			return;
		}
		syncSavedPage = 0;
		syncResumePage = 1;
		NVX_ADMIN.syncSavedPage = 0;
		NVX_ADMIN.syncResumePage = 1;
		updateSyncButtonUI();
		NvxCore.post('nvx_reset_warehouses_sync', {});
		$('#nvx-sync-log').empty();
		$('#nvx-sync-progress').hide();
		$('#nvx-sync-progress-fill').css('width', '0%');
		$('#nvx-sync-progress-percent').text('0%');
		logLine('info', 'Прогрес скинуто. Натисніть «Синхронізувати базу відділень» для завантаження з 1 сторінки.');
	});

	$('#nvx-sync-warehouses').on('click', function () {
		var $btn = $(this);

		$btn.prop('disabled', true);
		$('#nvx-reset-sync-link').hide();
		$('#nvx-sync-log').empty();

		var startPage = syncResumePage > 1 ? syncResumePage : 1;
		var retryCount = 0;
		var $loading;

		if (startPage > 1) {
			logLine('info', 'Відновлення синхронізації зі сторінки ' + startPage + ' (раніше збережено сторінку ' + syncSavedPage + ')…');
			$loading = logLine('info', 'Завантаження відділень… сторінка ' + startPage + '…');
			updateSyncProgress(startPage - 1, getApiWhCount(), 'Відновлення синхронізації (стор. ' + startPage + ')…', false);
		} else {
			$loading = logLine('info', 'Завантаження відділень… сторінка 1 (це може зайняти кілька хвилин)');
			updateSyncProgress(0, getApiWhCount(), 'Початок синхронізації…', false);
		}

		function syncPage(page) {
			NvxCore.post('nvx_sync_warehouses_page', { page: page, api_key: $('#nvx_api_key').val() || '' })
				.done(function (res) {
					if (!res || !res.success) {
						var msg = (res && res.data && res.data.message) || 'Невідома помилка синхронізації.';
						var isRetryable = /too many|ліміт|лимит|превыш|перевищ|timed out|timeout|cURL error 28/i.test(msg);

						if (isRetryable && retryCount < 6) {
							retryCount++;
							var wait = retryCount * 6;
							$loading.text('Пауза ' + wait + 'с і повтор стор. ' + page + ' (спроба ' + retryCount + '/6): ' + msg);
						$('#nvx-sync-progress-label').text('Пауза ' + wait + 'с (спроба ' + retryCount + '/6)…');
							setTimeout(function () { syncPage(page); }, wait * 1000);
							return;
						}

						syncResumePage = Math.max(1, page - 1);
						syncSavedPage = page;
						NVX_ADMIN.syncResumePage = syncResumePage;
						NVX_ADMIN.syncSavedPage = syncSavedPage;

						$loading.remove();
						$('#nvx-sync-progress-label').text('Синхронізацію призупинено');
						logLine('error', 'Синхронізацію призупинено: ' + msg);
						logLine('info', 'Ви можете продовжити — наступний запуск продовжить зі сторінки ' + syncResumePage + '.');
						updateSyncButtonUI();
						$btn.prop('disabled', false);
						return;
					}

					retryCount = 0;
					var d = res.data;
					syncSavedPage = d.page;
					syncResumePage = Math.max(1, d.page - 1);
					NVX_ADMIN.syncSavedPage = syncSavedPage;
					NVX_ADMIN.syncResumePage = syncResumePage;

					$('#nvx-wh-count').text(d.total_in_db.toLocaleString('uk-UA'));
					$loading.text('Завантаження відділень… сторінка ' + d.page + ' · у базі: ' + d.total_in_db.toLocaleString('uk-UA'));
					updateSyncProgress(d.page, getApiWhCount(), 'Завантаження відділень… сторінка ' + d.page, false);

					if (d.has_more) {
						setTimeout(function () { syncPage(d.page + 1); }, 300);
					} else {
						syncSavedPage = 0;
						syncResumePage = 1;
						NVX_ADMIN.syncSavedPage = 0;
						NVX_ADMIN.syncResumePage = 1;
						$loading.remove();
						updateSyncProgress(d.page, d.total_in_db, 'Синхронізацію успішно завершено!', true);
						var successMsg = 'База даних відділень успішно оновлена (' + d.total_in_db.toLocaleString('uk-UA') + ' у базі).';
						if (d.deleted_stale && d.deleted_stale > 0) {
							successMsg += ' Закритих відділень видалено: ' + d.deleted_stale.toLocaleString('uk-UA') + '.';
						}
						logLine('success', successMsg);
						$('#nvx-api-wh-count').text(d.total_in_db.toLocaleString('uk-UA'));
						updateSyncButtonUI();
						$btn.prop('disabled', false);
					}
				})
				.fail(function (xhr) {
					var msg = 'Запит не вдався (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').';
					if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
						msg = xhr.responseJSON.data.message;
					} else if (xhr && xhr.responseText) {
						msg += ' ' + xhr.responseText.replace(/<[^>]+>/g, ' ').trim().slice(0, 300);
					}

					syncResumePage = Math.max(1, page - 1);
					syncSavedPage = page;
					NVX_ADMIN.syncResumePage = syncResumePage;
					NVX_ADMIN.syncSavedPage = syncSavedPage;

					$loading.remove();
					$('#nvx-sync-progress-label').text('Синхронізацію призупинено');
						logLine('error', 'Синхронізацію призупинено: ' + msg);
					logLine('info', 'Ви можете продовжити — наступний запуск продовжить зі сторінки ' + syncResumePage + '.');
					updateSyncButtonUI();
					$btn.prop('disabled', false);
				});
		}

		syncPage(startPage);
	});

	function renderTtnList(items) {
		if (!items || !items.length) {
			return $('<p class="nvx-empty">').text('Немає записів.');
		}
		var $ul = $('<ul class="nvx-ttn-list">');
		items.forEach(function (it) {
			var $li = $('<li class="nvx-ttn-list__item">');
			var $a = $('<a class="nvx-ttn-list__link">').attr('href', it.url || '#');

			$a.append($('<span class="nvx-ttn-list__ttn">').text(it.waybill || ''));
			$a.append($('<span class="nvx-ttn-list__sep">').text('→'));
			$a.append($('<span class="nvx-ttn-list__order">').text('№' + (it.order_num || '')));
			$a.append($('<span class="nvx-ttn-list__sep">').text('—'));
			$a.append($('<span class="nvx-ttn-list__name">').text(it.recipient || '—'));
			$a.append($('<span class="nvx-ttn-list__sep">').text('—'));
			$a.append($('<span class="nvx-ttn-list__total">').text('(' + (it.total || '—') + ')'));
			$a.append($('<span class="nvx-ttn-list__sep">').text('→'));
			$a.append($('<span class="nvx-ttn-list__status">').text(it.status || '—'));

			$li.append($a);
			$ul.append($li);
		});
		return $ul;
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
						$active.empty().append(renderTtnList(d.active_items || []));
					}
					if ($done.length) {
						$done.empty().append(renderTtnList(d.delivered_items || []));
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

	// ---- Перевірка та встановлення оновлень плагіна ----
	var $checkBtn = $('#nvx-check-update-btn');
	var $updateBox = $('#nvx-update-result');

	$checkBtn.on('click', function () {
		$checkBtn.prop('disabled', true).text('Перевіряємо…');
		$updateBox.show().html('<div class="nvx-sync-log__item nvx-sync-log__item--info">🔍 Перевіряємо релізи на GitHub…</div>');

		NvxCore.post('nvx_check_update', {})
			.done(function (res) {
				if (!res || !res.success) {
					var msg = (res && res.data && res.data.message) || 'Не вдалося перевірити оновлення.';
					$updateBox.empty().append($('<div class="nvx-sync-log__item nvx-sync-log__item--error"></div>').text(msg));
					return;
				}

				var d = res.data;
				var safeLatest = $('<div>').text(d.latest_version || '').html();
				var safeCurrent = $('<div>').text(d.current_version || '').html();
				var safeUrl = '';
				if (typeof d.html_url === 'string' && d.html_url.indexOf('https://github.com/kdinya/nova-express-woocommerce') === 0) {
					safeUrl = d.html_url;
				}

				if (d.update_available) {
					var $changelogEl = null;
					if (d.changelog) {
						$changelogEl = $('<div class="nvx-changelog-preview" style="margin-top:10px; max-height:180px; overflow-y:auto; font-size:12px; background:#f9fafb; padding:10px; border-radius:6px; border:1px solid #e5e7eb; white-space:pre-wrap;"></div>').text(d.changelog);
					}

					var $infoItem = $('<div class="nvx-sync-log__item nvx-sync-log__item--info" style="border-left-color:var(--nvx-accent, #2271b1);"></div>');
					var $headerDiv = $('<div>').html('<strong>Доступна нова версія: v' + safeLatest + '</strong> (поточна: v' + safeCurrent + ')');
					$infoItem.append($headerDiv);
					if ($changelogEl) {
						$infoItem.append($changelogEl);
					}

					var $actionsDiv = $('<div style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;"></div>');
					var $runBtn = $('<button type="button" class="nvx-btn nvx-btn--primary" id="nvx-run-update-btn"></button>').text('⬇️ Оновити зараз до v' + (d.latest_version || ''));
					$actionsDiv.append($runBtn);

					if (safeUrl) {
						var $link = $('<a target="_blank" rel="noopener noreferrer" class="nvx-btn nvx-btn--ghost" style="text-decoration:none;">Переглянути реліз на GitHub ↗</a>').attr('href', safeUrl);
						$actionsDiv.append($link);
					}
					$infoItem.append($actionsDiv);
					$infoItem.append($('<div id="nvx-update-process-msg" style="margin-top:10px; font-weight:600; display:none;"></div>'));

					$updateBox.empty().append($infoItem);
				} else {
					var $successItem = $('<div class="nvx-sync-log__item nvx-sync-log__item--success"></div>');
					$successItem.append($('<div>').html('✓ У вас встановлена поточна версія (v' + safeCurrent + '). Новіших релізів не виявлено.'));
					$successItem.append($('<div style="margin-top:6px; font-size:12px; color:#475569;">Якщо реліз або код цієї версії було перезаписано на GitHub, ви можете оновити/перевстановити її зараз:</div>'));

					var $reinstallActions = $('<div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;"></div>');
					var $reinstallBtn = $('<button type="button" class="nvx-btn nvx-btn--ghost" id="nvx-run-update-btn"></button>').text('🔄 Оновити / перевстановити v' + (d.current_version || ''));
					$reinstallActions.append($reinstallBtn);

					if (safeUrl) {
						var $ghLink = $('<a target="_blank" rel="noopener noreferrer" class="nvx-btn nvx-btn--ghost" style="text-decoration:none;">Переглянути реліз на GitHub ↗</a>').attr('href', safeUrl);
						$reinstallActions.append($ghLink);
					}
					$successItem.append($reinstallActions);
					$successItem.append($('<div id="nvx-update-process-msg" style="margin-top:10px; font-weight:600; display:none;"></div>'));

					$updateBox.empty().append($successItem);
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка мережі при перевірці оновлень.';
				$updateBox.empty().append($('<div class="nvx-sync-log__item nvx-sync-log__item--error"></div>').text(msg));
			})
			.always(function () {
				$checkBtn.prop('disabled', false).text('🔄 Перевірити оновлення');
			});
	});

	$(document).on('click', '#nvx-run-update-btn', function () {
		var $btn = $(this);
		var $procMsg = $('#nvx-update-process-msg');

		if (!confirm('Запустити автоматичне оновлення плагіна?')) {
			return;
		}

		$btn.prop('disabled', true);
		$procMsg.show().css('color', '#2271b1').text('Завантаження та встановлення оновлення… Будь ласка, зачекайте.');

		NvxCore.post('nvx_run_update', {})
			.done(function (res) {
				if (res && res.success) {
					$procMsg.css('color', '#46b450').text(res.data.message || 'Оновлення завершено! Перезавантаження…');
					setTimeout(function () {
						window.location.reload();
					}, 1600);
				} else {
					var msg = (res && res.data && res.data.message) || 'Помилка встановлення оновлення.';
					$procMsg.css('color', '#dc3232').text('Помилка: ' + msg);
					$btn.prop('disabled', false);
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Помилка під час встановлення оновлення.';
				$procMsg.css('color', '#dc3232').text('Помилка: ' + msg);
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
