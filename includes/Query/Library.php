<?php
/**
 * The fixed library of safe, parameterised query functions. The AI model picks a function and fills
 * typed parameters through function calling; sanitize() validates them again before run().
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Query;

use Dev3\AiStoreInsights\Core3\Api\Api_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Function catalogue + runners.
 */
class Library {

	const METRIC_LABELS = array(
		'net_sales'     => 'Net sales',
		'gross_sales'   => 'Gross sales',
		'orders'        => 'Orders',
		'items_sold'    => 'Items sold',
		'aov'           => 'Average order value',
		'refunds'       => 'Refunds',
		'refund_count'  => 'Refunded orders',
		'refund_rate'   => 'Refund rate',
		'customers'     => 'Customers',
		'new_customers' => 'New customers',
		'revenue'       => 'Net sales',
		'quantity'      => 'Items sold',
		'discount'      => 'Discount given',
		'retention'     => 'Retention',
	);

	const MONEY = array( 'net_sales', 'gross_sales', 'aov', 'refunds', 'revenue', 'discount' );

	/**
	 * Translated metric label.
	 *
	 * @param string $metric Metric.
	 * @return string
	 */
	public static function metric_label( $metric ) {
		$labels = array(
			'net_sales'     => __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ),
			'gross_sales'   => __( 'Gross sales', 'dev3-ai-store-insights-for-woocommerce' ),
			'orders'        => __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ),
			'items_sold'    => __( 'Items sold', 'dev3-ai-store-insights-for-woocommerce' ),
			'aov'           => __( 'Average order value', 'dev3-ai-store-insights-for-woocommerce' ),
			'refunds'       => __( 'Refunds', 'dev3-ai-store-insights-for-woocommerce' ),
			'refund_count'  => __( 'Refunded orders', 'dev3-ai-store-insights-for-woocommerce' ),
			'refund_rate'   => __( 'Refund rate', 'dev3-ai-store-insights-for-woocommerce' ),
			'customers'     => __( 'Customers', 'dev3-ai-store-insights-for-woocommerce' ),
			'new_customers' => __( 'New customers', 'dev3-ai-store-insights-for-woocommerce' ),
			'revenue'       => __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ),
			'quantity'      => __( 'Items sold', 'dev3-ai-store-insights-for-woocommerce' ),
			'discount'      => __( 'Discount given', 'dev3-ai-store-insights-for-woocommerce' ),
			'retention'     => __( 'Retention', 'dev3-ai-store-insights-for-woocommerce' ),
		);
		return isset( $labels[ $metric ] ) ? $labels[ $metric ] : $metric;
	}

	/**
	 * Display format of a metric.
	 *
	 * @param string $metric Metric.
	 * @return string money|number|percent
	 */
	public static function metric_format( $metric ) {
		if ( in_array( $metric, self::MONEY, true ) ) {
			return 'money';
		}
		return in_array( $metric, array( 'refund_rate', 'retention' ), true ) ? 'percent' : 'number';
	}

	/**
	 * Parameter definitions shared by functions.
	 *
	 * @return array
	 */
	private static function params() {
		return array(
			'period'        => array(
				'type'        => 'enum',
				'values'      => Period::PRESETS,
				'default'     => 'last_30_days',
				'description' => 'Date range. Use "custom" with from/to for explicit dates or named months (e.g. March 2026 = custom 2026-03-01..2026-03-31).',
			),
			'from'          => array(
				'type'        => 'date',
				'description' => 'Start date YYYY-MM-DD (only with period=custom).',
			),
			'to'            => array(
				'type'        => 'date',
				'description' => 'End date YYYY-MM-DD inclusive (only with period=custom).',
			),
			'compare'       => array(
				'type'        => 'enum',
				'values'      => array( 'previous_period', 'previous_year', 'none' ),
				'default'     => 'previous_period',
				'description' => 'Comparison period.',
			),
			'interval'      => array(
				'type'        => 'enum',
				'values'      => array( 'auto', 'day', 'week', 'month', 'quarter', 'year' ),
				'default'     => 'auto',
				'description' => 'Time bucket for trends.',
			),
			'limit'         => array(
				'type'        => 'int',
				'min'         => 1,
				'max'         => 50,
				'default'     => 10,
				'description' => 'How many rows (top N).',
			),
			'location'      => array(
				'type'        => 'string',
				'max'         => 80,
				'description' => 'Only orders from this place: a country name or ISO code, a state/region/emirate name, or a city (e.g. "Dubai", "Germany", "California").',
			),
			'category'      => array(
				'type'        => 'string',
				'max'         => 80,
				'description' => 'Only products in this product category (name), sub-categories included.',
			),
			'product'       => array(
				'type'        => 'string',
				'max'         => 80,
				'description' => 'Only this product (name or SKU).',
			),
			'customer_type' => array(
				'type'        => 'enum',
				'values'      => array( 'any', 'new', 'returning' ),
				'default'     => 'any',
				'description' => 'First-time or repeat customers.',
			),
			'customer_kind' => array(
				'type'        => 'enum',
				'values'      => array( 'any', 'registered', 'guest' ),
				'default'     => 'any',
				'description' => 'Registered accounts or guest checkouts.',
			),
		);
	}

	/**
	 * Function catalogue.
	 *
	 * @return array name => { label, description, params{}, run callable }
	 */
	public static function functions() {
		$common  = array( 'period', 'from', 'to' );
		$filters = array( 'location', 'category', 'product', 'customer_type', 'customer_kind' );
		$fns     = array(
			'store_summary'     => array(
				'label'       => __( 'Store summary (KPIs)', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Headline KPIs for a period with change vs a comparison period: net sales, orders, average order value, items sold, refunds, customers and new customers. Use for "how did we do", "overview", "summary" questions.',
				'params'      => array_merge( $common, array( 'compare' ), $filters ),
				'run'         => array( Functions::class, 'store_summary' ),
			),
			'sales_trend'       => array(
				'label'       => __( 'Sales trend over time', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'One metric over time (by day/week/month…) with the comparison period. Use for trends, "per month", "daily", AOV over time, growth.',
				'params'      => array_merge( array( 'metric' ), array( 'interval' ), $common, array( 'compare' ), $filters ),
				'metrics'     => array( 'net_sales', 'gross_sales', 'orders', 'items_sold', 'aov', 'refunds', 'customers' ),
				'run'         => array( Functions::class, 'sales_trend' ),
			),
			'top_products'      => array(
				'label'       => __( 'Top products', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Best-selling products ranked by revenue, quantity or orders. Use for "best sellers", "top products", "what sells most".',
				'params'      => array_merge( array( 'metric', 'limit' ), $common, array( 'location', 'category', 'customer_type', 'customer_kind' ) ),
				'metrics'     => array( 'revenue', 'quantity', 'orders' ),
				'run'         => array( Functions::class, 'top_products' ),
			),
			'top_categories'    => array(
				'label'       => __( 'Top categories', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Product categories ranked by revenue, quantity or orders.',
				'params'      => array_merge( array( 'metric', 'limit' ), $common, array( 'location', 'customer_type', 'customer_kind' ) ),
				'metrics'     => array( 'revenue', 'quantity', 'orders' ),
				'run'         => array( Functions::class, 'top_categories' ),
			),
			'sales_by_location' => array(
				'label'       => __( 'Sales by location', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Sales grouped by country, region/state or city of the order address. Use for "where do sales come from", "by country", "top cities".',
				'params'      => array_merge( array( 'level', 'metric', 'limit' ), $common, array( 'category', 'product', 'customer_type', 'customer_kind' ) ),
				'metrics'     => array( 'net_sales', 'orders', 'aov', 'items_sold', 'customers' ),
				'extra'       => array(
					'level' => array(
						'type'        => 'enum',
						'values'      => array( 'country', 'region', 'city' ),
						'default'     => 'country',
						'description' => 'Grouping level.',
					),
				),
				'run'         => array( Functions::class, 'sales_by_location' ),
			),
			'customer_segments' => array(
				'label'       => __( 'Customer segments', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Split of sales between new vs returning customers, or registered vs guest checkouts.',
				'params'      => array_merge( array( 'segment', 'metric' ), $common, array( 'location', 'category', 'product' ) ),
				'metrics'     => array( 'net_sales', 'orders', 'aov', 'customers' ),
				'extra'       => array(
					'segment' => array(
						'type'        => 'enum',
						'values'      => array( 'new_vs_returning', 'registered_vs_guest' ),
						'default'     => 'new_vs_returning',
						'description' => 'Segmentation.',
					),
				),
				'run'         => array( Functions::class, 'customer_segments' ),
			),
			'top_customers'     => array(
				'label'       => __( 'Top customers', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Customers ranked by spend, number of orders or average order value.',
				'params'      => array_merge( array( 'metric', 'limit' ), $common, array( 'location', 'category', 'product' ) ),
				'metrics'     => array( 'revenue', 'orders', 'aov' ),
				'run'         => array( Functions::class, 'top_customers' ),
			),
			'refunds'           => array(
				'label'       => __( 'Refunds', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Refund amounts and refund rate over time (view=trend) or the most refunded products (view=products).',
				'params'      => array_merge( array( 'view', 'interval' ), $common, array( 'compare', 'location', 'category', 'product' ) ),
				'extra'       => array(
					'view' => array(
						'type'        => 'enum',
						'values'      => array( 'trend', 'products' ),
						'default'     => 'trend',
						'description' => 'Trend over time or by product.',
					),
				),
				'run'         => array( Functions::class, 'refunds' ),
			),
			'cohorts'           => array(
				'label'       => __( 'Customer cohorts', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Monthly cohorts by first order month: share of customers who ordered again in later months (retention) or revenue per cohort month.',
				'params'      => array( 'months', 'metric' ),
				'metrics'     => array( 'retention', 'revenue' ),
				'extra'       => array(
					'months' => array(
						'type'        => 'int',
						'min'         => 3,
						'max'         => 12,
						'default'     => 6,
						'description' => 'Number of cohort months.',
					),
				),
				'run'         => array( Functions::class, 'cohorts' ),
			),
			'stock_status'      => array(
				'label'       => __( 'Stock status', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Inventory: low stock, out of stock, days of cover at the current sales rate (what runs out first), or overstock (slow movers).',
				'params'      => array( 'view', 'limit', 'category' ),
				'extra'       => array(
					'view' => array(
						'type'        => 'enum',
						'values'      => array( 'low', 'out', 'cover', 'overstock' ),
						'default'     => 'low',
						'description' => 'Which stock view.',
					),
				),
				'run'         => array( Functions::class, 'stock_status' ),
			),
			'top_coupons'       => array(
				'label'       => __( 'Top coupons', 'dev3-ai-store-insights-for-woocommerce' ),
				'description' => 'Coupons ranked by orders or discount given.',
				'params'      => array_merge( array( 'metric', 'limit' ), $common ),
				'metrics'     => array( 'orders', 'discount' ),
				'run'         => array( Functions::class, 'top_coupons' ),
			),
		);
		/**
		 * Add or remove query functions. Each needs label, description, params (ids), optional metrics/extra, run callable.
		 *
		 * @param array $fns Functions.
		 */
		return (array) apply_filters( 'dev3si_query_functions', $fns );
	}

	/**
	 * Full parameter specs of one function.
	 *
	 * @param array $def Function definition.
	 * @return array id => spec
	 */
	public static function param_specs( array $def ) {
		$all = self::params();
		$out = array();
		foreach ( $def['params'] as $id ) {
			if ( isset( $def['extra'][ $id ] ) ) {
				$out[ $id ] = $def['extra'][ $id ];
			} elseif ( 'metric' === $id ) {
				$out[ $id ] = array(
					'type'        => 'enum',
					'values'      => $def['metrics'],
					'default'     => $def['metrics'][0],
					'description' => 'Measure to use.',
				);
			} elseif ( isset( $all[ $id ] ) ) {
				$out[ $id ] = $all[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Gemini function declarations (OpenAPI subset).
	 *
	 * @return array
	 */
	public static function declarations() {
		$out = array();
		foreach ( self::functions() as $name => $fn ) {
			$props = array();
			foreach ( self::param_specs( $fn ) as $id => $spec ) {
				$prop = array( 'description' => $spec['description'] );
				switch ( $spec['type'] ) {
					case 'enum':
						$prop['type'] = 'STRING';
						$prop['enum'] = array_values( $spec['values'] );
						break;
					case 'int':
						$prop['type']        = 'INTEGER';
						$prop['description'] = $spec['description'] . " ({$spec['min']}-{$spec['max']})";
						break;
					default:
						$prop['type'] = 'STRING';
				}
				$props[ $id ] = $prop;
			}
			$out[] = array(
				'name'        => $name,
				'description' => $fn['description'],
				'parameters'  => array(
					'type'       => 'OBJECT',
					'properties' => $props,
				),
			);
		}
		return $out;
	}

	/**
	 * Validates a call. Unknown functions are errors; unknown keys are dropped; bad enums fall back to
	 * the default; numbers are clamped; strings are sanitised and cut.
	 *
	 * @param string $name Function.
	 * @param mixed  $args Args.
	 * @return array|\WP_Error { fn, args }
	 */
	public static function sanitize( $name, $args ) {
		$name = sanitize_key( (string) $name );
		$fns  = self::functions();
		if ( ! isset( $fns[ $name ] ) ) {
			return Api_Error::make( 'dev3si_unknown_function', __( 'That question maps to a report this plugin does not have.', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Rephrase the question or use Explore.', 'dev3-ai-store-insights-for-woocommerce' ), 400 );
		}
		$args = is_array( $args ) ? $args : array();
		$out  = array();
		foreach ( self::param_specs( $fns[ $name ] ) as $id => $spec ) {
			$value = isset( $args[ $id ] ) ? $args[ $id ] : null;
			switch ( $spec['type'] ) {
				case 'enum':
					$value      = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
					$out[ $id ] = in_array( $value, $spec['values'], true ) ? $value : $spec['default'];
					break;
				case 'int':
					$value      = is_numeric( $value ) ? (int) $value : (int) $spec['default'];
					$out[ $id ] = max( (int) $spec['min'], min( (int) $spec['max'], $value ) );
					break;
				case 'date':
					$value = is_scalar( $value ) ? Period::clean_date( $value ) : '';
					if ( '' !== $value ) {
						$out[ $id ] = $value;
					}
					break;
				default:
					$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
					$value = mb_substr( $value, 0, isset( $spec['max'] ) ? (int) $spec['max'] : 80 );
					if ( '' !== $value ) {
						$out[ $id ] = $value;
					}
			}
		}
		if ( isset( $out['period'] ) && 'custom' !== $out['period'] ) {
			unset( $out['from'], $out['to'] );
		} elseif ( isset( $out['period'] ) && ( empty( $out['from'] ) || empty( $out['to'] ) ) ) {
			$out['period'] = 'last_30_days';
			unset( $out['from'], $out['to'] );
		}
		foreach ( array( 'customer_type', 'customer_kind' ) as $key ) {
			if ( isset( $out[ $key ] ) && 'any' === $out[ $key ] ) {
				unset( $out[ $key ] );
			}
		}
		return array(
			'fn'   => $name,
			'args' => $out,
		);
	}

	/**
	 * Runs a sanitised call.
	 *
	 * @param string $name Function.
	 * @param array  $args Args (raw is fine: sanitised here).
	 * @param array  $over Overrides applied after sanitising (e.g. a dashboard period).
	 * @return array|\WP_Error Result.
	 */
	public static function run( $name, $args, array $over = array() ) {
		if ( ! Store::ready() ) {
			return Api_Error::make( 'dev3si_no_analytics', __( 'WooCommerce Analytics tables were not found.', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Activate WooCommerce 8+ and let it finish its Analytics import.', 'dev3-ai-store-insights-for-woocommerce' ), 409 );
		}
		$call = self::sanitize( $name, $args );
		if ( is_wp_error( $call ) ) {
			return $call;
		}
		$fns  = self::functions();
		$spec = self::param_specs( $fns[ $call['fn'] ] );
		foreach ( $over as $key => $value ) {
			if ( isset( $spec[ $key ] ) && null !== $value && '' !== $value ) {
				$call['args'][ $key ] = $value;
			}
		}
		if ( $over ) {
			$call = self::sanitize( $call['fn'], $call['args'] );
		}
		$result = call_user_func( $fns[ $call['fn'] ]['run'], $call['args'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = array_merge(
			array(
				'fn'       => $call['fn'],
				'args'     => $call['args'],
				'label'    => $fns[ $call['fn'] ]['label'],
				'notes'    => array(),
				'summary'  => null,
				'currency' => Store::currency(),
			),
			$result
		);
		/**
		 * Filters a query result.
		 *
		 * @param array  $result Result.
		 * @param string $fn     Function.
		 * @param array  $args   Args.
		 */
		return apply_filters( 'dev3si_query_result', $result, $call['fn'], $call['args'] );
	}

	/**
	 * Catalogue for the UI (labels, params with options).
	 *
	 * @return array
	 */
	public static function catalogue() {
		$out = array();
		foreach ( self::functions() as $name => $fn ) {
			$params = array();
			foreach ( self::param_specs( $fn ) as $id => $spec ) {
				$item = array(
					'id'   => $id,
					'type' => $spec['type'],
				);
				if ( 'enum' === $spec['type'] ) {
					$item['options'] = array_map(
						static function ( $v ) {
							return array(
								'value' => $v,
								'label' => Library::option_label( $v ),
							);
						},
						$spec['values']
					);
					$item['default'] = $spec['default'];
				}
				if ( 'int' === $spec['type'] ) {
					$item += array(
						'min'     => $spec['min'],
						'max'     => $spec['max'],
						'default' => $spec['default'],
					);
				}
				$params[] = $item;
			}
			$out[] = array(
				'id'     => $name,
				'label'  => $fn['label'],
				'params' => $params,
			);
		}
		return $out;
	}

	/**
	 * Label for an enum value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function option_label( $value ) {
		$periods = Period::labels();
		if ( isset( $periods[ $value ] ) ) {
			return $periods[ $value ];
		}
		$map = array(
			'previous_period'     => __( 'Previous period', 'dev3-ai-store-insights-for-woocommerce' ),
			'previous_year'       => __( 'Same period last year', 'dev3-ai-store-insights-for-woocommerce' ),
			'none'                => __( 'No comparison', 'dev3-ai-store-insights-for-woocommerce' ),
			'auto'                => __( 'Automatic', 'dev3-ai-store-insights-for-woocommerce' ),
			'day'                 => __( 'Day', 'dev3-ai-store-insights-for-woocommerce' ),
			'week'                => __( 'Week', 'dev3-ai-store-insights-for-woocommerce' ),
			'month'               => __( 'Month', 'dev3-ai-store-insights-for-woocommerce' ),
			'quarter'             => __( 'Quarter', 'dev3-ai-store-insights-for-woocommerce' ),
			'year'                => __( 'Year', 'dev3-ai-store-insights-for-woocommerce' ),
			'any'                 => __( 'Any', 'dev3-ai-store-insights-for-woocommerce' ),
			'new'                 => __( 'New', 'dev3-ai-store-insights-for-woocommerce' ),
			'returning'           => __( 'Returning', 'dev3-ai-store-insights-for-woocommerce' ),
			'registered'          => __( 'Registered', 'dev3-ai-store-insights-for-woocommerce' ),
			'guest'               => __( 'Guest', 'dev3-ai-store-insights-for-woocommerce' ),
			'country'             => __( 'Country', 'dev3-ai-store-insights-for-woocommerce' ),
			'region'              => __( 'Region / state', 'dev3-ai-store-insights-for-woocommerce' ),
			'city'                => __( 'City', 'dev3-ai-store-insights-for-woocommerce' ),
			'new_vs_returning'    => __( 'New vs returning', 'dev3-ai-store-insights-for-woocommerce' ),
			'registered_vs_guest' => __( 'Registered vs guest', 'dev3-ai-store-insights-for-woocommerce' ),
			'trend'               => __( 'Trend', 'dev3-ai-store-insights-for-woocommerce' ),
			'products'            => __( 'By product', 'dev3-ai-store-insights-for-woocommerce' ),
			'low'                 => __( 'Low stock', 'dev3-ai-store-insights-for-woocommerce' ),
			'out'                 => __( 'Out of stock', 'dev3-ai-store-insights-for-woocommerce' ),
			'cover'               => __( 'Days of cover', 'dev3-ai-store-insights-for-woocommerce' ),
			'overstock'           => __( 'Overstock', 'dev3-ai-store-insights-for-woocommerce' ),
		);
		return isset( $map[ $value ] ) ? $map[ $value ] : self::metric_label( $value );
	}

	/**
	 * One-line description of a call ("Top products · Net sales · Last month · Location: Dubai").
	 *
	 * @param array $result Result or call.
	 * @return string
	 */
	public static function describe( array $result ) {
		$fns   = self::functions();
		$parts = array( isset( $fns[ $result['fn'] ] ) ? $fns[ $result['fn'] ]['label'] : $result['fn'] );
		$args  = $result['args'];
		if ( isset( $args['metric'] ) ) {
			$parts[] = self::metric_label( $args['metric'] );
		}
		if ( isset( $result['period']['label'] ) ) {
			$parts[] = $result['period']['label'];
		} elseif ( isset( $args['period'] ) ) {
			$parts[] = 'custom' === $args['period'] && isset( $args['from'], $args['to'] ) ? $args['from'] . ' – ' . $args['to'] : self::option_label( $args['period'] );
		}
		foreach ( array( 'location', 'category', 'product' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$parts[] = $args[ $key ];
			}
		}
		return implode( ' · ', $parts );
	}
}
