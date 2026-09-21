<?php

namespace NovaExpress\Tracking;

use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Automation\RuleEngine;
use NovaExpress\Ttn\TtnRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Пакетне опитування статусів ТТН + подія nvx/ttn_status_changed.
 * Без авто-видалення при «пропущеній» відповіді API.
 */
class TrackingRunner {

	private const BATCH_SIZE = 100;
	private const LOCK_KEY   = 'nvx_tracking_lock';
	private const LOCK_TTL   = 300; // 5 хв.

	private NovaPoshtaClient $client;
	private TtnRepository $repository;
	private RuleEngine $rule_engine;

	public function __construct( NovaPoshtaClient $client, TtnRepository $repository, RuleEngine $rule_engine ) {
		$this->client      = $client;
		$this->repository  = $repository;
		$this->rule_engine = $rule_engine;
	}

	/**
	 * @param bool $force Ігнорувати lock (не використовувати з UI без потреби).
	 */
	public function run( bool $force = false ): array {
		$stats = array(
			'checked'   => 0,
			'changed'   => 0,
			'delivered' => 0,
			'missing'   => 0,
			'deleted'   => 0,
			'errors'    => 0,
			'skipped'   => 0,
		);

		if ( ! $this->client->has_api_key() ) {
			return $stats;
		}

		if ( ! $force && ! $this->acquire_lock() ) {
			$stats['skipped'] = 1;
			return $stats;
		}

		try {
			$pending = $this->repository->find_pending( self::BATCH_SIZE );

			if ( empty( $pending ) ) {
				return $stats;
			}

			$documents = array();
			$by_number = array();

			foreach ( $pending as $row ) {
				$documents[]                         = array( 'DocumentNumber' => $row['waybill_number'] );
				$by_number[ $row['waybill_number'] ] = $row;
			}

			try {
				$results = $this->client->get_statuses( $documents );
			} catch ( \Throwable $e ) {
				$stats['errors']++;
				return $stats;
			}

			foreach ( $results as $status ) {
				$number = $status['Number'] ?? '';

				if ( '' === $number || ! isset( $by_number[ $number ] ) ) {
					continue;
				}

				$row = $by_number[ $number ];
				unset( $by_number[ $number ] );
				$stats['checked']++;

				// Лише явний текст «не знайдено / видалено» — не порожній статус.
				if ( self::looks_deleted( $status ) ) {
					$this->handle_confirmed_missing( $row, $status );
					$stats['deleted']++;
					continue;
				}

				$result = $this->apply_status_update( $row, $status );
				if ( $result['changed'] ) {
					$stats['changed']++;
				}
				if ( $result['delivered'] ) {
					$stats['delivered']++;
				}
			}

			// Відсутність у відповіді ≠ видалення. Лише позначаємо «не отримано статус».
			foreach ( $by_number as $row ) {
				$this->handle_batch_miss( $row );
				$stats['missing']++;
			}
		} finally {
			if ( ! $force ) {
				$this->release_lock();
			}
		}

		return $stats;
	}

	/**
	 * Оновлення однієї ТТН (кнопка «Оновити») — та сама логіка, що й cron, з automation.
	 *
	 * @return array{deleted:bool,status_code?:string,status_text?:string,details?:array,unchanged?:bool}
	 */
	public function refresh_one( array $row, ?\WC_Order $order = null ): array {
		$statuses = $this->client->get_statuses( array( array( 'DocumentNumber' => $row['waybill_number'] ) ) );

		if ( empty( $statuses[0] ) ) {
			// Немає даних у відповіді — не видаляємо, лише фіксуємо опитування.
			$this->handle_batch_miss( $row );
			return array(
				'deleted'     => false,
				'unchanged'   => true,
				'status_code' => (string) ( $row['carrier_status_code'] ?? '' ),
				'status_text' => __( 'Не вдалося отримати статус при цій перевірці. Спробуйте пізніше.', 'wc-nova-express' ),
			);
		}

		$status = $statuses[0];

		if ( self::looks_deleted( $status ) ) {
			$this->handle_confirmed_missing( $row, $status );
			return array( 'deleted' => true );
		}

		$result = $this->apply_status_update( $row, $status );

		return array(
			'deleted'     => false,
			'status_code' => $result['status_code'],
			'status_text' => $result['status_text'],
			'details'     => $status,
			'unchanged'   => ! $result['changed'],
		);
	}

	/**
	 * Застосувати новий статус; при зміні — подія automation.
	 *
	 * @return array{changed:bool,delivered:bool,status_code:string,status_text:string}
	 */
	public function apply_status_update( array $row, array $status ): array {
		$old_status_code = (string) ( $row['carrier_status_code'] ?? '' );
		$new_status_code = (string) ( $status['StatusCode'] ?? '' );
		$status_text     = (string) ( $status['Status'] ?? '' );
		$is_delivered    = $this->rule_engine->is_delivered_code( $new_status_code );

		if ( $new_status_code === $old_status_code && '' !== $new_status_code ) {
			$this->repository->touch_polled( (int) $row['id'] );
			return array(
				'changed'     => false,
				'delivered'   => $is_delivered,
				'status_code' => $new_status_code,
				'status_text' => $status_text,
			);
		}

		// $row уже містить tracking_details (взято з find_pending() / find_by_order()
		// раніше по ланцюжку виклику) — передаємо його напряму, щоб TtnRepository
		// не робив повторний SELECT цього самого рядка перед UPDATE.
		$this->repository->update_status(
			(int) $row['id'],
			$new_status_code,
			$status_text,
			$is_delivered,
			$status,
			array_key_exists( 'tracking_details', $row ) ? $row['tracking_details'] : false
		);

		$order = wc_get_order( (int) $row['order_id'] );
		if ( $order instanceof \WC_Order ) {
			/**
			 * Подія: змінився статус ТТН (cron і ручне оновлення).
			 */
			do_action( 'nvx/ttn_status_changed', $order, $row, $old_status_code, $status );
		}

		return array(
			'changed'     => true,
			'delivered'   => $is_delivered,
			'status_code' => $new_status_code,
			'status_text' => $status_text,
		);
	}

	/**
	 * Явний текст від НП «не знайдено / видалено» — прибираємо локально (підтверджено текстом).
	 */
	private function handle_confirmed_missing( array $row, array $status ): void {
		$this->repository->delete( (int) $row['id'] );

		$order = wc_get_order( (int) $row['order_id'] );
		if ( $order instanceof \WC_Order && $order->get_meta( '_nvx_waybill_number' ) === $row['waybill_number'] ) {
			$order->delete_meta_data( '_nvx_waybill_number' );
			$order->delete_meta_data( '_nvx_waybill_ref' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: waybill number */
					__( 'Nova Express: ТТН №%s не знайдено в Новій Пошті (підтверджено відповіддю API) — прибрано з картки замовлення.', 'wc-nova-express' ),
					$row['waybill_number']
				)
			);
			$order->save();
		}
	}

	/**
	 * Номер був у запиті, але не в відповіді — НЕ видаляємо.
	 */
	private function handle_batch_miss( array $row ): void {
		$this->repository->touch_polled( (int) $row['id'] );

		$details = array();
		if ( ! empty( $row['tracking_details'] ) ) {
			$decoded = json_decode( (string) $row['tracking_details'], true );
			if ( is_array( $decoded ) ) {
				$details = $decoded;
			}
		}
		$details['poll_miss_count'] = (int) ( $details['poll_miss_count'] ?? 0 ) + 1;
		$details['last_poll_miss']  = current_time( 'mysql' );

		global $wpdb;
		$wpdb->update(
			$this->repository->table_name(),
			array(
				'tracking_details' => wp_json_encode( $details, JSON_UNESCAPED_UNICODE ),
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'id' => (int) $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Лише явний текст про відсутність накладної. Порожній Status/Code — НЕ видалення.
	 */
	public static function looks_deleted( array $status ): bool {
		$text = mb_strtolower( ( $status['Status'] ?? '' ) . ' ' . ( $status['StatusCode'] ?? '' ) );

		foreach ( array( 'не знайдено', 'не існує', 'видален', 'not found', 'номер не знайдено' ) as $needle ) {
			if ( false !== mb_strpos( $text, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Атомарне блокування. Попередня реалізація (get_transient() → перевірка
	 * → set_transient()) — класичний TOCTOU race: два паралельні виклики
	 * (ручний "Перевірити зараз" одночасно з cron, або два cron-воркери на
	 * балансованому хостингу) можуть обидва пройти перевірку "лока немає"
	 * до того, як хтось із них встигне його виставити, і обидва почнуть
	 * опитування одночасно.
	 *
	 * Якщо є зовнішній object cache (Redis/Memcached) — wp_cache_add()
	 * атомарний на рівні самого кеш-сервера. Якщо ні — транзієнти WP
	 * зберігаються як звичайні рядки в wp_options, де на option_name є
	 * UNIQUE KEY; тому "INSERT IGNORE" виграє гонку атомарно на рівні БД
	 * незалежно від PHP-паралелізму.
	 */
	private function acquire_lock(): bool {
		$now = time();

		if ( wp_using_ext_object_cache() ) {
			// wp_cache_add() повертає false, якщо ключ уже є — атомарно.
			if ( ! wp_cache_add( self::LOCK_KEY, $now, 'nvx', self::LOCK_TTL ) ) {
				return false;
			}
			return true;
		}

		global $wpdb;

		$option_name = '_transient_' . self::LOCK_KEY;
		$timeout_name = '_transient_timeout_' . self::LOCK_KEY;

		// Прибираємо протухлий лок (не атомарно, але це лише прибирання
		// сміття — сама гонка за новий лок нижче все одно атомарна).
		$expires = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_name )
		);
		if ( $expires && $expires < $now ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $option_name, $timeout_name ) );
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( $timeout_name, 'options' );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				(string) $now
			)
		);

		if ( ! $inserted ) {
			// UNIQUE KEY на option_name відхилив вставку — лок уже тримає інший процес.
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$timeout_name,
				(string) ( $now + self::LOCK_TTL )
			)
		);

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( $timeout_name, 'options' );

		return true;
	}

	private function release_lock(): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( self::LOCK_KEY, 'nvx' );
			return;
		}
		delete_transient( self::LOCK_KEY );
	}
}
