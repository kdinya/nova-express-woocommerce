<?php

namespace NovaExpress\Admin;

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
		$ttn_id = isset( $_GET['ttn_id'] ) ? (int) $_GET['ttn_id'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;

		$row = $ttn_id ? $this->repository->find_by_id( $ttn_id ) : null;
		if ( ! $row && $order_id ) {
			$row = $this->repository->find_active_for_order( $order_id );
		}
		if ( ! $row ) {
			wp_die( esc_html__( 'ТТН не знайдено.', 'wc-nova-express' ) );
		}

		$order = wc_get_order( (int) $row['order_id'] );

		$number = (string) $row['waybill_number'];
		$last   = $order ? ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) : '';
		$first  = $order ? ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) : '';
		$name   = trim( $last . ' ' . $first );

		// Єдиний формат друку — HTML-етикетка 100×150 мм (нижче). Офіційний
		// PDF-друк Нової Пошти (100×100) прибрано з плагіна за рішенням адміна.
		$this->output_html_label( $number, $name );
	}

	/**
	 * HTML-етикетка рівно 100×150 мм, дані у верхній частині, автодрук.
	 */
	private function output_html_label( string $number, string $name ): void {
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
			size: 100mm 150mm;
			margin: 0;
		}
		* { box-sizing: border-box; margin: 0; padding: 0; }
		html, body {
			width: 100mm;
			height: 150mm;
			margin: 0;
			padding: 0;
			background: #fff;
			color: #000;
			font-family: Arial, Helvetica, "DejaVu Sans", sans-serif;
		}
		/* Аркуш рівно 100×150; контент притиснутий до ВЕРХУ */
		.sheet {
			width: 100mm;
			height: 150mm;
			padding: 8mm 6mm 0 6mm;
			text-align: center;
		}
		.ttn {
			font-size: 22pt;
			font-weight: 700;
			letter-spacing: 0.04em;
			line-height: 1.15;
			word-break: break-all;
		}
		.name {
			margin-top: 1.5mm;
			font-size: 14pt;
			font-weight: 400;
			line-height: 1.25;
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
		}
		@media print {
			html, body, .sheet {
				width: 100mm !important;
				height: 150mm !important;
			}
			.no-print { display: none !important; }
		}
	</style>
</head>
<body>
	<div class="sheet">
		<div class="ttn"><?php echo esc_html( $number ); ?></div>
		<div class="name"><?php echo esc_html( $name ); ?></div>
	</div>
	<div class="no-print">
		<button type="button" onclick="window.print()"><?php echo esc_html__( 'Друкувати', 'wc-nova-express' ); ?></button>
		<p style="margin-top:8px;font-size:12px;color:#666;">
			<?php echo esc_html__( 'У параметрах друку оберіть розмір паперу 100×150 мм (або «Властивості» → користувацький). Поля = 0.', 'wc-nova-express' ); ?>
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
