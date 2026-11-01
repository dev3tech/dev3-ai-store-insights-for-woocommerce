<?php
/**
 * The wp-admin Dashboard widget: today vs yesterday, a 14-day sparkline and the pinned insights.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Admin;

use Dev3\AiStoreInsights\Core3\Core\Capabilities;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Widget.
 */
class Dashboard_Widget {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'wp_dashboard_setup', array( $this, 'setup' ) );
	}

	/**
	 * Adds the widget.
	 */
	public function setup() {
		if ( ! Settings::get( 'dashboard_widget', true ) || ! Capabilities::can_manage() || ! Store::ready() ) {
			return;
		}
		wp_add_dashboard_widget( 'dev3si_widget', __( 'DEV3 AI Store Insights', 'dev3-ai-store-insights-for-woocommerce' ), array( $this, 'render' ) );
	}

	/**
	 * SVG sparkline (single series, 2px line, 10% wash).
	 *
	 * @param float[] $values Values.
	 * @return string
	 */
	public static function sparkline( array $values ) {
		$w = 240;
		$h = 40;
		$n = count( $values );
		if ( $n < 2 ) {
			return '';
		}
		$max = max( 0.0001, max( $values ) );
		$pts = array();
		foreach ( array_values( $values ) as $i => $v ) {
			$pts[] = round( $i / ( $n - 1 ) * $w, 1 ) . ',' . round( $h - 3 - ( $v / $max ) * ( $h - 6 ), 1 );
		}
		$line = implode( ' ', $pts );
		return '<svg class="dev3si-w-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" width="100%" height="' . $h . '" role="img" aria-label="' . esc_attr__( 'Net sales, last 14 days', 'dev3-ai-store-insights-for-woocommerce' ) . '">'
			. '<polygon points="0,' . $h . ' ' . esc_attr( $line ) . ' ' . $w . ',' . $h . '" fill="#2a78d6" fill-opacity="0.1"/>'
			. '<polyline points="' . esc_attr( $line ) . '" fill="none" stroke="#2a78d6" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/></svg>';
	}

	/**
	 * Renders.
	 */
	public function render() {
		$today = Library::run(
			'store_summary',
			array(
				'period'  => 'today',
				'compare' => 'previous_period',
			)
		);
		$trend = Library::run(
			'sales_trend',
			array(
				'period'  => 'custom',
				'from'    => gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -13 days' ) ),
				'to'      => current_time( 'Y-m-d' ),
				'compare' => 'none',
			)
		);
		echo '<style>.dev3si-w-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 12px}.dev3si-w-kpi{border:1px solid #e3e6eb;border-radius:8px;padding:8px 10px}.dev3si-w-kpi span{display:block;font-size:12px;color:#50575e}.dev3si-w-kpi strong{display:block;font-size:16px;line-height:1.4;color:#1d2327}.dev3si-w-kpi em{font-style:normal;font-size:12px;font-weight:600}.dev3si-w-up{color:#006300}.dev3si-w-down{color:#b42318}.dev3si-w-pins{margin:12px 0 0;padding:0;list-style:none}.dev3si-w-pins li{margin:0;padding:6px 0;border-top:1px solid #f0f0f1}@media (max-width:400px){.dev3si-w-kpis{grid-template-columns:1fr 1fr}}</style>';
		if ( ! is_wp_error( $today ) ) {
			echo '<div class="dev3si-w-kpis">';
			foreach ( array_slice( $today['chart']['items'], 0, 3 ) as $item ) {
				$change = $item['change'];
				$good   = null !== $change && ( 'up' === $item['good'] ? $change >= 0 : $change <= 0 );
				echo '<div class="dev3si-w-kpi"><span>' . esc_html( $item['label'] ) . '</span><strong>' . esc_html( Store::format( $item['value'], $item['format'] ) ) . '</strong>';
				if ( null !== $change ) {
					/* translators: %s: signed percent change */
					echo '<em class="' . ( $good ? 'dev3si-w-up' : 'dev3si-w-down' ) . '">' . esc_html( sprintf( __( '%s vs yesterday', 'dev3-ai-store-insights-for-woocommerce' ), ( $change >= 0 ? '▲ +' : '▼ ' ) . Store::format( $change, 'percent' ) ) ) . '</em>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		if ( ! is_wp_error( $trend ) ) {
			echo '<p style="margin:0 0 4px;color:#50575e">' . esc_html__( 'Net sales, last 14 days', 'dev3-ai-store-insights-for-woocommerce' ) . '</p>';
			echo self::sparkline( wp_list_pluck( $trend['rows'], 'value' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from numbers and escaped attributes.
		}
		$pins = Questions::all( 'pinned', 5 );
		if ( $pins ) {
			echo '<ul class="dev3si-w-pins">';
			foreach ( $pins as $pin ) {
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SI_SLUG . '#/dashboard' ) ) . '">' . esc_html( $pin['title'] ) . '</a></li>';
			}
			echo '</ul>';
		}
		echo '<p style="margin:12px 0 0"><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SI_SLUG . '#/ask' ) ) . '">' . esc_html__( 'Ask your store', 'dev3-ai-store-insights-for-woocommerce' ) . '</a></p>';
	}
}
