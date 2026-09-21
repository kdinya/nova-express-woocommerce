<?php

namespace NovaExpress\Frontend;

use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Ttn\TtnManager;

defined( 'ABSPATH' ) || exit;

/**
 * Додає на чекаут поля вибору міста/відділення (або вулиці — для дверей)
 * та зберігає обране в сесії (для перерахунку вартості) і в метаданих
 * замовлення (сумісно з HPOS, через $order->update_meta_data()).
 */
class AddressFields {

	private NovaPoshtaClient $client;

	public function __construct( NovaPoshtaClient $client ) {
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_after_checkout_shipping_form', array( $this, 'render_fields_shipping' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_fields' ) );
		add_filter( 'woocommerce_checkout_fields', array( $this, 'make_wc_address_optional' ), 1000 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist_to_order' ), 10, 2 );
		add_action( 'wp_ajax_nvx_set_session_address', array( $this, 'set_session_address' ) );
		add_action( 'wp_ajax_nopriv_nvx_set_session_address', array( $this, 'set_session_address' ) );

		// Сумісність із блоковим чекаутом (Cart & Checkout Blocks): класичні
		// хуки вище на ньому НЕ спрацьовують, тому поля реєструються окремо
		// через офіційний Additional Checkout Fields API (WooCommerce 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_checkout_fields' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_block_fields_to_order' ) );
	}

	/**
	 * Реєстрація полів для блокового чекауту. Через обмеження самого API
	 * (лише текстові/select-поля без живого автопідбору) тут використовується
	 * спрощений текстовий ввід міста/відділення — на класичному чекауті
	 * лишається повноцінний предиктивний пошук з assets/js/checkout.js.
	 */
	public function register_block_checkout_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nvx/service_type',
				'label'    => __( 'Тип доставки Новою Поштою', 'wc-nova-express' ),
				'location' => 'order',
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					array(
						'value' => TtnManager::SERVICE_WAREHOUSE_WAREHOUSE,
						'label' => __( 'У відділення / поштомат', 'wc-nova-express' ),
					),
					array(
						'value' => TtnManager::SERVICE_DOORS_DOORS,
						'label' => __( "Кур'єром за адресою", 'wc-nova-express' ),
					),
				),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nvx/city_name',
				'label'    => __( 'Місто (Нова Пошта)', 'wc-nova-express' ),
				'location' => 'order',
				'type'     => 'text',
				'required' => true,
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nvx/warehouse_label',
				'label'    => __( 'Відділення / поштомат або вулиця, будинок, квартира', 'wc-nova-express' ),
				'location' => 'order',
				'type'     => 'text',
				'required' => true,
			)
		);
	}

	/**
	 * Значення, введені через блоковий чекаут, WooCommerce зберігає прямо
	 * в мету замовлення під ключем поля ("nvx/city_name" тощо) — тут копіюємо
	 * їх у наш стандартний набір "_nvx_*", яким користується решта плагіна
	 * (сторінка створення ТТН, автоматизації), щоб код був єдиним незалежно
	 * від типу чекауту.
	 */
	public function sync_block_fields_to_order( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$city_name  = $order->get_meta( 'nvx/city_name' );
		$warehouse  = $order->get_meta( 'nvx/warehouse_label' );
		$service    = $order->get_meta( 'nvx/service_type' );

		if ( '' === $city_name && '' === $warehouse ) {
			return; // Класичний чекаут уже зберіг дані через persist_to_order() — нема чого синхронізувати.
		}

		if ( '' !== $service ) {
			$order->update_meta_data( '_nvx_service_type', $service );
		}
		if ( '' !== $city_name && '' === $order->get_meta( '_nvx_city_name' ) ) {
			$order->update_meta_data( '_nvx_city_name', $city_name );
			$order->set_shipping_city( $city_name );
		}
		if ( '' !== $warehouse && '' === $order->get_meta( '_nvx_warehouse_label' ) ) {
			// На блоковому чекауті це просто вільний текст (без Ref) — адміну
			// доведеться підтвердити відділення на сторінці створення ТТН.
			$order->update_meta_data( '_nvx_warehouse_label', $warehouse );
			$order->set_shipping_address_1( $warehouse );
		}

		$order->save();
	}

	/**
	 * Рендеримо блок завжди; видимість керує JS за обраним методом доставки Nova Express.
	 * Інакше при першому завантаженні чекауту полів немає в DOM і вони не з'являються.
	 */
	
	public function render_fields_shipping( $checkout ): void {
		$this->render_fields( $checkout );
	}

	public function render_fields( $checkout ): void {
		// Уникаємо подвійного рендеру, якщо вже виведено.
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		echo '<div id="nvx-checkout-fields" class="nvx-checkout-box" style="display:none;">';
		echo '<h3 style="display:flex;align-items:center;margin:0 0 1em;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:8px;flex-shrink:0;"><rect width="24" height="24" rx="4" fill="#DA291C"/><path d="M12 4L5 12H10V20L19 12H14V4Z" fill="white"/></svg><span>' . esc_html__( 'Доставка Новою Поштою', 'wc-nova-express' ) . '</span></h3>';

		echo '<div class="nvx-field-row">';
		echo '<p class="form-row form-row-wide">
				<label>' . esc_html__( 'Тип доставки', 'wc-nova-express' ) . '</label>
				<select id="nvx_service_type" name="nvx_service_type">
					<option value="' . esc_attr( TtnManager::SERVICE_WAREHOUSE_WAREHOUSE ) . '">' . esc_html__( 'У відділення / поштомат', 'wc-nova-express' ) . '</option>
					<option value="' . esc_attr( TtnManager::SERVICE_DOORS_DOORS ) . '">' . esc_html__( 'Кур\'єром за адресою', 'wc-nova-express' ) . '</option>
				</select>
			</p>';
		echo '</div>';

		echo '<p class="form-row form-row-wide">
				<label>' . esc_html__( 'Місто', 'wc-nova-express' ) . ' <abbr class="required">*</abbr></label>
				<input type="text" id="nvx_city_search" autocomplete="off" placeholder="' . esc_attr__( 'Почніть вводити назву міста…', 'wc-nova-express' ) . '" />
				<input type="hidden" id="nvx_city_ref" name="nvx_city_ref" />
				<input type="hidden" id="nvx_city_name" name="nvx_city_name" />
				<div class="nvx-suggest" id="nvx_city_suggest"></div>
			</p>';

		echo '<div id="nvx_warehouse_block">
				<p class="form-row form-row-wide">
					<label>' . esc_html__( 'Відділення / поштомат', 'wc-nova-express' ) . ' <abbr class="required">*</abbr></label>
					<input type="text" id="nvx_warehouse_search" autocomplete="off" placeholder="' . esc_attr__( 'Почніть вводити назву або номер відділення…', 'wc-nova-express' ) . '" />
					<input type="hidden" id="nvx_warehouse_ref" name="nvx_warehouse_ref" />
					<input type="hidden" id="nvx_warehouse_label" name="nvx_warehouse_label" />
					<div class="nvx-suggest" id="nvx_warehouse_suggest"></div>
				</p>
			</div>';

		echo '<div id="nvx_street_block" style="display:none;">
				<p class="form-row form-row-wide">
					<label>' . esc_html__( 'Вулиця', 'wc-nova-express' ) . '</label>
					<input type="text" id="nvx_street_search" autocomplete="off" />
					<input type="hidden" id="nvx_street_ref" name="nvx_street_ref" />
					<div class="nvx-suggest" id="nvx_street_suggest"></div>
				</p>
				<p class="form-row form-row-first">
					<label>' . esc_html__( 'Будинок', 'wc-nova-express' ) . '</label>
					<input type="text" id="nvx_building_number" name="nvx_building_number" required />
				</p>
				<p class="form-row form-row-last">
					<label>' . esc_html__( 'Квартира/офіс', 'wc-nova-express' ) . '</label>
					<input type="text" id="nvx_apartment" name="nvx_apartment" />
				</p>
			</div>';

		echo '</div>';
	}

	public function validate_fields(): void {
		if ( ! $this->cart_has_nova_express() ) {
			return;
		}

		if ( empty( $_POST['nvx_city_ref'] ) ) {
			wc_add_notice( __( 'Будь ласка, оберіть місто доставки Новою Поштою.', 'wc-nova-express' ), 'error' );
		}

		$service = sanitize_text_field( wp_unslash( $_POST['nvx_service_type'] ?? '' ) );

		if ( TtnManager::SERVICE_WAREHOUSE_WAREHOUSE === $service && empty( $_POST['nvx_warehouse_ref'] ) ) {
			wc_add_notice( __( 'Будь ласка, оберіть відділення або поштомат.', 'wc-nova-express' ), 'error' );
		}

		if ( TtnManager::SERVICE_DOORS_DOORS === $service ) {
			if ( empty( $_POST['nvx_street_ref'] ) ) {
				wc_add_notice( __( 'Будь ласка, оберіть вулицю зі списку підказок для кур\'єрської доставки.', 'wc-nova-express' ), 'error' );
			}
			if ( empty( $_POST['nvx_building_number'] ) ) {
				wc_add_notice( __( 'Будь ласка, вкажіть номер будинку для кур\'єрської доставки.', 'wc-nova-express' ), 'error' );
			}
		}
	}

	public function persist_to_order( \WC_Order $order, array $data ): void {
		if ( empty( $_POST['nvx_city_ref'] ) ) {
			return;
		}

		$service_type    = sanitize_text_field( wp_unslash( $_POST['nvx_service_type'] ?? TtnManager::SERVICE_WAREHOUSE_WAREHOUSE ) );
		$city_name       = sanitize_text_field( wp_unslash( $_POST['nvx_city_name'] ?? '' ) );
		$warehouse_label = sanitize_text_field( wp_unslash( $_POST['nvx_warehouse_label'] ?? '' ) );
		$building        = sanitize_text_field( wp_unslash( $_POST['nvx_building_number'] ?? '' ) );
		$apartment       = sanitize_text_field( wp_unslash( $_POST['nvx_apartment'] ?? '' ) );
		$street_name     = sanitize_text_field( wp_unslash( $_POST['nvx_street_search'] ?? '' ) );

		$order->update_meta_data( '_nvx_service_type', $service_type );
		$order->update_meta_data( '_nvx_city_ref', sanitize_text_field( wp_unslash( $_POST['nvx_city_ref'] ) ) );
		$order->update_meta_data( '_nvx_city_name', $city_name );
		$order->update_meta_data( '_nvx_warehouse_ref', sanitize_text_field( wp_unslash( $_POST['nvx_warehouse_ref'] ?? '' ) ) );
		$order->update_meta_data( '_nvx_warehouse_label', $warehouse_label );
		$order->update_meta_data( '_nvx_street_ref', sanitize_text_field( wp_unslash( $_POST['nvx_street_ref'] ?? '' ) ) );
		$order->update_meta_data( '_nvx_street_name', $street_name );
		$order->update_meta_data( '_nvx_building_number', $building );
		$order->update_meta_data( '_nvx_apartment', $apartment );

		// Оновлюємо стандартні поля адреси доставки WooCommerce, щоб вони показувалися
		// у картці замовлення, листах, PDF-накладних та чеках.
		if ( ! empty( $city_name ) ) {
			$order->set_shipping_city( $city_name );
			if ( empty( $order->get_billing_city() ) ) {
				$order->set_billing_city( $city_name );
			}
		}

		if ( TtnManager::SERVICE_WAREHOUSE_WAREHOUSE === $service_type && ! empty( $warehouse_label ) ) {
			$order->set_shipping_address_1( $warehouse_label );
			if ( empty( $order->get_billing_address_1() ) ) {
				$order->set_billing_address_1( $warehouse_label );
			}
		} elseif ( TtnManager::SERVICE_DOORS_DOORS === $service_type ) {
			$address_line = trim( $street_name . ' ' . $building );
			if ( ! empty( $address_line ) ) {
				$order->set_shipping_address_1( $address_line );
			}
			if ( ! empty( $apartment ) ) {
				$order->set_shipping_address_2( __( 'кв./офіс ', 'wc-nova-express' ) . $apartment );
			}
		}
	}

	/**
	 * AJAX: покупець обрав місто → зберігаємо в сесію (city_ref лишається
	 * в сесії для сумісності/можливого майбутнього використання; на
	 * вартість доставки це більше не впливає — вона на чекауті взагалі
	 * не рахується, див. NovaExpressShippingMethod).
	 */
	public function set_session_address(): void {
		check_ajax_referer( 'nvx_public_nonce', 'nonce' );

		$city_ref = isset( $_POST['city_ref'] ) ? sanitize_text_field( wp_unslash( $_POST['city_ref'] ) ) : '';

		if ( WC()->session && '' !== $city_ref ) {
			WC()->session->set( 'nvx_recipient_city_ref', $city_ref );
		}

		wp_send_json_success();
	}


	/**
	 * Коли обрано Nova Express — область/місто/індекс WC не потрібні
	 * (адреса йде через поля НП). Інакше WC/тема вимагають їх як для Укрпошти.
	 */
	public function make_wc_address_optional( array $fields ): array {
		if ( ! $this->cart_has_nova_express() ) {
			return $fields;
		}
		foreach ( array( 'billing', 'shipping' ) as $group ) {
			foreach ( array( 'state', 'city', 'postcode' ) as $key ) {
				if ( isset( $fields[ $group ][ $group . '_' . $key ] ) ) {
					$fields[ $group ][ $group . '_' . $key ]['required'] = false;
				}
			}
		}
		return $fields;
	}

	private function cart_has_nova_express(): bool {
		if ( ! WC()->session ) {
			return false;
		}

		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		if ( empty( $chosen ) || ! is_array( $chosen ) ) {
			return false;
		}

		foreach ( $chosen as $method_id ) {
			if ( is_string( $method_id ) && 0 === strpos( $method_id, 'nova_express' ) ) {
				return true;
			}
		}

		return false;
	}
}
