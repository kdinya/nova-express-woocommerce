<?php
/**
 * @var \WC_Order $order
 * @var array     $waybills
 * @var string    $service_type
 * @var string    $city_name
 * @var string    $warehouse
 */
defined( 'ABSPATH' ) || exit;

$create_url = add_query_arg(
	array(
		'page'     => 'nvx-create-waybill',
		'order_id' => $order->get_id(),
	),
	admin_url( 'admin.php' )
);

$has_active_ttn = ! empty( $waybills );
?>
<div class="nvx-order-panel" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
	<div class="nvx-order-delivery-details" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:14px;">
		<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
			<span style="font-size:11px; text-transform:uppercase; letter-spacing:0.5px; font-weight:700; color:#da291c;">
				<?php esc_html_e( 'Дані доставки НП', 'wc-nova-express' ); ?>
			</span>
			<span style="font-size:11px; background:#e2e8f0; color:#475569; padding:2px 6px; border-radius:4px;">
				<?php
				$is_doors = in_array( $service_type, array( 'doors_doors', 'warehouse_doors' ), true );
				echo $is_doors ? esc_html__( 'Кур'єр', 'wc-nova-express' ) : esc_html__( 'Відділення', 'wc-nova-express' );
				?>
			</span>
		</div>
		<div style="font-size:13px; line-height:1.4; color:#1e293b;">
			<?php if ( ! empty( $city_name ) ) : ?>
				<div><strong><?php esc_html_e( 'Місто:', 'wc-nova-express' ); ?></strong> <?php echo esc_html( $city_name ); ?></div>
			<?php endif; ?>
			<?php if ( ! empty( $warehouse ) ) : ?>
				<div style="margin-top:2px;"><strong><?php esc_html_e( 'Відділення:', 'wc-nova-express' ); ?></strong> <?php echo esc_html( $warehouse ); ?></div>
			<?php elseif ( $is_doors ) : ?>
				<?php
				$street = $order->get_meta( '_nvx_street_name' );
				$bld    = $order->get_meta( '_nvx_building_number' );
				$apt    = $order->get_meta( '_nvx_apartment' );
				$addr   = trim( $street . ' ' . $bld . ( $apt ? ' кв./оф. ' . $apt : '' ) );
				?>
				<?php if ( ! empty( $addr ) ) : ?>
					<div style="margin-top:2px;"><strong><?php esc_html_e( 'Адреса:', 'wc-nova-express' ); ?></strong> <?php echo esc_html( $addr ); ?></div>
				<?php endif; ?>
			<?php else : ?>
				<div style="color:#e11d48; font-size:12px; margin-top:2px;">⚠️ <?php esc_html_e( 'Відділення не вказано або очікує вибору', 'wc-nova-express' ); ?></div>
			<?php endif; ?>
		</div>
	</div>


	<?php if ( $has_active_ttn ) : ?>
		<?php foreach ( $waybills as $w ) : ?>
			<div class="nvx-waybill-card <?php echo ! empty( $w['is_delivered'] ) ? 'is-delivered' : ''; ?>" data-ttn-id="<?php echo esc_attr( $w['id'] ); ?>">
				<div class="nvx-waybill-simple">
					<div class="nvx-waybill-simple__brand">Нова Пошта</div>

					<div class="nvx-waybill-simple__row">
						<span class="nvx-waybill-simple__label"><?php esc_html_e( 'Номер ТТН', 'wc-nova-express' ); ?></span>
						<a class="nvx-waybill-simple__value nvx-waybill-card__number" href="https://novaposhta.ua/tracking/<?php echo esc_attr( $w['waybill_number'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $w['waybill_number'] ); ?>
						</a>
					</div>

					<div class="nvx-waybill-simple__row">
						<span class="nvx-waybill-simple__label"><?php esc_html_e( 'Статус відстеження', 'wc-nova-express' ); ?></span>
						<span class="nvx-waybill-simple__value">
							<?php if ( ! empty( $w['carrier_status_code'] ) ) : ?>
								[<?php echo esc_html( $w['carrier_status_code'] ); ?>]
							<?php endif; ?>
							<?php echo esc_html( $w['carrier_status_text'] ?: __( 'Очікує опитування', 'wc-nova-express' ) ); ?>
						</span>
					</div>
				</div>

				<div class="nvx-waybill-card__actions">
					<a class="nvx-btn nvx-btn--primary nvx-btn--sm" target="_blank" rel="noopener"
						href="<?php echo esc_url( \NovaExpress\Admin\LabelPrint::url( (int) $w['id'], (int) $order->get_id(), 'custom' ) ); ?>">
						<?php esc_html_e( 'Друк', 'wc-nova-express' ); ?>
					</a>
					<button type="button" class="nvx-btn nvx-btn--ghost nvx-btn--sm nvx-waybill-card__refresh">
						<?php esc_html_e( 'Оновити', 'wc-nova-express' ); ?>
					</button>
					<button type="button" class="nvx-btn nvx-btn--ghost nvx-btn--sm nvx-waybill-card__delete">
						<?php esc_html_e( 'Видалити', 'wc-nova-express' ); ?>
					</button>
				</div>
			</div>
		<?php endforeach; ?>
	<?php else : ?>
		<div class="nvx-order-panel__actions">
			<a class="nvx-btn nvx-btn--primary nvx-btn--block" href="<?php echo esc_url( $create_url ); ?>" target="_blank" rel="noopener" id="nvx-op-open-create">
				<?php esc_html_e( '+ Створити ТТН', 'wc-nova-express' ); ?>
			</a>

			<button type="button" class="nvx-btn nvx-btn--ghost nvx-btn--block" id="nvx-op-toggle-attach">
				<?php esc_html_e( 'Додати наявний номер ТТН', 'wc-nova-express' ); ?>
			</button>

			<div id="nvx-op-attach-form" class="nvx-op-attach-form" style="display:none;">
				<label class="nvx-field">
					<span><?php esc_html_e( 'Номер ТТН', 'wc-nova-express' ); ?></span>
					<input type="text" id="nvx-op-attach-number" placeholder="20450045632001" />
				</label>
				<button type="button" class="nvx-btn nvx-btn--primary nvx-btn--block" id="nvx-op-attach-submit">
					<?php esc_html_e( 'Додати ТТН', 'wc-nova-express' ); ?>
				</button>
			</div>

			<div class="nvx-inline-status" id="nvx-op-status"></div>
		</div>
	<?php endif; ?>
</div>
