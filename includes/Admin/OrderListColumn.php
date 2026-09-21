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

		$number = '';
		$status = '';

		if ( $row ) {
			$number = (string) ( $row['waybill_number'] ?? '' );
			$status = (string) ( $row['carrier_status_text'] ?: $row['carrier_status_code'] ?: '' );
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
		if ( '' !== $status ) {
			// Короткий статус — обрізаємо дуже довгі рядки.
			$short = $status;
			if ( function_exists( 'mb_strlen' ) && mb_strlen( $short ) > 42 ) {
				$short = mb_substr( $short, 0, 40 ) . '…';
			} elseif ( strlen( $short ) > 42 ) {
				$short = substr( $short, 0, 40 ) . '…';
			}
			echo '<span class="nvx-order-col-ttn__status" title="' . esc_attr( $status ) . '">' . esc_html( $short ) . '</span>';
		}
		echo '</div>';
	}
}
