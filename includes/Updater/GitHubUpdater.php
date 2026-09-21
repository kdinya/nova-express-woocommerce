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
		$res->name          = 'Nova Express for WooCommerce';
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
	 * Вирівнює назву теки розпакованого архіву з текою вже встановленого плагіна.
	 *
	 * Архіви з GitHub ("Source code (zip)" / zipball) розпаковуються в теку на
	 * кшталт "nova-express-woocommerce-2026.09.5" (або "kdinya-…-<sha>" для
	 * автооновлення). WordPress встановлює плагін у теку з такою самою назвою, тож
	 * при ручному встановленні ZIP (Плагіни → Додати новий → Завантажити) кожна
	 * версія потрапляла в окрему теку й у списку плагінів з'являвся дублікат.
	 *
	 * Тут ми ще до копіювання перейменовуємо розпаковану теку на теку поточного
	 * (вже встановленого) плагіна. Тоді WordPress бачить, що така тека існує, і
	 * пропонує «Замінити поточну завантаженою» (ручне встановлення) або просто
	 * перезаписує її (автооновлення) — нова тека більше не створюється.
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

		// Оновлюється інша копія/інший плагін — не чіпаємо.
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] !== $this->plugin_slug ) {
			return $source;
		}

		$proper_folder = dirname( $this->plugin_slug );
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
		$package_head = (string) $wp_filesystem->get_contents( $main_file );
		$own          = get_file_data( $this->plugin_file, array( 'Name' => 'Plugin Name' ) );
		if ( empty( $own['Name'] ) || ! preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', substr( $package_head, 0, 8192 ), $m ) || trim( $m[1] ) !== $own['Name'] ) {
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

	private function get_latest_release(): ?array {
		$cache_key = 'nvx_github_latest_release';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
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
		// Шукаємо завантажений zip-асет у релізі
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		// Якщо окремого асету немає — використовуємо zipball
		return $release['zipball_url'] ?? '';
	}
}
