<?php

namespace NovaExpress\Ajax;

use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Api\NovaPoshtaClient;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX-пошук міст/відділень/вулиць — використовується і на чекауті (для покупця),
 * і в адмінці налаштувань (для вибору відправника).
 */
class AddressAjax {

	private NovaPoshtaClient $client;

	public function __construct( NovaPoshtaClient $client ) {
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'wp_ajax_nvx_search_cities', array( $this, 'search_cities' ) );
		add_action( 'wp_ajax_nopriv_nvx_search_cities', array( $this, 'search_cities' ) );

		add_action( 'wp_ajax_nvx_get_warehouses', array( $this, 'get_warehouses' ) );
		add_action( 'wp_ajax_nopriv_nvx_get_warehouses', array( $this, 'get_warehouses' ) );

		add_action( 'wp_ajax_nvx_search_streets', array( $this, 'search_streets' ) );
		add_action( 'wp_ajax_nopriv_nvx_search_streets', array( $this, 'search_streets' ) );
	}

	public function search_cities(): void {
		check_ajax_referer( 'nvx_public_nonce', 'nonce' );

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( mb_strlen( $query ) < 2 ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'nvx_c_' . md5( mb_strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		try {
			$results = $this->client->search_settlements( $query );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		$mapped = array_map(
			static function ( $item ) {
				return array(
					'ref'   => $item['DeliveryCity'] ?? ( $item['Ref'] ?? '' ),
					'label' => trim( ( $item['MainDescription'] ?? '' ) . ', ' . ( $item['Area'] ?? '' ), ', ' ),
				);
			},
			$results
		);

		set_transient( $cache_key, $mapped, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( $mapped );
	}

	public function get_warehouses(): void {
		check_ajax_referer( 'nvx_public_nonce', 'nonce' );

		$city_ref = isset( $_GET['city_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['city_ref'] ) ) : '';
		$search   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( '' === $city_ref ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'nvx_w_' . md5( $city_ref . '_' . mb_strtolower( $search ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			$results = $cached;
		} else {
			try {
				$results = $this->client->get_warehouses( $city_ref, $search );
				set_transient( $cache_key, $results, 6 * HOUR_IN_SECONDS );
			} catch ( NovaPoshtaApiException $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
			}
		}

		$type_filter = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		if ( 'postomat' === $type_filter ) {
			$results = array_filter(
				$results,
				static function ( $item ) {
					$desc = $item['Description'] ?? '';
					$type = $item['TypeOfWarehouse'] ?? '';
					return false !== mb_stripos( $desc, 'Поштомат' ) || in_array( $type, array( 'f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc' ), true );
				}
			);
		} elseif ( 'warehouse' === $type_filter ) {
			$results = array_filter(
				$results,
				static function ( $item ) {
					$desc = $item['Description'] ?? '';
					$type = $item['TypeOfWarehouse'] ?? '';
					return false === mb_stripos( $desc, 'Поштомат' ) && ! in_array( $type, array( 'f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc' ), true );
				}
			);
		}

		$mapped = array_map(
			static function ( $item ) {
				return array(
					'ref'   => $item['Ref'] ?? '',
					'label' => $item['Description'] ?? '',
					'type'  => $item['TypeOfWarehouse'] ?? '',
				);
			},
			$results
		);

		wp_send_json_success( $mapped );
	}

	public function search_streets(): void {
		check_ajax_referer( 'nvx_public_nonce', 'nonce' );

		$city_ref = isset( $_GET['city_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['city_ref'] ) ) : '';
		$query    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';

		if ( '' === $city_ref || mb_strlen( $query ) < 2 ) {
			wp_send_json_success( array() );
		}

		$cache_key = 'nvx_s_' . md5( $city_ref . '_' . mb_strtolower( $query ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		try {
			$results = $this->client->search_streets( $city_ref, $query );
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		}

		$mapped = array_map(
			static function ( $item ) {
				return array(
					'ref'   => $item['SettlementStreetRef'] ?? ( $item['Ref'] ?? '' ),
					'label' => $item['Present'] ?? ( $item['StreetsTypeDescription'] ?? '' ) . ' ' . ( $item['StreetName'] ?? '' ),
				);
			},
			$results
		);

		set_transient( $cache_key, $mapped, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( $mapped );
	}
}
