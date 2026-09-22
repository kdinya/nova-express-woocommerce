<?php

namespace NovaExpress\Ajax;

use NovaExpress\Automation\RuleRepository;

defined( 'ABSPATH' ) || exit;

class AutomationAjax {

	private RuleRepository $repository;

	public function __construct( RuleRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_action( 'wp_ajax_nvx_save_rule', array( $this, 'save_rule' ) );
		add_action( 'wp_ajax_nvx_delete_rule', array( $this, 'delete_rule' ) );
		add_action( 'wp_ajax_nvx_toggle_rule', array( $this, 'toggle_rule' ) );
		add_action( 'wp_ajax_nvx_reorder_rules', array( $this, 'reorder_rules' ) );
		add_action( 'wp_ajax_nvx_clear_automation_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_nvx_test_webhook', array( $this, 'test_webhook' ) );
		add_action( 'wp_ajax_nvx_test_email', array( $this, 'test_email' ) );
	}

	/**
	 * Drag-and-drop у списку правил: зберігає новий порядок. Приймає масив id
	 * у бажаному порядку (лише ті, що вже збережені — id > 0).
	 */
	public function reorder_rules(): void {
		$this->guard();

		$raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '[]';
		$ids = json_decode( is_string( $raw ) ? $raw : wp_json_encode( $raw ), true );

		if ( ! is_array( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Некоректний формат порядку правил.', 'wc-nova-express' ) ), 400 );
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ), static function ( $id ) {
			return $id > 0;
		} ) );

		try {
			$this->repository->reorder( $ids );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success();
	}

	public function save_rule(): void {
		$this->guard();

		$raw_actions = isset( $_POST['actions'] ) ? wp_unslash( $_POST['actions'] ) : '[]';
		$actions     = json_decode( $raw_actions, true );

		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		$sanitized_actions = array();
		foreach ( $actions as $action ) {
			if ( empty( $action['type'] ) ) {
				continue;
			}

			$clean = array( 'type' => sanitize_key( $action['type'] ) );

			// Нотатка.
			if ( isset( $action['note_template'] ) ) {
				$clean['note_template'] = sanitize_textarea_field( $action['note_template'] );
			}

			// Статус замовлення.
			if ( isset( $action['target_order_status'] ) ) {
				$clean['target_order_status'] = sanitize_text_field( $action['target_order_status'] );
			}

			// Вебхук.
			if ( isset( $action['webhook_url'] ) ) {
				$clean['webhook_url'] = esc_url_raw( $action['webhook_url'] );
			}
			if ( isset( $action['payload_mode'] ) ) {
				$mode = sanitize_key( $action['payload_mode'] );
				$clean['payload_mode'] = in_array( $mode, array( 'data', 'sms' ), true ) ? $mode : 'data';
			}
			if ( isset( $action['delivery_method'] ) ) {
				$delivery = sanitize_key( $action['delivery_method'] );
				$clean['delivery_method'] = in_array( $delivery, array( 'get', 'post' ), true ) ? $delivery : 'get';
			}
			if ( isset( $action['include_structured'] ) ) {
				$clean['include_structured'] = ( '1' === (string) $action['include_structured'] || true === $action['include_structured'] || 1 === $action['include_structured'] ) ? '1' : '0';
			}
			if ( isset( $action['fields'] ) ) {
				$clean['fields'] = $this->sanitize_fields( $action['fields'] );
			}
			if ( isset( $action['sms_template'] ) ) {
				$clean['sms_template'] = sanitize_textarea_field( $action['sms_template'] );
			}

			// Email.
			if ( isset( $action['email_to'] ) ) {
				$clean['email_to'] = sanitize_text_field( $action['email_to'] );
			}
			if ( isset( $action['email_subject'] ) ) {
				$clean['email_subject'] = sanitize_text_field( $action['email_subject'] );
			}
			if ( isset( $action['email_body'] ) ) {
				$clean['email_body'] = sanitize_textarea_field( $action['email_body'] );
			}

			$sanitized_actions[] = $clean;
		}

		try {
			$id = $this->repository->save(
				array(
					'id'             => isset( $_POST['id'] ) ? (int) $_POST['id'] : 0,
					'title'          => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ),
					'trigger_kind'   => sanitize_key( wp_unslash( $_POST['trigger_kind'] ?? 'ttn' ) ),
					'trigger_status' => ( static function () {
						$raw = isset( $_POST['trigger_status'] ) ? wp_unslash( $_POST['trigger_status'] ) : 'any';
						if ( is_array( $raw ) ) {
							return implode( ',', array_map( 'sanitize_text_field', $raw ) );
						}
						return sanitize_text_field( (string) $raw );
					} )(),
					'is_enabled'     => ! empty( $_POST['is_enabled'] ),
					'actions'        => $sanitized_actions,
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * @param mixed $raw
	 * @return list<string>
	 */
	private function sanitize_fields( $raw ): array {
		if ( is_array( $raw ) ) {
			$keys = $raw;
		} else {
			$raw = trim( (string) $raw );
			if ( '' === $raw ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			$keys    = is_array( $decoded ) ? $decoded : array_map( 'trim', explode( ',', $raw ) );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $k ) {
							$k = (string) $k;
							$k = preg_replace( '/[^a-zA-Z0-9_]/', '', $k );
							return $k;
						},
						$keys
					)
				)
			)
		);
	}

	public function clear_log(): void {
		$this->guard();
		try {
			$this->repository->clear_log();
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
		wp_send_json_success();
	}

	public function toggle_rule(): void {
		$this->guard();

		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$enabled = ! empty( $_POST['is_enabled'] );

		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Немає ID правила.', 'wc-nova-express' ) ), 400 );
		}

		try {
			$this->repository->set_enabled( $id, $enabled );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success(
			array(
				'id'         => $id,
				'is_enabled' => $enabled ? 1 : 0,
			)
		);
	}

	public function delete_rule(): void {
		$this->guard();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id > 0 ) {
			try {
				$this->repository->delete( $id );
			} catch ( \Throwable $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
			}
		}

		wp_send_json_success();
	}


	public function test_webhook(): void {
		$this->guard();

		$url = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			if ( class_exists( '\NovaExpress\Admin\Settings' ) ) {
				$settings = \NovaExpress\Admin\Settings::get_all();
				$url      = (string) ( $settings['webhook_url'] ?? '' );
			}
		}
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			wp_send_json_error( array( 'message' => __( 'Некоректний або відсутній URL вебхука.', 'wc-nova-express' ) ), 400 );
		}

		$mode = isset( $_POST['payload_mode'] ) ? sanitize_key( wp_unslash( $_POST['payload_mode'] ) ) : 'data';
		$delivery = isset( $_POST['delivery_method'] ) ? sanitize_key( wp_unslash( $_POST['delivery_method'] ) ) : 'get';
		if ( ! in_array( $delivery, array( 'get', 'post' ), true ) ) {
			$delivery = 'get';
		}
		// SMS — завжди GET (так влаштований сам MacroDroid-тригер), незалежно
		// від того, що обрано в перемикачі способу надсилання.
		if ( 'sms' === $mode ) {
			$delivery = 'get';
		}
		$params = array();

		if ( 'sms' === $mode ) {
			$params['n_p_sms']    = isset( $_POST['n_p_sms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['n_p_sms'] ) ) : ( isset( $_POST['sms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['sms'] ) ) : 'Nova Express test SMS' );
			$params['n_p_number'] = isset( $_POST['n_p_number'] ) ? sanitize_text_field( wp_unslash( $_POST['n_p_number'] ) ) : ( isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '380991112233' );
		} else {
			$raw = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '{}';
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					if ( is_scalar( $v ) ) {
						$key = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $k );
						if ( '' !== $key ) {
							$params[ $key ] = (string) $v;
						}
					}
				}
			}
			if ( empty( $params ) ) {
				$params = array(
					'n_p_ttn'             => '20450123456789',
					'n_p_order'           => '1234',
					'n_p_status'          => '1',
					'n_p_status_dostavki' => 'Тест',
					'n_p_name'            => 'Тест Клієнт',
				);
			}
		}

		if ( 'post' === $delivery ) {
			// Той самий транспорт, що й у бойовому SendWebhookAction: JSON у тілі,
			// без жодних даних в URL — щоб тест реально перевіряв те, що обрано.
			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout'     => 20,
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json, text/plain, */*',
					),
					'body'        => wp_json_encode( $params ),
					'redirection' => 3,
				)
			);
			$final_url = $url;
		} else {
			$final_url = add_query_arg( $params, $url );

			// wp_safe_remote_get() блокує запити на приватні/зарезервовані IP —
			// той самий SSRF-захист, що й у бойовому SendWebhookAction.
			$response = wp_safe_remote_get(
				$final_url,
				array(
					'timeout'     => 20,
					'redirection' => 3,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message(), 'url' => $final_url ), 500 );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_send_json_error(
				array(
					'message' => sprintf( 'HTTP %d: %s', $code, mb_substr( (string) $body, 0, 300 ) ),
					'code'    => $code,
					'url'     => $final_url,
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => sprintf( 'HTTP %d OK', $code ),
				'code'    => $code,
				'url'     => $final_url,
			)
		);
	}

	public function test_email(): void {
		$this->guard();

		$raw_to = isset( $_POST['email_to'] ) ? sanitize_text_field( wp_unslash( $_POST['email_to'] ) ) : '';
		$to_list = array_filter( array_map( 'trim', preg_split( '/[,;]+/', $raw_to ) ) );
		$to_list = array_filter( $to_list, 'is_email' );

		// Якщо поле "Кому" порожнє — надсилаємо на email поточного користувача або адміна сайту.
		if ( empty( $to_list ) ) {
			$current_user = wp_get_current_user();
			$fallback = ( $current_user && ! empty( $current_user->user_email ) )
				? $current_user->user_email
				: (string) get_option( 'admin_email' );

			if ( is_email( $fallback ) ) {
				$to_list = array( $fallback );
			}
		}

		if ( empty( $to_list ) ) {
			wp_send_json_error( array( 'message' => __( 'Не вказано коректну email-адресу для тесту.', 'wc-nova-express' ) ), 400 );
		}

		$subject_tpl = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		if ( '' === $subject_tpl ) {
			$subject_tpl = __( 'Статус ТТН №{waybill}: {status}', 'wc-nova-express' );
		}

		$body_tpl = isset( $_POST['email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['email_body'] ) ) : '';
		if ( '' === $body_tpl ) {
			$body_tpl = __( "Замовлення №{order_number}\nТТН: {waybill}\nСтатус: {status} (код {code})\nКлієнт: {customer_name}", 'wc-nova-express' );
		}

		$replacements = array(
			'{waybill}'         => '20450123456789',
			'{status}'          => __( 'Прибув у відділення', 'wc-nova-express' ),
			'{code}'            => '7',
			'{order_number}'    => '1024',
			'{order_id}'        => '1024',
			'{order_status}'    => __( 'В обробці', 'wc-nova-express' ),
			'{customer_name}'   => __( 'Тест Клієнт', 'wc-nova-express' ),
			'{customer_email}'  => implode( ', ', $to_list ),
			'{customer_phone}'  => '+380501234567',
			'{order_total}'     => '1 250 грн',
			'{payment_method}'  => __( 'Післяплата', 'wc-nova-express' ),
			'{currency}'        => 'UAH',
			'{order_date}'      => date_i18n( 'd.m.Y H:i' ),
			'{city_name}'       => __( 'Київ', 'wc-nova-express' ),
			'{warehouse}'       => __( 'Відділення №1: вул. Пирогівський шлях, 135', 'wc-nova-express' ),
		);

		$subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $subject_tpl );
		$body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $body_tpl );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $body !== wp_strip_all_tags( $body ) ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		}

		$sent = wp_mail( $to_list, '[TEST] ' . $subject, $body, $headers );

		if ( ! $sent ) {
			wp_send_json_error( array(
				'message' => sprintf(
					__( 'wp_mail() не зміг надіслати лист на %s. Перевірте поштові налаштування сервера/SMTP.', 'wc-nova-express' ),
					implode( ', ', $to_list )
				),
			), 500 );
		}

		wp_send_json_success( array(
			'message' => sprintf(
				__( 'Тестовий лист надіслано на %s', 'wc-nova-express' ),
				implode( ', ', $to_list )
			),
			'to'      => implode( ', ', $to_list ),
			'subject' => '[TEST] ' . $subject,
		) );
	}

	private function guard(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}
	}
}
