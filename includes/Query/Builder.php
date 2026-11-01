<?php
/**
 * Prepared, parameterised aggregate queries over the WooCommerce Analytics tables.
 *
 * Callers only pass whitelisted metric ids, trusted SQL fragments written in this plugin, and values,
 * which always go through $wpdb->prepare(). Nothing from a user or the AI model becomes SQL text.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Aliases: s = wc_order_stats, p = wc_order_product_lookup (items source), c = wc_customer_lookup.
 */
class Builder {

	/**
	 * Metric expressions per source.
	 */
	const METRICS = array(
		'orders' => array(
			'net_sales'     => 'SUM(s.net_total)',
			'gross_sales'   => 'SUM(s.total_sales)',
			'orders'        => 'SUM(CASE WHEN s.parent_id = 0 THEN 1 ELSE 0 END)',
			'items_sold'    => 'SUM(s.num_items_sold)',
			'refunds'       => 'SUM(CASE WHEN s.parent_id > 0 THEN -s.total_sales ELSE 0 END)',
			'refund_count'  => 'SUM(CASE WHEN s.parent_id > 0 THEN 1 ELSE 0 END)',
			'customers'     => 'COUNT(DISTINCT CASE WHEN s.parent_id = 0 THEN s.customer_id END)',
			'new_customers' => 'COUNT(DISTINCT CASE WHEN s.parent_id = 0 AND s.returning_customer = 0 THEN s.customer_id END)',
			'order_net'     => 'SUM(CASE WHEN s.parent_id = 0 THEN s.net_total ELSE 0 END)',
			'order_gross'   => 'SUM(CASE WHEN s.parent_id = 0 THEN s.total_sales ELSE 0 END)',
		),
		'items'  => array(
			'net_sales'     => 'SUM(p.product_net_revenue)',
			'gross_sales'   => 'SUM(p.product_gross_revenue)',
			'orders'        => 'COUNT(DISTINCT CASE WHEN s.parent_id = 0 THEN p.order_id END)',
			'items_sold'    => 'SUM(p.product_qty)',
			'refunds'       => 'SUM(CASE WHEN s.parent_id > 0 THEN -p.product_gross_revenue ELSE 0 END)',
			'refund_count'  => 'COUNT(DISTINCT CASE WHEN s.parent_id > 0 THEN p.order_id END)',
			'customers'     => 'COUNT(DISTINCT CASE WHEN s.parent_id = 0 THEN p.customer_id END)',
			'new_customers' => 'COUNT(DISTINCT CASE WHEN s.parent_id = 0 AND s.returning_customer = 0 THEN p.customer_id END)',
			'order_net'     => 'SUM(CASE WHEN s.parent_id = 0 THEN p.product_net_revenue ELSE 0 END)',
			'order_gross'   => 'SUM(CASE WHEN s.parent_id = 0 THEN p.product_gross_revenue ELSE 0 END)',
		),
	);

	/**
	 * Source: orders|items.
	 *
	 * @var string
	 */
	public $source = 'orders';

	/**
	 * JOIN clauses keyed by name.
	 *
	 * @var array
	 */
	private $joins = array();

	/**
	 * WHERE fragments with placeholders.
	 *
	 * @var array
	 */
	private $where = array();

	/**
	 * Values for the placeholders, in order.
	 *
	 * @var array
	 */
	private $params = array();

	/**
	 * Location columns once joined.
	 *
	 * @var array|null
	 */
	private $loc = null;

	/**
	 * Human notes about the filters (e.g. "Location matched: Dubai (state, city)").
	 *
	 * @var array
	 */
	public $notes = array();

	/**
	 * New builder.
	 *
	 * @param string $source orders|items.
	 */
	public function __construct( $source = 'orders' ) {
		$this->source = 'items' === $source ? 'items' : 'orders';
		$statuses     = Store::excluded_statuses();
		$this->where( 's.status NOT IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', $statuses );
	}

	/**
	 * Builder for a period + filters.
	 *
	 * @param string $source  orders|items.
	 * @param array  $period  Resolved period (from/to).
	 * @param array  $filters Sanitised filters.
	 * @return Builder
	 */
	public static function make( $source, array $period, array $filters = array() ) {
		$b = new self( $source );
		$b->period( $period );
		$b->filters( $filters );
		return $b;
	}

	/**
	 * Adds a WHERE fragment.
	 *
	 * @param string $sql    Trusted SQL with placeholders.
	 * @param array  $params Values.
	 * @return $this
	 */
	public function where( $sql, array $params = array() ) {
		$this->where[] = $sql;
		foreach ( $params as $p ) {
			$this->params[] = $p;
		}
		return $this;
	}

	/**
	 * Adds a JOIN once.
	 *
	 * @param string $name Key.
	 * @param string $sql  Trusted SQL.
	 * @return $this
	 */
	public function join( $name, $sql ) {
		$this->joins[ $name ] = $sql;
		return $this;
	}

	/**
	 * Restricts to a date range.
	 *
	 * @param array $period Period.
	 * @return $this
	 */
	public function period( array $period ) {
		return $this->where( 's.date_created >= %s AND s.date_created <= %s', array( $period['from'], $period['to'] ) );
	}

	/**
	 * Only parent orders (no refund rows).
	 *
	 * @return $this
	 */
	public function orders_only() {
		return $this->where( 's.parent_id = 0' );
	}

	/**
	 * Only refund rows.
	 *
	 * @return $this
	 */
	public function refunds_only() {
		return $this->where( 's.parent_id > 0' );
	}

	/**
	 * Joins the customer lookup (alias c).
	 *
	 * @return $this
	 */
	public function with_customers() {
		$t = Store::tables();
		return $this->join( 'c', "LEFT JOIN {$t['customers']} c ON c.customer_id = s.customer_id" );
	}

	/**
	 * Joins the order address and returns its columns.
	 *
	 * @return array { country, state, city }
	 */
	public function location() {
		if ( null === $this->loc ) {
			$this->loc = Store::location_sql();
			$this->join( 'loc', $this->loc['join'] );
		}
		return $this->loc;
	}

	/**
	 * Applies filters: location, category, product, customer_type, customer_kind.
	 *
	 * @param array $filters Filters.
	 * @return $this
	 */
	public function filters( array $filters ) {
		global $wpdb;
		$t = Store::tables();
		if ( ! empty( $filters['customer_type'] ) && in_array( $filters['customer_type'], array( 'new', 'returning' ), true ) ) {
			$this->where( 's.returning_customer = %d', array( 'returning' === $filters['customer_type'] ? 1 : 0 ) );
			$this->notes[] = 'new' === $filters['customer_type'] ? __( 'New customers only', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Returning customers only', 'dev3-ai-store-insights-for-woocommerce' );
		}
		if ( ! empty( $filters['customer_kind'] ) && in_array( $filters['customer_kind'], array( 'registered', 'guest' ), true ) ) {
			$this->with_customers();
			$this->where( 'registered' === $filters['customer_kind'] ? '(c.user_id IS NOT NULL AND c.user_id > 0)' : '(c.user_id IS NULL OR c.user_id = 0)' );
			$this->notes[] = 'registered' === $filters['customer_kind'] ? __( 'Registered customers only', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Guest checkouts only', 'dev3-ai-store-insights-for-woocommerce' );
		}
		if ( ! empty( $filters['location'] ) ) {
			$resolved = Store::resolve_location( $filters['location'] );
			if ( $resolved ) {
				$loc    = $this->location();
				$parts  = array();
				$values = array();
				foreach ( $resolved['countries'] as $code ) {
					$parts[]  = "{$loc['country']} = %s";
					$values[] = $code;
				}
				foreach ( $resolved['states'] as $pair ) {
					$parts[]  = "({$loc['country']} = %s AND {$loc['state']} = %s)";
					$values[] = $pair['country'];
					$values[] = $pair['state'];
				}
				foreach ( $resolved['cities'] as $city ) {
					$parts[]  = "LOWER({$loc['city']}) = %s";
					$values[] = mb_strtolower( $city );
				}
				$this->where( '(' . implode( ' OR ', $parts ) . ')', $values );
				/* translators: %s: location */
				$this->notes[] = sprintf( __( 'Location: %s', 'dev3-ai-store-insights-for-woocommerce' ), $resolved['label'] );
			}
		}
		$product_ids = null;
		if ( ! empty( $filters['product'] ) ) {
			$resolved = Store::resolve_product( $filters['product'] );
			if ( $resolved ) {
				$product_ids = $resolved['ids'];
				/* translators: %s: product */
				$this->notes[] = sprintf( __( 'Product: %s', 'dev3-ai-store-insights-for-woocommerce' ), $resolved['label'] );
			}
		}
		$category = ! empty( $filters['category'] ) ? Store::resolve_category( $filters['category'] ) : null;
		if ( $category ) {
			/* translators: %s: category */
			$this->notes[] = sprintf( __( 'Category: %s', 'dev3-ai-store-insights-for-woocommerce' ), $category['label'] );
		}
		if ( null !== $product_ids || $category ) {
			$conds  = array();
			$values = array();
			if ( null !== $product_ids ) {
				$ph      = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
				$conds[] = "(%%COL%% IN ({$ph}) OR %%VAR%% IN ({$ph}))";
				$values  = array_merge( $values, $product_ids, $product_ids );
			}
			if ( $category ) {
				$ph      = implode( ',', array_fill( 0, count( $category['ids'] ), '%d' ) );
				$conds[] = "%%COL%% IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ({$ph}))";
				$values  = array_merge( $values, $category['ids'] );
			}
			$cond = implode( ' AND ', $conds );
			if ( 'items' === $this->source ) {
				$this->where( str_replace( array( '%%COL%%', '%%VAR%%' ), array( 'p.product_id', 'p.variation_id' ), $cond ), $values );
			} else {
				$inner = str_replace( array( '%%COL%%', '%%VAR%%' ), array( 'fp.product_id', 'fp.variation_id' ), $cond );
				$this->where( "s.order_id IN (SELECT fp.order_id FROM {$t['products']} fp WHERE {$inner})", $values );
			}
		}
		return $this;
	}

	/**
	 * Metric SQL for this source.
	 *
	 * @param string $metric Metric id.
	 * @return string
	 */
	public function metric( $metric ) {
		$map = self::METRICS[ $this->source ];
		return isset( $map[ $metric ] ) ? $map[ $metric ] : $map['net_sales'];
	}

	/**
	 * Runs SELECT … GROUP BY.
	 *
	 * @param array  $selects Alias => trusted expression. Metric ids may be passed as '@metric'.
	 * @param string $group   Trusted GROUP BY expression ('' = none).
	 * @param string $order   Trusted ORDER BY ('' = none).
	 * @param int    $limit   Limit (0 = none).
	 * @param string $having  Trusted HAVING ('' = none).
	 * @return array Rows (ARRAY_A).
	 */
	public function select( array $selects, $group = '', $order = '', $limit = 0, $having = '' ) {
		global $wpdb;
		$t    = Store::tables();
		$cols = array();
		foreach ( $selects as $alias => $expr ) {
			if ( 0 === strpos( $expr, '@' ) ) {
				$expr = $this->metric( substr( $expr, 1 ) );
			}
			$cols[] = $expr . ' AS ' . sanitize_key( $alias );
		}
		$from = 'items' === $this->source
			? "{$t['products']} p INNER JOIN {$t['stats']} s ON s.order_id = p.order_id"
			: "{$t['stats']} s";
		$sql  = 'SELECT ' . implode( ', ', $cols ) . " FROM {$from} " . implode( ' ', $this->joins ) . ' WHERE ' . implode( ' AND ', $this->where );
		if ( '' !== $group ) {
			$sql .= ' GROUP BY ' . $group;
		}
		if ( '' !== $having ) {
			$sql .= ' HAVING ' . $having;
		}
		if ( '' !== $order ) {
			$sql .= ' ORDER BY ' . $order;
		}
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		$prepared = $this->params ? $wpdb->prepare( $sql, $this->params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One row of totals for a list of metric ids.
	 *
	 * @param string[] $metrics Metric ids.
	 * @return array metric => float
	 */
	public function totals( array $metrics ) {
		$selects = array();
		foreach ( $metrics as $m ) {
			$selects[ $m ] = '@' . $m;
		}
		$rows = $this->select( $selects );
		$out  = array();
		foreach ( $metrics as $m ) {
			$out[ $m ] = isset( $rows[0][ $m ] ) ? (float) $rows[0][ $m ] : 0.0;
		}
		return self::derive( $out );
	}

	/**
	 * Adds derived metrics (aov, refund_rate) to a row of base metrics.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function derive( array $row ) {
		if ( isset( $row['order_net'], $row['orders'] ) ) {
			$row['aov'] = $row['orders'] > 0 ? round( (float) $row['order_net'] / (float) $row['orders'], 2 ) : 0.0;
		}
		if ( isset( $row['refunds'], $row['order_gross'] ) ) {
			$row['refund_rate'] = $row['order_gross'] > 0 ? round( (float) $row['refunds'] / (float) $row['order_gross'] * 100, 2 ) : 0.0;
		}
		return $row;
	}
}
