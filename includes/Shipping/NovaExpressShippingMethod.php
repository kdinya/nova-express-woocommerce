<?php

namespace NovaExpress\Shipping;

defined( 'ABSPATH' ) || exit;

/**
 * Метод доставки "Нова Пошта".
 *
 * Свідомо НЕ рахує і НЕ додає жодної суми до замовлення на чекауті: на
 * момент оформлення реальні вага й габарити посилки ще не відомі
 * (кошик може не мати коректної ваги товару, кількість місць/пакування
 * визначає комірник уже після збирання замовлення) — тож будь-яка
 * цифра, показана тут, була б відвертою здогадкою, а не тим, що
 * реально порахує Nova Poshta.
 *
 * Реальна вартість рахується вручну в адмінці на картці замовлення
 * (кнопка "Порахувати вартість" / автоматично при створенні ЕН, де
 * адмін вводить фактичну вагу) — саме там дані для розрахунку вже
 * достовірні. Дивись OrderAjax::calculate_delivery_price() та
 * PriceCalculator.
 *
 * Сам рядок доставки на чекауті додатково прихований візуально
 * (assets/css/checkout.css) — метод обирається "мовчки" (єдиний
 * доступний варіант), лише щоб розкрити поля вибору
 * міста/відділення Нової Пошти.
 */
class NovaExpressShippingMethod extends \WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'nova_express';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Нова Пошта (Nova Express Woo)', 'wc-nova-express' );
		$this->method_description = __( 'Доставка Новою Поштою. Вартість на чекауті не показується й не додається до замовлення — рахується вручну в адмінці, коли відома фактична вага посилки.', 'wc-nova-express' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', __( 'Нова Пошта', 'wc-nova-express' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = array(
			'title' => array(
				'title'       => __( 'Назва методу', 'wc-nova-express' ),
				'type'        => 'text',
				'default'     => __( 'Нова Пошта', 'wc-nova-express' ),
				'description' => __( 'Показується покупцю на оформленні (рядок доставки при цьому прихований — див. Nova Express Woo → Налаштування). Вартість не рахується й не додається на чекауті; лише вручну в адмінці, де відома фактична вага.', 'wc-nova-express' ),
			),
		);
	}

	public function calculate_shipping( $package = array() ): void {
		// Плагін не впливає на вартість на чекауті: метод завжди з нульовою ціною,
		// вартість доставки сплачується в Новій Пошті при отриманні.
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $this->title,
				'cost'    => 0,
				'package' => $package,
			)
		);
	}
}
