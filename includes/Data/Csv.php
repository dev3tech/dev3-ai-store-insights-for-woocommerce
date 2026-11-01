<?php
/**
 * CSV export of a result (formula-injection safe, UTF-8 BOM for Excel).
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Data;

defined( 'ABSPATH' ) || exit;

/**
 * CSV writer.
 */
class Csv {

	/**
	 * Neutralises cells that spreadsheets would run as formulas.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function cell( $value ) {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Builds the CSV text.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function build( array $result ) {
		$lines   = array();
		$columns = $result['columns'];
		$header  = array();
		foreach ( $columns as $col ) {
			$header[] = self::cell( $col['label'] );
		}
		$lines[] = self::line( $header );
		foreach ( $result['rows'] as $row ) {
			$cells = array();
			foreach ( $columns as $col ) {
				$cells[] = self::cell( isset( $row[ $col['key'] ] ) ? $row[ $col['key'] ] : null );
			}
			$lines[] = self::line( $cells );
		}
		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line.
	 *
	 * @param string[] $cells Cells.
	 * @return string
	 */
	private static function line( array $cells ) {
		return implode(
			',',
			array_map(
				static function ( $cell ) {
					return preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
				},
				$cells
			)
		);
	}

	/**
	 * File name.
	 *
	 * @param array $result Result.
	 * @return string
	 */
	public static function filename( array $result ) {
		return sanitize_file_name( 'store-insights-' . $result['fn'] . '-' . gmdate( 'Y-m-d' ) . '.csv' );
	}
}
