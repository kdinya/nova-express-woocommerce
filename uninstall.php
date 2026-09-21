<?php
/**
 * Виконується лише при видаленні плагіна через адмінку WordPress
 * (Uninstall), не при звичайній деактивації.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$settings = get_option( 'nvx_settings', array() );

// За замовчуванням дані НЕ видаляються автоматично, щоб не втратити історію ТТН
// та журнал автоматизацій при випадковому видаленні плагіна. Повне очищення
// виконується лише якщо адмін явно увімкнув відповідну галочку в
// Nova Express → Налаштування → "Дані плагіна при видаленні".
$wipe = isset( $settings['wipe_data_on_uninstall'] ) && 'yes' === $settings['wipe_data_on_uninstall'];

// Завжди очищаємо cron-події незалежно від збереження таблиць
wp_clear_scheduled_hook( 'nvx/tracking_cron_event' );
wp_clear_scheduled_hook( 'nvx/prune_automation_log' );
wp_clear_scheduled_hook( 'nvx_tracking_cron' );

if ( $wipe ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_waybills" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_automation_rules" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_automation_log" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_warehouses" );

	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_nvx_%'" );
	$hpos_table = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
		$wpdb->query( "DELETE FROM {$hpos_table} WHERE meta_key LIKE '_nvx_%'" );
	}

	delete_option( 'nvx_settings' );
		delete_option( 'nvx_db_version' );
	delete_option( 'nvx_warehouse_sync_last_page' );
	delete_option( 'nvx_warehouse_sync_completed_at' );
}
