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
		$api_key = Settings::get_api_key();
		if ( empty( $api_key ) ) {
			wp_die( esc_html__( 'API-ключ Нової Пошти не налаштовано в плагіні.', 'wc-nova-express' ) );
		}

		// Відповідно до документації Нової Пошти: параметр orders[] приймає Ref або номер ЕН
		$ref = ! empty( $row['document_ref'] ) ? trim( (string) $row['document_ref'] ) : '';
		if ( empty( $ref ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref ) ) {
			$ref = trim( (string) $row['waybill_number'] );
		}

		if ( empty( $ref ) ) {
			wp_die( esc_html__( 'Ідентифікатор або номер ТТН відсутній.', 'wc-nova-express' ) );
		}

		$url   = '';
		$title = 'Друк документа Нової Пошти';
		switch ( $format ) {
			case 'np_100x100':
				// Маркування 100х100 (термопринтер Zebra PDF) згідно з документацією
				$url = sprintf(
					'https://my.novaposhta.ua/orders/printMarking100x100/orders[]/%s/type/pdf/apiKey/%s/zebra',
					rawurlencode( $ref ),
					rawurlencode( $api_key )
				);
				$title = 'Маркування Нової Пошти 100×100';
				break;

			case 'np_85x85':
				// Маркування 85х85 PDF (тип pdf8) згідно з документацією
				$url = sprintf(
					'https://my.novaposhta.ua/orders/printMarking85x85/orders[]/%s/type/pdf8/apiKey/%s',
					rawurlencode( $ref ),
					rawurlencode( $api_key )
				);
				$title = 'Маркування Нової Пошти 85×85';
				break;

			case 'np_document':
				// Експрес-накладна А4 PDF згідно з документацією
				$url = sprintf(
					'https://my.novaposhta.ua/orders/printDocument/orders[]/%s/type/pdf/apiKey/%s',
					rawurlencode( $ref ),
					rawurlencode( $api_key )
				);
				$title = 'Експрес-накладна Нової Пошти';
				break;
		}

		if ( empty( $url ) ) {
			wp_die( esc_html__( 'Невідомий формат друку.', 'wc-nova-express' ) );
		}

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
	<meta http-equiv="refresh" content="0;url=<?php echo esc_url( $url ); ?>" />
	<title><?php echo esc_html( $title . ' — №' . $row['waybill_number'] ); ?></title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;
			background: #f4f6f3;
			color: #2e382d;
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 100vh;
			margin: 0;
			padding: 20px;
			box-sizing: border-box;
		}
		.nvx-redirect-card {
			background: #ffffff;
			border: 1px solid #dde5d6;
			border-radius: 8px;
			padding: 32px 28px;
			max-width: 480px;
			width: 100%;
			text-align: center;
			box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
		}
		.nvx-redirect-spinner {
			width: 36px;
			height: 36px;
			border: 3px solid #e2ebd8;
			border-top-color: #7CB342;
			border-radius: 50%;
			animation: nvx-spin 0.8s linear infinite;
			margin: 0 auto 18px;
		}
		@keyframes nvx-spin {
			to { transform: rotate(360deg); }
		}
		h2 {
			font-size: 18px;
			margin: 0 0 10px;
			color: #1a2318;
		}
		p {
			font-size: 14px;
			line-height: 1.5;
			color: #555;
			margin: 0 0 20px;
		}
		.nvx-btn-redirect {
			display: inline-block;
			background: #7CB342;
			color: #ffffff !important;
			text-decoration: none;
			padding: 10px 22px;
			font-size: 14px;
			font-weight: 600;
			border-radius: 6px;
			transition: background 0.15s ease;
		}
		.nvx-btn-redirect:hover {
			background: #689f38;
		}
	</style>
	<script>
		window.location.replace(<?php echo wp_json_encode( $url ); ?>);
	</script>
</head>
<body>
	<div class="nvx-redirect-card">
		<div class="nvx-redirect-spinner"></div>
		<h2><?php echo esc_html( $title ); ?></h2>
		<p><?php echo esc_html( sprintf( __( 'Відкриваємо документ №%s у системі Нової Пошти...', 'wc-nova-express' ), $row['waybill_number'] ) ); ?><br />
		<small><?php esc_html_e( 'Якщо завантаження не розпочалося автоматично, натисніть кнопку нижче:', 'wc-nova-express' ); ?></small></p>
		<a class="nvx-btn-redirect" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Відкрити документ PDF', 'wc-nova-express' ); ?></a>
	</div>
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
			background: #7CB342;
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
