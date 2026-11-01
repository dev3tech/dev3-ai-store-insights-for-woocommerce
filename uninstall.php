<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiStoreInsights
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3si_settings = get_option( 'dev3si_settings', array() );
if ( empty( $dev3si_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3si_usage', 'dev3si_core_cache', 'dev3si_core_jobs', 'dev3si_core_embeddings', 'dev3si_questions', 'dev3si_alerts' ) as $dev3si_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3si_table}" );
}
foreach ( array( 'dev3si_settings', 'dev3si_db_version', 'dev3si_alert_rules', 'dev3si_alerts_checked', 'dev3si_digests' ) as $dev3si_option ) {
	delete_option( $dev3si_option );
}
delete_transient( 'dev3si_models' );
delete_transient( 'dev3si_locations' );
wp_unschedule_hook( 'dev3si_daily' );
wp_unschedule_hook( 'dev3si_hourly' );
wp_unschedule_hook( 'dev3si_run_job' );
