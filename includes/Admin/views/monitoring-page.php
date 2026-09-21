<?php
/**
 * Вкладка «Моніторинг ТТН».
 *
 * @var array       $active_waybills
 * @var array       $delivered_recent
 * @var int         $active_count
 * @var string|null $last_polled_at Час останнього опитування статусів (MySQL datetime, час сайту).
 * @var int|false   $next_cron_at   Unix timestamp наступного запланованого опитування (wp_next_scheduled).
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="nvx-wrap" id="nvx-monitoring-app">
	<div class="nvx-header">
		<div class="nvx-header__logo"><img src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ); ?>" alt="Nova Express" /></div>
		<div>
			<h1><?php esc_html_e( 'Моніторинг ТТН', 'wc-nova-express' ); ?></h1>
			<p><?php esc_html_e( 'Активні накладні в базі плагіна та ручна перевірка статусів.', 'wc-nova-express' ); ?></p>
		</div>
		<button type="button" class="nvx-btn nvx-btn--primary" id="nvx-run-tracking-now">
			<?php esc_html_e( 'Перевірити статуси зараз', 'wc-nova-express' ); ?>
		</button>
	</div>
	<span id="nvx-run-tracking-result" class="nvx-inline-status" style="margin:0 0 12px;"></span>

	<p class="nvx-ttn-last-poll" id="nvx-ttn-last-poll">
		<?php if ( ! empty( $last_polled_at ) ) : ?>
			<?php
			// last_polled_at збережено через current_time('mysql') — це вже час
			// сайту (не GMT). date_create() з другим аргументом wp_timezone()
			// коректно трактує цей рядок як локальний час сайту й дає правильний
			// Unix-час для порівняння з current_time('timestamp', true) (реальний UTC).
			$dt       = date_create( $last_polled_at, wp_timezone() );
			$ts_local = $dt ? $dt->getTimestamp() : strtotime( $last_polled_at );
			$ago      = human_time_diff( $ts_local, current_time( 'timestamp', true ) );
			?>
			<?php
			printf(
				/* translators: 1: formatted date, 2: human-readable "X ago" */
				esc_html__( 'Останнє оновлення статусів: %1$s (%2$s тому)', 'wc-nova-express' ),
				esc_html( date_i18n( 'd.m.Y H:i', $ts_local, true ) ),
				esc_html( $ago )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Статуси ще жодного разу не опитувались.', 'wc-nova-express' ); ?>
		<?php endif; ?>
		<?php if ( ! empty( $next_cron_at ) ) : ?>
			· <?php
			printf(
				/* translators: %s: formatted date/time */
				esc_html__( 'наступна планова перевірка: %s', 'wc-nova-express' ),
				esc_html( date_i18n( 'd.m.Y H:i', $next_cron_at, true ) )
			);
			?>
		<?php endif; ?>
	</p>

	<section class="nvx-card nvx-ttn-monitor" id="nvx-ttn-monitor">
		<h2>
			<?php
			printf(
				/* translators: %d: active TTN count */
				esc_html__( 'ТТН у моніторингу (%d)', 'wc-nova-express' ),
				(int) ( $active_count ?? 0 )
			);
			?>
		</h2>
		<div data-list="active">
		<?php if ( empty( $active_waybills ) ) : ?>
			<p class="nvx-empty"><?php esc_html_e( 'Немає активних ТТН для відстеження.', 'wc-nova-express' ); ?></p>
		<?php else : ?>
			<ul class="nvx-ttn-list">
				<?php foreach ( $active_waybills as $row ) :
					$order = wc_get_order( (int) $row['order_id'] );
					$order_num = $order ? $order->get_order_number() : (string) $row['order_id'];
					$recipient = '';
					$total_str = '';
					if ( $order ) {
						$recipient = trim(
							( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) . ' ' .
							( $order->get_shipping_first_name() ?: $order->get_billing_first_name() )
						);
						$total_str = wp_strip_all_tags( $order->get_formatted_order_total() );
					}
					$status = (string) ( $row['carrier_status_text'] ?: $row['carrier_status_code'] ?: '—' );
					$order_url = $order
						? $order->get_edit_order_url()
						: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $row['order_id'] );
					?>
					<li class="nvx-ttn-list__item">
						<a class="nvx-ttn-list__link" href="<?php echo esc_url( $order_url ); ?>">
							<span class="nvx-ttn-list__ttn"><?php echo esc_html( $row['waybill_number'] ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">→</span>
							<span class="nvx-ttn-list__order">№<?php echo esc_html( $order_num ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">—</span>
							<span class="nvx-ttn-list__name"><?php echo esc_html( $recipient ?: '—' ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">—</span>
							<span class="nvx-ttn-list__total">(<?php echo esc_html( $total_str ?: '—' ); ?>)</span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">→</span>
							<span class="nvx-ttn-list__status"><?php echo esc_html( $status ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		</div>

		<h3 class="nvx-ttn-list__subtitle"><?php esc_html_e( 'Останні отримані (5)', 'wc-nova-express' ); ?></h3>
		<div data-list="delivered">
		<?php if ( empty( $delivered_recent ) ) : ?>
			<p class="nvx-empty"><?php esc_html_e( 'Ще немає доставлених ТТН у базі.', 'wc-nova-express' ); ?></p>
		<?php else : ?>
			<ul class="nvx-ttn-list nvx-ttn-list--done">
				<?php foreach ( $delivered_recent as $row ) :
					$order = wc_get_order( (int) $row['order_id'] );
					$order_num = $order ? $order->get_order_number() : (string) $row['order_id'];
					$recipient = '';
					$total_str = '';
					if ( $order ) {
						$recipient = trim(
							( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) . ' ' .
							( $order->get_shipping_first_name() ?: $order->get_billing_first_name() )
						);
						$total_str = wp_strip_all_tags( $order->get_formatted_order_total() );
					}
					$status = (string) ( $row['carrier_status_text'] ?: $row['carrier_status_code'] ?: 'Отримано' );
					$order_url = $order
						? $order->get_edit_order_url()
						: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $row['order_id'] );
					?>
					<li class="nvx-ttn-list__item">
						<a class="nvx-ttn-list__link" href="<?php echo esc_url( $order_url ); ?>">
							<span class="nvx-ttn-list__ttn"><?php echo esc_html( $row['waybill_number'] ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">→</span>
							<span class="nvx-ttn-list__order">№<?php echo esc_html( $order_num ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">—</span>
							<span class="nvx-ttn-list__name"><?php echo esc_html( $recipient ?: '—' ); ?></span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">—</span>
							<span class="nvx-ttn-list__total">(<?php echo esc_html( $total_str ?: '—' ); ?>)</span>
							<span class="nvx-ttn-list__sep" aria-hidden="true">→</span>
							<span class="nvx-ttn-list__status"><?php echo esc_html( $status ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		</div>
	</section>
</div>
