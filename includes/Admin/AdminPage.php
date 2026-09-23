<?php

namespace NovaExpress\Admin;

use NovaExpress\Automation\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Єдина точка входу в адмінці: WooCommerce → Nova Express
 * з вкладками Налаштування / Автоматизації ТТН / Автоматизації замовлень.
 */
class AdminPage {

	private RuleRepository $rules;
	private Settings $settings_page;
	private AutomationPage $automation_page;

	public function __construct( RuleRepository $rules ) {
		$this->rules            = $rules;
		$this->settings_page    = new Settings();
		$this->automation_page  = new AutomationPage( $rules );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Nova Express Woo', 'wc-nova-express' ),
			__( 'Nova Express Woo', 'wc-nova-express' ),
			'manage_woocommerce',
			'nvx-express',
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $tab, array( 'settings', 'label_template', 'automation_ttn', 'automation_order', 'monitoring', 'appearance' ), true ) ) {
			$tab = 'settings';
		}

		$base = admin_url( 'admin.php?page=nvx-express' );
		$tabs = array(
			'settings'          => __( 'Налаштування', 'wc-nova-express' ),
			'label_template'    => __( 'Шаблон етикетки', 'wc-nova-express' ),
			'automation_ttn'    => __( 'Автоматизації ТТН', 'wc-nova-express' ),
			'automation_order'  => __( 'Автоматизації замовлень', 'wc-nova-express' ),
			'monitoring'        => __( 'Моніторинг ТТН', 'wc-nova-express' ),
			'appearance'        => __( 'Оформлення', 'wc-nova-express' ),
		);
		?>
		<div class="wrap nvx-admin-shell">
			<h1 class="nvx-admin-shell__title"><?php esc_html_e( 'Nova Express Woo', 'wc-nova-express' ); ?></h1>
			<nav class="nav-tab-wrapper nvx-tabs">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( $base . '&tab=' . $key ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="nvx-tab-panel">
				<?php
				if ( 'settings' === $tab ) {
					$this->settings_page->render_content();
				} elseif ( 'label_template' === $tab ) {
					$settings = \NovaExpress\Admin\Settings::get_all();
					include NVX_PLUGIN_DIR . 'includes/Admin/views/label-template-page.php';
				} elseif ( 'automation_order' === $tab ) {
					$this->automation_page->render_content( 'order' );
				} elseif ( 'monitoring' === $tab ) {
					$ttn_repo         = new \NovaExpress\Ttn\TtnRepository();
					$active_waybills  = $ttn_repo->find_active_list( 200 );
					$delivered_recent = $ttn_repo->find_delivered_recent( 5 );
					$active_count     = $ttn_repo->count_active();
					$last_polled_at   = $ttn_repo->find_last_polled_at();
					$next_cron_at     = wp_next_scheduled( 'nvx/tracking_cron_event' );
					include NVX_PLUGIN_DIR . 'includes/Admin/views/monitoring-page.php';
				} elseif ( 'appearance' === $tab ) {
					include NVX_PLUGIN_DIR . 'includes/Admin/views/appearance-page.php';
				} else {
					$this->automation_page->render_content( 'ttn' );
				}
				?>
			</div>
		</div>
		<?php
	}
}
