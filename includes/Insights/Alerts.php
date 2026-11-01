<?php
/**
 * Anomaly alerts: rules, daily detection against a 28-day baseline, drill-down movers, log and e-mail.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Insights;

use Dev3\AiStoreInsights\Ask\Explainer;
use Dev3\AiStoreInsights\Ask\Prompts;
use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Api\Api_Error;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Query\Builder;
use Dev3\AiStoreInsights\Query\Functions;
use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;
use Dev3\AiStoreInsights\Settings as Plugin_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts engine.
 */
class Alerts {

	const OPTION    = 'dev3si_alert_rules';
	const CHECKED   = 'dev3si_alerts_checked';
	const METRICS   = array( 'net_sales', 'orders', 'aov', 'refunds', 'refund_rate', 'items_sold' );
	const BASE_DAYS = 28;

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3si_alerts';
	}

	/**
	 * Default rules.
	 *
	 * @return array
	 */
	public static function default_rules() {
		return array(
			array(
				'id'             => 'net-sales-drop',
				'type'           => 'metric',
				'name'           => __( 'Net sales dropped', 'dev3-ai-store-insights-for-woocommerce' ),
				'metric'         => 'net_sales',
				'direction'      => 'drop',
				'sensitivity'    => 'medium',
				'min_change_pct' => 30,
				'min_baseline'   => 50,
			),
			array(
				'id'             => 'orders-unusual',
				'type'           => 'metric',
				'name'           => __( 'Unusual number of orders', 'dev3-ai-store-insights-for-woocommerce' ),
				'metric'         => 'orders',
				'direction'      => 'both',
				'sensitivity'    => 'medium',
				'min_change_pct' => 40,
				'min_baseline'   => 3,
			),
			array(
				'id'             => 'refunds-spike',
				'type'           => 'metric',
				'name'           => __( 'Refunds spiked', 'dev3-ai-store-insights-for-woocommerce' ),
				'metric'         => 'refunds',
				'direction'      => 'spike',
				'sensitivity'    => 'medium',
				'min_change_pct' => 50,
				'min_baseline'   => 0,
			),
			array(
				'id'   => 'stock-best-sellers',
				'type' => 'stock',
				'name' => __( 'Best sellers about to run out', 'dev3-ai-store-insights-for-woocommerce' ),
				'days' => 7,
			),
		);
	}

	/**
	 * Sanitises one rule.
	 *
	 * @param array $rule Raw rule.
	 * @return array|null
	 */
	public static function clean_rule( $rule ) {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$type = isset( $rule['type'] ) && 'stock' === $rule['type'] ? 'stock' : 'metric';
		$id   = isset( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : '';
		$out  = array(
			'id'         => '' !== $id ? substr( $id, 0, 40 ) : 'rule-' . strtolower( wp_generate_password( 8, false ) ),
			'type'       => $type,
			'name'       => mb_substr( sanitize_text_field( (string) ( $rule['name'] ?? '' ) ), 0, 80 ),
			'enabled'    => ! isset( $rule['enabled'] ) || (bool) $rule['enabled'],
			'notify'     => ! isset( $rule['notify'] ) || (bool) $rule['notify'],
			'recipients' => Plugin_Settings::clean_emails( (string) ( $rule['recipients'] ?? '' ) ),
		);
		if ( 'stock' === $type ) {
			$out['days'] = max( 1, min( 60, (int) ( $rule['days'] ?? 7 ) ) );
			if ( '' === $out['name'] ) {
				$out['name'] = __( 'Best sellers about to run out', 'dev3-ai-store-insights-for-woocommerce' );
			}
			return $out;
		}
		$metric = isset( $rule['metric'] ) && in_array( $rule['metric'], self::METRICS, true ) ? $rule['metric'] : 'net_sales';
		$out   += array(
			'metric'         => $metric,
			'direction'      => isset( $rule['direction'] ) && in_array( $rule['direction'], array( 'drop', 'spike', 'both' ), true ) ? $rule['direction'] : 'both',
			'sensitivity'    => isset( $rule['sensitivity'] ) && in_array( $rule['sensitivity'], array( 'low', 'medium', 'high' ), true ) ? $rule['sensitivity'] : 'medium',
			'min_change_pct' => max( 0, min( 500, (int) ( $rule['min_change_pct'] ?? 25 ) ) ),
			'min_baseline'   => max( 0, min( 1000000000, (float) ( $rule['min_baseline'] ?? 0 ) ) ),
			'location'       => mb_substr( sanitize_text_field( (string) ( $rule['location'] ?? '' ) ), 0, 80 ),
			'category'       => mb_substr( sanitize_text_field( (string) ( $rule['category'] ?? '' ) ), 0, 80 ),
		);
		if ( '' === $out['name'] ) {
			$out['name'] = Library::metric_label( $metric );
		}
		return $out;
	}

	/**
	 * Rules (defaults on first use).
	 *
	 * @return array
	 */
	public static function rules() {
		$rules = get_option( self::OPTION, null );
		if ( ! is_array( $rules ) ) {
			$rules = self::default_rules();
		}
		return array_values( array_filter( array_map( array( self::class, 'clean_rule' ), $rules ) ) );
	}

	/**
	 * Saves rules.
	 *
	 * @param array $rules Rules.
	 * @return array
	 */
	public static function save_rules( $rules ) {
		$clean = array();
		$seen  = array();
		foreach ( array_slice( (array) $rules, 0, 30 ) as $rule ) {
			$rule = self::clean_rule( $rule );
			if ( $rule && ! isset( $seen[ $rule['id'] ] ) ) {
				$seen[ $rule['id'] ] = true;
				$clean[]             = $rule;
			}
		}
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * The z-score threshold.
	 *
	 * @param array $rule Rule.
	 * @return float
	 */
	public static function threshold( array $rule ) {
		$map = array(
			'low'    => 3.0,
			'medium' => 2.5,
			'high'   => 2.0,
		);
		/**
		 * The z-score a value must reach to raise an alert.
		 *
		 * @param float $z    Threshold.
		 * @param array $rule Rule.
		 */
		return (float) apply_filters( 'dev3si_alert_threshold', $map[ $rule['sensitivity'] ], $rule );
	}

	/**
	 * Daily values of a metric for the 28 days before `$day` plus `$day` itself.
	 *
	 * @param string $metric  Metric.
	 * @param string $day     Y-m-d.
	 * @param array  $filters Filters.
	 * @return array [ Y-m-d => value ] (29 entries, oldest first)
	 */
	public static function daily( $metric, $day, array $filters = array() ) {
		$tz     = wp_timezone();
		$end    = new \DateTimeImmutable( $day . ' 23:59:59', $tz );
		$start  = $end->setTime( 0, 0, 0 )->modify( '-' . self::BASE_DAYS . ' days' );
		$period = array(
			'from' => $start->format( 'Y-m-d H:i:s' ),
			'to'   => $end->format( 'Y-m-d H:i:s' ),
		);
		$source = ! empty( $filters['category'] ) ? 'items' : 'orders';
		$b      = Builder::make( $source, $period, $filters );
		$rows   = $b->select(
			array(
				'd'           => Period::bucket_sql( 's.date_created', 'day' ),
				'net_sales'   => '@net_sales',
				'orders'      => '@orders',
				'order_net'   => '@order_net',
				'order_gross' => '@order_gross',
				'refunds'     => '@refunds',
				'items_sold'  => '@items_sold',
			),
			'd'
		);
		$found  = array();
		foreach ( $rows as $row ) {
			$row = Builder::derive( $row );
			$found[ substr( (string) $row['d'], 0, 10 ) ] = (float) $row[ $metric ];
		}
		$out = array();
		for ( $i = 0; $i <= self::BASE_DAYS; $i++ ) {
			$key         = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
			$out[ $key ] = isset( $found[ $key ] ) ? round( $found[ $key ], 2 ) : 0.0;
		}
		return $out;
	}

	/**
	 * Pure statistics: does `$value` stand out from `$baseline` for this rule?
	 *
	 * @param array $rule     Rule.
	 * @param float $value    Day value.
	 * @param array $baseline Baseline values.
	 * @return array|null { value, baseline, change_pct, zscore, severity, direction }
	 */
	public static function evaluate( array $rule, $value, array $baseline ) {
		$n = count( $baseline );
		if ( $n < 7 ) {
			return null;
		}
		$mean = array_sum( $baseline ) / $n;
		$var  = 0.0;
		foreach ( $baseline as $v ) {
			$var += ( $v - $mean ) * ( $v - $mean );
		}
		$std = sqrt( $var / $n );
		$dir = $value >= $mean ? 'spike' : 'drop';
		if ( 'both' !== $rule['direction'] && $dir !== $rule['direction'] ) {
			return null;
		}
		$size = 'spike' === $dir ? max( $mean, $value ) : $mean;
		if ( $size < (float) $rule['min_baseline'] || ( 0.0 === (float) $mean && 0.0 === (float) $value ) ) {
			return null;
		}
		if ( $std > 0 ) {
			$z = ( $value - $mean ) / $std;
		} else {
			$z = abs( $value - $mean ) > 0.0001 ? ( $value > $mean ? 9.9 : -9.9 ) : 0.0;
		}
		$change = $mean > 0 ? ( $value - $mean ) / $mean * 100 : ( $value > 0 ? 100.0 : 0.0 );
		$thr    = self::threshold( $rule );
		if ( abs( $z ) < $thr || abs( $change ) < (float) $rule['min_change_pct'] ) {
			return null;
		}
		$severity = abs( $z ) >= $thr + 1.5 ? 'high' : ( abs( $z ) >= $thr + 0.5 ? 'medium' : 'low' );
		return array(
			'value'      => round( $value, 2 ),
			'baseline'   => round( $mean, 2 ),
			'change_pct' => round( $change, 1 ),
			'zscore'     => round( max( -99, min( 99, $z ) ), 2 ),
			'severity'   => $severity,
			'direction'  => $dir,
		);
	}

	/**
	 * Products and countries that moved most on `$day` vs their 28-day daily average.
	 *
	 * @param string $day     Y-m-d.
	 * @param array  $filters Filters.
	 * @param string $dir     drop|spike.
	 * @return array { products[], locations[] }
	 */
	public static function movers( $day, array $filters = array(), $dir = 'drop' ) {
		$tz    = wp_timezone();
		$d0    = new \DateTimeImmutable( $day . ' 00:00:00', $tz );
		$daysp = array(
			'from' => $d0->format( 'Y-m-d H:i:s' ),
			'to'   => $d0->setTime( 23, 59, 59 )->format( 'Y-m-d H:i:s' ),
		);
		$basep = array(
			'from' => $d0->modify( '-' . self::BASE_DAYS . ' days' )->format( 'Y-m-d H:i:s' ),
			'to'   => $d0->modify( '-1 second' )->format( 'Y-m-d H:i:s' ),
		);
		$out   = array();
		foreach ( array( 'products', 'locations' ) as $kind ) {
			$values = array();
			foreach ( array(
				'day'  => $daysp,
				'base' => $basep,
			) as $which => $period ) {
				if ( 'products' === $kind ) {
					$b    = Builder::make( 'items', $period, $filters );
					$rows = $b->select(
						array(
							'k' => 'p.product_id',
							'v' => '@net_sales',
						),
						'p.product_id',
						'v DESC',
						200
					);
				} else {
					$b    = Builder::make( ! empty( $filters['category'] ) ? 'items' : 'orders', $period, $filters );
					$loc  = $b->location();
					$rows = $b->select(
						array(
							'k' => "COALESCE({$loc['country']}, '')",
							'v' => '@net_sales',
						),
						'k',
						'v DESC',
						100
					);
				}
				foreach ( $rows as $row ) {
					$key = (string) $row['k'];
					if ( ! isset( $values[ $key ] ) ) {
						$values[ $key ] = array(
							'day'  => 0.0,
							'base' => 0.0,
						);
					}
					$values[ $key ][ $which ] = 'base' === $which ? (float) $row['v'] / self::BASE_DAYS : (float) $row['v'];
				}
			}
			$list = array();
			foreach ( $values as $key => $v ) {
				$diff = $v['day'] - $v['base'];
				if ( ( 'drop' === $dir && $diff >= 0 ) || ( 'spike' === $dir && $diff <= 0 ) ) {
					continue;
				}
				if ( 'products' === $kind ) {
					$name = Store::product_name( (int) $key );
				} else {
					$name = '' === $key ? __( 'Unknown', 'dev3-ai-store-insights-for-woocommerce' ) : Store::country_name( strtoupper( $key ) );
				}
				$list[] = array(
					'name'     => $name,
					'day'      => round( $v['day'], 2 ),
					'baseline' => round( $v['base'], 2 ),
					'diff'     => round( $diff, 2 ),
				);
			}
			usort(
				$list,
				static function ( $a, $b ) {
					return abs( $b['diff'] ) <=> abs( $a['diff'] );
				}
			);
			$out[ $kind ] = array_slice( $list, 0, 5 );
		}
		return $out;
	}

	/**
	 * Detects one rule on one day; stores and returns the alert, or null.
	 *
	 * @param array  $rule Rule.
	 * @param string $day  Y-m-d.
	 * @return array|null
	 */
	public static function detect( array $rule, $day ) {
		if ( self::exists( $rule['id'], $day ) ) {
			return null;
		}
		if ( 'stock' === $rule['type'] ) {
			return self::detect_stock( $rule, $day );
		}
		$filters = array_filter(
			array(
				'location' => $rule['location'],
				'category' => $rule['category'],
			)
		);
		$series  = self::daily( $rule['metric'], $day, $filters );
		$value   = $series[ $day ];
		$base    = array_values( array_slice( $series, 0, self::BASE_DAYS ) );
		$hit     = self::evaluate( $rule, $value, $base );
		if ( ! $hit ) {
			return null;
		}
		$detail = array(
			'rule'      => $rule['name'],
			'direction' => $hit['direction'],
			'format'    => Library::metric_format( $rule['metric'] ),
			'filters'   => $filters,
			'series'    => array_map(
				static function ( $k, $v ) {
					return array(
						'key'   => $k,
						'label' => wp_date( 'j M', strtotime( $k . ' 12:00:00' ) ),
						'value' => $v,
					);
				},
				array_keys( $series ),
				array_values( $series )
			),
			'movers'    => self::movers( $day, $filters, $hit['direction'] ),
		);
		return self::store( $rule, $day, $hit, $detail );
	}

	/**
	 * Stock rule: top sellers (last 30 days) whose stock lasts fewer than N days.
	 *
	 * @param array  $rule Rule.
	 * @param string $day  Day.
	 * @return array|null
	 */
	private static function detect_stock( array $rule, $day ) {
		$cover = Functions::stock_status(
			array(
				'view'  => 'cover',
				'limit' => 50,
			)
		);
		$sold  = Functions::units_sold( 30 );
		arsort( $sold );
		$top   = array_slice( array_keys( $sold ), 0, 10 );
		$items = array();
		foreach ( $cover['rows'] as $row ) {
			if ( in_array( (int) $row['id'], $top, true ) && null !== $row['cover'] && $row['cover'] < $rule['days'] ) {
				$items[] = $row;
			}
		}
		if ( ! $items ) {
			return null;
		}
		$hit = array(
			'value'      => count( $items ),
			'baseline'   => 0,
			'change_pct' => 0,
			'zscore'     => 0,
			'severity'   => min( wp_list_pluck( $items, 'cover' ) ) < 2 ? 'high' : 'medium',
			'direction'  => 'drop',
		);
		return self::store(
			$rule,
			$day,
			$hit,
			array(
				'rule'   => $rule['name'],
				'format' => 'number',
				'items'  => array_slice( $items, 0, 10 ),
			)
		);
	}

	/**
	 * Whether an alert exists.
	 *
	 * @param string $rule_id Rule.
	 * @param string $day     Day.
	 * @return bool
	 */
	public static function exists( $rule_id, $day ) {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE rule_id = %s AND day = %s", $rule_id, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Inserts an alert, notifies, fires the action.
	 *
	 * @param array  $rule   Rule.
	 * @param string $day    Day.
	 * @param array  $hit    Hit.
	 * @param array  $detail Detail.
	 * @return array|null
	 */
	private static function store( array $rule, $day, array $hit, array $detail ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'rule_id'    => $rule['id'],
				'metric'     => 'stock' === $rule['type'] ? 'stock' : $rule['metric'],
				'day'        => $day,
				'value'      => $hit['value'],
				'baseline'   => $hit['baseline'],
				'change_pct' => $hit['change_pct'],
				'zscore'     => $hit['zscore'],
				'severity'   => $hit['severity'],
				'status'     => 'new',
				'detail'     => wp_json_encode( $detail ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		if ( ! $ok ) {
			return null;
		}
		$alert = self::get( (int) $wpdb->insert_id );
		/**
		 * Fires when an anomaly alert is created.
		 *
		 * @param array $alert Alert.
		 * @param array $rule  Rule.
		 */
		do_action( 'dev3si_alert_created', $alert, $rule );
		if ( $rule['notify'] ) {
			Mailer::alert( $alert, $rule );
		}
		return $alert;
	}

	/**
	 * Row → API shape.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function shape( $row ) {
		if ( ! $row ) {
			return null;
		}
		$detail = json_decode( (string) $row['detail'], true );
		$detail = is_array( $detail ) ? $detail : array();
		return array(
			'id'         => (int) $row['id'],
			'rule_id'    => (string) $row['rule_id'],
			'rule'       => isset( $detail['rule'] ) ? $detail['rule'] : (string) $row['rule_id'],
			'metric'     => (string) $row['metric'],
			'label'      => 'stock' === $row['metric'] ? __( 'Stock', 'dev3-ai-store-insights-for-woocommerce' ) : Library::metric_label( $row['metric'] ),
			'format'     => isset( $detail['format'] ) ? $detail['format'] : 'number',
			'day'        => (string) $row['day'],
			'value'      => (float) $row['value'],
			'baseline'   => (float) $row['baseline'],
			'change_pct' => (float) $row['change_pct'],
			'zscore'     => (float) $row['zscore'],
			'severity'   => (string) $row['severity'],
			'status'     => (string) $row['status'],
			'detail'     => $detail,
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * One alert.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Paged list.
	 *
	 * @param string $status new|ack|all.
	 * @param int    $page   Page.
	 * @param int    $per    Per page.
	 * @return array { items, total, counts }
	 */
	public static function query( $status = 'all', $page = 1, $per = 20 ) {
		global $wpdb;
		$t      = self::table();
		$offset = max( 0, ( $page - 1 ) * $per );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( in_array( $status, array( 'new', 'ack' ), true ) ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = %s ORDER BY day DESC, id DESC LIMIT %d OFFSET %d", $status, $per, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $status ) );
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY day DESC, id DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		}
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$t} GROUP BY status", ARRAY_A );
		// phpcs:enable
		return array(
			'items'  => array_values( array_filter( array_map( array( self::class, 'shape' ), (array) $rows ) ) ),
			'total'  => $total,
			'counts' => wp_list_pluck( (array) $counts, 'n', 'status' ),
		);
	}

	/**
	 * Sets status.
	 *
	 * @param int    $id     Id.
	 * @param string $status new|ack.
	 * @return array|null
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'status' => 'ack' === $status ? 'ack' : 'new' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Runs every enabled rule for the last N days (ending yesterday).
	 *
	 * @param int $days Days.
	 * @return array Created alerts.
	 */
	public static function check( $days = 1 ) {
		if ( ! Store::ready() ) {
			return array();
		}
		$created   = array();
		$yesterday = Period::now()->modify( '-1 day' );
		for ( $i = max( 1, min( 60, (int) $days ) ) - 1; $i >= 0; $i-- ) {
			$day = $yesterday->modify( "-{$i} days" )->format( 'Y-m-d' );
			foreach ( self::rules() as $rule ) {
				if ( ! $rule['enabled'] || ( 'stock' === $rule['type'] && $i > 0 ) ) {
					continue;
				}
				$alert = self::detect( $rule, $day );
				if ( $alert ) {
					$created[] = $alert;
				}
			}
		}
		update_option( self::CHECKED, $yesterday->format( 'Y-m-d' ), false );
		return $created;
	}

	/**
	 * Hourly tick: checks yesterday once per day.
	 */
	public static function tick() {
		if ( ! Settings::get( 'alerts_enabled', true ) ) {
			return;
		}
		$yesterday = Period::now()->modify( '-1 day' )->format( 'Y-m-d' );
		if ( get_option( self::CHECKED ) !== $yesterday ) {
			self::check( 1 );
		}
	}

	/**
	 * Explanation of an alert (AI when available, else the movers as sentences). Saved into the alert.
	 *
	 * @param int  $id    Alert id.
	 * @param bool $force Re-generate.
	 * @return array|\WP_Error
	 */
	public static function explain( $id, $force = false ) {
		global $wpdb;
		$alert = self::get( $id );
		if ( ! $alert ) {
			return Api_Error::make( 'dev3si_not_found', __( 'Alert not found.', 'dev3-ai-store-insights-for-woocommerce' ), '', 404 );
		}
		if ( ! $force && ! empty( $alert['detail']['explanation'] ) && 'ai' === ( $alert['detail']['explanation']['source'] ?? '' ) ) {
			return $alert;
		}
		$explanation = self::template( $alert );
		if ( Ai::enabled( 'admin' ) ) {
			$facts  = array(
				'rule'     => $alert['rule'],
				'metric'   => $alert['label'],
				'day'      => $alert['day'],
				'value'    => $alert['value'],
				'baseline' => $alert['baseline'] . ' (28-day daily average)',
				'change'   => $alert['change_pct'] . '%',
				'currency' => Store::currency()['code'],
				'movers'   => $alert['detail']['movers'] ?? array(),
				'items'    => $alert['detail']['items'] ?? array(),
			);
			$result = Ai::run(
				'alert_explain',
				array(
					'system'      => Prompts::alert_system(),
					'prompt'      => wp_json_encode( $facts ),
					'schema'      => Prompts::explain_schema(),
					'temperature' => 0.3,
					'max_tokens'  => 1200,
					'profile'     => 'fast',
				)
			);
			if ( ! is_wp_error( $result ) ) {
				$explanation = Explainer::clean( $result['data'] ) + array(
					'source' => 'ai',
					'model'  => $result['model'],
				);
			} else {
				$explanation['error'] = Api_Error::to_response( $result )->get_data();
			}
		}
		$detail                = $alert['detail'];
		$detail['explanation'] = $explanation;
		$wpdb->update( self::table(), array( 'detail' => wp_json_encode( $detail ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Template explanation from the movers.
	 *
	 * @param array $alert Alert.
	 * @return array
	 */
	public static function template( array $alert ) {
		$fmt = $alert['format'];
		if ( 'stock' === $alert['metric'] ) {
			$names = wp_list_pluck( $alert['detail']['items'] ?? array(), 'name' );
			return array(
				/* translators: %d: number of products */
				'headline'   => sprintf( _n( '%d best seller may run out soon.', '%d best sellers may run out soon.', count( $names ), 'dev3-ai-store-insights-for-woocommerce' ), count( $names ) ),
				'summary'    => __( 'At the last 30 days\' sales rate these products have less than the configured days of stock left. Reorder or adjust their promotion.', 'dev3-ai-store-insights-for-woocommerce' ),
				'highlights' => array_slice( $names, 0, 4 ),
				'follow_ups' => array( __( 'Which products will run out of stock first?', 'dev3-ai-store-insights-for-woocommerce' ) ),
				'source'     => 'template',
			);
		}
		$dir        = $alert['change_pct'] >= 0 ? __( 'above', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'below', 'dev3-ai-store-insights-for-woocommerce' );
		$highlights = array();
		foreach ( array( 'products', 'locations' ) as $kind ) {
			foreach ( array_slice( $alert['detail']['movers'][ $kind ] ?? array(), 0, 2 ) as $m ) {
				/* translators: 1: name, 2: day value, 3: usual value */
				$highlights[] = sprintf( __( '%1$s: %2$s (usually %3$s a day)', 'dev3-ai-store-insights-for-woocommerce' ), $m['name'], Store::format( $m['day'], 'money' ), Store::format( $m['baseline'], 'money' ) );
			}
		}
		return array(
			/* translators: 1: metric, 2: value, 3: day */
			'headline'   => sprintf( __( '%1$s was %2$s on %3$s.', 'dev3-ai-store-insights-for-woocommerce' ), $alert['label'], Store::format( $alert['value'], $fmt ), wp_date( get_option( 'date_format', 'j M Y' ), strtotime( $alert['day'] . ' 12:00:00' ) ) ),
			/* translators: 1: percent, 2: above/below, 3: baseline */
			'summary'    => sprintf( __( 'That is %1$s %2$s the 28-day daily average of %3$s. The products and countries below moved the most.', 'dev3-ai-store-insights-for-woocommerce' ), Store::format( abs( $alert['change_pct'] ), 'percent' ), $dir, Store::format( $alert['baseline'], $fmt ) ),
			'highlights' => $highlights,
			'follow_ups' => array( __( 'Net sales by day for the last 30 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Best sellers last 7 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'source'     => 'template',
		);
	}

	/**
	 * DEV3 AI rule suggestions (draft; nothing saved).
	 *
	 * @return array|\WP_Error { rules[], model, tokens }
	 */
	public static function advice() {
		$day   = Period::now()->modify( '-1 day' )->format( 'Y-m-d' );
		$facts = array();
		foreach ( array( 'net_sales', 'orders', 'aov', 'refunds' ) as $metric ) {
			$values = array();
			foreach ( array( 0, 29, 58 ) as $back ) {
				$values = array_merge( array_values( self::daily( $metric, gmdate( 'Y-m-d', strtotime( $day . " -{$back} days" ) ) ) ), $values );
			}
			$facts[ $metric ] = array(
				'daily_last_90' => array_slice( $values, -87 ),
			);
		}
		$result = Ai::run(
			'alert_advice',
			array(
				'system'      => Prompts::advice_system(),
				'prompt'      => 'Currency: ' . Store::currency()['code'] . "\n" . wp_json_encode( $facts ),
				'schema'      => Prompts::advice_schema(),
				'temperature' => 0.2,
				'max_tokens'  => 2000,
				'profile'     => 'heavy',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rules = array();
		foreach ( (array) ( $result['data']['rules'] ?? array() ) as $raw ) {
			$raw['type'] = 'metric';
			unset( $raw['id'] );
			$rule = self::clean_rule( $raw );
			if ( $rule ) {
				$rule['reason'] = mb_substr( sanitize_text_field( (string) ( $raw['reason'] ?? '' ) ), 0, 200 );
				$rules[]        = $rule;
			}
		}
		return array(
			'rules'  => $rules,
			'model'  => $result['model'],
			'tokens' => $result['usage']['total'],
		);
	}
}
