<?php

namespace NovaExpress\Automation;

defined( 'ABSPATH' ) || exit;

class RuleRepository {

	private string $rules_table;
	private string $log_table;

	public function __construct() {
		global $wpdb;
		$this->rules_table = $wpdb->prefix . 'nvx_automation_rules';
		$this->log_table   = $wpdb->prefix . 'nvx_automation_log';
	}

	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$this->rules_table} ORDER BY sort_order ASC, id ASC", ARRAY_A );

		return array_map( array( $this, 'decode_row' ), $rows ?: array() );
	}

	/**
	 * Зберігає новий порядок правил (drag-and-drop у списку автоматизацій).
	 * $ordered_ids — id правил у бажаному порядку (лише в межах одного виду —
	 * ttn або order, — оскільки списки на вкладках окремі; sort_order не
	 * потребує бути унікальним чи безперервним глобально).
	 *
	 * @param int[] $ordered_ids
	 */
	public function reorder( array $ordered_ids ): void {
		global $wpdb;

		$position = 0;
		foreach ( $ordered_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$wpdb->update(
				$this->rules_table,
				array( 'sort_order' => $position ),
				array( 'id' => $id ),
				array( '%d' ),
				array( '%d' )
			);
			++$position;
		}
	}

	/**
	 * Наступне значення sort_order для щойно створеного правила — щоб нове
	 * правило додавалось у КІНЕЦЬ списку, а не на початок (за замовчуванням
	 * у БД sort_order = 0, що інакше означало б "завжди перше").
	 */
	private function next_sort_order(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$max = $wpdb->get_var( "SELECT MAX(sort_order) FROM {$this->rules_table}" );

		return null === $max ? 0 : ( (int) $max + 1 );
	}

	/**
	 * @param string $kind ttn|order
	 * @return list<array<string,mixed>>
	 */
	public function all_by_kind( string $kind ): array {
		$kind = ( 'order' === $kind ) ? 'order' : 'ttn';
		return array_values(
			array_filter(
				$this->all(),
				static function ( $rule ) use ( $kind ) {
					$k = (string) ( $rule['trigger_kind'] ?? 'ttn' );
					if ( '' === $k ) {
						$k = 'ttn';
					}
					return $k === $kind;
				}
			)
		);
	}

	/**
	 * Правила, у яких є тригер «any» або конкретний status_code серед обраних.
	 * trigger_status зберігається як "any" або CSV кодів: "6,9,10".
	 */
	public function enabled_for_status( string $status_code, string $kind = 'ttn' ): array {
		$kind = ( 'order' === $kind ) ? 'order' : 'ttn';
		$all  = array_filter(
			$this->all_by_kind( $kind ),
			static function ( $rule ) {
				return ! empty( $rule['is_enabled'] );
			}
		);

		$matched = array();
		foreach ( $all as $rule ) {
			$raw = (string) ( $rule['trigger_status'] ?? 'any' );
			// «Будь-яка зміна» — не для службових тригерів створення / додавання ТТН.
			if ( 'any' === $raw || '' === $raw ) {
				if ( 'ttn' === $kind && in_array( $status_code, array( 'ttn_created', 'ttn_added' ), true ) ) {
					continue;
				}
				$matched[] = $rule;
				continue;
			}
			$codes = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
			if ( in_array( $status_code, $codes, true ) ) {
				$matched[] = $rule;
			}
		}

		return $matched;
	}

	/**
	 * Збереження правила. trigger_status: 'any' або CSV кодів.
	 *
	 * @throws \RuntimeException
	 */
	public function save( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql' );

		$trigger = $data['trigger_status'] ?? 'any';
		if ( is_array( $trigger ) ) {
			$trigger = implode( ',', array_map( 'sanitize_text_field', $trigger ) );
		}
		$trigger = sanitize_text_field( (string) $trigger );
		if ( '' === $trigger ) {
			$trigger = 'any';
		}

		$kind = sanitize_key( (string) ( $data['trigger_kind'] ?? 'ttn' ) );
		if ( ! in_array( $kind, array( 'ttn', 'order' ), true ) ) {
			$kind = 'ttn';
		}

		$record = array(
			'title'          => sanitize_text_field( $data['title'] ?? '' ),
			'trigger_kind'   => $kind,
			'trigger_status' => $trigger,
			'is_enabled'     => empty( $data['is_enabled'] ) ? 0 : 1,
			'actions'        => wp_json_encode( $data['actions'] ?? array() ),
			'updated_at'     => $now,
		);

		if ( '' === $record['title'] ) {
			$record['title'] = __( 'Без назви', 'wc-nova-express' );
		}

		if ( ! empty( $data['id'] ) ) {
			// sort_order при редагуванні існуючого правила не чіпаємо тут —
			// його змінює лише reorder() (drag-and-drop), не форма збереження.
			$result = $wpdb->update( $this->rules_table, $record, array( 'id' => (int) $data['id'] ) );

			if ( false === $result ) {
				throw new \RuntimeException(
					sprintf(
						__( 'Не вдалося оновити правило в базі даних: %s', 'wc-nova-express' ),
						$wpdb->last_error ?: __( 'невідома помилка БД', 'wc-nova-express' )
					)
				);
			}

			return (int) $data['id'];
		}

		$record['created_at'] = $now;
		$record['sort_order'] = $this->next_sort_order();
		$result               = $wpdb->insert( $this->rules_table, $record );

		if ( false === $result ) {
			throw new \RuntimeException(
				sprintf(
					__( 'Не вдалося створити правило в базі даних: %s', 'wc-nova-express' ),
					$wpdb->last_error ?: __( 'невідома помилка БД', 'wc-nova-express' )
				)
			);
		}

		return (int) $wpdb->insert_id;
	}

	public function set_enabled( int $id, bool $enabled ): void {
		global $wpdb;
		$wpdb->update(
			$this->rules_table,
			array(
				'is_enabled' => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->rules_table, array( 'id' => $id ), array( '%d' ) );
	}

	public function log( int $rule_id, int $order_id, string $waybill_number, string $action_type, string $result, string $message = '' ): void {
		global $wpdb;

		$wpdb->insert(
			$this->log_table,
			array(
				'rule_id'        => $rule_id,
				'order_id'       => $order_id,
				'waybill_number' => $waybill_number,
				'action_type'    => $action_type,
				'result'         => $result,
				'message'        => $message,
				'created_at'     => current_time( 'mysql' ),
			)
		);
	}

	public function clear_log(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$this->log_table}" );
	}

	/**
	 * Прибирає записи журналу старші за $days днів. Раніше журнал ріс
	 * необмежено (єдиний спосіб почистити — ручний TRUNCATE кнопкою),
	 * що на активному магазині з частими автоматизаціями з часом
	 * роздуває таблицю. Викликається щоденним cron (Plugin::boot()).
	 */
	public function prune_old_log( int $days = 90 ): int {
		global $wpdb;

		$days = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$this->log_table} WHERE created_at < %s",
				$cutoff
			)
		);

		return false === $deleted ? 0 : (int) $deleted;
	}

	public function recent_log( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->log_table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
	}

	private function decode_row( array $row ): array {
		$row['actions'] = json_decode( $row['actions'], true ) ?: array();
		if ( empty( $row['trigger_kind'] ) ) {
			$row['trigger_kind'] = 'ttn';
		}
		// Для JS: масив кодів тригера.
		$raw = (string) ( $row['trigger_status'] ?? 'any' );
		if ( 'any' === $raw || '' === $raw ) {
			$row['trigger_statuses'] = array( 'any' );
		} else {
			$row['trigger_statuses'] = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		}

		return $row;
	}
}
