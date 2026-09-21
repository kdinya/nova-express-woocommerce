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
		add_filter( 'upgrader_post_install', array( $this, 'post_install' ), 10, 3 );
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
		$res->author        = '<a href="https://github.com/' . esc_attr( $this->repo_owner ) . '">Nova Express</a>';
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
	 * Очищення та приведення каталогу плагіна до правильного імені після завантаження з GitHub.
	 */
	public function post_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		$proper_folder_name = dirname( $this->plugin_slug );

		// Якщо GitHub розпакував архів із суфіксом репозиторію/тегу у назві теки:
		if ( isset( $result['destination'] ) && basename( $result['destination'] ) !== $proper_folder_name ) {
			$correct_destination = trailingslashit( WP_PLUGIN_DIR ) . $proper_folder_name;
			if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
				$wp_filesystem->move( $result['destination'], $correct_destination );
			}
			$result['destination'] = $correct_destination;
		}

		return $result;
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
