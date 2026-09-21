<?php

namespace NovaExpress\Automation\Action;

defined( 'ABSPATH' ) || exit;

class ChangeStatusAction implements ActionInterface {

	/** Статуси, які плагін ніколи не виставляє. */
	private const BLOCKED = array(
		'trash',
		'auto-draft',
		'checkout-draft',
		'draft',
	);

	public function run( \WC_Order $order, array $config, array $status_event ): array {
		$target_status = sanitize_key( (string) ( $config['target_order_status'] ?? '' ) );
		$target_status = 0 === strpos( $target_status, 'wc-' ) ? substr( $target_status, 3 ) : $target_status;

		if ( '' === $target_status ) {
			return array(
				'result'  => 'error',
				'message' => __( 'Не вказано цільовий статус замовлення для дії.', 'wc-nova-express' ),
			);
		}

		if ( in_array( $target_status, self::BLOCKED, true ) ) {
			return array(
				'result'  => 'error',
				'message' => __( 'Заборонено змінювати статус замовлення на «видалено/чернетка». Плагін не переносить замовлення в кошик.', 'wc-nova-express' ),
			);
		}

		// Лише зареєстровані статуси WooCommerce.
		$allowed = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
				$allowed[] = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			}
		}
		if ( $allowed && ! in_array( $target_status, $allowed, true ) ) {
			return array(
				'result'  => 'error',
				'message' => sprintf(
					/* translators: %s: status slug */
					__( 'Невідомий статус замовлення «%s» — зміну скасовано.', 'wc-nova-express' ),
					$target_status
				),
			);
		}

		// Не чіпаємо вже той самий статус.
		if ( $order->get_status() === $target_status ) {
			return array(
				'result'  => 'ok',
				'message' => sprintf( 'order status already %s', $target_status ),
			);
		}

		$order->update_status(
			$target_status,
			sprintf(
				/* translators: %s: waybill carrier status */
				__( 'Nova Express: автоматична зміна статусу за подією перевізника «%s».', 'wc-nova-express' ),
				$status_event['status_text'] ?? ''
			)
		);

		return array(
			'result'  => 'ok',
			'message' => sprintf( 'order status -> %s', $target_status ),
		);
	}
}
