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
		add_action( 'wp_ajax_nvx_reset_warehouses_sync', array( $this, 'reset_sync' ) );
		add_action( 'wp_ajax_nvx_check_warehouses_count', array( $this, 'check_api_count' ) );

		// Локальний швидкий пошук — доступний і гостям (чекаут), і адмінці.
		add_action( 'wp_ajax_nvx_local_search_cities', array( $this, 'search_cities' ) );
		add_action( 'wp_ajax_nopriv_nvx_local_search_cities', array( $this, 'search_cities' ) );

		add_action( 'wp_ajax_nvx_local_search_warehouses', array( $this, 'search_warehouses' ) );
		add_action( 'wp_ajax_nopriv_nvx_local_search_warehouses', array( $this, 'search_warehouses' ) );
	}

	public function check_api_count(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
			return;
		}

		$posted_api_key = isset( $_REQUEST['api_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['api_key'] ) ) : '';
		if ( '' !== $posted_api_key ) {
			$this->sync->client()->set_api_key( $posted_api_key );
		}

		delete_transient( 'nvx_np_warehouses_total_count' );
		$total = $this->sync->client()->get_warehouses_total_count();
		if ( null !== $total ) {
			set_transient( 'nvx_np_warehouses_total_count', $total, HOUR_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'total_in_db' => $this->repository->count(),
				'total_in_api' => $total,
			)
		);
	}

	public function reset_sync(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
			return;
		}

		$this->sync->reset_progress();
		wp_send_json_success( array( 'saved_page' => 0 ) );
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

		$force_reset = ! empty( $_POST['reset'] );
		if ( $force_reset ) {
			$this->sync->reset_progress();
			$page = 1;
		} elseif ( isset( $_POST['page'] ) && '' !== $_POST['page'] && 'resume' !== $_POST['page'] ) {
			$page = max( 1, (int) $_POST['page'] );
		} else {
			$page = $this->sync->get_resume_page();
		}

		try {
			$result               = $this->sync->sync_page( $page );
			$result['saved_page'] = $this->sync->get_saved_page();
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error(
				array(
					'message'    => $e->getMessage(),
					'errors'     => $e->api_errors(),
					'saved_page' => $this->sync->get_saved_page(),
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
					'saved_page' => $this->sync->get_saved_page(),
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

		$mapped = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$city = trim( (string) ( $row['city_name'] ?? '' ) );
				if ( '' === $city ) {
					continue;
				}
				$area = trim( (string) ( $row['area_name'] ?? '' ) );
				$label = $city . ( '' !== $area ? ', ' . $area . ( false === mb_stripos( $area, 'обл' ) ? ' обл.' : '' ) : '' );
				$mapped[] = array(
					'ref'   => (string) ( $row['ref'] ?? '' ),
					'label' => $label,
				);
			}
		}

		// Якщо в локальній базі записів не знайдено (наприклад, базу ще не синхронізовано
		// або введено селище/місто, якого немає в таблиці) — звертаємось до Nova Poshta API
		if ( empty( $mapped ) && mb_strlen( $query ) >= 2 && $this->sync->client()->has_api_key() ) {
			try {
				$api_cities = $this->sync->client()->search_settlements( $query );
				foreach ( $api_cities as $item ) {
					$ref = (string) ( $item['DeliveryCity'] ?? ( $item['Ref'] ?? '' ) );
					$label = trim( (string) ( $item['Present'] ?? '' ) );
					if ( '' === $label ) {
						$main = trim( (string) ( $item['MainDescription'] ?? '' ) );
						$area = trim( (string) ( $item['Area'] ?? '' ) );
						$label = $main . ( '' !== $area ? ', ' . $area . ( false === mb_stripos( $area, 'обл' ) ? ' обл.' : '' ) : '' );
					}
					if ( '' !== $ref && '' !== $label ) {
						$mapped[] = array(
							'ref'   => $ref,
							'label' => $label,
						);
					}
				}
			} catch ( \Throwable $e ) {
				// Плавний fallback без аварійного завершення
			}
		}

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
				$desc        = (string) ( $row['description'] ?? '' );
				$wh_type     = (string) ( $row['warehouse_type'] ?? '' );
				$is_postomat = in_array( $wh_type, array( 'f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc' ), true ) || ( false !== mb_stripos( $desc, 'поштомат' ) );

				return array(
					'ref'            => (string) ( $row['ref'] ?? '' ),
					'label'          => $desc,
					'type'           => $wh_type,
					'is_postomat'    => $is_postomat,
					'max_dim_width'  => isset( $row['max_dim_width'] ) && null !== $row['max_dim_width'] ? (float) $row['max_dim_width'] : null,
					'max_dim_height' => isset( $row['max_dim_height'] ) && null !== $row['max_dim_height'] ? (float) $row['max_dim_height'] : null,
					'max_dim_length' => isset( $row['max_dim_length'] ) && null !== $row['max_dim_length'] ? (float) $row['max_dim_length'] : null,
				);
			},
			$rows
		);

		// Fallback на live API якщо в локальній базі немає відділень по цьому city_ref
		if ( empty( $mapped ) && $this->sync->client()->has_api_key() ) {
			try {
				$api_whs = $this->sync->client()->get_warehouses( $city_ref, $query );
				foreach ( $api_whs as $wh ) {
					$desc    = (string) ( $wh['Description'] ?? '' );
					$wh_type = (string) ( $wh['TypeOfWarehouse'] ?? '' );
					$is_post = in_array( $wh_type, array( 'f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc' ), true ) || ( false !== mb_stripos( $desc, 'поштомат' ) );

					if ( 'postomat' === $type && ! $is_post ) {
						continue;
					}
					if ( 'warehouse' === $type && $is_post ) {
						continue;
					}

					$dims  = $wh['SendingLimitationsOnDimensions'] ?? array();
					$max_w = isset( $dims['Width'] ) && (float) $dims['Width'] > 0 ? (float) $dims['Width'] : null;
					$max_h = isset( $dims['Height'] ) && (float) $dims['Height'] > 0 ? (float) $dims['Height'] : null;
					$max_l = isset( $dims['Length'] ) && (float) $dims['Length'] > 0 ? (float) $dims['Length'] : null;

					$mapped[] = array(
						'ref'            => (string) ( $wh['Ref'] ?? '' ),
						'label'          => $desc,
						'type'           => $wh_type,
						'is_postomat'    => $is_post,
						'max_dim_width'  => $max_w,
						'max_dim_height' => $max_h,
						'max_dim_length' => $max_l,
					);
				}
			} catch ( \Throwable $e ) {
				// Плавний fallback
			}
		}

		wp_send_json_success( $mapped );
	}
}
