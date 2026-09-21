<?php

namespace NovaExpress\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Активація/деактивація плагіна: створення власних таблиць БД
 * (замість використання таблиць іншого плагіна) та реєстрація крону.
 */
class Installer {

	public static function activate(): void {
		self::create_tables();
		self::maybe_schedule_cron();

		if ( false === get_option( 'nvx_settings' ) ) {
			add_option( 'nvx_settings', self::default_settings() );
		}

		self::maybe_upgrade_schema();
		update_option( 'nvx_db_version', NVX_DB_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Викликається на plugins_loaded — донакочує нові таблиці/структуру
	 * для сайтів, де плагін вже був активований на попередній версії.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'nvx_db_version' ) === NVX_DB_VERSION ) {
			// Навіть без зміни схеми БД перевіряємо, чи є новий cron —
			// на існуючих сайтах activate() повторно не викликається.
			self::maybe_schedule_cron();
			return;
		}

		self::create_tables();
		self::maybe_upgrade_schema();
		self::maybe_schedule_cron();
		update_option( 'nvx_db_version', NVX_DB_VERSION );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'nvx/tracking_cron_event' );
		wp_clear_scheduled_hook( 'nvx/prune_automation_log' );
		wp_clear_scheduled_hook( 'nvx_tracking_cron' );
		flush_rewrite_rules();
	}

	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$ttn_table   = $wpdb->prefix . 'nvx_waybills';
		$rules_table = $wpdb->prefix . 'nvx_automation_rules';
		$log_table   = $wpdb->prefix . 'nvx_automation_log';

		$sql = "CREATE TABLE {$ttn_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			waybill_number VARCHAR(32) NOT NULL,
			document_ref VARCHAR(64) DEFAULT '',
			service_type VARCHAR(32) NOT NULL DEFAULT 'warehouse_warehouse',
			carrier_status_code VARCHAR(16) DEFAULT '',
			carrier_status_text VARCHAR(255) DEFAULT '',
			is_delivered TINYINT(1) NOT NULL DEFAULT 0,
			tracking_details LONGTEXT NULL,
			last_polled_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY waybill_number (waybill_number),
			KEY order_id (order_id),
			KEY is_delivered (is_delivered)
		) {$charset_collate};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$rules_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			trigger_kind VARCHAR(20) NOT NULL DEFAULT 'ttn',
			trigger_status VARCHAR(255) NOT NULL DEFAULT 'any',
			is_enabled TINYINT(1) NOT NULL DEFAULT 1,
			sort_order BIGINT NOT NULL DEFAULT 0,
			actions LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY trigger_kind (trigger_kind),
			KEY sort_order (sort_order)
		) {$charset_collate};";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$log_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			waybill_number VARCHAR(32) DEFAULT '',
			action_type VARCHAR(32) NOT NULL,
			result VARCHAR(16) NOT NULL DEFAULT 'ok',
			message TEXT,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset_collate};";
		dbDelta( $sql );

		$warehouses_table = $wpdb->prefix . 'nvx_warehouses';

		$sql = "CREATE TABLE {$warehouses_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ref VARCHAR(64) NOT NULL,
			city_ref VARCHAR(64) NOT NULL,
			city_name VARCHAR(191) NOT NULL,
			area_name VARCHAR(191) DEFAULT '',
			description VARCHAR(255) NOT NULL,
			short_address VARCHAR(255) DEFAULT '',
			warehouse_type VARCHAR(64) DEFAULT '',
			warehouse_index VARCHAR(16) DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			max_dim_width DECIMAL(6,2) NULL,
			max_dim_height DECIMAL(6,2) NULL,
			max_dim_length DECIMAL(6,2) NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ref (ref),
			KEY city_ref (city_ref),
			KEY city_name (city_name)
		) {$charset_collate};";
		dbDelta( $sql );
	}

	private static function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( 'nvx/tracking_cron_event' ) ) {
			wp_schedule_event( time() + 300, 'nvx_polling_interval', 'nvx/tracking_cron_event' );
		}
		if ( ! wp_next_scheduled( 'nvx/prune_automation_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nvx/prune_automation_log' );
		}
	}

	private static function default_settings(): array {
		return array(
			'api_key'                 => '',
			'sender_ref'              => '',
			'polling_interval'        => 30, // хвилини.
			'webhook_url'             => '',
			'auto_create_ttn'         => 'no',
			// За замовчуванням дані НЕ видаляються автоматично при видаленні плагіна
			// через адмінку WordPress, щоб не втратити історію ТТН/журнал автоматизацій.
			'wipe_data_on_uninstall'  => 'no',
		);
	}

	/**
	 * Додає відсутні колонки на існуючих установках.
	 */
	public static function maybe_upgrade_schema(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'nvx_automation_rules';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'trigger_kind'" );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN trigger_kind VARCHAR(20) NOT NULL DEFAULT 'ttn' AFTER title" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sort_col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'sort_order'" );
		if ( empty( $sort_col ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN sort_order BIGINT NOT NULL DEFAULT 0, ADD KEY sort_order (sort_order)" );
			// Заповнюємо початкове значення за поточним id, щоб порядок правил,
			// які вже існували до цього оновлення, візуально не змінився (id ASC
			// був порядком за замовчуванням і раніше).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE `{$table}` SET sort_order = id" );
		}

		$warehouses_table = $wpdb->prefix . 'nvx_warehouses';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dim_col = $wpdb->get_results( "SHOW COLUMNS FROM `{$warehouses_table}` LIKE 'max_dim_width'" );
		if ( empty( $dim_col ) ) {
			// Ліміт габаритів (SendingLimitationsOnDimensions з Address.getWarehouses) —
			// потрібен, щоб перевіряти розміри місця ще ДО відправки запиту в API
			// (замість того, щоб дізнаватись про завеликий розмір лише з помилки НП).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$warehouses_table}` ADD COLUMN max_dim_width DECIMAL(6,2) NULL, ADD COLUMN max_dim_height DECIMAL(6,2) NULL, ADD COLUMN max_dim_length DECIMAL(6,2) NULL" );
		}
	}
}
