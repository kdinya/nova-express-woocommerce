<?php

namespace NovaExpress\Admin;

use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Ttn\TtnManager;

defined( 'ABSPATH' ) || exit;

/**
 * Метабокс на картці замовлення: дані доставки НП + ручне створення ТТН
 * + історія статусів. Сумісний з HPOS (реєструється і на woocommerce_page_wc-orders,
 * і на класичному екрані shop_order).
 */
class OrderMetaBox {

	private TtnManager $manager;
	private NovaPoshtaClient $client;

	public function __construct( TtnManager $manager, NovaPoshtaClient $client ) {
		$this->manager = $manager;
		$this->client  = $client;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_order_address' ) );
	}

	public function render_admin_order_address( \WC_Order $order ): void {
		$city_name       = $order->get_meta( '_nvx_city_name' );
		$warehouse_label = $order->get_meta( '_nvx_warehouse_label' );
		$service_type    = $order->get_meta( '_nvx_service_type' );
		$building        = $order->get_meta( '_nvx_building_number' );
		$apartment       = $order->get_meta( '_nvx_apartment' );
		$street_name     = $order->get_meta( '_nvx_street_name' );

		if ( empty( $city_name ) && empty( $warehouse_label ) ) {
			return;
		}

		$is_doors = ( TtnManager::SERVICE_DOORS_DOORS === $service_type || TtnManager::SERVICE_WAREHOUSE_DOORS === $service_type );
		?>
		<div class="nvx-admin-billing-delivery" style="margin-top:10px; padding:8px 10px; background:#f8fafc; border-left:3px solid #da291c; border-radius:4px; font-size:13px; line-height:1.4;">
			<div style="font-weight:600; color:#da291c; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
				<span>📦 <?php echo $is_doors ? esc_html__( 'Нова Пошта: Адресна доставка', 'wc-nova-express' ) : esc_html__( 'Нова Пошта: Відділення / Поштомат', 'wc-nova-express' ); ?></span>
			</div>
			<?php if ( ! empty( $city_name ) ) : ?>
				<div style="color:#1e293b;">
					<strong><?php esc_html_e( 'Місто:', 'wc-nova-express' ); ?></strong> <?php echo esc_html( $city_name ); ?>
				</div>
			<?php endif; ?>
			<?php if ( $is_doors ) : ?>
				<div style="color:#1e293b; margin-top:2px;">
					<strong><?php esc_html_e( 'Адреса:', 'wc-nova-express' ); ?></strong>
					<?php echo esc_html( trim( $street_name . ' ' . $building . ( $apartment ? ' кв./оф. ' . $apartment : '' ) ) ); ?>
				</div>
			<?php elseif ( ! empty( $warehouse_label ) ) : ?>
				<div style="color:#1e293b; margin-top:2px;">
					<strong><?php esc_html_e( 'Відділення:', 'wc-nova-express' ); ?></strong> <?php echo esc_html( $warehouse_label ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function add_meta_box(): void {
		$screen = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'nvx_order_panel',
			__( 'Nova Express: доставка', 'wc-nova-express' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	public function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$waybills     = $this->manager->repository()->find_by_order( $order->get_id() );
		$service_type = $order->get_meta( '_nvx_service_type' ) ?: TtnManager::SERVICE_WAREHOUSE_WAREHOUSE;
		$city_name    = $order->get_meta( '_nvx_city_name' );
		$warehouse    = $order->get_meta( '_nvx_warehouse_label' );

		include NVX_PLUGIN_DIR . 'includes/Admin/views/order-panel.php';
	}
}
