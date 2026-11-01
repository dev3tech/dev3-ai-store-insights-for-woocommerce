<?php
/**
 * Plain-English questions → a library call, without AI. Covers the common phrasings; DEV3 AI handles the rest.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Ask;

use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Keyword parser.
 */
class Rule_Parser {

	const MONTHS = array( 'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december' );

	/**
	 * Parses a question.
	 *
	 * @param string $question Question.
	 * @return array { fn, args, confidence (0-1), matched[] }
	 */
	public static function parse( $question ) {
		$q       = ' ' . preg_replace( '/\s+/', ' ', mb_strtolower( wp_strip_all_tags( (string) $question ) ) ) . ' ';
		$q       = str_replace( array( '?', '!', ',', '.', '"', "'s " ), array( ' ', ' ', ' ', ' ', ' ', ' ' ), $q );
		$args    = array();
		$matched = array();
		$score   = 0.0;

		$period = self::period( $q );
		if ( $period ) {
			$args    = array_merge( $args, $period );
			$score  += 0.3;
			$matched = array_merge( $matched, array( 'period' ) );
		}

		$fn = self::detect( $q, $args );
		if ( $fn ) {
			$score    += 0.5;
			$matched[] = 'function';
		} else {
			$fn = self::has( $q, array( 'sales', 'revenue', 'orders', 'sold', 'turnover', 'income' ) ) ? 'sales_trend' : 'store_summary';
			if ( 'sales_trend' === $fn && self::has( $q, array( 'how much', 'total', 'how many' ) ) ) {
				$fn = 'store_summary';
			}
		}

		if ( preg_match( '/\btop (\d{1,2})\b/', $q, $m ) || preg_match( '/\b(\d{1,2}) (best|top|most)\b/', $q, $m ) ) {
			$args['limit'] = (int) $m[1];
		}

		$location = self::location( $q );
		if ( $location && ! in_array( $fn, array( 'sales_by_location', 'cohorts', 'stock_status', 'top_coupons' ), true ) ) {
			$args['location'] = $location;
			$score           += 0.2;
			$matched[]        = 'location';
		}
		$category = self::category( $q );
		if ( $category && ! in_array( $fn, array( 'top_categories', 'cohorts', 'top_coupons' ), true ) ) {
			$args['category'] = $category;
			$score           += 0.1;
			$matched[]        = 'category';
		}
		if ( ! in_array( $fn, array( 'customer_segments', 'cohorts', 'stock_status', 'top_coupons', 'top_customers' ), true ) ) {
			if ( self::has( $q, array( ' new customer', 'first-time', 'first time' ) ) ) {
				$args['customer_type'] = 'new';
			} elseif ( self::has( $q, array( 'returning', 'repeat customer', 'existing customer' ) ) ) {
				$args['customer_type'] = 'returning';
			}
			if ( self::has( $q, array( ' guest' ) ) ) {
				$args['customer_kind'] = 'guest';
			} elseif ( self::has( $q, array( 'registered', 'members', 'account holders' ) ) ) {
				$args['customer_kind'] = 'registered';
			}
		}
		if ( ! isset( $args['compare'] ) && self::has( $q, array( 'last year', 'year over year', 'yoy', 'vs last year', 'compared to last year' ) ) && isset( $args['period'] ) && 'last_year' !== $args['period'] ) {
			$args['compare'] = 'previous_year';
		}

		return array(
			'fn'         => $fn,
			'args'       => $args,
			'confidence' => min( 1.0, round( $score, 2 ) ),
			'matched'    => $matched,
		);
	}

	/**
	 * Whether any needle occurs.
	 *
	 * @param string   $q       Haystack.
	 * @param string[] $needles Needles.
	 * @return bool
	 */
	private static function has( $q, array $needles ) {
		foreach ( $needles as $n ) {
			if ( false !== strpos( $q, $n ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Function + function args from keywords.
	 *
	 * @param string $q    Question.
	 * @param array  $args Args (filled by reference).
	 * @return string|null
	 */
	private static function detect( $q, array &$args ) {
		if ( self::has( $q, array( 'cohort', 'retention', 'retain', 'come back', 'came back', 'repeat rate', 'repeat purchase rate' ) ) ) {
			$args['metric'] = self::has( $q, array( 'revenue', 'sales', 'spend' ) ) ? 'revenue' : 'retention';
			if ( preg_match( '/(\d{1,2}) months?/', $q, $m ) ) {
				$args['months'] = (int) $m[1];
			}
			unset( $args['period'], $args['from'], $args['to'] );
			return 'cohorts';
		}
		if ( self::has( $q, array( 'stock', 'inventory', 'run out', 'running out', 'restock', 'days of cover', 'sell out', 'selling out' ) ) ) {
			if ( self::has( $q, array( 'run out', 'running out', 'runs out', 'days of cover', 'days left', 'days of stock', 'sell out', 'selling out', 'restock', 'how long' ) ) ) {
				$args['view'] = 'cover';
			} elseif ( self::has( $q, array( 'out of stock', 'sold out', 'unavailable' ) ) ) {
				$args['view'] = 'out';
			} elseif ( self::has( $q, array( 'overstock', 'too much', 'slow', 'not selling', 'dead stock' ) ) ) {
				$args['view'] = 'overstock';
			} else {
				$args['view'] = 'low';
			}
			unset( $args['period'], $args['from'], $args['to'] );
			return 'stock_status';
		}
		if ( self::has( $q, array( 'refund', 'returned', 'returns', 'money back' ) ) ) {
			$args['view'] = self::has( $q, array( 'product', 'item', 'which', 'what ' ) ) ? 'products' : 'trend';
			return 'refunds';
		}
		if ( self::has( $q, array( 'coupon', 'discount code', 'promo code', 'voucher' ) ) ) {
			$args['metric'] = self::has( $q, array( 'discount given', 'how much discount', 'cost', 'amount' ) ) ? 'discount' : 'orders';
			return 'top_coupons';
		}
		if ( preg_match( '/\b(top|best|biggest|largest|most valuable|vip|loyal|highest spending|big)\b.{0,20}\b(customers?|buyers?|clients?|spenders?)\b/', $q ) || self::has( $q, array( 'who spent', 'who buys the most', 'who bought the most' ) ) ) {
			$args['metric'] = self::has( $q, array( 'orders', 'often', 'frequent' ) ) ? 'orders' : ( self::has( $q, array( 'average', 'aov' ) ) ? 'aov' : 'revenue' );
			return 'top_customers';
		}
		if ( self::has( $q, array( 'new vs returning', 'new versus returning', 'new or returning', 'new and returning', 'returning vs new', 'returning customers', 'repeat customers', 'first-time vs', 'new customers vs' ) ) ) {
			$args['segment'] = 'new_vs_returning';
			$args['metric']  = self::metric_word( $q, array( 'net_sales', 'orders', 'aov', 'customers' ) );
			return 'customer_segments';
		}
		if ( self::has( $q, array( 'guest', 'registered', 'accounts vs', 'with an account' ) ) && self::has( $q, array( ' vs', 'versus', 'compare', 'split', 'share', 'or ' ) ) ) {
			$args['segment'] = 'registered_vs_guest';
			$args['metric']  = self::metric_word( $q, array( 'net_sales', 'orders', 'aov', 'customers' ) );
			return 'customer_segments';
		}
		if ( self::has( $q, array( 'categor' ) ) ) {
			$args['metric'] = self::has( $q, array( 'units', 'quantity', 'items', 'how many' ) ) ? 'quantity' : ( self::has( $q, array( 'orders' ) ) ? 'orders' : 'revenue' );
			return 'top_categories';
		}
		if ( preg_match( '/\b(by|per|which|top|best|each) (countr|cit|region|state|province|emirate|location|market)/', $q ) || self::has( $q, array( 'where do', 'where are', 'come from', 'geograph', 'countries', 'cities', 'regions' ) ) ) {
			$args['level']  = self::has( $q, array( 'cit' ) ) ? 'city' : ( self::has( $q, array( 'region', 'state', 'province', 'emirate' ) ) ? 'region' : 'country' );
			$args['metric'] = self::metric_word( $q, array( 'net_sales', 'orders', 'aov', 'items_sold', 'customers' ) );
			return 'sales_by_location';
		}
		if ( preg_match( '/\b(top|best|most popular)( \d{1,2})? (products?|items?|sellers?)\b/', $q ) || self::has( $q, array( 'best sell', 'best-sell', 'bestsell', 'top sell', 'top product', 'best product', 'most popular', 'sold most', 'sells most', 'sell the most', 'sold the most', 'top items', 'top-selling', 'popular product', 'what sells', 'what sold', 'products sold', 'best performing product', 'top performing product' ) ) ) {
			$args['metric'] = self::has( $q, array( 'units', 'quantity', 'how many', 'most sold', 'sold most', 'sold the most', 'volume' ) ) ? 'quantity' : ( self::has( $q, array( ' orders' ) ) ? 'orders' : 'revenue' );
			return 'top_products';
		}
		$interval = null;
		foreach ( array(
			'day'     => array( 'daily', 'by day', 'per day', 'each day', 'day by day' ),
			'week'    => array( 'weekly', 'by week', 'per week', 'each week' ),
			'month'   => array( 'monthly', 'by month', 'per month', 'each month', 'month by month' ),
			'quarter' => array( 'quarterly', 'by quarter', 'per quarter' ),
			'year'    => array( 'yearly', 'by year', 'per year', 'annual' ),
		) as $key => $words ) {
			if ( self::has( $q, $words ) ) {
				$interval = $key;
				break;
			}
		}
		if ( $interval || self::has( $q, array( 'trend', 'over time', 'growth', 'growing', 'chart', 'graph', 'average order', 'aov', 'basket size' ) ) ) {
			if ( $interval ) {
				$args['interval'] = $interval;
			}
			$args['metric'] = self::metric_word( $q, array( 'net_sales', 'gross_sales', 'orders', 'items_sold', 'aov', 'refunds', 'customers' ) );
			return 'sales_trend';
		}
		if ( self::has( $q, array( 'summary', 'overview', 'how are we', 'how did we', 'how did the store', 'how is the store', 'how are sales', 'how were sales', 'how is business', 'kpi', 'performance', 'doing', 'dashboard', 'snapshot' ) ) ) {
			return 'store_summary';
		}
		return null;
	}

	/**
	 * Metric from words, limited to the allowed ids.
	 *
	 * @param string   $q       Question.
	 * @param string[] $allowed Allowed ids (first is the default).
	 * @return string
	 */
	private static function metric_word( $q, array $allowed ) {
		$map = array(
			'aov'         => array( 'average order', 'aov', 'basket size', 'order value' ),
			'refunds'     => array( 'refund' ),
			'customers'   => array( 'customers', 'buyers', 'shoppers' ),
			'items_sold'  => array( 'units', 'items sold', 'quantity', 'items' ),
			'orders'      => array( 'orders', 'order count', 'number of orders', 'transactions' ),
			'gross_sales' => array( 'gross' ),
		);
		foreach ( $map as $metric => $words ) {
			if ( in_array( $metric, $allowed, true ) && self::has( $q, $words ) ) {
				return $metric;
			}
		}
		return $allowed[0];
	}

	/**
	 * Period words → period args.
	 *
	 * @param string $q Question.
	 * @return array
	 */
	public static function period( $q ) {
		$now = Period::now();
		if ( preg_match( '/\b(last|past|previous) (\d{1,3}) (day|week|month)s?\b/', $q, $m ) ) {
			$n    = max( 1, (int) $m[2] );
			$days = 'day' === $m[3] ? $n : ( 'week' === $m[3] ? $n * 7 : 0 );
			$map  = array(
				7  => 'last_7_days',
				30 => 'last_30_days',
				90 => 'last_90_days',
			);
			if ( $days && isset( $map[ $days ] ) ) {
				return array( 'period' => $map[ $days ] );
			}
			if ( 'month' === $m[3] && 12 === $n ) {
				return array( 'period' => 'last_12_months' );
			}
			$from = $days ? $now->modify( '-' . ( $days - 1 ) . ' days' ) : $now->modify( '-' . $n . ' months' )->modify( '+1 day' );
			return array(
				'period' => 'custom',
				'from'   => $from->format( 'Y-m-d' ),
				'to'     => $now->format( 'Y-m-d' ),
			);
		}
		$phrases = array(
			'last_12_months' => array( 'last 12 months', 'past 12 months', 'past year', 'last twelve months' ),
			'yesterday'      => array( 'yesterday' ),
			'today'          => array( 'today', 'so far today' ),
			'last_week'      => array( 'last week', 'previous week', 'past week' ),
			'this_week'      => array( 'this week', 'week to date', 'wtd' ),
			'last_month'     => array( 'last month', 'previous month', 'past month' ),
			'this_month'     => array( 'this month', 'month to date', 'mtd' ),
			'last_quarter'   => array( 'last quarter', 'previous quarter' ),
			'this_quarter'   => array( 'this quarter', 'quarter to date', 'qtd' ),
			'last_year'      => array( 'last year', 'previous year' ),
			'this_year'      => array( 'this year', 'year to date', 'ytd' ),
			'all_time'       => array( 'all time', 'all-time', ' ever ', 'since we started', 'since launch' ),
		);
		foreach ( $phrases as $preset => $words ) {
			if ( self::has( $q, $words ) ) {
				return array( 'period' => $preset );
			}
		}
		foreach ( self::MONTHS as $i => $month ) {
			if ( preg_match( '/\b(' . $month . '|' . substr( $month, 0, 3 ) . ')\b(?: (\d{4}))?/', $q, $m ) ) {
				if ( 'may' === $month && empty( $m[2] ) && ! preg_match( '/\b(in|during|for|of) may\b/', $q ) ) {
					continue;
				}
				$year = ! empty( $m[2] ) ? (int) $m[2] : (int) $now->format( 'Y' );
				if ( empty( $m[2] ) && $i + 1 > (int) $now->format( 'n' ) ) {
					--$year;
				}
				$start = sprintf( '%04d-%02d-01', $year, $i + 1 );
				return array(
					'period' => 'custom',
					'from'   => $start,
					'to'     => gmdate( 'Y-m-t', strtotime( $start . ' 12:00:00' ) ),
				);
			}
		}
		if ( preg_match( '/\b(in|during|for) (20\d{2})\b/', $q, $m ) || preg_match( '/\b(20\d{2})\b/', $q, $m ) ) {
			$year = (int) end( $m );
			return array(
				'period' => 'custom',
				'from'   => $year . '-01-01',
				'to'     => $year . '-12-31',
			);
		}
		return array();
	}

	/**
	 * Known location named in the question.
	 *
	 * @param string $q Question.
	 * @return string|null
	 */
	public static function location( $q ) {
		$known = Store::known_locations();
		$names = array();
		foreach ( $known['cities'] as $city ) {
			$names[] = $city;
		}
		foreach ( $known['states'] as $pair ) {
			$name = Store::state_name( $pair['country'], $pair['state'] );
			if ( $name !== $pair['state'] ) {
				$names[] = $name;
			}
		}
		foreach ( Store::countries() as $name ) {
			$names[] = preg_replace( '/\s*\(.*\)$/', '', html_entity_decode( $name ) );
		}
		$names = array_merge( $names, array( 'UAE', 'USA', 'UK' ) );
		usort(
			$names,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		foreach ( $names as $name ) {
			$needle = mb_strtolower( trim( $name ) );
			if ( mb_strlen( $needle ) < 2 || in_array( $needle, array( 'us', 'in', 'me', 'it' ), true ) ) {
				continue;
			}
			if ( preg_match( '/(?<![\p{L}])' . preg_quote( $needle, '/' ) . '(?![\p{L}])/u', $q ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Category named in the question.
	 *
	 * @param string $q Question.
	 * @return string|null
	 */
	public static function category( $q ) {
		$names = Store::category_names( 100 );
		usort(
			$names,
			static function ( $a, $b ) {
				return mb_strlen( $b ) <=> mb_strlen( $a );
			}
		);
		foreach ( $names as $name ) {
			$needle = mb_strtolower( $name );
			$single = preg_replace( '/s$/', '', $needle );
			if ( mb_strlen( $single ) < 3 ) {
				continue;
			}
			if ( preg_match( '/(?<![\p{L}])' . preg_quote( $single, '/' ) . '(s|es)?(?![\p{L}])/u', $q ) ) {
				return $name;
			}
		}
		return null;
	}
}
