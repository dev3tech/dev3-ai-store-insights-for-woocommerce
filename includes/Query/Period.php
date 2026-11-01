<?php
/**
 * Date ranges in the site timezone: presets, custom ranges, comparisons and interval buckets.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves period presets into [from, to] local datetimes.
 */
class Period {

	const PRESETS = array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'this_week', 'last_week', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'last_year', 'last_12_months', 'all_time', 'custom' );

	const INTERVALS = array( 'day', 'week', 'month', 'quarter', 'year' );

	/**
	 * Fixed "now" for tests (local time, Y-m-d H:i:s).
	 *
	 * @var string|null
	 */
	public static $now = null;

	/**
	 * Current local time.
	 *
	 * @return \DateTimeImmutable
	 */
	public static function now() {
		$tz = wp_timezone();
		return self::$now ? new \DateTimeImmutable( self::$now, $tz ) : new \DateTimeImmutable( 'now', $tz );
	}

	/**
	 * Human labels.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'today'          => __( 'Today', 'dev3-ai-store-insights-for-woocommerce' ),
			'yesterday'      => __( 'Yesterday', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_7_days'    => __( 'Last 7 days', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_30_days'   => __( 'Last 30 days', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_90_days'   => __( 'Last 90 days', 'dev3-ai-store-insights-for-woocommerce' ),
			'this_week'      => __( 'This week', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_week'      => __( 'Last week', 'dev3-ai-store-insights-for-woocommerce' ),
			'this_month'     => __( 'This month', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_month'     => __( 'Last month', 'dev3-ai-store-insights-for-woocommerce' ),
			'this_quarter'   => __( 'This quarter', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_quarter'   => __( 'Last quarter', 'dev3-ai-store-insights-for-woocommerce' ),
			'this_year'      => __( 'This year', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_year'      => __( 'Last year', 'dev3-ai-store-insights-for-woocommerce' ),
			'last_12_months' => __( 'Last 12 months', 'dev3-ai-store-insights-for-woocommerce' ),
			'all_time'       => __( 'All time', 'dev3-ai-store-insights-for-woocommerce' ),
			'custom'         => __( 'Custom range', 'dev3-ai-store-insights-for-woocommerce' ),
		);
	}

	/**
	 * Validates a Y-m-d date string.
	 *
	 * @param mixed $value Value.
	 * @return string '' when invalid.
	 */
	public static function clean_date( $value ) {
		$value = substr( trim( (string) $value ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		$parts = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $parts[1], $parts[2], $parts[0] ) && $parts[0] >= 2000 && $parts[0] <= 2100 ? $value : '';
	}

	/**
	 * Resolves a preset (or a custom range) to local datetimes.
	 *
	 * @param string $preset Preset id.
	 * @param string $from   Custom from (Y-m-d).
	 * @param string $to     Custom to (Y-m-d).
	 * @return array { preset, from, to, label, days }
	 */
	public static function resolve( $preset, $from = '', $to = '' ) {
		$preset = in_array( $preset, self::PRESETS, true ) ? $preset : 'last_30_days';
		$now    = self::now();
		$today  = $now->setTime( 0, 0, 0 );
		$sow    = (int) get_option( 'start_of_week', 1 );
		$dow    = (int) $today->format( 'w' );
		$week   = $today->modify( '-' . ( ( $dow - $sow + 7 ) % 7 ) . ' days' );
		$qstart = $today->setDate( (int) $today->format( 'Y' ), (int) ( floor( ( (int) $today->format( 'n' ) - 1 ) / 3 ) * 3 + 1 ), 1 );
		$end    = $today->setTime( 23, 59, 59 );

		switch ( $preset ) {
			case 'today':
				$range = array( $today, $end );
				break;
			case 'yesterday':
				$range = array( $today->modify( '-1 day' ), $end->modify( '-1 day' ) );
				break;
			case 'last_7_days':
				$range = array( $today->modify( '-6 days' ), $end );
				break;
			case 'last_90_days':
				$range = array( $today->modify( '-89 days' ), $end );
				break;
			case 'this_week':
				$range = array( $week, $end );
				break;
			case 'last_week':
				$range = array( $week->modify( '-7 days' ), $week->modify( '-1 second' ) );
				break;
			case 'this_month':
				$range = array( $today->modify( 'first day of this month' ), $end );
				break;
			case 'last_month':
				$range = array( $today->modify( 'first day of last month' ), $today->modify( 'first day of this month' )->modify( '-1 second' ) );
				break;
			case 'this_quarter':
				$range = array( $qstart, $end );
				break;
			case 'last_quarter':
				$range = array( $qstart->modify( '-3 months' ), $qstart->modify( '-1 second' ) );
				break;
			case 'this_year':
				$range = array( $today->setDate( (int) $today->format( 'Y' ), 1, 1 ), $end );
				break;
			case 'last_year':
				$y     = (int) $today->format( 'Y' ) - 1;
				$range = array( $today->setDate( $y, 1, 1 ), $today->setDate( $y, 12, 31 )->setTime( 23, 59, 59 ) );
				break;
			case 'last_12_months':
				$range = array( $today->modify( 'first day of this month' )->modify( '-11 months' ), $end );
				break;
			case 'all_time':
				$range = array( new \DateTimeImmutable( '2000-01-01 00:00:00', wp_timezone() ), $end );
				break;
			case 'custom':
				$f = self::clean_date( $from );
				$t = self::clean_date( $to );
				if ( '' === $f || '' === $t ) {
					return self::resolve( 'last_30_days' );
				}
				if ( $f > $t ) {
					list( $f, $t ) = array( $t, $f );
				}
				$range = array( new \DateTimeImmutable( $f . ' 00:00:00', wp_timezone() ), new \DateTimeImmutable( $t . ' 23:59:59', wp_timezone() ) );
				break;
			default:
				$range = array( $today->modify( '-29 days' ), $end );
		}
		return self::make( $preset, $range[0], $range[1] );
	}

	/**
	 * Builds the period array.
	 *
	 * @param string             $preset Preset.
	 * @param \DateTimeImmutable $from   From.
	 * @param \DateTimeImmutable $to     To.
	 * @param string             $label  Label override.
	 * @return array
	 */
	private static function make( $preset, \DateTimeImmutable $from, \DateTimeImmutable $to, $label = '' ) {
		$labels = self::labels();
		if ( '' === $label ) {
			$label = 'custom' === $preset ? self::range_label( $from, $to ) : $labels[ $preset ];
		}
		return array(
			'preset' => $preset,
			'from'   => $from->format( 'Y-m-d H:i:s' ),
			'to'     => $to->format( 'Y-m-d H:i:s' ),
			'label'  => $label,
			'range'  => self::range_label( $from, $to ),
			'days'   => (int) floor( ( $to->getTimestamp() - $from->getTimestamp() ) / DAY_IN_SECONDS ) + 1,
		);
	}

	/**
	 * "1 Mar 2026 – 31 Mar 2026".
	 *
	 * @param \DateTimeImmutable $from From.
	 * @param \DateTimeImmutable $to   To.
	 * @return string
	 */
	private static function range_label( \DateTimeImmutable $from, \DateTimeImmutable $to ) {
		$a = wp_date( 'j M Y', $from->getTimestamp(), wp_timezone() );
		$b = wp_date( 'j M Y', $to->getTimestamp(), wp_timezone() );
		return $a === $b ? $a : $a . ' – ' . $b;
	}

	/**
	 * The comparison period.
	 *
	 * @param array  $period Resolved period.
	 * @param string $mode   previous_period|previous_year|none.
	 * @return array|null
	 */
	public static function compare( array $period, $mode ) {
		if ( 'none' === $mode || 'all_time' === $period['preset'] ) {
			return null;
		}
		$tz   = wp_timezone();
		$from = new \DateTimeImmutable( $period['from'], $tz );
		$to   = new \DateTimeImmutable( $period['to'], $tz );
		if ( 'previous_year' === $mode ) {
			return self::make( 'custom', $from->modify( '-1 year' ), $to->modify( '-1 year' ), __( 'Same period last year', 'dev3-ai-store-insights-for-woocommerce' ) );
		}
		$months = array(
			'this_month'     => 1,
			'last_month'     => 1,
			'this_quarter'   => 3,
			'last_quarter'   => 3,
			'this_year'      => 12,
			'last_year'      => 12,
			'last_12_months' => 12,
		);
		if ( isset( $months[ $period['preset'] ] ) ) {
			$n     = $months[ $period['preset'] ];
			$pfrom = $from->modify( "-{$n} months" );
			$pto   = in_array( $period['preset'], array( 'last_month', 'last_quarter', 'last_year' ), true )
				? $from->modify( '-1 second' )
				: $to->modify( "-{$n} months" );
			return self::make( 'custom', $pfrom, $pto, __( 'Previous period', 'dev3-ai-store-insights-for-woocommerce' ) );
		}
		$seconds = $to->getTimestamp() - $from->getTimestamp() + 1;
		return self::make( 'custom', $from->modify( '-' . $seconds . ' seconds' ), $from->modify( '-1 second' ), __( 'Previous period', 'dev3-ai-store-insights-for-woocommerce' ) );
	}

	/**
	 * Picks an interval for a period.
	 *
	 * @param array  $period   Period.
	 * @param string $interval Requested interval or 'auto'.
	 * @return string
	 */
	public static function interval( array $period, $interval ) {
		$days = (int) $period['days'];
		if ( in_array( $interval, self::INTERVALS, true ) ) {
			// Cap the number of buckets so a chart stays readable.
			$limits = array(
				'day'  => 400,
				'week' => 260,
			);
			if ( ! isset( $limits[ $interval ] ) || $days <= $limits[ $interval ] * ( 'week' === $interval ? 7 : 1 ) ) {
				return $interval;
			}
		}
		if ( $days <= 45 ) {
			return 'day';
		}
		if ( $days <= 190 ) {
			return 'week';
		}
		if ( $days <= 1100 ) {
			return 'month';
		}
		return 'quarter';
	}

	/**
	 * SQL expression turning a datetime column into the bucket key (Y-m-d of the bucket start).
	 * No "%d" in here: the expression goes through $wpdb->prepare().
	 *
	 * @param string $column   Column (trusted).
	 * @param string $interval Interval.
	 * @return string
	 */
	public static function bucket_sql( $column, $interval ) {
		$sow = (int) get_option( 'start_of_week', 1 );
		switch ( $interval ) {
			case 'week':
				return "CAST(DATE_SUB(DATE({$column}), INTERVAL MOD(DAYOFWEEK({$column}) - 1 - {$sow} + 7, 7) DAY) AS CHAR)";
			case 'month':
				return "DATE_FORMAT({$column}, '%Y-%m-01')";
			case 'quarter':
				return "CONCAT(YEAR({$column}), '-', LPAD((QUARTER({$column}) - 1) * 3 + 1, 2, '0'), '-01')";
			case 'year':
				return "CONCAT(YEAR({$column}), '-01-01')";
			default:
				return "CAST(DATE({$column}) AS CHAR)";
		}
	}

	/**
	 * Every bucket key between two datetimes, with labels.
	 *
	 * @param string $from     Local datetime.
	 * @param string $to       Local datetime.
	 * @param string $interval Interval.
	 * @return array [ key => label ]
	 */
	public static function buckets( $from, $to, $interval ) {
		$tz     = wp_timezone();
		$cursor = new \DateTimeImmutable( substr( $from, 0, 10 ), $tz );
		$end    = new \DateTimeImmutable( substr( $to, 0, 10 ), $tz );
		$sow    = (int) get_option( 'start_of_week', 1 );
		switch ( $interval ) {
			case 'week':
				$cursor = $cursor->modify( '-' . ( ( (int) $cursor->format( 'w' ) - $sow + 7 ) % 7 ) . ' days' );
				$step   = '+1 week';
				$format = 'j M';
				break;
			case 'month':
				$cursor = $cursor->modify( 'first day of this month' );
				$step   = '+1 month';
				$format = 'M Y';
				break;
			case 'quarter':
				$cursor = $cursor->setDate( (int) $cursor->format( 'Y' ), (int) ( floor( ( (int) $cursor->format( 'n' ) - 1 ) / 3 ) * 3 + 1 ), 1 );
				$step   = '+3 months';
				$format = 'quarter';
				break;
			case 'year':
				$cursor = $cursor->setDate( (int) $cursor->format( 'Y' ), 1, 1 );
				$step   = '+1 year';
				$format = 'Y';
				break;
			default:
				$step   = '+1 day';
				$format = 'j M';
		}
		$out   = array();
		$guard = 0;
		while ( $cursor <= $end && $guard < 1500 ) {
			$key = $cursor->format( 'Y-m-d' );
			if ( 'quarter' === $format ) {
				$out[ $key ] = 'Q' . (int) ceil( (int) $cursor->format( 'n' ) / 3 ) . ' ' . $cursor->format( 'Y' );
			} else {
				$out[ $key ] = wp_date( $format, $cursor->setTime( 12, 0 )->getTimestamp(), $tz );
			}
			$cursor = $cursor->modify( $step );
			++$guard;
		}
		return $out;
	}
}
