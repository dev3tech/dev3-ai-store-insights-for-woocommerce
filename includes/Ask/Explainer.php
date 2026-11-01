<?php
/**
 * Short explanations of results: DEV3 AI (Gemini, structured output) with a template fallback.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Ask;

use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Explanation builder.
 */
class Explainer {

	/**
	 * Compact, privacy-minimised payload of results for the model.
	 *
	 * @param array $results Results.
	 * @return array
	 */
	public static function payload( array $results ) {
		$names = (bool) Settings::get( 'send_customer_names', false );
		$out   = array();
		foreach ( $results as $result ) {
			$keys = wp_list_pluck( $result['columns'], 'key' );
			$rows = array();
			foreach ( array_slice( $result['rows'], 0, 15 ) as $i => $row ) {
				$clean = array();
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $row ) ) {
						$clean[ $key ] = $row[ $key ];
					}
				}
				if ( ! empty( $result['personal'] ) && ! $names && isset( $clean['name'] ) ) {
					/* translators: %d: rank */
					$clean['name'] = sprintf( __( 'Customer %d', 'dev3-ai-store-insights-for-woocommerce' ), $i + 1 );
				}
				$rows[] = $clean;
			}
			$item = array(
				'report'  => Library::describe( $result ),
				'title'   => $result['title'],
				'period'  => isset( $result['period']['label'] ) ? $result['period']['label'] . ( isset( $result['period']['range'] ) ? ' (' . $result['period']['range'] . ')' : '' ) : '',
				'compare' => ! empty( $result['compare_period'] ) ? $result['compare_period']['label'] . ' (' . $result['compare_period']['range'] . ')' : '',
				'summary' => $result['summary'],
				'columns' => wp_list_pluck( $result['columns'], 'label', 'key' ),
				'rows'    => $rows,
				'total'   => count( $result['rows'] ),
				'notes'   => $result['notes'],
			);
			if ( ! empty( $result['extra'] ) ) {
				$item['kpis'] = $result['extra'];
			}
			$out[] = $item;
		}
		/**
		 * Payload sent to the model to explain results.
		 *
		 * @param array $out     Payload.
		 * @param array $results Results.
		 */
		return (array) apply_filters( 'dev3si_ai_result_payload', $out, $results );
	}

	/**
	 * AI explanation.
	 *
	 * @param string $question Question.
	 * @param array  $results  Results.
	 * @param string $context  Context.
	 * @return array|\WP_Error { headline, summary, highlights, follow_ups, source, model, tokens, cached }
	 */
	public static function ai( $question, array $results, $context = 'admin' ) {
		$data   = self::payload( $results );
		$result = Ai::run(
			'ask_explain',
			array(
				'system'      => Prompts::explain_system(),
				'prompt'      => 'Question: ' . ( '' !== $question ? $question : '(saved report)' ) . "\nCurrency: " . Store::currency()['code'] . "\nData:\n" . wp_json_encode( $data ),
				'schema'      => Prompts::explain_schema(),
				'temperature' => 0.3,
				'max_tokens'  => 1200,
				'profile'     => 'fast',
				'cache'       => 6 * HOUR_IN_SECONDS,
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return self::clean( $result['data'] ) + array(
			'source' => 'ai',
			'model'  => $result['model'],
			'tokens' => $result['usage']['total'],
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Sanitises model output.
	 *
	 * @param mixed $data Data.
	 * @return array
	 */
	public static function clean( $data ) {
		$data  = is_array( $data ) ? $data : array();
		$lines = static function ( $items, $max, $len ) {
			$out = array();
			foreach ( array_slice( is_array( $items ) ? $items : array(), 0, $max ) as $line ) {
				$line = mb_substr( trim( sanitize_text_field( (string) $line ) ), 0, $len );
				if ( '' !== $line ) {
					$out[] = $line;
				}
			}
			return $out;
		};
		return array(
			'headline'   => mb_substr( sanitize_text_field( (string) ( $data['headline'] ?? '' ) ), 0, 160 ),
			'summary'    => mb_substr( sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ), 0, 700 ),
			'highlights' => $lines( $data['highlights'] ?? array(), 4, 180 ),
			'follow_ups' => $lines( $data['follow_ups'] ?? array(), 3, 110 ),
		);
	}

	/**
	 * Template explanation (no AI).
	 *
	 * @param array $results Results.
	 * @return array
	 */
	public static function template( array $results ) {
		$result = $results ? $results[0] : null;
		if ( ! $result ) {
			return array(
				'headline'   => __( 'No data to explain.', 'dev3-ai-store-insights-for-woocommerce' ),
				'summary'    => '',
				'highlights' => array(),
				'follow_ups' => array(),
				'source'     => 'template',
			);
		}
		$s          = (array) $result['summary'];
		$period     = isset( $result['period']['label'] ) ? mb_strtolower( $result['period']['label'] ) : '';
		$fmt        = isset( $s['format'] ) ? $s['format'] : 'number';
		$headline   = '';
		$summary    = array();
		$highlights = array();
		if ( empty( $result['rows'] ) || ( isset( $s['value'] ) && 0.0 === (float) $s['value'] && 'kpi' !== $result['chart']['type'] && 'heatmap' !== $result['chart']['type'] ) ) {
			/* translators: %s: period */
			$headline  = sprintf( __( 'No matching data for %s.', 'dev3-ai-store-insights-for-woocommerce' ), $period );
			$summary[] = __( 'Try a longer period or remove a filter.', 'dev3-ai-store-insights-for-woocommerce' );
		} elseif ( 'bar' === $result['chart']['type'] || 'share' === $result['chart']['type'] ) {
			$top   = $result['rows'][0];
			$key   = $result['chart']['value'];
			$value = Store::format( $top[ $key ], $result['chart']['format'] );
			/* translators: 1: item, 2: value, 3: period */
			$headline = sprintf( __( '%1$s leads with %2$s (%3$s).', 'dev3-ai-store-insights-for-woocommerce' ), $top['name'], $value, $period );
			if ( isset( $top['share'] ) && null !== $top['share'] ) {
				/* translators: %s: percent */
				$summary[] = sprintf( __( 'That is %s of the total.', 'dev3-ai-store-insights-for-woocommerce' ), Store::format( $top['share'], 'percent' ) );
			}
			$names = wp_list_pluck( array_slice( $result['rows'], 1, 3 ), 'name' );
			if ( $names ) {
				/* translators: %s: list of names */
				$summary[] = sprintf( __( 'Next: %s.', 'dev3-ai-store-insights-for-woocommerce' ), implode( ', ', $names ) );
			}
			foreach ( array_slice( $result['rows'], 0, 3 ) as $row ) {
				$highlights[] = $row['name'] . ': ' . Store::format( $row[ $key ], $result['chart']['format'] );
			}
		} elseif ( 'heatmap' === $result['chart']['type'] ) {
			/* translators: %s: percent */
			$headline  = sprintf( __( 'On average %s of new customers ordered again in their second month.', 'dev3-ai-store-insights-for-woocommerce' ), Store::format( $s['value'], 'percent' ) );
			$summary[] = __( 'Each row is the month of a customer\'s first order; columns show the share who ordered again in later months.', 'dev3-ai-store-insights-for-woocommerce' );
		} else {
			$value = Store::format( (float) $s['value'], $fmt );
			/* translators: 1: metric, 2: value, 3: period */
			$headline = sprintf( __( '%1$s: %2$s (%3$s).', 'dev3-ai-store-insights-for-woocommerce' ), $s['label'], $value, $period );
			if ( isset( $s['change'] ) && null !== $s['change'] && ! empty( $result['compare_period'] ) ) {
				$summary[] = sprintf(
					/* translators: 1: up/down, 2: percent, 3: comparison label, 4: previous value */
					__( 'That is %1$s %2$s vs %3$s (%4$s).', 'dev3-ai-store-insights-for-woocommerce' ),
					$s['change'] >= 0 ? __( 'up', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'down', 'dev3-ai-store-insights-for-woocommerce' ),
					Store::format( abs( $s['change'] ), 'percent' ),
					mb_strtolower( $result['compare_period']['label'] ),
					Store::format( (float) $s['previous'], $fmt )
				);
			}
			if ( 'line' === $result['chart']['type'] && $result['rows'] ) {
				$best = $result['rows'][0];
				foreach ( $result['rows'] as $row ) {
					if ( $row['value'] > $best['value'] ) {
						$best = $row;
					}
				}
				/* translators: 1: bucket label, 2: value */
				$highlights[] = sprintf( __( 'Best: %1$s with %2$s', 'dev3-ai-store-insights-for-woocommerce' ), $best['label'], Store::format( $best['value'], $fmt ) );
			}
			if ( 'kpi' === $result['chart']['type'] ) {
				foreach ( array_slice( $result['chart']['items'], 1, 3 ) as $item ) {
					$highlights[] = $item['label'] . ': ' . Store::format( $item['value'], $item['format'] ) . ( null !== $item['change'] ? ' (' . ( $item['change'] >= 0 ? '+' : '' ) . Store::format( $item['change'], 'percent' ) . ')' : '' );
				}
			}
		}
		return array(
			'headline'   => $headline,
			'summary'    => implode( ' ', $summary ),
			'highlights' => $highlights,
			'follow_ups' => self::follow_ups( $result ),
			'source'     => 'template',
		);
	}

	/**
	 * Rule-based follow-up suggestions.
	 *
	 * @param array $result Result.
	 * @return string[]
	 */
	public static function follow_ups( array $result ) {
		$map = array(
			'store_summary'     => array( __( 'Best sellers this month', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Net sales by week for the last 90 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Sales by country last month', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'sales_trend'       => array( __( 'Top products last 30 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'New vs returning customers last 30 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Average order value by month this year', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'top_products'      => array( __( 'Top categories last month', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Which products will run out of stock first?', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Most refunded products last 90 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'top_categories'    => array( __( 'Best sellers last month', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Sales by city last month', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'sales_by_location' => array( __( 'Sales by city last 90 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Top customers last 90 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'customer_segments' => array( __( 'Customer retention cohorts', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Top customers this year', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'top_customers'     => array( __( 'New vs returning customers this year', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Customer retention cohorts', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'refunds'           => array( __( 'Most refunded products last 90 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Store summary last 30 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'cohorts'           => array( __( 'New vs returning customers last 90 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Top customers this year', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'stock_status'      => array( __( 'Which products will run out of stock first?', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Best sellers last 30 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
			'top_coupons'       => array( __( 'Store summary last 30 days', 'dev3-ai-store-insights-for-woocommerce' ), __( 'New vs returning customers last 30 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
		);
		return isset( $map[ $result['fn'] ] ) ? $map[ $result['fn'] ] : array();
	}
}
