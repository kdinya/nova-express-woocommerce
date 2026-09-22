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

	return {
		post: post,
		setStatus: setStatus,
		confirm: confirm,
		alert: alert
	};
})(jQuery);
