<?php

namespace NovaExpress\Ttn;

use NovaExpress\Admin\Settings;
use NovaExpress\Api\Exception\NovaPoshtaApiException;
use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Helpers\Formatting;
use NovaExpress\Shipping\PriceCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Формує запит на створення ТТН з даних замовлення WooCommerce (сумісно з HPOS)
 * та зберігає результат у власній таблиці.
 */
class TtnManager {

	public const SERVICE_WAREHOUSE_WAREHOUSE = 'warehouse_warehouse';
	public const SERVICE_WAREHOUSE_DOORS     = 'warehouse_doors';
	public const SERVICE_DOORS_WAREHOUSE     = 'doors_warehouse';
	public const SERVICE_DOORS_DOORS         = 'doors_doors';

	private NovaPoshtaClient $client;
	private TtnRepository $repository;

	public function __construct( NovaPoshtaClient $client, TtnRepository $repository ) {
		$this->client     = $client;
		$this->repository = $repository;
	}

	/**
	 * @throws NovaPoshtaApiException
	 */
	public function create_for_order( \WC_Order $order, array $overrides = array() ): array {
		$this->guard_single_active_ttn( $order );

		// Захист від подвійного кліку/подвійної вкладки, поки перший запит
		// ще в польоті: без цього локальний guard_single_active_ttn() вище
		// нічого не бачить (запис у нашій таблиці з'явиться лише ПІСЛЯ
		// успішної відповіді Nova Poshta), тому паралельний другий виклик
		// міг проскочити і створити другу реальну ТТН в кабінеті НП.
		$lock_key   = 'nvx_ttn_creating_' . $order->get_id();
		$lock_token = $this->acquire_lock( $lock_key, 2 * MINUTE_IN_SECONDS );
		if ( ! $lock_token ) {
			throw new NovaPoshtaApiException(
				__( 'Створення ТТН для цього замовлення вже виконується (попередній запит ще обробляється). Зачекайте кілька секунд і перевірте кабінет Нової Пошти, перш ніж повторювати.', 'wc-nova-express' )
			);
		}

		$service_type = $overrides['service_type'] ?? $order->get_meta( '_nvx_service_type' ) ?: self::SERVICE_WAREHOUSE_WAREHOUSE;

		$payload = $this->build_payload( $order, $service_type, $overrides );

		try {
			$result = $this->client->create_waybill( $payload );
		} catch ( NovaPoshtaApiException $e ) {
			$is_timeout = false !== stripos( $e->getMessage(), 'timeout' ) || false !== stripos( $e->getMessage(), 'timed out' );

			if ( $is_timeout ) {
				// Навмисно НЕ знімаємо лок тут: ми не знаємо, чи ТТН
				// фактично створилась на боці Nova Poshta (запит міг дійти
				// і виконатись, а відповідь — не встигнути повернутись).
				// Лок сам згасне за 2 хв (self-heal, як і раніше), а до
				// того часу негайний повторний клік буде заблокований —
				// саме цього не вистачало в попередній версії.
				throw new NovaPoshtaApiException(
					sprintf(
						/* translators: %s: original error message */
						__( 'Немає відповіді від Nova Poshta впродовж очікування (timeout): %s. НЕ створюйте ТТН повторно наосліп — спершу перевірте кабінет Нової Пошти: якщо накладна там вже з\'явилась, додайте її номер через "Додати наявну ТТН" замість повторного створення.', 'wc-nova-express' ),
						$e->getMessage()
					)
				);
			}

			// Звичайна помилка API (валідація тощо) — точно відомо, що ТТН
			// не створено, тож немає причин тримати лок і затримувати повтор.
			$this->release_lock( $lock_key, $lock_token );
			throw $e;
		}

		try {
			$id = $this->repository->insert(
				array(
					'order_id'       => $order->get_id(),
					'waybill_number' => $result['IntDocNumber'],
					'document_ref'   => $result['Ref'] ?? '',
					'service_type'   => $service_type,
				)
			);
		} catch ( \RuntimeException $e ) {
			$this->release_lock( $lock_key, $lock_token );
			// ТТН уже в НП — повідомляємо явно, щоб адмін не створював дубль.
			throw new NovaPoshtaApiException(
				sprintf(
					/* translators: 1: waybill number, 2: error */
					__( 'ТТН №%1$s створено в Новій Пошті, але не збережено локально: %2$s. Не створюйте повторно — додайте існуючий номер.', 'wc-nova-express' ),
					$result['IntDocNumber'] ?? '',
					$e->getMessage()
				)
			);
		}

		$this->release_lock( $lock_key, $lock_token );

		// Лише мета + нотатка. Статус замовлення / видалення — поза межами create_for_order.
		// Автоматизації (у т.ч. change_status) йдуть окремо через do_action( nvx/ttn_created ).
		$order->update_meta_data( '_nvx_waybill_number', $result['IntDocNumber'] );
		$order->update_meta_data( '_nvx_waybill_ref', $result['Ref'] ?? '' );
		$order->update_meta_data( '_nvx_service_type', $service_type );
		$order->add_order_note(
			sprintf(
				/* translators: %s: waybill number */
				__( 'Nova Express Woo: створено ТТН №%s.', 'wc-nova-express' ),
				$result['IntDocNumber']
			)
		);
		$order->save();

		// Одразу опитуємо реальний статус (замість очікування наступного циклу
		// крону) — щоб на картці замовлення відразу було видно щось змістовніше
		// за "Очікує опитування" (наприклад, "Номер відправлення зареєстровано").
		$this->refresh_status_now( $id, $result['IntDocNumber'] );

		$row = $this->repository->find_by_id( $id );
		/**
		 * Подія: ТТН створено (для автоматизацій з тригером ttn_created).
		 */
		do_action( 'nvx/ttn_created', $order, $row ?: array(
			'id'             => $id,
			'waybill_number' => $result['IntDocNumber'],
			'document_ref'   => $result['Ref'] ?? '',
		), 'created' );

		return array(
			'id'             => $id,
			'waybill_number' => $result['IntDocNumber'],
			'ref'            => $result['Ref'] ?? '',
			'cost'           => $result['CostOnSite'] ?? null,
			'estimated_date' => $result['EstimatedDeliveryDate'] ?? null,
		);
	}

	/**
	 * На одне замовлення дозволено лише один активний ТТН — якщо він уже є,
	 * створення/додавання нового блокується (спершу треба видалити наявний).
	 *
	 * @throws NovaPoshtaApiException
	 */
	private function guard_single_active_ttn( \WC_Order $order ): void {
		$existing = $this->repository->find_by_order( $order->get_id() );

		if ( ! empty( $existing ) ) {
			throw new NovaPoshtaApiException(
				__( 'У цього замовлення вже є активний ТТН. Спочатку видаліть його, щоб створити новий.', 'wc-nova-express' )
			);
		}
	}

	/**
	 * Ручне (за запитом адміна, поза циклом крону) оновлення статусу однієї ТТН.
	 * Якщо Nova Poshta більше не знає про цю накладну (видалена на боці НП) —
	 * прибираємо запис з нашої бази й повертаємо ['deleted' => true], щоб
	 * інтерфейс показав кнопки "Створити"/"Додати" знову.
	 *
	 * @throws NovaPoshtaApiException
	 */
	public function refresh_single( int $ttn_id, ?\WC_Order $order = null ): array {
		$row = $this->repository->find_by_id( $ttn_id );

		if ( ! $row ) {
			throw new NovaPoshtaApiException( __( 'ТТН не знайдено в базі плагіна.', 'wc-nova-express' ) );
		}

		if ( $order instanceof \WC_Order && (int) $row['order_id'] !== (int) $order->get_id() ) {
			throw new NovaPoshtaApiException( __( 'ТТН не належить цьому замовленню.', 'wc-nova-express' ) );
		}

		// Та сама логіка, що й cron: подія automation + без небезпечного auto-delete.
		$runner = new \NovaExpress\Tracking\TrackingRunner(
			$this->client,
			$this->repository,
			new \NovaExpress\Automation\RuleEngine( new \NovaExpress\Automation\RuleRepository() )
		);

		return $runner->refresh_one( $row, $order );
	}

	/**
	 * Nova Poshta булевого поля "видалено" в getStatusDocuments —
	 * визначаємо за характерним текстом відповіді, коли номер більше не існує.
	 */
	private function looks_deleted( array $status ): bool {
		$text = mb_strtolower( ( $status['Status'] ?? '' ) . ' ' . ( $status['StatusCode'] ?? '' ) );

		foreach ( array( 'не знайдено', 'не існує', 'not found', 'номер не знайдено' ) as $needle ) {
			if ( false !== mb_strpos( $text, $needle ) ) {
				return true;
			}
		}

		// Порожній Status + порожній/нульовий StatusCode при наявному Number — часто означає «немає в системі».
		$code = trim( (string) ( $status['StatusCode'] ?? '' ) );
		$st   = trim( (string) ( $status['Status'] ?? '' ) );
		if ( ( '' === $code || '0' === $code ) && '' === $st ) {
			return true;
		}

		return false;
	}

	/**
	 * Миттєве оновлення статусу після створення ТТН.
	 * Помилки навмисно не кидаються далі — свіжостворена ТТН у Нової Пошти інколи
	 * ще не встигає з'явитись у трекінгу за перші секунди, це не критично,
	 * її підхопить черговий цикл крону.
	 */
	private function refresh_status_now( int $id, string $waybill_number ): void {
		try {
			$statuses = $this->client->get_statuses( array( array( 'DocumentNumber' => $waybill_number ) ) );

			if ( ! empty( $statuses[0] ) && ! $this->looks_deleted( $statuses[0] ) ) {
				$status_code = (string) ( $statuses[0]['StatusCode'] ?? '' );
				$this->repository->update_status(
					$id,
					$status_code,
					$statuses[0]['Status'] ?? '',
					in_array( $status_code, array( '9', '10', '11' ), true ),
					$statuses[0]
				);
			}
		} catch ( \Throwable $e ) {
			// Свідомо ігноруємо — див. коментар вище.
		}
	}

	/**
	 * Повне видалення ТТН: спроба видалити на боці Нової Пошти (best-effort —
	 * якщо накладна вже роздрукована/використана, API може відмовити, але
	 * локальний запис все одно прибираємо, щоб не засмічувати картку замовлення)
	 * та видалення запису з власної таблиці.
	 */
	public function delete_waybill( int $ttn_id, ?\WC_Order $order = null, bool $force_local = false ): void {
		$row = $this->repository->find_by_id( $ttn_id );

		if ( ! $row ) {
			throw new NovaPoshtaApiException( __( 'ТТН не знайдено в базі плагіна.', 'wc-nova-express' ) );
		}

		if ( $order instanceof \WC_Order && (int) $row['order_id'] !== (int) $order->get_id() ) {
			throw new NovaPoshtaApiException( __( 'ТТН не належить цьому замовленню.', 'wc-nova-express' ) );
		}

		$np_deleted = false;
		$np_message = '';
		$tried_np   = false;

		try {
			if ( $this->client->has_api_key() ) {
				$ref = (string) ( $row['document_ref'] ?? '' );
				if ( '' === $ref && $order instanceof \WC_Order ) {
					$ref = (string) $order->get_meta( '_nvx_waybill_ref' );
				}
				if ( '' === $ref ) {
					$ref = $this->client->find_document_ref( $row['waybill_number'] );
				}
				if ( '' !== $ref ) {
					$tried_np   = true;
					$np_deleted = $this->client->delete_waybill( $ref );
					if ( ! $np_deleted ) {
						$np_message = __( 'Нова Пошта не підтвердила видалення (можливо, ТТН уже прийнята/в дорозі).', 'wc-nova-express' );
					}
				} else {
					$np_message = __( 'Не вдалося визначити Ref накладної — видалення в кабінеті Нової Пошти пропущено.', 'wc-nova-express' );
				}
			}
		} catch ( \Throwable $e ) {
			$tried_np   = true;
			$np_deleted = false;
			$np_message = $e->getMessage();
		}

		// Якщо НП не видалила і це не force_local — не чіпаємо локальний запис.
		if ( $tried_np && ! $np_deleted && ! $force_local ) {
			throw new NovaPoshtaApiException(
				sprintf(
					/* translators: 1: waybill, 2: reason */
					__( 'ТТН №%1$s не видалено: %2$s Локальний запис збережено. За потреби видаліть лише локально (force).', 'wc-nova-express' ),
					$row['waybill_number'],
					$np_message ?: __( 'відмова API', 'wc-nova-express' )
				)
			);
		}

		$this->repository->delete( $ttn_id );

		if ( $order instanceof \WC_Order ) {
			if ( $order->get_meta( '_nvx_waybill_number' ) === $row['waybill_number'] ) {
				$order->delete_meta_data( '_nvx_waybill_number' );
				$order->delete_meta_data( '_nvx_waybill_ref' );
			}
			if ( $np_deleted ) {
				$note = sprintf(
					__( 'Nova Express Woo: ТТН №%s видалено локально та в кабінеті Нової Пошти.', 'wc-nova-express' ),
					$row['waybill_number']
				);
			} elseif ( $force_local ) {
				$note = sprintf(
					__( 'Nova Express Woo: ТТН №%1$s видалено лише локально (примусово). У НП: %2$s', 'wc-nova-express' ),
					$row['waybill_number'],
					$np_message ?: __( 'не змінювалось', 'wc-nova-express' )
				);
			} else {
				$note = sprintf(
					__( 'Nova Express Woo: ТТН №%s видалено локально (у кабінеті НП запис не знайдено).', 'wc-nova-express' ),
					$row['waybill_number']
				);
			}
			$order->add_order_note( $note );
			$order->save();
		}
	}

	/**
	 * Прив'язка вже існуючої ТТН (створеної, наприклад, вручну в кабінеті НП)
	 * до замовлення — без виклику InternetDocument.save. Номер перевіряється
	 * через TrackingDocument.getStatusDocuments, щоб не зберігати "порожню" ТТН.
	 *
	 * @throws NovaPoshtaApiException
	 */
	public function attach_existing( \WC_Order $order, string $waybill_number ): array {
		$this->guard_single_active_ttn( $order );

		$lock_key   = 'nvx_ttn_creating_' . $order->get_id();
		$lock_token = $this->acquire_lock( $lock_key, 60 );
		if ( ! $lock_token ) {
			throw new NovaPoshtaApiException( __( 'Операція з ТТН для цього замовлення вже триває. Зачекайте хвилину.', 'wc-nova-express' ) );
		}

		try {
			return $this->do_attach_existing( $order, $waybill_number );
		} finally {
			$this->release_lock( $lock_key, $lock_token );
		}
	}

	private function do_attach_existing( \WC_Order $order, string $waybill_number ): array {

		$waybill_number = trim( $waybill_number );

		if ( '' === $waybill_number ) {
			throw new NovaPoshtaApiException( __( 'Не вказано номер ТТН.', 'wc-nova-express' ) );
		}

		if ( $this->repository->find_by_waybill_number( $waybill_number ) ) {
			throw new NovaPoshtaApiException( __( 'Ця ТТН вже додана в системі.', 'wc-nova-express' ) );
		}

		$statuses = $this->client->get_statuses( array( array( 'DocumentNumber' => $waybill_number ) ) );

		if ( empty( $statuses[0] ) ) {
			throw new NovaPoshtaApiException( __( 'Nova Poshta не знайшла ТТН з таким номером.', 'wc-nova-express' ) );
		}

		$status = $statuses[0];

		// Ref для подальшого видалення в кабінеті НП (з трекінгу або getDocumentList).
		$document_ref = (string) ( $status['Ref'] ?? $status['RefEW'] ?? '' );
		if ( '' === $document_ref ) {
			try {
				$document_ref = $this->client->find_document_ref( $waybill_number );
			} catch ( \Throwable $e ) {
				$document_ref = '';
			}
		}

		$id = $this->repository->insert(
			array(
				'order_id'       => $order->get_id(),
				'waybill_number' => $waybill_number,
				'document_ref'   => $document_ref,
				'service_type'   => $order->get_meta( '_nvx_service_type' ) ?: self::SERVICE_WAREHOUSE_WAREHOUSE,
			)
		);

		$this->repository->update_status(
			$id,
			(string) ( $status['StatusCode'] ?? '' ),
			(string) ( $status['Status'] ?? '' ),
			in_array( (string) ( $status['StatusCode'] ?? '' ), array( '9', '10', '11' ), true ),
			$status
		);

		$order->update_meta_data( '_nvx_waybill_number', $waybill_number );
		if ( '' !== $document_ref ) {
			$order->update_meta_data( '_nvx_waybill_ref', $document_ref );
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: waybill number */
				__( 'Nova Express Woo: додано наявну ТТН №%s.', 'wc-nova-express' ),
				$waybill_number
			)
		);
		$order->save();

		$row = $this->repository->find_by_id( $id );
		do_action( 'nvx/ttn_created', $order, $row ?: array(
			'id'             => $id,
			'waybill_number' => $waybill_number,
			'document_ref'   => $document_ref,
		), 'attached' );

		return array(
			'id'             => $id,
			'waybill_number' => $waybill_number,
			'status_text'    => $status['Status'] ?? '',
			'ref'            => $document_ref,
		);
	}

	/**
	 * Формування тіла запиту InternetDocument.save з урахуванням варіанту сервісу,
	 * даних одержувача та переліку місць (кожне зі своєю вагою/габаритами).
	 *
	 * Nova Poshta вимагає посилатись на контрагентів за Ref (не просто ім'ям/телефоном):
	 * Sender/ContactSender — контрагент-відправник (беремо з налаштувань, куди він
	 * потрапляє через "Отримати дані відправника" в адмінці); Recipient/ContactRecipient —
	 * контрагент-отримувач, якого ми створюємо (або перевикористовуємо за телефоном)
	 * автоматично прямо тут через Counterparty.save.
	 *
	 * @throws NovaPoshtaApiException
	 */
	private function build_payload( \WC_Order $order, string $service_type, array $overrides ): array {
		$settings = Settings::get_all();

		if ( empty( $settings['sender_counterparty_ref'] ) || empty( $settings['sender_contact_ref'] ) ) {
			throw new NovaPoshtaApiException(
				__( 'Не налаштовано контрагента-відправника. Перейдіть у Nova Express Woo → Налаштування та натисніть «Отримати дані відправника автоматично».', 'wc-nova-express' )
			);
		}

		// Профіль відправника (основне або додаткове відділення).
		$sender_profile = Settings::get_sender_profile( (string) ( $overrides['sender_id'] ?? 'primary' ) );
		if ( ! $sender_profile ) {
			$sender_profile = array(
				'city_ref'        => $settings['sender_city_ref'] ?? '',
				'warehouse_ref'   => $settings['sender_warehouse_ref'] ?? '',
				'counterparty_ref'=> $settings['sender_counterparty_ref'],
				'contact_ref'     => $settings['sender_contact_ref'],
				'phone'           => $settings['sender_phone'] ?? '',
			);
		}

		$sender_type    = in_array( $service_type, array( self::SERVICE_DOORS_WAREHOUSE, self::SERVICE_DOORS_DOORS ), true ) ? 'Doors' : 'Warehouse';
		$recipient_type = in_array( $service_type, array( self::SERVICE_WAREHOUSE_DOORS, self::SERVICE_DOORS_DOORS ), true ) ? 'Doors' : 'Warehouse';

		$places = $this->normalize_places( $overrides['places'] ?? array(), $order );

		$total_weight  = array_sum( array_column( $places, 'weight' ) );
		$declared_cost = $overrides['declared_cost'] ?? $this->order_total_for_declaration( $order );

		$recipient_last  = $overrides['recipient_last_name'] ?? $order->get_shipping_last_name() ?: $order->get_billing_last_name();
		$recipient_first = $overrides['recipient_first_name'] ?? $order->get_shipping_first_name() ?: $order->get_billing_first_name();
		$recipient_mid   = $overrides['recipient_middle_name'] ?? '';
		$recipient_phone = Formatting::normalize_phone( $overrides['recipient_phone'] ?? $order->get_billing_phone() );
		$recipient_email = $overrides['recipient_email'] ?? $order->get_billing_email();

		if ( '' === $recipient_phone ) {
			throw new NovaPoshtaApiException( __( 'Не вдалось розпізнати телефон отримувача — перевірте номер.', 'wc-nova-express' ) );
		}

		$city_ref      = $overrides['recipient_city_ref'] ?? $order->get_meta( '_nvx_city_ref' );
		$warehouse_ref = $overrides['recipient_warehouse_ref'] ?? $order->get_meta( '_nvx_warehouse_ref' );
		$street_ref    = $overrides['recipient_street_ref'] ?? $order->get_meta( '_nvx_street_ref' );
		$building      = $overrides['recipient_building'] ?? $order->get_meta( '_nvx_building_number' );
		$apartment     = $overrides['recipient_apartment'] ?? $order->get_meta( '_nvx_apartment' );

		if ( empty( $city_ref ) ) {
			throw new NovaPoshtaApiException( __( 'Не обрано місто отримувача.', 'wc-nova-express' ) );
		}
		if ( 'Warehouse' === $recipient_type && empty( $warehouse_ref ) ) {
			throw new NovaPoshtaApiException( __( 'Не обрано відділення/поштомат отримувача.', 'wc-nova-express' ) );
		}
		if ( 'Doors' === $recipient_type && empty( $building ) ) {
			throw new NovaPoshtaApiException( __( 'Не вказано номер будинку отримувача.', 'wc-nova-express' ) );
		}

		// Габарити конкретного відділення/поштомата отримувача часто МЕНШІ за
		// загальний запобіжник 120 см нижче (особливо у поштоматів — комірки
		// різного розміру). Перевіряємо ДО створення контрагента й відправки
		// запиту в API, щоб дати зрозумілу помилку одразу, замість загадкового
		// "Max param value sizes is …" від Нової Пошти (і не витрачати зайвий
		// виклик Counterparty.save, якщо все одно не влізе).
		if ( 'Warehouse' === $recipient_type && ! empty( $warehouse_ref ) ) {
			$this->assert_places_fit_warehouse( $places, (string) $warehouse_ref );
		}

		// Контрагент-отримувач: Nova Poshta для приватної особи автоматично
		// створює (або повертає наявну за номером телефону) і саму контактну особу.
		$recipient_counterparty = $this->client->save_counterparty(
			array(
				'CounterpartyType'     => 'PrivatePerson',
				'CounterpartyProperty' => 'Recipient',
				'FirstName'            => $recipient_first ?: 'Клієнт',
				'LastName'             => $recipient_last ?: '—',
				'MiddleName'           => $recipient_mid,
				'Phone'                => $recipient_phone,
				'Email'                => $recipient_email,
			)
		);

		$recipient_ref         = $recipient_counterparty['Ref'] ?? '';
		$recipient_contact_ref = $recipient_counterparty['ContactPerson']['data'][0]['Ref']
			?? $recipient_counterparty['ContactPerson'][0]['Ref']
			?? '';

		if ( '' === $recipient_contact_ref ) {
			// Резервний варіант: якщо API не повернув контактну особу одразу в тілі відповіді —
			// запитуємо її окремо (для приватної особи вона завжди є, з тим самим Ref, що і контрагент).
			$contacts = $this->client->get_counterparty_contacts( $recipient_ref );
			$recipient_contact_ref = $contacts[0]['Ref'] ?? $recipient_ref;
		}

		if ( empty( $recipient_ref ) ) {
			throw new NovaPoshtaApiException( __( 'Не вдалося створити контрагента-отримувача в Nova Poshta.', 'wc-nova-express' ) );
		}

		$payload = array(
			'NewAddress'     => '1',
			'PayerType'      => $overrides['payer_type'] ?? 'Recipient',
			'PaymentMethod'  => $overrides['payment_method'] ?? 'Cash',
			'DateTime'       => $overrides['date'] ?? current_time( 'd.m.Y' ),
			'CargoType'      => $overrides['cargo_type'] ?? 'Parcel',
			'ServiceType'    => $this->map_service_type( $service_type ),
			'SeatsAmount'    => (string) max( 1, count( $places ) ),
			'Description'    => $overrides['description'] ?? Formatting::apply_order_template(
				$settings['description_template'] ?: __( 'Замовлення №{order_number}', 'wc-nova-express' ),
				$order
			),
			'Info'           => array_key_exists( 'additional_info', $overrides ) && null !== $overrides['additional_info']
				? (string) $overrides['additional_info']
				: Formatting::resolve_additional_info(
					(string) ( $settings['additional_info_template'] ?? '' ),
					$order,
					(string) ( $settings['additional_info_contains'] ?? '' )
				),
			'Cost'            => (string) $declared_cost,
			'Weight'          => (string) ( $total_weight > 0 ? $total_weight : $this->order_weight( $order ) ),
			'CitySender'      => $sender_profile['city_ref'] ?? ( $settings['sender_city_ref'] ?? '' ),
			'Sender'          => $sender_profile['counterparty_ref'] ?? $settings['sender_counterparty_ref'],
			'ContactSender'   => $sender_profile['contact_ref'] ?? $settings['sender_contact_ref'],
			'SendersPhone'    => Formatting::normalize_phone( $sender_profile['phone'] ?? ( $settings['sender_phone'] ?? '' ) ),
			'CityRecipient'   => $city_ref,
			'Recipient'       => $recipient_ref,
			'ContactRecipient' => $recipient_contact_ref,
			'RecipientsPhone' => $recipient_phone,
		);

		// OptionsSeat: габарити в см + фактична вага.
		// volumetricVolume НЕ передаємо як W*H*L/4000 — у частини відповідей API
		// це поле трактується як об'єм у м³, і тоді «50 см» дає помилку «максимальний розмір».
		// Офіційні приклади НП для Parcel часто передають лише weight + volumetric*.
		$payload['OptionsSeat'] = array_map(
			static function ( $place ) {
				$w = min( 120.0, max( 1.0, (float) $place['width'] ) );
				$h = min( 120.0, max( 1.0, (float) $place['height'] ) );
				$l = min( 120.0, max( 1.0, (float) $place['length'] ) );
				$wt = max( 0.1, (float) $place['weight'] );

				return array(
					'weight'           => (string) round( $wt, 2 ),
					'volumetricWidth'  => (string) round( $w, 1 ),
					'volumetricLength' => (string) round( $l, 1 ),
					'volumetricHeight' => (string) round( $h, 1 ),
				);
			},
			$places
		);

		// Вага документа — не менша за суму місць і не менша за об'ємну вагу (L*W*H/4000).
		$vol_weight = 0.0;
		foreach ( $places as $place ) {
			$w = min( 120.0, max( 1.0, (float) $place['width'] ) );
			$h = min( 120.0, max( 1.0, (float) $place['height'] ) );
			$l = min( 120.0, max( 1.0, (float) $place['length'] ) );
			$vol_weight += ( $w * $h * $l ) / 4000;
		}
		$payload['Weight'] = (string) round( max( (float) $payload['Weight'], $total_weight, $vol_weight, 0.1 ), 2 );

		if ( 'Warehouse' === $sender_type ) {
			$wh = $sender_profile['warehouse_ref'] ?? ( $settings['sender_warehouse_ref'] ?? '' );
			if ( empty( $wh ) ) {
				throw new NovaPoshtaApiException( __( 'Не вказано відділення відправника в налаштуваннях.', 'wc-nova-express' ) );
			}
			$payload['SenderAddress'] = $wh;
		} else {
			$payload['SenderAddress']  = $settings['sender_street_ref'] ?? '';
			$payload['SenderBuilding'] = $settings['sender_building'] ?? '';
		}

		// Ключовий фікс: Ref відділення отримувача передається саме як
		// "RecipientAddress" (у попередній версії помилково використовувалось
		// неіснуюче поле "RecipientWarehouseIndex", тому API відхиляло запит).
		if ( 'Warehouse' === $recipient_type ) {
			$payload['RecipientAddress'] = $warehouse_ref;
		} else {
			$payload['RecipientStreetRef']   = $street_ref;
			$payload['BuildingNumber']       = $building;
			$payload['NoteAddressRecipient'] = $apartment;
		}

		return apply_filters( 'nvx/waybill_payload', $payload, $order, $service_type );
	}

	/**
	 * Перевіряє, чи влазять габарити місць у комірку конкретного відділення/поштомата
	 * (SendingLimitationsOnDimensions з локального кешу довідника відділень).
	 * Якщо ліміту в кеші немає (звичайне велике відділення, або довідник ще не
	 * синхронізований) — нічого не перевіряємо, лишається загальний запобіжник 120 см
	 * у build_payload().
	 *
	 * @throws NovaPoshtaApiException
	 */
	private function assert_places_fit_warehouse( array $places, string $warehouse_ref ): void {
		$limit = ( new \NovaExpress\Warehouse\WarehouseRepository() )->find_dimension_limit( $warehouse_ref );
		if ( null === $limit ) {
			return;
		}

		$known_sides = array_filter(
			array( $limit['width'], $limit['height'], $limit['length'] ),
			static function ( $v ) {
				return null !== $v;
			}
		);

		if ( empty( $known_sides ) ) {
			return;
		}

		// Найсуворіше з відомих обмежень — найменша заявлена сторона комірки: саме
		// вона визначає, чи фізично влізе посилка (НП сама повертає одне число в
		// тексті помилки, тому й тут звіряємось за одним, найстрогішим значенням).
		$max_side = min( $known_sides );

		foreach ( $places as $place ) {
			$biggest_side = max( (float) $place['width'], (float) $place['height'], (float) $place['length'] );

			if ( $biggest_side > $max_side ) {
				throw new NovaPoshtaApiException(
					sprintf(
						/* translators: 1: найбільша сторона місця (см), 2: ліміт відділення (см) */
						__( 'Розміри посилки (найбільша сторона — %1$s см) перевищують ліміт обраного відділення/поштомата отримувача (%2$s см). Зменшіть габарити місця в замовленні або оберіть інше відділення/поштомат для доставки.', 'wc-nova-express' ),
						self::format_cm( $biggest_side ),
						self::format_cm( $max_side )
					)
				);
			}
		}
	}

	/**
	 * Число сантиметрів без зайвих нулів після коми (40 замість 40.0, 40.5 лишається як є).
	 */
	private static function format_cm( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * Нормалізує перелік місць з форми (вага/ширина/висота/довжина).
	 * Якщо місця не передані — одне місце з розрахованою вагою замовлення.
	 */
	private function normalize_places( array $raw_places, \WC_Order $order ): array {
		if ( empty( $raw_places ) ) {
			return array(
				array(
					'weight' => $this->order_weight( $order ),
					'width'  => 10,
					'height' => 10,
					'length' => 10,
				),
			);
		}

		$places = array();

		foreach ( $raw_places as $place ) {
			$places[] = array(
				'weight' => max( 0.1, (float) ( $place['weight'] ?? 0.5 ) ),
				'width'  => max( 1, (float) ( $place['width'] ?? 10 ) ),
				'height' => max( 1, (float) ( $place['height'] ?? 10 ) ),
				'length' => max( 1, (float) ( $place['length'] ?? 10 ) ),
			);
		}

		return $places;
	}

	private function map_service_type( string $service_type ): string {
		return PriceCalculator::map_service_type( $service_type );
	}

	private function order_total_for_declaration( \WC_Order $order ): float {
		return max( 100, round( (float) $order->get_total(), 2 ) );
	}

	private function order_weight( \WC_Order $order ): float {
		$weight = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product && $product->has_weight() ) {
				$weight += (float) $product->get_weight() * $item->get_quantity();
			}
		}

		return $weight > 0 ? round( $weight, 2 ) : 0.5;
	}

	public function repository(): TtnRepository {
		return $this->repository;
	}
	/**
	 * Атомарне захоплення блокування операції (захист від race condition при подвійному кліку)
	 * з генерацією унікального токена власника (lock owner token).
	 *
	 * @return string|null Токен блокування при успіху або null, якщо блокування вже зайняте.
	 */
	private function acquire_lock( string $key, int $ttl = 120 ): ?string {
		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$acquired = (bool) wp_cache_add( $key, $token, 'nvx_locks', $ttl );
			return $acquired ? $token : null;
		}

		$now     = time();
		$current = get_option( $key );

		if ( is_array( $current ) && isset( $current['expires'] ) ) {
			if ( (int) $current['expires'] > $now ) {
				return null;
			}
		} elseif ( is_numeric( $current ) && (int) $current > $now ) {
			// Зворотна сумісність із попереднім числовим форматом timestamp.
			return null;
		}

		delete_option( $key );
		$payload = array(
			'token'   => $token,
			'expires' => $now + $ttl,
		);

		$added = (bool) add_option( $key, $payload, '', 'no' );
		return $added ? $token : null;
	}

	/**
	 * Звільнення блокування лише якщо токен збігається з поточним власником.
	 */
	private function release_lock( string $key, ?string $token = null ): void {
		if ( null === $token || '' === $token ) {
			return;
		}

		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$stored_token = wp_cache_get( $key, 'nvx_locks' );
			if ( $stored_token === $token ) {
				wp_cache_delete( $key, 'nvx_locks' );
			}
			return;
		}

		$current = get_option( $key );
		if ( is_array( $current ) && isset( $current['token'] ) ) {
			if ( hash_equals( (string) $current['token'], (string) $token ) ) {
				delete_option( $key );
			}
		} elseif ( is_numeric( $current ) ) {
			// Зворотна сумісність: старий формат без токена
			delete_option( $key );
		}
	}
}
