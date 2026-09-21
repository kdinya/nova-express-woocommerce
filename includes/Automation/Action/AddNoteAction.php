<?php

namespace NovaExpress\Automation\Action;

use NovaExpress\Helpers\Formatting;

defined( 'ABSPATH' ) || exit;

class AddNoteAction implements ActionInterface {

	public function run( \WC_Order $order, array $config, array $status_event ): array {
		$template = ! empty( $config['note_template'] )
			? $config['note_template']
			: __( 'Nova Express Woo: статус ТТН №{waybill} змінено на «{status}».', 'wc-nova-express' );

		$note = Formatting::apply_order_template(
			$template,
			$order,
			array(
				'waybill' => $status_event['waybill_number'] ?? '',
				'status'  => $status_event['status_text'] ?? '',
				'code'    => $status_event['status_code'] ?? '',
			)
		);

		$order->add_order_note( $note );

		return array(
			'result'  => 'ok',
			'message' => $note,
		);
	}
}
