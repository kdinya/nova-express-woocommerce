<?php

namespace NovaExpress\Automation\Action;

use NovaExpress\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * JSON POST на URL з правила або глобальний webhook_url.
 *
 * Тіло містить:
 * 1) структуровані блоки event / waybill / order / recipient / sender / shipping (опційно);
 * 2) плоскі ключі n_p_* — лише ті, що обрані галочками в правилі.
 */
class SendWebhookAction implements ActionInterface {

	/** @var array<string, string> Ключ => підпис для UI */
	public static function available_flat_fields(): array {
		return array(
			'n_p_status'          => 'n_p_status — код статусу ТТН',
			'n_p_info'            => 'n_p_info — опис відправлення',
			'n_p_name'            => 'n_p_name — ПІБ отримувача',
			'n_p_number'          => 'n_p_number — телефон отримувача',
			'n_p_number_my'       => 'n_p_number_my — телефон відправника',
			'n_p_ttn'             => 'n_p_ttn — номер ТТН',
			'n_p_status_dostavki' => 'n_p_status_dostavki — текстовий статус',
			'n_p_primechanie'     => 'n_p_primechanie — додаткова інформація',
			'n_p_order'           => 'n_p_order — номер замовлення',
			'n_p_order_id'        => 'n_p_order_id — ID замовлення',
			'n_p_order_status'    => 'n_p_order_status — статус замовлення WC',
			'n_p_order_total'     => 'n_p_order_total — сума замовлення',
			'n_p_city'            => 'n_p_city — місто',
			'n_p_warehouse'       => 'n_p_warehouse — відділення',
			'n_p_email'           => 'n_p_email — email клієнта',
			'n_p_event'           => 'n_p_event — тип події',
			'n_p_event_id'        => 'n_p_event_id — унікальний ID події',
		);
	}

	public function run( \WC_Order $order, array $config, array $status_event ): array {
		$url = ! empty( $config['webhook_url'] ) ? trim( $config['webhook_url'] ) : '';

		if ( '' === $url && class_exists( Settings::class ) ) {
			$settings = Settings::get_all();
			$url      = (string) ( $settings['webhook_url'] ?? '' );
		}

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return array(
				'result'  => 'error',
				'message' => __( 'Некоректний або відсутній URL вебхука.', 'wc-nova-express' ),
			);
		}

		$mode = sanitize_key( (string) ( $config['payload_mode'] ?? 'data' ) );
		if ( 'sms' !== $mode ) {
			$mode = 'data';
		}

		// 'get' (за замовчуванням, зберігає поточну поведінку для вже
		// налаштованих правил) — плоскі n_p_* як query-параметри, як і
		// раніше (потрібно для MacroDroid та подібних інструментів, що
		// вміють читати лише query string). 'post' — новий, явно обраний
		// адміном варіант: повний payload (структурований блок + плоскі
		// поля) єдиним JSON POST — рекомендовано для звичайних вебхук-
		// приймачів (Zapier/Make/n8n/власний сервер), оскільки не лишає
		// персональні дані (ПІБ, телефон, email) в URL, який осідає в
		// логах проксі/веб-серверів.
		$delivery = sanitize_key( (string) ( $config['delivery_method'] ?? 'get' ) );
		if ( ! in_array( $delivery, array( 'get', 'post' ), true ) ) {
			$delivery = 'get';
		}
		// SMS-режим існує саме для MacroDroid-подібних тригерів на query
		// string — це і є весь сенс режиму, тому лишається GET завжди.
		if ( 'sms' === $mode ) {
			$delivery = 'get';
		}

		if ( 'post' === $delivery ) {
			$payload = $this->build_payload( $order, $status_event, $config );

			// wp_safe_remote_post() (на відміну від wp_remote_post()) блокує
			// запити на приватні/зарезервовані IP (localhost, 169.254.169.254,
			// 10.x/172.16.x/192.168.x тощо) — захист від SSRF через URL
			// вебхука, який адмін міг вставити з чужого/скомпрометованого джерела.
			$response = wp_safe_remote_post(
				$url,
				array(
					'timeout'     => 20,
					'headers'     => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json, text/plain, */*',
					),
					'body'        => wp_json_encode( $payload ),
					'redirection' => 3,
				)
			);
		} else {
			$params    = $this->build_query_params( $order, $status_event, $config, $mode );
			$final_url = $this->append_query_params( $url, $params );

			// MacroDroid (і подібні) читають змінні з query string — GET надійніший.
			$response = wp_safe_remote_get(
				$final_url,
				array(
					'timeout'     => 20,
					'headers'     => array(
						'Accept' => 'application/json, text/plain, */*',
					),
					'redirection' => 3,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return array(
				'result'  => 'error',
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'result'  => 'error',
				'message' => sprintf( 'HTTP %d: %s', $code, wp_remote_retrieve_body( $response ) ),
			);
		}

		return array(
			'result'  => 'ok',
			'message' => sprintf( 'HTTP %d', $code ),
		);
	}

	/**
	 * Параметри для query string (лише скаляри).
	 *
	 * @return array<string,string>
	 */
	private function build_query_params( \WC_Order $order, array $status_event, array $config, string $mode ): array {
		if ( 'sms' === $mode ) {
			$sms_text = $this->build_sms_text( $order, $status_event, $config );
			$phone    = (string) ( $order->get_billing_phone() ?: $order->get_meta( '_nvx_phone' ) );
			if ( class_exists( '\NovaExpress\Helpers\Formatting' ) ) {
				$phone = \NovaExpress\Helpers\Formatting::normalize_phone( $phone );
			}
			return array(
				'n_p_sms'    => $sms_text,
				'n_p_number' => $phone,
			);
		}

		// Режим «дані»: лише вибрані плоскі n_p_* (структуровані вкладені блоки в URL не передаємо).
		$full = $this->build_payload( $order, $status_event, array_merge( $config, array( 'include_structured' => '0' ) ) );
		$params = array();
		foreach ( $full as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$params[ (string) $key ] = (string) $value;
		}
		return $params;
	}

	/**
	 * Додає query-параметри до базового URL (зберігає існуючі).
	 *
	 * @param array<string,string> $params
	 */
	private function append_query_params( string $url, array $params ): string {
		if ( empty( $params ) ) {
			return $url;
		}
		// add_query_arg коректно url-encode значення.
		return add_query_arg( $params, $url );
	}

	/**
	 * Тіло вебхука з урахуванням обраних галочками полів.
	 *
	 * @param array $status_event Дані з RuleEngine / TrackingRunner.
	 * @param array $config       Конфіг дії (webhook_url, fields, include_structured).
	 */
	private function build_payload( \WC_Order $order, array $status_event, array $config = array() ): array {
		$settings = class_exists( Settings::class ) ? Settings::get_all() : array();

		$ttn_number   = (string) ( $status_event['waybill_number'] ?? $order->get_meta( '_nvx_waybill_number' ) );
		$status_code  = (string) ( $status_event['status_code'] ?? '' );
		$status_text  = (string) ( $status_event['status_text'] ?? '' );
		$prev_code    = (string) ( $status_event['previous_status_code'] ?? '' );
		$event_type   = (string) ( $status_event['event'] ?? 'status_changed' );

		$recipient_name = trim(
			( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) . ' ' .
			( $order->get_shipping_first_name() ?: $order->get_billing_first_name() )
		);
		$recipient_phone = (string) ( $order->get_billing_phone() ?: $order->get_meta( '_nvx_phone' ) );
		$sender_phone    = (string) ( $settings['sender_phone'] ?? '' );

		// Опис / додаткова інформація — з шаблонів налаштувань (як при створенні ТТН).
		$description = '';
		$additional  = '';
		if ( class_exists( '\NovaExpress\Helpers\Formatting' ) ) {
			$description = \NovaExpress\Helpers\Formatting::apply_order_template(
				(string) ( $settings['description_template'] ?? '' ) ?: __( 'Замовлення №{order_number}', 'wc-nova-express' ),
				$order
			);
			$additional = \NovaExpress\Helpers\Formatting::apply_order_template(
				(string) ( $settings['additional_info_template'] ?? '' ),
				$order
			);
		}

		$city_name       = (string) ( $order->get_meta( '_nvx_city_name' ) ?: $order->get_shipping_city() );
		$warehouse_label = (string) $order->get_meta( '_nvx_warehouse_label' );
		$warehouse_ref   = (string) $order->get_meta( '_nvx_warehouse_ref' );
		$city_ref        = (string) $order->get_meta( '_nvx_city_ref' );
		$service_type    = (string) ( $order->get_meta( '_nvx_service_type' ) ?: 'warehouse_warehouse' );

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'name'       => $item->get_name(),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'product_id' => $item->get_product_id(),
				'quantity'   => $item->get_quantity(),
				'total'      => (float) $item->get_total(),
			);
		}

		$event_id = hash(
			'sha256',
			implode(
				'|',
				array(
					(string) $order->get_id(),
					$ttn_number,
					$prev_code,
					$status_code,
					$event_type,
				)
			)
		);

		// Які плоскі ключі передавати (порожньо / відсутнє = усі).
		$all_keys = array_keys( self::available_flat_fields() );
		// Якщо fields не задано взагалі — за замовчуванням усі ключі.
		// Якщо задано порожній масив — не передаємо жодного плоского ключа.
		if ( ! array_key_exists( 'fields', $config ) || null === $config['fields'] || '' === $config['fields'] ) {
			$selected = $all_keys;
		} else {
			$selected = $this->parse_selected_fields( $config );
		}

		// --- Структурований блок (опційно) ---
		$include_structured = ! isset( $config['include_structured'] )
			|| (string) $config['include_structured'] === '1'
			|| $config['include_structured'] === true
			|| $config['include_structured'] === 1;

		$payload = array();

		if ( $include_structured ) {
			$payload = array(
				'event'    => 'nvx.ttn_status_changed',
				'event_id' => $event_id,
				'sent_at'  => gmdate( 'c' ),
				'waybill'  => array(
					'number'               => $ttn_number,
					'status_code'          => $status_code,
					'status_text'          => $status_text,
					'previous_status_code' => $prev_code,
					'is_delivered'         => ! empty( $status_event['is_delivered'] ),
					'service_type'         => $service_type,
					'description'          => $description,
					'additional_info'      => $additional,
				),
				'order'    => array(
					'id'            => $order->get_id(),
					'number'        => $order->get_order_number(),
					'status'        => $order->get_status(),
					'total'         => (float) $order->get_total(),
					'currency'      => $order->get_currency(),
					'payment'       => $order->get_payment_method(),
					'payment_title' => $order->get_payment_method_title(),
					'created_at'    => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : '',
					'items'         => $items,
				),
				'recipient' => array(
					'name'       => $recipient_name,
					'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
					'last_name'  => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
					'phone'      => $recipient_phone,
					'email'      => $order->get_billing_email(),
				),
				'sender'   => array(
					'phone' => $sender_phone,
					'name'  => trim( ( $settings['sender_first_name'] ?? '' ) . ' ' . ( $settings['sender_last_name'] ?? '' ) ),
				),
				'shipping' => array(
					'city_name'       => $city_name,
					'city_ref'        => $city_ref,
					'warehouse_label' => $warehouse_label,
					'warehouse_ref'   => $warehouse_ref,
					'service_type'    => $service_type,
				),
			);
		}

		// --- Плоскі ключі n_p_* (лише обрані) ---
		$all_flat = array(
			'n_p_status'          => $status_code,
			'n_p_info'            => $description,
			'n_p_name'            => $recipient_name,
			'n_p_number'          => $recipient_phone,
			'n_p_number_my'       => $sender_phone,
			'n_p_ttn'             => $ttn_number,
			'n_p_status_dostavki' => $status_text,
			'n_p_primechanie'     => $additional,
			'n_p_order'           => (string) $order->get_order_number(),
			'n_p_order_id'        => (string) $order->get_id(),
			'n_p_order_status'    => $order->get_status(),
			'n_p_order_total'     => (string) $order->get_total(),
			'n_p_city'            => $city_name,
			'n_p_warehouse'       => $warehouse_label,
			'n_p_email'           => $order->get_billing_email(),
			'n_p_event'           => $event_type,
			'n_p_event_id'        => $event_id,
		);

		foreach ( $selected as $key ) {
			if ( array_key_exists( $key, $all_flat ) ) {
				$payload[ $key ] = $all_flat[ $key ];
			}
		}

		return $payload;
	}

	/**
	 * @return string[]
	 */

	/**
	 * Готовий текст SMS з шаблону правила.
	 */
	private function build_sms_text( \WC_Order $order, array $status_event, array $config ): string {
		$template = trim( (string) ( $config['sms_template'] ?? '' ) );
		if ( '' === $template ) {
			$template = __( 'Замовлення №{order_number}: ТТН {waybill}, статус «{status}».', 'wc-nova-express' );
		}

		$settings = class_exists( Settings::class ) ? Settings::get_all() : array();

		$description = '';
		$additional  = '';
		if ( class_exists( '\NovaExpress\Helpers\Formatting' ) ) {
			$description = \NovaExpress\Helpers\Formatting::apply_order_template(
				(string) ( $settings['description_template'] ?? '' ),
				$order
			);
			$additional = \NovaExpress\Helpers\Formatting::resolve_additional_info(
				(string) ( $settings['additional_info_template'] ?? '' ),
				$order,
				(string) ( $settings['additional_info_contains'] ?? '' )
			);
		}

		// {waybill}/{status}/{code} — стан конкретної події трекінгу (не поле
		// замовлення, тому не може бути в базовій мапі Formatting).
		// {description}/{additional_info}/{info} — уже готовий, обчислений вище
		// текст (сам може містити свої плейсхолдери — навмисно НЕ рекурсивний).
		// Решта плейсхолдерів (order_number, order_id, customer_*, order_total,
		// order_date, city_name, warehouse тощо) — з базової мапи Formatting,
		// без дублювання тут.
		return \NovaExpress\Helpers\Formatting::apply_order_template(
			$template,
			$order,
			array(
				'waybill'         => (string) ( $status_event['waybill_number'] ?? $order->get_meta( '_nvx_waybill_number' ) ),
				'status'          => (string) ( $status_event['status_text'] ?? '' ),
				'code'            => (string) ( $status_event['status_code'] ?? '' ),
				'description'     => $description,
				'additional_info' => $additional,
				'info'            => $additional,
			)
		);
	}

	private function parse_selected_fields( array $config ): array {
		$raw = $config['fields'] ?? '';

		if ( is_array( $raw ) ) {
			return array_values( array_filter( array_map( 'strval', $raw ) ) );
		}

		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}

		// Підтримка JSON-масиву або CSV.
		if ( '[' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'strval', $decoded ) ) );
			}
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}
}
