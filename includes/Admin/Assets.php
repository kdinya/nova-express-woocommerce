<?php

namespace NovaExpress\Admin;

defined( 'ABSPATH' ) || exit;

class Assets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( string $hook ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$is_shell_page      = ( 'woocommerce_page_nvx-express' === $hook ) || ( 'nvx-express' === $page );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab                = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$is_settings_page   = $is_shell_page && ( 'settings' === $tab || 'monitoring' === $tab || '' === $tab );
		$is_automation_page = $is_shell_page && in_array( $tab, array( 'automation_ttn', 'automation_order' ), true );
		// Сумісність зі старими URL.
		$is_settings_page   = $is_settings_page || ( 'woocommerce_page_nvx-settings' === $hook ) || ( 'nvx-settings' === $page );
		$is_automation_page = $is_automation_page || ( 'woocommerce_page_nvx-automation' === $hook ) || ( 'nvx-automation' === $page );
		$is_order_screen    = in_array( $hook, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_type          = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
		$is_orders_list     = ( 'edit.php' === $hook && 'shop_order' === $post_type )
			|| ( 'woocommerce_page_wc-orders' === $hook );

		if ( ! $is_shell_page && ! $is_settings_page && ! $is_automation_page && ! $is_order_screen && ! $is_orders_list ) {
			return;
		}

		wp_enqueue_style( 'nvx-admin', NVX_PLUGIN_URL . 'assets/css/admin.css', array(), NVX_VERSION );

		wp_enqueue_script( 'nvx-admin-core', NVX_PLUGIN_URL . 'assets/js/admin-core.js', array( 'jquery' ), NVX_VERSION, true );

		$saved_page = (int) get_option( 'nvx_warehouse_sync_last_page', 0 );

		$config = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'nvx_admin_nonce' ),
			'syncSavedPage'  => $saved_page,
			'syncResumePage' => $saved_page > 1 ? max( 1, $saved_page - 1 ) : 1,
			'i18n'           => array(
				'confirmDelete' => __( 'Видалити це правило автоматизації?', 'wc-nova-express' ),
				'saved'         => __( 'Збережено', 'wc-nova-express' ),
				'error'         => __( 'Помилка', 'wc-nova-express' ),
				'creating'      => __( 'Створюємо ТТН…', 'wc-nova-express' ),
				'checking'      => __( 'Перевіряємо…', 'wc-nova-express' ),
			),
		);
		wp_localize_script( 'nvx-admin-core', 'NVX_ADMIN', $config );

		if ( $is_order_screen ) {
			wp_enqueue_script( 'nvx-admin-order', NVX_PLUGIN_URL . 'assets/js/admin-order.js', array( 'nvx-admin-core' ), NVX_VERSION, true );
		}

		if ( $is_automation_page ) {
			wp_enqueue_script( 'nvx-admin-automation', NVX_PLUGIN_URL . 'assets/js/admin-automation.js', array( 'nvx-admin-core' ), NVX_VERSION, true );
		}

		if ( $is_settings_page ) {
			wp_enqueue_script( 'nvx-admin-settings', NVX_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'nvx-admin-core' ), NVX_VERSION, true );
		}
	}
}
