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

if ( $wipe ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_waybills" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_automation_rules" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_automation_log" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvx_warehouses" );

	delete_option( 'nvx_settings' );
	delete_option( 'nvx_db_version' );

	// Заплановані cron-події лишаються неактуальними без таблиць/налаштувань —
	// прибираємо, щоб не лишити "висячих" wp-cron записів.
	$timestamp = wp_next_scheduled( 'nvx/tracking_cron_event' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'nvx/tracking_cron_event' );
	}
	$prune_timestamp = wp_next_scheduled( 'nvx/prune_automation_log' );
	if ( $prune_timestamp ) {
		wp_unschedule_event( $prune_timestamp, 'nvx/prune_automation_log' );
	}
}
