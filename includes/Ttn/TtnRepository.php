<?php

namespace NovaExpress\Ttn;

defined( 'ABSPATH' ) || exit;

/**
 * Репозиторій локальної таблиці ТТН (nvx_waybills).
 */
class TtnRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nvx_waybills';
	}

	public function table_name(): string {
		return $this->table;
	}

	public function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$result = $wpdb->insert(
			$this->table,
			array(
				'order_id'            => (int) $data['order_id'],
				'waybill_number'      => sanitize_text_field( $data['waybill_number'] ),
				'document_ref'        => sanitize_text_field( $data['document_ref'] ?? '' ),
				'service_type'        => sanitize_text_field( $data['service_type'] ?? 'warehouse_warehouse' ),
				'carrier_status_code' => sanitize_text_field( $data['carrier_status_code'] ?? '' ),
				'carrier_status_text' => sanitize_text_field( $data['carrier_status_text'] ?? '' ),
				'is_delivered'        => 0,
				'created_at'          => $now,
				'updated_at'          => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: DB error */
					__( 'Не вдалося зберегти ТТН у локальну базу: %s', 'wc-nova-express' ),
					$wpdb->last_error ?: 'unknown'
				)
			);
		}

		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new \RuntimeException( __( 'Не вдалося зберегти ТТН у локальну базу (insert_id=0).', 'wc-nova-express' ) );
		}

		return $id;
	}

	public function find_active_for_order( int $order_id ): ?array {
		$rows = $this->find_by_order( $order_id );
		return $rows[0] ?? null;
	}

	/**
	 * Той самий результат, що дає find_active_for_order() для кожного order_id,
	 * але одним запитом для всього списку — щоб уникнути окремого SELECT на
	 * кожен рядок у списку замовлень WooCommerce (N+1).
	 *
	 * @param int[] $order_ids
	 * @return array<int,array<string,mixed>> Мапа order_id => останній рядок ТТН (або відсутній ключ, якщо ТТН немає).
	 */
	public function find_latest_for_orders( array $order_ids ): array {
		global $wpdb;

		$order_ids = array_values( array_unique( array_map( 'intval', $order_ids ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE order_id IN ({$placeholders}) ORDER BY id DESC",
				$order_ids
			),
			ARRAY_A
		);

		$latest = array();
		foreach ( $rows as $row ) {
			$oid = (int) $row['order_id'];
			// Перший рядок для кожного order_id у списку (ORDER BY id DESC) — і є "останній", як у find_active_for_order().
			if ( ! isset( $latest[ $oid ] ) ) {
				$latest[ $oid ] = $row;
			}
		}

		return $latest;
	}

	public function find_by_order( int $order_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY id DESC", $order_id ),
			ARRAY_A
		);
	}

	public function find_by_waybill_number( string $number ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE waybill_number = %s", $number ),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function find_by_id( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Усі ТТН, які ще не мають фінального статусу "отримано".
	 */
	public function find_pending( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE is_delivered = 0 ORDER BY last_polled_at IS NULL DESC, last_polled_at ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * @param array|null  $raw_status         Повна відповідь TrackingDocument.getStatusDocuments (опційно).
	 * @param string|false|null $previous_details Попереднє значення колонки tracking_details (JSON) із рядка,
	 *                                            який уже є у виклику (наприклад, з find_pending()) —
	 *                                            передається сюди, щоб не робити повторний SELECT.
	 *                                            false (за замовчуванням) = не передано, читаємо з БД як і раніше
	 *                                            (для викликів, де рядок наперед невідомий). null = відомо, що
	 *                                            попереднього значення немає.
	 */
	public function update_status( int $id, string $status_code, string $status_text, bool $is_delivered, ?array $raw_status = null, $previous_details = false ): void {
		global $wpdb;

		$data = array(
			'carrier_status_code' => sanitize_text_field( $status_code ),
			'carrier_status_text' => sanitize_text_field( $status_text ),
			'is_delivered'        => $is_delivered ? 1 : 0,
			'last_polled_at'      => current_time( 'mysql' ),
			'updated_at'          => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%d', '%s', '%s' );

		if ( null !== $raw_status ) {
			$keep = array(
				'Number', 'Status', 'StatusCode', 'StatusCodeDescription',
				'WarehouseRecipient', 'WarehouseSender',
				'CityRecipient', 'CitySender',
				'RecipientDateTime', 'ScheduledDeliveryDate', 'DateCreated',
				'DocumentWeight', 'DocumentCost', 'CostOnSite',
				'PayerType', 'PaymentMethod', 'CargoType', 'ServiceType',
				'SeatsAmount', 'PhoneRecipient', 'PhoneSender',
				'RecipientAddress', 'SenderAddress',
				'RefEW', 'RefCityRecipient', 'RefCitySender',
				'ActualDeliveryDate', 'DateScan', 'TrackingUpdateDate',
			);
			$slim = array();
			foreach ( $keep as $key ) {
				if ( isset( $raw_status[ $key ] ) && '' !== (string) $raw_status[ $key ] ) {
					$slim[ $key ] = $raw_status[ $key ];
				}
			}
			// Зберігаємо лічильник пропусків, якщо був. Якщо викликач уже має
			// це значення під рукою (наприклад, з find_pending()) — використовуємо
			// його напряму, без повторного SELECT до БД.
			if ( false === $previous_details ) {
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT tracking_details FROM {$this->table} WHERE id = %d", $id ) );
			} else {
				$existing = $previous_details;
			}
			if ( $existing ) {
				$prev = json_decode( (string) $existing, true );
				if ( is_array( $prev ) && isset( $prev['poll_miss_count'] ) ) {
					$slim['poll_miss_count'] = 0; // успішний poll скидає
				}
			}
			$data['tracking_details'] = wp_json_encode( $slim, JSON_UNESCAPED_UNICODE );
			$formats[]                = '%s';
		}

		$result = $wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: DB error */
					__( 'Не вдалося оновити статус ТТН: %s', 'wc-nova-express' ),
					$wpdb->last_error ?: 'unknown'
				)
			);
		}
	}


	/**
	 * Активні (ще не доставлені) ТТН для моніторингу.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function find_active_list( int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE is_delivered = 0 ORDER BY updated_at DESC, id DESC LIMIT %d",
				max( $limit, 1 )
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Останні доставлені ТТН.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function find_delivered_recent( int $limit = 5 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE is_delivered = 1 ORDER BY updated_at DESC, id DESC LIMIT %d",
				max( $limit, 1 )
			),
			ARRAY_A
		) ?: array();
	}

	public function count_active(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE is_delivered = 0" );
	}

	/**
	 * Час останнього опитування статусів (MAX(last_polled_at) серед активних
	 * ТТН) — для відображення у "Моніторинг ТТН", щоб було видно, чи взагалі
	 * і коли востаннє реально спрацював крон/ручна перевірка.
	 */
	public function find_last_polled_at(): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( "SELECT MAX(last_polled_at) FROM {$this->table}" );

		return $value ?: null;
	}

	public function touch_polled( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table,
			array( 'last_polled_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public function delete( int $id ): void {
		global $wpdb;
		$result = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: DB error */
					__( 'Не вдалося видалити ТТН з локальної бази: %s', 'wc-nova-express' ),
					$wpdb->last_error ?: 'unknown'
				)
			);
		}
	}
}
