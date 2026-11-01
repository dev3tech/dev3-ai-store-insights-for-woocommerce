<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights;

use Dev3\AiStoreInsights\Core3\Core\Assets;
use Dev3\AiStoreInsights\Core3\Core\Jobs;
use Dev3\AiStoreInsights\Core3\Core\Privacy;
use Dev3\AiStoreInsights\Core3\Core\Schema;
use Dev3\AiStoreInsights\Data\Health;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Insights\Alerts;
use Dev3\AiStoreInsights\Insights\Digests;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK   = 'dev3si_daily';
	const HOURLY_HOOK = 'dev3si_hourly';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered once.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SI_DB_VERSION );
		self::schedule();
	}

	/**
	 * Schedules cron events.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::HOURLY_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::HOURLY_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		add_filter( 'dev3si_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3si_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3si_schema_sql', array( Questions::class, 'schema' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', array( $this, 'textdomain' ) );
		self::register_schema();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3SI_DB_VERSION ) );
		add_action( 'admin_init', array( self::class, 'schedule' ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( self::CRON_HOOK, array( Questions::class, 'prune' ) );
		add_action( self::HOURLY_HOOK, array( Digests::class, 'tick' ) );
		add_action( self::HOURLY_HOOK, array( Alerts::class, 'tick' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_filter( 'rest_pre_serve_request', array( Rest\Rest_Controller::class, 'serve_csv' ), 10, 3 );
		add_action( 'update_option_dev3si_settings', static fn() => delete_transient( 'dev3si_locations' ) );
		Privacy::init();
		add_action( 'init', array( $this, 'privacy' ) );
		// Background job types must be registered on every request (the runner looks them up).
		Jobs::register( Health::JOB, array( Health::class, 'run_chunk' ) );
		add_action( 'init', array( $this, 'assets' ) );
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Dashboard_Widget() )->register();
		}
		add_action( 'admin_notices', array( $this, 'woo_missing_notice' ) );
	}

	/**
	 * Loads translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-store-insights-for-woocommerce', false, dirname( plugin_basename( DEV3SI_FILE ) ) . '/languages' );
	}

	/**
	 * GDPR exporter/eraser for the question history (on init: the labels are translated).
	 */
	public function privacy() {
		Privacy::add_exporter( __( 'DEV3 AI Store Insights questions', 'dev3-ai-store-insights-for-woocommerce' ), array( Questions::class, 'export_personal' ) );
		Privacy::add_eraser( __( 'DEV3 AI Store Insights questions', 'dev3-ai-store-insights-for-woocommerce' ), array( Questions::class, 'erase_personal' ) );
	}

	/**
	 * Registers the admin bundle once.
	 */
	public function assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register( array( 'admin' ) );
	}

	/**
	 * HPOS + blocks compatibility (orders are only read through the Analytics lookup tables and the HPOS-aware address join).
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3SI_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3SI_FILE, true );
		}
	}

	/**
	 * Notice when WooCommerce is missing.
	 */
	public function woo_missing_notice() {
		if ( class_exists( 'WooCommerce' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Store Insights for WooCommerce needs WooCommerce 8.0 or later to be active.', 'dev3-ai-store-insights-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Rest_Controller() )->register_routes();
	}
}
