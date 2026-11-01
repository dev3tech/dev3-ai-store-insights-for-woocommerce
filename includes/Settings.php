<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights;

use Dev3\AiStoreInsights\Query\Period;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3si_settings_defaults / dev3si_settings_sanitize.
 */
class Settings {

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'appearance'          => 'auto',
				'default_period'      => 'last_30_days',
				'ask_mode'            => 'ai',
				'explain_answers'     => true,
				'send_customer_names' => false,
				'location_basis'      => 'billing',
				'history_days'        => 90,
				'alerts_enabled'      => true,
				'alert_recipients'    => '',
				'dashboard_widget'    => true,
			)
		);
	}

	/**
	 * Comma/space separated e-mails → cleaned list string.
	 *
	 * @param string $raw Raw.
	 * @return string
	 */
	public static function clean_emails( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $raw ) as $email ) {
			$email = sanitize_email( $email );
			if ( $email && is_email( $email ) ) {
				$out[ strtolower( $email ) ] = $email;
			}
		}
		return implode( ', ', array_slice( array_values( $out ), 0, 20 ) );
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$enums = array(
			'appearance'     => array( 'auto', 'light', 'dark' ),
			'default_period' => array_diff( Period::PRESETS, array( 'custom' ) ),
			'ask_mode'       => array( 'ai', 'rules' ),
			'location_basis' => array( 'billing', 'shipping' ),
		);
		foreach ( $enums as $key => $values ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $values, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		foreach ( array( 'explain_answers', 'send_customer_names', 'alerts_enabled', 'dashboard_widget' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['history_days'] ) ) {
			$out['history_days'] = min( 730, max( 7, (int) $input['history_days'] ) );
		}
		if ( isset( $input['alert_recipients'] ) ) {
			$out['alert_recipients'] = self::clean_emails( wp_unslash( (string) $input['alert_recipients'] ) );
		}
		return $out;
	}
}
