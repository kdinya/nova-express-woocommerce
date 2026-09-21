/* Nova Express — спільні хелпери адмінки. */
window.NvxCore = (function ($) {
	'use strict';

	function post(action, data) {
		return $.post(NVX_ADMIN.ajaxUrl, Object.assign({ action: action, nonce: NVX_ADMIN.nonce }, data));
	}

	function setStatus($el, text, type) {
		$el.text(text).removeClass('is-success is-error').addClass(type ? 'is-' + type : '');
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

	return { post: post, setStatus: setStatus };
})(jQuery);
