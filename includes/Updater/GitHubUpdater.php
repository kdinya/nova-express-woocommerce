<?php

namespace NovaExpress\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Автоматична перевірка та встановлення оновлень з репозиторію GitHub:
 * kdinya/nova-express-woocommerce
 */
class GitHubUpdater {

	private string $repo_owner;
	private string $repo_name;
	private string $plugin_file;
	private string $plugin_slug;
	private string $version;

	public function __construct( string $plugin_file, string $repo_owner = 'kdinya', string $repo_name = 'nova-express-woocommerce', string $version = NVX_VERSION ) {
		$this->plugin_file = $plugin_file;
		$this->plugin_slug = plugin_basename( $plugin_file );
		$this->repo_owner  = $repo_owner;
		$this->repo_name   = $repo_name;
		$this->version     = $version;
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_api_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );

		// Ручна перевірка та встановлення оновлення зі сторінки налаштувань.
		add_action( 'wp_ajax_nvx_check_update', array( $this, 'ajax_check_update' ) );
		add_action( 'wp_ajax_nvx_run_update', array( $this, 'ajax_run_update' ) );
	}

	/**
	 * Перевірка оновлень у GitHub Releases.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'] ?? '', 'v' );
		if ( version_compare( $latest_version, $this->version, '>' ) ) {
			$package = $this->get_download_package( $release );

			if ( ! empty( $package ) ) {
				$obj              = new \stdClass();
				$obj->slug        = dirname( $this->plugin_slug );
				$obj->new_version = $latest_version;
				$obj->url         = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
				$obj->package     = $package;
				$obj->plugin      = $this->plugin_slug;

				$transient->response[ $this->plugin_slug ] = $obj;
			}
		}

		return $transient;
	}

	/**
	 * Інформація про плагін для модального вікна деталей оновлення в адмінці WP.
	 */
	public function plugin_api_info( $res, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		$slug = dirname( $this->plugin_slug );
		if ( ! isset( $args->slug ) || ( $args->slug !== $slug && $args->slug !== $this->plugin_slug ) ) {
			return $res;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return $res;
		}

		$latest_version = ltrim( $release['tag_name'] ?? '', 'v' );

		$res                = new \stdClass();
		$res->name          = 'Nova Express Woo for WooCommerce';
		$res->slug          = $slug;
		$res->version       = $latest_version;
		$res->author        = '<a href="https://github.com/' . esc_url( 'https://github.com/' . $this->repo_owner ) . '">Nova Express</a>';
		$res->homepage      = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
		$res->download_link = $this->get_download_package( $release );
		$res->tested        = '9.4';
		$res->requires      = '6.0';
		$res->requires_php  = '7.4';
		$res->last_updated  = $release['published_at'] ?? current_time( 'mysql' );

		$res->sections = array(
			'description' => 'Доставка Новою Поштою для WooCommerce: розрахунок, ручне створення ТТН, моніторинг статусів та автоматизації.',
			'changelog'   => ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : 'Оновлення версії ' . esc_html( $latest_version ),
		);

		return $res;
	}

	/**
	 * Вирівнює назву теки розпакованого архіву з текою плагіна.
	 *
	 * Архіви з GitHub ("Source code (zip)" / zipball) розпаковуються в теку на
	 * кшталт "nova-express-woocommerce-2026.09.5" (або "kdinya-…-<sha>" для
	 * автооновлення). WordPress встановлює плагін у теку з такою самою назвою, тож
	 * при ручному встановленні ZIP (Плагіни → Додати новий → Завантажити) кожна
	 * версія потрапляла в окрему теку й у списку плагінів з'являвся дублікат.
	 *
	 * Тут ми ще до копіювання перейменовуємо розпаковану теку:
	 *  - ручне встановлення ZIP → завжди в теку "wc-nova-express" (за назвою
	 *    головного файлу плагіна). Якщо вона вже є, WordPress пропонує «Замінити
	 *    поточну завантаженою» — нова тека не створюється;
	 *  - автооновлення з адмінки → у теку вже встановленого плагіна, щоб оновлення
	 *    завжди лягало поверх наявної копії, навіть якщо вона лежить в іншій теці.
	 *
	 * Фільтр спрацьовує лише для пакета з файлом цього плагіна; інші плагіни, теми
	 * та ядро не зачіпаються.
	 *
	 * @param string|\WP_Error $source        Розпакована тека з пакета (зі слешем в кінці).
	 * @param string           $remote_source Робоча тека розпакування.
	 * @param \WP_Upgrader     $upgrader      Екземпляр апгрейдера.
	 * @param array            $hook_extra    Додаткові дані операції.
	 * @return string|\WP_Error
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ! ( $upgrader instanceof \Plugin_Upgrader ) || ! is_object( $wp_filesystem ) ) {
			return $source;
		}

		$updated_plugin = ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) ) ? $hook_extra['plugin'] : '';

		// Оновлюється інша копія/інший плагін — не чіпаємо.
		if ( '' !== $updated_plugin && $updated_plugin !== $this->plugin_slug ) {
			return $source;
		}

		// Автооновлення → тека встановленого плагіна; ручне встановлення → "wc-nova-express".
		$proper_folder = ( '' !== $updated_plugin ) ? dirname( $this->plugin_slug ) : basename( $this->plugin_file, '.php' );
		if ( '.' === $proper_folder || '' === $proper_folder ) {
			return $source;
		}

		$source_dir = untrailingslashit( $source );
		if ( basename( $source_dir ) === $proper_folder || $source_dir === untrailingslashit( $remote_source ) ) {
			return $source;
		}

		// Переконуємось, що в пакеті саме цей плагін (головний файл із тією ж назвою плагіна).
		$main_file = trailingslashit( $source_dir ) . basename( $this->plugin_file );
		if ( ! $wp_filesystem->exists( $main_file ) ) {
			return $source;
		}
		$package_head  = (string) $wp_filesystem->get_contents( $main_file );
		$own           = get_file_data( $this->plugin_file, array( 'Name' => 'Plugin Name' ) );
		$package_name  = preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', substr( $package_head, 0, 8192 ), $m ) ? trim( $m[1] ) : '';
		$names_match   = ( ! empty( $own['Name'] ) && $package_name === $own['Name'] );
		$is_our_plugin = $names_match || ( false !== stripos( $package_name, 'Nova Express' ) );
		if ( ! $is_our_plugin ) {
			return $source;
		}

		$new_source = trailingslashit( $remote_source ) . $proper_folder;
		if ( ! $wp_filesystem->move( $source_dir, $new_source, true ) ) {
			return new \WP_Error(
				'nvx_rename_failed',
				sprintf(
					/* translators: %s: plugin folder name */
					__( 'Не вдалося перейменувати розпаковану теку плагіна в «%s». Оновлення скасовано, щоб не створювати дублікат плагіна.', 'wc-nova-express' ),
					$proper_folder
				)
			);
		}

		return trailingslashit( $new_source );
	}

	private function get_latest_release( bool $bypass_cache = false ): ?array {
		$cache_key = 'nvx_github_latest_release';

		if ( ! $bypass_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$url      = "https://api.github.com/repos/{$this->repo_owner}/{$this->repo_name}/releases/latest";
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'WordPress/Nova-Express-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			return null;
		}

		// Кешуємо на 6 годин
		set_transient( $cache_key, $body, 6 * HOUR_IN_SECONDS );

		return $body;
	}

	private function get_download_package( array $release ): string {
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return '';
		}

		foreach ( $release['assets'] as $asset ) {
			if ( ! empty( $asset['name'] ) && 'wc-nova-express.zip' === $asset['name'] && ! empty( $asset['browser_download_url'] ) ) {
				return (string) $asset['browser_download_url'];
			}
		}

		return '';
	}
			}
		}

		// Якщо окремого асету немає — використовуємо zipball
		return $release['zipball_url'] ?? '';
	}

	/**
	 * AJAX: примусова перевірка оновлень (без кешів GitHub і WordPress).
	 */
	public function ajax_check_update(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		// Скидаємо кеші: власний transient і transient оновлень WordPress.
		delete_transient( 'nvx_github_latest_release' );
		delete_site_transient( 'update_plugins' );

		$release = $this->get_latest_release( true );

		if ( ! $release || empty( $release['tag_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Не вдалося зв’язатися з GitHub. Спробуйте пізніше.', 'wc-nova-express' ) ) );
		}

		$latest_version = ltrim( (string) $release['tag_name'], 'v' );
		$update_available = version_compare( $latest_version, $this->version, '>' );

		wp_send_json_success(
			array(
				'current_version'  => $this->version,
				'latest_version'   => $latest_version,
				'update_available' => $update_available,
				'can_reinstall'    => true,
				'changelog'        => (string) ( $release['body'] ?? '' ),
				'html_url'         => (string) ( $release['html_url'] ?? '' ),
			)
		);
	}

	/**
	 * AJAX: запуск стандартного механізму оновлення WordPress для цього плагіна.
	 */
	public function ajax_run_update(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостатньо прав.', 'wc-nova-express' ) ), 403 );
		}

		check_ajax_referer( 'nvx_admin_nonce', 'nonce' );

		// Отримуємо найсвіжіший реліз з GitHub напряму без кешу.
		delete_transient( 'nvx_github_latest_release' );
		delete_site_transient( 'update_plugins' );

		$release = $this->get_latest_release( true );
		if ( ! $release || empty( $release['tag_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Не вдалося отримати дані релізу з GitHub. Спробуйте пізніше.', 'wc-nova-express' ) ) );
		}

		$package = $this->get_download_package( $release );
		if ( empty( $package ) ) {
			wp_send_json_error( array( 'message' => __( 'Не знайдено ZIP-архів релізу на GitHub.', 'wc-nova-express' ) ) );
		}

		// Примусово прописуємо пакет у transient 'update_plugins', щоб WordPress
		// міг встановити/перевстановити його, навіть якщо номер версії збігається
		// з поточною встановленою версією (коли версію перезаписано на GitHub).
		$latest_version = ltrim( (string) $release['tag_name'], 'v' );
		$transient      = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$obj              = new \stdClass();
		$obj->slug        = dirname( $this->plugin_slug );
		$obj->new_version = $latest_version;
		$obj->url         = "https://github.com/{$this->repo_owner}/{$this->repo_name}";
		$obj->package     = $package;
		$obj->plugin      = $this->plugin_slug;

		$transient->response[ $this->plugin_slug ] = $obj;
		set_site_transient( 'update_plugins', $transient );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$was_active        = is_plugin_active( $this->plugin_slug );
		$is_network_active = is_plugin_active_for_network( $this->plugin_slug );

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $this->plugin_slug );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		if ( false === $result ) {
			$skin_errors = $skin->get_errors();
			$err_msg     = ( is_wp_error( $skin_errors ) && $skin_errors->has_errors() )
				? $skin_errors->get_error_message()
				: __( 'Оновлення не вдалося встановити. Перевірте з’єднання з GitHub або права на запис у теку wp-content/plugins.', 'wc-nova-express' );
			wp_send_json_error( array( 'message' => $err_msg ) );
		}

		// Plugin_Upgrader деактивує плагін перед оновленням файлів (deactivate_plugins).
		// Обов'язково повторно активуємо його після завершення оновлення.
		$plugin_to_activate = $this->plugin_slug;
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_to_activate ) ) {
			$candidates = array(
				'wc-nova-express/wc-nova-express.php',
				'nova-express-woocommerce/wc-nova-express.php',
			);
			foreach ( $candidates as $candidate ) {
				if ( file_exists( WP_PLUGIN_DIR . '/' . $candidate ) ) {
					$plugin_to_activate = $candidate;
					break;
				}
			}
		}

		activate_plugin( $plugin_to_activate, '', $is_network_active, true );

		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %s: new version */
					__( 'Плагін оновлено до версії %s. Сторінка буде перезавантажена.', 'wc-nova-express' ),
					esc_html( $this->get_installed_version() )
				),
				'version' => $this->get_installed_version(),
			)
		);
	}

	/**
	 * Поточна версія встановленого плагіна (з головного файлу, а не константи —
	 * після оновлення константа в запиті вже стара).
	 */
	private function get_installed_version(): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $this->plugin_file, false, false );

		return (string) ( $data['Version'] ?? $this->version );
	}
}
