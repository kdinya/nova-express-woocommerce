/**
 * Інтерактивний вибір та живий попередній перегляд кольору в адмінці Nova Express.
 */
(function ($) {
	'use strict';

	$(function () {
		var $picker = $('#nvx_color_picker');
		var $text = $('#nvx_admin_primary_color');
		var $presets = $('.nvx-preset-btn');

		function hexToRgb(hex) {
			hex = hex.replace('#', '');
			if (hex.length === 3) {
				hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
			}
			if (hex.length !== 6) {
				return { r: 124, g: 179, b: 66 };
			}
			return {
				r: parseInt(hex.substring(0, 2), 16),
				g: parseInt(hex.substring(2, 4), 16),
				b: parseInt(hex.substring(4, 6), 16)
			};
		}

		function applyColor(hex) {
			if (!hex || !/^#[0-9a-fA-F]{6}$/.test(hex)) {
				return;
			}

			var rgb = hexToRgb(hex);
			var darkR = Math.max(0, Math.round(rgb.r * 0.85));
			var darkG = Math.max(0, Math.round(rgb.g * 0.85));
			var darkB = Math.max(0, Math.round(rgb.b * 0.85));
			var darkHex = '#' + ((1 << 24) + (darkR << 16) + (darkG << 8) + darkB).toString(16).slice(1);

			var lightR = Math.min(255, Math.round(rgb.r + (255 - rgb.r) * 0.15));
			var lightG = Math.min(255, Math.round(rgb.g + (255 - rgb.g) * 0.15));
			var lightB = Math.min(255, Math.round(rgb.b + (255 - rgb.b) * 0.15));
			var lightHex = '#' + ((1 << 24) + (lightR << 16) + (lightG << 8) + lightB).toString(16).slice(1);

			var root = document.documentElement;
			root.style.setProperty('--nvx-primary', hex);
			root.style.setProperty('--nvx-primary-dark', darkHex);
			root.style.setProperty('--nvx-primary-soft', 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.09)');
			root.style.setProperty('--nvx-header-gradient', 'linear-gradient(135deg, ' + hex + ' 0%, ' + lightHex + ' 50%, ' + darkHex + ' 100%)');
			root.style.setProperty('--nvx-header-shadow', '0 10px 28px rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.28)');

			// Синхронізація активного пресету
			$presets.each(function () {
				var presetColor = $(this).data('color');
				if (presetColor && presetColor.toLowerCase() === hex.toLowerCase()) {
					$(this).addClass('is-active');
				} else {
					$(this).removeClass('is-active');
				}
			});
		}

		// Зміна через піпетку
		$picker.on('input change', function () {
			var val = $(this).val();
			$text.val(val);
			applyColor(val);
		});

		// Зміна через текстове поле
		$text.on('input change', function () {
			var val = $(this).val().trim();
			if (val.charAt(0) !== '#') {
				val = '#' + val;
			}
			if (/^#[0-9a-fA-F]{6}$/.test(val)) {
				$picker.val(val);
				applyColor(val);
			}
		});

		// Клік по пресету
		$presets.on('click', function () {
			var color = $(this).data('color');
			if (color) {
				$picker.val(color);
				$text.val(color);
				applyColor(color);
			}
		});
	});
})(jQuery);
