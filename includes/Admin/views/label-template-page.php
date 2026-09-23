<?php
/**
 * Вкладка «Шаблон етикетки»: конструктор користувацької етикетки ТТН з Live Preview.
 *
 * @var array<string,mixed> $settings Поточні налаштування плагіна.
 */

defined( 'ABSPATH' ) || exit;

$tmpl = \NovaExpress\Admin\Settings::get_label_template();
?>

<div class="nvx-wrap">
	<div class="nvx-header">
		<div class="nvx-header__logo"><img src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ); ?>" alt="Nova Express Woo" /></div>
		<div>
			<h1><?php esc_html_e( 'Nova Express Woo', 'wc-nova-express' ); ?></h1>
			<p><?php esc_html_e( 'Конструктор користувацької етикетки ТТН з попереднім переглядом', 'wc-nova-express' ); ?></p>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
		<div class="nvx-alert nvx-alert--success" style="margin-bottom:20px;">
			<?php esc_html_e( 'Налаштування шаблону етикетки успішно збережено.', 'wc-nova-express' ); ?>
		</div>
	<?php endif; ?>

	<div class="nvx-label-builder-grid">
		<!-- Ліва колонка: Форма налаштувань -->
		<div class="nvx-label-builder-controls">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="nvx-label-template-form">
				<input type="hidden" name="action" value="nvx_save_label_template">
				<?php wp_nonce_field( 'nvx_save_label_template' ); ?>

				<section class="nvx-card">
					<header class="nvx-card__header">
						<h3><?php esc_html_e( '1. Розміри та геометрія етикетки', 'wc-nova-express' ); ?></h3>
					</header>
					<div class="nvx-card__body">
						<p class="description" style="margin-bottom: 12px;">
							<?php esc_html_e( 'Вкажіть точні розміри в міліметрах (наприклад, 100×150 мм, 100×100 мм або 85×85 мм).', 'wc-nova-express' ); ?>
						</p>
						<div class="nvx-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div class="nvx-field">
								<label for="nvx_lbl_width"><strong><?php esc_html_e( 'Ширина (мм):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_width" name="label_width" min="40" max="300"
									value="<?php echo esc_attr( $tmpl['width'] ); ?>" class="small-text" style="width:100%;">
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_height"><strong><?php esc_html_e( 'Висота (мм):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_height" name="label_height" min="30" max="400"
									value="<?php echo esc_attr( $tmpl['height'] ); ?>" class="small-text" style="width:100%;">
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_m_top"><strong><?php esc_html_e( 'Відступ зверху (мм):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_m_top" name="label_margin_top" min="0" max="50"
									value="<?php echo esc_attr( $tmpl['margin_top'] ); ?>" class="small-text" style="width:100%;">
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_m_sides"><strong><?php esc_html_e( 'Відступ з боків (мм):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_m_sides" name="label_margin_sides" min="0" max="50"
									value="<?php echo esc_attr( $tmpl['margin_sides'] ); ?>" class="small-text" style="width:100%;">
							</div>
						</div>
					</div>
				</section>

				<section class="nvx-card">
					<header class="nvx-card__header">
						<h3><?php esc_html_e( '2. Стиль, шрифт та відстані між елементами', 'wc-nova-express' ); ?></h3>
					</header>
					<div class="nvx-card__body">
						<div class="nvx-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
							<div class="nvx-field">
								<label for="nvx_lbl_align"><strong><?php esc_html_e( 'Вирівнювання вмісту:', 'wc-nova-express' ); ?></strong></label>
								<select id="nvx_lbl_align" name="label_align" style="width:100%;">
									<option value="center" <?php selected( 'center', $tmpl['align'] ); ?>><?php esc_html_e( 'По центру', 'wc-nova-express' ); ?></option>
									<option value="left" <?php selected( 'left', $tmpl['align'] ); ?>><?php esc_html_e( 'По лівому краю', 'wc-nova-express' ); ?></option>
								</select>
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_font_size"><strong><?php esc_html_e( 'Базовий шрифт тексту:', 'wc-nova-express' ); ?></strong></label>
								<select id="nvx_lbl_font_size" name="label_font_size" style="width:100%;">
									<option value="small" <?php selected( 'small', $tmpl['font_size'] ); ?>><?php esc_html_e( 'Дрібний', 'wc-nova-express' ); ?></option>
									<option value="medium" <?php selected( 'medium', $tmpl['font_size'] ); ?>><?php esc_html_e( 'Середній (стандартний)', 'wc-nova-express' ); ?></option>
									<option value="large" <?php selected( 'large', $tmpl['font_size'] ); ?>><?php esc_html_e( 'Великий', 'wc-nova-express' ); ?></option>
								</select>
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_ttn_font_size"><strong><?php esc_html_e( 'Розмір номера ТТН (pt):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_ttn_font_size" name="label_ttn_font_size" min="12" max="48"
									value="<?php echo esc_attr( $tmpl['ttn_font_size'] ?? 22 ); ?>" class="small-text" style="width:100%;">
							</div>
							<div class="nvx-field">
								<label for="nvx_lbl_barcode_height"><strong><?php esc_html_e( 'Висота штрих-коду (px):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_barcode_height" name="label_barcode_height" min="20" max="120"
									value="<?php echo esc_attr( $tmpl['barcode_height'] ?? 50 ); ?>" class="small-text" style="width:100%;">
							</div>
							<div class="nvx-field" style="grid-column: span 2;">
								<label for="nvx_lbl_item_spacing"><strong><?php esc_html_e( 'Відстань між усіма об\'єктами (мм):', 'wc-nova-express' ); ?></strong></label>
								<input type="number" id="nvx_lbl_item_spacing" name="label_item_spacing" min="0" max="20"
									value="<?php echo esc_attr( $tmpl['item_spacing'] ?? 2 ); ?>" class="small-text" style="width:100%;">
								<p class="description" style="margin-top:4px;">
									<?php esc_html_e( 'Єдиний вертикальний проміжок між номером ТТН, штрих-кодом, блоками одержувача та замовлення.', 'wc-nova-express' ); ?>
								</p>
							</div>
						</div>
					</div>
				</section>

				<section class="nvx-card">
					<header class="nvx-card__header">
						<h3><?php esc_html_e( '3. Елементи для відображення', 'wc-nova-express' ); ?></h3>
					</header>
					<div class="nvx-card__body">
						<p class="description" style="margin-bottom: 12px;">
							<?php esc_html_e( 'Виберіть поля, які мають друкуватись на етикетці:', 'wc-nova-express' ); ?>
						</p>
						<div class="nvx-checkbox-grid" style="display:flex;flex-direction:column;gap:8px;">
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_ttn" id="nvx_chk_ttn" value="1" <?php checked( true, $tmpl['show_ttn'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Номер ТТН (великим шрифтом)', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_barcode" id="nvx_chk_barcode" value="1" <?php checked( true, $tmpl['show_barcode'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Штрих-код ТТН (векторний Code 128 SVG)', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_recipient_name" id="nvx_chk_name" value="1" <?php checked( true, $tmpl['show_recipient_name'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'ПІБ одержувача', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_recipient_phone" id="nvx_chk_phone" value="1" <?php checked( true, $tmpl['show_recipient_phone'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Номер телефону одержувача', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_recipient_address" id="nvx_chk_address" value="1" <?php checked( true, $tmpl['show_recipient_address'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Адреса доставки / номер відділення чи поштомату', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_order_number" id="nvx_chk_order" value="1" <?php checked( true, $tmpl['show_order_number'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Номер замовлення в магазині', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_order_items" id="nvx_chk_items" value="1" <?php checked( true, $tmpl['show_order_items'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Склад замовлення (список товарів і кількість)', 'wc-nova-express' ); ?></span>
								</div>
							</label>
							<label class="nvx-field nvx-field--checkbox">
								<input type="checkbox" name="label_show_order_total" id="nvx_chk_total" value="1" <?php checked( true, $tmpl['show_order_total'] ); ?>>
								<div class="nvx-checkbox-text">
									<span class="nvx-checkbox-title"><?php esc_html_e( 'Оголошена вартість / сума до сплати', 'wc-nova-express' ); ?></span>
								</div>
							</label>
						</div>
					</div>
				</section>

				<section class="nvx-card">
					<header class="nvx-card__header">
						<h3><?php esc_html_e( '4. Додаткова примітка на етикетці', 'wc-nova-express' ); ?></h3>
					</header>
					<div class="nvx-card__body">
						<p class="description" style="margin-bottom: 8px;">
							<?php esc_html_e( 'Текст унизу етикетки (наприклад: «Дякуємо за покупку!» або контактні дані магазину):', 'wc-nova-express' ); ?>
						</p>
						<input type="text" id="nvx_lbl_note" name="label_custom_note"
							value="<?php echo esc_attr( $tmpl['custom_note'] ); ?>"
							placeholder="<?php esc_attr_e( 'Наприклад: Дякуємо за замовлення!', 'wc-nova-express' ); ?>"
							style="width:100%;">
					</div>
				</section>

				<div class="nvx-form-actions" style="margin-top:20px;">
					<button type="submit" class="nvx-btn nvx-btn--primary nvx-btn--lg">
						<?php esc_html_e( 'Зберегти шаблон', 'wc-nova-express' ); ?>
					</button>
				</div>
			</form>
		</div>

		<!-- Права колонка: Живий перегляд (Live Preview) -->
		<div class="nvx-label-builder-preview">
			<div class="nvx-card nvx-sticky-card">
				<header class="nvx-card__header" style="display:flex;align-items:center;justify-content:space-between;">
					<h3><?php esc_html_e( 'Живий перегляд', 'wc-nova-express' ); ?></h3>
					<span class="nvx-badge" id="nvx-preview-dims" style="font-weight:600;font-size:12px;">
						<?php echo esc_html( $tmpl['width'] . ' × ' . $tmpl['height'] . ' мм' ); ?>
					</span>
				</header>
				<div class="nvx-card__body" style="background:#f4f6f8;padding:20px;display:flex;justify-content:center;align-items:flex-start;min-height:360px;">
					<div id="nvx-label-preview-box" class="nvx-label-preview-box"
						style="background:#fff;border:1px dashed #999;box-shadow:0 4px 12px rgba(0,0,0,0.08);padding:14px;box-sizing:border-box;width:240px;color:#000;font-family:sans-serif;line-height:1.3;">
						
						<div id="prev-ttn" style="font-size:18px;font-weight:bold;margin-bottom:4px;word-break:break-all;">20451098765432</div>
						
						<div id="prev-barcode" style="margin:4px 0 6px 0;">
							<div id="prev-barcode-bar" style="height:42px;background:repeating-linear-gradient(90deg,#000,#000 2px,#fff 2px,#fff 4px,#000 4px,#000 7px,#fff 7px,#fff 9px);margin:0 auto;width:90%;"></div>
						</div>
						
						<div id="prev-name" style="font-size:14px;font-weight:600;margin-top:4px;">Коваленко Олександр</div>
						<div id="prev-phone" style="font-size:11px;margin-top:2px;">Тел: +380 97 123 45 67</div>
						<div id="prev-address" style="font-size:11px;margin-top:2px;color:#222;">м. Київ, Відділення №42 (до 30 кг)</div>
						
						<div id="prev-order" style="margin-top:6px;">
							<span style="display:inline-block;padding:1px 5px;border:1px solid #333;border-radius:3px;font-size:10px;font-weight:bold;">Замовлення #1042</span>
						</div>
						
						<div id="prev-total" style="font-size:11px;font-weight:bold;margin-top:4px;">Сума: 1 450,00 ₴</div>
						
						<div id="prev-items" style="margin-top:6px;border-top:1px solid #eee;padding-top:4px;font-size:10px;color:#555;">
							• Футболка оверсайз (L) — 1 шт.<br>• Шкарпетки класичні — 2 шт.
						</div>
						
						<div id="prev-note" style="margin-top:8px;font-size:10px;font-style:italic;color:#555;">
							Дякуємо за покупку!
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
