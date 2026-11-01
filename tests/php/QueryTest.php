<?php
/**
 * Query library numbers against seeded WooCommerce Analytics data (HPOS and legacy address joins).
 *
 * @package Dev3\AiStoreInsights
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;

class QueryTest extends Dev3si_Base_Case {

	public function test_periods_resolve_in_site_time() {
		$p = Period::resolve( 'last_month' );
		$this->assertSame( '2026-09-01 00:00:00', $p['from'] );
		$this->assertSame( '2026-09-30 23:59:59', $p['to'] );
		$c = Period::compare( $p, 'previous_period' );
		$this->assertSame( '2026-08-01 00:00:00', $c['from'] );
		$this->assertSame( '2026-08-31 23:59:59', $c['to'] );
		$y = Period::compare( $p, 'previous_year' );
		$this->assertSame( '2025-09-01 00:00:00', $y['from'] );
		$w = Period::resolve( 'this_week' ); // 25 Oct 2026 is a Sunday; weeks start Monday.
		$this->assertSame( '2026-10-19 00:00:00', $w['from'] );
		$l7 = Period::resolve( 'last_7_days' );
		$this->assertSame( '2026-10-19 00:00:00', $l7['from'] );
		$this->assertSame( 7, $l7['days'] );
		$prev = Period::compare( $l7, 'previous_period' );
		$this->assertSame( '2026-10-12 00:00:00', $prev['from'] );
		$this->assertSame( '2026-10-18 23:59:59', $prev['to'] );
		$custom = Period::resolve( 'custom', '2026-03-31', '2026-03-01' );
		$this->assertSame( '2026-03-01 00:00:00', $custom['from'], 'Reversed custom ranges are swapped.' );
		$this->assertSame( 'last_30_days', Period::resolve( 'custom', 'nope', '' )['preset'] );
		$this->assertCount( 7, Period::buckets( $l7['from'], $l7['to'], 'day' ) );
		$this->assertSame( array( '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19' ), array_keys( Period::buckets( '2026-10-01 00:00:00', '2026-10-25 23:59:59', 'week' ) ) );
		$this->assertSame( 'month', Period::interval( Period::resolve( 'last_12_months' ), 'auto' ) );
	}

	public function test_store_summary_counts_orders_and_skips_excluded_statuses() {
		$this->seed_store();
		$r = Library::run(
			'store_summary',
			array(
				'period'  => 'last_month',
				'compare' => 'previous_period',
			)
		);
		$this->assertNotWPError( $r );
		$kpi = wp_list_pluck( $r['chart']['items'], 'value', 'key' );
		$this->assertEquals( 3, $kpi['orders'], 'The failed order is excluded.' );
		$this->assertEqualsWithDelta( 420.0, $kpi['net_sales'], 0.01 );
		$this->assertEqualsWithDelta( 140.0, $kpi['aov'], 0.01 );
		$this->assertEquals( 9, $kpi['items_sold'] );
		$this->assertNull( $r['chart']['items'][0]['change'], 'No previous data → no change %.' );
		$this->assertSame( 'kpi', $r['chart']['type'] );
	}

	public function test_best_sellers_in_dubai_last_month() {
		$this->seed_store();
		$r = Library::run(
			'top_products',
			array(
				'period'   => 'last_month',
				'location' => 'Dubai',
				'metric'   => 'revenue',
			)
		);
		$this->assertNotWPError( $r );
		$this->assertSame( array( 'Desert Rose Perfume', 'Oud Candle' ), wp_list_pluck( $r['rows'], 'name' ) );
		$this->assertEqualsWithDelta( 200.0, $r['rows'][0]['revenue'], 0.01 );
		$this->assertEqualsWithDelta( 100.0, $r['rows'][1]['revenue'], 0.01 );
		$this->assertEqualsWithDelta( 66.7, $r['rows'][0]['share'], 0.1 );
		$this->assertContains( 'Location: Dubai', $r['notes'] );
		$by_qty = Library::run(
			'top_products',
			array(
				'period' => 'last_month',
				'metric' => 'quantity',
			)
		);
		$this->assertSame( 'Oud Candle', $by_qty['rows'][0]['name'] );
		$this->assertSame( 6, $by_qty['rows'][0]['quantity'] );
	}

	public function test_location_join_works_on_both_order_storages() {
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
		$this->seed_store();
		foreach ( array( true, false ) as $hpos ) {
			Store::set_hpos( $hpos );
			$r = Library::run(
				'sales_by_location',
				array(
					'period' => 'last_month',
					'level'  => 'country',
				)
			);
			$this->assertNotWPError( $r );
			$names = wp_list_pluck( $r['rows'], 'net_sales', 'name' );
			$this->assertEqualsWithDelta( 300.0, $names['United Arab Emirates (UAE)'] ?? $names['United Arab Emirates'] ?? 0, 0.01, $hpos ? 'HPOS' : 'legacy' );
			$this->assertEqualsWithDelta( 120.0, $names['Germany'], 0.01 );
			$germany = Library::run(
				'store_summary',
				array(
					'period'   => 'last_month',
					'location' => 'Germany',
				)
			);
			$this->assertEquals( 1, wp_list_pluck( $germany['chart']['items'], 'value', 'key' )['orders'] );
		}
		Store::set_hpos( null );
	}

	public function test_category_and_product_filters_use_line_items() {
		$p = $this->seed_store();
		$r = Library::run(
			'sales_trend',
			array(
				'period'   => 'last_month',
				'category' => 'Fragrance',
				'interval' => 'month',
				'compare'  => 'none',
			)
		);
		$this->assertEqualsWithDelta( 300.0, $r['summary']['value'], 0.01, 'Only perfume lines count.' );
		$this->assertContains( 'Category: Fragrance', $r['notes'] );
		$cats = Library::run( 'top_categories', array( 'period' => 'last_month' ) );
		$this->assertSame( array( 'Fragrance', 'Home' ), wp_list_pluck( $cats['rows'], 'name' ) );
		$prod = Library::run(
			'store_summary',
			array(
				'period'  => 'all_time',
				'product' => $p['candle']->get_sku(),
			)
		);
		$this->assertEquals( 3, wp_list_pluck( $prod['chart']['items'], 'value', 'key' )['orders'] );
	}

	public function test_sales_trend_zero_fills_and_compares() {
		$this->seed_store();
		$r = Library::run(
			'sales_trend',
			array(
				'period'   => 'this_month',
				'interval' => 'day',
				'metric'   => 'net_sales',
			)
		);
		$this->assertCount( 25, $r['rows'] );
		$by = wp_list_pluck( $r['rows'], 'value', 'key' );
		$this->assertEqualsWithDelta( 100.0, $by['2026-10-03'], 0.01 );
		$this->assertEquals( 0, $by['2026-10-04'] );
		$this->assertEqualsWithDelta( 70.0, $by['2026-10-20'], 0.01 );
		$this->assertEqualsWithDelta( 170.0, $r['summary']['value'], 0.01 );
		$this->assertEqualsWithDelta( 420.0, $r['summary']['previous'], 0.01, 'Previous = 1–25 Sep.' );
		$this->assertEqualsWithDelta( -59.5, $r['summary']['change'], 0.1 );
		$aov = Library::run(
			'sales_trend',
			array(
				'period'   => 'last_month',
				'interval' => 'month',
				'metric'   => 'aov',
				'compare'  => 'none',
			)
		);
		$this->assertEqualsWithDelta( 140.0, $aov['rows'][0]['value'], 0.01 );
	}

	public function test_new_vs_returning_and_top_customers() {
		$this->seed_store();
		$seg = Library::run(
			'customer_segments',
			array(
				'period'  => 'this_month',
				'segment' => 'new_vs_returning',
				'metric'  => 'orders',
			)
		);
		$rows = wp_list_pluck( $seg['rows'], 'orders', 'name' );
		$this->assertSame( 1, $rows['Returning customers'], 'Aisha ordered before.' );
		$this->assertSame( 1, $rows['New customers'] );
		$this->assertSame( 'share', $seg['chart']['type'] );
		$top = Library::run( 'top_customers', array( 'period' => 'all_time' ) );
		$this->assertSame( 'Aisha Khan', $top['rows'][0]['name'] );
		$this->assertEqualsWithDelta( 300.0, $top['rows'][0]['revenue'], 0.01 );
		$this->assertTrue( $top['personal'] );
	}

	public function test_refunds_are_negative_rows() {
		$p     = $this->seed_store();
		$order = $this->order(
			array(
				'date'  => '2026-10-10 10:00:00',
				'items' => array( array( $p['perfume'], 1 ) ),
				'email' => 'zed@example.com',
			)
		);
		$this->refund( $order, 100, '2026-10-11 10:00:00' );
		$r = Library::run(
			'refunds',
			array(
				'period'  => 'this_month',
				'compare' => 'none',
			)
		);
		$this->assertEqualsWithDelta( 100.0, $r['summary']['value'], 0.01 );
		$extra = wp_list_pluck( $r['extra'], 'value', 'key' );
		$this->assertEquals( 1, $extra['refund_count'] );
		$this->assertEqualsWithDelta( 37.0, $extra['refund_rate'], 0.1 );
		$summary = Library::run( 'store_summary', array( 'period' => 'this_month' ) );
		$this->assertEqualsWithDelta( 170.0, wp_list_pluck( $summary['chart']['items'], 'value', 'key' )['net_sales'], 0.01, 'Refund cancels the 10 Oct sale.' );
		$products = Library::run(
			'refunds',
			array(
				'period' => 'this_month',
				'view'   => 'products',
			)
		);
		$this->assertSame( 'Desert Rose Perfume', $products['rows'][0]['name'] );
	}

	public function test_cohorts_stock_and_coupons() {
		$this->seed_store();
		$c = Library::run( 'cohorts', array( 'months' => 3 ) );
		$this->assertCount( 3, $c['rows'] );
		$sep = $c['rows'][1];
		$this->assertSame( 3, $sep['size'] );
		$this->assertEqualsWithDelta( 33.3, $sep['m1'], 0.1, 'Aisha came back in October.' );
		$this->assertNull( $sep['m2'] );
		$low = Library::run( 'stock_status', array( 'view' => 'low' ) );
		$this->assertSame( array( 'Amber Soap', 'Desert Rose Perfume' ), wp_list_pluck( $low['rows'], 'name' ) );
		$cover = Library::run( 'stock_status', array( 'view' => 'cover' ) );
		$this->assertSame( 'Amber Soap', $cover['rows'][0]['name'], 'Lowest days of cover first (3 left, 3 sold in 30 days).' );
		$this->assertEqualsWithDelta( 30.0, $cover['rows'][0]['cover'], 0.1 );
		$this->assertSame( 'days', $cover['chart']['format'] );
		$coupons = Library::run( 'top_coupons', array( 'period' => 'all_time' ) );
		$this->assertSame( array(), $coupons['rows'] );
	}
}
