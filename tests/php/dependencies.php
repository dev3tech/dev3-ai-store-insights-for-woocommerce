<?php
/**
 * Loads WooCommerce before the plugin in the test suite and installs its tables (incl. Analytics + HPOS).
 *
 * @package Dev3\AiStoreInsights
 */

tests_add_filter(
	'dev3si_test_dependencies',
	static function () {
		return array( file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ? 'woocommerce/woocommerce.php' : 'woocommerce.latest-stable/woocommerce.php' );
	}
);

tests_add_filter(
	'setup_theme',
	static function () {
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
			if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer' ) ) {
				wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
			}
			// Start from an empty order store: HPOS and Analytics tables survive the WP test installer between runs.
			global $wpdb;
			foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'wc_order_stats', 'wc_order_product_lookup', 'wc_customer_lookup', 'wc_order_coupon_lookup', 'wc_order_tax_lookup', 'dev3si_questions', 'dev3si_alerts' ) as $dev3si_table ) {
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $dev3si_table ) ) ) {
					$wpdb->query( "DELETE FROM {$wpdb->prefix}{$dev3si_table}" ); // phpcs:ignore
				}
			}
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles WooCommerce just added.
			wp_roles();
		}
	}
);

tests_add_filter(
	'wp_mail_from',
	static function () {
		return 'shop@example.org';
	}
);
