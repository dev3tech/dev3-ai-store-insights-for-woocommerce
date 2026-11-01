<?php
/**
 * E-mail rendering (inline-styled tables: e-mail clients drop SVG) and sending for digests and alerts.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Insights;

use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Mailer.
 */
class Mailer {

	const INK   = '#1f2328';
	const MUTED = '#5f6670';
	const LINE  = '#e3e6eb';
	const BAR   = '#2a78d6';
	const PREV  = '#c3c2b7';
	const UP    = '#006300';
	const DOWN  = '#b42318';
	const BG    = '#f4f5f7';
	const BRAND = '#4f46e5';
	const RAMP  = array( '#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5', '#2a78d6', '#256abf', '#1c5cab' );

	/**
	 * Plain-text body for the next wp_mail call.
	 *
	 * @var string
	 */
	private static $alt = '';

	/**
	 * Formats a cell value.
	 *
	 * @param mixed  $value  Value.
	 * @param string $format Format.
	 * @return string
	 */
	public static function value( $value, $format ) {
		if ( null === $value || '' === $value ) {
			return '–';
		}
		if ( 'text' === $format ) {
			return (string) $value;
		}
		if ( 'change' === $format ) {
			return ( $value >= 0 ? '+' : '' ) . Store::format( $value, 'percent' );
		}
		return Store::format( (float) $value, 'auto' === $format ? 'number' : $format );
	}

	/**
	 * One result as an e-mail block.
	 *
	 * @param array $result Result.
	 * @param array $opts   { title }.
	 * @return string HTML.
	 */
	public static function result_html( array $result, array $opts = array() ) {
		$title = isset( $opts['title'] ) ? $opts['title'] : $result['title'];
		$sub   = isset( $result['period']['label'] ) ? $result['period']['label'] . ( ! empty( $result['period']['range'] ) ? ' · ' . $result['period']['range'] : '' ) : '';
		$html  = '<tr><td style="padding:20px 24px 4px;font:600 16px/1.3 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . '">' . esc_html( $title ) . '</td></tr>';
		$html .= '<tr><td style="padding:0 24px 12px;font:13px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::MUTED . '">' . esc_html( $sub ) . '</td></tr>';
		$html .= '<tr><td style="padding:0 24px 16px">' . self::chart_html( $result ) . '</td></tr>';
		return $html;
	}

	/**
	 * Chart as e-mail-safe HTML.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function chart_html( array $result ) {
		$font = 'font:13px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK;
		$type = $result['chart']['type'];
		if ( empty( $result['rows'] ) ) {
			return '<p style="' . $font . ';color:' . self::MUTED . ';margin:0">' . esc_html__( 'No data for this period.', 'dev3-ai-store-insights-for-woocommerce' ) . '</p>';
		}
		if ( 'kpi' === $type ) {
			return self::kpi_html( $result['chart']['items'] );
		}
		if ( 'bar' === $type || 'share' === $type ) {
			$key  = $result['chart']['value'];
			$max  = max( 0.0001, max( array_map( 'floatval', wp_list_pluck( $result['rows'], $key ) ) ) );
			$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">';
			foreach ( array_slice( $result['rows'], 0, 10 ) as $row ) {
				$pct   = max( 1, (int) round( (float) $row[ $key ] / $max * 100 ) );
				$html .= '<tr><td style="' . $font . ';padding:6px 0 2px">' . esc_html( $row['name'] ) . '</td><td align="right" style="' . $font . ';padding:6px 0 2px;font-weight:600;white-space:nowrap">' . esc_html( self::value( $row[ $key ], $result['chart']['format'] ) ) . '</td></tr>';
				$html .= '<tr><td colspan="2" style="padding:0 0 4px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td width="' . $pct . '%" style="background:' . self::BAR . ';height:8px;border-radius:0 4px 4px 0;font-size:0;line-height:0">&nbsp;</td><td style="font-size:0;line-height:0">&nbsp;</td></tr></table></td></tr>';
			}
			return $html . '</table>';
		}
		if ( 'line' === $type && count( $result['rows'] ) < 3 ) {
			return self::table_html( $result );
		}
		if ( 'line' === $type ) {
			$rows = $result['rows'];
			if ( count( $rows ) > 31 ) {
				$rows = array_slice( $rows, -31 );
			}
			$vals = array_map( 'floatval', wp_list_pluck( $rows, 'value' ) );
			$max  = max( 0.0001, max( $vals ) );
			$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-bottom:1px solid ' . self::LINE . '"><tr>';
			foreach ( $rows as $row ) {
				$h     = max( 1, (int) round( (float) $row['value'] / $max * 80 ) );
				$html .= '<td valign="bottom" style="padding:0 1px;height:84px" title="' . esc_attr( $row['label'] . ': ' . self::value( $row['value'], $result['chart']['format'] ) ) . '"><div style="background:' . self::BAR . ';height:' . $h . 'px;border-radius:3px 3px 0 0;font-size:0;line-height:0">&nbsp;</div></td>';
			}
			$html .= '</tr></table>';
			$first = reset( $rows );
			$last  = end( $rows );
			$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="' . $font . ';color:' . self::MUTED . ';font-size:12px">' . esc_html( $first['label'] ) . '</td><td align="right" style="' . $font . ';color:' . self::MUTED . ';font-size:12px">' . esc_html( $last['label'] ) . '</td></tr></table>';
			$s     = $result['summary'];
			if ( $s ) {
				$html .= '<p style="' . $font . ';margin:8px 0 0">' . esc_html( $s['label'] ) . ': <strong>' . esc_html( self::value( $s['value'], $s['format'] ) ) . '</strong>' . self::delta_html( $s['change'] ?? null, 'refunds' !== $s['metric'] ) . '</p>';
			}
			return $html;
		}
		if ( 'heatmap' === $type ) {
			$cells = $result['chart']['cells'];
			$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="2" style="' . $font . ';font-size:12px"><tr><th align="left" style="color:' . self::MUTED . ';font-weight:600">' . esc_html__( 'Cohort', 'dev3-ai-store-insights-for-woocommerce' ) . '</th>';
			foreach ( $result['columns'] as $col ) {
				if ( in_array( $col['key'], $cells, true ) ) {
					$html .= '<th style="color:' . self::MUTED . ';font-weight:600">' . esc_html( $col['label'] ) . '</th>';
				}
			}
			$html .= '</tr>';
			foreach ( $result['rows'] as $row ) {
				$html .= '<tr><td style="white-space:nowrap">' . esc_html( $row['cohort'] ) . ' (' . (int) $row['size'] . ')</td>';
				foreach ( $cells as $cell ) {
					$v = $row[ $cell ];
					if ( null === $v ) {
						$html .= '<td></td>';
						continue;
					}
					$step  = (int) min( 9, max( 0, round( (float) $v / 100 * 9 ) ) );
					$ink   = $step >= 6 ? '#ffffff' : self::INK;
					$html .= '<td align="center" style="background:' . self::RAMP[ $step ] . ';color:' . $ink . ';padding:4px 2px;border-radius:3px">' . esc_html( self::value( $v, $result['chart']['format'] ) ) . '</td>';
				}
				$html .= '</tr>';
			}
			return $html . '</table>';
		}
		return self::table_html( $result );
	}

	/**
	 * Generic table.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function table_html( array $result ) {
		$font = 'font:13px/1.4 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK;
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;' . $font . '"><tr>';
		foreach ( $result['columns'] as $col ) {
			$html .= '<th align="' . ( 'text' === $col['format'] ? 'left' : 'right' ) . '" style="padding:6px 4px;border-bottom:1px solid ' . self::LINE . ';color:' . self::MUTED . ';font-weight:600">' . esc_html( $col['label'] ) . '</th>';
		}
		$html .= '</tr>';
		foreach ( array_slice( $result['rows'], 0, 10 ) as $row ) {
			$html .= '<tr>';
			foreach ( $result['columns'] as $col ) {
				$format = 'auto' === $col['format'] && isset( $row['format'] ) ? $row['format'] : $col['format'];
				$html  .= '<td align="' . ( 'text' === $col['format'] ? 'left' : 'right' ) . '" style="padding:6px 4px;border-bottom:1px solid ' . self::LINE . '">' . esc_html( self::value( $row[ $col['key'] ] ?? null, $format ) ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Delta badge.
	 *
	 * @param float|null $change  Change %.
	 * @param bool       $up_good Whether up is good.
	 * @return string
	 */
	private static function delta_html( $change, $up_good = true ) {
		if ( null === $change ) {
			return '';
		}
		$good  = $up_good ? $change >= 0 : $change <= 0;
		$arrow = $change >= 0 ? '▲' : '▼';
		return ' <span style="color:' . ( $good ? self::UP : self::DOWN ) . ';font-weight:600;white-space:nowrap">' . $arrow . ' ' . esc_html( self::value( $change, 'change' ) ) . '</span>';
	}

	/**
	 * KPI tiles (2 per row).
	 *
	 * @param array $items KPI items.
	 * @return string
	 */
	public static function kpi_html( array $items ) {
		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0 8px">';
		foreach ( array_chunk( $items, 2 ) as $pair ) {
			$html .= '<tr>';
			foreach ( $pair as $i => $item ) {
				$html .= '<td width="50%" valign="top" style="padding:12px 14px;border:1px solid ' . self::LINE . ';border-radius:8px;' . ( 0 === $i ? '' : '' ) . '"><div style="font:12px/1.3 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::MUTED . '">' . esc_html( $item['label'] ) . '</div><div style="font:600 20px/1.3 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . ';margin-top:2px">' . esc_html( self::value( $item['value'], $item['format'] ) ) . '</div><div style="font:12px/1.3 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin-top:2px">' . self::delta_html( $item['change'], 'up' === $item['good'] ) . '</div></td>';
				if ( 0 === $i ) {
					$html .= '<td width="8" style="font-size:0">&nbsp;</td>';
				}
			}
			if ( 1 === count( $pair ) ) {
				$html .= '<td width="50%"></td>';
			}
			$html .= '</tr>';
		}
		return $html . '</table>';
	}

	/**
	 * Full e-mail document.
	 *
	 * @param string $title   Title.
	 * @param string $intro   Intro paragraph (plain text).
	 * @param string $blocks  Inner rows HTML.
	 * @param string $cta_url Button URL.
	 * @return string
	 */
	public static function wrap( $title, $intro, $blocks, $cta_url = '' ) {
		$font  = '-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif';
		$html  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title></head>';
		$html .= '<body style="margin:0;padding:0;background:' . self::BG . '"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . self::BG . '"><tr><td align="center" style="padding:24px 12px">';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:12px">';
		$html .= '<tr><td style="padding:24px 24px 4px;font:600 12px/1.3 ' . $font . ';letter-spacing:.04em;text-transform:uppercase;color:' . self::BRAND . '">' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</td></tr>';
		$html .= '<tr><td style="padding:0 24px 8px;font:700 22px/1.3 ' . $font . ';color:' . self::INK . '">' . esc_html( $title ) . '</td></tr>';
		if ( '' !== $intro ) {
			$html .= '<tr><td style="padding:0 24px 8px;font:14px/1.55 ' . $font . ';color:' . self::INK . '">' . nl2br( esc_html( $intro ) ) . '</td></tr>';
		}
		$html .= $blocks;
		if ( '' !== $cta_url ) {
			$html .= '<tr><td style="padding:8px 24px 24px"><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:' . self::BRAND . ';color:#ffffff;text-decoration:none;font:600 14px/1 ' . $font . ';padding:12px 18px;border-radius:8px">' . esc_html__( 'Open Store Insights', 'dev3-ai-store-insights-for-woocommerce' ) . '</a></td></tr>';
		}
		$html .= '</table><p style="font:12px/1.4 ' . $font . ';color:' . self::MUTED . ';margin:12px 0 0">' . esc_html__( 'Sent by DEV3 AI Store Insights for WooCommerce. Change or stop these e-mails in Store Insights.', 'dev3-ai-store-insights-for-woocommerce' ) . '</p>';
		return $html . '</td></tr></table></body></html>';
	}

	/**
	 * Plain-text version of results.
	 *
	 * @param array $results Results.
	 * @return string
	 */
	public static function text( array $results ) {
		$out = array();
		foreach ( $results as $result ) {
			$out[] = strtoupper( $result['title'] ) . ' (' . ( $result['period']['label'] ?? '' ) . ')';
			if ( 'kpi' === $result['chart']['type'] ) {
				foreach ( $result['chart']['items'] as $item ) {
					$out[] = '- ' . $item['label'] . ': ' . self::value( $item['value'], $item['format'] ) . ( null !== $item['change'] ? ' (' . self::value( $item['change'], 'change' ) . ')' : '' );
				}
			} else {
				foreach ( array_slice( $result['rows'], 0, 10 ) as $row ) {
					$cells = array();
					foreach ( $result['columns'] as $col ) {
						$cells[] = self::value( $row[ $col['key'] ] ?? null, 'auto' === $col['format'] && isset( $row['format'] ) ? $row['format'] : $col['format'] );
					}
					$out[] = '- ' . implode( ' | ', $cells );
				}
			}
			$out[] = '';
		}
		return implode( "\n", $out );
	}

	/**
	 * Sends an HTML e-mail with a plain-text alternative.
	 *
	 * @param string|array $to      Recipients.
	 * @param string       $subject Subject.
	 * @param string       $html    HTML.
	 * @param string       $text    Plain text.
	 * @return bool
	 */
	public static function send( $to, $subject, $html, $text ) {
		self::$alt = $text;
		$hook      = array( self::class, 'alt_body' );
		add_action( 'phpmailer_init', $hook );
		$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $hook );
		self::$alt = '';
		return (bool) $ok;
	}

	/**
	 * Adds AltBody.
	 *
	 * @param object $mailer PHPMailer.
	 */
	public static function alt_body( $mailer ) {
		if ( '' !== self::$alt && is_object( $mailer ) ) {
			$mailer->AltBody = self::$alt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
	}

	/**
	 * Recipients for alerts.
	 *
	 * @param array $rule Rule.
	 * @return string[]
	 */
	public static function alert_recipients( array $rule ) {
		$raw = '' !== (string) ( $rule['recipients'] ?? '' ) ? $rule['recipients'] : (string) Settings::get( 'alert_recipients', '' );
		$out = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( ! $out ) {
			$out = array( get_option( 'admin_email' ) );
		}
		return $out;
	}

	/**
	 * Alert e-mail.
	 *
	 * @param array $alert Alert.
	 * @param array $rule  Rule.
	 * @return bool
	 */
	public static function alert( array $alert, array $rule ) {
		/**
		 * Alert recipients.
		 *
		 * @param string[] $emails Emails.
		 * @param array    $alert  Alert.
		 */
		$to = (array) apply_filters( 'dev3si_alert_recipients', self::alert_recipients( $rule ), $alert );
		if ( ! $to ) {
			return false;
		}
		$exp    = Alerts::template( $alert );
		$blocks = '';
		if ( ! empty( $alert['detail']['series'] ) ) {
			$blocks .= self::result_html(
				array(
					'title'   => $alert['label'],
					'period'  => array( 'label' => __( 'Last 29 days', 'dev3-ai-store-insights-for-woocommerce' ) ),
					'chart'   => array(
						'type'   => 'line',
						'format' => $alert['format'],
					),
					'rows'    => $alert['detail']['series'],
					'summary' => null,
				)
			);
		}
		$lines = $exp['highlights'];
		if ( $lines ) {
			$blocks .= '<tr><td style="padding:0 24px 16px;font:13px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:' . self::INK . '"><ul style="margin:0;padding-left:18px"><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul></td></tr>';
		}
		/* translators: 1: rule name, 2: day */
		$subject = sprintf( __( 'Store alert: %1$s (%2$s)', 'dev3-ai-store-insights-for-woocommerce' ), $alert['rule'], $alert['day'] );
		$html    = self::wrap( $exp['headline'], $exp['summary'], $blocks, admin_url( 'admin.php?page=' . DEV3SI_SLUG . '#/alerts' ) );
		return self::send( $to, $subject, $html, $exp['headline'] . "\n\n" . $exp['summary'] . "\n\n" . implode( "\n", $lines ) );
	}
}
