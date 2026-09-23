<?php

namespace NovaExpress\Ajax;

use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Shipping\PriceCalculator;
use NovaExpress\Ttn\TtnManager;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX-дії з замовленням: створення повноцінної ТТН зі сторінки-конструктора
 * (нова вкладка), прив'язка вже існуючого номера ТТН з картки замовлення.
 */
class OrderAjax {

	private TtnManager $manager;

	public function __construct( TtnManager $manager ) {
		$this->manager = $manager;
	}

	public function register(): void {
		add_action( 'wp_ajax_nvx_create_waybill', array( $this, 'create_waybill' ) );
		add_action( 'wp_ajax_nvx_calculate_delivery_price', array( $this, 'calculate_delivery_price' ) );
		add_action( 'wp_ajax_nvx_attach_waybill', array( $this, 'attach_waybill' ) );
		add_action( 'wp_ajax_nvx_delete_waybill', array( $this, 'delete_waybill' ) );
		add_action( 'wp_ajax_nvx_refresh_waybill', array( $this, 'refresh_waybill' ) );
	}

	public function create_waybill(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Замовлення не знайдено.', 'wc-nova-express' ) ), 404 );
		}

		$places_raw = isset( $_POST['places'] ) ? json_decode( wp_unslash( $_POST['places'] ), true ) : array();
		$places     = array();

		if ( is_array( $places_raw ) ) {
			foreach ( $places_raw as $place ) {
				$places[] = array(
					'weight' => (float) ( $place['weight'] ?? 0 ),
					'width'  => (float) ( $place['width'] ?? 0 ),
					'height' => (float) ( $place['height'] ?? 0 ),
					'length' => (float) ( $place['length'] ?? 0 ),
				);
			}
		}

		$service_type_raw = sanitize_text_field( wp_unslash( $_POST['service_type'] ?? '' ) );
		if ( '' !== $service_type_raw && ! in_array( $service_type_raw, PriceCalculator::allowed_service_types(), true ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: submitted service type value */
						__( 'Невідомий тип доставки: %s', 'wc-nova-express' ),
						$service_type_raw
					),
				),
				400
			);
		}

		$overrides = array(
			'service_type'            => '' !== $service_type_raw ? $service_type_raw : null,
			'payer_type'              => sanitize_text_field( wp_unslash( $_POST['payer_type'] ?? 'Recipient' ) ),
			'cargo_type'              => sanitize_text_field( wp_unslash( $_POST['cargo_type'] ?? 'Parcel' ) ),
			'payment_method'          => sanitize_text_field( wp_unslash( $_POST['payment_method'] ?? 'Cash' ) ),
			'date'                    => sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ) ?: null,
			'places'                  => $places,
			'declared_cost'           => isset( $_POST['declared_cost'] ) && '' !== $_POST['declared_cost'] ? (float) $_POST['declared_cost'] : null,
			'description'             => sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ) ?: null,
			'internal_number'         => sanitize_text_field( wp_unslash( $_POST['internal_number'] ?? '' ) ) ?: null,
			'additional_info'         => sanitize_textarea_field( wp_unslash( $_POST['additional_info'] ?? '' ) ) ?: null,
			'recipient_last_name'     => sanitize_text_field( wp_unslash( $_POST['recipient_last_name'] ?? '' ) ) ?: null,
			'recipient_first_name'    => sanitize_text_field( wp_unslash( $_POST['recipient_first_name'] ?? '' ) ) ?: null,
			'recipient_middle_name'   => sanitize_text_field( wp_unslash( $_POST['recipient_middle_name'] ?? '' ) ) ?: null,
			'recipient_email'         => sanitize_email( wp_unslash( $_POST['recipient_email'] ?? '' ) ) ?: null,
			'recipient_phone'         => sanitize_text_field( wp_unslash( $_POST['recipient_phone'] ?? '' ) ) ?: null,
			'recipient_city_ref'      => sanitize_text_field( wp_unslash( $_POST['recipient_city_ref'] ?? '' ) ) ?: null,
			'recipient_city_name'     => sanitize_text_field( wp_unslash( $_POST['recipient_city_name'] ?? '' ) ) ?: null,
			'recipient_warehouse_ref' => sanitize_text_field( wp_unslash( $_POST['recipient_warehouse_ref'] ?? '' ) ) ?: null,
			'recipient_street_ref'    => sanitize_text_field( wp_unslash( $_POST['recipient_street_ref'] ?? '' ) ) ?: null,
			'recipient_building'      => sanitize_text_field( wp_unslash( $_POST['recipient_building'] ?? '' ) ) ?: null,
			'recipient_apartment'     => sanitize_text_field( wp_unslash( $_POST['recipient_apartment'] ?? '' ) ) ?: null,
			'sender_id'                => sanitize_text_field( wp_unslash( $_POST['sender_id'] ?? 'primary' ) ) ?: 'primary',
		);

		$overrides = array_filter( $overrides, static fn( $v ) => null !== $v && array() !== $v );
		if ( empty( $places ) ) {
			unset( $overrides['places'] );
		}

		try {
			$result = $this->manager->create_for_order( $order, $overrides );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'errors'  => $e->api_errors(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	public function attach_waybill(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$order_id       = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$waybill_number = sanitize_text_field( wp_unslash( $_POST['waybill_number'] ?? '' ) );
		$order          = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Замовлення не знайдено.', 'wc-nova-express' ) ), 404 );
		}

		try {
			$result = $this->manager->attach_existing( $order, $waybill_number );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		wp_send_json_success( $result );
	}

	public function delete_waybill(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$ttn_id   = isset( $_POST['ttn_id'] ) ? (int) $_POST['ttn_id'] : 0;
		$order    = wc_get_order( $order_id );

		$force_local = ! empty( $_POST['force_local'] );
		try {
			$this->manager->delete_waybill( $ttn_id, $order instanceof \WC_Order ? $order : null, (bool) $force_local );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage(), 'can_force_local' => true ), 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success();
	}

	public function refresh_waybill(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$ttn_id   = isset( $_POST['ttn_id'] ) ? (int) $_POST['ttn_id'] : 0;
		$order    = wc_get_order( $order_id );

		try {
			$result = $this->manager->refresh_single( $ttn_id, $order instanceof \WC_Order ? $order : null );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		// Актуальний статус із бази — щоб картка замовлення оновила лише його, без перезавантаження сторінки.
		if ( empty( $result['deleted'] ) ) {
			$fresh = $this->manager->repository()->find_by_id( $ttn_id );
			if ( $fresh ) {
				$last_time = ! empty( $fresh['last_polled_at'] ) ? $fresh['last_polled_at'] : ( $fresh['updated_at'] ?? '' );
				$time_fmt  = $last_time ? mysql2date( 'd.m.Y H:i', $last_time, false ) : '';
				$c_code = (string) ( $fresh['carrier_status_code'] ?? '' );
				$is_dispatched = ! empty( $fresh['is_delivered'] ) || ( '' !== $c_code && ! in_array( $c_code, array( '1', '2', '3', 'ttn_created', 'ttn_added' ), true ) );
				$result['waybill'] = array(
					'carrier_status_code'   => $c_code,
					'carrier_status_text'   => (string) ( $fresh['carrier_status_text'] ?? '' ),
					'is_delivered'          => ! empty( $fresh['is_delivered'] ),
					'is_dispatched'         => $is_dispatched,
					'last_updated_formatted'=> $time_fmt,
					'status_display'         => \NovaExpress\Helpers\Formatting::format_ttn_status_display( $fresh ),
					'status_short'           => \NovaExpress\Helpers\Formatting::format_ttn_status_display( $fresh, false ),
				);
			}
		}

		wp_send_json_success( $result );
	}
/**
	 * Орієнтовна вартість доставки (InternetDocument.getDocumentPrice).
	 */
	public function calculate_delivery_price(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$settings = \NovaExpress\Admin\Settings::get_all();
		$client   = new \NovaExpress\Api\NovaPoshtaClient( $settings['api_key'] ?? '' );

		if ( ! $client->has_api_key() ) {
			wp_send_json_error( array( 'message' => __( 'Немає API-ключа.', 'wc-nova-express' ) ), 400 );
		}

		$sender_id = sanitize_text_field( wp_unslash( $_POST['sender_id'] ?? 'primary' ) );
		$profile   = \NovaExpress\Admin\Settings::get_sender_profile( $sender_id ) ?: array();
		$city_sender = $profile['city_ref'] ?? ( $settings['sender_city_ref'] ?? '' );

		$city_recipient = sanitize_text_field( wp_unslash( $_POST['recipient_city_ref'] ?? '' ) );
		$service_raw    = sanitize_text_field( wp_unslash( $_POST['service_type'] ?? TtnManager::SERVICE_WAREHOUSE_WAREHOUSE ) );

		try {
			$service = PriceCalculator::map_service_type_strict( $service_raw );
		} catch ( \InvalidArgumentException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		$weight = max( 0.1, (float) ( $_POST['weight'] ?? 0.1 ) );
		$cost   = max( 100, (float) ( $_POST['declared_cost'] ?? 100 ) );

		if ( '' === $city_sender || '' === $city_recipient ) {
			wp_send_json_error( array( 'message' => __( 'Оберіть місто отримувача та відправника.', 'wc-nova-express' ) ), 400 );
		}

		$places_raw    = isset( $_POST['places'] ) && is_array( $_POST['places'] ) ? wp_unslash( $_POST['places'] ) : array();
		$options_seat  = array();
		$places_weight = 0.0;
		$vol_weight    = 0.0;

		foreach ( $places_raw as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$w  = min( 120.0, max( 1.0, (float) ( $p['width'] ?? 10 ) ) );
			$h  = min( 120.0, max( 1.0, (float) ( $p['height'] ?? 10 ) ) );
			$l  = min( 120.0, max( 1.0, (float) ( $p['length'] ?? 10 ) ) );
			$wt = max( 0.1, (float) ( $p['weight'] ?? 0.1 ) );

			$places_weight += $wt;
			$vol_weight    += ( $w * $h * $l ) / 4000.0;

			$options_seat[] = array(
				'weight'           => (string) round( $wt, 2 ),
				'volumetricWidth'  => (string) round( $w, 1 ),
				'volumetricLength' => (string) round( $l, 1 ),
				'volumetricHeight' => (string) round( $h, 1 ),
			);
		}

		$effective_weight = max( $weight, $places_weight, $vol_weight, 0.1 );
		$seats_count      = ! empty( $options_seat ) ? count( $options_seat ) : max( 1, (int) ( $_POST['seats'] ?? 1 ) );

		$params = array(
			'CitySender'    => $city_sender,
			'CityRecipient' => $city_recipient,
			'Weight'        => (string) round( $effective_weight, 2 ),
			'ServiceType'   => $service,
			'Cost'          => (string) round( $cost, 2 ),
			'CargoType'     => sanitize_text_field( wp_unslash( $_POST['cargo_type'] ?? 'Parcel' ) ),
			'SeatsAmount'   => (string) $seats_count,
		);

		if ( ! empty( $options_seat ) ) {
			$params['OptionsSeat'] = $options_seat;
		}

		$result = PriceCalculator::calculate( $client, $params );

		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
		}

		$price = (float) $result['cost'];
		wp_send_json_success(
			array(
				'cost'     => $price,
				'cost_fmt' => number_format_i18n( $price, 2 ) . ' грн',
				'raw'      => $result['raw'],
			)
		);
	}
}
