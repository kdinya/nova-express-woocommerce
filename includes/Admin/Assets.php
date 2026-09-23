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

		$css_file      = NVX_PLUGIN_DIR . 'assets/css/admin.css';
		$core_file     = NVX_PLUGIN_DIR . 'assets/js/admin-core.js';
		$settings_file = NVX_PLUGIN_DIR . 'assets/js/admin-settings.js';

		$css_ver      = file_exists( $css_file ) ? (string) filemtime( $css_file ) : NVX_VERSION;
		$core_ver     = file_exists( $core_file ) ? (string) filemtime( $core_file ) : NVX_VERSION;
		$settings_ver = file_exists( $settings_file ) ? (string) filemtime( $settings_file ) : NVX_VERSION;

		wp_enqueue_style( 'nvx-admin', NVX_PLUGIN_URL . 'assets/css/admin.css', array(), $css_ver );
		// Динамічний акцентний колір для адмінки плагіна.
		$admin_color = Settings::get_admin_color();
		$custom_css  = self::generate_dynamic_css( $admin_color );
		wp_add_inline_style( 'nvx-admin', $custom_css );


		wp_enqueue_script( 'nvx-admin-core', NVX_PLUGIN_URL . 'assets/js/admin-core.js', array( 'jquery' ), $core_ver, true );

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

		
		if ( $is_shell_page && 'appearance' === $tab ) {
			$appearance_file = NVX_PLUGIN_DIR . 'assets/js/admin-appearance.js';
			$appearance_ver  = file_exists( $appearance_file ) ? (string) filemtime( $appearance_file ) : NVX_VERSION;
			wp_enqueue_script( 'nvx-admin-appearance', NVX_PLUGIN_URL . 'assets/js/admin-appearance.js', array( 'jquery' ), $appearance_ver, true );
		}

		if ( $is_settings_page ) {
			wp_enqueue_script( 'nvx-admin-settings', NVX_PLUGIN_URL . 'assets/js/admin-settings.js', array( 'nvx-admin-core' ), $settings_ver, true );
		}

		if ( $is_shell_page && 'label_template' === $tab ) {
			$template_file = NVX_PLUGIN_DIR . 'assets/js/admin-label-template.js';
			$template_ver  = file_exists( $template_file ) ? (string) filemtime( $template_file ) : NVX_VERSION;
			wp_enqueue_script( 'nvx-admin-label-template', NVX_PLUGIN_URL . 'assets/js/admin-label-template.js', array( 'jquery', 'nvx-admin-core' ), $template_ver, true );
		}
	}

	private static function generate_dynamic_css( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) !== 6 || ! ctype_xdigit( $hex ) ) {
			$hex = '7cb342';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		// Розрахунок темнішого (hover) та світлішого відтінків
		$dark_r  = max( 0, (int) round( $r * 0.85 ) );
		$dark_g  = max( 0, (int) round( $g * 0.85 ) );
		$dark_b  = max( 0, (int) round( $b * 0.85 ) );
		$dark_hex = sprintf( '#%02x%02x%02x', $dark_r, $dark_g, $dark_b );

		$light_r = min( 255, (int) round( $r + ( 255 - $r ) * 0.15 ) );
		$light_g = min( 255, (int) round( $g + ( 255 - $g ) * 0.15 ) );
		$light_b = min( 255, (int) round( $b + ( 255 - $b ) * 0.15 ) );
		$light_hex = sprintf( '#%02x%02x%02x', $light_r, $light_g, $light_b );

		$primary_hex  = '#' . $hex;
		$primary_soft = sprintf( 'rgba(%d, %d, %d, 0.09)', $r, $g, $b );
		$shadow       = sprintf( '0 10px 28px rgba(%d, %d, %d, 0.28)', $r, $g, $b );

		return ":root {
	--nvx-primary: {$primary_hex};
	--nvx-primary-dark: {$dark_hex};
	--nvx-primary-soft: {$primary_soft};
	--nvx-header-gradient: linear-gradient(135deg, {$primary_hex} 0%, {$light_hex} 50%, {$dark_hex} 100%);
	--nvx-header-shadow: {$shadow};
}";
	}

}
