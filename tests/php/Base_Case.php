<?php
/**
 * Shared set-up: clean tables, fixed clock, fake HTTP (Gemini), store factory synced into WooCommerce Analytics.
 *
 * @package Dev3\AiStoreInsights
 */

use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Core\Cache;
use Dev3\AiStoreInsights\Core3\Core\Schema;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store;
use Dev3\AiStoreInsights\Core3\Data\Usage_Repository;
use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;

abstract class Dev3si_Base_Case extends WP_UnitTestCase {

	const NOW = '2026-10-25 12:00:00';

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Fake replies in order (each [ status, body ]); the last repeats.
	 *
	 * @var array
	 */
	protected $replies = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SI_DB_VERSION );
		global $wpdb;
		foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_customer_lookup', 'wc_order_coupon_lookup', 'dev3si_questions', 'dev3si_alerts' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" ); // phpcs:ignore -- DML so the test transaction rolls it back.
		}
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client     = null;
		Period::$now    = self::NOW;
		Store::reset();
		delete_transient( 'dev3si_locations' );
		delete_option( 'dev3si_alert_rules' );
		delete_option( 'dev3si_alerts_checked' );
		delete_option( 'dev3si_digests' );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'start_of_week', 1 );
		update_option( 'woocommerce_notify_low_stock_amount', 5 );
		update_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
		$this->requests = array();
		$this->replies  = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'dev3si_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3si_jobs_use_action_scheduler', '__return_false' );
		reset_phpmailer_instance();
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3si_jobs_use_action_scheduler', '__return_false' );
		Period::$now = null;
		Store::reset();
		parent::tear_down();
	}

	/**
	 * Saves an API key so AI calls reach the fake HTTP layer.
	 */
	protected function with_key() {
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Settings_Store::flush();
	}

	/**
	 * Gemini response with a text part.
	 *
	 * @param mixed $data Array (JSON-encoded) or text.
	 * @return array
	 */
	protected function gemini_text( $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => is_string( $data ) ? $data : wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 100,
					'candidatesTokenCount' => 50,
					'totalTokenCount'      => 150,
				),
			),
		);
	}

	/**
	 * Gemini response with function calls.
	 *
	 * @param array $calls [ [ name, args ] ].
	 * @return array
	 */
	protected function gemini_calls( array $calls ) {
		$parts = array();
		foreach ( $calls as $call ) {
			$parts[] = array(
				'functionCall' => array(
					'name' => $call[0],
					'args' => $call[1],
				),
			);
		}
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => $parts ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array( 'totalTokenCount' => 90 ),
			),
		);
	}

	/**
	 * pre_http_request handler.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|\WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'body' => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
		);
		if ( ! $this->replies ) {
			return new WP_Error( 'http_blocked', 'No fake for ' . $url );
		}
		$reply = count( $this->replies ) > 1 ? array_shift( $this->replies ) : $this->replies[0];
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply[1] ),
			'response' => array(
				'code'    => $reply[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Category term id.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	protected function category( $name ) {
		$term = term_exists( $name, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $name, 'product_cat' );
		}
		return (int) $term['term_id'];
	}

	/**
	 * Simple product.
	 *
	 * @param string   $name  Name.
	 * @param float    $price Price.
	 * @param int[]    $cats  Category ids.
	 * @param int|null $stock Managed stock (null = unmanaged).
	 * @return WC_Product_Simple
	 */
	protected function product( $name, $price, array $cats = array(), $stock = null ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( (string) $price );
		$p->set_sku( sanitize_title( $name ) );
		if ( $cats ) {
			$p->set_category_ids( $cats );
		}
		if ( null !== $stock ) {
			$p->set_manage_stock( true );
			$p->set_stock_quantity( $stock );
		}
		$p->save();
		return wc_get_product( $p->get_id() );
	}

	/**
	 * Creates an order and syncs it into the Analytics tables.
	 *
	 * @param array $o { date, items [ [ product, qty ] ], country, state, city, email, status, customer }.
	 * @return WC_Order
	 */
	protected function order( array $o ) {
		$order = wc_create_order( array( 'customer_id' => isset( $o['customer'] ) ? (int) $o['customer'] : 0 ) );
		foreach ( $o['items'] as $line ) {
			$order->add_product( $line[0], $line[1] );
		}
		$order->set_billing_first_name( $o['first'] ?? 'Sam' );
		$order->set_billing_last_name( $o['last'] ?? 'Buyer' );
		$order->set_billing_email( $o['email'] ?? 'buyer@example.com' );
		$order->set_billing_country( $o['country'] ?? 'US' );
		$order->set_billing_state( $o['state'] ?? '' );
		$order->set_billing_city( $o['city'] ?? 'Austin' );
		$order->set_date_created( $o['date'] );
		$order->calculate_totals( false );
		$order->set_status( $o['status'] ?? 'completed' );
		$order->save();
		$this->sync( $order->get_id() );
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Imports an order (or refund) into the Analytics tables.
	 *
	 * @param int $id Id.
	 */
	protected function sync( $id ) {
		OrdersScheduler::import( $id );
		Store::reset();
		delete_transient( 'dev3si_locations' );
	}

	/**
	 * Refunds one line item.
	 *
	 * @param WC_Order $order  Order.
	 * @param float    $amount Amount.
	 * @param string   $date   Date.
	 * @return WC_Order_Refund
	 */
	protected function refund( WC_Order $order, $amount, $date ) {
		$items = $order->get_items();
		$item  = reset( $items );
		$ref   = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => array(
					$item->get_id() => array(
						'qty'          => 1,
						'refund_total' => $amount,
					),
				),
			)
		);
		$ref->set_date_created( $date );
		$ref->save();
		$this->sync( $order->get_id() );
		$this->sync( $ref->get_id() );
		return $ref;
	}

	/**
	 * A small store: perfume (Fragrance) + candles (Home) sold in Dubai, Berlin and Austin.
	 *
	 * @return array Products.
	 */
	protected function seed_store() {
		$frag    = $this->category( 'Fragrance' );
		$home    = $this->category( 'Home' );
		$perfume = $this->product( 'Desert Rose Perfume', 100, array( $frag ), 9 );
		$candle  = $this->product( 'Oud Candle', 20, array( $home ), 200 );
		$soap    = $this->product( 'Amber Soap', 10, array( $home ), 6 );
		// Last month (September 2026).
		$this->order(
			array(
				'date'    => '2026-09-05 10:00:00',
				'items'   => array( array( $perfume, 2 ) ),
				'country' => 'AE',
				'state'   => 'DU',
				'city'    => 'Dubai',
				'email'   => 'aisha@example.com',
				'first'   => 'Aisha',
				'last'    => 'Khan',
			)
		);
		$this->order(
			array(
				'date'    => '2026-09-12 10:00:00',
				'items'   => array( array( $candle, 5 ) ),
				'country' => 'AE',
				'state'   => 'DU',
				'city'    => 'Dubai',
				'email'   => 'omar@example.com',
				'first'   => 'Omar',
			)
		);
		$this->order(
			array(
				'date'    => '2026-09-20 10:00:00',
				'items'   => array( array( $perfume, 1 ), array( $candle, 1 ) ),
				'country' => 'DE',
				'city'    => 'Berlin',
				'email'   => 'lena@example.com',
				'first'   => 'Lena',
			)
		);
		// Excluded status: never counted.
		$this->order(
			array(
				'date'    => '2026-09-21 10:00:00',
				'items'   => array( array( $perfume, 10 ) ),
				'country' => 'AE',
				'city'    => 'Dubai',
				'status'  => 'failed',
			)
		);
		// This month (October 2026): Aisha returns.
		$this->order(
			array(
				'date'    => '2026-10-03 10:00:00',
				'items'   => array( array( $perfume, 1 ) ),
				'country' => 'AE',
				'state'   => 'DU',
				'city'    => 'Dubai',
				'email'   => 'aisha@example.com',
				'first'   => 'Aisha',
				'last'    => 'Khan',
			)
		);
		$this->order(
			array(
				'date'  => '2026-10-20 09:00:00',
				'items' => array( array( $soap, 3 ), array( $candle, 2 ) ),
				'city'  => 'Austin',
				'state' => 'TX',
				'email' => 'joe@example.com',
				'first' => 'Joe',
			)
		);
		return compact( 'perfume', 'candle', 'soap', 'frag', 'home' );
	}
}
