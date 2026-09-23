<?php

namespace NovaExpress\Warehouse;

use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Api\NovaPoshtaClient;

defined( 'ABSPATH' ) || exit;

/**
 * Синхронізація локальної бази відділень (Address.getWarehouses сторінками).
 */
class WarehouseSync {

	public const OPTION_LAST_PAGE    = 'nvx_warehouse_sync_last_page';
	public const OPTION_COMPLETED_AT = 'nvx_warehouse_sync_completed_at';
	public const OPTION_STARTED_AT   = 'nvx_warehouse_sync_started_at';

	// 500 — максимум, який приймає Address.getWarehouses (NovaPoshtaClient::get_warehouses_page
	// сам обрізає Limit до 500). Було 150 — при ~54 000 відділень це втричі більше сторінок
	// (і, відповідно, утричі більше окремих HTTP-запитів до API та AJAX-запитів з браузера),
	// ніж потрібно.
	private const PAGE_SIZE = 500;

	private NovaPoshtaClient $client;
	private WarehouseRepository $repository;

	public function client(): NovaPoshtaClient {
		return $this->client;
	}

	public function __construct( NovaPoshtaClient $client, WarehouseRepository $repository ) {
		$this->client     = $client;
		$this->repository = $repository;
	}

	public function get_saved_page(): int {
		return (int) get_option( self::OPTION_LAST_PAGE, 0 );
	}

	public function get_resume_page(): int {
		$saved = $this->get_saved_page();
		if ( $saved <= 1 ) {
			return 1;
		}
		// Продовжуємо з попередньої сторінки — це запобігає пропускам
		// при зміщенні списку у разі додавання нових відділень, а ON DUPLICATE KEY UPDATE
		// гарантує повну ідемпотентність.
		return max( 1, $saved - 1 );
	}

	public function save_progress( int $page, bool $has_more ): int {
		$deleted_stale = 0;

		if ( $has_more ) {
			update_option( self::OPTION_LAST_PAGE, $page, false );
		} else {
			$started_raw = get_option( self::OPTION_STARTED_AT, 0 );
			if ( ! empty( $started_raw ) ) {
				// Зберігаємо Unix timestamp (число), щоб уникнути подвійного зсуву таймзони при strtotime + wp_date.
				$started_ts = is_numeric( $started_raw ) ? (int) $started_raw : (int) strtotime( (string) $started_raw );
				if ( $started_ts > 0 ) {
					// Cutoff на 60 секунд раніше початку синхронізації
					$cutoff = wp_date( 'Y-m-d H:i:s', max( 0, $started_ts - 60 ) );
					$current_now = current_time( 'mysql' );
					// Запобіжник: cutoff ніколи не повинен перевищувати поточний час запису!
					if ( $cutoff < $current_now ) {
						$deleted_stale = $this->repository->truncate_stale( $cutoff );
					}
				}
				delete_option( self::OPTION_STARTED_AT );
			}
			delete_option( self::OPTION_LAST_PAGE );
			delete_transient( 'nvx_np_warehouses_total_count' );
			update_option( self::OPTION_COMPLETED_AT, current_time( 'mysql' ), false );
		}

		return $deleted_stale;
	}

	public function reset_progress(): void {
		delete_option( self::OPTION_LAST_PAGE );
		delete_option( self::OPTION_STARTED_AT );
	}

	/**
	 * @return array{page:int, fetched:int, has_more:bool, total_in_db:int}
	 * @throws NovaPoshtaApiException
	 */
	public function sync_page( int $page ): array {
		$attempts     = 0;
		// 2 спроби максимум: тривалі ретраї всередині одного PHP-запиту тримають PHP-FPM воркер
		// і MySQL-з'єднання відкритими хвилинами (на шеред-хостингу це вичерпує пул з'єднань).
		// Повторні спроби з паузами ініціює браузер (admin-settings.js) окремими AJAX-запитами.
		$max_attempts = 2;
		$last_error   = null;

		while ( $attempts < $max_attempts ) {
			try {
				// Фіксуємо мітку старту синхронізації ДО оновлення записів першої сторінки в БД,
				// щоб updated_at записів першої пачки гарантовано був >= started_at.
				// Якщо синхронізація відновлюється зі сторінки > 1 і мітки немає — не створюємо її,
				// щоб наприкінці випадково не видалити раніше завантажені сторінки 1..(page-1).
				if ( 1 === $page && ! get_option( self::OPTION_STARTED_AT ) ) {
					// Зберігаємо Unix timestamp (ціле число секунд), зсунутий на 10 секунд назад для буфера.
					update_option( self::OPTION_STARTED_AT, time() - 10, false );
				}

				$rows = $this->client->get_warehouses_page( $page, self::PAGE_SIZE );

				if ( ! empty( $rows ) ) {
					$this->repository->upsert_batch( $rows );
				}

				$has_more      = count( $rows ) === self::PAGE_SIZE;
				$deleted_stale = $this->save_progress( $page, $has_more );

				return array(
					'page'          => $page,
					'fetched'       => count( $rows ),
					'has_more'      => $has_more,
					'total_in_db'   => $this->repository->count(),
					'deleted_stale' => $deleted_stale,
				);
			} catch ( NovaPoshtaApiException $e ) {
				$last_error = $e;
				$attempts++;

				if ( ( ! $this->is_retryable_error( $e ) ) || $attempts >= $max_attempts ) {
					throw $e;
				}

			}
		}

		throw $last_error;
	}

	private function is_retryable_error( NovaPoshtaApiException $e ): bool {
		$haystack = mb_strtolower( $e->getMessage() . ' ' . implode( ' ', $e->api_errors() ) );

		$needles = array(
			'too many',
			'ліміт',
			'лимит',
			'превыш',
			'перевищ',
			'rate limit',
			'timed out',
			'timeout',
			'curl error 28',
			'operation timed out',
			'connection timed out',
		);

		foreach ( $needles as $needle ) {
			if ( false !== mb_strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
