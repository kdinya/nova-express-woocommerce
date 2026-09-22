<?php
/**
 * @var array  $rules
 * @var array  $recent_log
 * @var array  $statuses
 * @var array  $status_help
 * @var array  $order_statuses
 * @var string $rule_kind ttn|order
 */
defined( 'ABSPATH' ) || exit;
$rule_kind = isset( $rule_kind ) && 'order' === $rule_kind ? 'order' : 'ttn';
?>
<div class="nvx-wrap" id="nvx-automation-app"
	data-rule-kind="<?php echo esc_attr( $rule_kind ); ?>"
	data-statuses="<?php echo esc_attr( wp_json_encode( $statuses ) ); ?>"
	data-order-statuses="<?php echo esc_attr( wp_json_encode( $order_statuses ) ); ?>"
	data-rules="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>">

<style id="nvx-auto-layout-css">
#nvx-automation-app .nvx-auto-layout{
	display:flex !important;
	gap:24px !important;
	align-items:flex-start !important;
	width:100% !important;
}
#nvx-automation-app .nvx-auto-layout__main{
	flex:1 1 62% !important;
	max-width: 680px !important;
	min-width:0 !important;
	display:flex !important;
	flex-direction:column !important;
	gap:20px !important;
}
#nvx-automation-app .nvx-auto-layout__side{
	flex:0 0 340px !important;
	min-width:280px !important;
	position:sticky !important;
	top:46px !important;
	align-self:flex-start !important;
}
#nvx-automation-app .nvx-auto-layout__side .nvx-status-guide{ margin-top:0 !important; }
@media (max-width:960px){
	#nvx-automation-app .nvx-auto-layout{
		flex-direction:column !important;
	}
	#nvx-automation-app .nvx-auto-layout__side{
		width:100% !important;
		flex:none !important;
		position:static !important;
	}
}
</style>

	<div class="nvx-header">
		<div class="nvx-header__logo"><img src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ); ?>" alt="Nova Express Woo" /></div>
		<div>
			<h1><?php echo 'order' === $rule_kind
				? esc_html__( 'Автоматизації замовлень', 'wc-nova-express' )
				: esc_html__( 'Автоматизації ТТН', 'wc-nova-express' ); ?></h1>
			<p><?php echo 'order' === $rule_kind
				? esc_html__( 'Дії при зміні статусу замовлення WooCommerce (вебхук, SMS, email, нотатка…)', 'wc-nova-express' )
				: esc_html__( 'Дії, що виконуються автоматично при зміні статусу ТТН у Новій Пошті', 'wc-nova-express' ); ?></p>
		</div>
		<button type="button" class="nvx-btn nvx-btn--primary" id="nvx-new-rule"><?php esc_html_e( '+ Нове правило', 'wc-nova-express' ); ?></button>
	</div>

	<div class="nvx-auto-layout">
		<div class="nvx-auto-layout__main">
			<div class="nvx-auto-layout__rules">
				<div id="nvx-rules-list" class="nvx-rules-list">
					<p class="nvx-empty" id="nvx-rules-empty" style="display:none;"><?php esc_html_e( 'Правил ще немає. Створіть перше правило автоматизації.', 'wc-nova-express' ); ?></p>
				</div>
			</div>

			<div class="nvx-auto-layout__log">
				<section class="nvx-card nvx-card--log">
					<div class="nvx-log-head">
						<h2><?php esc_html_e( 'Журнал останніх виконань', 'wc-nova-express' ); ?></h2>
						<button type="button" class="nvx-btn nvx-btn--ghost nvx-btn--sm" id="nvx-clear-log"><?php esc_html_e( 'Очистити журнал', 'wc-nova-express' ); ?></button>
					</div>
					<div id="nvx-log-body">
					<?php if ( empty( $recent_log ) ) : ?>
						<p class="nvx-empty"><?php esc_html_e( 'Поки що немає записів.', 'wc-nova-express' ); ?></p>
					<?php else : ?>
						<table class="nvx-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Час', 'wc-nova-express' ); ?></th>
									<th><?php esc_html_e( 'Замовлення', 'wc-nova-express' ); ?></th>
									<th><?php esc_html_e( 'ТТН', 'wc-nova-express' ); ?></th>
									<th><?php esc_html_e( 'Дія', 'wc-nova-express' ); ?></th>
									<th><?php esc_html_e( 'Результат', 'wc-nova-express' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php
							$nvx_action_type_labels = array(
								'add_note'      => '📝 ' . __( 'Нотатка', 'wc-nova-express' ),
								'change_status' => '🔄 ' . __( 'Статус замовлення', 'wc-nova-express' ),
								'send_webhook'  => '🌐 ' . __( 'Вебхук', 'wc-nova-express' ),
								'send_email'    => '✉️ ' . __( 'Email', 'wc-nova-express' ),
							);
							$nvx_result_labels = array(
								'ok'    => __( 'Успішно', 'wc-nova-express' ),
								'error' => __( 'Помилка', 'wc-nova-express' ),
							);
							?>
							<?php foreach ( $recent_log as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry['created_at'] ); ?></td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $entry['order_id'] ) ); ?>">
											#<?php echo esc_html( $entry['order_id'] ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $entry['waybill_number'] ); ?></td>
									<td><?php echo esc_html( $nvx_action_type_labels[ $entry['action_type'] ] ?? $entry['action_type'] ); ?></td>
									<td>
										<span class="nvx-badge nvx-badge--<?php echo 'ok' === $entry['result'] ? 'success' : 'error'; ?>">
											<?php echo esc_html( $nvx_result_labels[ $entry['result'] ] ?? $entry['result'] ); ?>
										</span>
										<?php if ( ! empty( $entry['message'] ) ) : ?>
											<div class="nvx-log-message"><?php echo esc_html( $entry['message'] ); ?></div>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					</div>
				</section>
			</div>
		</div>

		<aside class="nvx-auto-layout__side">
			<section class="nvx-card nvx-status-guide" style="margin-top:0 !important;">
				<h2><?php echo 'order' === $rule_kind
					? esc_html__( 'Довідка: статуси замовлень WooCommerce', 'wc-nova-express' )
					: esc_html__( 'Довідка: статуси ТТН Нової Пошти', 'wc-nova-express' ); ?></h2>
				<p class="nvx-card__hint"><?php echo 'order' === $rule_kind
					? esc_html__( 'Усі зареєстровані статуси магазину з кодами та моментами їх спрацювання:', 'wc-nova-express' )
					: esc_html__( 'Оберіть потрібний статус як тригер правила. Коротко, що означає кожен код:', 'wc-nova-express' ); ?></p>
				<ul class="nvx-status-guide__list">
					<?php foreach ( $status_help as $code => $item ) : ?>
						<?php if ( 'order' === $rule_kind ) : ?>
							<li>
								<span class="nvx-status-guide__body">
									<span class="nvx-status-guide__headline">
										<strong class="nvx-status-guide__name"><?php echo esc_html( $item['title'] ); ?></strong>
										<span class="nvx-status-guide__slug"><?php echo esc_html( $item['slug'] ); ?></span>
									</span>
									<span class="nvx-status-guide__desc"><?php echo esc_html( $item['help'] ); ?></span>
								</span>
							</li>
						<?php else : ?>
							<li>
								<span class="nvx-status-guide__code"><?php echo esc_html( $code ); ?></span>
								<span class="nvx-status-guide__text">
									<strong><?php echo esc_html( $item['title'] ); ?></strong>
									<?php echo esc_html( $item['help'] ); ?>
								</span>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</section>
		</aside>
	</div>

	<template id="nvx-rule-template">
		<div class="nvx-rule-card is-collapsed" data-id="">
			<div class="nvx-rule-card__head">
				<span class="nvx-rule-drag" draggable="true" title="<?php esc_attr_e( 'Перетягніть, щоб змінити порядок', 'wc-nova-express' ); ?>">⠿</span>
				<button type="button" class="nvx-rule-toggle" aria-expanded="false" title="<?php esc_attr_e( 'Згорнути / розгорнути', 'wc-nova-express' ); ?>">▶</button>
				<label class="nvx-switch">
					<input type="checkbox" class="nvx-rule-enabled" checked />
					<span></span>
				</label>
				<input type="text" class="nvx-rule-title" placeholder="<?php esc_attr_e( 'Назва правила', 'wc-nova-express' ); ?>" />
				<span class="nvx-rule-card__summary"></span>
			</div>

			<div class="nvx-rule-card__body">
				<div class="nvx-field nvx-trigger-field">
					<span><?php echo 'order' === $rule_kind
						? esc_html__( 'Коли статус замовлення змінюється на', 'wc-nova-express' )
						: esc_html__( 'Коли статус ТТН змінюється на (можна кілька)', 'wc-nova-express' ); ?></span>
					<div class="nvx-trigger-checks">
						<label class="nvx-trigger-any"><input type="checkbox" class="nvx-rule-trigger-any" value="any" /> <?php esc_html_e( 'Будь-яка зміна статусу', 'wc-nova-express' ); ?></label>
					</div>
					<select class="nvx-rule-trigger-select" style="display:none;">
						<option value=""><?php esc_html_e( '— оберіть статус —', 'wc-nova-express' ); ?></option>
					</select>
				</div>

				<div class="nvx-actions-list"></div>

				<div class="nvx-add-action-group">
					<span class="nvx-add-action-group__label"><?php esc_html_e( 'Додати дію:', 'wc-nova-express' ); ?></span>
					<button type="button" class="nvx-chip-btn nvx-add-action" data-type="add_note">📝 <?php esc_html_e( 'Нотатка', 'wc-nova-express' ); ?></button>
					<button type="button" class="nvx-chip-btn nvx-add-action nvx-action-ttn-only" data-type="change_status">🔄 <?php esc_html_e( 'Статус замовлення', 'wc-nova-express' ); ?></button>
					<button type="button" class="nvx-chip-btn nvx-add-action" data-type="send_webhook">🌐 <?php esc_html_e( 'Вебхук', 'wc-nova-express' ); ?></button>
					<button type="button" class="nvx-chip-btn nvx-add-action nvx-action-ttn-only" data-type="send_email">✉️ <?php esc_html_e( 'Email', 'wc-nova-express' ); ?></button>
				</div>

				<div class="nvx-rule-card__foot">
					<button type="button" class="nvx-btn nvx-btn--primary nvx-rule-save"><?php esc_html_e( 'Зберегти правило', 'wc-nova-express' ); ?></button>
					<span class="nvx-inline-status nvx-rule-status"></span>
					<button type="button" class="nvx-btn nvx-btn--danger nvx-rule-delete"><?php esc_html_e( 'Видалити', 'wc-nova-express' ); ?></button>
				</div>
			</div>
		</div>
	</template>

	<template id="nvx-action-note-template">
		<div class="nvx-action-block" data-type="add_note">
			<div class="nvx-action-block__head">
				<strong>📝 <?php esc_html_e( 'Додати нотатку до замовлення', 'wc-nova-express' ); ?></strong>
				<button type="button" class="nvx-icon-btn nvx-action-delete">✕</button>
			</div>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Текст (плейсхолдери: {waybill}, {status}, {code}, {order_number}, {order_id}, {order_status}, {customer_name}, {customer_email}, {order_total}, {payment_method}, {city_name}, {warehouse}, {meta:ключ} — повний список у підказці нижче біля вебхука)', 'wc-nova-express' ); ?></span>
				<textarea class="nvx-action-field" data-key="note_template" rows="2"></textarea>
			</label>
		</div>
	</template>

	<template id="nvx-action-status-template">
		<div class="nvx-action-block" data-type="change_status">
			<div class="nvx-action-block__head">
				<strong>🔄 <?php esc_html_e( 'Змінити статус замовлення', 'wc-nova-express' ); ?></strong>
				<button type="button" class="nvx-icon-btn nvx-action-delete">✕</button>
			</div>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Новий статус', 'wc-nova-express' ); ?></span>
				<select class="nvx-action-field" data-key="target_order_status"></select>
			</label>
		</div>
	</template>

	<template id="nvx-action-webhook-template">
		<div class="nvx-action-block" data-type="send_webhook">
			<div class="nvx-action-block__head">
				<strong>🌐 <?php esc_html_e( 'Надіслати вебхук', 'wc-nova-express' ); ?></strong>
				<button type="button" class="nvx-icon-btn nvx-action-delete">✕</button>
			</div>
			<p class="nvx-card__hint">
				<?php esc_html_e( 'Потрібно надіслати кілька вебхуків на цю саму подію? Додайте ще один блок «Вебхук» кнопкою «Додати дію» вище — кожен блок має власний URL, формат і набір даних, і всі додані вебхуки надсилаються.', 'wc-nova-express' ); ?>
			</p>
			<label class="nvx-field">
				<span><?php esc_html_e( 'URL (порожньо = URL за замовчуванням із налаштувань)', 'wc-nova-express' ); ?></span>
				<input type="url" class="nvx-action-field" data-key="webhook_url" placeholder="https://trigger.macrodroid.com/…/nova_poshta" />
			</label>

			<div class="nvx-field">
				<span><?php esc_html_e( 'Режим передачі', 'wc-nova-express' ); ?></span>
				<input type="hidden" class="nvx-action-field" data-key="payload_mode" value="data" />
				<div class="nvx-payload-mode">
					<label class="nvx-mode-chip">
						<input type="radio" class="nvx-payload-mode-radio" value="data" checked />
						<span><?php esc_html_e( 'Вибрані дані (JSON-поля)', 'wc-nova-express' ); ?></span>
					</label>
					<label class="nvx-mode-chip">
						<input type="radio" class="nvx-payload-mode-radio" value="sms" />
						<span><?php esc_html_e( 'Готовий текст SMS', 'wc-nova-express' ); ?></span>
					</label>
				</div>
			</div>

			<div class="nvx-webhook-sms" hidden>
				<div class="nvx-macrodroid-box">
					<div class="nvx-macrodroid-box__title">📱 <?php esc_html_e( 'Відправка SMS через ваш телефон (MacroDroid)', 'wc-nova-express' ); ?></div>
					<p><?php esc_html_e( 'MacroDroid — це додаток автоматизації для Android. Плагін через вебхук передає номер телефону та текст на ваш смартфон, а MacroDroid автоматично відправляє SMS через вашу SIM-карту. Це дозволяє надсилати повідомлення клієнтам безкоштовно, використовуючи пакет SMS вашого мобільного тарифу без підключення платних SMS-сервісів.', 'wc-nova-express' ); ?></p>
				</div>
				<label class="nvx-field">
					<span><?php esc_html_e( 'Шаблон SMS', 'wc-nova-express' ); ?></span>
					<textarea class="nvx-action-field nvx-sms-template" data-key="sms_template" rows="3" placeholder="<?php esc_attr_e( 'Ваше замовлення №{order_number} відправлено. ТТН: {waybill}. Дякуємо!', 'wc-nova-express' ); ?>"></textarea>
				</label>
				<div class="nvx-sms-meta">
					<span class="nvx-sms-counter" data-count>0</span>
					<span class="nvx-sms-meta__hint">
						<?php esc_html_e( 'Символів. Кирилиця: до 70 в 1 SMS, 67 у кожній наступній. Латиниця: 160 / 153. Рекомендовано до 140 символів.', 'wc-nova-express' ); ?>
					</span>
				</div>
				<p class="nvx-card__hint">
					<?php esc_html_e( 'Плейсхолдери:', 'wc-nova-express' ); ?>
					<code>{order_number}</code>,
					<code>{order_id}</code>,
					<code>{order_status}</code>,
					<code>{waybill}</code>,
					<code>{status}</code>,
					<code>{code}</code>,
					<code>{customer_name}</code>,
					<code>{customer_first_name}</code>,
					<code>{customer_last_name}</code>,
					<code>{customer_email}</code>,
					<code>{phone}</code>,
					<code>{order_total}</code>,
					<code>{payment_method}</code>,
					<code>{currency}</code>,
					<code>{order_date}</code>,
					<code>{city_name}</code>,
					<code>{warehouse}</code>,
					<code>{description}</code>,
					<code>{additional_info}</code>,
					<code>{site_name}</code>,
					<code>{date}</code>,
					<code>{items_count}</code>,
					<code>{meta:ключ}</code>
				</p>
				<p class="nvx-card__hint">
					<?php esc_html_e( "{customer_first_name} — лише ім'я (напр. «Іван»). {customer_name} — повне ПІБ. {order_status} — назва статусу замовлення WooCommerce (напр. «Виконується»). {order_date} — дата створення замовлення; {date} — сьогоднішня дата. {city_name}/{warehouse} — місто й відділення отримувача, якщо вже обрані на замовленні. {description} — з шаблону «Опис відправлення». {additional_info} — з «Додаткова інформація», з урахуванням умови «Показувати лише якщо містить». {meta:ключ} — мета-поле замовлення (кастомні поля чекауту тощо).", "wc-nova-express" ); ?>
				</p>
				<div class="nvx-sms-preview">
					<div class="nvx-sms-preview__label"><?php esc_html_e( "Перегляд змінних для MacroDroid:", "wc-nova-express" ); ?></div>
					<pre class="nvx-sms-preview__body" data-sms-preview>{}</pre>
				</div>
			</div>

			<div class="nvx-field nvx-delivery-method-field">
				<span><?php esc_html_e( 'Спосіб надсилання (оберіть один варіант)', 'wc-nova-express' ); ?></span>
				<input type="hidden" class="nvx-action-field" data-key="delivery_method" value="get" />
				<div class="nvx-payload-mode">
					<label class="nvx-mode-chip">
						<input type="radio" class="nvx-delivery-method-radio" value="get" checked />
						<span><?php esc_html_e( 'GET, query-параметри (сумісно з MacroDroid)', 'wc-nova-express' ); ?></span>
					</label>
					<label class="nvx-mode-chip">
						<input type="radio" class="nvx-delivery-method-radio" value="post" />
						<span><?php esc_html_e( 'POST, JSON у тілі (рекомендовано для звичайних вебхуків)', 'wc-nova-express' ); ?></span>
					</label>
				</div>
				<p class="nvx-card__hint">
					<strong><?php esc_html_e( 'GET, query-параметри', 'wc-nova-express' ); ?></strong> —
					<?php esc_html_e( 'обрані дані (в т.ч. ПІБ/телефон/email, якщо обрані нижче) кладуться прямо в адресу запиту (?n_p_ttn=…&n_p_status=…). Такий формат читають прості автоматизації на кшталт MacroDroid/Tasker, але дані можуть лишитись у логах проміжних серверів/проксі. Оберіть цей варіант, якщо ваш приймач саме так і очікує дані — через URL.', 'wc-nova-express' ); ?>
				</p>
				<p class="nvx-card__hint">
					<strong><?php esc_html_e( 'POST, JSON у тілі', 'wc-nova-express' ); ?></strong> —
					<?php esc_html_e( 'ті самі дані (плюс, за бажанням, структуровані блоки нижче) надсилаються одним JSON-тілом запиту, без жодних даних в URL. Рекомендований варіант для звичайних вебхуків: Zapier, Make, n8n, власний сервер-приймач.', 'wc-nova-express' ); ?>
				</p>
			</div>

			<div class="nvx-webhook-fields">
				<span class="nvx-field__label"><?php esc_html_e( 'Які дані передавати', 'wc-nova-express' ); ?></span>
				<label class="nvx-check-line">
					<input type="checkbox" class="nvx-action-check" data-key="include_structured" value="1" checked />
					<span><?php esc_html_e( 'Структуровані блоки (у режимі GET не передаються в URL; передаються при POST)', 'wc-nova-express' ); ?></span>
				</label>
				<p class="nvx-card__hint"><?php esc_html_e( 'У режимі GET у URL йдуть лише вибрані плоскі ключі n_p_* як query-параметри (?n_p_ttn=…&n_p_status=…). У режимі POST — повний JSON (структуровані блоки + ті самі плоскі ключі) в тілі запиту.', 'wc-nova-express' ); ?></p>
				<details class="nvx-structured-help">
					<summary><?php esc_html_e( 'Що входить у структуровані блоки', 'wc-nova-express' ); ?></summary>
					<ul class="nvx-structured-help__list">
						<li><code>event</code><span><?php esc_html_e( 'Тип події — nvx.ttn_status_changed.', 'wc-nova-express' ); ?></span></li>
						<li><code>event_id</code><span><?php esc_html_e( 'Унікальний id події для дедуплікації.', 'wc-nova-express' ); ?></span></li>
						<li><code>sent_at</code><span><?php esc_html_e( 'Час відправки (ISO 8601, UTC).', 'wc-nova-express' ); ?></span></li>
						<li><code>waybill</code><span><?php esc_html_e( 'ТТН і статуси.', 'wc-nova-express' ); ?></span></li>
						<li><code>order</code><span><?php esc_html_e( 'Замовлення + items[].', 'wc-nova-express' ); ?></span></li>
						<li><code>recipient</code><span><?php esc_html_e( 'Отримувач.', 'wc-nova-express' ); ?></span></li>
						<li><code>sender</code><span><?php esc_html_e( 'Відправник.', 'wc-nova-express' ); ?></span></li>
						<li><code>shipping</code><span><?php esc_html_e( 'Місто / відділення.', 'wc-nova-express' ); ?></span></li>
					</ul>
				</details>

				<div class="nvx-webhook-fields__head">
					<span><?php esc_html_e( 'Плоскі ключі n_p_*', 'wc-nova-express' ); ?></span>
					<span class="nvx-webhook-fields__actions">
						<button type="button" class="nvx-link-btn nvx-fields-all"><?php esc_html_e( 'Усі', 'wc-nova-express' ); ?></button>
						<button type="button" class="nvx-link-btn nvx-fields-none"><?php esc_html_e( 'Жодного', 'wc-nova-express' ); ?></button>
					</span>
				</div>
				<div class="nvx-field-checks">
					<?php
					$flat_fields = class_exists( '\NovaExpress\Automation\Action\SendWebhookAction' )
						? \NovaExpress\Automation\Action\SendWebhookAction::available_flat_fields()
						: array();
					foreach ( $flat_fields as $fkey => $flabel ) :
						?>
						<label class="nvx-check-line">
							<input type="checkbox" class="nvx-action-field-check" data-field="<?php echo esc_attr( $fkey ); ?>" value="1" checked />
							<span><?php echo esc_html( $flabel ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<input type="hidden" class="nvx-action-field" data-key="fields" value="" />
				<div class="nvx-sms-preview nvx-data-preview">
					<div class="nvx-sms-preview__label"><?php esc_html_e( "Перегляд змінних для MacroDroid:", "wc-nova-express" ); ?></div>
					<pre class="nvx-sms-preview__body" data-data-preview></pre>
				</div>
			</div>

			<div class="nvx-webhook-test-row">
				<button type="button" class="nvx-btn nvx-btn--ghost nvx-webhook-test"><?php esc_html_e( '▶ Тест вебхука', 'wc-nova-express' ); ?></button>
				<span class="nvx-inline-status nvx-webhook-test-status"></span>
			</div>
		</div>
	</template>

	<template id="nvx-action-email-template">
		<div class="nvx-action-block" data-type="send_email">
			<div class="nvx-action-block__head">
				<strong>✉️ <?php esc_html_e( 'Надіслати email', 'wc-nova-express' ); ?></strong>
				<button type="button" class="nvx-icon-btn nvx-action-delete">✕</button>
			</div>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Кому (порожньо = email клієнта з замовлення; кілька адрес через кому)', 'wc-nova-express' ); ?></span>
				<input type="text" class="nvx-action-field" data-key="email_to" placeholder="client@example.com, manager@shop.ua" />
			</label>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Тема', 'wc-nova-express' ); ?></span>
				<input type="text" class="nvx-action-field" data-key="email_subject" placeholder="<?php esc_attr_e( 'Статус ТТН №{waybill}: {status}', 'wc-nova-express' ); ?>" />
			</label>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Текст листа (плейсхолдери: {waybill}, {status}, {code}, {order_number}, {order_id}, {order_status}, {customer_name}, {customer_email}, {order_total}, {payment_method}, {currency}, {order_date}, {city_name}, {warehouse}, {meta:ключ})', 'wc-nova-express' ); ?></span>
				<textarea class="nvx-action-field" data-key="email_body" rows="4" placeholder="<?php esc_attr_e( 'Замовлення №{order_number}\nТТН: {waybill}\nСтатус: {status}', 'wc-nova-express' ); ?>"></textarea>
			</label>
			<div class="nvx-email-test-row">
				<label class="nvx-field nvx-email-test-to-field">
					<span><?php esc_html_e( 'Куди надіслати тест:', 'wc-nova-express' ); ?></span>
					<input type="email" class="nvx-email-test-to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" placeholder="<?php esc_attr_e( 'admin@example.com', 'wc-nova-express' ); ?>" />
				</label>
				<button type="button" class="nvx-btn nvx-btn--ghost nvx-email-test"><?php esc_html_e( '✉️ Тест email', 'wc-nova-express' ); ?></button>
				<span class="nvx-inline-status nvx-email-test-status"></span>
			</div>
		</div>
	</template>
</div>
