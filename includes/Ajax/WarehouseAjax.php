<?php

namespace NovaExpress\Ajax;

use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Warehouse\WarehouseRepository;
use NovaExpress\Warehouse\WarehouseSync;

defined( 'ABSPATH' ) || exit;

class WarehouseAjax {

	private WarehouseSync $sync;
	private WarehouseRepository $repository;

	public function __construct( WarehouseSync $sync, WarehouseRepository $repository ) {
		$this->sync       = $sync;
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'wp_ajax_nvx_sync_warehouses_page', array( $this, 'sync_page' ) );

		// Локальний швидкий пошук — доступний і гостям (чекаут), і адмінці.
		add_action( 'wp_ajax_nvx_local_search_cities', array( $this, 'search_cities' ) );
		add_action( 'wp_ajax_nopriv_nvx_local_search_cities', array( $this, 'search_cities' ) );

		add_action( 'wp_ajax_nvx_local_search_warehouses', array( $this, 'search_warehouses' ) );
		add_action( 'wp_ajax_nopriv_nvx_local_search_warehouses', array( $this, 'search_warehouses' ) );
	}

	public function sync_page(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
			return;
		}

		$posted_api_key = isset( $_REQUEST['api_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['api_key'] ) ) : '';
		if ( '' !== $posted_api_key ) {
			$settings = \NovaExpress\Admin\Settings::get_all();
			$settings['api_key'] = $posted_api_key;
			update_option( 'nvx_settings', $settings );
			$this->sync->client()->set_api_key( $posted_api_key );
		}

		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;

		try {
			$result = $this->sync->sync_page( $page );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error(
				array(
					'message' => $e->getMessage(),
					'errors'  => $e->api_errors(),
				),
				400
			);
		} catch ( \Throwable $e ) {
			// Будь-яка інша помилка (БД, мережа, неочікувана відповідь API) —
			// повертаємо як JSON, а не даємо запиту "тихо" впасти в білий екран.
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: raw error message */
						__( 'Неочікувана помилка під час синхронізації: %s', 'wc-nova-express' ),
						$e->getMessage()
					),
				),
				500
			);
		}

		wp_send_json_success( $result );
	}

	public function search_cities(): void {
		$nonce_ok = wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'nvx_public_nonce' ) || wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'nvx_admin_nonce' );

		if ( ! $nonce_ok ) {
			wp_send_json_error( array( 'message' => __( 'Недійсний запит.', 'wc-nova-express' ) ), 400 );
		}

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( mb_strlen( $query ) < 1 ) {
			wp_send_json_success( array() );
		}

		$rows = $this->repository->search_cities( $query );

		$mapped = array_map(
			static function ( $row ) {
				return array(
					'ref'   => $row['ref'],
					'label' => trim( $row['city_name'] . ( $row['area_name'] ? ', ' . $row['area_name'] : '' ) ),
				);
			},
			$rows
		);

		wp_send_json_success( $mapped );
	}

	public function search_warehouses(): void {
		$nonce_ok = wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'nvx_public_nonce' ) || wp_verify_nonce( $_REQUEST['nonce'] ?? '', 'nvx_admin_nonce' );

		if ( ! $nonce_ok ) {
			wp_send_json_error( array( 'message' => __( 'Недійсний запит.', 'wc-nova-express' ) ), 400 );
		}

		$city_ref = isset( $_GET['city_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['city_ref'] ) ) : '';
		$query    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( '' === $city_ref ) {
			wp_send_json_success( array() );
		}

		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		$rows = $this->repository->search_in_city( $city_ref, $query, $type );

		$mapped = array_map(
			static function ( $row ) {
				return array(
					'ref'   => $row['ref'],
					'label' => $row['description'],
					'type'  => $row['warehouse_type'],
				);
			},
			$rows
		);

		wp_send_json_success( $mapped );
	}
}
