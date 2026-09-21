<?php

namespace NovaExpress\Shipping;

use NovaExpress\Api\NovaPoshtaClient;
use NovaExpress\Ttn\TtnManager;

defined( 'ABSPATH' ) || exit;

/**
 * Єдине місце розрахунку вартості доставки через Nova Poshta API
 * (InternetDocument.getDocumentPrice) та мапінгу внутрішнього типу
 * сервісу ("warehouse_warehouse" тощо) у формат Nova Poshta API
 * ("WarehouseWarehouse" тощо).
 *
 * Раніше ця логіка була продубльована в NovaExpressShippingMethod
 * (checkout) і OrderAjax (адмінський калькулятор) — двома окремими
 * реалізаціями, які могли розійтися при майбутніх змінах. Тепер обидва
 * місця користуються цим класом.
 */
class PriceCalculator {

	/**
	 * @return array<string,string> внутрішній тип сервісу => формат Nova Poshta API
	 */
	public static function service_map(): array {
		return array(
			TtnManager::SERVICE_WAREHOUSE_WAREHOUSE => 'WarehouseWarehouse',
			TtnManager::SERVICE_WAREHOUSE_DOORS     => 'WarehouseDoors',
			TtnManager::SERVICE_DOORS_WAREHOUSE     => 'DoorsWarehouse',
			TtnManager::SERVICE_DOORS_DOORS         => 'DoorsDoors',
		);
	}

	/**
	 * Безпечний мапінг для значень, що вже походять із наших власних
	 * констант/збережених даних (не з прямого користувацького вводу) —
	 * якщо раптом щось невідоме, тихо повертає дефолт, аби не зламати
	 * внутрішній потік там, де вхідне значення вже мало бути валідним.
	 */
	public static function map_service_type( string $service_type ): string {
		$map = self::service_map();

		return $map[ $service_type ] ?? 'WarehouseWarehouse';
	}

	/**
	 * Строгий мапінг для значень, що приходять напряму від користувача
	 * (POST-параметр із форми checkout/адмінки). На відміну від
	 * map_service_type() тут немає тихого фолбеку — невідоме значення
	 * має бути явною помилкою запиту, а не мовчки підмінятись іншим
	 * сервісом доставки (могло призвести до розбіжності між тим, що
	 * обрав клієнт, і тим, що фактично порахувалось/створилось).
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function map_service_type_strict( string $service_type ): string {
		$map = self::service_map();

		if ( ! isset( $map[ $service_type ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: service type value */
					__( 'Невідомий тип доставки: %s', 'wc-nova-express' ),
					$service_type
				)
			);
		}

		return $map[ $service_type ];
	}

	/**
	 * @return string[] дозволені внутрішні значення service_type
	 */
	public static function allowed_service_types(): array {
		return array_keys( self::service_map() );
	}

	/**
	 * Розрахунок вартості (InternetDocument.getDocumentPrice) з одним
	 * автоповтором лише при таймауті з'єднання (не при бізнес-помилці
	 * API — таку повторювати немає сенсу).
	 *
	 * @param array<string,string> $params CitySender, CityRecipient, Weight, ServiceType, Cost, CargoType, SeatsAmount.
	 * @return array{ok:bool,cost:?float,message:?string,raw:array}
	 */
	public static function calculate( NovaPoshtaClient $client, array $params ): array {
		$result = null;
		$error  = null;

		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			try {
				$result = $client->calculate_price( $params );
				$error  = null;
				break;
			} catch ( \Throwable $e ) {
				$error = $e;
				$is_timeout = false !== stripos( $e->getMessage(), 'timeout' ) || false !== stripos( $e->getMessage(), 'timed out' );
				if ( ! $is_timeout ) {
					break;
				}
			}
		}

		if ( $error ) {
			return array(
				'ok'      => false,
				'cost'    => null,
				'message' => $error->getMessage(),
				'raw'     => array(),
			);
		}

		if ( ! isset( $result['Cost'] ) ) {
			return array(
				'ok'      => false,
				'cost'    => null,
				'message' => __( 'Nova Poshta не повернула вартість доставки.', 'wc-nova-express' ),
				'raw'     => $result ?: array(),
			);
		}

		return array(
			'ok'      => true,
			'cost'    => (float) $result['Cost'],
			'message' => null,
			'raw'     => $result,
		);
	}
}
