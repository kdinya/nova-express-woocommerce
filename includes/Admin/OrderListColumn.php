<?php

namespace NovaExpress\Admin;

use NovaExpress\Ttn\TtnRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Стовпчик «ТТН» у списку замовлень (класичний CPT + HPOS).
 */
class OrderListColumn {

	private TtnRepository $repository;

	/**
	 * Кеш "order_id => рядок ТТН (або null)" на один HTTP-запит, щоб не робити
	 * SELECT для того самого замовлення двічі за один рендер сторінки.
	 *
	 * @var array<int,array<string,mixed>|null>
	 */
	private array $cache = array();

	public function __construct( ?TtnRepository $repository = null ) {
		$this->repository = $repository ?: new TtnRepository();
	}

	public function register(): void {
		// Classic (posts table).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 20, 2 );

		// Одним запитом наперед підвантажуємо ТТН для всіх замовлень, які WordPress
		// щойно вибрав для поточної сторінки списку (класичний CPT-список) —
		// замість окремого SELECT на кожен рядок у render_cell().
		add_filter( 'the_posts', array( $this, 'preload_for_classic_list' ), 10, 2 );

		// HPOS.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_hpos_column' ), 20, 2 );
	}

	/**
	 * Хук 'the_posts' спрацьовує один раз для основного запиту сторінки
	 * "Замовлення" (список shop_order) — саме перед рендером таблиці.
	 * Використовуємо його, щоб зібрати всі order_id одразу і зробити один
	 * запит `WHERE order_id IN (...)` замість запиту на кожен рядок.
	 *
	 * Стосується лише класичного (не-HPOS) списку замовлень: HPOS-список
	 * WooCommerce не використовує WP_Query, тому 'the_posts' для нього не
	 * спрацьовує — там render_hpos_column() і далі читає по одному рядку.
	 *
	 * @param \WP_Post[] $posts
	 * @param \WP_Query  $query
	 * @return \WP_Post[]
	 */
	public function preload_for_classic_list( array $posts, \WP_Query $query ): array {
		if ( is_admin() && $query->is_main_query() && 'shop_order' === $query->get( 'post_type' ) && ! empty( $posts ) ) {
			$order_ids   = wp_list_pluck( $posts, 'ID' );
			$this->cache = $this->cache + $this->repository->find_latest_for_orders( $order_ids );
		}

		return $posts;
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public function add_column( array $columns ): array {
		$new = array();
		$inserted = false;

		foreach ( $columns as $key => $label ) {
			// Перед сумою замовлення (order_total / total).
			if ( ! $inserted && in_array( $key, array( 'order_total', 'total' ), true ) ) {
				$new['nvx_ttn'] = __( 'ТТН НП', 'wc-nova-express' );
				$inserted      = true;
			}
			$new[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$new['nvx_ttn'] = __( 'ТТН НП', 'wc-nova-express' );
		}

		return $new;
	}

	/**
	 * Classic list table.
	 *
	 * @param string $column
	 * @param int    $post_id
	 */
	public function render_legacy_column( string $column, $post_id ): void {
		if ( 'nvx_ttn' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * HPOS list table.
	 *
	 * @param string    $column
	 * @param \WC_Order $order
	 */
	public function render_hpos_column( string $column, $order ): void {
		if ( 'nvx_ttn' !== $column ) {
			return;
		}
		if ( $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	private function render_cell( \WC_Order $order ): void {
		$order_id = $order->get_id();

		// Якщо для цього замовлення вже є результат у кеші (заповнений
		// preload_for_classic_list() одним запитом на всю сторінку) — беремо
		// звідти. Інакше (HPOS-список, або кеш ще не заповнений) — один
		// запит саме для цього замовлення (find_active_for_order() і так
		// повертає останній рядок за id DESC, окремий "запасний" запит не потрібен).
		if ( array_key_exists( $order_id, $this->cache ) ) {
			$row = $this->cache[ $order_id ];
		} else {
			$row                     = $this->repository->find_active_for_order( $order_id );
			$this->cache[ $order_id ] = $row;
		}

		$number  = '';
		$status  = '';
		$code    = '';
		$has_row = false;

		if ( $row ) {
			$has_row = true;
			$number  = (string) ( $row['waybill_number'] ?? '' );
			$code    = (string) ( $row['carrier_status_code'] ?? '' );
			$status  = (string) ( $row['carrier_status_text'] ?: $row['carrier_status_code'] ?: '' );
		}

		if ( '' === $number ) {
			$number = (string) $order->get_meta( '_nvx_waybill_number' );
		}

		if ( '' === $number ) {
			echo '<span class="nvx-order-col-ttn__empty">—</span>';
			return;
		}

		$track_url = 'https://novaposhta.ua/tracking/?cargo_number=' . rawurlencode( $number );

		echo '<div class="nvx-order-col-ttn">';
		echo '<a class="nvx-order-col-ttn__num" href="' . esc_url( $track_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $number ) . '</a>';
		$short = $has_row ? self::short_status( $code ) : null;

		if ( $short ) {
			// Короткий зрозумілий статус; повний текст від Нової Пошти — у підказці при наведенні.
			echo '<span class="nvx-order-col-ttn__status nvx-order-col-ttn__status--' . esc_attr( $short['tone'] ) . '" title="' . esc_attr( $status ) . '">' . esc_html( $short['label'] ) . '</span>';
		} elseif ( '' !== $status ) {
			// Невідомий код статусу — показуємо текст від Нової Пошти, обрізавши дуже довгі рядки.
			$text = $status;
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 42 ) {
				$text = mb_substr( $text, 0, 40 ) . '…';
			} elseif ( strlen( $text ) > 42 ) {
				$text = substr( $text, 0, 40 ) . '…';
			}
			echo '<span class="nvx-order-col-ttn__status" title="' . esc_attr( $status ) . '">' . esc_html( $text ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * Короткий статус ТТН для списку замовлень за кодом статусу Нової Пошти.
	 * Повний перелік кодів — у довідці на вкладці «Автоматизації ТТН».
	 *
	 * @param string $code Код статусу НП (порожній — ТТН щойно створено, ще не опитувалась).
	 * @return array{label:string,tone:string}|null null — код невідомий.
	 */
	private static function short_status( string $code ): ?array {
		switch ( $code ) {
			case '':
			case '1':
				return array( 'label' => __( 'Створено', 'wc-nova-express' ), 'tone' => 'new' );

			case '4':
			case '41':
			case '5':
			case '6':
			case '12':
			case '14':
			case '101':
			case '104':
			case '112':
				return array( 'label' => __( 'В дорозі', 'wc-nova-express' ), 'tone' => 'transit' );

			case '7':
			case '8':
				return array( 'label' => __( 'У відділенні', 'wc-nova-express' ), 'tone' => 'ready' );

			case '9':
			case '10':
			case '11':
				return array( 'label' => __( 'Отримано', 'wc-nova-express' ), 'tone' => 'done' );

			case '102':
			case '103':
				return array( 'label' => __( 'Відмова', 'wc-nova-express' ), 'tone' => 'problem' );

			case '105':
				return array( 'label' => __( 'Повертається', 'wc-nova-express' ), 'tone' => 'problem' );

			case '106':
				return array( 'label' => __( 'Повернено', 'wc-nova-express' ), 'tone' => 'problem' );

			case '111':
				return array( 'label' => __( 'Не вручено', 'wc-nova-express' ), 'tone' => 'problem' );

			case '2':
				return array( 'label' => __( 'Видалено', 'wc-nova-express' ), 'tone' => 'problem' );

			case '3':
				return array( 'label' => __( 'Не знайдено', 'wc-nova-express' ), 'tone' => 'problem' );
		}

		return null;
	}
}
