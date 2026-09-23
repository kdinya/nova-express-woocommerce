/* Nova Express — Конструктор етикетки ТТН (Live Preview) */
jQuery(function ($) {
	'use strict';

	function updateLabelPreview() {
		var w = parseInt($('#nvx_lbl_width').val(), 10) || 100;
		var h = parseInt($('#nvx_lbl_height').val(), 10) || 150;
		var align = $('#nvx_lbl_align').val() || 'center';
		var fontSize = $('#nvx_lbl_font_size').val() || 'medium';
		var ttnPt = parseInt($('#nvx_lbl_ttn_font_size').val(), 10) || 22;
		var barcodeH = parseInt($('#nvx_lbl_barcode_height').val(), 10) || 50;
		var spacing = parseInt($('#nvx_lbl_item_spacing').val(), 10) || 2;

		$('#nvx-preview-dims').text(w + ' × ' + h + ' мм');

		var $box = $('#nvx-label-preview-box');
		if (!$box.length) return;

		var boxWidth = 240;
		var boxHeight = Math.round(boxWidth * (h / w));
		$box.css({
			'width': boxWidth + 'px',
			'min-height': Math.max(260, Math.min(boxHeight, 460)) + 'px',
			'text-align': align
		});

		var fontMap = {
			small:  { name: '12px', text: '10px' },
			medium: { name: '14px', text: '11px' },
			large:  { name: '16px', text: '13px' }
		};
		var f = fontMap[fontSize] || fontMap.medium;

		// Масштабування pt до пікселів у preview
		var ttnPx = Math.round(ttnPt * 0.85);
		$('#prev-ttn')
			.css({
				'font-size': ttnPx + 'px',
				'margin-bottom': spacing + 'px'
			})
			.toggle($('#nvx_chk_ttn').is(':checked'));

		// Висота штрих-коду
		var barScale = Math.round(barcodeH * 0.7);
		$('#prev-barcode-bar').css('height', Math.max(20, barScale) + 'px');
		$('#prev-barcode')
			.css({
				'margin-top': spacing + 'px',
				'margin-bottom': spacing + 'px'
			})
			.toggle($('#nvx_chk_barcode').is(':checked'));

		$('#prev-name')
			.css({
				'font-size': f.name,
				'margin-top': spacing + 'px'
			})
			.toggle($('#nvx_chk_name').is(':checked'));

		$('#prev-phone')
			.css({
				'font-size': f.text,
				'margin-top': Math.max(1, Math.round(spacing * 0.7)) + 'px'
			})
			.toggle($('#nvx_chk_phone').is(':checked'));

		$('#prev-address')
			.css({
				'font-size': f.text,
				'margin-top': Math.max(1, Math.round(spacing * 0.7)) + 'px'
			})
			.toggle($('#nvx_chk_address').is(':checked'));

		$('#prev-order')
			.css('margin-top', spacing + 'px')
			.toggle($('#nvx_chk_order').is(':checked'));

		$('#prev-total')
			.css({
				'font-size': f.text,
				'margin-top': spacing + 'px'
			})
			.toggle($('#nvx_chk_total').is(':checked'));

		$('#prev-items')
			.css('margin-top', spacing + 'px')
			.toggle($('#nvx_chk_items').is(':checked'));

		var note = ($('#nvx_lbl_note').val() || '').trim();
		if (note) {
			$('#prev-note').text(note).css('margin-top', spacing + 'px').show();
		} else {
			$('#prev-note').hide();
		}
	}

	$(document).on('input change', '#nvx_lbl_width, #nvx_lbl_height, #nvx_lbl_m_top, #nvx_lbl_m_sides, #nvx_lbl_align, #nvx_lbl_font_size, #nvx_lbl_ttn_font_size, #nvx_lbl_barcode_height, #nvx_lbl_item_spacing, #nvx_lbl_note', updateLabelPreview);
	$(document).on('change', '#nvx_chk_ttn, #nvx_chk_barcode, #nvx_chk_name, #nvx_chk_phone, #nvx_chk_address, #nvx_chk_order, #nvx_chk_items, #nvx_chk_total', updateLabelPreview);

	updateLabelPreview();
});
