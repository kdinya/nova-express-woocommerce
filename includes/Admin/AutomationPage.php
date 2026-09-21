<?php

namespace NovaExpress\Admin;

use NovaExpress\Automation\RuleRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Сторінка керування правилами автоматизації (WooCommerce > Nova Express: автоматизації).
 * Дані зберігаються/віддаються через AJAX (NovaExpress\Ajax\AutomationAjax),
 * ця сторінка лише рендерить каркас та передає список статусів у JS.
 */
class AutomationPage {

	private RuleRepository $repository;

	public function __construct( RuleRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		// Меню більше не реєструємо — вкладка в AdminPage.
	}

	/**
	 * @param string $kind ttn|order
	 */
	public function render_content( string $kind = 'ttn' ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$kind = ( 'order' === $kind ) ? 'order' : 'ttn';

		try {
			$rules          = $this->repository->all_by_kind( $kind );
			$recent_log     = $this->repository->recent_log( 30 );
			$statuses       = ( 'order' === $kind ) ? $this->order_status_triggers() : $this->carrier_statuses();
			$status_help    = ( 'order' === $kind ) ? array() : $this->carrier_status_help();
			$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			$rule_kind      = $kind;

			include NVX_PLUGIN_DIR . 'includes/Admin/views/automation-page.php';
		} catch ( \Throwable $e ) {
			printf(
				'<div class="notice notice-error"><p><strong>Nova Express:</strong> %s</p><p><code>%s:%d</code></p></div>',
				esc_html( $e->getMessage() ),
				esc_html( $e->getFile() ),
				(int) $e->getLine()
			);
		}
	}

	/**
	 * Статуси замовлення WC як тригери (ключ без префікса wc-).
	 *
	 * @return array<string,string>
	 */
	public function order_status_triggers(): array {
		$out = array( 'any' => __( 'Будь-яка зміна статусу замовлення', 'wc-nova-express' ) );
		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return $out;
		}
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$code         = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			$out[ $code ] = $label;
		}
		return $out;
	}

	/**
	 * Скорочений довідник статусів Nova Poshta (StatusCode => назва) для випадаючого списку
	 * тригера правила.
	 */
		public function carrier_statuses(): array {
		return array(
			'ttn_created' => __( 'ТТН створено або додано', 'wc-nova-express' ),
			'1'           => __( '1 — Нова пошта очікує надходження від відправника', 'wc-nova-express' ),
			'2'           => __( '2 — Видалено (накладну видалено/скасовано)', 'wc-nova-express' ),
			'3'           => __( '3 — Номер не знайдено', 'wc-nova-express' ),
			'4'           => __( '4 — Відправлення у місті відправника', 'wc-nova-express' ),
			'41'          => __( '41 — Локальне сортування у місті відправника', 'wc-nova-express' ),
			'5'           => __( '5 — Відправлення прямує до міста одержувача', 'wc-nova-express' ),
			'6'           => __( '6 — Прибуло у місто одержувача', 'wc-nova-express' ),
			'7'           => __( '7 — Прибуло на відділення (готове до видачі)', 'wc-nova-express' ),
			'8'           => __( '8 — Прибуло у поштомат / відділення (очікує видачі)', 'wc-nova-express' ),
			'9'           => __( '9 — Відправлення отримано (успішно вручено)', 'wc-nova-express' ),
			'10'          => __( '10 — Отримано (адресна доставка кур\'єром / грошовий переказ)', 'wc-nova-express' ),
			'11'          => __( '11 — Отримано (часткова видача)', 'wc-nova-express' ),
			'12'          => __( '12 — Нова Пошта комплектує відправлення', 'wc-nova-express' ),
			'14'          => __( '14 — Перенаправлено / змінено адресу', 'wc-nova-express' ),
			'101'         => __( '101 — На шляху до одержувача (кур\'єр)', 'wc-nova-express' ),
			'102'         => __( '102 — Відмова одержувача', 'wc-nova-express' ),
			'103'         => __( '103 — Відмова (повернення оформлено)', 'wc-nova-express' ),
			'104'         => __( '104 — Зміна адреси / переадресація', 'wc-nova-express' ),
			'105'         => __( '105 — Припинено зберігання (минув термін)', 'wc-nova-express' ),
			'106'         => __( '106 — Повернення отримано відправником', 'wc-nova-express' ),
			'111'         => __( '111 — Невдала спроба доставки', 'wc-nova-express' ),
			'112'         => __( '112 — Доставку перенесено', 'wc-nova-express' ),
		);
	}

	public function carrier_status_help(): array {
		return array(
			'ttn_created' => array(
				'title' => __( 'ТТН створено або додано', 'wc-nova-express' ),
				'help'  => __( 'Накладну щойно сформовано або вручну прив\'язано до замовлення. Посилку ще не передано перевізнику.', 'wc-nova-express' ),
			),
			'1'   => array(
				'title' => __( 'Очікує надходження', 'wc-nova-express' ),
				'help'  => __( 'ТТН зареєстровано в системі, Нова пошта очікує фактичного надходження посилки від відправника.', 'wc-nova-express' ),
			),
			'2'   => array(
				'title' => __( 'Видалено', 'wc-nova-express' ),
				'help'  => __( 'Експрес-накладну видалено або скасовано в системі Нової Пошти.', 'wc-nova-express' ),
			),
			'3'   => array(
				'title' => __( 'Номер не знайдено', 'wc-nova-express' ),
				'help'  => __( 'Вказаний номер ТТН не знайдено в системі Нової Пошти.', 'wc-nova-express' ),
			),
			'4'   => array(
				'title' => __( 'У місті відправника', 'wc-nova-express' ),
				'help'  => __( 'Відправлення прийнято у відділенні або терміналі в місті відправника.', 'wc-nova-express' ),
			),
			'41'  => array(
				'title' => __( 'Локальне сортування', 'wc-nova-express' ),
				'help'  => __( 'Відправлення проходить сортування у терміналі міста відправника.', 'wc-nova-express' ),
			),
			'5'   => array(
				'title' => __( 'Прямує до одержувача', 'wc-nova-express' ),
				'help'  => __( 'Відправлення прямує між сортувальними терміналами до міста одержувача.', 'wc-nova-express' ),
			),
			'6'   => array(
				'title' => __( 'Прибуло у місто одержувача', 'wc-nova-express' ),
				'help'  => __( 'Посилка прибула у сортувальний термінал міста призначення і готується до відправки у відділення або кур\'єру.', 'wc-nova-express' ),
			),
			'7'   => array(
				'title' => __( 'Прибуло на відділення (готове до видачі)', 'wc-nova-express' ),
				'help'  => __( 'Посилка розвантажена на вказаному відділенні й готова до видачі клієнту. Чудовий момент надіслати SMS або перевести статус у «Прибуло у відділення».', 'wc-nova-express' ),
			),
			'8'   => array(
				'title' => __( 'У поштоматі / відділенні', 'wc-nova-express' ),
				'help'  => __( 'Посилку завантажено у поштомат або прийнято на відділенні, очікує отримання клієнтом.', 'wc-nova-express' ),
			),
			'9'   => array(
				'title' => __( 'Відправлення отримано (успішно)', 'wc-nova-express' ),
				'help'  => __( 'Клієнт забрав відправлення у відділенні чи поштоматі. Фінальний успішний статус — переведення замовлення у «Виконано».', 'wc-nova-express' ),
			),
			'10'  => array(
				'title' => __( 'Отримано (кур\'єр / переказ)', 'wc-nova-express' ),
				'help'  => __( 'Адресну доставку виконано або видано грошовий переказ.', 'wc-nova-express' ),
			),
			'11'  => array(
				'title' => __( 'Отримано частково', 'wc-nova-express' ),
				'help'  => __( 'Часткова видача місць відправлення клієнту.', 'wc-nova-express' ),
			),
			'12'  => array(
				'title' => __( 'Комплектується', 'wc-nova-express' ),
				'help'  => __( 'Нова Пошта комплектує відправлення.', 'wc-nova-express' ),
			),
			'14'  => array(
				'title' => __( 'Перенаправлено', 'wc-nova-express' ),
				'help'  => __( 'Одержувач або відправник оформив переадресацію.', 'wc-nova-express' ),
			),
			'101' => array(
				'title' => __( 'Передано кур\'єру', 'wc-nova-express' ),
				'help'  => __( 'Посилку видано кур\'єру, прямує за адресою одержувача.', 'wc-nova-express' ),
			),
			'102' => array(
				'title' => __( 'Відмова одержувача', 'wc-nova-express' ),
				'help'  => __( 'Клієнт відмовився від отримання посилки. Можна змінити статус замовлення на «Скасовано» або сповістити менеджера.', 'wc-nova-express' ),
			),
			'103' => array(
				'title' => __( 'Відмова (повернення оформлено)', 'wc-nova-express' ),
				'help'  => __( 'Клієнт відмовився, Нова Пошта сформувала зворотну накладну до відправника.', 'wc-nova-express' ),
			),
			'104' => array(
				'title' => __( 'Зміна адреси', 'wc-nova-express' ),
				'help'  => __( 'Адресу доставки або відділення було змінено.', 'wc-nova-express' ),
			),
			'105' => array(
				'title' => __( 'Припинено зберігання', 'wc-nova-express' ),
				'help'  => __( 'Минув встановлений термін зберігання посилки у відділенні.', 'wc-nova-express' ),
			),
			'106' => array(
				'title' => __( 'Повернення отримано відправником', 'wc-nova-express' ),
				'help'  => __( 'Повернене відправлення вручено назад відправнику.', 'wc-nova-express' ),
			),
			'111' => array(
				'title' => __( 'Невдала спроба доставки', 'wc-nova-express' ),
				'help'  => __( 'Кур’єру не вдалося вручити посилку (клієнт не відповідав або був відсутній).', 'wc-nova-express' ),
			),
			'112' => array(
				'title' => __( 'Доставку перенесено', 'wc-nova-express' ),
				'help'  => __( 'Дату чи час доставки перенесено за домовленістю.', 'wc-nova-express' ),
			),
		);
	}
}
