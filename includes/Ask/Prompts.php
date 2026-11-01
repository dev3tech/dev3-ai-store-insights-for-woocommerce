<?php
/**
 * Every prompt and schema the plugin sends to Gemini, in one place.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Ask;

use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt library.
 */
class Prompts {

	const VERSION = '1.0';

	/**
	 * Store context for the planner (date, timezone, currency, categories, locations present).
	 *
	 * @return array
	 */
	public static function context() {
		$now   = Period::now();
		$known = Store::known_locations();
		$ctx   = array(
			'today'       => $now->format( 'Y-m-d (l)' ),
			'timezone'    => wp_timezone_string(),
			'week_starts' => 0 === (int) get_option( 'start_of_week', 1 ) ? 'Sunday' : 'Monday',
			'currency'    => Store::currency()['code'],
			'categories'  => array_slice( Store::category_names( 40 ), 0, 40 ),
			'countries'   => array_slice(
				array_map(
					static function ( $code ) {
						return $code . ' ' . Store::country_name( $code );
					},
					$known['countries']
				),
				0,
				40
			),
			'cities'      => array_slice( $known['cities'], 0, 40 ),
		);
		/**
		 * Context given to the question planner.
		 *
		 * @param array $ctx Context.
		 */
		return (array) apply_filters( 'dev3si_ask_context', $ctx );
	}

	/**
	 * Planner system instruction.
	 *
	 * @param array $ctx Context.
	 * @return string
	 */
	public static function plan_system( array $ctx ) {
		return implode(
			"\n",
			array(
				'You map a WooCommerce store owner\'s question to one of the provided report functions. Always answer with a function call; never write SQL or prose.',
				'Pick the single best function. Use a second call only when the question clearly asks for two different things (e.g. "top products and top countries").',
				'Dates: today is ' . $ctx['today'] . ' in ' . $ctx['timezone'] . '; weeks start on ' . $ctx['week_starts'] . '. Prefer the preset periods. "Last month" = last_month. For a named month or explicit dates use period=custom with from/to (YYYY-MM-DD). If no period is mentioned use last_30_days.',
				'Locations: put a city, emirate/state or country in "location" exactly as the user wrote it (e.g. "Dubai"). Product categories go in "category".',
				'"Best sellers" = top_products by revenue unless the user says units/quantity. "AOV" = average order value (sales_trend metric aov or store_summary).',
				'Store currency: ' . $ctx['currency'] . '.',
				'Categories in this store: ' . ( $ctx['categories'] ? implode( ', ', $ctx['categories'] ) : 'n/a' ) . '.',
				'Countries with orders: ' . ( $ctx['countries'] ? implode( ', ', $ctx['countries'] ) : 'n/a' ) . '.',
				'Cities with orders: ' . ( $ctx['cities'] ? implode( ', ', $ctx['cities'] ) : 'n/a' ) . '.',
			)
		);
	}

	/**
	 * Explanation system instruction.
	 *
	 * @return string
	 */
	public static function explain_system() {
		return implode(
			"\n",
			array(
				'You are a concise e-commerce analyst explaining a report to a busy store owner.',
				'Use ONLY the numbers in the data. Never invent figures, causes or products. If the data is empty, say so and suggest a broader period.',
				'Headline: one sentence with the key number. Summary: 2-3 short sentences: what happened, the comparison when given, and what stands out.',
				'Highlights: up to 4 short factual bullet points. Follow-ups: up to 3 natural next questions the owner could ask this tool (sales, products, categories, locations, customers, refunds, cohorts, stock, coupons).',
				'Plain text only: no markdown, no emojis. Write money with the currency given. Keep the user\'s language.',
			)
		);
	}

	/**
	 * Explanation schema.
	 *
	 * @return array
	 */
	public static function explain_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'headline'   => array(
					'type'      => 'STRING',
					'maxLength' => 160,
				),
				'summary'    => array(
					'type'      => 'STRING',
					'maxLength' => 700,
				),
				'highlights' => array(
					'type'     => 'ARRAY',
					'maxItems' => 4,
					'items'    => array(
						'type'      => 'STRING',
						'maxLength' => 180,
					),
				),
				'follow_ups' => array(
					'type'     => 'ARRAY',
					'maxItems' => 3,
					'items'    => array(
						'type'      => 'STRING',
						'maxLength' => 110,
					),
				),
			),
			'required'   => array( 'headline', 'summary' ),
		);
	}

	/**
	 * Alert explanation system instruction.
	 *
	 * @return string
	 */
	public static function alert_system() {
		return 'You explain an anomaly detected in a WooCommerce store\'s daily numbers. Use only the data given: the metric, the day\'s value, the 28-day baseline and the products/locations that moved most. Give a headline, a 2-3 sentence summary of the likely drivers (as observations, not certainties), up to 4 highlights and up to 3 follow-up questions. Plain text, no markdown.';
	}

	/**
	 * Alert advice system instruction.
	 *
	 * @return string
	 */
	public static function advice_system() {
		return 'You suggest anomaly alert rules for a WooCommerce store from 90 days of daily totals. Each rule watches one metric (net_sales, orders, aov, refunds, refund_rate, items_sold) for a drop, a spike or both, with a sensitivity (low, medium, high), a minimum percent change (5-90) and a minimum baseline below which the rule stays quiet. Suggest 2 to 5 rules that fit this store\'s volume and volatility, each with a short name and a one-sentence reason. Return JSON only.';
	}

	/**
	 * Alert advice schema.
	 *
	 * @return array
	 */
	public static function advice_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'rules' => array(
					'type'     => 'ARRAY',
					'maxItems' => 5,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'name'           => array(
								'type'      => 'STRING',
								'maxLength' => 80,
							),
							'metric'         => array(
								'type' => 'STRING',
								'enum' => array( 'net_sales', 'orders', 'aov', 'refunds', 'refund_rate', 'items_sold' ),
							),
							'direction'      => array(
								'type' => 'STRING',
								'enum' => array( 'drop', 'spike', 'both' ),
							),
							'sensitivity'    => array(
								'type' => 'STRING',
								'enum' => array( 'low', 'medium', 'high' ),
							),
							'min_change_pct' => array( 'type' => 'INTEGER' ),
							'min_baseline'   => array( 'type' => 'NUMBER' ),
							'reason'         => array(
								'type'      => 'STRING',
								'maxLength' => 200,
							),
						),
						'required'   => array( 'name', 'metric', 'direction', 'sensitivity' ),
					),
				),
			),
			'required'   => array( 'rules' ),
		);
	}

	/**
	 * Digest summary system instruction.
	 *
	 * @return string
	 */
	public static function digest_system() {
		return 'You write the opening paragraph of a store performance email digest. Use only the numbers given, compare with the previous period when available, mention the strongest and weakest points, and stay under 90 words. Plain text, no markdown, no greeting or sign-off.';
	}
}
