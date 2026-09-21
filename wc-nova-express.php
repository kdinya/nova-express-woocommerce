<?php
/**
 * Plugin Name:       Nova Express for WooCommerce
 * Plugin URI:        https://example.com/nova-express
 * Description:       Доставка Новою Поштою для WooCommerce: розрахунок вартості, ручне створення ТТН (ТТН) з картки замовлення, автоматичний моніторинг статусів ТТН та автоматизації (нотатки, зміна статусу, вебхуки).
 * Version:           2026.09.3
 * Author:            kdinya
 * Author URI:        https://github.com/kdinya
 * Text Domain:       wc-nova-express
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.4
 *
 * @package NovaExpress
 */

defined( 'ABSPATH' ) || exit;

// Запобігання конфлікту, якщо одночасно завантажено копію плагіна з іншої теки
if ( defined( 'NVX_VERSION' ) ) {
	return;
}


define( 'NVX_PLUGIN_FILE', __FILE__ );
define( 'NVX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NVX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NVX_VERSION', '2026.09.3' );
define( 'NVX_DB_VERSION', '1.7.0' );

/**
 * Легкий PSR-4-подібний автозавантажувач без Composer,
 * простір імен NovaExpress\ мапиться на каталог /includes.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'NovaExpress\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = NVX_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

/**
 * Декларація сумісності з HPOS (High-Performance Order Storage)
 * та Cart/Checkout Blocks. Обов'язково виконується до woocommerce_init.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				NVX_PLUGIN_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				NVX_PLUGIN_FILE,
				false
			);
		}
	}
);

/**
 * Перевірка активності WooCommerce перш ніж щось завантажувати.
 */
function nvx_is_woocommerce_active() {
	return in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ),
		true
	) || ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) );
}

/**
 * Кастомний інтервал WP-Cron для опитування статусів ТТН.
 * Значення (у хвилинах) береться з налаштувань плагіна, мінімум 5 хв.
 */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$settings = get_option( 'nvx_settings', array() );
		$minutes  = isset( $settings['polling_interval'] ) ? (int) $settings['polling_interval'] : 30;
		$minutes  = max( 5, $minutes );

		$schedules['nvx_polling_interval'] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			'display'  => sprintf(
				/* translators: %d: minutes */
				__( 'Nova Express: кожні %d хв.', 'wc-nova-express' ),
				$minutes
			),
		);

		return $schedules;
	}
);

register_activation_hook( NVX_PLUGIN_FILE, array( '\NovaExpress\Install\Installer', 'activate' ) );
register_deactivation_hook( NVX_PLUGIN_FILE, array( '\NovaExpress\Install\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', function () {
	if ( is_admin() && class_exists( 'NovaExpress\\Install\\Installer' ) ) {
		\NovaExpress\Install\Installer::maybe_upgrade_schema();
	}
}, 5 );

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'wc-nova-express', false, dirname( plugin_basename( NVX_PLUGIN_FILE ) ) . '/languages' );

		if ( ! nvx_is_woocommerce_active() ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Nova Express: для роботи плагіна потрібен активний WooCommerce.', 'wc-nova-express' ) .
						'</p></div>';
				}
			);
			return;
		}

		\NovaExpress\Install\Installer::maybe_upgrade();
		\NovaExpress\Plugin::instance()->boot();
	},
	20
);
