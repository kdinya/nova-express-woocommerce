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
		add_action( 'admin_post_nvx_save_appearance', array( $this, 'save_appearance' ) );
		add_action( 'admin_post_nvx_save_label_template', array( $this, 'save_label_template' ) );
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
			'default_service'         => 'warehouse_warehouse',
			'default_payer'           => 'Recipient',
			'description_template'    => __( 'Замовлення №{order_number}', 'wc-nova-express' ),
			'additional_info_template' => '',
			'additional_info_contains' => '',
			'additional_warehouses'  => array(),
			// За замовчуванням дані НЕ видаляються автоматично при видаленні плагіна
			// через адмінку WordPress, щоб не втратити історію ТТН/журнал автоматизацій.
			'enable_phone_mask'        => 'yes',
			'wipe_data_on_uninstall' => 'no',
			'admin_primary_color'    => '#7CB342',
			'label_width'            => 100,
			'label_height'           => 150,
			'label_margin_top'       => 8,
			'label_margin_sides'     => 6,
			'label_font_size'        => 'medium',
			'label_align'            => 'center',
			'label_show_barcode'     => 'yes',
			'label_show_ttn'         => 'yes',
			'label_show_recipient_name' => 'yes',
			'label_show_recipient_phone'=> 'yes',
			'label_show_recipient_address' => 'yes',
			'label_show_order_number'=> 'yes',
			'label_show_order_items' => 'no',
			'label_show_order_total' => 'no',
			'label_custom_note'      => '',
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
	 * шорткод [woocommerce_checkout]. На блоковому чекауті поля Nova Express Woo
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

	public static function get_label_template(): array {
		$all = self::get_all();
		return array(
			'width'                 => (int) ( $all['label_width'] ?? 100 ),
			'height'                => (int) ( $all['label_height'] ?? 150 ),
			'margin_top'            => (int) ( $all['label_margin_top'] ?? 8 ),
			'margin_sides'          => (int) ( $all['label_margin_sides'] ?? 6 ),
			'font_size'             => (string) ( $all['label_font_size'] ?? 'medium' ),
			'align'                 => (string) ( $all['label_align'] ?? 'center' ),
			'show_barcode'          => 'yes' === ( $all['label_show_barcode'] ?? 'yes' ),
			'show_ttn'              => 'yes' === ( $all['label_show_ttn'] ?? 'yes' ),
			'show_recipient_name'   => 'yes' === ( $all['label_show_recipient_name'] ?? 'yes' ),
			'show_recipient_phone'  => 'yes' === ( $all['label_show_recipient_phone'] ?? 'yes' ),
			'show_recipient_address'=> 'yes' === ( $all['label_show_recipient_address'] ?? 'yes' ),
			'show_order_number'     => 'yes' === ( $all['label_show_order_number'] ?? 'yes' ),
			'show_order_items'      => 'yes' === ( $all['label_show_order_items'] ?? 'no' ),
			'show_order_total'      => 'yes' === ( $all['label_show_order_total'] ?? 'no' ),
			'custom_note'           => (string) ( $all['label_custom_note'] ?? '' ),
		);
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		check_admin_referer( 'nvx_save_settings' );

		$previous = self::get_all();

		$posted_warehouse_ref = sanitize_text_field( wp_unslash( $_POST['sender_warehouse_ref'] ?? '' ) );
		$posted_warehouse_label = sanitize_text_field( wp_unslash( $_POST['sender_warehouse_label'] ?? '' ) );

		$posted_api_key = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
		$api_key        = '' !== $posted_api_key ? $posted_api_key : ( $previous['api_key'] ?? '' );

		$settings = array(
			'api_key'                 => $api_key,
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
			'default_service'         => sanitize_text_field( wp_unslash( $_POST['default_service'] ?? 'warehouse_warehouse' ) ),
			'default_payer'           => in_array( $_POST['default_payer'] ?? '', array( 'Recipient', 'Sender', 'ThirdPerson' ), true ) ? $_POST['default_payer'] : 'Recipient',
			'description_template'    => sanitize_text_field( wp_unslash( $_POST['description_template'] ?? '' ) ),
			'additional_info_template' => sanitize_textarea_field( wp_unslash( $_POST['additional_info_template'] ?? '' ) ),
			'additional_info_contains' => sanitize_text_field( wp_unslash( $_POST['additional_info_contains'] ?? '' ) ),
			'enable_phone_mask'        => ! empty( $_POST['enable_phone_mask'] ) ? 'yes' : 'no',
			'wipe_data_on_uninstall'  => ! empty( $_POST['wipe_data_on_uninstall'] ) ? 'yes' : 'no',
			'label_width'             => max( 40, min( 300, (int) ( $_POST['label_width'] ?? 100 ) ) ),
			'label_height'            => max( 30, min( 400, (int) ( $_POST['label_height'] ?? 150 ) ) ),
			'label_margin_top'        => max( 0, min( 50, (int) ( $_POST['label_margin_top'] ?? 8 ) ) ),
			'label_margin_sides'      => max( 0, min( 50, (int) ( $_POST['label_margin_sides'] ?? 6 ) ) ),
			'label_font_size'         => in_array( $_POST['label_font_size'] ?? '', array( 'small', 'medium', 'large' ), true ) ? $_POST['label_font_size'] : 'medium',
			'label_align'             => in_array( $_POST['label_align'] ?? '', array( 'center', 'left' ), true ) ? $_POST['label_align'] : 'center',
			'label_show_barcode'      => ! empty( $_POST['label_show_barcode'] ) ? 'yes' : 'no',
			'label_show_ttn'          => ! empty( $_POST['label_show_ttn'] ) ? 'yes' : 'no',
			'label_show_recipient_name' => ! empty( $_POST['label_show_recipient_name'] ) ? 'yes' : 'no',
			'label_show_recipient_phone'=> ! empty( $_POST['label_show_recipient_phone'] ) ? 'yes' : 'no',
			'label_show_recipient_address' => ! empty( $_POST['label_show_recipient_address'] ) ? 'yes' : 'no',
			'label_show_order_number' => ! empty( $_POST['label_show_order_number'] ) ? 'yes' : 'no',
			'label_show_order_items'  => ! empty( $_POST['label_show_order_items'] ) ? 'yes' : 'no',
			'label_show_order_total'  => ! empty( $_POST['label_show_order_total'] ) ? 'yes' : 'no',
			'label_custom_note'       => sanitize_text_field( wp_unslash( $_POST['label_custom_note'] ?? '' ) ),
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

		$settings             = self::get_all();
		$warehouses_count     = ( new \NovaExpress\Warehouse\WarehouseRepository() )->count();
		$checkout_type        = self::detect_checkout_type();
		$api_warehouses_count = null;

		if ( ! empty( $settings['api_key'] ) ) {
			$cached_api_count = get_transient( 'nvx_np_warehouses_total_count' );
			if ( false !== $cached_api_count && is_numeric( $cached_api_count ) ) {
				$api_warehouses_count = (int) $cached_api_count;
			} else {
				$client               = new \NovaExpress\Api\NovaPoshtaClient( $settings['api_key'] );
				$api_warehouses_count = $client->get_warehouses_total_count();
				if ( null !== $api_warehouses_count ) {
					set_transient( 'nvx_np_warehouses_total_count', $api_warehouses_count, HOUR_IN_SECONDS );
				}
			}
		}

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

	public static function get_admin_color(): string {
		$settings = self::get_all();
		$color    = ! empty( $settings['admin_primary_color'] ) ? sanitize_hex_color( $settings['admin_primary_color'] ) : '';
		return ! empty( $color ) ? $color : '#7CB342';
	}

	public function save_label_template(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		check_admin_referer( 'nvx_save_label_template' );

		$settings = self::get_all();
		$settings['label_width']                = max( 40, min( 300, (int) ( $_POST['label_width'] ?? 100 ) ) );
		$settings['label_height']               = max( 30, min( 400, (int) ( $_POST['label_height'] ?? 150 ) ) );
		$settings['label_margin_top']           = max( 0, min( 50, (int) ( $_POST['label_margin_top'] ?? 8 ) ) );
		$settings['label_margin_sides']         = max( 0, min( 50, (int) ( $_POST['label_margin_sides'] ?? 6 ) ) );
		$settings['label_font_size']            = in_array( $_POST['label_font_size'] ?? '', array( 'small', 'medium', 'large' ), true ) ? $_POST['label_font_size'] : 'medium';
		$settings['label_align']                = in_array( $_POST['label_align'] ?? '', array( 'center', 'left' ), true ) ? $_POST['label_align'] : 'center';
		$settings['label_show_barcode']         = ! empty( $_POST['label_show_barcode'] ) ? 'yes' : 'no';
		$settings['label_show_ttn']             = ! empty( $_POST['label_show_ttn'] ) ? 'yes' : 'no';
		$settings['label_show_recipient_name']    = ! empty( $_POST['label_show_recipient_name'] ) ? 'yes' : 'no';
		$settings['label_show_recipient_phone']   = ! empty( $_POST['label_show_recipient_phone'] ) ? 'yes' : 'no';
		$settings['label_show_recipient_address'] = ! empty( $_POST['label_show_recipient_address'] ) ? 'yes' : 'no';
		$settings['label_show_order_number']    = ! empty( $_POST['label_show_order_number'] ) ? 'yes' : 'no';
		$settings['label_show_order_items']     = ! empty( $_POST['label_show_order_items'] ) ? 'yes' : 'no';
		$settings['label_show_order_total']     = ! empty( $_POST['label_show_order_total'] ) ? 'yes' : 'no';
		$settings['label_custom_note']          = sanitize_text_field( wp_unslash( $_POST['label_custom_note'] ?? '' ) );

		update_option( self::OPTION_KEY, $settings );

		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'nvx-express',
				'tab'     => 'label_template',
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function save_appearance(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостатньо прав.', 'wc-nova-express' ) );
		}

		check_admin_referer( 'nvx_save_appearance' );

		$color = isset( $_POST['admin_primary_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['admin_primary_color'] ) ) : '';
		if ( empty( $color ) ) {
			$color = '#7CB342';
		}

		$settings                        = self::get_all();
		$settings['admin_primary_color'] = $color;
		update_option( self::OPTION_KEY, $settings );

		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'nvx-express',
				'tab'     => 'appearance',
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
