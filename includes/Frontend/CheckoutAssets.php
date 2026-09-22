<?php

namespace NovaExpress\Frontend;

defined( 'ABSPATH' ) || exit;

class CheckoutAssets {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_style( 'nvx-checkout', NVX_PLUGIN_URL . 'assets/css/checkout.css', array(), NVX_VERSION );
		wp_enqueue_script( 'nvx-checkout', NVX_PLUGIN_URL . 'assets/js/checkout.js', array( 'jquery' ), NVX_VERSION, true );

		wp_localize_script(
			'nvx-checkout',
			'NVX_CHECKOUT',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'nvx_public_nonce' ),
				'enablePhoneMask' => ( 'yes' === ( \NovaExpress\Admin\Settings::get_all()['enable_phone_mask'] ?? 'yes' ) ),
			)
		);
	}
}
