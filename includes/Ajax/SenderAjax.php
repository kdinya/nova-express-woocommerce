<?php

namespace NovaExpress\Ajax;

use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Api\NovaPoshtaClient;

defined( 'ABSPATH' ) || exit;

/**
 * Автоматичне отримання Ref контрагента-відправника та контактної особи —
 * без цього кроку InternetDocument.save завжди повертає
 * "Sender not selected; ContactSender not selected", бо Нова Пошта
 * ідентифікує відправника саме за Ref контрагента, а не текстовим ім'ям.
 */
class SenderAjax {

	private NovaPoshtaClient $client;

	public function __construct( NovaPoshtaClient $client ) {
		$this->client = $client;
	}

	public function register(): void {
		add_action( 'wp_ajax_nvx_fetch_sender_counterparty', array( $this, 'fetch' ) );
	}

	public function fetch(): void {
		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
			return;
		}

		$posted_api_key = isset( $_REQUEST['api_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['api_key'] ) ) : '';
		if ( '' !== $posted_api_key ) {
			$this->client->set_api_key( $posted_api_key );
		}

		try {
			$counterparties = $this->client->get_counterparties( 'Sender' );

			if ( empty( $counterparties ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Nova Poshta не повернула жодного контрагента-відправника для цього API-ключа.', 'wc-nova-express' ) ),
					404
				);
			}

			$counterparty = $counterparties[0];
			$contacts     = $this->client->get_counterparty_contacts( $counterparty['Ref'] );

			if ( empty( $contacts ) ) {
				wp_send_json_error(
					array( 'message' => __( 'У контрагента-відправника немає жодної контактної особи.', 'wc-nova-express' ) ),
					404
				);
			}

			$contact = $contacts[0];

			// Одразу зберігаємо в налаштування — без додаткового кліку "Зберегти",
			// щоб не губити щойно отримані дані, якщо адмін забуде натиснути форму.
			$settings                             = \NovaExpress\Admin\Settings::get_all();
			$settings['sender_counterparty_ref']  = $counterparty['Ref'];
			$settings['sender_contact_ref']       = $contact['Ref'];
			$settings['sender_last_name']         = $contact['LastName'] ?? '';
			$settings['sender_first_name']        = $contact['FirstName'] ?? '';
			$settings['sender_middle_name']       = $contact['MiddleName'] ?? '';
			$settings['sender_contact_name']      = trim( ( $contact['LastName'] ?? '' ) . ' ' . ( $contact['FirstName'] ?? '' ) . ' ' . ( $contact['MiddleName'] ?? '' ) );
			if ( ! empty( $contact['Phones'] ) ) {
				$settings['sender_phone'] = preg_replace( '/\D+/', '', $contact['Phones'] );
			}
			if ( '' !== $posted_api_key ) { $settings['api_key'] = $posted_api_key; }
			update_option( 'nvx_settings', $settings );

			wp_send_json_success(
				array(
					'counterparty_ref'  => $counterparty['Ref'],
					'counterparty_name' => $counterparty['Description'] ?? '',
					'contact_ref'       => $contact['Ref'],
					'last_name'         => $settings['sender_last_name'],
					'first_name'        => $settings['sender_first_name'],
					'middle_name'       => $settings['sender_middle_name'],
					'contact_name'      => $settings['sender_contact_name'],
					'phone'             => $settings['sender_phone'],
				)
			);
		} catch ( NovaPoshtaApiException $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}
}
