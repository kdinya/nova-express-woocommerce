<?php
/**
 * @var array $settings
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="nvx-wrap">
	<div class="nvx-header">
		<div class="nvx-header__logo" style="background:none;padding:0;overflow:hidden;box-shadow:none;"><img src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ); ?>" alt="Nova Express" style="width:44px;height:44px;display:block;border-radius:8px;" /></div>
		<div>
			<h1><?php esc_html_e( 'Nova Express', 'wc-nova-express' ); ?></h1>
			<p><?php esc_html_e( 'Доставка Новою Поштою, ТТН та автоматизації для вашого магазину', 'wc-nova-express' ); ?></p>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="nvx-alert nvx-alert--success"><?php esc_html_e( 'Налаштування збережено.', 'wc-nova-express' ); ?></div>
	<?php endif; ?>

	<?php if ( 'blocks' === $checkout_type ) : ?>
		<div class="nvx-alert" style="background:#fff7e6; color:#8a6414;">
			<?php esc_html_e( 'Ваша сторінка оформлення замовлення використовує блоковий чекаут WooCommerce (Cart & Checkout Blocks). Поля Nova Express на ній працюють у спрощеному режимі — прості текстові поля без живого пошуку міста/відділення (пошук з підказками доступний лише на класичному чекауті). Якщо потрібен повноцінний UI з автопідбором, замініть блок "Оформлення замовлення" на сторінці на класичну форму: Сторінки → Оформлення замовлення → видаліть блок і вставте шорткод [woocommerce_checkout].', 'wc-nova-express' ); ?>
		</div>
	<?php elseif ( 'unknown' === $checkout_type ) : ?>
		<div class="nvx-alert" style="background:#fff7e6; color:#8a6414;">
			<?php esc_html_e( 'Не вдалося автоматично визначити тип сторінки оформлення замовлення. Якщо поля доставки Nova Express не з’являються на чекауті — перевірте, чи не використовує сторінка блоковий чекаут WooCommerce.', 'wc-nova-express' ); ?>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvx-grid">
		<input type="hidden" name="action" value="nvx_save_settings" />
		<?php wp_nonce_field( 'nvx_save_settings' ); ?>

		<section class="nvx-card">
			<h2><?php esc_html_e( '1. Підключення до API', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint"><?php esc_html_e( 'Ключ можна отримати в особистому кабінеті Нової Пошти → Налаштування → API-ключі.', 'wc-nova-express' ); ?></p>

			<label class="nvx-field">
				<span><?php esc_html_e( 'API-ключ', 'wc-nova-express' ); ?></span>
				<div style="display:flex; gap:8px; align-items:center;">
					<input type="password" id="nvx_api_key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" style="flex:1;" />
					<button type="button" class="nvx-btn nvx-btn--ghost" id="nvx-toggle-api-key" style="white-space:nowrap;">
						👁️ <?php esc_html_e( 'Показати', 'wc-nova-express' ); ?>
					</button>
				</div>
				<small class="nvx-field__hint"><?php esc_html_e( 'Можна ввести новий ключ та одразу натиснути «Синхронізувати базу відділень» або «Отримати дані відправника» без обов\'язкового попереднього збереження форми.', 'wc-nova-express' ); ?></small>
			</label>
		</section>

<section class="nvx-card">
			<h2><?php esc_html_e( '2. База відділень Нової Пошти', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint"><?php esc_html_e( 'Завантажте повну базу відділень/поштоматів локально — це прискорює вибір відділення на чекауті й на сторінці замовлення, без звернення до API при кожному запиті.', 'wc-nova-express' ); ?></p>

			<div class="nvx-sync-box">
				<div class="nvx-sync-box__stat">
					<strong id="nvx-wh-count"><?php echo esc_html( number_format_i18n( $warehouses_count ) ); ?></strong>
					<span><?php esc_html_e( 'відділень у локальній базі', 'wc-nova-express' ); ?></span>
				</div>
				<button type="button" class="nvx-btn nvx-btn--ghost" id="nvx-sync-warehouses">
					<?php esc_html_e( 'Синхронізувати базу відділень', 'wc-nova-express' ); ?>
				</button>
			</div>

			<div class="nvx-sync-log" id="nvx-sync-log"></div>
		</section>

<section class="nvx-card">
			<h2><?php esc_html_e( '3. Дані відправника', 'wc-nova-express' ); ?></h2>

			<div class="nvx-sync-box" style="margin-bottom:16px;">
				<div>
					<strong style="display:block; font-size:13.5px;"><?php esc_html_e( 'Контрагент-відправник', 'wc-nova-express' ); ?></strong>
					<span style="font-size:12px; color:var(--nvx-text-soft);" id="nvx-sender-cp-status">
						<?php
						if ( ! empty( $settings['sender_counterparty_ref'] ) && ! empty( $settings['sender_contact_ref'] ) ) {
							echo '✓ ' . esc_html( $settings['sender_contact_name'] ?: __( 'Налаштовано', 'wc-nova-express' ) );
						} else {
							esc_html_e( 'Ще не отримано — натисніть кнопку праворуч', 'wc-nova-express' );
						}
						?>
					</span>
				</div>
				<button type="button" class="nvx-btn nvx-btn--ghost" id="nvx-fetch-sender">
					<?php esc_html_e( 'Отримати дані відправника автоматично', 'wc-nova-express' ); ?>
				</button>
			</div>
			<p class="nvx-card__hint"><?php esc_html_e( 'Nova Poshta ідентифікує відправника за Ref контрагента, а не просто іменем — без цього кроку створення ТТН завжди завершиться помилкою "Sender not selected".', 'wc-nova-express' ); ?></p>

			<div class="nvx-field-row">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Місто відправлення', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-sender-city-search" value="<?php echo esc_attr( $settings['sender_city_name'] ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву…', 'wc-nova-express' ); ?>" autocomplete="off" />
					<input type="hidden" name="sender_city_ref" id="nvx-sender-city-ref" value="<?php echo esc_attr( $settings['sender_city_ref'] ); ?>" />
					<input type="hidden" name="sender_city_name" id="nvx-sender-city-name" value="<?php echo esc_attr( $settings['sender_city_name'] ); ?>" />
					<div class="nvx-suggest" id="nvx-sender-city-suggest"></div>
				</label>

				<label class="nvx-field">
					<span><?php esc_html_e( 'Відділення/поштомат відправлення', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-sender-warehouse-search" value="<?php echo esc_attr( $settings['sender_warehouse_label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву або номер…', 'wc-nova-express' ); ?>" autocomplete="off" />
					<input type="hidden" name="sender_warehouse_ref" id="nvx-sender-warehouse-ref" value="<?php echo esc_attr( $settings['sender_warehouse_ref'] ); ?>" />
					<input type="hidden" name="sender_warehouse_label" id="nvx-sender-warehouse-label" value="<?php echo esc_attr( $settings['sender_warehouse_label'] ?? '' ); ?>" />
					<div class="nvx-suggest" id="nvx-sender-warehouse-suggest"></div>
				</label>
			</div>

			<div class="nvx-field-row">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Прізвище відправника', 'wc-nova-express' ); ?></span>
					<input type="text" name="sender_last_name" value="<?php echo esc_attr( $settings['sender_last_name'] ); ?>" />
				</label>
				<label class="nvx-field">
					<span><?php esc_html_e( "Ім'я відправника", 'wc-nova-express' ); ?></span>
					<input type="text" name="sender_first_name" value="<?php echo esc_attr( $settings['sender_first_name'] ); ?>" />
				</label>
				<label class="nvx-field">
					<span><?php esc_html_e( 'По батькові', 'wc-nova-express' ); ?></span>
					<input type="text" name="sender_middle_name" value="<?php echo esc_attr( $settings['sender_middle_name'] ); ?>" />
				</label>
				<label class="nvx-field">
					<span><?php esc_html_e( 'Телефон відправника', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-sender-phone" name="sender_phone" value="<?php echo esc_attr( $settings['sender_phone'] ); ?>" placeholder="380671234567" />
				</label>
			</div>
			<p class="nvx-card__hint">
				<?php esc_html_e( 'Натисніть кнопку вище, щоб підтягнути дані наявного відправника з вашого акаунту Нової Пошти автоматично. Або впишіть ПІБ і телефон вручну та натисніть «Зберегти налаштування» — плагін сам знайде/створить відповідного контрагента через Nova Poshta API.', 'wc-nova-express' ); ?>
			</p>

			<?php if ( isset( $_GET['sender_error'] ) ) : ?>
				<div class="nvx-alert" style="background:var(--nvx-danger-soft); color:var(--nvx-danger);">
					<?php esc_html_e( 'Не вдалося зберегти відправника: ', 'wc-nova-express' ); ?>
					<?php echo esc_html( rawurldecode( $_GET['sender_error'] ) ); ?>
				</div>
			<?php endif; ?>
		

			<hr style="border:none;border-top:1px solid var(--nvx-border);margin:20px 0 16px;" />
			<h3 style="margin:0 0 8px;font-size:15px;"><?php esc_html_e( 'Додаткові відділення відправника', 'wc-nova-express' ); ?></h3>
			<p class="nvx-card__hint"><?php esc_html_e( 'Основне відділення вище. За потреби додайте ще склади — кнопка «Додати відділення». На сторінці створення ТТН оберіть, звідки відправляти.', 'wc-nova-express' ); ?></p>
			<div id="nvx-extra-wh-list">
			<?php
			$extras = $settings['additional_warehouses'] ?? array();
			foreach ( $extras as $i => $ex ) :
				if ( empty( $ex['warehouse_ref'] ) ) {
					continue;
				}
			?>
				<div class="nvx-extra-wh" data-index="<?php echo esc_attr( (string) $i ); ?>">
					<label class="nvx-field">
						<span><?php esc_html_e( 'Назва (для себе)', 'wc-nova-express' ); ?></span>
						<input type="text" name="extra_wh_label[]" value="<?php echo esc_attr( $ex['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Напр. Склад Київ', 'wc-nova-express' ); ?>" />
					</label>
					<div class="nvx-field-row">
						<label class="nvx-field">
							<span><?php esc_html_e( 'Місто', 'wc-nova-express' ); ?></span>
							<input type="text" class="nvx-extra-city-search" value="<?php echo esc_attr( $ex['city_name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву…', 'wc-nova-express' ); ?>" autocomplete="off" />
							<input type="hidden" name="extra_wh_city_ref[]" class="nvx-extra-city-ref" value="<?php echo esc_attr( $ex['city_ref'] ?? '' ); ?>" />
							<input type="hidden" name="extra_wh_city_name[]" class="nvx-extra-city-name" value="<?php echo esc_attr( $ex['city_name'] ?? '' ); ?>" />
							<div class="nvx-suggest nvx-extra-city-suggest"></div>
						</label>
						<label class="nvx-field">
							<span><?php esc_html_e( 'Відділення / поштомат', 'wc-nova-express' ); ?></span>
							<input type="text" class="nvx-extra-warehouse-search" value="<?php echo esc_attr( $ex['warehouse_label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву або номер…', 'wc-nova-express' ); ?>" autocomplete="off" />
							<input type="hidden" name="extra_wh_ref[]" class="nvx-extra-warehouse-ref" value="<?php echo esc_attr( $ex['warehouse_ref'] ?? '' ); ?>" />
							<input type="hidden" name="extra_wh_warehouse_label[]" class="nvx-extra-warehouse-label" value="<?php echo esc_attr( $ex['warehouse_label'] ?? '' ); ?>" />
							<div class="nvx-suggest nvx-extra-warehouse-suggest"></div>
						</label>
					</div>
					<button type="button" class="nvx-btn nvx-btn--ghost nvx-extra-wh-remove" style="margin-top:8px;"><?php esc_html_e( 'Видалити', 'wc-nova-express' ); ?></button>
				</div>
			<?php endforeach; ?>
			</div>
			<button type="button" class="nvx-btn nvx-btn--primary" id="nvx-extra-wh-add" style="margin-top:8px;">
				<?php esc_html_e( '+ Додати відділення', 'wc-nova-express' ); ?>
			</button>
			<template id="nvx-extra-wh-tpl">
				<div class="nvx-extra-wh" data-index="new">
					<label class="nvx-field">
						<span><?php esc_html_e( 'Назва (для себе)', 'wc-nova-express' ); ?></span>
						<input type="text" name="extra_wh_label[]" value="" placeholder="<?php esc_attr_e( 'Напр. Склад Київ', 'wc-nova-express' ); ?>" />
					</label>
					<div class="nvx-field-row">
						<label class="nvx-field">
							<span><?php esc_html_e( 'Місто', 'wc-nova-express' ); ?></span>
							<input type="text" class="nvx-extra-city-search" value="" placeholder="<?php esc_attr_e( 'Почніть вводити назву…', 'wc-nova-express' ); ?>" autocomplete="off" />
							<input type="hidden" name="extra_wh_city_ref[]" class="nvx-extra-city-ref" value="" />
							<input type="hidden" name="extra_wh_city_name[]" class="nvx-extra-city-name" value="" />
							<div class="nvx-suggest nvx-extra-city-suggest"></div>
						</label>
						<label class="nvx-field">
							<span><?php esc_html_e( 'Відділення / поштомат', 'wc-nova-express' ); ?></span>
							<input type="text" class="nvx-extra-warehouse-search" value="" placeholder="<?php esc_attr_e( 'Почніть вводити назву або номер…', 'wc-nova-express' ); ?>" autocomplete="off" />
							<input type="hidden" name="extra_wh_ref[]" class="nvx-extra-warehouse-ref" value="" />
							<input type="hidden" name="extra_wh_warehouse_label[]" class="nvx-extra-warehouse-label" value="" />
							<div class="nvx-suggest nvx-extra-warehouse-suggest"></div>
						</label>
					</div>
					<button type="button" class="nvx-btn nvx-btn--ghost nvx-extra-wh-remove" style="margin-top:8px;"><?php esc_html_e( 'Видалити', 'wc-nova-express' ); ?></button>
				</div>
			</template>

		</section>

		<section class="nvx-card">
			<h2><?php esc_html_e( '4. Вартість доставки та параметри за замовчуванням', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint">
				<?php esc_html_e( 'Оберіть режим нарахування вартості доставки на чекауті та початкові значення для нових накладних.', 'wc-nova-express' ); ?>
			</p>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Режим вартості доставки на чекауті', 'wc-nova-express' ); ?></span>
				<select name="price_mode" id="nvx_price_mode">
					<option value="free_receiver" <?php selected( $settings['price_mode'] ?? 'free_receiver', 'free_receiver' ); ?>>
						<?php esc_html_e( 'За тарифами перевізника (оплата при отриманні — 0 грн у замовленні)', 'wc-nova-express' ); ?>
					</option>
					<option value="fixed" <?php selected( $settings['price_mode'] ?? '', 'fixed' ); ?>>
						<?php esc_html_e( 'Фіксована вартість доставки', 'wc-nova-express' ); ?>
					</option>
					<option value="api" <?php selected( $settings['price_mode'] ?? '', 'api' ); ?>>
						<?php esc_html_e( 'Розрахунок вартості онлайн через API Нової Пошти', 'wc-nova-express' ); ?>
					</option>
				</select>
				<small><?php esc_html_e( 'За замовчуванням рекомендовано "За тарифами перевізника": сума доставки не додається до чеку замовлення, а покупець сплачує доставку у відділенні за тарифами Нової Пошти.', 'wc-nova-express' ); ?></small>
			</label>

			<div id="nvx_fixed_price_wrap" style="<?php echo ( ($settings['price_mode'] ?? '') === 'fixed' ) ? '' : 'display:none;'; ?> margin-top:12px;">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Фіксована сума доставки (грн)', 'wc-nova-express' ); ?></span>
					<input type="number" step="0.01" min="0" name="fixed_price" value="<?php echo esc_attr( $settings['fixed_price'] ?? 0 ); ?>" />
				</label>
			</div>

			<div class="nvx-field-row" style="margin-top:16px;">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Платник доставки за замовчуванням', 'wc-nova-express' ); ?></span>
					<select name="default_payer">
						<option value="Recipient" <?php selected( $settings['default_payer'] ?? 'Recipient', 'Recipient' ); ?>><?php esc_html_e( 'Отримувач', 'wc-nova-express' ); ?></option>
						<option value="Sender" <?php selected( $settings['default_payer'] ?? '', 'Sender' ); ?>><?php esc_html_e( 'Відправник', 'wc-nova-express' ); ?></option>
						<option value="ThirdPerson" <?php selected( $settings['default_payer'] ?? '', 'ThirdPerson' ); ?>><?php esc_html_e( 'Третя особа', 'wc-nova-express' ); ?></option>
					</select>
					<small><?php esc_html_e( 'Підставляється при створенні ТТН. За замовчуванням: Отримувач.', 'wc-nova-express' ); ?></small>
				</label>

				<label class="nvx-field">
					<span><?php esc_html_e( 'Варіант доставки за замовчуванням', 'wc-nova-express' ); ?></span>
					<select name="default_service">
						<option value="warehouse_warehouse" <?php selected( $settings['default_service'] ?? '', 'warehouse_warehouse' ); ?>><?php esc_html_e( 'Склад — Склад (відділення)', 'wc-nova-express' ); ?></option>
						<option value="warehouse_doors" <?php selected( $settings['default_service'] ?? '', 'warehouse_doors' ); ?>><?php esc_html_e( 'Склад — Двері', 'wc-nova-express' ); ?></option>
						<option value="doors_warehouse" <?php selected( $settings['default_service'] ?? '', 'doors_warehouse' ); ?>><?php esc_html_e( 'Двері — Склад', 'wc-nova-express' ); ?></option>
						<option value="doors_doors" <?php selected( $settings['default_service'] ?? '', 'doors_doors' ); ?>><?php esc_html_e( "Двері — Двері (кур'єр)", 'wc-nova-express' ); ?></option>
					</select>
					<small><?php esc_html_e( 'Початковий тип сервісу при формуванні ТТН.', 'wc-nova-express' ); ?></small>
				</label>
			</div>
		</section>

								<section class="nvx-card">
			<h2><?php esc_html_e( '5. Опис та додаткова інформація відправлення', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint">
				<?php esc_html_e( 'Ці шаблони підставляються за замовчуванням у поля "Опис відправлення" та "Додаткова інформація" на сторінці створення ТТН — там їх ще можна відредагувати вручну перед відправкою. Стандартні плейсхолдери:', 'wc-nova-express' ); ?>
				<code>{order_number}</code>, <code>{order_id}</code>, <code>{order_status}</code>, <code>{customer_name}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{customer_email}</code>, <code>{order_total}</code>, <code>{payment_method}</code>, <code>{currency}</code>, <code>{order_date}</code>, <code>{city_name}</code>, <code>{warehouse}</code>, <code>{total}</code>, <code>{date}</code>, <code>{items_count}</code>, <code>{phone}</code>, <code>{email}</code>, <code>{site_name}</code>.
				<br />
				<?php esc_html_e( 'Плюс будь-яке довільне поле замовлення (кастомні поля чекауту тощо):', 'wc-nova-express' ); ?>
				<code>{meta:ключ}</code> <?php esc_html_e( 'або просто', 'wc-nova-express' ); ?> <code>{ключ}</code> — <?php esc_html_e( 'наприклад', 'wc-nova-express' ); ?> <code>{meta:vchasno_kasa_receipt_url}</code>, <code>{vchasno_kasa_receipt_url}</code>, <code>{meta:_billing_company}</code>.
			</p>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Шаблон опису відправлення', 'wc-nova-express' ); ?></span>
				<input type="text" name="description_template" value="<?php echo esc_attr( $settings['description_template'] ); ?>" placeholder="Замовлення №{order_number}" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Шаблон додаткової інформації', 'wc-nova-express' ); ?></span>
				<textarea name="additional_info_template" rows="2" placeholder="{site_name} — дякуємо за замовлення, {customer_name}!"><?php echo esc_textarea( $settings['additional_info_template'] ); ?></textarea>
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Показувати додаткову інформацію лише якщо містить', 'wc-nova-express' ); ?></span>
				<input type="text" name="additional_info_contains" value="<?php echo esc_attr( $settings['additional_info_contains'] ?? '' ); ?>" placeholder="https://kasa.vchasno.ua/check-viewer" />
				<span class="nvx-field__hint" style="display:block;margin-top:6px;font-size:12px;color:var(--nvx-text-soft,#64748b);">
					<?php esc_html_e( 'Після підстановки плейсхолдерів текст додаткової інформації перевіряється: якщо це поле заповнене і готовий текст НЕ містить вказаний фрагмент — на сторінці створення ТТН поле буде порожнім. Залиште порожнім, щоб завжди показувати результат шаблону.', 'wc-nova-express' ); ?>
				</span>
			</label>
		</section>

		<section class="nvx-card">
			<h2><?php esc_html_e( '6. Моніторинг статусів ТТН', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint"><?php esc_html_e( 'Плагін періодично опитує Nova Poshta API для всіх ТТН, статус яких ще не «Отримано».', 'wc-nova-express' ); ?></p>

			<label class="nvx-field nvx-field--inline">
				<span><?php esc_html_e( 'Інтервал опитування, хв', 'wc-nova-express' ); ?></span>
				<input type="number" min="5" step="5" name="polling_interval" value="<?php echo esc_attr( $settings['polling_interval'] ); ?>" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Webhook URL за замовчуванням', 'wc-nova-express' ); ?></span>
				<input type="url" name="webhook_url" value="<?php echo esc_attr( $settings['webhook_url'] ); ?>" placeholder="https://example.com/hooks/nova-poshta" />
				<small><?php esc_html_e( 'Використовується в діях автоматизації "Надіслати вебхук", якщо у самому правилі URL не вказано.', 'wc-nova-express' ); ?></small>
			</label>

		</section>

		<section class="nvx-card">
			<h2><?php esc_html_e( '7. Дані плагіна при видаленні', 'wc-nova-express' ); ?></h2>
			<p class="nvx-card__hint">
				<?php esc_html_e( 'Стосується лише повного видалення плагіна через "Плагіни → Видалити" в адмінці WordPress (не звичайної деактивації).', 'wc-nova-express' ); ?>
			</p>
			<label class="nvx-check-line">
				<input type="checkbox" name="wipe_data_on_uninstall" value="1" <?php checked( 'yes', $settings['wipe_data_on_uninstall'] ?? 'no' ); ?> />
				<span><?php esc_html_e( 'Видаляти всі дані плагіна (історію ТТН, автоматизації, журнал, налаштування, кеш відділень) при видаленні через адмінку WordPress', 'wc-nova-express' ); ?></span>
			</label>
			<p class="nvx-card__hint">
				<strong><?php esc_html_e( 'За замовчуванням вимкнено', 'wc-nova-express' ); ?></strong> —
				<?php esc_html_e( 'дані НЕ видаляються автоматично, щоб не втратити історію ТТН при випадковому видаленні плагіна. Увімкніть цю галочку, лише якщо вам дійсно потрібне повне очищення бази даних разом із видаленням плагіна.', 'wc-nova-express' ); ?>
			</p>
		</section>


		<div class="nvx-actions">
			<button type="submit" class="nvx-btn nvx-btn--primary"><?php esc_html_e( 'Зберегти налаштування', 'wc-nova-express' ); ?></button>
			<a class="nvx-btn nvx-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=nvx-express&tab=automation_ttn' ) ); ?>"><?php esc_html_e( 'Перейти до автоматизацій →', 'wc-nova-express' ); ?></a>
		</div>
	</form>
</div>

<script>
(function () {
	function ajaxUrl() {
		if (window.NVX_ADMIN && NVX_ADMIN.ajaxUrl) return NVX_ADMIN.ajaxUrl;
		return (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
	}
	function nonce() {
		return (window.NVX_ADMIN && NVX_ADMIN.nonce) ? NVX_ADMIN.nonce : '';
	}

	function buildBlock() {
		var div = document.createElement('div');
		div.className = 'nvx-extra-wh';
		div.innerHTML =
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
			'<button type="button" class="nvx-btn nvx-btn--ghost nvx-extra-wh-remove" style="margin-top:8px;">Видалити</button>';
		return div;
	}

	function loadWarehouses(wrap, cityRef) {
		var select = wrap.querySelector('.nvx-extra-warehouse');
		var labelInput = wrap.querySelector('.nvx-extra-warehouse-label');
		if (!select) return;
		select.innerHTML = '<option>Завантаження…</option>';
		var url = ajaxUrl() + '?action=nvx_local_search_warehouses&nonce=' + encodeURIComponent(nonce()) + '&city_ref=' + encodeURIComponent(cityRef);
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			select.innerHTML = '';
			if (res && res.success && res.data && res.data.length) {
				res.data.forEach(function (w) {
					var opt = document.createElement('option');
					opt.value = w.ref;
					opt.textContent = w.label;
					select.appendChild(opt);
				});
				if (labelInput) labelInput.value = select.options[0] ? select.options[0].text : '';
			} else {
				select.innerHTML = '<option value="">Відділень не знайдено</option>';
			}
		}).catch(function () {
			select.innerHTML = '<option value="">Помилка завантаження</option>';
		});
	}

	var cityTimers = {};
	document.addEventListener('input', function (e) {
		if (!e.target || !e.target.classList || !e.target.classList.contains('nvx-extra-city-search')) return;
		var wrap = e.target.closest('.nvx-extra-wh');
		if (!wrap) return;
		var q = e.target.value || '';
		var box = wrap.querySelector('.nvx-extra-city-suggest');
		if (q.length < 2) {
			if (box) box.innerHTML = '';
			return;
		}
		var key = wrap.getAttribute('data-index') || 'x';
		clearTimeout(cityTimers[key]);
		cityTimers[key] = setTimeout(function () {
			var url = ajaxUrl() + '?action=nvx_local_search_cities&nonce=' + encodeURIComponent(nonce()) + '&q=' + encodeURIComponent(q);
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
				if (!box) return;
				box.innerHTML = '';
				if (!res || !res.success || !res.data) return;
				res.data.forEach(function (item) {
					var el = document.createElement('div');
					el.className = 'nvx-suggest-item';
					el.textContent = item.label;
					el.addEventListener('click', function () {
						var ref = wrap.querySelector('.nvx-extra-city-ref');
						var name = wrap.querySelector('.nvx-extra-city-name');
						var search = wrap.querySelector('.nvx-extra-city-search');
						if (ref) ref.value = item.ref;
						if (name) name.value = item.label;
						if (search) search.value = item.label;
						box.innerHTML = '';
						var wr = wrap.querySelector('.nvx-extra-warehouse-ref');
						var wl = wrap.querySelector('.nvx-extra-warehouse-label');
						var ws = wrap.querySelector('.nvx-extra-warehouse-search');
						var wsg = wrap.querySelector('.nvx-extra-warehouse-suggest');
						if (wr) wr.value = '';
						if (wl) wl.value = '';
						if (ws) ws.value = '';
						if (wsg) wsg.innerHTML = '';
					});
					box.appendChild(el);
				});
			});
		}, 300);
	});

	document.addEventListener('change', function (e) {
		if (!e.target || !e.target.classList || !e.target.classList.contains('nvx-extra-warehouse')) return;
		var wrap = e.target.closest('.nvx-extra-wh');
		if (!wrap) return;
		var labelInput = wrap.querySelector('.nvx-extra-warehouse-label');
		if (labelInput && e.target.selectedOptions && e.target.selectedOptions[0]) {
			labelInput.value = e.target.selectedOptions[0].text;
		}
	});

	document.addEventListener('click', function (e) {
		var t = e.target;
		if (!t) return;
		var addBtn = t.id === 'nvx-extra-wh-add' ? t : (t.closest && t.closest('#nvx-extra-wh-add'));
		if (addBtn) {
			e.preventDefault();
			e.stopPropagation();
			var list = document.getElementById('nvx-extra-wh-list');
			if (list) list.appendChild(buildBlock());
			return;
		}
		var rm = t.classList && t.classList.contains('nvx-extra-wh-remove') ? t : (t.closest && t.closest('.nvx-extra-wh-remove'));
		if (rm) {
			e.preventDefault();
			var block = rm.closest('.nvx-extra-wh');
			if (block) block.remove();
		}
	}, true);

	// Підказки не обрізати overflow батька
	var style = document.createElement('style');
	style.textContent = '.nvx-extra-wh{overflow:visible!important;} .nvx-suggest{position:relative!important;top:auto!important;display:block;width:100%;} .nvx-suggest:not(:empty){margin-top:6px;} .nvx-suggest-item{padding:8px 10px;cursor:pointer;background:#fff;border-bottom:1px solid #eee;} .nvx-suggest-item:hover{background:#f0f7e8;}';
	document.head.appendChild(style);

	var extraWhSearchTimer = {};
	function nvxFetchExtraWh(wrap, q) {
		var cityRefEl = wrap.querySelector('.nvx-extra-city-ref');
		var cityRef = cityRefEl ? cityRefEl.value : '';
		var box = wrap.querySelector('.nvx-extra-warehouse-suggest');
		var refEl = wrap.querySelector('.nvx-extra-warehouse-ref');
		var labelEl = wrap.querySelector('.nvx-extra-warehouse-label');
		var searchEl = wrap.querySelector('.nvx-extra-warehouse-search');
		if (!box) return;
		if (!cityRef) {
			box.innerHTML = '<div class="nvx-suggest-item">Спочатку оберіть місто</div>';
			return;
		}
		box.innerHTML = '<div class="nvx-suggest-item">Завантаження…</div>';
		var url = ajaxUrl() + '?action=nvx_local_search_warehouses&nonce=' + encodeURIComponent(nonce()) + '&city_ref=' + encodeURIComponent(cityRef) + '&q=' + encodeURIComponent(q || '');
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			box.innerHTML = '';
			if (!res || !res.success || !res.data || !res.data.length) {
				box.innerHTML = '<div class="nvx-suggest-item">Нічого не знайдено</div>';
				return;
			}
			res.data.forEach(function (w) {
				var el = document.createElement('div');
				el.className = 'nvx-suggest-item';
				el.textContent = w.label;
				el.addEventListener('mousedown', function (ev) {
					ev.preventDefault();
					if (refEl) refEl.value = w.ref;
					if (labelEl) labelEl.value = w.label;
					if (searchEl) searchEl.value = w.label;
					box.innerHTML = '';
				});
				box.appendChild(el);
			});
		}).catch(function () {
			box.innerHTML = '<div class="nvx-suggest-item">Помилка завантаження</div>';
		});
	}
	document.addEventListener('focusin', function (e) {
		if (!e.target || !e.target.classList || !e.target.classList.contains('nvx-extra-warehouse-search')) return;
		var wrap = e.target.closest('.nvx-extra-wh');
		if (wrap) nvxFetchExtraWh(wrap, '');
	});
	document.addEventListener('input', function (e) {
		if (!e.target || !e.target.classList || !e.target.classList.contains('nvx-extra-warehouse-search')) return;
		var wrap = e.target.closest('.nvx-extra-wh');
		if (!wrap) return;
		var q = e.target.value || '';
		var refEl = wrap.querySelector('.nvx-extra-warehouse-ref');
		var labelEl = wrap.querySelector('.nvx-extra-warehouse-label');
		if (refEl) refEl.value = '';
		if (labelEl) labelEl.value = '';
		var key = wrap.getAttribute('data-index') || 'w';
		clearTimeout(extraWhSearchTimer[key]);
		extraWhSearchTimer[key] = setTimeout(function () {
			nvxFetchExtraWh(wrap, q);
		}, 200);
	});

})();
</script>

