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

		// Форматування адреси безпосередньо у стандартному блоці WooCommerce
		// (без додаткових рамок, кольорів чи окремих блоків)
		add_filter( 'woocommerce_order_formatted_billing_address', array( $this, 'format_order_address' ), 20, 2 );
		add_filter( 'woocommerce_order_formatted_shipping_address', array( $this, 'format_order_address' ), 20, 2 );
	}

	/**
	 * Підставляє відділення або кур'єрську адресу Нової Пошти у стандартний рядок адреси WooCommerce.
	 *
	 * @param array<string,string> $address
	 * @param \WC_Order            $order
	 * @return array<string,string>
	 */
	public function format_order_address( array $address, \WC_Order $order ): array {
		$city_name       = (string) $order->get_meta( '_nvx_city_name' );
		$warehouse_label = (string) $order->get_meta( '_nvx_warehouse_label' );
		$service_type    = (string) $order->get_meta( '_nvx_service_type' );
		$street_name     = (string) $order->get_meta( '_nvx_street_name' );
		$building        = (string) $order->get_meta( '_nvx_building_number' );
		$apartment       = (string) $order->get_meta( '_nvx_apartment' );

		if ( '' === $city_name && '' === $warehouse_label && '' === $street_name ) {
			return $address;
		}

		$is_doors = ( TtnManager::SERVICE_DOORS_DOORS === $service_type || TtnManager::SERVICE_WAREHOUSE_DOORS === $service_type );

		if ( $is_doors && '' !== $street_name ) {
			$address['address_1'] = trim( $street_name . ' ' . $building );
			if ( '' !== $apartment ) {
				$address['address_2'] = __( 'кв./офіс ', 'wc-nova-express' ) . $apartment;
			} else {
				unset( $address['address_2'] );
			}
		} elseif ( '' !== $warehouse_label ) {
			$address['address_1'] = $warehouse_label;
			unset( $address['address_2'] );
		}

		if ( '' !== $city_name ) {
			$address['city'] = $city_name;
		}

		// Для замовлень Нової Пошти виключаємо поштовий індекс сторонніх служб
		unset( $address['postcode'] );

		return $address;
	}

	public function add_meta_box(): void {
		$screen = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'nvx_order_panel',
			'<img src="' . esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ) . '" alt="" width="20" height="20" style="width:20px;height:20px;vertical-align:middle;margin-right:8px;border-radius:4px;" />'
				. esc_html__( 'Nova Express Woo: доставка', 'wc-nova-express' ),
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
