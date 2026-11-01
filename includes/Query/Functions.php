<?php
/**
 * Runners for the query library. Each receives sanitised args and returns
 * { title, period, compare_period, chart, columns, rows, summary, notes }.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Query implementations.
 */
class Functions {

	/**
	 * Resolved period of a call.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	private static function period( array $args ) {
		return Period::resolve( isset( $args['period'] ) ? $args['period'] : 'last_30_days', isset( $args['from'] ) ? $args['from'] : '', isset( $args['to'] ) ? $args['to'] : '' );
	}

	/**
	 * Filters of a call.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	private static function filters( array $args ) {
		return array_intersect_key( $args, array_flip( array( 'location', 'category', 'product', 'customer_type', 'customer_kind' ) ) );
	}

	/**
	 * Product-level source when a product/category filter narrows the lines.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	private static function source( array $args ) {
		return ! empty( $args['category'] ) || ! empty( $args['product'] ) ? 'items' : 'orders';
	}

	/**
	 * Percentage change or null.
	 *
	 * @param float $current  Current.
	 * @param float $previous Previous.
	 * @return float|null
	 */
	public static function change( $current, $previous ) {
		$previous = (float) $previous;
		if ( abs( $previous ) < 0.00001 ) {
			return null;
		}
		return round( ( (float) $current - $previous ) / abs( $previous ) * 100, 1 );
	}

	/**
	 * Column definition.
	 *
	 * @param string $key    Key.
	 * @param string $label  Label.
	 * @param string $format text|money|number|percent|days.
	 * @return array
	 */
	private static function col( $key, $label, $format = 'text' ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'format' => $format,
		);
	}

	/**
	 * Base metric set for totals.
	 *
	 * @return string[]
	 */
	private static function base_metrics() {
		return array( 'net_sales', 'gross_sales', 'orders', 'items_sold', 'refunds', 'refund_count', 'customers', 'new_customers', 'order_net', 'order_gross' );
	}

	/**
	 * Notes from a builder.
	 *
	 * @param Builder $b Builder.
	 * @return array
	 */
	private static function notes( Builder $b ) {
		return array_values( array_unique( $b->notes ) );
	}

	/**
	 * Store summary KPIs.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function store_summary( array $args ) {
		$period  = self::period( $args );
		$compare = Period::compare( $period, isset( $args['compare'] ) ? $args['compare'] : 'previous_period' );
		$source  = self::source( $args );
		$b       = Builder::make( $source, $period, self::filters( $args ) );
		$cur     = $b->totals( self::base_metrics() );
		$prev    = $compare ? Builder::make( $source, $compare, self::filters( $args ) )->totals( self::base_metrics() ) : null;

		$defs  = array(
			'net_sales'     => true,
			'orders'        => true,
			'aov'           => true,
			'items_sold'    => true,
			'refunds'       => false,
			'customers'     => true,
			'new_customers' => true,
		);
		$items = array();
		foreach ( $defs as $metric => $up_good ) {
			$items[] = array(
				'key'      => $metric,
				'label'    => Library::metric_label( $metric ),
				'value'    => round( (float) $cur[ $metric ], 2 ),
				'previous' => $prev ? round( (float) $prev[ $metric ], 2 ) : null,
				'change'   => $prev ? self::change( $cur[ $metric ], $prev[ $metric ] ) : null,
				'format'   => Library::metric_format( $metric ),
				'good'     => $up_good ? 'up' : 'down',
			);
		}
		$interval = Period::interval( $period, 'auto' );
		$spark    = self::series( $b, $period, $interval, 'net_sales' );
		return array(
			'title'          => __( 'Store summary', 'dev3-ai-store-insights-for-woocommerce' ),
			'period'         => $period,
			'compare_period' => $compare,
			'chart'          => array(
				'type'  => 'kpi',
				'items' => $items,
				'spark' => $spark,
			),
			'columns'        => array(
				self::col( 'label', __( 'Metric', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'value', __( 'Value', 'dev3-ai-store-insights-for-woocommerce' ), 'auto' ),
				self::col( 'previous', $compare ? $compare['label'] : __( 'Previous', 'dev3-ai-store-insights-for-woocommerce' ), 'auto' ),
				self::col( 'change', __( 'Change', 'dev3-ai-store-insights-for-woocommerce' ), 'change' ),
			),
			'rows'           => $items,
			'summary'        => array(
				'metric'   => 'net_sales',
				'label'    => Library::metric_label( 'net_sales' ),
				'value'    => $items[0]['value'],
				'previous' => $items[0]['previous'],
				'change'   => $items[0]['change'],
				'format'   => 'money',
			),
			'notes'          => self::notes( $b ),
		);
	}

	/**
	 * Values of one metric per bucket (zero-filled).
	 *
	 * @param Builder $b        Builder.
	 * @param array   $period   Period.
	 * @param string  $interval Interval.
	 * @param string  $metric   Metric (aov/refund_rate derived).
	 * @return array [ { key, label, value } ]
	 */
	private static function series( Builder $b, array $period, $interval, $metric ) {
		$selects = array( 'bucket' => Period::bucket_sql( 's.date_created', $interval ) );
		if ( 'aov' === $metric ) {
			$selects += array(
				'order_net' => '@order_net',
				'orders'    => '@orders',
			);
		} elseif ( 'refund_rate' === $metric ) {
			$selects += array(
				'refunds'     => '@refunds',
				'order_gross' => '@order_gross',
			);
		} else {
			$selects['v'] = '@' . $metric;
		}
		$rows = array();
		foreach ( $b->select( $selects, 'bucket' ) as $row ) {
			$row = Builder::derive( $row );
			if ( 'aov' === $metric ) {
				$row['v'] = $row['aov'];
			} elseif ( 'refund_rate' === $metric ) {
				$row['v'] = $row['refund_rate'];
			}
			$rows[ substr( (string) $row['bucket'], 0, 10 ) ] = round( (float) $row['v'], 2 );
		}
		$out = array();
		foreach ( Period::buckets( $period['from'], $period['to'], $interval ) as $key => $label ) {
			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'value' => isset( $rows[ $key ] ) ? $rows[ $key ] : 0,
			);
		}
		return $out;
	}

	/**
	 * Total of a metric for a builder (aov/refund_rate derived).
	 *
	 * @param Builder $b      Builder.
	 * @param string  $metric Metric.
	 * @return float
	 */
	private static function total( Builder $b, $metric ) {
		$totals = $b->totals( self::base_metrics() );
		return isset( $totals[ $metric ] ) ? round( (float) $totals[ $metric ], 2 ) : 0.0;
	}

	/**
	 * Metric over time with comparison.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function sales_trend( array $args ) {
		$metric   = isset( $args['metric'] ) ? $args['metric'] : 'net_sales';
		$period   = self::period( $args );
		$compare  = Period::compare( $period, isset( $args['compare'] ) ? $args['compare'] : 'previous_period' );
		$interval = Period::interval( $period, isset( $args['interval'] ) ? $args['interval'] : 'auto' );
		$source   = self::source( $args );
		$b        = Builder::make( $source, $period, self::filters( $args ) );
		$cur      = self::series( $b, $period, $interval, $metric );
		$prev     = null;
		$prev_tot = null;
		if ( $compare ) {
			$pb       = Builder::make( $source, $compare, self::filters( $args ) );
			$prev     = self::series( $pb, $compare, $interval, $metric );
			$prev_tot = self::total( $pb, $metric );
		}
		$rows = array();
		foreach ( $cur as $i => $point ) {
			$rows[] = array(
				'key'            => $point['key'],
				'label'          => $point['label'],
				'value'          => $point['value'],
				'previous'       => $prev && isset( $prev[ $i ] ) ? $prev[ $i ]['value'] : null,
				'previous_label' => $prev && isset( $prev[ $i ] ) ? $prev[ $i ]['label'] : null,
			);
		}
		$format = Library::metric_format( $metric );
		$total  = self::total( $b, $metric );
		$label  = Library::metric_label( $metric );
		return array(
			/* translators: 1: metric, 2: interval */
			'title'          => sprintf( __( '%1$s by %2$s', 'dev3-ai-store-insights-for-woocommerce' ), $label, mb_strtolower( Library::option_label( $interval ) ) ),
			'period'         => $period,
			'compare_period' => $compare,
			'chart'          => array(
				'type'     => 'line',
				'x'        => 'label',
				'interval' => $interval,
				'format'   => $format,
				'series'   => array_values(
					array_filter(
						array(
							array(
								'key'   => 'value',
								'label' => $period['label'],
							),
							$compare ? array(
								'key'   => 'previous',
								'label' => $compare['label'],
								'muted' => true,
							) : null,
						)
					)
				),
			),
			'columns'        => array_values(
				array_filter(
					array(
						self::col( 'label', Library::option_label( $interval ) ),
						self::col( 'value', $label, $format ),
						$compare ? self::col( 'previous', $compare['label'], $format ) : null,
					)
				)
			),
			'rows'           => $rows,
			'summary'        => array(
				'metric'   => $metric,
				'label'    => $label,
				'value'    => $total,
				'previous' => $prev_tot,
				'change'   => null !== $prev_tot ? self::change( $total, $prev_tot ) : null,
				'format'   => $format,
			),
			'notes'          => self::notes( $b ),
		);
	}

	/**
	 * Ranked result helper: adds share and builds the bar chart payload.
	 *
	 * @param array  $rows    Rows (with 'name' and metric keys).
	 * @param string $metric  Ranking key.
	 * @param float  $total   Total of the metric for shares (0 = no share).
	 * @param string $title   Title.
	 * @param array  $columns Columns.
	 * @param array  $period  Period.
	 * @param array  $notes   Notes.
	 * @return array
	 */
	private static function ranked( array $rows, $metric, $total, $title, array $columns, array $period, array $notes ) {
		$format = Library::metric_format( $metric );
		foreach ( $rows as &$row ) {
			$row['share'] = $total > 0 ? round( (float) $row[ $metric ] / $total * 100, 1 ) : null;
		}
		unset( $row );
		if ( $total > 0 ) {
			$columns[] = self::col( 'share', __( 'Share', 'dev3-ai-store-insights-for-woocommerce' ), 'percent' );
		}
		$top = $rows ? $rows[0] : null;
		return array(
			'title'   => $title,
			'period'  => $period,
			'chart'   => array(
				'type'   => 'bar',
				'label'  => 'name',
				'value'  => $metric,
				'format' => $format,
			),
			'columns' => $columns,
			'rows'    => $rows,
			'summary' => array(
				'metric' => $metric,
				'label'  => Library::metric_label( $metric ),
				'value'  => $total,
				'format' => $format,
				'top'    => $top ? $top['name'] : null,
				'share'  => $top ? $top['share'] : null,
			),
			'notes'   => $notes,
		);
	}

	/**
	 * Top products.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function top_products( array $args ) {
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'revenue';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$period = self::period( $args );
		$b      = Builder::make( 'items', $period, self::filters( $args ) );
		$order  = array(
			'revenue'  => 'revenue DESC',
			'quantity' => 'quantity DESC',
			'orders'   => 'orders DESC',
		);
		$data   = $b->select(
			array(
				'product_id' => 'p.product_id',
				'revenue'    => '@net_sales',
				'quantity'   => '@items_sold',
				'orders'     => '@orders',
			),
			'p.product_id',
			$order[ $metric ] . ', p.product_id ASC',
			$limit,
			$metric . ' > 0'
		);
		$rows   = array();
		foreach ( $data as $row ) {
			$rows[] = array(
				'id'       => (int) $row['product_id'],
				'name'     => Store::product_name( (int) $row['product_id'] ),
				'revenue'  => round( (float) $row['revenue'], 2 ),
				'quantity' => (int) $row['quantity'],
				'orders'   => (int) $row['orders'],
			);
		}
		$totals = $b->totals( array( 'net_sales', 'items_sold', 'orders' ) );
		$map    = array(
			'revenue'  => 'net_sales',
			'quantity' => 'items_sold',
			'orders'   => 'orders',
		);
		return self::ranked(
			$rows,
			$metric,
			(float) $totals[ $map[ $metric ] ],
			__( 'Top products', 'dev3-ai-store-insights-for-woocommerce' ),
			array(
				self::col( 'name', __( 'Product', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'revenue', __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'quantity', __( 'Items sold', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
			),
			$period,
			self::notes( $b )
		);
	}

	/**
	 * Top categories.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function top_categories( array $args ) {
		global $wpdb;
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'revenue';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$period = self::period( $args );
		$b      = Builder::make( 'items', $period, self::filters( $args ) );
		$b->join( 'cat', "INNER JOIN {$wpdb->term_relationships} ctr ON ctr.object_id = p.product_id INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id AND ctt.taxonomy = 'product_cat'" );
		$order = array(
			'revenue'  => 'revenue DESC',
			'quantity' => 'quantity DESC',
			'orders'   => 'orders DESC',
		);
		$data  = $b->select(
			array(
				'term_id'  => 'ctt.term_id',
				'revenue'  => '@net_sales',
				'quantity' => '@items_sold',
				'orders'   => '@orders',
			),
			'ctt.term_id',
			$order[ $metric ] . ', ctt.term_id ASC',
			$limit,
			$metric . ' > 0'
		);
		$rows  = array();
		foreach ( $data as $row ) {
			$term   = get_term( (int) $row['term_id'], 'product_cat' );
			$rows[] = array(
				'id'       => (int) $row['term_id'],
				'name'     => $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name ) : '#' . (int) $row['term_id'],
				'revenue'  => round( (float) $row['revenue'], 2 ),
				'quantity' => (int) $row['quantity'],
				'orders'   => (int) $row['orders'],
			);
		}
		$notes   = self::notes( $b );
		$notes[] = __( 'A product in several categories counts in each of them.', 'dev3-ai-store-insights-for-woocommerce' );
		return self::ranked(
			$rows,
			$metric,
			0,
			__( 'Top categories', 'dev3-ai-store-insights-for-woocommerce' ),
			array(
				self::col( 'name', __( 'Category', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'revenue', __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'quantity', __( 'Items sold', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
			),
			$period,
			$notes
		);
	}

	/**
	 * Sales by country / region / city.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function sales_by_location( array $args ) {
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'net_sales';
		$level  = isset( $args['level'] ) ? $args['level'] : 'country';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$period = self::period( $args );
		$b      = Builder::make( self::source( $args ), $period, self::filters( $args ) );
		$loc    = $b->location();
		$group  = array(
			'country' => "{$loc['country']}",
			'region'  => "{$loc['country']}, {$loc['state']}",
			'city'    => "{$loc['country']}, LOWER({$loc['city']})",
		);
		$data   = $b->select(
			array(
				'country'   => "MAX({$loc['country']})",
				'state'     => "MAX({$loc['state']})",
				'city'      => "MAX({$loc['city']})",
				'net_sales' => '@net_sales',
				'orders'    => '@orders',
				'order_net' => '@order_net',
				'items'     => '@items_sold',
				'customers' => '@customers',
			),
			$group[ $level ],
			'net_sales DESC',
			500
		);
		$rows   = array();
		$total  = 0.0;
		foreach ( $data as $row ) {
			$row     = Builder::derive( $row );
			$country = strtoupper( (string) $row['country'] );
			if ( '' === $country ) {
				$name = __( 'Unknown', 'dev3-ai-store-insights-for-woocommerce' );
			} elseif ( 'country' === $level ) {
				$name = Store::country_name( $country );
			} elseif ( 'region' === $level ) {
				$name = ( '' !== (string) $row['state'] ? Store::state_name( $country, (string) $row['state'] ) . ', ' : '' ) . Store::country_name( $country );
			} else {
				$name = ( '' !== trim( (string) $row['city'] ) ? trim( (string) $row['city'] ) : __( 'Unknown city', 'dev3-ai-store-insights-for-woocommerce' ) ) . ', ' . $country;
			}
			$rows[] = array(
				'name'       => $name,
				'country'    => $country,
				'net_sales'  => round( (float) $row['net_sales'], 2 ),
				'orders'     => (int) $row['orders'],
				'aov'        => (float) $row['aov'],
				'items_sold' => (int) $row['items'],
				'customers'  => (int) $row['customers'],
			);
			$total += 'aov' === $metric ? 0 : (float) ( 'items_sold' === $metric ? $row['items'] : $row[ $metric ] );
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $metric ) {
				return $b[ $metric ] <=> $a[ $metric ];
			}
		);
		$rows   = array_slice(
			array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $metric ) {
						return $r[ $metric ] > 0;
					}
				)
			),
			0,
			$limit
		);
		$titles = array(
			'country' => __( 'Sales by country', 'dev3-ai-store-insights-for-woocommerce' ),
			'region'  => __( 'Sales by region', 'dev3-ai-store-insights-for-woocommerce' ),
			'city'    => __( 'Sales by city', 'dev3-ai-store-insights-for-woocommerce' ),
		);
		$notes  = self::notes( $b );
		/* translators: %s: billing or shipping */
		$notes[] = sprintf( __( 'Grouped by the %s address of each order.', 'dev3-ai-store-insights-for-woocommerce' ), 'shipping' === Store::basis() ? __( 'shipping', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'billing', 'dev3-ai-store-insights-for-woocommerce' ) );
		return self::ranked(
			$rows,
			$metric,
			$total,
			$titles[ $level ],
			array(
				self::col( 'name', Library::option_label( $level ) ),
				self::col( 'net_sales', __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'aov', __( 'AOV', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'customers', __( 'Customers', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
			),
			$period,
			$notes
		);
	}

	/**
	 * New vs returning / registered vs guest.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function customer_segments( array $args ) {
		$segment = isset( $args['segment'] ) ? $args['segment'] : 'new_vs_returning';
		$metric  = isset( $args['metric'] ) ? $args['metric'] : 'net_sales';
		$period  = self::period( $args );
		$b       = Builder::make( self::source( $args ), $period, self::filters( $args ) );
		if ( 'registered_vs_guest' === $segment ) {
			$b->with_customers();
			$expr   = 'CASE WHEN c.user_id > 0 THEN 1 ELSE 0 END';
			$labels = array(
				1 => __( 'Registered', 'dev3-ai-store-insights-for-woocommerce' ),
				0 => __( 'Guest', 'dev3-ai-store-insights-for-woocommerce' ),
			);
		} else {
			$expr   = 's.returning_customer';
			$labels = array(
				0 => __( 'New customers', 'dev3-ai-store-insights-for-woocommerce' ),
				1 => __( 'Returning customers', 'dev3-ai-store-insights-for-woocommerce' ),
			);
		}
		$data  = $b->select(
			array(
				'seg'       => $expr,
				'net_sales' => '@net_sales',
				'orders'    => '@orders',
				'order_net' => '@order_net',
				'customers' => '@customers',
			),
			'seg'
		);
		$found = array();
		foreach ( $data as $row ) {
			$found[ (int) $row['seg'] ] = Builder::derive( $row );
		}
		$rows = array();
		foreach ( $labels as $key => $label ) {
			$row    = isset( $found[ $key ] ) ? $found[ $key ] : array(
				'net_sales' => 0,
				'orders'    => 0,
				'aov'       => 0,
				'customers' => 0,
			);
			$rows[] = array(
				'key'       => $key,
				'name'      => $label,
				'net_sales' => round( (float) $row['net_sales'], 2 ),
				'orders'    => (int) $row['orders'],
				'aov'       => (float) $row['aov'],
				'customers' => (int) $row['customers'],
			);
		}
		$total = 'aov' === $metric ? 0 : array_sum( wp_list_pluck( $rows, $metric ) );
		$out   = self::ranked(
			$rows,
			$metric,
			$total,
			'registered_vs_guest' === $segment ? __( 'Registered vs guest customers', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'New vs returning customers', 'dev3-ai-store-insights-for-woocommerce' ),
			array(
				self::col( 'name', __( 'Segment', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'net_sales', __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'aov', __( 'AOV', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'customers', __( 'Customers', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
			),
			$period,
			self::notes( $b )
		);
		if ( 'aov' !== $metric ) {
			$out['chart']['type'] = 'share';
		}
		return $out;
	}

	/**
	 * Top customers.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function top_customers( array $args ) {
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'revenue';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$period = self::period( $args );
		$b      = Builder::make( self::source( $args ), $period, self::filters( $args ) );
		$b->with_customers();
		$data = $b->select(
			array(
				'customer_id' => 's.customer_id',
				'first'       => 'MAX(c.first_name)',
				'last'        => 'MAX(c.last_name)',
				'username'    => 'MAX(c.username)',
				'user_id'     => 'MAX(c.user_id)',
				'country'     => 'MAX(c.country)',
				'revenue'     => '@net_sales',
				'orders'      => '@orders',
				'order_net'   => '@order_net',
			),
			's.customer_id',
			'aov' === $metric ? 'order_net DESC' : ( 'orders' === $metric ? 'orders DESC, revenue DESC' : 'revenue DESC' ),
			'aov' === $metric ? 500 : $limit,
			'orders > 0'
		);
		$rows = array();
		foreach ( $data as $row ) {
			$row  = Builder::derive( $row );
			$name = trim( $row['first'] . ' ' . $row['last'] );
			if ( '' === $name ) {
				$name = (string) $row['username'];
			}
			if ( '' === $name ) {
				/* translators: %d: customer id */
				$name = sprintf( __( 'Guest customer #%d', 'dev3-ai-store-insights-for-woocommerce' ), (int) $row['customer_id'] );
			}
			$rows[] = array(
				'id'      => (int) $row['customer_id'],
				'name'    => $name,
				'kind'    => (int) $row['user_id'] > 0 ? 'registered' : 'guest',
				'country' => (string) $row['country'],
				'revenue' => round( (float) $row['revenue'], 2 ),
				'orders'  => (int) $row['orders'],
				'aov'     => (float) $row['aov'],
			);
		}
		if ( 'aov' === $metric ) {
			usort(
				$rows,
				static function ( $a, $b ) {
					return $b['aov'] <=> $a['aov'];
				}
			);
			$rows = array_slice( $rows, 0, $limit );
		}
		$total           = 'aov' === $metric ? 0 : (float) $b->totals( array( 'net_sales', 'orders' ) )[ 'revenue' === $metric ? 'net_sales' : 'orders' ];
		$out             = self::ranked(
			$rows,
			$metric,
			$total,
			__( 'Top customers', 'dev3-ai-store-insights-for-woocommerce' ),
			array(
				self::col( 'name', __( 'Customer', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'country', __( 'Country', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'revenue', __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'aov', __( 'AOV', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
			),
			$period,
			self::notes( $b )
		);
		$out['personal'] = true;
		return $out;
	}

	/**
	 * Refunds trend or by product.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function refunds( array $args ) {
		$view   = isset( $args['view'] ) ? $args['view'] : 'trend';
		$period = self::period( $args );
		if ( 'products' === $view ) {
			$b = Builder::make( 'items', $period, self::filters( $args ) );
			$b->refunds_only();
			$data = $b->select(
				array(
					'product_id' => 'p.product_id',
					'refunds'    => 'SUM(-p.product_gross_revenue)',
					'quantity'   => 'SUM(-p.product_qty)',
					'orders'     => 'COUNT(DISTINCT s.parent_id)',
				),
				'p.product_id',
				'refunds DESC',
				isset( $args['limit'] ) ? (int) $args['limit'] : 10,
				'refunds > 0'
			);
			$rows = array();
			foreach ( $data as $row ) {
				$rows[] = array(
					'id'       => (int) $row['product_id'],
					'name'     => Store::product_name( (int) $row['product_id'] ),
					'refunds'  => round( (float) $row['refunds'], 2 ),
					'quantity' => (int) $row['quantity'],
					'orders'   => (int) $row['orders'],
				);
			}
			$notes = self::notes( $b );
			if ( ! $rows ) {
				$notes[] = __( 'No refunded line items in this period. Refunds entered as an amount only (without items) appear in the refunds trend instead.', 'dev3-ai-store-insights-for-woocommerce' );
			}
			return self::ranked(
				$rows,
				'refunds',
				(float) array_sum( wp_list_pluck( $rows, 'refunds' ) ),
				__( 'Most refunded products', 'dev3-ai-store-insights-for-woocommerce' ),
				array(
					self::col( 'name', __( 'Product', 'dev3-ai-store-insights-for-woocommerce' ) ),
					self::col( 'refunds', __( 'Refunded', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
					self::col( 'quantity', __( 'Items refunded', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
					self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				),
				$period,
				$notes
			);
		}
		$out          = self::sales_trend( array_merge( $args, array( 'metric' => 'refunds' ) ) );
		$source       = self::source( $args );
		$compare      = Period::compare( $period, isset( $args['compare'] ) ? $args['compare'] : 'previous_period' );
		$cur          = Builder::make( $source, $period, self::filters( $args ) )->totals( self::base_metrics() );
		$prev         = $compare ? Builder::make( $source, $compare, self::filters( $args ) )->totals( self::base_metrics() ) : null;
		$out['title'] = __( 'Refunds over time', 'dev3-ai-store-insights-for-woocommerce' );
		$out['extra'] = array(
			array(
				'key'      => 'refunds',
				'label'    => Library::metric_label( 'refunds' ),
				'value'    => round( $cur['refunds'], 2 ),
				'previous' => $prev ? round( $prev['refunds'], 2 ) : null,
				'change'   => $prev ? self::change( $cur['refunds'], $prev['refunds'] ) : null,
				'format'   => 'money',
				'good'     => 'down',
			),
			array(
				'key'      => 'refund_count',
				'label'    => Library::metric_label( 'refund_count' ),
				'value'    => (int) $cur['refund_count'],
				'previous' => $prev ? (int) $prev['refund_count'] : null,
				'change'   => $prev ? self::change( $cur['refund_count'], $prev['refund_count'] ) : null,
				'format'   => 'number',
				'good'     => 'down',
			),
			array(
				'key'      => 'refund_rate',
				'label'    => Library::metric_label( 'refund_rate' ),
				'value'    => (float) $cur['refund_rate'],
				'previous' => $prev ? (float) $prev['refund_rate'] : null,
				'change'   => $prev ? self::change( $cur['refund_rate'], $prev['refund_rate'] ) : null,
				'format'   => 'percent',
				'good'     => 'down',
			),
		);
		return $out;
	}

	/**
	 * Monthly cohorts.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function cohorts( array $args ) {
		global $wpdb;
		$months = isset( $args['months'] ) ? (int) $args['months'] : 6;
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'retention';
		$start  = Period::now()->setTime( 0, 0, 0 )->modify( 'first day of this month' )->modify( '-' . ( $months - 1 ) . ' months' );
		$period = array(
			'preset' => 'custom',
			'from'   => $start->format( 'Y-m-d H:i:s' ),
			'to'     => Period::now()->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' ),
			/* translators: %d: number of months */
			'label'  => sprintf( _n( 'Last %d month', 'Last %d months', $months, 'dev3-ai-store-insights-for-woocommerce' ), $months ),
			'days'   => 0,
		);
		$first  = new Builder( 'orders' );
		$first->orders_only()->where( 's.customer_id > 0' );
		$firsts    = $first->select(
			array(
				'customer_id' => 's.customer_id',
				'first'       => 'MIN(s.date_created)',
			),
			's.customer_id',
			'',
			0,
			$wpdb->prepare( 'MIN(s.date_created) >= %s', $period['from'] )
		);
		$cohort_of = array();
		foreach ( $firsts as $row ) {
			$cohort_of[ (int) $row['customer_id'] ] = substr( (string) $row['first'], 0, 7 );
		}
		$activity = new Builder( 'orders' );
		$activity->orders_only()->period( $period );
		$acts = $activity->select(
			array(
				'customer_id' => 's.customer_id',
				'ym'          => "DATE_FORMAT(s.date_created, '%Y-%m')",
				'revenue'     => 'SUM(s.net_total)',
			),
			's.customer_id, ym'
		);
		$keys = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$keys[] = $start->modify( "+{$i} months" )->format( 'Y-m' );
		}
		$matrix = array();
		foreach ( $keys as $key ) {
			$matrix[ $key ] = array(
				'size'    => 0,
				'active'  => array_fill( 0, $months, 0 ),
				'revenue' => array_fill( 0, $months, 0.0 ),
			);
		}
		foreach ( $cohort_of as $cohort ) {
			if ( isset( $matrix[ $cohort ] ) ) {
				++$matrix[ $cohort ]['size'];
			}
		}
		foreach ( $acts as $row ) {
			$cid = (int) $row['customer_id'];
			if ( ! isset( $cohort_of[ $cid ] ) || ! isset( $matrix[ $cohort_of[ $cid ] ] ) ) {
				continue;
			}
			$offset = array_search( (string) $row['ym'], $keys, true ) - array_search( $cohort_of[ $cid ], $keys, true );
			if ( $offset >= 0 && $offset < $months ) {
				++$matrix[ $cohort_of[ $cid ] ]['active'][ $offset ];
				$matrix[ $cohort_of[ $cid ] ]['revenue'][ $offset ] += (float) $row['revenue'];
			}
		}
		$rows    = array();
		$columns = array(
			self::col( 'cohort', __( 'First order', 'dev3-ai-store-insights-for-woocommerce' ) ),
			self::col( 'size', __( 'Customers', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
		);
		$cells   = array();
		for ( $i = 0; $i < $months; $i++ ) {
			/* translators: %d: months after the first order */
			$columns[] = self::col( 'm' . $i, 0 === $i ? __( 'Month 0', 'dev3-ai-store-insights-for-woocommerce' ) : sprintf( __( '+%d mo', 'dev3-ai-store-insights-for-woocommerce' ), $i ), 'retention' === $metric ? 'percent' : 'money' );
			$cells[]   = 'm' . $i;
		}
		$ret_sum = 0;
		$ret_n   = 0;
		foreach ( $matrix as $key => $cell ) {
			$row = array(
				'cohort' => wp_date( 'M Y', strtotime( $key . '-15 12:00:00' ) ),
				'size'   => $cell['size'],
			);
			$age = count( $keys ) - 1 - array_search( $key, $keys, true );
			for ( $i = 0; $i < $months; $i++ ) {
				if ( $i > $age ) {
					$row[ 'm' . $i ] = null;
					continue;
				}
				$row[ 'm' . $i ] = 'retention' === $metric
					? ( $cell['size'] > 0 ? round( $cell['active'][ $i ] / $cell['size'] * 100, 1 ) : 0 )
					: round( $cell['revenue'][ $i ], 2 );
				if ( 1 === $i && 'retention' === $metric && $cell['size'] > 0 ) {
					$ret_sum += $row['m1'];
					++$ret_n;
				}
			}
			$rows[] = $row;
		}
		return array(
			'title'   => 'retention' === $metric ? __( 'Customer retention by cohort', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Revenue by cohort', 'dev3-ai-store-insights-for-woocommerce' ),
			'period'  => $period,
			'chart'   => array(
				'type'   => 'heatmap',
				'label'  => 'cohort',
				'cells'  => $cells,
				'format' => 'retention' === $metric ? 'percent' : 'money',
			),
			'columns' => $columns,
			'rows'    => $rows,
			'summary' => array(
				'metric' => $metric,
				'label'  => __( 'Average month-1 retention', 'dev3-ai-store-insights-for-woocommerce' ),
				'value'  => $ret_n ? round( $ret_sum / $ret_n, 1 ) : 0,
				'format' => 'percent',
			),
			'notes'   => array( __( 'Guest checkouts count as customers too. Month 0 is the month of the first order.', 'dev3-ai-store-insights-for-woocommerce' ) ),
		);
	}

	/**
	 * Units sold per product/variation in the last N days.
	 *
	 * @param int $days Days.
	 * @return array id => units
	 */
	public static function units_sold( $days = 30 ) {
		$period = array(
			'from' => Period::now()->setTime( 0, 0, 0 )->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d H:i:s' ),
			'to'   => Period::now()->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' ),
		);
		$b      = Builder::make( 'items', $period );
		$out    = array();
		foreach ( $b->select(
			array(
				'id'    => 'IF(p.variation_id > 0, p.variation_id, p.product_id)',
				'units' => 'SUM(p.product_qty)',
			),
			'id'
		) as $row ) {
			$out[ (int) $row['id'] ] = (float) $row['units'];
		}
		return $out;
	}

	/**
	 * Stock views.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function stock_status( array $args ) {
		global $wpdb;
		$view  = isset( $args['view'] ) ? $args['view'] : 'low';
		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$t     = Store::tables();
		$low   = max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
		$where = array( "po.post_status = 'publish'", "po.post_type IN ('product','product_variation')" );
		$vals  = array();
		$notes = array();
		if ( ! empty( $args['category'] ) ) {
			$cat = Store::resolve_category( $args['category'] );
			if ( $cat ) {
				$where[] = "IF(po.post_parent > 0, po.post_parent, po.ID) IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN (" . implode( ',', array_fill( 0, count( $cat['ids'] ), '%d' ) ) . '))';
				$vals    = array_merge( $vals, $cat['ids'] );
				/* translators: %s: category */
				$notes[] = sprintf( __( 'Category: %s', 'dev3-ai-store-insights-for-woocommerce' ), $cat['label'] );
			}
		}
		if ( 'out' === $view ) {
			$where[] = "m.stock_status = 'outofstock'";
		} else {
			$where[] = 'm.stock_quantity IS NOT NULL AND m.stock_quantity > 0';
			if ( 'low' === $view ) {
				$where[] = 'm.stock_quantity <= %d';
				$vals[]  = $low;
			}
		}
		$sql  = "SELECT m.product_id, m.sku, m.stock_quantity, m.stock_status FROM {$t['meta']} m INNER JOIN {$wpdb->posts} po ON po.ID = m.product_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.stock_quantity ASC, m.product_id ASC LIMIT 1000';
		$data = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$sold = self::units_sold( 30 );
		$rows = array();
		foreach ( (array) $data as $row ) {
			$id    = (int) $row['product_id'];
			$units = isset( $sold[ $id ] ) ? $sold[ $id ] : 0;
			$stock = null === $row['stock_quantity'] ? null : (float) $row['stock_quantity'];
			$cover = $units > 0 && null !== $stock ? round( $stock / ( $units / 30 ), 1 ) : null;
			if ( 'cover' === $view && null === $cover ) {
				continue;
			}
			if ( 'overstock' === $view && null !== $cover && $cover < 120 ) {
				continue;
			}
			$rows[] = array(
				'id'      => $id,
				'name'    => Store::product_name( $id ),
				'sku'     => (string) $row['sku'],
				'stock'   => null === $stock ? 0 : $stock,
				'sold_30' => $units,
				'cover'   => $cover,
				'status'  => (string) $row['stock_status'],
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $view ) {
				if ( 'cover' === $view ) {
					return $a['cover'] <=> $b['cover'];
				}
				if ( 'overstock' === $view ) {
					return $b['stock'] <=> $a['stock'];
				}
				if ( 'out' === $view ) {
					return $b['sold_30'] <=> $a['sold_30'];
				}
				return $a['stock'] <=> $b['stock'];
			}
		);
		$total  = count( $rows );
		$rows   = array_slice( $rows, 0, $limit );
		$titles = array(
			'low'       => __( 'Low stock', 'dev3-ai-store-insights-for-woocommerce' ),
			'out'       => __( 'Out of stock', 'dev3-ai-store-insights-for-woocommerce' ),
			'cover'     => __( 'Days of stock left', 'dev3-ai-store-insights-for-woocommerce' ),
			'overstock' => __( 'Overstocked, slow-moving products', 'dev3-ai-store-insights-for-woocommerce' ),
		);
		if ( 'low' === $view ) {
			/* translators: %d: threshold */
			$notes[] = sprintf( __( 'Low means %d or fewer in stock (WooCommerce low stock threshold).', 'dev3-ai-store-insights-for-woocommerce' ), $low );
		}
		$notes[] = __( 'Sales rate uses the last 30 days.', 'dev3-ai-store-insights-for-woocommerce' );
		return array(
			'title'   => $titles[ $view ],
			'period'  => array(
				'preset' => 'today',
				'from'   => Period::now()->format( 'Y-m-d H:i:s' ),
				'to'     => Period::now()->format( 'Y-m-d H:i:s' ),
				'label'  => __( 'Right now', 'dev3-ai-store-insights-for-woocommerce' ),
				'days'   => 1,
			),
			'chart'   => array(
				'type'   => 'out' === $view ? 'table' : 'bar',
				'label'  => 'name',
				'value'  => 'cover' === $view ? 'cover' : 'stock',
				'format' => 'cover' === $view ? 'days' : 'number',
			),
			'columns' => array(
				self::col( 'name', __( 'Product', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'sku', __( 'SKU', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'stock', __( 'In stock', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'sold_30', __( 'Sold (30 days)', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'cover', __( 'Days left', 'dev3-ai-store-insights-for-woocommerce' ), 'days' ),
			),
			'rows'    => $rows,
			'summary' => array(
				'metric' => 'products',
				'label'  => __( 'Products', 'dev3-ai-store-insights-for-woocommerce' ),
				'value'  => $total,
				'format' => 'number',
				'top'    => $rows ? $rows[0]['name'] : null,
			),
			'notes'   => $notes,
		);
	}

	/**
	 * Top coupons.
	 *
	 * @param array $args Args.
	 * @return array
	 */
	public static function top_coupons( array $args ) {
		$metric = isset( $args['metric'] ) ? $args['metric'] : 'orders';
		$limit  = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
		$period = self::period( $args );
		$t      = Store::tables();
		$b      = Builder::make( 'orders', $period );
		$b->join( 'cl', "INNER JOIN {$t['coupons']} cl ON cl.order_id = s.order_id" );
		$data = $b->select(
			array(
				'coupon_id' => 'cl.coupon_id',
				'orders'    => 'COUNT(DISTINCT cl.order_id)',
				'discount'  => 'SUM(cl.discount_amount)',
				'net_sales' => 'SUM(s.net_total)',
			),
			'cl.coupon_id',
			'discount' === $metric ? 'discount DESC' : 'orders DESC, discount DESC',
			$limit
		);
		$rows = array();
		foreach ( $data as $row ) {
			$code   = get_the_title( (int) $row['coupon_id'] );
			$rows[] = array(
				'id'        => (int) $row['coupon_id'],
				/* translators: %d: coupon id */
				'name'      => '' !== $code ? $code : sprintf( __( 'Coupon #%d', 'dev3-ai-store-insights-for-woocommerce' ), (int) $row['coupon_id'] ),
				'orders'    => (int) $row['orders'],
				'discount'  => round( (float) $row['discount'], 2 ),
				'net_sales' => round( (float) $row['net_sales'], 2 ),
			);
		}
		return self::ranked(
			$rows,
			$metric,
			(float) array_sum( wp_list_pluck( $rows, $metric ) ),
			__( 'Top coupons', 'dev3-ai-store-insights-for-woocommerce' ),
			array(
				self::col( 'name', __( 'Coupon', 'dev3-ai-store-insights-for-woocommerce' ) ),
				self::col( 'orders', __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ), 'number' ),
				self::col( 'discount', __( 'Discount', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
				self::col( 'net_sales', __( 'Net sales of those orders', 'dev3-ai-store-insights-for-woocommerce' ), 'money' ),
			),
			$period,
			array()
		);
	}
}
