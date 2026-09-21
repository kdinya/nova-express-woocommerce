<?php

namespace NovaExpress\Tracking;

use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Automation\RuleEngine;
use NovaExpress\Ttn\TtnRepository;
use NovaExpress\Helpers\Formatting;

defined( 'ABSPATH' ) || exit;

/**
 * Реєструє WP-Cron подію 'nvx/tracking_cron_event' (інтервал 'nvx_polling_interval'
 * задається в налаштуваннях, cron_schedules фільтр — у головному файлі плагіна)
 * та делегує саму роботу TrackingRunner.
 */
class TrackingScheduler {

	private TrackingRunner $runner;

	public function __construct( NovaPoshtaClient $client, TtnRepository $repository, RuleEngine $rule_engine ) {
		$this->runner = new TrackingRunner( $client, $repository, $rule_engine );
	}

	public function register(): void {
		add_action( 'nvx/tracking_cron_event', array( $this->runner, 'run' ) );

		// Дозволяє вручну запустити перевірку (кнопка "Перевірити зараз" в налаштуваннях).
		add_action( 'wp_ajax_nvx_run_tracking_now', array( $this, 'handle_manual_run' ) );
	}

	public function handle_manual_run(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		$stats = $this->runner->run();

		// Актуальні списки для оновлення UI без перезавантаження.
		$repo = new \NovaExpress\Ttn\TtnRepository();
		$stats['active_count']     = $repo->count_active();
		$stats['active_items']     = self::map_list_items( $repo->find_active_list( 200 ) );
		$stats['delivered_items']  = self::map_list_items( $repo->find_delivered_recent( 5 ) );

		$last_polled_at = $repo->find_last_polled_at();
		if ( $last_polled_at ) {
			$dt       = date_create( $last_polled_at, wp_timezone() );
			$ts_local = $dt ? $dt->getTimestamp() : strtotime( $last_polled_at );
			$stats['last_poll_text'] = sprintf(
				/* translators: 1: formatted date, 2: human-readable "X ago" */
				__( 'Останнє оновлення статусів: %1$s (%2$s тому)', 'wc-nova-express' ),
				wp_date( 'd.m.Y H:i', $ts_local ),
				human_time_diff( $ts_local, time() )
			);
		}

		if ( ! empty( $stats['skipped'] ) ) {
			wp_send_json_success(
				array_merge(
					$stats,
					array(
						'message' => __( 'Перевірка вже виконується (cron або інший запит). Спробуйте через хвилину.', 'wc-nova-express' ),
					)
				)
			);
		}

		wp_send_json_success( $stats );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return list<array<string,string>>
	 */
	private static function map_list_items( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$order      = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $row['order_id'] ) : null;
			$order_num  = $order ? $order->get_order_number() : (string) $row['order_id'];
			$recipient  = '';
			$total_str  = '';
			$order_url  = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $row['order_id'] );
			if ( $order ) {
				$recipient = trim(
					( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) . ' ' .
					( $order->get_shipping_first_name() ?: $order->get_billing_first_name() )
				);
				$total_str = Formatting::clean_order_total( $order );
				$order_url = $order->get_edit_order_url();
			}
			$out[] = array(
				'waybill'   => (string) ( $row['waybill_number'] ?? '' ),
				'order_num' => (string) $order_num,
				'recipient' => $recipient,
				'total'     => $total_str,
				'status'    => Formatting::format_ttn_status_display( $row ),
				'url'       => $order_url,
			);
		}
		return $out;
	}
}

