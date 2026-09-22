/* Nova Express — спільні хелпери адмінки. */
window.NvxCore = (function ($) {
	'use strict';

	function post(action, data) {
		return $.post(NVX_ADMIN.ajaxUrl, Object.assign({ action: action, nonce: NVX_ADMIN.nonce }, data));
	}

	function setStatus($el, text, type) {
		$el.text(text).removeClass('is-success is-error').addClass(type ? 'is-' + type : '');
	}

	// SVG-іконки для модальних вікон дизайну плагіна
	var ICONS = {
		question: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
		warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>',
		danger: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"></path><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>',
		info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>',
		success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>',
		close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>'
	};

	function escapeHtml(str) {
		if (typeof str !== 'string') {
			return '';
		}
		return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function formatMessage(str) {
		return escapeHtml(str).replace(/\n/g, '<br>');
	}

	/**
	 * Модальне діалогове вікно підтвердження у фірмовому стилі Nova Express.
	 * Повертає Promise<boolean>.
	 */
	function confirm(opts) {
		if (typeof opts === 'string') {
			opts = { message: opts };
		}
		opts = opts || {};

		var title = opts.title || 'Підтвердження дії';
		var message = opts.message || '';
		var confirmText = opts.confirmText || 'Підтвердити';
		var cancelText = opts.cancelText || 'Скасувати';
		var isDestructive = !!opts.destructive;
		var type = opts.type || (isDestructive ? 'danger' : 'primary');
		var icon = isDestructive ? ICONS.danger : (type === 'warning' ? ICONS.warning : (type === 'info' ? ICONS.info : (type === 'success' ? ICONS.success : ICONS.question)));

		var confirmBtnClass = isDestructive ? 'nvx-btn nvx-btn--danger-solid' : 'nvx-btn nvx-btn--primary';

		return new Promise(function (resolve) {
			var $backdrop = $('<div class="nvx-modal-backdrop" role="dialog" aria-modal="true">' +
				'<div class="nvx-modal">' +
					'<div class="nvx-modal__head">' +
						'<div class="nvx-modal__icon nvx-modal__icon--' + type + '">' + icon + '</div>' +
						'<div class="nvx-modal__title-wrap">' +
							'<h3 class="nvx-modal__title">' + escapeHtml(title) + '</h3>' +
						'</div>' +
						'<button type="button" class="nvx-modal__close" aria-label="Закрити">' + ICONS.close + '</button>' +
					'</div>' +
					'<div class="nvx-modal__body">' + formatMessage(message) + '</div>' +
					'<div class="nvx-modal__foot">' +
						'<button type="button" class="nvx-btn nvx-btn--secondary nvx-modal__btn-cancel">' + escapeHtml(cancelText) + '</button>' +
						'<button type="button" class="' + confirmBtnClass + ' nvx-modal__btn-confirm">' + escapeHtml(confirmText) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>');

			function cleanup(result) {
				$(document).off('keydown.nvxModal');
				$backdrop.removeClass('is-active');
				setTimeout(function () {
					$backdrop.remove();
					resolve(result);
				}, 180);
			}

			$backdrop.find('.nvx-modal__btn-confirm').on('click', function () {
				cleanup(true);
			});

			$backdrop.find('.nvx-modal__btn-cancel, .nvx-modal__close').on('click', function () {
				cleanup(false);
			});

			$backdrop.on('click', function (e) {
				if ($(e.target).is('.nvx-modal-backdrop')) {
					cleanup(false);
				}
			});

			$(document).on('keydown.nvxModal', function (e) {
				if (e.key === 'Escape' || e.keyCode === 27) {
					cleanup(false);
				}
			});

			$('body').append($backdrop);
			$backdrop[0].offsetHeight; // eslint-disable-line no-unused-expressions
			$backdrop.addClass('is-active');

			if (isDestructive) {
				$backdrop.find('.nvx-modal__btn-cancel').focus();
			} else {
				$backdrop.find('.nvx-modal__btn-confirm').focus();
			}
		});
	}

	/**
	 * Модальне інформаційне вікно / попередження у стилі плагіна.
	 * Повертає Promise<void>.
	 */
	function alert(opts) {
		if (typeof opts === 'string') {
			opts = { message: opts };
		}
		opts = opts || {};

		var title = opts.title || 'Повідомлення';
		var message = opts.message || '';
		var okText = opts.okText || 'Зрозуміло';
		var type = opts.type || 'info';
		var icon = (type === 'danger' || type === 'error') ? ICONS.danger : (type === 'warning' ? ICONS.warning : (type === 'success' ? ICONS.success : ICONS.info));
		var iconType = (type === 'error') ? 'danger' : type;

		return new Promise(function (resolve) {
			var $backdrop = $('<div class="nvx-modal-backdrop" role="dialog" aria-modal="true">' +
				'<div class="nvx-modal">' +
					'<div class="nvx-modal__head">' +
						'<div class="nvx-modal__icon nvx-modal__icon--' + iconType + '">' + icon + '</div>' +
						'<div class="nvx-modal__title-wrap">' +
							'<h3 class="nvx-modal__title">' + escapeHtml(title) + '</h3>' +
						'</div>' +
						'<button type="button" class="nvx-modal__close" aria-label="Закрити">' + ICONS.close + '</button>' +
					'</div>' +
					'<div class="nvx-modal__body">' + formatMessage(message) + '</div>' +
					'<div class="nvx-modal__foot">' +
						'<button type="button" class="nvx-btn nvx-btn--primary nvx-modal__btn-confirm">' + escapeHtml(okText) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>');

			function cleanup() {
				$(document).off('keydown.nvxModal');
				$backdrop.removeClass('is-active');
				setTimeout(function () {
					$backdrop.remove();
					resolve();
				}, 180);
			}

			$backdrop.find('.nvx-modal__btn-confirm, .nvx-modal__close').on('click', function () {
				cleanup();
			});

			$backdrop.on('click', function (e) {
				if ($(e.target).is('.nvx-modal-backdrop')) {
					cleanup();
				}
			});

			$(document).on('keydown.nvxModal', function (e) {
				if (e.key === 'Escape' || e.keyCode === 27) {
					cleanup();
				}
			});

			$('body').append($backdrop);
			$backdrop[0].offsetHeight; // eslint-disable-line no-unused-expressions
			$backdrop.addClass('is-active');
			$backdrop.find('.nvx-modal__btn-confirm').focus();
		});
	}

	// Підстрахова: якщо будь-який AJAX-запит плагіна впаде без власного .fail(),
	// користувач все одно побачить, що щось пішло не так, а не "тиху" зависаючу кнопку.
	$(document).ajaxError(function (event, xhr, settings) {
		if (!settings || !settings.url || settings.url.indexOf('admin-ajax.php') === -1) {
			return;
		}
		if (!settings.data || settings.data.indexOf('action=nvx_') === -1) {
			return;
		}
		console.error('Nova Express AJAX error:', settings.data, xhr.status, xhr.responseText);
	});

		/**
	 * Модальне вікно вибору формату друку ТТН.
	 */
	function openPrintModal(opts) {
		opts = opts || {};
		var ttnNumber = opts.ttnNumber || '';
		var urlCustom = opts.urlCustom || '#';
		var urlNp100  = opts.urlNp100 || '#';
		var urlNp85   = opts.urlNp85 || '#';
		var urlNpDoc  = opts.urlNpDoc || '#';

		var title = ttnNumber ? 'Друк ТТН №' + ttnNumber : 'Оберіть формат друку';

		var optionsHtml = '<div class="nvx-print-modal-list">' +
			'<a class="nvx-print-option" href="' + escapeHtml(urlCustom) + '" target="_blank" rel="noopener">' +
				'<div class="nvx-print-option__icon">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="13" y2="16"/></svg>' +
				'</div>' +
				'<div class="nvx-print-option__content">' +
					'<div class="nvx-print-option__title">Користувацька етикетка</div>' +
					'<div class="nvx-print-option__desc">Спрощений HTML-шаблон (налаштовані розміри та поля)</div>' +
				'</div>' +
				'<div class="nvx-print-option__arrow">→</div>' +
			'</a>' +

			'<a class="nvx-print-option" href="' + escapeHtml(urlNp100) + '" target="_blank" rel="noopener">' +
				'<div class="nvx-print-option__icon">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
				'</div>' +
				'<div class="nvx-print-option__content">' +
					'<div class="nvx-print-option__title">Маркування Нової Пошти (100×100 мм)</div>' +
					'<div class="nvx-print-option__desc">Офіційний термо-стікер PDF від Нової Пошти (Zebra)</div>' +
				'</div>' +
				'<div class="nvx-print-option__arrow">→</div>' +
			'</a>' +

			'<a class="nvx-print-option" href="' + escapeHtml(urlNp85) + '" target="_blank" rel="noopener">' +
				'<div class="nvx-print-option__icon">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="1"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="15" x2="12" y2="15"/></svg>' +
				'</div>' +
				'<div class="nvx-print-option__content">' +
					'<div class="nvx-print-option__title">Маркування Нової Пошти (85×85 мм)</div>' +
					'<div class="nvx-print-option__desc">Компактний квадратний стікер PDF від Нової Пошти</div>' +
				'</div>' +
				'<div class="nvx-print-option__arrow">→</div>' +
			'</a>' +

			'<a class="nvx-print-option" href="' + escapeHtml(urlNpDoc) + '" target="_blank" rel="noopener">' +
				'<div class="nvx-print-option__icon">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' +
				'</div>' +
				'<div class="nvx-print-option__content">' +
					'<div class="nvx-print-option__title">Експрес-накладна Нової Пошти (А4)</div>' +
					'<div class="nvx-print-option__desc">Повний бланк документа з усіма реквізитами</div>' +
				'</div>' +
				'<div class="nvx-print-option__arrow">→</div>' +
			'</a>' +
		'</div>';

		var $backdrop = $('<div class="nvx-modal-backdrop" role="dialog" aria-modal="true">' +
			'<div class="nvx-modal" style="max-width:480px;">' +
				'<div class="nvx-modal__head">' +
					'<div class="nvx-modal__icon nvx-modal__icon--primary">' +
						'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
					'</div>' +
					'<div class="nvx-modal__title-wrap">' +
						'<h3 class="nvx-modal__title">' + escapeHtml(title) + '</h3>' +
					'</div>' +
					'<button type="button" class="nvx-modal__close" aria-label="Закрити">' + ICONS.close + '</button>' +
				'</div>' +
				'<div class="nvx-modal__body">' + optionsHtml + '</div>' +
				'<div class="nvx-modal__foot">' +
					'<button type="button" class="nvx-btn nvx-btn--secondary nvx-modal__btn-close">Закрити</button>' +
				'</div>' +
			'</div>' +
		'</div>');

		function cleanup() {
			$(document).off('keydown.nvxPrintModal');
			$backdrop.removeClass('is-active');
			setTimeout(function () {
				$backdrop.remove();
			}, 180);
		}

		$backdrop.find('.nvx-modal__btn-close, .nvx-modal__close').on('click', function () {
			cleanup();
		});

		$backdrop.find('.nvx-print-option').on('click', function () {
			setTimeout(cleanup, 100);
		});

		$backdrop.on('click', function (e) {
			if ($(e.target).is('.nvx-modal-backdrop')) {
				cleanup();
			}
		});

		$(document).on('keydown.nvxPrintModal', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				cleanup();
			}
		});

		$('body').append($backdrop);
		$backdrop[0].offsetHeight;
		$backdrop.addClass('is-active');
	}

return {
		post: post,
		openPrintModal: openPrintModal,
		setStatus: setStatus,
		confirm: confirm,
		alert: alert
	};
})(jQuery);
