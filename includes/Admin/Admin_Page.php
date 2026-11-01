<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Admin;

use Dev3\AiStoreInsights\Core3\Core\Assets;
use Dev3\AiStoreInsights\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3SI_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3SI_NAME, __( 'DEV3 AI Insights', 'dev3-ai-store-insights-for-woocommerce' ), Capabilities::manage(), DEV3SI_SLUG, array( $this, 'render' ), 'dashicons-chart-area', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3si-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3SI_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3si-admin-root" class="dev3si-root dev3si-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SI_SLUG ) ) . '">' . esc_html__( 'Open Insights', 'dev3-ai-store-insights-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
