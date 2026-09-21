<?php

namespace NovaExpress\Automation\Action;

use NovaExpress\Helpers\Formatting;

defined( 'ABSPATH' ) || exit;

/**
 * Надсилає email через wp_mail з шаблонами теми/тіла.
 *
 * Повний перелік плейсхолдерів — див. Formatting::apply_order_template()
 * (усі базові плейсхолдери замовлення/клієнта) + подієві {waybill}, {status},
 * {code}, передані нижче через $extra.
 */
class SendEmailAction implements ActionInterface {

	public function run( \WC_Order $order, array $config, array $status_event ): array {
		$to = trim( (string) ( $config['email_to'] ?? '' ) );
		if ( '' === $to ) {
			$to = (string) $order->get_billing_email();
		}

		// Підтримка кількох адрес через кому.
		$to = array_filter( array_map( 'trim', preg_split( '/[,;]+/', $to ) ) );
		$to = array_filter( $to, 'is_email' );

		if ( empty( $to ) ) {
			return array(
				'result'  => 'error',
				'message' => __( 'Немає валідної email-адреси отримувача.', 'wc-nova-express' ),
			);
		}

		$subject_tpl = ! empty( $config['email_subject'] )
			? (string) $config['email_subject']
			: __( 'Статус ТТН №{waybill}: {status}', 'wc-nova-express' );

		$body_tpl = ! empty( $config['email_body'] )
			? (string) $config['email_body']
			: __( "Замовлення №{order_number}\nТТН: {waybill}\nСтатус: {status} (код {code})\nКлієнт: {customer_name}", 'wc-nova-express' );

		$extra = array(
			'waybill' => (string) ( $status_event['waybill_number'] ?? '' ),
			'status'  => (string) ( $status_event['status_text'] ?? '' ),
			'code'    => (string) ( $status_event['status_code'] ?? '' ),
			// Email — єдине місце, де {order_total} традиційно форматувався з
			// символом валюти (get_formatted_order_total включає HTML), а не як
			// голе число, як у базовій мапі Formatting — лишаємо цю поведінку.
			'order_total' => (string) $order->get_formatted_order_total(),
		);

		$subject = Formatting::apply_order_template( $subject_tpl, $order, $extra );
		$body    = Formatting::apply_order_template( $body_tpl, $order, $extra );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Якщо в тілі є HTML-теги — відправляємо як HTML.
		if ( $body !== wp_strip_all_tags( $body ) ) {
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		}

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			return array(
				'result'  => 'error',
				'message' => sprintf(
					/* translators: %s: email list */
					__( 'Не вдалося надіслати лист на %s', 'wc-nova-express' ),
					implode( ', ', $to )
				),
			);
		}

		return array(
			'result'  => 'ok',
			'message' => sprintf(
				/* translators: 1: email list, 2: subject */
				__( 'Лист надіслано на %1$s: %2$s', 'wc-nova-express' ),
				implode( ', ', $to ),
				$subject
			),
		);
	}
}
