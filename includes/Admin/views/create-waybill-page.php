<?php
/**
 * @var \WC_Order $order
 * @var array     $settings
 */
defined( 'ABSPATH' ) || exit;

$default_last  = $order->get_shipping_last_name() ?: $order->get_billing_last_name();
$default_first = $order->get_shipping_first_name() ?: $order->get_billing_first_name();
$default_city_ref  = $order->get_meta( '_nvx_city_ref' );
$default_city_name = $order->get_meta( '_nvx_city_name' );
$default_warehouse_ref   = $order->get_meta( '_nvx_warehouse_ref' );
$default_warehouse_label = $order->get_meta( '_nvx_warehouse_label' );
$default_service = $order->get_meta( '_nvx_service_type' ) ?: 'warehouse_warehouse';
$default_description     = \NovaExpress\Helpers\Formatting::apply_order_template(
	$settings['description_template'] ?: __( 'Замовлення №{order_number}', 'wc-nova-express' ),
	$order
);
$default_additional_info = \NovaExpress\Helpers\Formatting::resolve_additional_info(
	$settings['additional_info_template'] ?? '',
	$order,
	(string) ( $settings['additional_info_contains'] ?? '' )
);
$order_weight = 0;
foreach ( $order->get_items() as $item ) {
	$product = $item->get_product();
	if ( $product && $product->has_weight() ) {
		$order_weight += (float) $product->get_weight() * $item->get_quantity();
	}
}
$order_weight = $order_weight > 0 ? max( 0.1, round( $order_weight, 2 ) ) : 0.1;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<title><?php esc_html_e( 'Створення ТТН', 'wc-nova-express' ); ?> — #<?php echo esc_html( $order->get_order_number() ); ?></title>
	<?php wp_print_styles( array( 'dashicons' ) ); ?>
	<link rel="stylesheet" href="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/css/admin.css' ); ?>?v=<?php echo esc_attr( NVX_VERSION ); ?>" />
	<link rel="stylesheet" href="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/css/create-waybill.css' ); ?>?v=<?php echo esc_attr( NVX_VERSION ); ?>" />
</head>
<body class="nvx-standalone">

<div class="nvx-cw-app"
	data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-print-base="<?php echo esc_url( admin_url( 'admin.php?page=nvx-print-label' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'nvx_admin_nonce' ) ); ?>"
	data-order-weight="<?php echo esc_attr( $order_weight ); ?>"
	data-order-total="<?php echo esc_attr( $order->get_total() ); ?>"
	data-default-city-ref="<?php echo esc_attr( $default_city_ref ); ?>"
	data-default-city-name="<?php echo esc_attr( $default_city_name ); ?>"
	data-default-warehouse-ref="<?php echo esc_attr( $default_warehouse_ref ); ?>"
	data-default-warehouse-label="<?php echo esc_attr( $default_warehouse_label ); ?>">

	<header class="nvx-cw-header">
		<div class="nvx-cw-header__logo">NE</div>
		<div class="nvx-cw-header__title">
			<h1><?php esc_html_e( 'Створення ТТН', 'wc-nova-express' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %1$s: order number, %2$s: customer name */
					esc_html__( 'Замовлення №%1$s · %2$s', 'wc-nova-express' ),
					esc_html( $order->get_order_number() ),
					esc_html( trim( $default_first . ' ' . $default_last ) )
				);
				?>
			</p>
		</div>
		<button type="button" class="nvx-btn nvx-btn--ghost" onclick="window.close()"><?php esc_html_e( 'Закрити вкладку', 'wc-nova-express' ); ?></button>
	</header>

	<div id="nvx-cw-alert" class="nvx-alert" style="display:none;"></div>

	<div class="nvx-cw-columns">

		<!-- Колонка 1: Параметри відправлення -->
		<section class="nvx-cw-col">
			<h2><?php esc_html_e( 'Параметри відправлення', 'wc-nova-express' ); ?></h2>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Платник доставки', 'wc-nova-express' ); ?></span>
				<select id="nvx-cw-payer-type">
					<option value="Recipient"><?php esc_html_e( 'Отримувач', 'wc-nova-express' ); ?></option>
					<option value="Sender"><?php esc_html_e( 'Відправник', 'wc-nova-express' ); ?></option>
					<option value="ThirdPerson"><?php esc_html_e( 'Третя особа', 'wc-nova-express' ); ?></option>
				</select>
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Форма оплати', 'wc-nova-express' ); ?></span>
				<select id="nvx-cw-payment-method">
					<option value="Cash"><?php esc_html_e( 'Готівка', 'wc-nova-express' ); ?></option>
					<option value="NonCash"><?php esc_html_e( 'Безготівковий розрахунок', 'wc-nova-express' ); ?></option>
				</select>
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Тип відправлення', 'wc-nova-express' ); ?></span>
				<select id="nvx-cw-cargo-type">
					<option value="Parcel"><?php esc_html_e( 'Посилка (до 30 кг)', 'wc-nova-express' ); ?></option>
					<option value="Cargo"><?php esc_html_e( 'Вантаж (понад 30 кг)', 'wc-nova-express' ); ?></option>
					<option value="Documents"><?php esc_html_e( 'Документи', 'wc-nova-express' ); ?></option>
					<option value="TiresWheels"><?php esc_html_e( 'Шини та диски', 'wc-nova-express' ); ?></option>
					<option value="Pallet"><?php esc_html_e( 'Палети', 'wc-nova-express' ); ?></option>
				</select>
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Дата відправлення', 'wc-nova-express' ); ?></span>
				<input type="date" id="nvx-cw-date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
			</label>

			<div class="nvx-cw-places" id="nvx-cw-places">
				<!-- місця рендеряться JS -->
			</div>
			<button type="button" class="nvx-btn nvx-btn--ghost nvx-btn--block" id="nvx-cw-add-place">
				<?php esc_html_e( '+ Додати місце', 'wc-nova-express' ); ?>
			</button>

			<div class="nvx-cw-price-estimate" id="nvx-cw-price-estimate">
				<div class="nvx-cw-price-estimate__row">
					<div>
						<span class="nvx-cw-price-estimate__label"><?php esc_html_e( 'Орієнтовна вартість доставки', 'wc-nova-express' ); ?></span>
						<strong class="nvx-cw-price-estimate__value" id="nvx-cw-price-value">—</strong>
					</div>
					<button type="button" class="nvx-btn nvx-btn--ghost" id="nvx-cw-calc-price">
						<?php esc_html_e( 'Розрахувати', 'wc-nova-express' ); ?>
					</button>
				</div>
				<span class="nvx-cw-price-estimate__hint" id="nvx-cw-price-hint"><?php esc_html_e( 'Натисніть «Розрахувати» після вибору міста, ваги та габаритів', 'wc-nova-express' ); ?></span>
			</div>

			<label class="nvx-field" style="margin-top:16px;">
				<span><?php esc_html_e( 'Оголошена вартість', 'wc-nova-express' ); ?></span>
				<input type="number" step="0.01" min="0" id="nvx-cw-declared-cost" placeholder="<?php echo esc_attr( $order->get_total() ); ?>" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Опис відправлення', 'wc-nova-express' ); ?></span>
				<input type="text" id="nvx-cw-description" value="<?php echo esc_attr( $default_description ); ?>" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Внутрішній номер відправлення', 'wc-nova-express' ); ?></span>
				<input type="text" id="nvx-cw-internal-number" value="<?php echo esc_attr( $order->get_order_number() ); ?>" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Додаткова інформація', 'wc-nova-express' ); ?></span>
				<textarea id="nvx-cw-additional-info" rows="2"><?php echo esc_textarea( $default_additional_info ); ?></textarea>
				<small><a href="<?php echo esc_url( admin_url( 'admin.php?page=nvx-express&tab=settings' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Налаштувати шаблони за замовчуванням →', 'wc-nova-express' ); ?></a></small>
			</label>
		</section>

		<!-- Колонка 2: Дані відправника -->
		<section class="nvx-cw-col">
			<h2><?php esc_html_e( 'Дані відправника', 'wc-nova-express' ); ?></h2>

			<div class="nvx-cw-carrier-badge">
				<span class="dot"></span>
				<?php esc_html_e( 'Нова Пошта', 'wc-nova-express' ); ?>
			</div>

			<?php $sender_profiles = \NovaExpress\Admin\Settings::get_sender_profiles(); ?>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Відправлення з відділення', 'wc-nova-express' ); ?></span>
				<select id="nvx-cw-sender-id">
					<?php foreach ( $sender_profiles as $sp ) :
						$wh_label = $sp['warehouse_label'] ?: ( $sp['label'] ?? '' );
						$city     = $sp['city_name'] ?? '';
						// У списку: назва · адреса відділення · місто
						$option_text = trim( ( $sp['label'] ?? '' ) !== $wh_label
							? ( ( $sp['label'] ?? '' ) . ' — ' . $wh_label . ( $city ? ' — ' . $city : '' ) )
							: ( $wh_label . ( $city ? ' — ' . $city : '' ) ) );
						?>
						<option value="<?php echo esc_attr( $sp['id'] ); ?>"
							data-label="<?php echo esc_attr( $wh_label ); ?>"
							data-city="<?php echo esc_attr( $city ); ?>"
							data-contact="<?php echo esc_attr( $settings['sender_contact_name'] ?? '' ); ?>"
							data-phone="<?php echo esc_attr( $settings['sender_phone'] ?? '' ); ?>">
							<?php echo esc_html( $option_text ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<div class="nvx-cw-sender-card">
				<span class="nvx-cw-sender-card__label"><?php esc_html_e( 'Адреса відправлення', 'wc-nova-express' ); ?></span>
				<?php if ( ! empty( $settings['sender_city_name'] ) ) : ?>
					<strong><?php echo esc_html( $settings['sender_city_name'] ); ?></strong>
					<span class="nvx-cw-sender-card__ref">
						<?php echo esc_html( $settings['sender_warehouse_label'] ?: __( '⚠ Відділення не обрано в налаштуваннях', 'wc-nova-express' ) ); ?>
					</span>
					<?php if ( ! empty( $settings['sender_contact_name'] ) ) : ?>
						<span class="nvx-cw-sender-card__contact">
							<?php echo esc_html( trim( $settings['sender_contact_name'] ) ); ?>
							<?php if ( ! empty( $settings['sender_phone'] ) ) : ?>
								· <?php echo esc_html( $settings['sender_phone'] ); ?>
							<?php endif; ?>
						</span>
					<?php else : ?>
						<span class="nvx-cw-sender-card__empty">⚠ <?php esc_html_e( 'Контрагент-відправник не налаштований', 'wc-nova-express' ); ?></span>
					<?php endif; ?>
				<?php else : ?>
					<span class="nvx-cw-sender-card__empty">⚠ <?php esc_html_e( 'Адресу відправника ще не налаштовано', 'wc-nova-express' ); ?></span>
				<?php endif; ?>
			</div>

			<a class="nvx-btn nvx-btn--ghost nvx-btn--block" href="<?php echo esc_url( admin_url( 'admin.php?page=nvx-express&tab=settings' ) ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'Керувати адресами', 'wc-nova-express' ); ?>
			</a>

			<h2 style="margin-top:28px;"><?php esc_html_e( 'Тип доставки', 'wc-nova-express' ); ?></h2>
			<label class="nvx-field">
				<span><?php esc_html_e( 'Варіант сервісу', 'wc-nova-express' ); ?></span>
				<select id="nvx-cw-service-type">
					<option value="warehouse_warehouse" <?php selected( $default_service, 'warehouse_warehouse' ); ?>><?php esc_html_e( 'Склад — Склад', 'wc-nova-express' ); ?></option>
					<option value="warehouse_doors" <?php selected( $default_service, 'warehouse_doors' ); ?>><?php esc_html_e( 'Склад — Двері', 'wc-nova-express' ); ?></option>
					<option value="doors_warehouse" <?php selected( $default_service, 'doors_warehouse' ); ?>><?php esc_html_e( 'Двері — Склад', 'wc-nova-express' ); ?></option>
					<option value="doors_doors" <?php selected( $default_service, 'doors_doors' ); ?>><?php esc_html_e( 'Двері — Двері', 'wc-nova-express' ); ?></option>
				</select>
			</label>
		</section>

		<!-- Колонка 3: Дані отримувача -->
		<section class="nvx-cw-col">
			<h2><?php esc_html_e( 'Дані отримувача', 'wc-nova-express' ); ?></h2>

			<div class="nvx-radio-inline">
				<label><input type="radio" name="nvx-cw-recipient-type" value="private" checked /> <?php esc_html_e( 'Фізична особа', 'wc-nova-express' ); ?></label>
				<label><input type="radio" name="nvx-cw-recipient-type" value="org" disabled /> <?php esc_html_e( 'Організація (незабаром)', 'wc-nova-express' ); ?></label>
			</div>

			<div class="nvx-field-row">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Прізвище', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-cw-last-name" value="<?php echo esc_attr( $default_last ); ?>" />
				</label>
				<label class="nvx-field">
					<span><?php esc_html_e( "Ім'я", 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-cw-first-name" value="<?php echo esc_attr( $default_first ); ?>" />
				</label>
			</div>

			<label class="nvx-field">
				<span><?php esc_html_e( 'По батькові', 'wc-nova-express' ); ?></span>
				<input type="text" id="nvx-cw-middle-name" />
			</label>

			<label class="nvx-field">
				<span>Email</span>
				<input type="email" id="nvx-cw-email" value="<?php echo esc_attr( $order->get_billing_email() ); ?>" />
			</label>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Телефон', 'wc-nova-express' ); ?></span>
				<input type="text" id="nvx-cw-phone" value="<?php echo esc_attr( $order->get_billing_phone() ); ?>" />
			</label>

			<div class="nvx-radio-inline">
				<label><input type="radio" name="nvx-cw-delivery-type" value="warehouse" checked /> <?php esc_html_e( 'Відділення', 'wc-nova-express' ); ?></label>
				<label><input type="radio" name="nvx-cw-delivery-type" value="address" /> <?php esc_html_e( 'Адреса', 'wc-nova-express' ); ?></label>
			</div>

			<label class="nvx-field">
				<span><?php esc_html_e( 'Місто', 'wc-nova-express' ); ?></span>
				<input type="text" id="nvx-cw-city-search" autocomplete="off" value="<?php echo esc_attr( $default_city_name ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву…', 'wc-nova-express' ); ?>" />
				<input type="hidden" id="nvx-cw-city-ref" value="<?php echo esc_attr( $default_city_ref ); ?>" />
				<div class="nvx-suggest" id="nvx-cw-city-suggest"></div>
			</label>

			<div id="nvx-cw-warehouse-block">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Відділення / поштомат', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-cw-warehouse-search" value="<?php echo esc_attr( $default_warehouse_label ); ?>" placeholder="<?php esc_attr_e( 'Почніть вводити назву або номер відділення…', 'wc-nova-express' ); ?>" autocomplete="off" />
					<input type="hidden" id="nvx-cw-warehouse-ref" value="<?php echo esc_attr( $default_warehouse_ref ); ?>" />
					<input type="hidden" id="nvx-cw-warehouse-label" value="<?php echo esc_attr( $default_warehouse_label ); ?>" />
					<div class="nvx-suggest" id="nvx-cw-warehouse-suggest"></div>
				</label>
			</div>

			<div id="nvx-cw-address-block" style="display:none;">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Вулиця', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-cw-street-search" autocomplete="off" />
					<input type="hidden" id="nvx-cw-street-ref" />
					<div class="nvx-suggest" id="nvx-cw-street-suggest"></div>
				</label>
				<div class="nvx-field-row">
					<label class="nvx-field">
						<span><?php esc_html_e( 'Будинок', 'wc-nova-express' ); ?></span>
						<input type="text" id="nvx-cw-building" />
					</label>
					<label class="nvx-field">
						<span><?php esc_html_e( 'Квартира/офіс', 'wc-nova-express' ); ?></span>
						<input type="text" id="nvx-cw-apartment" />
					</label>
				</div>
			</div>
		</section>
	</div>

	<footer class="nvx-cw-footer">
		<div class="nvx-cw-footer__info">
			<span class="nvx-cw-footer__order">
				<?php
				printf(
					/* translators: %1$s: order number, %2$s: order total, %3$s: currency */
					esc_html__( 'Замовлення №%1$s · %2$s %3$s', 'wc-nova-express' ),
					esc_html( $order->get_order_number() ),
					esc_html( $order->get_total() ),
					esc_html( $order->get_currency() )
				);
				?>
			</span>
			<span class="nvx-inline-status" id="nvx-cw-status"></span>
		</div>
		<button type="button" class="nvx-btn nvx-btn--primary nvx-btn--large" id="nvx-cw-submit">
			<span class="dashicons dashicons-yes-alt"></span>
			<?php esc_html_e( 'Створити ТТН', 'wc-nova-express' ); ?>
		</button>
	</footer>
</div>

<script src="<?php echo esc_url( includes_url( 'js/jquery/jquery.min.js' ) ); ?>"></script>
<script src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/js/admin-create-waybill.js' ); ?>?v=<?php echo esc_attr( NVX_VERSION ); ?>"></script>
</body>
</html>
