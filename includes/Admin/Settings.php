<?php

namespace NovaExpress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Сторінка налаштувань плагіна (WooCommerce > Nova Express) +
 * статичний доступ до збережених опцій з інших класів.
 */
class Settings {

	private const OPTION_KEY = 'nvx_settings';

	public function register(): void {
		$this->register_save_hook();
	}

	/** Хук збереження форми (меню реєструє AdminPage). */
	public function register_save_hook(): void {
		add_action( 'admin_post_nvx_save_settings', array( $this, 'save' ) );
	}

	public static function get_all(): array {
		$defaults = array(
			'api_key'                 => '',
			'sender_city_ref'         => '',
			'sender_city_name'        => '',
			'sender_counterparty_ref' => '',
			'sender_contact_ref'      => '',
			'sender_contact_name'     => '',
			'sender_last_name'        => '',
			'sender_first_name'       => '',
			'sender_middle_name'      => '',
			'sender_warehouse_ref'    => '',
			'sender_warehouse_label'  => '',
			'sender_street_ref'       => '',
			'sender_building'         => '',
			'sender_phone'            => '',
			'polling_interval'        => 30,
			'webhook_url'             => '',
			'price_mode'              => 'api', // 'fixed' | 'api'.
			'fixed_price'             => 0,
			'default_service'         => 'warehouse_warehouse',
			'description_template'    => __( 'Замовлення №{order_number}', 'wc-nova-express' ),
			'additional_info_template' => '',
			'additional_info_contains' => '',
			'additional_warehouses'  => array(),
			// За замовчуванням дані НЕ видаляються автоматично при видаленні плагіна
			// через адмінку WordPress, щоб не втратити історію ТТН/журнал автоматизацій.
			'wipe_data_on_uninstall' => 'no',
		);

		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
	}

	public static function get_api_key(): string {
		$settings = self::get_all();

		return (string) $settings['api_key'];
	}

	/**
	 * Діагностика: визначає, чи сторінка "Оформлення замовлення" використовує
	 * блоковий редактор WooCommerce (Cart & Checkout Blocks) чи класичний
	 * шорткод [woocommerce_checkout]. На блоковому чекауті поля Nova Express
	 * виглядають інакше (прості текстові поля без живого пошуку) — важливо,
	 * щоб адмін розумів, чому.
	 */
	public static function detect_checkout_type(): string {
		$checkout_page_id = wc_get_page_id( 'checkout' );

		if ( $checkout_page_id <= 0 ) {
			return 'unknown';
		}

		$page = get_post( $checkout_page_id );

		if ( ! $page ) {
			return 'unknown';
		}

		if ( has_block( 'woocommerce/checkout', $page ) ) {
			return 'blocks';
		}

		if ( false !== strpos( $page->post_content, '[woocommerce_checkout' ) ) {
			return 'classic';
		}

		return 'unknown';
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		check_admin_referer( 'nvx_save_settings' );

		$previous = self::get_all();

		$posted_warehouse_ref = sanitize_text_field( wp_unslash( $_POST['sender_warehouse_ref'] ?? '' ) );
		$posted_warehouse_label = sanitize_text_field( wp_unslash( $_POST['sender_warehouse_label'] ?? '' ) );

		$settings = array(
			'api_key'                 => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
			'sender_city_ref'         => sanitize_text_field( wp_unslash( $_POST['sender_city_ref'] ?? '' ) ),
			'sender_city_name'        => sanitize_text_field( wp_unslash( $_POST['sender_city_name'] ?? '' ) ),
			// Ref контрагента/контакту більше не редагуються напряму в UI (зайва
			// технічна деталь для адміна) — обчислюються нижче через API за ПІБ/телефоном.
			'sender_counterparty_ref' => $previous['sender_counterparty_ref'],
			'sender_contact_ref'      => $previous['sender_contact_ref'],
			'sender_contact_name'     => $previous['sender_contact_name'],
			'sender_last_name'        => sanitize_text_field( wp_unslash( $_POST['sender_last_name'] ?? '' ) ),
			'sender_first_name'       => sanitize_text_field( wp_unslash( $_POST['sender_first_name'] ?? '' ) ),
			'sender_middle_name'      => sanitize_text_field( wp_unslash( $_POST['sender_middle_name'] ?? '' ) ),
			// Захист від випадкового стирання: якщо форма прийшла без обраного відділення
			// (наприклад, список ще не встиг підвантажитись у браузері), лишаємо попереднє значення,
			// а не затираємо його порожнім рядком.
			'sender_warehouse_ref'    => '' !== $posted_warehouse_ref ? $posted_warehouse_ref : $previous['sender_warehouse_ref'],
			'sender_warehouse_label'  => '' !== $posted_warehouse_ref ? $posted_warehouse_label : $previous['sender_warehouse_label'],
			'sender_street_ref'       => sanitize_text_field( wp_unslash( $_POST['sender_street_ref'] ?? '' ) ),
			'sender_building'         => sanitize_text_field( wp_unslash( $_POST['sender_building'] ?? '' ) ),
			'sender_phone'            => sanitize_text_field( wp_unslash( $_POST['sender_phone'] ?? '' ) ),
			'polling_interval'        => max( 5, (int) ( $_POST['polling_interval'] ?? 30 ) ),
			'webhook_url'             => esc_url_raw( wp_unslash( $_POST['webhook_url'] ?? '' ) ),
			'price_mode'              => in_array( $_POST['price_mode'] ?? '', array( 'fixed', 'api' ), true ) ? $_POST['price_mode'] : 'api',
			'default_service'         => sanitize_text_field( wp_unslash( $_POST['default_service'] ?? 'warehouse_warehouse' ) ),
			'fixed_price'             => (float) ( $_POST['fixed_price'] ?? 0 ),
			'description_template'    => sanitize_text_field( wp_unslash( $_POST['description_template'] ?? '' ) ),
			'additional_info_template' => sanitize_textarea_field( wp_unslash( $_POST['additional_info_template'] ?? '' ) ),
			'additional_info_contains' => sanitize_text_field( wp_unslash( $_POST['additional_info_contains'] ?? '' ) ),
			'wipe_data_on_uninstall'  => ! empty( $_POST['wipe_data_on_uninstall'] ) ? 'yes' : 'no',
		);

		$sender_error = '';

		// Якщо адмін ввів/змінив ПІБ чи телефон відправника вручну (замість кнопки
		// "Отримати автоматично") — самі створюємо/оновлюємо контрагента-відправника
		// через Counterparty.save, щоб не вимагати від адміна знання технічних Ref.
		$name_changed = $settings['sender_last_name'] !== $previous['sender_last_name']
			|| $settings['sender_first_name'] !== $previous['sender_first_name']
			|| $settings['sender_middle_name'] !== $previous['sender_middle_name']
			|| $settings['sender_phone'] !== $previous['sender_phone']
			|| empty( $previous['sender_counterparty_ref'] );

		if ( $name_changed && '' !== $settings['api_key'] && '' !== $settings['sender_last_name'] ) {
			try {
				$client = new \NovaExpress\Api\NovaPoshtaClient( $settings['api_key'] );

				$counterparty = $client->save_counterparty(
					array(
						'CounterpartyType'     => 'PrivatePerson',
						'CounterpartyProperty' => 'Sender',
						'FirstName'            => $settings['sender_first_name'],
						'LastName'             => $settings['sender_last_name'],
						'MiddleName'           => $settings['sender_middle_name'],
						'Phone'                => \NovaExpress\Helpers\Formatting::normalize_phone( $settings['sender_phone'] ),
					)
				);

				$settings['sender_counterparty_ref'] = $counterparty['Ref'] ?? $settings['sender_counterparty_ref'];
				$contact_ref = $counterparty['ContactPerson']['data'][0]['Ref']
					?? $counterparty['ContactPerson'][0]['Ref']
					?? '';

				if ( '' === $contact_ref && ! empty( $settings['sender_counterparty_ref'] ) ) {
					$contacts    = $client->get_counterparty_contacts( $settings['sender_counterparty_ref'] );
					$contact_ref = $contacts[0]['Ref'] ?? $settings['sender_counterparty_ref'];
				}

				$settings['sender_contact_ref']  = $contact_ref;
				$settings['sender_contact_name'] = trim( $settings['sender_last_name'] . ' ' . $settings['sender_first_name'] . ' ' . $settings['sender_middle_name'] );
			} catch ( \Throwable $e ) {
				$sender_error = $e->getMessage();
				// Не блокуємо збереження решти налаштувань — просто лишаємо
				// попередній Ref (якщо був) і повідомляємо про помилку окремо.
			}
		}

		
		// Додаткові відділення відправника (той самий контрагент, інший склад).
		$extra = array();
		$extra_labels = isset( $_POST['extra_wh_label'] ) ? (array) wp_unslash( $_POST['extra_wh_label'] ) : array();
		$extra_city_refs = isset( $_POST['extra_wh_city_ref'] ) ? (array) wp_unslash( $_POST['extra_wh_city_ref'] ) : array();
		$extra_city_names = isset( $_POST['extra_wh_city_name'] ) ? (array) wp_unslash( $_POST['extra_wh_city_name'] ) : array();
		$extra_wh_refs = isset( $_POST['extra_wh_ref'] ) ? (array) wp_unslash( $_POST['extra_wh_ref'] ) : array();
		$extra_wh_labels = isset( $_POST['extra_wh_warehouse_label'] ) ? (array) wp_unslash( $_POST['extra_wh_warehouse_label'] ) : array();
		foreach ( $extra_wh_refs as $i => $ref ) {
			$ref = sanitize_text_field( (string) $ref );
			if ( '' === $ref ) {
				continue;
			}
			$extra[] = array(
				'id'              => 'extra_' . ( $i + 1 ),
				'label'           => sanitize_text_field( (string) ( $extra_labels[ $i ] ?? '' ) ) ?: ( 'Відділення ' . ( $i + 1 ) ),
				'city_ref'        => sanitize_text_field( (string) ( $extra_city_refs[ $i ] ?? '' ) ),
				'city_name'       => sanitize_text_field( (string) ( $extra_city_names[ $i ] ?? '' ) ),
				'warehouse_ref'   => $ref,
				'warehouse_label' => sanitize_text_field( (string) ( $extra_wh_labels[ $i ] ?? '' ) ),
			);
		}
		$settings['additional_warehouses'] = $extra;

		update_option( self::OPTION_KEY, $settings );

		// Якщо інтервал опитування змінився — перепланувати cron із новим розкладом.
		if ( (int) $previous['polling_interval'] !== (int) $settings['polling_interval'] ) {
			$timestamp = wp_next_scheduled( 'nvx/tracking_cron_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'nvx/tracking_cron_event' );
			}
			wp_schedule_event( time() + 60, 'nvx_polling_interval', 'nvx/tracking_cron_event' );
		}

		$redirect_args = array( 'page' => 'nvx-express', 'tab' => 'settings', 'updated' => '1' );
		if ( $sender_error ) {
			$redirect_args['sender_error'] = rawurlencode( $sender_error );
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_content(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings          = self::get_all();
		$warehouses_count  = ( new \NovaExpress\Warehouse\WarehouseRepository() )->count();
		$checkout_type     = self::detect_checkout_type();

		include NVX_PLUGIN_DIR . 'includes/Admin/views/settings-page.php';
	}
/**
	 * Профілі відправника: основне відділення + додаткові склади.
	 * Контрагент/телефон спільні; відрізняються місто та відділення.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_sender_profiles(): array {
		$s = self::get_all();
		$profiles = array();

		$profiles[] = array(
			'id'              => 'primary',
			'label'           => $s['sender_warehouse_label'] ?: __( 'Основне відділення', 'wc-nova-express' ),
			'city_ref'        => (string) ( $s['sender_city_ref'] ?? '' ),
			'city_name'       => (string) ( $s['sender_city_name'] ?? '' ),
			'warehouse_ref'   => (string) ( $s['sender_warehouse_ref'] ?? '' ),
			'warehouse_label' => (string) ( $s['sender_warehouse_label'] ?? '' ),
			'counterparty_ref'=> (string) ( $s['sender_counterparty_ref'] ?? '' ),
			'contact_ref'     => (string) ( $s['sender_contact_ref'] ?? '' ),
			'phone'           => (string) ( $s['sender_phone'] ?? '' ),
		);

		foreach ( (array) ( $s['additional_warehouses'] ?? array() ) as $row ) {
			if ( empty( $row['warehouse_ref'] ) ) {
				continue;
			}
			$profiles[] = array(
				'id'              => (string) ( $row['id'] ?? uniqid( 's_', false ) ),
				'label'           => (string) ( $row['label'] ?? $row['warehouse_label'] ?? '' ),
				'city_ref'        => (string) ( $row['city_ref'] ?? $s['sender_city_ref'] ?? '' ),
				'city_name'       => (string) ( $row['city_name'] ?? $s['sender_city_name'] ?? '' ),
				'warehouse_ref'   => (string) $row['warehouse_ref'],
				'warehouse_label' => (string) ( $row['warehouse_label'] ?? '' ),
				'counterparty_ref'=> (string) ( $s['sender_counterparty_ref'] ?? '' ),
				'contact_ref'     => (string) ( $s['sender_contact_ref'] ?? '' ),
				'phone'           => (string) ( $s['sender_phone'] ?? '' ),
			);
		}

		return $profiles;
	}

	/**
	 * @return array<string,string>|null
	 */
	public static function get_sender_profile( string $id ): ?array {
		foreach ( self::get_sender_profiles() as $p ) {
			if ( ( $p['id'] ?? '' ) === $id ) {
				return $p;
			}
		}
		$all = self::get_sender_profiles();
		return $all[0] ?? null;
	}
}
