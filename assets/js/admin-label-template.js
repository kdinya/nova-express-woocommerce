/* Nova Express — Конструктор етикетки ТТН (Live Preview) */
jQuery(function ($) {
	'use strict';

	function updateLabelPreview() {
		var w = parseInt($('#nvx_lbl_width').val(), 10) || 100;
		var h = parseInt($('#nvx_lbl_height').val(), 10) || 150;
		var align = $('#nvx_lbl_align').val() || 'center';
		var fontSize = $('#nvx_lbl_font_size').val() || 'medium';

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
			small:  { ttn: '15px', name: '12px', text: '10px' },
			medium: { ttn: '18px', name: '14px', text: '11px' },
			large:  { ttn: '21px', name: '16px', text: '13px' }
		};
		var f = fontMap[fontSize] || fontMap.medium;

		$('#prev-ttn').css('font-size', f.ttn).toggle($('#nvx_chk_ttn').is(':checked'));
		$('#prev-barcode').toggle($('#nvx_chk_barcode').is(':checked'));
		$('#prev-name').css('font-size', f.name).toggle($('#nvx_chk_name').is(':checked'));
		$('#prev-phone').css('font-size', f.text).toggle($('#nvx_chk_phone').is(':checked'));
		$('#prev-address').css('font-size', f.text).toggle($('#nvx_chk_address').is(':checked'));
		$('#prev-order').toggle($('#nvx_chk_order').is(':checked'));
		$('#prev-items').toggle($('#nvx_chk_items').is(':checked'));
		$('#prev-total').css('font-size', f.text).toggle($('#nvx_chk_total').is(':checked'));

		var note = ($('#nvx_lbl_note').val() || '').trim();
		if (note) {
			$('#prev-note').text(note).show();
		} else {
			$('#prev-note').hide();
		}
	}

	$(document).on('input change', '#nvx_lbl_width, #nvx_lbl_height, #nvx_lbl_m_top, #nvx_lbl_m_sides, #nvx_lbl_align, #nvx_lbl_font_size, #nvx_lbl_note', updateLabelPreview);
	$(document).on('change', '#nvx_chk_ttn, #nvx_chk_barcode, #nvx_chk_name, #nvx_chk_phone, #nvx_chk_address, #nvx_chk_order, #nvx_chk_items, #nvx_chk_total', updateLabelPreview);

	updateLabelPreview();
});
