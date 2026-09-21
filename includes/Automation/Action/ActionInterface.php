<?php

namespace NovaExpress\Automation\Action;

defined( 'ABSPATH' ) || exit;

interface ActionInterface {

	/**
	 * @param \WC_Order $order         Замовлення, до якого відноситься подія.
	 * @param array     $config        Конфігурація дії, задана адміном у правилі.
	 * @param array     $status_event  Дані події: waybill_number, status_code, status_text, previous_status_code.
	 *
	 * @return array{result: string, message: string} 'ok' | 'error'
	 */
	public function run( \WC_Order $order, array $config, array $status_event ): array;
}
