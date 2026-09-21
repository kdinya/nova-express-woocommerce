<?php

namespace NovaExpress\Api;

use NovaExpress\Api\Exception\NovaPoshtaApiException;

defined( 'ABSPATH' ) || exit;

/**
 * Тонкий клієнт до офіційного Nova Poshta API 2.0 (JSON RPC-подібний,
 * єдиний ендпоінт https://api.novaposhta.ua/v2.0/json/).
 *
 * Документація: https://developers.novaposhta.ua/
 */
class NovaPoshtaClient {

	private const ENDPOINT = 'https://api.novaposhta.ua/v2.0/json/';

	private string $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	public function set_api_key( string $api_key ): void {
		$this->api_key = $api_key;
	}

	public function has_api_key(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Пошук населених пунктів (Address.searchSettlements) — предиктивний ввід міста.
	 */
	public function search_settlements( string $query, int $limit = 15 ): array {
		$response = $this->call(
			'Address',
			'searchSettlements',
			array(
				'CityName' => $query,
				'Limit'    => $limit,
			)
		);

		return $response['data'][0]['Addresses'] ?? array();
	}

	/**
	 * Список відділень/поштоматів у населеному пункті (Address.getWarehouses).
	 */
	/**
	 * Загальна кількість відділень у базі Нової Пошти (з info.totalCount).
	 */
	public function get_warehouses_total_count(): ?int {
		try {
			$props = array(
				'Page'  => '1',
				'Limit' => '1',
			);
			$response = $this->call( 'Address', 'getWarehouses', $props, 15 );
			if ( isset( $response['info']['totalCount'] ) ) {
				return (int) $response['info']['totalCount'];
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	public function get_warehouses_page( int $page = 1, int $limit = 150 ): array {
		$props = array(
			'Page'     => (string) max( 1, $page ),
			'Limit'    => (string) max( 1, min( 500, $limit ) ),
		);
		$response = $this->call( 'Address', 'getWarehouses', $props, 60 );
		return $response['data'] ?? array();
	}

	public function get_warehouses( string $settlement_ref, string $find_by_string = '', int $page = 1, int $limit = 50 ): array {
		$props = array(
			'Page'  => $page,
			'Limit' => $limit,
		);

		// Порожній SettlementRef = повна база відділень по всій Україні
		// (використовується під час фонової синхронізації).
		if ( '' !== $settlement_ref ) {
			$props['SettlementRef'] = $settlement_ref;
		}

		if ( '' !== $find_by_string ) {
			$props['FindByString'] = $find_by_string;
		}

		$response = $this->call( 'Address', 'getWarehouses', $props );

		return $response['data'] ?? array();
	}

	/**
	 * Пошук вулиць у населеному пункті (Address.searchSettlementStreets) — для адресної доставки.
	 */
	public function search_streets( string $settlement_ref, string $query ): array {
		$response = $this->call(
			'Address',
			'searchSettlementStreets',
			array(
				'SettlementRef' => $settlement_ref,
				'StreetName'    => $query,
				'Limit'         => 20,
			)
		);

		return $response['data'][0]['Addresses'] ?? array();
	}

	/**
	 * Список контрагентів (за замовчуванням — відправник), прив'язаних до акаунту API-ключа.
	 * Використовується в налаштуваннях, щоб автоматично підтягнути Ref відправника,
	 * замість того щоб адмін вручну шукав його десь у кабінеті.
	 */
	public function get_counterparties( string $property = 'Sender', int $page = 1 ): array {
		$response = $this->call(
			'Counterparty',
			'getCounterparties',
			array(
				'CounterpartyProperty' => $property,
				'Page'                 => $page,
			)
		);

		return $response['data'] ?? array();
	}

	/**
	 * Створення (або перевикористання наявного за телефоном) контрагента —
	 * для приватної особи. Nova Poshta для типу PrivatePerson автоматично
	 * створює й пов'язану контактну особу з тим самим Ref, що і сам контрагент.
	 *
	 * @throws NovaPoshtaApiException
	 */
	public function save_counterparty( array $params ): array {
		$response = $this->call( 'Counterparty', 'save', $params );

		if ( empty( $response['data'][0] ) ) {
			throw new NovaPoshtaApiException(
				__( 'Nova Poshta API не повернув дані контрагента.', 'wc-nova-express' ),
				$response['errors'] ?? array()
			);
		}

		return $response['data'][0];
	}

	/**
	 * Розрахунок вартості доставки (InternetDocument.getDocumentPrice).
	 */
	public function calculate_price( array $params ): array {
		// Окремий timeout — getDocumentPrice інколи відповідає довше.
		$response = $this->call( 'InternetDocument', 'getDocumentPrice', $params, 60 );

		return $response['data'][0] ?? array();
	}

	/**
	 * Отримати чи створити контрагента-відправника з даних, збережених у налаштуваннях.
	 * Повертає Ref контрагента.
	 */
	/**
	 * Список контактних осіб контрагента. Метод належить до моделі "Counterparty"
	 * (не "ContactPerson" — раніше тут була помилка: неправильна назва моделі
	 * призводила до "Method ... not found" і, як наслідок, відправник
	 * лишався незаповненим, а створення ТТН завжди падало з "Sender is incorrect").
	 */
	public function get_counterparty_contacts( string $counterparty_ref ): array {
		$response = $this->call(
			'Counterparty',
			'getCounterpartyContactPersons',
			array( 'Ref' => $counterparty_ref )
		);

		return $response['data'] ?? array();
	}

	/**
	 * Створення експрес-накладної (ТТН/ТТН) — InternetDocument.save.
	 *
	 * @throws NovaPoshtaApiException
	 */
	public function create_waybill( array $params ): array {
		// Без автоповтору: timeout після успішного save на боці НП може створити другу ТТН.
		// При помилці з'єднання — користувач повторює вручну після перевірки кабінету.
		$response = $this->call( 'InternetDocument', 'save', $params, 90 );

		if ( empty( $response['data'][0] ) ) {
			throw new NovaPoshtaApiException(
				__( 'Nova Poshta API не повернув дані створеної накладної. Перевірте кабінет НП — накладна могла вже створитися.', 'wc-nova-express' ),
				$response['errors'] ?? array()
			);
		}

		return $response['data'][0];
	}

	/**
	 * Видалення ТТН (InternetDocument.delete) — наприклад, якщо адмін помилково створив накладну.
	 */
	public function delete_waybill( string $ref ): bool {
		$response = $this->call( 'InternetDocument', 'delete', array( 'DocumentRefs' => $ref ) );

		return ! empty( $response['success'] );
	}

	/**
	 * Пакетний запит статусів ТТН (TrackingDocument.getStatusDocuments).
	 * $documents: [['DocumentNumber' => '20450045632001', 'Phone' => '380671234567'], ...]
	 * До 100 накладних за один запит згідно з лімітами API.
	 */
	/**
	 * Знайти Ref накладної за номером ТТН (для видалення прив'язаних вручну ТТН).
	 */
	public function find_document_ref( string $waybill_number ): string {
		$response = $this->call(
			'InternetDocument',
			'getDocumentList',
			array(
				'IntDocNumber' => $waybill_number,
				'GetFullList'  => '0',
			)
		);

		$data = $response['data'] ?? array();
		if ( empty( $data[0]['Ref'] ) ) {
			return '';
		}

		return (string) $data[0]['Ref'];
	}

	public function get_statuses( array $documents ): array {
		if ( empty( $documents ) ) {
			return array();
		}

		$response = $this->call(
			'TrackingDocument',
			'getStatusDocuments',
			array( 'Documents' => $documents )
		);

		return $response['data'] ?? array();
	}

	/**
	 * Низькорівневий виклик API з єдиною точкою обробки помилок.
	 *
	 * @throws NovaPoshtaApiException
	 */
	private function call( string $model, string $method, array $properties, int $timeout = 60 ): array {
		if ( ! $this->has_api_key() ) {
			throw new NovaPoshtaApiException( __( 'Не вказано API-ключ Нової Пошти в налаштуваннях плагіна.', 'wc-nova-express' ) );
		}

		$body = array(
			'apiKey'           => $this->api_key,
			'modelName'        => $model,
			'calledMethod'     => $method,
			'methodProperties' => $properties,
		);

		$timeout = max( 30, $timeout );

		// Деякі хостинги ріжуть timeout до 10с — форсуємо через cURL.
		$curl_timeout = $timeout;
		$curl_hook = static function ( $handle ) use ( $curl_timeout ) {
			if ( is_resource( $handle ) || ( is_object( $handle ) && 'CurlHandle' === get_class( $handle ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, min( 20, $curl_timeout ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				curl_setopt( $handle, CURLOPT_TIMEOUT, $curl_timeout );
			}
		};
		add_action( 'http_api_curl', $curl_hook, 10, 1 );

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 3,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( $body ),
		);

		try {
			$response = wp_remote_post( self::ENDPOINT, $args );
		} finally {
			remove_action( 'http_api_curl', $curl_hook, 10 );
		}

		if ( is_wp_error( $response ) ) {
			throw new NovaPoshtaApiException(
				sprintf(
					/* translators: %s: transport error message */
					__( 'Помилка з\'єднання з Nova Poshta API: %s', 'wc-nova-express' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( 200 !== (int) $code || ! is_array( $data ) ) {
			throw new NovaPoshtaApiException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Nova Poshta API повернув неочікувану відповідь (HTTP %d).', 'wc-nova-express' ),
					$code
				)
			);
		}

		if ( empty( $data['success'] ) ) {
			$errors = ! empty( $data['errors'] ) ? $data['errors'] : ( $data['warnings'] ?? array() );
			$message = ! empty( $errors ) ? implode( '; ', array_map( 'strval', $errors ) ) : __( 'Невідома помилка Nova Poshta API.', 'wc-nova-express' );

			throw new NovaPoshtaApiException( self::humanize_error( $message ), $errors );
		}

		return $data;
	}

	/**
	 * Дописує зрозуміле пояснення до типових технічних помилок Nova Poshta API,
	 * не приховуючи оригінальний текст (він лишається — корисний для підтримки).
	 */
	private static function humanize_error( string $message ): string {
		if ( false !== mb_stripos( $message, 'max param value size' ) ) {
			return $message . ' ' . __(
				'— це означає, що одна зі сторін посилки (Ш/В/Д) завелика саме для обраного відділення/поштомата отримувача (не загальне обмеження плагіна чи Нової Пошти). У поштоматів комірки різного розміру, тож ліміт відрізняється від точки до точки. Зменшіть габарити місця в замовленні або оберіть інше відділення/поштомат для доставки.',
				'wc-nova-express'
			);
		}

		return $message;
	}
}
