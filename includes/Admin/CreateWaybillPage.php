<?php

namespace NovaExpress\Admin;

use NovaExpress\Ttn\TtnManager;
use NovaExpress\Warehouse\WarehouseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Окрема сторінка-конструктор ТТН (відкривається в новій вкладці кнопкою
 * "Створити ТТН" з картки замовлення). Навмисно НЕ додається у меню
 * WooCommerce — доступна лише за прямим посиланням admin.php?page=nvx-create-waybill&order_id=…
 */
class CreateWaybillPage {

	private TtnManager $manager;
	private WarehouseRepository $warehouse_repository;

	public function __construct( TtnManager $manager, WarehouseRepository $warehouse_repository ) {
		$this->manager               = $manager;
		$this->warehouse_repository  = $warehouse_repository;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_hidden_page' ) );
		add_action( 'admin_head', array( $this, 'hide_menu_item' ) );
	}

	public function add_hidden_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Nova Express: створення ТТН', 'wc-nova-express' ),
			__( 'Створення ТТН', 'wc-nova-express' ),
			'manage_woocommerce',
			'nvx-create-waybill',
			array( $this, 'render' )
		);
	}

	/**
	 * Пункт меню лишається зареєстрованим (це важливо: WordPress визначає,
	 * чи дозволено користувачу відкрити сторінку, саме за записом у $submenu
	 * для батьківського пункту — remove_submenu_page() ламає цю перевірку
	 * і показує "вам не дозволено переглядати цю сторінку"). Тому просто
	 * ховаємо пункт візуально через CSS, не видаляючи реєстрацію.
	 */
	public function hide_menu_item(): void {
		echo '<style>#toplevel_page_woocommerce a[href*="page=nvx-create-waybill"]{display:none !important;}</style>';
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Замовлення не знайдено.', 'wc-nova-express' ) );
		}

		$settings = Settings::get_all();

		include NVX_PLUGIN_DIR . 'includes/Admin/views/create-waybill-page.php';
	}
}
