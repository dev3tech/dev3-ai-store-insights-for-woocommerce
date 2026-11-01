<?php
/**
 * Store facts the query library needs: table names, report statuses, HPOS-aware order location
 * columns and resolvers that turn names ("Dubai", "Hoodies") into ids/codes.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Query;

use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers (no state besides small per-request memos).
 */
class Store {

	/**
	 * Per-request memo.
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Clears memos (tests).
	 */
	public static function reset() {
		self::$memo = array();
	}

	/**
	 * Forces the order storage the address join reads (tests; null = detect).
	 *
	 * @param bool|null $on HPOS.
	 */
	public static function set_hpos( $on ) {
		unset( self::$memo['locations'] );
		if ( null === $on ) {
			unset( self::$memo['hpos'] );
			return;
		}
		self::$memo['hpos'] = (bool) $on;
	}

	/**
	 * WooCommerce Analytics table names.
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'stats'     => $wpdb->prefix . 'wc_order_stats',
			'products'  => $wpdb->prefix . 'wc_order_product_lookup',
			'customers' => $wpdb->prefix . 'wc_customer_lookup',
			'coupons'   => $wpdb->prefix . 'wc_order_coupon_lookup',
			'meta'      => $wpdb->prefix . 'wc_product_meta_lookup',
			'addresses' => $wpdb->prefix . 'wc_order_addresses',
			'orders'    => $wpdb->prefix . 'wc_orders',
		);
	}

	/**
	 * Whether the Analytics tables exist (WooCommerce active and installed).
	 *
	 * @return bool
	 */
	public static function ready() {
		if ( ! isset( self::$memo['ready'] ) ) {
			global $wpdb;
			$t                   = self::tables();
			self::$memo['ready'] = class_exists( 'WooCommerce' ) && $t['stats'] === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t['stats'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return self::$memo['ready'];
	}

	/**
	 * Whether HPOS tables are the authoritative order store.
	 *
	 * @return bool
	 */
	public static function hpos() {
		if ( isset( self::$memo['hpos'] ) ) {
			return self::$memo['hpos'];
		}
		$on = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$on = (bool) \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		self::$memo['hpos'] = $on;
		return $on;
	}

	/**
	 * Order statuses excluded from reports (WooCommerce > Settings > Analytics), as stored in wc_order_stats.
	 *
	 * @return string[]
	 */
	public static function excluded_statuses() {
		$excluded = get_option( 'woocommerce_excluded_report_order_statuses', array( 'pending', 'failed', 'cancelled' ) );
		$excluded = is_array( $excluded ) ? $excluded : array();
		$out      = array( 'wc-trash', 'trash', 'auto-draft', 'wc-auto-draft', 'wc-checkout-draft', 'checkout-draft' );
		foreach ( $excluded as $status ) {
			$status = sanitize_key( (string) $status );
			$out[]  = 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
		}
		/**
		 * Statuses left out of every insight.
		 *
		 * @param string[] $out Statuses with the wc- prefix.
		 */
		return array_values( array_unique( (array) apply_filters( 'dev3si_excluded_statuses', $out ) ) );
	}

	/**
	 * Address basis for location breakdowns.
	 *
	 * @return string billing|shipping
	 */
	public static function basis() {
		return 'shipping' === Settings::get( 'location_basis', 'billing' ) ? 'shipping' : 'billing';
	}

	/**
	 * JOIN + column expressions for the order's address (alias s = wc_order_stats). Refunds use their parent order.
	 *
	 * @return array { join, country, state, city }
	 */
	public static function location_sql() {
		global $wpdb;
		$basis = self::basis();
		$t     = self::tables();
		$id    = 'IF(s.parent_id > 0, s.parent_id, s.order_id)';
		if ( self::hpos() ) {
			return array(
				'join'    => "LEFT JOIN {$t['addresses']} la ON la.order_id = {$id} AND la.address_type = '{$basis}'",
				'country' => 'la.country',
				'state'   => 'la.state',
				'city'    => 'la.city',
			);
		}
		$pm = $wpdb->postmeta;
		return array(
			'join'    => "LEFT JOIN {$pm} lc ON lc.post_id = {$id} AND lc.meta_key = '_{$basis}_country' LEFT JOIN {$pm} ls ON ls.post_id = {$id} AND ls.meta_key = '_{$basis}_state' LEFT JOIN {$pm} lt ON lt.post_id = {$id} AND lt.meta_key = '_{$basis}_city'",
			'country' => 'lc.meta_value',
			'state'   => 'ls.meta_value',
			'city'    => 'lt.meta_value',
		);
	}

	/**
	 * Countries (code => name).
	 *
	 * @return array
	 */
	public static function countries() {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			return WC()->countries->get_countries();
		}
		return array();
	}

	/**
	 * Country name.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function country_name( $code ) {
		$all = self::countries();
		return isset( $all[ $code ] ) ? html_entity_decode( $all[ $code ] ) : (string) $code;
	}

	/**
	 * State name.
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 * @return string
	 */
	public static function state_name( $country, $state ) {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) {
				return html_entity_decode( $states[ $state ] );
			}
		}
		return (string) $state;
	}

	/**
	 * Distinct order locations present in the data (cached a few minutes): countries, states, cities.
	 *
	 * @return array { countries[], states[ {country,state} ], cities[] }
	 */
	public static function known_locations() {
		if ( isset( self::$memo['locations'] ) ) {
			return self::$memo['locations'];
		}
		$cached = get_transient( 'dev3si_locations' );
		if ( is_array( $cached ) ) {
			self::$memo['locations'] = $cached;
			return $cached;
		}
		$out = array(
			'countries' => array(),
			'states'    => array(),
			'cities'    => array(),
		);
		if ( self::ready() ) {
			global $wpdb;
			$t   = self::tables();
			$loc = self::location_sql();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT {$loc['country']} AS country, {$loc['state']} AS state, {$loc['city']} AS city, COUNT(*) AS n FROM {$t['stats']} s {$loc['join']} WHERE s.parent_id = 0 GROUP BY country, state, city ORDER BY n DESC LIMIT 400", ARRAY_A );
			// phpcs:enable
			$cities = array();
			foreach ( (array) $rows as $row ) {
				$country = strtoupper( (string) $row['country'] );
				if ( '' !== $country ) {
					$out['countries'][ $country ] = true;
					if ( '' !== (string) $row['state'] ) {
						$out['states'][ $country . ':' . $row['state'] ] = array(
							'country' => $country,
							'state'   => (string) $row['state'],
						);
					}
				}
				$city = trim( (string) $row['city'] );
				if ( '' !== $city ) {
					$cities[ mb_strtolower( $city ) ] = $city;
				}
			}
			$out['countries'] = array_keys( $out['countries'] );
			$out['states']    = array_values( $out['states'] );
			$out['cities']    = array_slice( array_values( $cities ), 0, 150 );
		}
		set_transient( 'dev3si_locations', $out, 10 * MINUTE_IN_SECONDS );
		self::$memo['locations'] = $out;
		return $out;
	}

	/**
	 * Resolves free text to location conditions. "Dubai" matches the UAE state DU and the city Dubai.
	 *
	 * @param string $text Text.
	 * @return array|null { label, countries[], states[ {country,state} ], cities[] }
	 */
	public static function resolve_location( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$needle    = mb_strtolower( $text );
		$aliases   = array(
			'uae'            => 'AE',
			'emirates'       => 'AE',
			'usa'            => 'US',
			'us'             => 'US',
			'america'        => 'US',
			'united states'  => 'US',
			'uk'             => 'GB',
			'britain'        => 'GB',
			'great britain'  => 'GB',
			'england'        => 'GB',
			'united kingdom' => 'GB',
			'holland'        => 'NL',
			'korea'          => 'KR',
			'south korea'    => 'KR',
		);
		$out       = array(
			'label'     => $text,
			'countries' => array(),
			'states'    => array(),
			'cities'    => array(),
		);
		$countries = self::countries();
		if ( isset( $aliases[ $needle ] ) ) {
			$out['countries'][] = $aliases[ $needle ];
		} elseif ( 2 === strlen( $text ) && isset( $countries[ strtoupper( $text ) ] ) ) {
			$out['countries'][] = strtoupper( $text );
		} else {
			foreach ( $countries as $code => $name ) {
				$name = mb_strtolower( html_entity_decode( $name ) );
				if ( $name === $needle || preg_replace( '/\s*\(.*\)$/', '', $name ) === $needle || 'the ' . $needle === $name ) {
					$out['countries'][] = $code;
					break;
				}
			}
		}
		if ( $out['countries'] ) {
			$out['label'] = self::country_name( $out['countries'][0] );
			return $out;
		}
		$known = self::known_locations();
		$check = array();
		foreach ( $known['states'] as $pair ) {
			$check[ $pair['country'] ] = true;
		}
		foreach ( array_keys( $check ) as $country ) {
			$states = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_states( $country ) : array();
			foreach ( (array) $states as $code => $name ) {
				if ( mb_strtolower( html_entity_decode( $name ) ) === $needle || mb_strtolower( $code ) === $needle ) {
					$out['states'][] = array(
						'country' => $country,
						'state'   => (string) $code,
					);
				}
			}
		}
		$out['cities'][] = $text;
		return $out;
	}

	/**
	 * Category term ids (with children) for a name or slug.
	 *
	 * @param string $text Name or slug.
	 * @return array { ids[], label }|null
	 */
	public static function resolve_category( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text || ! taxonomy_exists( 'product_cat' ) ) {
			return null;
		}
		$term = get_term_by( 'slug', sanitize_title( $text ), 'product_cat' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $text, 'product_cat' );
		}
		if ( ! $term ) {
			$found = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'search'     => $text,
					'number'     => 1,
					'hide_empty' => false,
				)
			);
			$term  = ! is_wp_error( $found ) && $found ? $found[0] : null;
		}
		if ( ! $term ) {
			return array(
				'ids'   => array( 0 ),
				'label' => $text,
			);
		}
		$ids = array_merge( array( (int) $term->term_id ), array_map( 'intval', (array) get_term_children( $term->term_id, 'product_cat' ) ) );
		return array(
			'ids'   => $ids,
			'label' => html_entity_decode( $term->name ),
		);
	}

	/**
	 * Product ids for a name or SKU (up to 20).
	 *
	 * @param string $text Text.
	 * @return array { ids[], label }|null
	 */
	public static function resolve_product( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		global $wpdb;
		$t   = self::tables();
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t['meta']} WHERE sku = %s LIMIT 20", $text ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( ! $ids ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status NOT IN ('trash','auto-draft') AND post_title LIKE %s ORDER BY (post_title = %s) DESC, ID ASC LIMIT 20", '%' . $wpdb->esc_like( $text ) . '%', $text ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$ids = array_map( 'intval', (array) $ids );
		return array(
			'ids'   => $ids ? $ids : array( 0 ),
			'label' => $ids ? self::product_name( $ids[0] ) . ( count( $ids ) > 1 ? ' +' . ( count( $ids ) - 1 ) : '' ) : $text,
		);
	}

	/**
	 * Product display name (parent name for variations is kept by WooCommerce).
	 *
	 * @param int $id Id.
	 * @return string
	 */
	public static function product_name( $id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( $product ) {
			return html_entity_decode( wp_strip_all_tags( $product->get_name() ) );
		}
		/* translators: %d: product id */
		return sprintf( __( 'Product #%d (deleted)', 'dev3-ai-store-insights-for-woocommerce' ), $id );
	}

	/**
	 * Top product categories (names) for prompts and suggestions.
	 *
	 * @param int $limit Limit.
	 * @return string[]
	 */
	public static function category_names( $limit = 40 ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'number'     => $limit,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			if ( 'uncategorized' !== $term->slug ) {
				$out[] = html_entity_decode( $term->name );
			}
		}
		return $out;
	}

	/**
	 * Currency facts for the UI and emails.
	 *
	 * @return array
	 */
	public static function currency() {
		if ( ! function_exists( 'get_woocommerce_currency' ) ) {
			return array(
				'code'     => 'USD',
				'symbol'   => '$',
				'decimals' => 2,
				'position' => 'left',
			);
		}
		return array(
			'code'     => get_woocommerce_currency(),
			'symbol'   => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimals' => wc_get_price_decimals(),
			'position' => (string) get_option( 'woocommerce_currency_pos', 'left' ),
		);
	}

	/**
	 * Formats a value for emails and template explanations.
	 *
	 * @param float  $value  Value.
	 * @param string $format money|number|percent.
	 * @return string
	 */
	public static function format( $value, $format ) {
		$value = (float) $value;
		if ( 'money' === $format ) {
			$c    = self::currency();
			$num  = number_format_i18n( $value, abs( $value ) >= 100 ? 0 : (int) $c['decimals'] );
			$sign = $value < 0 ? '-' : '';
			$num  = ltrim( $num, '-' );
			switch ( $c['position'] ) {
				case 'right':
					return $sign . $num . $c['symbol'];
				case 'right_space':
					return $sign . $num . ' ' . $c['symbol'];
				case 'left_space':
					return $sign . $c['symbol'] . ' ' . $num;
				default:
					return $sign . $c['symbol'] . $num;
			}
		}
		if ( 'percent' === $format ) {
			return number_format_i18n( $value, abs( $value ) < 10 ? 1 : 0 ) . '%';
		}
		if ( 'days' === $format ) {
			/* translators: %s: number of days */
			return sprintf( __( '%s days', 'dev3-ai-store-insights-for-woocommerce' ), number_format_i18n( $value, 0 ) );
		}
		return number_format_i18n( $value, floor( $value ) === $value ? 0 : 1 );
	}
}
