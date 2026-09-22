<?php
/**
 * Вкладка «Оформлення» адмінки Nova Express Woo.
 */

defined( 'ABSPATH' ) || exit;

$current_color = \NovaExpress\Admin\Settings::get_admin_color();
$is_updated    = ! empty( $_GET['updated'] );

$presets = array(
	array(
		'label' => __( 'Зелений', 'wc-nova-express' ),
		'color' => '#7CB342',
	),
	array(
		'label' => __( 'Нова Пошта (фірмовий червоний)', 'wc-nova-express' ),
		'color' => '#da291c',
	),
	array(
		'label' => __( 'Класичний синій', 'wc-nova-express' ),
		'color' => '#2563eb',
	),
	array(
		'label' => __( 'Фіолетовий / Індиго', 'wc-nova-express' ),
		'color' => '#7c3aed',
	),
	array(
		'label' => __( 'Бурштиновий / Помаранчевий', 'wc-nova-express' ),
		'color' => '#ea580c',
	),
	array(
		'label' => __( 'Темний графіт', 'wc-nova-express' ),
		'color' => '#334155',
	),
);
?>

<div class="nvx-wrap">
	<div class="nvx-header">
		<div class="nvx-header__logo"><img src="<?php echo esc_url( NVX_PLUGIN_URL . 'assets/images/icon-64x64.png' ); ?>" alt="Nova Express Woo" /></div>
		<div>
			<h1><?php esc_html_e( 'Оформлення', 'wc-nova-express' ); ?></h1>
			<p><?php esc_html_e( 'Налаштування колірної теми та зовнішнього вигляду панелі керування', 'wc-nova-express' ); ?></p>
		</div>
	</div>

	<?php if ( $is_updated ) : ?>
		<div class="nvx-alert nvx-alert--success" style="margin-bottom:18px;">
			<?php esc_html_e( 'Колірну тему успішно збережено!', 'wc-nova-express' ); ?>
		</div>
	<?php endif; ?>

	<div class="nvx-card">
		<h2>🎨 <?php esc_html_e( 'Колірна тема панелі керування', 'wc-nova-express' ); ?></h2>
		<p class="nvx-card__hint">
			<?php esc_html_e( 'Оберіть готовий колір або встановіть довільний відтінок. Цей колір застосовується до кнопок, активних перемикачів, бейджів та шапки плагіна в адмін-панелі.', 'wc-nova-express' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="nvx-appearance-form">
			<?php wp_nonce_field( 'nvx_save_appearance' ); ?>
			<input type="hidden" name="action" value="nvx_save_appearance">

			<div class="nvx-field">
				<span><?php esc_html_e( 'Готові колірні схеми:', 'wc-nova-express' ); ?></span>
				<div class="nvx-color-presets">
					<?php foreach ( $presets as $preset ) : ?>
						<button type="button"
							class="nvx-preset-btn <?php echo strtolower( $current_color ) === strtolower( $preset['color'] ) ? 'is-active' : ''; ?>"
							data-color="<?php echo esc_attr( $preset['color'] ); ?>"
							title="<?php echo esc_attr( $preset['label'] ); ?>">
							<span class="nvx-preset-btn__dot" style="background-color:<?php echo esc_attr( $preset['color'] ); ?>;"></span>
							<span class="nvx-preset-btn__label"><?php echo esc_html( $preset['label'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="nvx-field-row" style="margin-top:20px; align-items:flex-end;">
				<div class="nvx-field" style="max-width:220px;">
					<span><?php esc_html_e( 'Довільний колір:', 'wc-nova-express' ); ?></span>
					<div class="nvx-color-input-wrap">
						<input type="color"
							id="nvx_color_picker"
							class="nvx-color-picker"
							value="<?php echo esc_attr( $current_color ); ?>">
						<input type="text"
							name="admin_primary_color"
							id="nvx_admin_primary_color"
							class="nvx-color-text"
							maxlength="7"
							value="<?php echo esc_attr( $current_color ); ?>">
					</div>
					<small><?php esc_html_e( 'Формат HEX, наприклад #7CB342 або #da291c', 'wc-nova-express' ); ?></small>
				</div>
			</div>

			<div class="nvx-preview-box">
				<div class="nvx-preview-box__title"><?php esc_html_e( 'Попередній перегляд елементів:', 'wc-nova-express' ); ?></div>
				<div class="nvx-preview-box__items">
					<button type="button" class="nvx-btn nvx-btn--primary"><?php esc_html_e( 'Основна кнопка', 'wc-nova-express' ); ?></button>
					<button type="button" class="nvx-btn nvx-btn--ghost"><?php esc_html_e( 'Другорядна кнопка', 'wc-nova-express' ); ?></button>
					<span class="nvx-badge"><?php esc_html_e( 'Активний статус', 'wc-nova-express' ); ?></span>
					<label class="nvx-mode-chip is-active" style="margin:0;">
						<input type="radio" checked onclick="return false;">
						<span><?php esc_html_e( 'Активна опція', 'wc-nova-express' ); ?></span>
					</label>
				</div>
			</div>

			<div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--nvx-border);">
				<button type="submit" class="nvx-btn nvx-btn--primary">
					💾 <?php esc_html_e( 'Зберегти оформлення', 'wc-nova-express' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
