/* Nova Express — конструктор правил автоматизації (мульти-тригери). */
jQuery(function ($) {
	'use strict';

	var $app = $('#nvx-automation-app');
	var ruleKind = $app.data('rule-kind') || 'ttn';
	if (!$app.length) {
		return;
	}

	var statuses = JSON.parse($app.attr('data-statuses') || '{}');
	var orderStatuses = JSON.parse($app.attr('data-order-statuses') || '{}');
	var rules = JSON.parse($app.attr('data-rules') || '[]');

	var actionTemplates = {
		add_note: '#nvx-action-note-template',
		change_status: '#nvx-action-status-template',
		send_webhook: '#nvx-action-webhook-template',
		send_email: '#nvx-action-email-template'
	};

	function buildTriggerChecks($rule, selected) {
		selected = selected || [];
		if (!selected.length) {
			selected = ['any'];
		}
		var $box = $rule.find('.nvx-trigger-checks');
		// leave the "any" label, rebuild rest
		$box.find('label').not('.nvx-trigger-any').remove();
		var $any = $box.find('.nvx-rule-trigger-any');
		$any.prop('checked', selected.indexOf('any') !== -1).prop('disabled', false);

		$.each(statuses, function (code, label) {
			var id = 'nvx-tr-' + ($rule.attr('data-id') || 'new') + '-' + code + '-' + Math.random().toString(36).slice(2, 7);
			var checked = selected.indexOf(String(code)) !== -1 && selected.indexOf('any') === -1;
			var $lab = $('<label>').attr('for', id);
			var $cb = $('<input>', { type: 'checkbox', class: 'nvx-rule-trigger-code', value: code, id: id });
			$cb.prop('checked', checked).prop('disabled', false);
			// Цифра/код статусу спереду (крім службових ttn_created / ttn_added)
			var prefix = (String(code) === 'ttn_created' || String(code) === 'ttn_added') ? '' : ('[' + code + '] ');
			$lab.append($cb).append(document.createTextNode(' ' + prefix + label));
			if (checked) {
				$lab.addClass('is-checked');
			}
			$box.append($lab);
		});

		if ($any.is(':checked')) {
			$box.find('.nvx-trigger-any').addClass('is-checked');
		}
	}

	function syncTriggerVisual($rule) {
		$rule.find('.nvx-trigger-checks label').each(function () {
			var $lab = $(this);
			var $cb = $lab.find('input[type="checkbox"]');
			$lab.toggleClass('is-checked', $cb.is(':checked'));
		});
	}

	function bindTriggerLogic($rule) {
		// «Будь-яка зміна» і конкретні статуси — взаємно виключні, але всі клікабельні.
		$rule.on('change', '.nvx-rule-trigger-any', function () {
			if ($(this).is(':checked')) {
				$rule.find('.nvx-rule-trigger-code').prop('checked', false);
			}
			syncTriggerVisual($rule);
		});
		$rule.on('change', '.nvx-rule-trigger-code', function () {
			if ($(this).is(':checked')) {
				$rule.find('.nvx-rule-trigger-any').prop('checked', false);
			}
			if (!$rule.find('.nvx-rule-trigger-code:checked').length && !$rule.find('.nvx-rule-trigger-any').is(':checked')) {
				$rule.find('.nvx-rule-trigger-any').prop('checked', true);
			}
			syncTriggerVisual($rule);
		});
		$rule.on('mousedown', '.nvx-trigger-checks label', function (e) {
			e.preventDefault();
			var $cb = $(this).find('input[type="checkbox"]');
			$cb.prop('disabled', false);
			$cb.prop('checked', !$cb.prop('checked'));
			if ($cb.hasClass('nvx-rule-trigger-any')) {
				if ($cb.prop('checked')) {
					$rule.find('.nvx-rule-trigger-code').prop('checked', false);
				}
			} else {
				if ($cb.prop('checked')) {
					// Вибір конкретного статусу знімає «будь-яка зміна»
					$rule.find('.nvx-rule-trigger-any').prop('checked', false);
				}
				if (!$rule.find('.nvx-rule-trigger-code:checked').length && !$rule.find('.nvx-rule-trigger-any').is(':checked')) {
					$rule.find('.nvx-rule-trigger-any').prop('checked', true);
				}
			}
			syncTriggerVisual($rule);
		});
		$rule.on('click', '.nvx-trigger-checks label', function (e) {
			e.preventDefault();
		});
		syncTriggerVisual($rule);
	}

	function collectTriggers($rule) {
		if (ruleKind === 'order') {
			return $rule.find('.nvx-rule-trigger-select').val() || '';
		}
		if ($rule.find('.nvx-rule-trigger-any').is(':checked')) {
			return 'any';
		}
		var codes = [];
		$rule.find('.nvx-rule-trigger-code:checked').each(function () {
			codes.push(String($(this).val()));
		});
		return codes.length ? codes.join(',') : 'any';
	}

	function buildOrderStatusOptions($select) {
		$select.empty();
		$.each(orderStatuses, function (key, label) {
			var clean = key.replace('wc-', '');
			$select.append($('<option>', { value: clean, text: label }));
		});
	}

	function parseFieldsList(raw) {
		// null/undefined = «не задано» → усі галочки
		if (raw === null || raw === undefined) {
			return null;
		}
		if (Array.isArray(raw)) {
			return raw.map(String);
		}
		raw = String(raw).trim();
		if (!raw) {
			return [];
		}
		if (raw.charAt(0) === '[') {
			try {
				var arr = JSON.parse(raw);
				return Array.isArray(arr) ? arr.map(String) : [];
			} catch (e) {
				return [];
			}
		}
		return raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
	}

	function syncWebhookFieldsHidden($block) {
		var selected = [];
		$block.find('.nvx-action-field-check').each(function () {
			if (this.checked) {
				selected.push($(this).attr('data-field'));
			}
		});
		$block.find('.nvx-action-field[data-key="fields"]').val(JSON.stringify(selected));
	}

	function exampleSmsPreview(template) {
		var samples = {
			'{order_number}': '1234',
			'{order_id}': '456',
			'{order_status}': 'Виконується',
			'{waybill}': '20450123456789',
			'{status}': 'Прибув у відділення',
			'{code}': '7',
			'{customer_name}': 'Іван Петренко',
			'{customer_first_name}': 'Іван',
			'{customer_last_name}': 'Петренко',
			'{customer_email}': 'client@example.com',
			'{phone}': '380991112233',
			'{order_total}': '1250',
			'{payment_method}': 'Оплата карткою',
			'{currency}': 'UAH',
			'{order_date}': '25.08.2026',
			'{city_name}': 'Київ',
			'{warehouse}': 'Відділення №12',
			'{description}': 'Замовлення №1234',
			'{additional_info}': 'Посилання на чек : https://kasa.vchasno.ua/check-viewer/ABC',
			'{info}': 'Посилання на чек : https://kasa.vchasno.ua/check-viewer/ABC',
			'{site_name}': 'Мій магазин',
			'{date}': '29.08.2026',
			'{items_count}': '2'
		};
		var text = String(template || '');
		Object.keys(samples).forEach(function (k) {
			text = text.split(k).join(samples[k]);
		});
		// meta:ключ → приклад
		text = text.replace(/\{meta:[^}]+\}/g, 'meta_value');
		text = text.replace(/\{[a-zA-Z0-9_]+\}/g, '…');
		return text;
	}

	function baseWebhookUrl($block) {
		var u = ($block.find('.nvx-action-field[data-key="webhook_url"]').val() || '').trim();
		if (!u) {
			u = 'https://trigger.macrodroid.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/nova_poshta';
		}
		return u;
	}

	function buildQueryUrl(base, params) {
		var url = base;
		var keys = Object.keys(params);
		if (!keys.length) {
			return url;
		}
		var qs = keys.map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k] == null ? '' : params[k]));
		}).join('&');
		return url + (url.indexOf('?') >= 0 ? '&' : '?') + qs;
	}

	function updateSmsUi($block) {
		var tpl = $block.find('.nvx-sms-template').val() || '';
		var previewText = exampleSmsPreview(tpl);
		var len = previewText.length;
		var hasCyr = /[^\x00-\x7F]/.test(previewText);
		var segSize = hasCyr ? 70 : 160;
		var segNext = hasCyr ? 67 : 153;
		var segments = len === 0 ? 0 : (len <= segSize ? 1 : 1 + Math.ceil((len - segSize) / segNext));
		$block.find('[data-count]').text(len + ' симв. ≈ ' + segments + ' SMS');
		var params = {
			n_p_sms: previewText,
			n_p_number: '380991112233'
		};
		var warn = (buildQueryUrl(baseWebhookUrl($block), params).length > 2000)
			? '\n\n⚠️ Після кодування URL > 2000 символів — MacroDroid може обрізати.'
			: '';
		// Читабельний прев'ю: саме текст змінних, не percent-encoding.
		var readable =
			'n_p_sms = ' + previewText + '\n' +
			'n_p_number = ' + params.n_p_number + '\n\n' +
			'→ у URL піде як query: ?n_p_sms=…&n_p_number=…' + warn;
		$block.find('[data-sms-preview]').text(readable);
		$block.data('nvx-last-params', params);
	}

	function updateDataPreview($block) {
		syncWebhookFieldsHidden($block);
		var samples = {
			n_p_status: '7',
			n_p_info: 'Замовлення №1234',
			n_p_name: 'Іван Петренко',
			n_p_number: '380991112233',
			n_p_number_my: '380501112233',
			n_p_ttn: '20450123456789',
			n_p_status_dostavki: 'Прибув у відділення',
			n_p_primechanie: '',
			n_p_order: '1234',
			n_p_order_id: '456',
			n_p_order_status: 'processing',
			n_p_order_total: '1250',
			n_p_city: 'Київ',
			n_p_warehouse: 'Відділення №12',
			n_p_email: 'client@example.com',
			n_p_event: 'status_changed',
			n_p_event_id: 'abc123'
		};
		var params = {};
		$block.find('.nvx-action-field-check:checked').each(function () {
			var f = $(this).attr('data-field');
			if (f) {
				params[f] = samples[f] != null ? samples[f] : '';
			}
		});
		var keys = Object.keys(params);
		var lines = keys.map(function (k) { return k + ' = ' + params[k]; });
		var note = keys.length ? lines.join('\n') : '(не вибрано жодного поля)';
		var warn = buildQueryUrl(baseWebhookUrl($block), params).length > 2000
			? '\n\n⚠️ Після кодування URL > 2000 символів — скоротіть набір полів.'
			: '';
		$block.find('[data-data-preview]').text(note + '\n\n→ у URL піде як query: ?n_p_…=…' + warn);
		$block.data('nvx-last-params', params);
	}

	function bindWebhookBlock($block, values) {
		values = values || {};
		var uid = 'nvx_pm_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
		$block.find('.nvx-payload-mode-radio').attr('name', uid);

		// delivery-method (GET/POST) — окрема група радіо-кнопок, тому потребує
		// СВОГО унікального "name" (без нього браузер не групує кнопки, і можна
		// було вибрати/лишити позначеними обидві одночасно — саме той баг,
		// на який скаржились: "не можна вибрати щось одне").
		var uidDelivery = 'nvx_dm_' + Date.now() + '_' + Math.random().toString(36).slice(2, 7);
		$block.find('.nvx-delivery-method-radio').attr('name', uidDelivery);

		var mode = String(values.payload_mode || 'data');
		if (mode !== 'sms') { mode = 'data'; }
		$block.find('.nvx-action-field[data-key="payload_mode"]').val(mode);
		$block.find('.nvx-payload-mode-radio').each(function () {
			this.checked = (String(this.value) === mode);
		});

		// delivery_method: 'get' (за замовчуванням, як і раніше) або 'post'
		// (JSON у тілі) — стосується лише режиму 'data'; SMS завжди GET
		// (так влаштований сам MacroDroid-тригер), тому селектор для нього ховається.
		var deliveryMethod = String(values.delivery_method || 'get');
		if (deliveryMethod !== 'post') { deliveryMethod = 'get'; }
		$block.find('.nvx-action-field[data-key="delivery_method"]').val(deliveryMethod);
		$block.find('.nvx-delivery-method-radio').each(function () {
			this.checked = (String(this.value) === deliveryMethod);
		});
		$block.on('change', '.nvx-delivery-method-radio', function () {
			var v = $block.find('.nvx-delivery-method-radio:checked').val() || 'get';
			$block.find('.nvx-action-field[data-key="delivery_method"]').val(v);
		});

		function syncModePanels() {
			var m = $block.find('.nvx-payload-mode-radio:checked').val() || 'data';
			$block.find('.nvx-action-field[data-key="payload_mode"]').val(m);
			if (m === 'sms') {
				$block.find('.nvx-webhook-sms').prop('hidden', false);
				$block.find('.nvx-webhook-fields').prop('hidden', true);
				$block.find('.nvx-delivery-method-field').hide();
				updateSmsUi($block);
			} else {
				$block.find('.nvx-webhook-sms').prop('hidden', true);
				$block.find('.nvx-webhook-fields').prop('hidden', false);
				$block.find('.nvx-delivery-method-field').show();
				updateDataPreview($block);
			}
		}

		// include_structured
		var includeStruct = values.include_structured;
		if (includeStruct === undefined || includeStruct === null || includeStruct === '') {
			includeStruct = '1';
		}
		var structOn = (includeStruct === '1' || includeStruct === 1 || includeStruct === true || includeStruct === 'true');
		$block.find('.nvx-action-check[data-key="include_structured"]').prop('checked', !!structOn);

		// fields checkboxes
		var selected = parseFieldsList(values.fields);
		var $checks = $block.find('.nvx-action-field-check');
		if (selected === null) {
			$checks.each(function () { this.checked = true; });
		} else {
			var selMap = {};
			selected.forEach(function (k) { selMap[String(k)] = true; });
			$checks.each(function () {
				var f = $(this).attr('data-field');
				this.checked = !!selMap[String(f)];
			});
		}
		syncWebhookFieldsHidden($block);

		if (values.sms_template) {
			$block.find('.nvx-sms-template').val(values.sms_template);
		}

		syncModePanels();

		$block.on('change', '.nvx-payload-mode-radio', syncModePanels);
		$block.on('change', '.nvx-action-field-check, .nvx-action-check[data-key="include_structured"]', function () {
			syncWebhookFieldsHidden($block);
			updateDataPreview($block);
		});
		$block.find('.nvx-fields-all').on('click', function (e) {
			e.preventDefault();
			$block.find('.nvx-action-field-check').each(function () { this.checked = true; });
			syncWebhookFieldsHidden($block);
			updateDataPreview($block);
		});
		$block.find('.nvx-fields-none').on('click', function (e) {
			e.preventDefault();
			$block.find('.nvx-action-field-check').each(function () { this.checked = false; });
			syncWebhookFieldsHidden($block);
			updateDataPreview($block);
		});
		$block.on('input', '.nvx-sms-template', function () {
			updateSmsUi($block);
		});
		$block.on('input change', '.nvx-action-field[data-key="webhook_url"]', function () {
			var m = $block.find('.nvx-action-field[data-key="payload_mode"]').val() || 'data';
			if (m === 'sms') { updateSmsUi($block); } else { updateDataPreview($block); }
		});

		function syncModeChips() {
			$block.find('.nvx-mode-chip').each(function () {
				var $lab = $(this);
				// Спрацьовує для обох груп радіо-кнопок у чіпах: payload-mode
				// (Дані/SMS) і delivery-method (GET/POST) — визначаємо, який саме
				// input лежить у цій мітці, а не жорстко прив'язуємось до одного класу.
				var $radio = $lab.find('.nvx-payload-mode-radio, .nvx-delivery-method-radio');
				$lab.toggleClass('is-active', $radio.is(':checked'));
			});
		}
		syncModeChips();
		$block.on('change', '.nvx-payload-mode-radio, .nvx-delivery-method-radio', function () {
			syncModeChips();
		});
		$block.on('click', '.nvx-mode-chip', function (e) {
			var $radio = $(this).find('.nvx-payload-mode-radio, .nvx-delivery-method-radio');
			if ($radio.length && !$radio.prop('checked')) {
				$radio.prop('checked', true).trigger('change');
			}
			syncModeChips();
		});

		$block.find('.nvx-webhook-test').on('click', function () {
			var $btn = $(this);
			var $st = $block.find('.nvx-webhook-test-status');
			var url = $block.find('.nvx-action-field[data-key="webhook_url"]').val() || '';
			var mode = $block.find('.nvx-action-field[data-key="payload_mode"]').val() || 'data';
			var delivery = $block.find('.nvx-action-field[data-key="delivery_method"]').val() || 'get';
			NvxCore.setStatus($st, 'Надсилаємо тест…');
			$btn.prop('disabled', true);

			var data = {
				webhook_url: url,
				payload_mode: mode,
				delivery_method: delivery
			};
			if (mode === 'sms') {
				updateSmsUi($block);
				var p = $block.data('nvx-last-params') || {};
				data.n_p_sms = p.n_p_sms || $block.find('.nvx-sms-template').val() || 'Nova Express test SMS';
				data.n_p_number = p.n_p_number || '380991112233';
			} else {
				updateDataPreview($block);
				data.payload = JSON.stringify($block.data('nvx-last-params') || {
					n_p_ttn: '20450123456789',
					n_p_order: '1234',
					n_p_status: '1',
					n_p_status_dostavki: 'Тест',
					n_p_name: 'Тест Клієнт'
				});
			}

			NvxCore.post('nvx_test_webhook', data)
				.done(function (res) {
					if (res && res.success) {
						NvxCore.setStatus($st, '✓ ' + ((res.data && res.data.message) || 'OK'), 'success');
					} else {
						NvxCore.setStatus($st, (res && res.data && res.data.message) || 'Помилка', 'error');
					}
				})
				.fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || ('HTTP ' + (xhr.status || '?'));
					NvxCore.setStatus($st, msg, 'error');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});
	}

	function bindEmailBlock($block) {
		$block.find('.nvx-email-test').on('click', function () {
			var $btn = $(this);
			var $st = $block.find('.nvx-email-test-status');
			var testTo = ($block.find('.nvx-email-test-to').val() || '').trim();
			var subject = $block.find('.nvx-action-field[data-key="email_subject"]').val() || '';
			var body = $block.find('.nvx-action-field[data-key="email_body"]').val() || '';

			if (!testTo) {
				NvxCore.setStatus($st, 'Вкажіть email для тесту', 'error');
				$block.find('.nvx-email-test-to').focus();
				return;
			}

			NvxCore.setStatus($st, 'Надсилаємо тест…');
			$btn.prop('disabled', true);

			NvxCore.post('nvx_test_email', {
				test_email_to: testTo,
				email_subject: subject,
				email_body: body
			})
				.done(function (res) {
					if (res && res.success) {
						NvxCore.setStatus($st, '✓ ' + ((res.data && res.data.message) || 'OK'), 'success');
					} else {
						NvxCore.setStatus($st, (res && res.data && res.data.message) || 'Помилка', 'error');
					}
				})
				.fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || ('HTTP ' + (xhr.status || '?'));
					NvxCore.setStatus($st, msg, 'error');
				})
				.always(function () {
					$btn.prop('disabled', false);
				});
		});
	}

	function addActionBlock($rule, type, values) {
		var tpl = actionTemplates[type];
		if (!tpl) {
			return;
		}
		var $block = $($(tpl).html());
		values = values || {};

		if (type === 'change_status') {
			buildOrderStatusOptions($block.find('[data-key="target_order_status"]'));
		}

		// Текстові поля (url, sms, email…) — без службових ключів
		$.each(values, function (key, val) {
			if (key === 'fields' || key === 'include_structured' || key === 'type' || key === 'payload_mode') {
				return;
			}
			var $el = $block.find('.nvx-action-field[data-key="' + key + '"]');
			if ($el.length) {
				$el.val(val == null ? '' : val);
			}
		});

		if (type === 'send_webhook') {
			bindWebhookBlock($block, values);
		}

		if (type === 'send_email') {
			bindEmailBlock($block);
		}

		$block.find('.nvx-action-delete').on('click', function () {
			$block.remove();
		});

		$rule.find('.nvx-actions-list').append($block);
	}

	function updateRuleSummary($rule) {
		var title = $rule.find('.nvx-rule-title').val() || 'Без назви';
		var trig = collectTriggers($rule);
		var nAct = $rule.find('.nvx-action-block').length;
		$rule.find('.nvx-rule-card__summary').text(trig + ' · дій: ' + nAct);
	}

	function renderRule(rule, opts) {
		opts = opts || {};
		var $rule = $($('#nvx-rule-template').html());
		rule = rule || {};
		$rule.attr('data-id', rule.id || '');
		$rule.find('.nvx-rule-title').val(rule.title || '');
		$rule.find('.nvx-rule-enabled').prop('checked', rule.is_enabled === undefined ? true : !!(+rule.is_enabled));

		$rule.find('.nvx-rule-enabled').on('change', function (e) {
			e.stopPropagation();
			var id = $rule.attr('data-id');
			var on = $(this).is(':checked') ? 1 : 0;
			if (!id) {
				// Ще не збережене правило — лише локально.
				return;
			}
			var $sw = $(this);
			$sw.prop('disabled', true);
			NvxCore.post('nvx_toggle_rule', { id: id, is_enabled: on })
				.done(function (res) {
					if (!res || !res.success) {
						$sw.prop('checked', !on);
					}
				})
				.fail(function () {
					$sw.prop('checked', !on);
				})
				.always(function () {
					$sw.prop('disabled', false);
				});
		});

		if (ruleKind === 'order') {
			$rule.find('.nvx-action-ttn-only').remove();
			$rule.find('.nvx-trigger-checks').hide();
			$rule.find('.nvx-rule-trigger-select').show();
			var $sel = $rule.find('.nvx-rule-trigger-select');
			$sel.find('option:not([value=""])').remove();
			$.each(statuses, function (code, label) {
				$sel.append($('<option>', { value: String(code), text: label }));
			});
			var selected = rule.trigger_statuses || (rule.trigger_status ? String(rule.trigger_status).split(',') : []);
			var one = selected.length ? String(selected[0]) : '';
			if (one) { $sel.val(one); }
		} else {
			$rule.find('.nvx-rule-trigger-select').hide();
			var selectedTtn = rule.trigger_statuses || (rule.trigger_status ? String(rule.trigger_status).split(',') : ['any']);
			buildTriggerChecks($rule, selectedTtn);
			bindTriggerLogic($rule);
		}

		(rule.actions || []).forEach(function (action) {
			if (ruleKind === 'order' && (action.type === 'change_status' || action.type === 'send_email')) {
				return;
			}
			addActionBlock($rule, action.type, action);
		});

		$rule.find('.nvx-add-action').on('click', function () {
			addActionBlock($rule, $(this).data('type'), {});
			updateRuleSummary($rule);
		});

		$rule.find('.nvx-rule-delete').on('click', function (e) {
			e.stopPropagation();
			if (!window.confirm(NVX_ADMIN.i18n.confirmDelete)) {
				return;
			}
			var id = $rule.attr('data-id');
			if (id) {
				NvxCore.post('nvx_delete_rule', { id: id }).done(function () {
					$rule.remove();
				});
			} else {
				$rule.remove();
			}
		});

		$rule.find('.nvx-rule-save').on('click', function (e) {
			e.stopPropagation();
			saveRule($rule);
		});

		if (opts.expand) {
			$rule.removeClass('is-collapsed');
			$rule.find('.nvx-rule-toggle').attr('aria-expanded', 'true');
		}
		$rule.find('.nvx-rule-toggle').on('click', function (e) {
			e.stopPropagation();
			$rule.toggleClass('is-collapsed');
			$(this).attr('aria-expanded', $rule.hasClass('is-collapsed') ? 'false' : 'true');
		});
		$rule.find('.nvx-rule-card__head').on('click', function (e) {
			if ($(e.target).closest('input,label,button,a,.nvx-rule-drag').length) return;
			$rule.toggleClass('is-collapsed');
			$rule.find('.nvx-rule-toggle').attr('aria-expanded', $rule.hasClass('is-collapsed') ? 'false' : 'true');
		});

		updateRuleSummary($rule);
		$rule.on('change input', '.nvx-rule-title, .nvx-rule-trigger-select, .nvx-rule-trigger-any, .nvx-rule-trigger-code', function () {
			updateRuleSummary($rule);
		});

		$('#nvx-rules-list').append($rule);
	}

	function collectActions($rule) {
		var actions = [];
		$rule.find('.nvx-action-block').each(function () {
			var $block = $(this);
			var action = { type: $block.data('type') };

			$block.find('.nvx-action-field').each(function () {
				var key = $(this).data('key');
				if (!key) return;
				action[key] = $(this).val();
			});

			$block.find('.nvx-action-check[data-key]').each(function () {
				action[$(this).data('key')] = $(this).is(':checked') ? '1' : '0';
			});

			if (action.type === 'send_webhook') {
				syncWebhookFieldsHidden($block);
				var fieldsRaw = $block.find('.nvx-action-field[data-key="fields"]').val() || '[]';
				try {
					action.fields = JSON.parse(fieldsRaw);
				} catch (e) {
					action.fields = [];
				}
				var pm = $block.find('.nvx-action-field[data-key="payload_mode"]').val()
					|| $block.find('.nvx-payload-mode-radio:checked').val()
					|| 'data';
				action.payload_mode = (pm === 'sms') ? 'sms' : 'data';
				action.include_structured = $block.find('.nvx-action-check[data-key="include_structured"]').is(':checked') ? '1' : '0';
				action.sms_template = $block.find('.nvx-sms-template').val() || '';
			}

			actions.push(action);
		});
		return actions;
	}

	function saveRule($rule) {
		var $status = $rule.find('.nvx-rule-status');
		NvxCore.setStatus($status, 'Зберігаємо…');

		NvxCore.post('nvx_save_rule', {
			id: $rule.attr('data-id') || '',
			title: $rule.find('.nvx-rule-title').val(),
			trigger_kind: ruleKind,
			trigger_status: collectTriggers($rule),
			is_enabled: $rule.find('.nvx-rule-enabled').is(':checked') ? 1 : 0,
			actions: JSON.stringify(collectActions($rule))
		})
			.done(function (res) {
				if (res && res.success) {
					$rule.attr('data-id', res.data.id);
					NvxCore.setStatus($status, '✓ Збережено', 'success');
				} else {
					NvxCore.setStatus($status, (res && res.data && res.data.message) || NVX_ADMIN.i18n.error, 'error');
				}
			})
			.fail(function (xhr) {
				var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'Запит не вдався (HTTP ' + (xhr && xhr.status ? xhr.status : '?') + ').';
				NvxCore.setStatus($status, msg, 'error');
			});
	}

	if (!rules.length) {
		$('#nvx-rules-empty').show();
	} else {
		rules.forEach(renderRule);
	}

	$('#nvx-new-rule').on('click', function () {
		$('#nvx-rules-empty').hide();
		renderRule({ trigger_status: ruleKind === 'order' ? '' : 'any', trigger_statuses: ruleKind === 'order' ? [] : ['any'], is_enabled: 1, actions: [] }, { expand: true });
	});

	$('#nvx-clear-log').on('click', function () {
		if (!window.confirm('Очистити журнал виконань?')) {
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		NvxCore.post('nvx_clear_automation_log', {}).done(function (res) {
			if (res && res.success) {
				$('#nvx-log-body').html('<p class="nvx-empty">Поки що немає записів.</p>');
			}
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ---- Drag-and-drop сортування карток правил ----
	function persistRuleOrder() {
		var ids = [];
		$('#nvx-rules-list .nvx-rule-card').each(function () {
			var id = $(this).attr('data-id');
			if (id) {
				ids.push(parseInt(id, 10));
			}
		});
		if (!ids.length) {
			return;
		}
		NvxCore.post('nvx_reorder_rules', { ids: JSON.stringify(ids) });
	}

	(function initDragReorder() {
		var $list = $('#nvx-rules-list');
		var draggedEl = null;

		$list.on('dragstart', '.nvx-rule-drag', function (e) {
			draggedEl = $(this).closest('.nvx-rule-card')[0];
			if (!draggedEl) {
				return;
			}
			draggedEl.classList.add('is-dragging');
			if (e.originalEvent && e.originalEvent.dataTransfer) {
				e.originalEvent.dataTransfer.effectAllowed = 'move';
				// Firefox вимагає непорожній setData(), щоб drag взагалі почався.
				try { e.originalEvent.dataTransfer.setData('text/plain', ''); } catch (err) { /* ignore */ }
			}
		});

		$list.on('dragend', '.nvx-rule-drag', function () {
			if (draggedEl) {
				draggedEl.classList.remove('is-dragging');
			}
			draggedEl = null;
			persistRuleOrder();
		});

		$list.on('dragover', '.nvx-rule-card', function (e) {
			if (!draggedEl || this === draggedEl) {
				return;
			}
			e.preventDefault();
			if (e.originalEvent && e.originalEvent.dataTransfer) {
				e.originalEvent.dataTransfer.dropEffect = 'move';
			}
			var rect = this.getBoundingClientRect();
			var clientY = e.originalEvent ? e.originalEvent.clientY : e.clientY;
			var after = (clientY - rect.top) > (rect.height / 2);
			if (after) {
				$(this).after(draggedEl);
			} else {
				$(this).before(draggedEl);
			}
		});

		// Дозволяємо drop (без цього браузер за замовчуванням його забороняє).
		$list.on('drop', '.nvx-rule-card', function (e) {
			e.preventDefault();
		});
	})();
});
