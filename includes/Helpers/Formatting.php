<?php

namespace NovaExpress\Helpers;

defined( 'ABSPATH' ) || exit;

class Formatting {

	/**
	 * Nova Poshta приймає телефон рівно у форматі "380XXXXXXXXX" (12 цифр,
	 * без "+", пробілів чи дужок). WooCommerce зберігає телефон як увів
	 * покупець ("+38 (068) 331-23-81" тощо) — нормалізуємо перед відправкою.
	 */
	public static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );

		if ( '' === $digits ) {
			return '';
		}

		// 0XXXXXXXXX (10 цифр, локальний формат) → додаємо код країни.
		if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			return '38' . $digits;
		}

		// XXXXXXXXX (9 цифр, без коду оператора-нуля) → малоймовірно, але про всяк випадок.
		if ( 9 === strlen( $digits ) ) {
			return '380' . $digits;
		}

		// 380XXXXXXXXX (12 цифр) — уже коректний формат.
		if ( 12 === strlen( $digits ) && '380' === substr( $digits, 0, 3 ) ) {
			return $digits;
		}

		// 80XXXXXXXXX (11 цифр, код країни без початкової 3) — нетиповий, але приведемо.
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			return '3' . $digits;
		}

		return $digits;
	}

	/**
	 * Підставляє динамічні плейсхолдери в шаблон (опис/додаткова інформація
	 * відправлення, нотатка, тема/тіло email, текст SMS — усі йдуть через
	 * ЦЕЙ метод, щоб один і той самий набір плейсхолдерів однаково працював
	 * УСЮДИ, а не лише там, де конкретна дія випадково продублювала свою
	 * власну мапу).
	 *
	 * Базові плейсхолдери (доступні завжди, обчислюються з $order):
	 *   {order_number}, {order_id}, {order_status}, {order_total}, {payment_method},
	 *   {currency}, {order_date}, {site_name}, {customer_name}, {customer_first_name},
	 *   {customer_last_name}, {customer_email}, {phone}, {date}, {items_count},
	 *   {city_name}, {warehouse}, {total} (застарілий синонім {order_total}, лишений
	 *   для сумісності з уже збереженими шаблонами).
	 *
	 * $extra — значення, яких немає в самому замовленні (наприклад, {waybill},
	 * {status}, {code} — це стан конкретної події трекінгу, а не поле замовлення).
	 * Ключі в $extra передаються БЕЗ фігурних дужок, напр. array('waybill' => '123').
	 *
	 * Довільне поле замовлення:
	 *   {meta:ключ} — наприклад {meta:vchasno_kasa_receipt_url} або {meta:_billing_company}
	 *   {ключ}     — те саме коротко, якщо ключ не збігається зі стандартним плейсхолдером.
	 *
	 * @param array<string,string> $extra Додаткові плейсхолдери без фігурних дужок у ключах.
	 */
	public static function apply_order_template( string $template, \WC_Order $order, array $extra = array() ): string {
		if ( '' === $template ) {
			return '';
		}

		$replacements = array(
			'{order_number}'        => $order->get_order_number(),
			'{order_id}'            => (string) $order->get_id(),
			'{order_status}'        => function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status(),
			'{order_total}'         => (string) $order->get_total(),
			'{payment_method}'      => (string) $order->get_payment_method_title(),
			'{currency}'            => (string) $order->get_currency(),
			'{order_date}'          => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd.m.Y' ) : '',
			'{site_name}'           => get_bloginfo( 'name' ),
			'{customer_name}'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'{customer_first_name}' => $order->get_billing_first_name(),
			'{customer_last_name}'  => $order->get_billing_last_name(),
			'{customer_email}'      => (string) $order->get_billing_email(),
			'{phone}'               => $order->get_billing_phone(),
			'{email}'               => $order->get_billing_email(),
			'{date}'                => date_i18n( 'd.m.Y' ),
			'{items_count}'         => (string) $order->get_item_count(),
			'{city_name}'           => (string) ( $order->get_meta( '_nvx_city_name' ) ?: $order->get_shipping_city() ),
			'{warehouse}'           => (string) $order->get_meta( '_nvx_warehouse_label' ),
			// {total} — застарілий синонім {order_total}; лишено, щоб не ламати
			// вже збережені шаблони з попередніх версій плагіна.
			'{total}'               => (string) $order->get_total(),
		);

		foreach ( $extra as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = (string) $value;
		}

		$result = strtr( $template, $replacements );

		// {meta:ключ} — явний синтаксис для мета-даних замовлення.
		$result = preg_replace_callback(
			'/\{meta:([a-zA-Z0-9_\-\/]+)\}/',
			static function ( $matches ) use ( $order ) {
				$value = $order->get_meta( $matches[1] );

				return is_scalar( $value ) ? (string) $value : '';
			},
			$result
		);

		// {довільний_ключ} — якщо лишились фігурні дужки, пробуємо як meta замовлення.
		$result = preg_replace_callback(
			'/\{([a-zA-Z0-9_\-\/]+)\}/',
			static function ( $matches ) use ( $order ) {
				$key = $matches[1];
				// Не чіпаємо службові/вже відомі плейсхолдери статусів тощо.
				$reserved = array(
					'waybill', 'status', 'code', 'order_number', 'order_id', 'order_status',
					'order_total', 'payment_method', 'currency', 'order_date', 'site_name',
					'customer_name', 'customer_first_name', 'customer_last_name', 'customer_email',
					'total', 'date', 'items_count', 'phone', 'email', 'city_name', 'warehouse',
					'description', 'additional_info', 'info',
				);
				if ( in_array( $key, $reserved, true ) ) {
					return $matches[0];
				}
				$value = $order->get_meta( $key );
				if ( ( '' === $value || null === $value ) && 0 !== strpos( $key, '_' ) ) {
					$value = $order->get_meta( '_' . $key );
				}
				return is_scalar( $value ) && '' !== (string) $value ? (string) $value : '';
			},
			$result
		);

		return $result;
	}

	/**
	 * Резолвить шаблон додаткової інформації та застосовує фільтр «містить».
	 * Якщо $contains непорожній і готовий текст його не містить — повертає порожній рядок.
	 */
	public static function resolve_additional_info( string $template, \WC_Order $order, string $contains = '' ): string {
		$text = self::apply_order_template( $template, $order );
		$contains = trim( $contains );

		if ( '' === $text ) {
			return '';
		}

		if ( '' !== $contains ) {
			if ( function_exists( 'mb_stripos' ) ) {
				$found = false !== mb_stripos( $text, $contains );
			} else {
				$found = false !== stripos( $text, $contains );
			}
			if ( ! $found ) {
				return '';
			}
		}

		return $text;
	}

	/**
	 * Повертає очищену від HTML та HTML-сутностей (наприклад, &nbsp;) суму замовлення.
	 */
	public static function clean_order_total( \WC_Order $order ): string {
		$raw = (string) $order->get_formatted_order_total();
		$stripped = wp_strip_all_tags( $raw );
		$decoded = html_entity_decode( $stripped, ENT_QUOTES, "UTF-8" );
		return trim( preg_replace( "/\x{00A0}|\s+/u", " ", $decoded ) );
	}

	/**
	 * Отримує дату та час зміни статусу за даними Нової Пошти (DateScan, RecipientDateTime, TrackingUpdateDate)
	 * або час останнього опитування/оновлення.
	 */
	public static function format_ttn_status_time( array $row ): string {
		$time_str = '';
		if ( ! empty( $row['tracking_details'] ) ) {
			$details = is_array( $row['tracking_details'] )
				? $row['tracking_details']
				: json_decode( (string) $row['tracking_details'], true );
			if ( is_array( $details ) ) {
				// Якщо накладна отримана — найточніший час отримання в RecipientDateTime
				if ( ! empty( $details['RecipientDateTime'] ) ) {
					$time_str = (string) $details['RecipientDateTime'];
				} elseif ( ! empty( $details['DateScan'] ) ) {
					$time_str = (string) $details['DateScan'];
				} elseif ( ! empty( $details['ActualDeliveryDate'] ) ) {
					$time_str = (string) $details['ActualDeliveryDate'];
				} elseif ( ! empty( $details['TrackingUpdateDate'] ) ) {
					$time_str = (string) $details['TrackingUpdateDate'];
				} elseif ( ! empty( $details['DateCreated'] ) ) {
					$time_str = (string) $details['DateCreated'];
				}
			}
		}

		if ( '' === $time_str ) {
			$time_str = ! empty( $row['last_polled_at'] ) ? (string) $row['last_polled_at'] : (string) ( $row['updated_at'] ?? '' );
		}

		if ( '' === $time_str ) {
			return '';
		}

		$time_str = trim( $time_str );

		// Дата та час від Нової Пошти (DateScan, RecipientDateTime, TrackingUpdateDate)
		// вже передаються у часовому поясі України — форматуємо без повторного зсуву.
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2})(?::\d{2})?/', $time_str, $m ) ) {
			return $m[3] . '.' . $m[2] . '.' . $m[1] . ' ' . $m[4];
		}
		if ( preg_match( '/^(\d{2}\.\d{2}\.\d{4})\s+(\d{2}:\d{2})(?::\d{2})?/', $time_str, $m ) ) {
			return $m[1] . ' ' . $m[2];
		}

		$ts = strtotime( $time_str );
		if ( false !== $ts && $ts > 0 ) {
			return wp_date( 'd.m.Y H:i', $ts );
		}

		return $time_str;
	}

	/**
	 * Форматує статус у вигляді: [Код] Назва статусу (час зміни)
	 */
	public static function format_ttn_status_display( array $row, bool $include_time = true ): string {
		$code = ! empty( $row['carrier_status_code'] ) ? (string) $row['carrier_status_code'] : '';
		$text = ! empty( $row['carrier_status_text'] ) ? (string) $row['carrier_status_text'] : '';

		if ( '' === $text && '' === $code ) {
			return __( 'Очікує опитування', 'wc-nova-express' );
		}

		if ( '' === $text ) {
			$text = $code;
		}

		$prefix = '' !== $code ? '[' . $code . '] ' : '';
		$suffix = '';
		if ( $include_time ) {
			$time   = self::format_ttn_status_time( $row );
			$suffix = '' !== $time ? ' (' . $time . ')' : '';
		}

		return $prefix . $text . $suffix;
	}
}
