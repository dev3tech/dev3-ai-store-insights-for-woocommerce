<?php
/**
 * Data health: are all orders in the WooCommerce Analytics tables? Imports missing ones in a background job.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Data;

use Dev3\AiStoreInsights\Core3\Api\Api_Error;
use Dev3\AiStoreInsights\Core3\Core\Jobs;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Health checks.
 */
class Health {

	const JOB = 'dev3si_import';

	/**
	 * Order ids that are missing from wc_order_stats (HPOS-aware), newest first.
	 *
	 * @param int $limit Limit.
	 * @return int[]
	 */
	public static function missing_ids( $limit = 5000 ) {
		global $wpdb;
		if ( ! Store::ready() ) {
			return array();
		}
		$t = Store::tables();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( Store::hpos() ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT o.id FROM {$t['orders']} o LEFT JOIN {$t['stats']} s ON s.order_id = o.id WHERE o.type IN ('shop_order','shop_order_refund') AND o.status NOT IN ('trash','auto-draft','wc-checkout-draft','draft') AND s.order_id IS NULL ORDER BY o.id DESC LIMIT %d", $limit ) );
		} else {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$t['stats']} s ON s.order_id = p.ID WHERE p.post_type IN ('shop_order','shop_order_refund') AND p.post_status NOT IN ('trash','auto-draft','wc-checkout-draft','draft') AND s.order_id IS NULL ORDER BY p.ID DESC LIMIT %d", $limit ) );
		}
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Status for the UI.
	 *
	 * @return array
	 */
	public static function status() {
		global $wpdb;
		$ready = Store::ready();
		$out   = array(
			'woocommerce' => class_exists( 'WooCommerce' ),
			'ready'       => $ready,
			'hpos'        => Store::hpos(),
			'orders'      => 0,
			'missing'     => 0,
			'job'         => Jobs::active( self::JOB ),
		);
		if ( $ready ) {
			$t              = Store::tables();
			$out['orders']  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['stats']} WHERE parent_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$out['missing'] = count( self::missing_ids( 5000 ) );
		}
		return $out;
	}

	/**
	 * Starts the import job.
	 *
	 * @return array|\WP_Error Job.
	 */
	public static function start_import() {
		if ( ! class_exists( '\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler' ) ) {
			return Api_Error::make( 'dev3si_no_scheduler', __( 'This WooCommerce version cannot import orders into Analytics.', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Update WooCommerce, or use WooCommerce > Settings > Advanced > Analytics > Import historical data.', 'dev3-ai-store-insights-for-woocommerce' ), 409 );
		}
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$ids = self::missing_ids( 5000 );
		if ( ! $ids ) {
			return Api_Error::make( 'dev3si_nothing_missing', __( 'Every order is already in WooCommerce Analytics.', 'dev3-ai-store-insights-for-woocommerce' ), '', 409 );
		}
		$id = Jobs::create( self::JOB, array_reverse( $ids ), array(), 25 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Order ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$out = array();
		foreach ( $items as $id ) {
			try {
				\Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler::import( (int) $id );
				$out[ $id ] = true;
			} catch ( \Throwable $e ) {
				$out[ $id ] = $e->getMessage();
			}
		}
		delete_transient( 'dev3si_locations' );
		return $out;
	}
}
