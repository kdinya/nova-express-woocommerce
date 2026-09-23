<?php

namespace NovaExpress\Admin;

use NovaExpress\Helpers\Barcode;
use NovaExpress\Helpers\Formatting;
use NovaExpress\Ttn\TtnRepository;

defined( 'ABSPATH' ) || exit;

class LabelPrint {

	private TtnRepository $repository;

	public function __construct( TtnRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_render' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	public function register_page(): void {
		add_submenu_page(
			null,
			__( 'Друк етикетки Nova Express Woo', 'wc-nova-express' ),
			__( 'Друк етикетки', 'wc-nova-express' ),
			'manage_woocommerce',
			'nvx-print-label',
			'__return_null'
		);
	}

	public function maybe_render(): void {
		if ( ! is_admin() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'nvx-print-label' !== $page ) {
			return;
		}
		$this->render();
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ttn_id   = isset( $_GET['ttn_id'] ) ? (int) $_GET['ttn_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$format   = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'custom';

		$row = $ttn_id ? $this->repository->find_by_id( $ttn_id ) : null;
		if ( ! $row && $order_id ) {
			$row = $this->repository->find_active_for_order( $order_id );
		}
		if ( ! $row ) {
			wp_die( esc_html__( 'ТТН не знайдено.', 'wc-nova-express' ) );
		}

		// Якщо обрано стандартний формат Нової Пошти — стрімимо офіційний PDF через серверний проксі
		if ( in_array( $format, array( 'np_100x100', 'np_85x85', 'np_document' ), true ) ) {
			$this->stream_np_pdf( $row, $format );
			return;
		}

		$order = ! empty( $row['order_id'] ) ? wc_get_order( (int) $row['order_id'] ) : ( $order_id ? wc_get_order( $order_id ) : null );

		$this->output_html_label( $row, $order );
	}

	/**
	 * Перенаправлення на офіційний PDF-документ Нової Пошти згідно з офіційною документацією.
	 *
	 * За документацією (https://api-portal.novapost.com/methods/ua/api-docs-ua/ua/drukovani-formi),
	 * друк здійснюється безпосередньо з браузера за посиланням з API-ключем.
	 * Використовуємо надійне клієнтське перенаправлення (meta-refresh + JS + пряма кнопка відкриття),
	 * оскільки прямий серверний cURL-запит блокується Cloudflare з боку my.novaposhta.ua (повертає SPA HTML).
	 */
		private function stream_np_pdf( array $row, string $format ): void {
		$order = ! empty( $row['order_id'] ) ? wc_get_order( (int) $row['order_id'] ) : null;
		
		if ( 'np_document' === $format ) {
			$this->output_document_a4( $row, $order );
			return;
		}

		$width = 'np_85x85' === $format ? 85 : 100;
		$height = 'np_85x85' === $format ? 85 : 100;
		$this->output_standard_marking( $row, $order, $width, $height );
	}

	/**
	 * Рендеринг стандартного маркування Нової Пошти (100х100 або 85х85 мм) без залежності від сторонніх кукі/Cloudflare
	 */
	private function output_standard_marking( array $row, ?\WC_Order $order, int $width, int $height ): void {
		$number = (string) $row['waybill_number'];
		$last   = $order ? ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) : '';
		$first  = $order ? ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) : '';
		$recipient_name = trim( $last . ' ' . $first );

		$phone = '';
		if ( $order ) {
			$raw_phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
			$phone     = $raw_phone ? Formatting::normalize_phone( $raw_phone ) : '';
		}

		$city = $order ? (string) $order->get_meta( '_nvx_city_name' ) : '';
		$wh   = $order ? (string) ( $order->get_meta( '_nvx_warehouse_label' ) ?: $order->get_meta( '_nvx_warehouse_name' ) ) : '';
		if ( empty( $wh ) && $order ) {
			$wh = (string) $order->get_shipping_address_1();
		}

		$barcode_svg = '' !== $number ? Barcode::code128_svg( $number, 54, 2 ) : '';
		$order_num = $order ? $order->get_order_number() : '';
		$total_display = $order ? wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) : '';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( 'Маркування №' . $number ); ?></title>
	<style>
		@page {
			size: <?php echo (int) $width; ?>mm <?php echo (int) $height; ?>mm;
			margin: 0;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		html, body {
			width: <?php echo (int) $width; ?>mm;
			height: <?php echo (int) $height; ?>mm;
			background: #fff;
			color: #000;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
		.nvx-np-label {
			width: 100%;
			height: 100%;
			padding: 4mm;
			display: flex;
			flex-direction: column;
			justify-content: space-between;
			border: 1px dashed #bbb;
		}
		@media print {
			.nvx-np-label { border: none; }
			.nvx-print-bar { display: none !important; }
		}
		.nvx-print-bar {
			position: fixed;
			bottom: 15px;
			right: 15px;
			background: #1c2333;
			color: #fff;
			padding: 8px 16px;
			border-radius: 20px;
			font-size: 13px;
			box-shadow: 0 4px 12px rgba(0,0,0,0.25);
			display: flex;
			gap: 10px;
			align-items: center;
			z-index: 9999;
		}
		.nvx-print-btn {
			background: #e11c1b;
			color: #fff;
			border: none;
			padding: 6px 14px;
			border-radius: 12px;
			cursor: pointer;
			font-weight: 600;
		}
		.nvx-np-header {
			display: flex;
			align-items: center;
			justify-content: space-between;
			border-bottom: 2px solid #000;
			padding-bottom: 2mm;
			margin-bottom: 2mm;
		}
		.nvx-np-brand {
			font-size: 13pt;
			font-weight: 900;
			color: #e11c1b;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.nvx-np-seats {
			font-size: 11pt;
			font-weight: 700;
			border: 1.5px solid #000;
			padding: 1px 6px;
			border-radius: 3px;
		}
		.nvx-np-barcode-wrap {
			text-align: center;
			margin: 2mm 0;
		}
		.nvx-np-barcode-svg svg {
			max-width: 100%;
			height: 48px;
		}
		.nvx-np-ttn {
			font-size: 16pt;
			font-weight: 800;
			letter-spacing: 1px;
			margin-top: 1mm;
		}
		.nvx-np-section {
			border-top: 1px solid #000;
			padding-top: 1.5mm;
			margin-top: 1.5mm;
			font-size: 9.5pt;
			line-height: 1.25;
		}
		.nvx-np-section-title {
			font-size: 7.5pt;
			text-transform: uppercase;
			color: #555;
			font-weight: 700;
			margin-bottom: 1px;
		}
		.nvx-np-recipient {
			font-size: 11pt;
			font-weight: 800;
		}
		.nvx-np-phone {
			font-weight: 700;
		}
		.nvx-np-address {
			font-size: 9pt;
			font-weight: 600;
			margin-top: 1px;
		}
		.nvx-np-meta-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 4px;
			font-size: 8.5pt;
			border-top: 1px solid #000;
			padding-top: 1.5mm;
			margin-top: 1.5mm;
		}
	</style>
</head>
<body>
	<div class="nvx-print-bar">
		<span>Маркування <?php echo (int) $width; ?>×<?php echo (int) $height; ?> мм</span>
		<button type="button" class="nvx-print-btn" onclick="window.print();">Друк</button>
	</div>
	<div class="nvx-np-label">
		<div>
			<div class="nvx-np-header">
				<div class="nvx-np-brand">НОВА ПОШТА</div>
				<div class="nvx-np-seats">Місце 1/1</div>
			</div>
			<div class="nvx-np-barcode-wrap">
				<div class="nvx-np-barcode-svg"><?php echo $barcode_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div class="nvx-np-ttn"><?php echo esc_html( Formatting::format_waybill_number( $number ) ); ?></div>
			</div>
			<div class="nvx-np-section">
				<div class="nvx-np-section-title">Одержувач:</div>
				<div class="nvx-np-recipient"><?php echo esc_html( $recipient_name ?: 'Клієнт' ); ?></div>
				<?php if ( $phone ) : ?>
					<div class="nvx-np-phone"><?php echo esc_html( $phone ); ?></div>
				<?php endif; ?>
				<div class="nvx-np-address">
					<?php echo esc_html( $city ? $city . ', ' . $wh : $wh ); ?>
				</div>
			</div>
		</div>

		<div class="nvx-np-meta-grid">
			<div>
				<?php if ( $order_num ) : ?>
					<div>Замовлення: <strong>#<?php echo esc_html( $order_num ); ?></strong></div>
				<?php endif; ?>
				<div>Тип: <strong>Посилка</strong></div>
			</div>
			<div style="text-align:right;">
				<?php if ( $total_display ) : ?>
					<div>Оголошена: <strong><?php echo wp_strip_all_tags( $total_display ); ?></strong></div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<script>
		window.addEventListener('load', function() {
			window.print();
		});
	</script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Рендеринг експрес-накладної Нової Пошти формату А4
	 */
	private function output_document_a4( array $row, ?\WC_Order $order ): void {
		$number = (string) $row['waybill_number'];
		$last   = $order ? ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) : '';
		$first  = $order ? ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) : '';
		$recipient_name = trim( $last . ' ' . $first );

		$phone = '';
		if ( $order ) {
			$raw_phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
			$phone     = $raw_phone ? Formatting::normalize_phone( $raw_phone ) : '';
		}

		$city = $order ? (string) $order->get_meta( '_nvx_city_name' ) : '';
		$wh   = $order ? (string) ( $order->get_meta( '_nvx_warehouse_label' ) ?: $order->get_meta( '_nvx_warehouse_name' ) ) : '';
		if ( empty( $wh ) && $order ) {
			$wh = (string) $order->get_shipping_address_1();
		}

		$barcode_svg = '' !== $number ? Barcode::code128_svg( $number, 58, 2 ) : '';
		$order_num = $order ? $order->get_order_number() : '';
		$total_display = $order ? wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) : '';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( 'Експрес-накладна А4 №' . $number ); ?></title>
	<style>
		@page { size: A4 portrait; margin: 10mm; }
		* { box-sizing: border-box; margin: 0; padding: 0; }
		html, body {
			background: #fff;
			color: #1c2333;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
		.nvx-a4-wrap {
			max-width: 190mm;
			margin: 0 auto;
			padding: 5mm;
			border: 2px solid #222;
			border-radius: 4px;
		}
		@media print {
			.nvx-print-bar { display: none !important; }
			.nvx-a4-wrap { border: 2px solid #222; }
		}
		.nvx-print-bar {
			position: fixed;
			bottom: 20px;
			right: 20px;
			background: #1c2333;
			color: #fff;
			padding: 10px 20px;
			border-radius: 30px;
			font-size: 14px;
			box-shadow: 0 4px 16px rgba(0,0,0,0.3);
			display: flex;
			gap: 12px;
			align-items: center;
			z-index: 9999;
		}
		.nvx-print-btn {
			background: #e11c1b;
			color: #fff;
			border: none;
			padding: 8px 18px;
			border-radius: 14px;
			cursor: pointer;
			font-weight: 700;
		}
		.nvx-a4-head {
			display: flex;
			justify-content: space-between;
			align-items: center;
			border-bottom: 2px solid #222;
			padding-bottom: 4mm;
			margin-bottom: 4mm;
		}
		.nvx-a4-logo {
			font-size: 22pt;
			font-weight: 900;
			color: #e11c1b;
			letter-spacing: 0.5px;
		}
		.nvx-a4-title {
			font-size: 14pt;
			font-weight: 800;
			text-transform: uppercase;
		}
		.nvx-a4-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 6mm;
			margin-bottom: 5mm;
		}
		.nvx-a4-box {
			border: 1px solid #777;
			padding: 4mm;
			border-radius: 4px;
		}
		.nvx-a4-box h4 {
			font-size: 10.5pt;
			text-transform: uppercase;
			border-bottom: 1px solid #ccc;
			padding-bottom: 2mm;
			margin-bottom: 2mm;
			color: #333;
		}
		.nvx-a4-table {
			width: 100%;
			border-collapse: collapse;
			margin-top: 4mm;
		}
		.nvx-a4-table th, .nvx-a4-table td {
			border: 1px solid #888;
			padding: 6px 10px;
			font-size: 10pt;
			text-align: left;
		}
		.nvx-a4-table th { background: #f1f3f5; font-weight: 700; }
	</style>
</head>
<body>
	<div class="nvx-print-bar">
		<span>Експрес-накладна А4</span>
		<button type="button" class="nvx-print-btn" onclick="window.print();">Роздрукувати</button>
	</div>
	<div class="nvx-a4-wrap">
		<div class="nvx-a4-head">
			<div>
				<div class="nvx-a4-logo">НОВА ПОШТА</div>
				<div class="nvx-a4-title">Експрес-накладна</div>
			</div>
			<div style="text-align:right;">
				<div style="margin-bottom:4px;"><?php echo $barcode_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<div style="font-size:14pt; font-weight:800;"><?php echo esc_html( Formatting::format_waybill_number( $number ) ); ?></div>
			</div>
		</div>

		<div class="nvx-a4-grid">
			<div class="nvx-a4-box">
				<h4>Відправник</h4>
				<p style="font-weight:700; font-size:11pt;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
				<p style="font-size:9.5pt; margin-top:2px;">Інтернет-магазин</p>
			</div>
			<div class="nvx-a4-box">
				<h4>Одержувач</h4>
				<p style="font-weight:700; font-size:11pt;"><?php echo esc_html( $recipient_name ?: 'Клієнт' ); ?></p>
				<?php if ( $phone ) : ?>
					<p style="font-weight:700; font-size:10pt; margin-top:2px;"><?php echo esc_html( $phone ); ?></p>
				<?php endif; ?>
				<p style="font-size:9.5pt; margin-top:2px;"><?php echo esc_html( $city ? $city . ', ' . $wh : $wh ); ?></p>
			</div>
		</div>

		<table class="nvx-a4-table">
			<thead>
				<tr>
					<th>Параметр</th>
					<th>Значення</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>Номер замовлення</td>
					<td><strong>#<?php echo esc_html( $order_num ); ?></strong></td>
				</tr>
				<tr>
					<td>Кількість місць</td>
					<td>1</td>
				</tr>
				<tr>
					<td>Оголошена вартість</td>
					<td><strong><?php echo wp_strip_all_tags( $total_display ); ?></strong></td>
				</tr>
				<tr>
					<td>Опис відправлення</td>
					<td>Товари інтернет-магазину</td>
				</tr>
			</tbody>
		</table>
	</div>
	<script>
		window.addEventListener('load', function() {
			window.print();
		});
	</script>
</body>
</html>
		<?php
		exit;
	}

	private function output_html_label( array $row, ?\WC_Order $order ): void {
		$tpl = Settings::get_label_template();

		$number = (string) $row['waybill_number'];
		$last   = $order ? ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) : '';
		$first  = $order ? ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) : '';
		$name   = trim( $last . ' ' . $first );

		$phone = '';
		if ( $order ) {
			$raw_phone = $order->get_shipping_phone() ?: $order->get_billing_phone();
			$phone     = $raw_phone ? Formatting::normalize_phone( $raw_phone ) : '';
		}

		// Адреса / відділення одержувача
		$address = '';
		if ( $order ) {
			$city = (string) $order->get_meta( '_nvx_city_name' );
			$wh   = (string) ( $order->get_meta( '_nvx_warehouse_label' ) ?: $order->get_meta( '_nvx_warehouse_name' ) );
			if ( empty( $wh ) ) {
				$wh = (string) $order->get_shipping_address_1();
			}
			$address = trim( $city . ( $city && $wh ? ', ' : '' ) . $wh );
		}

		// Номер замовлення
		$order_num = $order ? $order->get_order_number() : '';

		// Товари
		$items = array();
		if ( $order && ! empty( $tpl['show_order_items'] ) ) {
			foreach ( $order->get_items() as $item ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$items[] = array(
						'name' => $item->get_name(),
						'qty'  => $item->get_quantity(),
					);
				}
			}
		}

		// Оголошена вартість / сума
		$total_display = '';
		if ( $order && ! empty( $tpl['show_order_total'] ) ) {
			$total_display = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );
		}

		// Генерація штрих-коду
		$barcode_height = max( 20, (int) ( $tpl['barcode_height'] ?? 50 ) );
		$barcode_svg = '';
		if ( ! empty( $tpl['show_barcode'] ) && '' !== $number ) {
			$barcode_svg = Barcode::code128_svg( $number, $barcode_height, 2 );
		}

		$width        = max( 40, (int) $tpl['width'] );
		$height       = max( 30, (int) $tpl['height'] );
		$margin_top   = max( 0, (int) $tpl['margin_top'] );
		$margin_sides = max( 0, (int) $tpl['margin_sides'] );
		$align        = 'left' === $tpl['align'] ? 'left' : 'center';

		$font_size_map = array(
			'small'  => array( 'ttn' => '18pt', 'name' => '12pt', 'text' => '10pt' ),
			'medium' => array( 'ttn' => '22pt', 'name' => '14pt', 'text' => '11pt' ),
			'large'  => array( 'ttn' => '26pt', 'name' => '16pt', 'text' => '13pt' ),
		);
		$sizes = $font_size_map[ $tpl['font_size'] ] ?? $font_size_map['medium'];
		$ttn_size = ! empty( $tpl['ttn_font_size'] ) ? ( (int) $tpl['ttn_font_size'] . 'pt' ) : $sizes['ttn'];
		$item_spacing = max( 0, (int) ( $tpl['item_spacing'] ?? 2 ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="uk">
<head>
	<meta charset="utf-8" />
	<title><?php echo esc_html( $number ); ?></title>
	<style>
		@page {
			size: <?php echo (int) $width; ?>mm <?php echo (int) $height; ?>mm;
			margin: 0;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		html, body {
			width: <?php echo (int) $width; ?>mm;
			height: <?php echo (int) $height; ?>mm;
			margin: 0;
			padding: 0;
			background: #fff;
			color: #000;
			font-family: Arial, Helvetica, "DejaVu Sans", sans-serif;
		}
		.sheet {
			width: <?php echo (int) $width; ?>mm;
			height: <?php echo (int) $height; ?>mm;
			padding: <?php echo (int) $margin_top; ?>mm <?php echo (int) $margin_sides; ?>mm 0 <?php echo (int) $margin_sides; ?>mm;
			text-align: <?php echo esc_attr( $align ); ?>;
		}
		.ttn-wrap {
			margin-bottom: <?php echo (int) $item_spacing; ?>mm;
		}
		.ttn {
			font-size: <?php echo esc_attr( $ttn_size ); ?>;
			font-weight: 700;
			letter-spacing: 0.04em;
			line-height: 1.15;
			word-break: break-all;
		}
		.barcode {
			margin: <?php echo (int) $item_spacing; ?>mm 0;
			max-width: 100%;
		}
		.barcode svg {
			display: block;
			margin: 0 <?php echo 'left' === $align ? '0' : 'auto'; ?>;
			max-width: 100%;
			height: <?php echo (int) $barcode_height; ?>px;
		}
		.name {
			margin-top: <?php echo (int) $item_spacing; ?>mm;
			font-size: <?php echo esc_attr( $sizes['name'] ); ?>;
			font-weight: 600;
			line-height: 1.25;
		}
		.info-row {
			margin-top: <?php echo (int) $item_spacing; ?>mm;
			font-size: <?php echo esc_attr( $sizes['text'] ); ?>;
			line-height: 1.3;
			color: #222;
		}
		.order-badge {
			display: inline-block;
			margin-top: <?php echo (int) $item_spacing; ?>mm;
			padding: 1.5px 5px;
			border: 1px solid #333;
			border-radius: 3px;
			font-weight: 700;
			font-size: <?php echo esc_attr( $sizes['text'] ); ?>;
		}
		.items-table {
			width: 100%;
			margin-top: <?php echo (int) $item_spacing; ?>mm;
			border-collapse: collapse;
			font-size: 9.5pt;
			text-align: left;
		}
		.items-table th, .items-table td {
			padding: 1.5mm 1mm;
			border-bottom: 1px solid #ddd;
		}
		.note {
			margin-top: <?php echo (int) $item_spacing; ?>mm;
			font-size: 9.5pt;
			font-style: italic;
			color: #444;
		}
		.no-print {
			position: fixed;
			left: 0; right: 0; bottom: 10px;
			text-align: center;
		}
		.no-print button {
			padding: 8px 16px;
			font-size: 14px;
			cursor: pointer;
			background: var(--nvx-primary, #7CB342);
			color: #fff;
			border: none;
			border-radius: 4px;
			font-weight: bold;
		}
		@media print {
			html, body, .sheet {
				width: <?php echo (int) $width; ?>mm !important;
				height: <?php echo (int) $height; ?>mm !important;
			}
			.no-print { display: none !important; }
		}
	</style>
</head>
<body>
	<div class="sheet">
		<?php if ( ! empty( $tpl['show_ttn'] ) && '' !== $number ) : ?>
			<div class="ttn-wrap">
				<div class="ttn"><?php echo esc_html( $number ); ?></div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['show_barcode'] ) && ! empty( $barcode_svg ) ) : ?>
			<div class="barcode">
				<?php echo $barcode_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['show_recipient_name'] ) && '' !== $name ) : ?>
			<div class="name"><?php echo esc_html( $name ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['show_recipient_phone'] ) && '' !== $phone ) : ?>
			<div class="info-row"><strong>Тел:</strong> <?php echo esc_html( $phone ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['show_recipient_address'] ) && '' !== $address ) : ?>
			<div class="info-row"><?php echo esc_html( $address ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['show_order_number'] ) && '' !== $order_num ) : ?>
			<div>
				<span class="order-badge">Замовлення №<?php echo esc_html( $order_num ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $total_display ) ) : ?>
			<div class="info-row" style="margin-top:2mm;font-weight:bold;">
				Сума: <?php echo wp_kses_post( $total_display ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $items ) ) : ?>
			<table class="items-table">
				<thead>
					<tr>
						<th>Товар</th>
						<th style="width:30px;text-align:right;">К-сть</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $it ) : ?>
						<tr>
							<td><?php echo esc_html( $it['name'] ); ?></td>
							<td style="text-align:right;"><?php echo (int) $it['qty']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $tpl['custom_note'] ) ) : ?>
			<div class="note"><?php echo esc_html( $tpl['custom_note'] ); ?></div>
		<?php endif; ?>
	</div>

	<div class="no-print">
		<button type="button" onclick="window.print()"><?php echo esc_html__( 'Друкувати', 'wc-nova-express' ); ?></button>
		<p style="margin-top:8px;font-size:12px;color:#666;">
			<?php echo esc_html( sprintf( __( 'Розмір паперу у налаштуваннях друку: %d×%d мм. Поля = 0.', 'wc-nova-express' ), (int) $width, (int) $height ) ); ?>
		</p>
	</div>
	<script>
		window.addEventListener('load', function () {
			setTimeout(function () { window.print(); }, 300);
		});
	</script>
</body>
</html>
		<?php
		exit;
	}

	public static function url( int $ttn_id, int $order_id, string $format = 'custom' ): string {
		return add_query_arg(
			array(
				'page'     => 'nvx-print-label',
				'ttn_id'   => $ttn_id,
				'order_id' => $order_id,
				'format'   => $format,
			),
			admin_url( 'admin.php' )
		);
	}
}
