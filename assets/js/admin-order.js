/* Nova Express — картка замовлення: створення ТТН у новій вкладці + прив'язка наявної ТТН. */
jQuery(function ($) {
	'use strict';

	var $panel = $('.nvx-order-panel');
	if (!$panel.length) {
		return;
	}

	var orderId = $panel.data('order-id');

	$('#nvx-op-toggle-attach').on('click', function () {
		$('#nvx-op-attach-form').slideToggle(120);
	});

	$('#nvx-op-attach-submit').on('click', function () {
		var $btn = $(this);
		var $status = $('#nvx-op-status');
		var number = $('#nvx-op-attach-number').val().trim();

		if (!number) {
			NvxCore.setStatus($status, 'Вкажіть номер ТТН', 'error');
			return;
		}

		$btn.prop('disabled', true);
		NvxCore.setStatus($status, 'Перевіряємо номер…');

		NvxCore.post('nvx_attach_waybill', { order_id: orderId, waybill_number: number })
			.done(function (res) {
				if (res && res.success) {
					NvxCore.setStatus($status, '✓ ТТН додано', 'success');
					setTimeout(function () { window.location.reload(); }, 700);
				} else {
					NvxCore.setStatus($status, (res && res.data && res.data.message) || NVX_ADMIN.i18n.error, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || NVX_ADMIN.i18n.error;
				NvxCore.setStatus($status, msg, 'error');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	$(document).on('click', '.nvx-waybill-card__refresh', function () {
		var $btn = $(this);
		var $card = $btn.closest('.nvx-waybill-card');
		var ttnId = $card.data('ttn-id');
		var originalLabel = $btn.data('label') || $.trim($btn.text());

		$btn.data('label', originalLabel).prop('disabled', true).text('Перевіряємо…');

		function restore() {
			$btn.prop('disabled', false).text(originalLabel);
		}

		NvxCore.post('nvx_refresh_waybill', { order_id: orderId, ttn_id: ttnId })
			.done(function (res) {
				if (!(res && res.success)) {
					restore();
					NvxCore.alert((res && res.data && res.data.message) || NVX_ADMIN.i18n.error);
					return;
				}

				// ТТН зникла в Новій Пошті й видалена з бази — змінюється сам блок
				// (з'являються «Створити»/«Додати»), тому лише в цьому випадку перезавантажуємо.
				if (res.data && res.data.deleted) {
					window.location.reload();
					return;
				}

				// Інакше оновлюємо лише статус ТТН у цій картці.
				var w = res.data && res.data.waybill;
				if (w) {
					var text = w.status_display || ((w.carrier_status_code ? '[' + w.carrier_status_code + '] ' : '') +
						(w.carrier_status_text || 'Очікує опитування'));
					$card.find('.nvx-waybill-card__status').text(text);
					$card.toggleClass('is-delivered', !!w.is_delivered);
					if (w.is_dispatched) {
						$card.find('.nvx-waybill-card__delete').remove();
					}
				}

				$btn.prop('disabled', false).text('✓ Оновлено');
				setTimeout(function () {
					if (!$btn.prop('disabled')) {
						$btn.text(originalLabel);
					}
				}, 1500);
			})
			.fail(function (xhr) {
				restore();
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || NVX_ADMIN.i18n.error;
				NvxCore.alert(msg);
			});
	});

	$(document).on('click', '.nvx-waybill-card__delete', function () {
		var $card = $(this).closest('.nvx-waybill-card');
		var ttnId = $card.data('ttn-id');
		var number = $card.find('.nvx-waybill-card__number').text().trim();

		NvxCore.confirm({
			title: 'Видалення ТТН ' + number,
			message: 'Видалити ТТН ' + number + ' повністю з бази? Цю дію не можна скасувати. Спроба видалити накладну також і на боці Нової Пошти (якщо це ще можливо).',
			confirmText: 'Видалити',
			cancelText: 'Скасувати',
			destructive: true
		}).then(function (ok) {
			if (!ok) {
				return;
			}

			$card.css('opacity', 0.5);

			NvxCore.post('nvx_delete_waybill', { order_id: orderId, ttn_id: ttnId })
				.done(function (res) {
					if (res && res.success) {
						// Перезавантажуємо картку, щоб з'явились кнопки «Створити ТТН» / «Додати».
						window.location.reload();
					} else {
						$card.css('opacity', 1);
						NvxCore.alert((res && res.data && res.data.message) || NVX_ADMIN.i18n.error);
					}
				})
				.fail(function (xhr) {
					$card.css('opacity', 1);
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || NVX_ADMIN.i18n.error;
					NvxCore.alert(msg);
				});
		});
	});

	// Коли в новій вкладці (сторінка створення ТТН) успішно створено накладну —
	// автоматично оновлюємо картку замовлення, щоб побачити новий статус.
	window.addEventListener('message', function (event) {
		if (event.origin !== window.location.origin) {
			return;
		}
		var data = event.data;
		if (data && data.type === 'nvx_waybill_created' && String(data.orderId) === String(orderId)) {
			window.location.reload();
		}
	});
});
