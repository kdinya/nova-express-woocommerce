<?php

namespace NovaExpress\Warehouse;

defined( 'ABSPATH' ) || exit;

/**
 * Доступ до локальної таблиці {$wpdb->prefix}nvx_warehouses — кешу бази
 * відділень/поштоматів Нової Пошти. Дозволяє миттєвий пошук на чекауті
 * та в адмінці без звернення до API при кожному введенні символу.
 */
class WarehouseRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'nvx_warehouses';
	}

	public function upsert_batch( array $rows ): void {
		global $wpdb;

		if ( empty( $rows ) ) {
			return;
		}

		$now = current_time( 'mysql' );

		// Один багаторядковий INSERT ... ON DUPLICATE KEY UPDATE замість окремого
		// $wpdb->replace() на кожен рядок: на сторінці з 500 відділень це різниця
		// між 500 запитами до БД і одним. При синхронізації всієї бази (сотні
		// сторінок) саме це — а не мережевий виклик до API — часто й є вузьким
		// місцем швидкості.
		$row_templates = array();
		$value_rows    = array();

		foreach ( $rows as $row ) {
			if ( empty( $row['Ref'] ) ) {
				continue; // Без Ref немає унікального ключа — пропускаємо "биту" відповідь.
			}

			// Address.getWarehouses повертає SendingLimitationsOnDimensions (Width/Height/Length,
			// см) — реальний ліміт габаритів для КОНКРЕТНОГО відділення чи поштомата. У поштоматів
			// комірки маленькі (значно менші за загальні 120 см, які плагін використовує як
			// запобіжник за замовчуванням) — тому зберігаємо цей ліміт, щоб звіряти габарити
			// місця ще до відправки запиту на створення ЕН, а не дізнаватись про це з помилки API.
			$dims  = $row['SendingLimitationsOnDimensions'] ?? array();
			$max_w = isset( $dims['Width'] ) && (float) $dims['Width'] > 0 ? (float) $dims['Width'] : null;
			$max_h = isset( $dims['Height'] ) && (float) $dims['Height'] > 0 ? (float) $dims['Height'] : null;
			$max_l = isset( $dims['Length'] ) && (float) $dims['Length'] > 0 ? (float) $dims['Length'] : null;

			$placeholders = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
			$args         = array(
				(string) ( $row['Ref'] ?? '' ),
				(string) ( $row['CityRef'] ?? ( $row['SettlementRef'] ?? '' ) ),
				(string) ( $row['CityDescription'] ?? ( $row['SettlementDescription'] ?? '' ) ),
				(string) ( $row['SettlementAreaDescription'] ?? ( $row['RegionCity'] ?? '' ) ),
				(string) ( $row['Description'] ?? '' ),
				(string) ( $row['ShortAddress'] ?? '' ),
				(string) ( $row['TypeOfWarehouse'] ?? '' ),
				(string) ( $row['Number'] ?? '' ),
			);

			// Ліміти габаритів здебільшого відсутні (звичайне велике відділення) —
			// тоді пишемо буквальний SQL NULL, а НЕ %f-плейсхолдер: інакше PHP null,
			// підставлений у %f через sprintf, перетвориться на число 0.0 замість
			// справжнього NULL (на відміну від $wpdb->insert()/replace(), які самі
			// розпізнають null у масиві даних — тут, у сирому багаторядковому SQL,
			// цього робити нікому, тож обробляємо вручну).
			foreach ( array( $max_w, $max_h, $max_l ) as $dim ) {
				if ( null === $dim ) {
					$placeholders[] = 'NULL';
				} else {
					$placeholders[] = '%f';
					$args[]         = $dim;
				}
			}

			$placeholders[] = '1'; // is_active
			$placeholders[] = '%s'; // updated_at
			$args[]         = $now;

			$row_templates[] = '(' . implode( ', ', $placeholders ) . ')';
			$value_rows[]    = $args;
		}

		if ( empty( $value_rows ) ) {
			return;
		}

		$sql = "INSERT INTO {$this->table}
			(ref, city_ref, city_name, area_name, description, short_address, warehouse_type, warehouse_index, max_dim_width, max_dim_height, max_dim_length, is_active, updated_at)
			VALUES " . implode( ', ', $row_templates ) . '
			ON DUPLICATE KEY UPDATE
				city_ref = VALUES(city_ref),
				city_name = VALUES(city_name),
				area_name = VALUES(area_name),
				description = VALUES(description),
				short_address = VALUES(short_address),
				warehouse_type = VALUES(warehouse_type),
				warehouse_index = VALUES(warehouse_index),
				max_dim_width = VALUES(max_dim_width),
				max_dim_height = VALUES(max_dim_height),
				max_dim_length = VALUES(max_dim_length),
				is_active = VALUES(is_active),
				updated_at = VALUES(updated_at)';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, array_merge( ...$value_rows ) ) );
	}

	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Список унікальних міст, що мають хоча б одне відділення в кеші — для
	 * швидкого предиктивного пошуку міста без звернення до Address API.
	 */
	public function search_cities( string $query, int $limit = 15 ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT city_ref AS ref, city_name, area_name
				 FROM {$this->table}
				 WHERE city_name LIKE %s
				 GROUP BY city_ref, city_name, area_name
				 ORDER BY CHAR_LENGTH(city_name) ASC
				 LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);
	}

	public function search_in_city( string $city_ref, string $query = '', string $type = '', int $limit = 400 ): array {
		global $wpdb;

		// Фільтр типу пункту видачі: 'postomat' — лише поштомати, 'warehouse' — лише відділення.
		// Поштомати в довіднику НП мають TypeOfWarehouse f9316480 (Поштомат) чи 95dc212d (Поштомат ПриватБанку);
		// для надійності додатково звіряємо назву. %% — екранування відсотка для \$wpdb->prepare().
		$type_sql = '';
		if ( 'postomat' === $type ) {
			$type_sql = " AND (warehouse_type IN ('f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc') OR description LIKE '%%Поштомат%%')";
		} elseif ( 'warehouse' === $type ) {
			$type_sql = " AND (warehouse_type NOT IN ('f9316480-5f2d-425d-bc2c-ac7cd29decf0', '95dc212d-479c-4ffb-a8ab-8c1b9073d0bc') AND description NOT LIKE '%%Поштомат%%')";
		}

		if ( '' === $query ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ref, description, warehouse_type FROM {$this->table} WHERE city_ref = %s AND is_active = 1{$type_sql} ORDER BY warehouse_index ASC, description ASC LIMIT %d",
					$city_ref,
					$limit
				),
				ARRAY_A
			);
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ref, description, warehouse_type FROM {$this->table} WHERE city_ref = %s AND is_active = 1{$type_sql} AND description LIKE %s ORDER BY warehouse_index ASC LIMIT %d",
				$city_ref,
				$like,
				$limit
			),
			ARRAY_A
		);
	}

	public function truncate_stale( string $before_datetime ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$this->table} WHERE updated_at < %s", $before_datetime )
		);
	}

	/**
	 * Реальний ліміт габаритів конкретного відділення/поштомата (SendingLimitationsOnDimensions
	 * з довідника Нової Пошти). null, якщо відділення не знайдено в локальному кеші, або якщо
	 * для нього взагалі не заявлено обмеження (звичайне велике відділення).
	 *
	 * @return array{width:float|null,height:float|null,length:float|null}|null
	 */
	public function find_dimension_limit( string $ref ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT max_dim_width, max_dim_height, max_dim_length FROM {$this->table} WHERE ref = %s",
				$ref
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		if ( null === $row['max_dim_width'] && null === $row['max_dim_height'] && null === $row['max_dim_length'] ) {
			return null;
		}

		return array(
			'width'  => null !== $row['max_dim_width'] ? (float) $row['max_dim_width'] : null,
			'height' => null !== $row['max_dim_height'] ? (float) $row['max_dim_height'] : null,
			'length' => null !== $row['max_dim_length'] ? (float) $row['max_dim_length'] : null,
		);
	}
}
