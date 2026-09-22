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
	private const BACKOFF_KEY          = 'nvx_tracking_backoff';
	private const BACKOFF_FAILURES_KEY = 'nvx_tracking_consecutive_failures';

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

		if ( ! $force && $this->is_backed_off() ) {
			$stats['skipped'] = 1;
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
				$this->reset_api_failures();
			} catch ( \Throwable $e ) {
				$this->record_api_failure();
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
		$order_id = ! empty( $row['order_id'] ) ? (int) $row['order_id'] : 0;
		$status_text = __( 'Номер не знайдено в системі Нової Пошти', 'wc-nova-express' );

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order->update_meta_data( '_nvx_tracking_status', $status_text );
				$order->update_meta_data( '_nvx_tracking_code', 'not_found' );
				$order->add_order_note(
					sprintf(
						/* translators: %s: TTN number */
						__( 'Нова Пошта: ТТН %s не знайдено або видалено в системі перевізника. Історію збережено.', 'wc-nova-express' ),
						$row['waybill_number']
					)
				);
				$order->save();
			}
		}

		$this->repository->update_status(
			(int) $row['id'],
			'not_found',
			$status_text,
			0,
			$status
		);
	}

	
}
