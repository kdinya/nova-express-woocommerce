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

	public function save_progress( int $page, bool $has_more ): void {
		if ( $has_more ) {
			update_option( self::OPTION_LAST_PAGE, $page, false );
		} else {
			delete_option( self::OPTION_LAST_PAGE );
			update_option( self::OPTION_COMPLETED_AT, current_time( 'mysql' ), false );
		}
	}

	public function reset_progress(): void {
		delete_option( self::OPTION_LAST_PAGE );
	}

	/**
	 * @return array{page:int, fetched:int, has_more:bool, total_in_db:int}
	 * @throws NovaPoshtaApiException
	 */
	public function sync_page( int $page ): array {
		$attempts     = 0;
		$max_attempts = 5;
		$last_error   = null;

		while ( $attempts < $max_attempts ) {
			try {
				$rows = $this->client->get_warehouses_page( $page, self::PAGE_SIZE );

				if ( ! empty( $rows ) ) {
					$this->repository->upsert_batch( $rows );
				}

				$has_more = count( $rows ) === self::PAGE_SIZE;
				$this->save_progress( $page, $has_more );

				return array(
					'page'        => $page,
					'fetched'     => count( $rows ),
					'has_more'    => $has_more,
					'total_in_db' => $this->repository->count(),
				);
			} catch ( NovaPoshtaApiException $e ) {
				$last_error = $e;
				$attempts++;

				if ( ( ! $this->is_retryable_error( $e ) ) || $attempts >= $max_attempts ) {
					throw $e;
				}

				// 2с, 4с, 8с, 16с…
				usleep( (int) ( pow( 2, $attempts ) * 1000000 ) );
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
