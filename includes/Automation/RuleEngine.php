<?php

namespace NovaExpress\Automation;

use NovaExpress\Automation\Action\AddNoteAction;
use NovaExpress\Automation\Action\ChangeStatusAction;
use NovaExpress\Automation\Action\SendEmailAction;
use NovaExpress\Automation\Action\SendWebhookAction;

defined( 'ABSPATH' ) || exit;

/**
 * Виконує правила автоматизації:
 * - зміна статусу ТТН (трекінг);
 * - створення ТТН (тригер ttn_created) та додавання наявної ТТН до замовлення (тригер ttn_added).
 */
class RuleEngine {

	private RuleRepository $repository;

	/** @var array<string,\NovaExpress\Automation\Action\ActionInterface> */
	private array $actions;

	public function __construct( RuleRepository $repository ) {
		$this->repository = $repository;

		$this->actions = array(
			'add_note'      => new AddNoteAction(),
			'change_status' => new ChangeStatusAction(),
			'send_webhook'  => new SendWebhookAction(),
			'send_email'    => new SendEmailAction(),
		);
	}

	/**
	 * Хук nvx/ttn_status_changed( WC_Order $order, array $waybill_row, string $old_status_code, array $new_status ).
	 */
	public function handle_status_changed( \WC_Order $order, array $waybill_row, string $old_status_code, array $new_status ): void {
		$status_code = (string) ( $new_status['StatusCode'] ?? '' );

		$rules = $this->repository->enabled_for_status( $status_code );

		if ( empty( $rules ) ) {
			return;
		}

		$status_event = array(
			'waybill_number'       => $waybill_row['waybill_number'] ?? '',
			'status_code'          => $status_code,
			'status_text'          => $new_status['Status'] ?? '',
			'previous_status_code' => $old_status_code,
			'is_delivered'         => $this->is_delivered_code( $status_code ),
			'event'                => 'status_changed',
		);

		$this->run_rules( $rules, $order, $waybill_row, $status_event );
	}

	/**
	 * Хук nvx/ttn_created( WC_Order $order, array $waybill_row, string $source ).
	 * $source: created → тригер ttn_created («ТТН створено»);
	 *          attached → тригер ttn_added («ТТН додано»).
	 */
	public function handle_ttn_created( \WC_Order $order, array $waybill_row, string $source = 'created' ): void {
		$trigger = ( 'attached' === $source ) ? 'ttn_added' : 'ttn_created';
		$rules   = $this->repository->enabled_for_status( $trigger );

		if ( empty( $rules ) ) {
			return;
		}

		$status_event = array(
			'waybill_number'       => $waybill_row['waybill_number'] ?? '',
			'status_code'          => $trigger,
			'status_text'          => 'attached' === $source
				? __( 'ТТН додано до замовлення', 'wc-nova-express' )
				: __( 'ТТН створено', 'wc-nova-express' ),
			'previous_status_code' => '',
			'is_delivered'         => false,
			'event'                => $trigger,
			'source'               => $source,
		);

		$this->run_rules( $rules, $order, $waybill_row, $status_event );
	}

	/**
	 * @param array[] $rules
	 */
	
	/**
	 * WooCommerce: зміна статусу замовлення.
	 *
	 * @param int       $order_id
	 * @param string    $status_from без префікса wc-
	 * @param string    $status_to   без префікса wc-
	 * @param \WC_Order $order
	 */
	public function handle_order_status_changed( $order_id, $status_from, $status_to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$status_to = (string) $status_to;
		$rules     = $this->repository->enabled_for_status( $status_to, 'order' );
		if ( empty( $rules ) ) {
			return;
		}

		$waybill_row = array(
			'waybill_number' => (string) $order->get_meta( '_nvx_waybill_number' ),
		);

		$status_event = array(
			'waybill_number'       => $waybill_row['waybill_number'],
			'status_code'          => $status_to,
			'status_text'          => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $status_to ) : $status_to,
			'previous_status_code' => (string) $status_from,
			'is_delivered'         => false,
			'event'                => 'order_status_changed',
		);

		$this->run_rules( $rules, $order, $waybill_row, $status_event );
	}

private function run_rules( array $rules, \WC_Order $order, array $waybill_row, array $status_event ): void {
		if ( $this->already_processed( $order, $waybill_row, $status_event ) ) {
			return;
		}

		foreach ( $rules as $rule ) {
			foreach ( (array) $rule['actions'] as $action_config ) {
				$type = $action_config['type'] ?? '';

				if ( ! isset( $this->actions[ $type ] ) ) {
					continue;
				}

				// Мережеві дії (вебхук, email) — окремою фоновою подією, а не в
				// тому самому запиті, що й створення/оновлення ТТН. Див.
				// schedule_deferred_action() нижче.
				if ( in_array( $type, self::DEFERRED_ACTION_TYPES, true ) ) {
					$this->schedule_deferred_action( $order->get_id(), (int) $rule['id'], $type, $action_config, $status_event );
					continue;
				}

				try {
					$outcome = $this->actions[ $type ]->run( $order, $action_config, $status_event );
				} catch ( \Throwable $e ) {
					$outcome = array(
						'result'  => 'error',
						'message' => $e->getMessage(),
					);
				}

				$this->repository->log(
					(int) $rule['id'],
					$order->get_id(),
					$waybill_row['waybill_number'] ?? '',
					$type,
					$outcome['result'],
					$outcome['message'] ?? ''
				);
			}
		}
	}

	/**
	 * Типи дій, які відправляють мережевий запит зовні сайту (вебхук, email/SMTP)
	 * і тому виконуються окремою фоновою подією — див. schedule_deferred_action().
	 */
	private const DEFERRED_ACTION_TYPES = array( 'send_webhook', 'send_email' );

	/**
	 * Реальне створення ТТН — це вже ланцюжок із кількох послідовних викликів
	 * Nova Poshta API (Counterparty.save → InternetDocument.save → одразу
	 * опитування статусу). Якщо після всього цього ще й синхронно чекати на
	 * відповідь стороннього вебхука/SMTP-сервера в тому самому HTTP-запиті —
	 * на хостингах із типовим лімітом виконання (30с) процес може обірватись
	 * рівно на цьому останньому кроці: ТТН вже встигає успішно створитись і
	 * зберегтись (це видно на картці замовлення), а вебхук — просто ніколи
	 * не встигає запуститись, і жодної помилки при цьому не лишається — саме
	 * тому кнопка "Тест" (один ізольований запит) працює завжди, а реальне
	 * спрацювання — рідко. Виносимо в окрему подію WP-Cron, яка виконується
	 * в іншому запиті з власним лімітом часу.
	 */
	private function schedule_deferred_action( int $order_id, int $rule_id, string $type, array $action_config, array $status_event ): void {
		wp_schedule_single_event(
			time(),
			'nvx/run_deferred_action',
			array( $order_id, $rule_id, $type, $action_config, $status_event )
		);

		// Неблокуючий (fire-and-forget) запит до wp-cron.php одразу, а не
		// очікування, поки хтось інший відвідає сайт — на магазині з малою
		// кількістю замовлень "органічний" WP-Cron інакше спрацював би
		// із суттєвою затримкою (аж до наступного відвідування сайту).
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Обробник фонової події 'nvx/run_deferred_action' — виконується в окремому
	 * запиті, не пов'язаному з часом виконання того запиту, що створив/оновив ТТН.
	 */
	public function run_deferred_action( int $order_id, int $rule_id, string $type, array $action_config, array $status_event ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! isset( $this->actions[ $type ] ) ) {
			return;
		}

		try {
			$outcome = $this->actions[ $type ]->run( $order, $action_config, $status_event );
		} catch ( \Throwable $e ) {
			$outcome = array(
				'result'  => 'error',
				'message' => $e->getMessage(),
			);
		}

		$this->repository->log(
			$rule_id,
			$order_id,
			(string) ( $status_event['waybill_number'] ?? '' ),
			$type,
			$outcome['result'],
			$outcome['message'] ?? ''
		);
	}

	/**
	 * Захист від подвійного виконання того самого "події" (той самий
	 * order + waybill + перехід статусу) у вузькому вікні часу.
	 *
	 * Атомарний lock у TrackingRunner тепер не дає ДВОМ ПОВНИМ ЦИКЛАМ
	 * cron/опитування перекритись, але той самий перехід статусу
	 * теоретично може прилетіти двічі й іншим шляхом: адмін натиснув
	 * "Оновити" на картці замовлення рівно в момент, коли щойно
	 * завершився цикл крону з тим самим результатом, або WooCommerce
	 * викликав woocommerce_order_status_changed двічі поспіль (відомий
	 * edge case деяких платіжних шлюзів/плагінів). Без цього дедуплю
	 * вебхук/email/зміна-статусу могли б виконатись двічі — саме те,
	 * на що вказував пункт огляду про "event_id генерується, але не
	 * використовується як idempotency key".
	 */
	private function already_processed( \WC_Order $order, array $waybill_row, array $status_event ): bool {
		$fingerprint = md5(
			implode(
				'|',
				array(
					$order->get_id(),
					(string) ( $waybill_row['waybill_number'] ?? '' ),
					(string) ( $status_event['event'] ?? '' ),
					(string) ( $status_event['previous_status_code'] ?? '' ),
					(string) ( $status_event['status_code'] ?? '' ),
				)
			)
		);

		$key = 'nvx_evt_' . $fingerprint;

		if ( function_exists( 'wp_cache_add' ) && wp_using_ext_object_cache() ) {
			$added = wp_cache_add( $key, 1, 'nvx_events', 60 );
			if ( ! $added ) {
				return true;
			}
		}

		if ( false !== get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, 1, 60 );

		return false;
	}

	/**
	 * Коди фінального статусу "Отримано" в довіднику Nova Poshta.
	 */
	public function is_delivered_code( string $status_code ): bool {
		return in_array( $status_code, array( '9', '10', '11' ), true );
	}
}
