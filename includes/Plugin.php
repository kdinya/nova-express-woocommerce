<?php

namespace NovaExpress;

use NovaExpress\Admin\Assets as AdminAssets;
use NovaExpress\Admin\AutomationPage;
use NovaExpress\Admin\OrderListColumn;
use NovaExpress\Admin\OrderMetaBox;
use NovaExpress\Admin\AdminPage;
use NovaExpress\Admin\Settings;
use NovaExpress\Ajax\AddressAjax;
use NovaExpress\Ajax\AutomationAjax;
use NovaExpress\Ajax\OrderAjax;
use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Automation\RuleEngine;
use NovaExpress\Automation\RuleRepository;
use NovaExpress\Frontend\AddressFields;
use NovaExpress\Frontend\CheckoutAssets;
use NovaExpress\Shipping\NovaExpressShippingMethod;
use NovaExpress\Tracking\TrackingScheduler;
use NovaExpress\Ttn\TtnManager;
use NovaExpress\Ttn\TtnRepository;
use NovaExpress\Admin\CreateWaybillPage;
use NovaExpress\Admin\LabelPrint;
use NovaExpress\Ajax\SenderAjax;
use NovaExpress\Ajax\WarehouseAjax;
use NovaExpress\Warehouse\WarehouseRepository;
use NovaExpress\Warehouse\WarehouseSync;
use NovaExpress\Updater\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

/**
 * Головний контейнер плагіна. Тримає єдині екземпляри сервісів
 * та підключає всі модулі до відповідних WordPress/WooCommerce хуків.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private NovaPoshtaClient $api_client;
	private TtnRepository $ttn_repository;
	private RuleRepository $rule_repository;
	private TtnManager $ttn_manager;
	private RuleEngine $rule_engine;
	private WarehouseRepository $warehouse_repository;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		$this->api_client      = new NovaPoshtaClient( Settings::get_api_key() );
		$this->ttn_repository  = new TtnRepository();
		$this->rule_repository = new RuleRepository();
		$this->ttn_manager          = new TtnManager( $this->api_client, $this->ttn_repository );
		$this->rule_engine          = new RuleEngine( $this->rule_repository );
		$this->warehouse_repository = new WarehouseRepository();
		$warehouse_sync             = new WarehouseSync( $this->api_client, $this->warehouse_repository );

		// Shipping method.
		add_filter( 'woocommerce_shipping_methods', array( $this, 'register_shipping_method' ) );

		// GitHub self-updater.
		( new GitHubUpdater( NVX_PLUGIN_FILE ) )->register();

		// Admin.
		( new Settings() )->register(); // save hook
		( new AdminPage( $this->rule_repository ) )->register();
		( new OrderMetaBox( $this->ttn_manager, $this->api_client ) )->register();
		( new OrderListColumn( $this->ttn_repository ) )->register();
		( new CreateWaybillPage( $this->ttn_manager, $this->warehouse_repository ) )->register();
		( new LabelPrint( $this->ttn_repository ) )->register();
		( new AdminAssets() )->register();

		// Frontend / checkout.
		( new AddressFields( $this->api_client ) )->register();
		( new CheckoutAssets() )->register();

		// AJAX.
		( new AddressAjax( $this->api_client ) )->register();
		( new OrderAjax( $this->ttn_manager ) )->register();
		( new AutomationAjax( $this->rule_repository ) )->register();
		( new WarehouseAjax( $warehouse_sync, $this->warehouse_repository ) )->register();
		( new SenderAjax( $this->api_client ) )->register();

		// Cron-моніторинг статусів ТТН + виконання правил автоматизації.
		( new TrackingScheduler( $this->api_client, $this->ttn_repository, $this->rule_engine ) )->register();

		// Реагування правил автоматизації на подію зміни статусу.
		add_action( 'nvx/ttn_status_changed', array( $this->rule_engine, 'handle_status_changed' ), 10, 4 );
		add_action( 'nvx/ttn_created', array( $this->rule_engine, 'handle_ttn_created' ), 10, 3 );
		add_action( 'woocommerce_order_status_changed', array( $this->rule_engine, 'handle_order_status_changed' ), 20, 4 );

		// Фонове виконання мережевих дій автоматизації (вебхук/email) — окремим
		// запитом WP-Cron, а не в тому самому запиті, що створив/оновив ТТН.
		// Див. RuleEngine::schedule_deferred_action().
		add_action( 'nvx/run_deferred_action', array( $this->rule_engine, 'run_deferred_action' ), 10, 5 );

		// Щоденне прибирання журналу автоматизацій, аби таблиця не росла
		// необмежено (раніше єдиним способом почистити був ручний TRUNCATE).
		// Саме розписування cron — в Installer (activate/upgrade), не тут:
		// boot() виконується на кожному запиті, а wp_next_scheduled() —
		// зайвий запит до опцій на кожному хіті сайту.
		add_action( 'nvx/prune_automation_log', array( $this, 'prune_automation_log' ) );
	}

	/**
	 * Викликається щоденним cron 'nvx/prune_automation_log'.
	 * Поріг днів можна змінити фільтром 'nvx/automation_log_retention_days'.
	 */
	public function prune_automation_log(): void {
		$days = (int) apply_filters( 'nvx/automation_log_retention_days', 90 );
		if ( $days <= 0 ) {
			return; // 0/від'ємне значення через фільтр = "не чистити".
		}
		$this->rule_repository->prune_old_log( $days );
	}

	public function register_shipping_method( array $methods ): array {
		$methods['nova_express'] = NovaExpressShippingMethod::class;

		return $methods;
	}

	public function api_client(): NovaPoshtaClient {
		return $this->api_client;
	}

	public function ttn_repository(): TtnRepository {
		return $this->ttn_repository;
	}
}
