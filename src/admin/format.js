import { __, sprintf } from '@wordpress/i18n';

/**
 * Value formatting for charts, tables and tiles. The currency comes from WooCommerce (meta.currency).
 */
let currency = { code: 'USD', symbol: '$', decimals: 2, position: 'left' };

export function setCurrency( next ) {
	if ( next && next.code ) {
		currency = next;
	}
}

export function getCurrency() {
	return currency;
}

const num = ( v ) => ( typeof v === 'number' ? v : parseFloat( v ) || 0 );

/**
 * Money: "$1,284.50", compact "$1.3K".
 *
 * @param {number}  value   Value.
 * @param {boolean} compact Compact notation.
 * @return {string} Formatted.
 */
export function money( value, compact = false ) {
	const v = num( value );
	const abs = Math.abs( v );
	let digits;
	if ( compact ) {
		digits = formatCompact( abs );
	} else {
		const decimals = abs >= 100 ? 0 : currency.decimals;
		digits = abs.toLocaleString( undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals } );
	}
	const sign = v < 0 ? '-' : '';
	switch ( currency.position ) {
		case 'right':
			return `${ sign }${ digits }${ currency.symbol }`;
		case 'right_space':
			return `${ sign }${ digits } ${ currency.symbol }`;
		case 'left_space':
			return `${ sign }${ currency.symbol } ${ digits }`;
		default:
			return `${ sign }${ currency.symbol }${ digits }`;
	}
}

/**
 * 1,284 / 12.9K / 4.2M.
 *
 * @param {number} value Value.
 * @return {string} Compact number.
 */
export function formatCompact( value ) {
	const v = num( value );
	const abs = Math.abs( v );
	const units = [
		[ 1e9, 'B' ],
		[ 1e6, 'M' ],
		[ 1e3, 'K' ],
	];
	for ( const [ size, suffix ] of units ) {
		if ( abs >= size ) {
			const scaled = v / size;
			return `${ scaled.toLocaleString( undefined, { maximumFractionDigits: Math.abs( scaled ) >= 100 ? 0 : 1 } ) }${ suffix }`;
		}
	}
	return v.toLocaleString( undefined, { maximumFractionDigits: abs < 10 && abs % 1 ? 1 : 0 } );
}

export function number( value, compact = false ) {
	const v = num( value );
	return compact ? formatCompact( v ) : v.toLocaleString( undefined, { maximumFractionDigits: v % 1 ? 1 : 0 } );
}

export function percent( value ) {
	const v = num( value );
	return `${ v.toLocaleString( undefined, { maximumFractionDigits: Math.abs( v ) < 10 ? 1 : 0 } ) }%`;
}

export function days( value ) {
	if ( value === null || value === undefined ) {
		return '–';
	}
	const v = num( value );
	/* translators: %s: number of days */
	return sprintf( __( '%s days', 'dev3-ai-store-insights-for-woocommerce' ), v.toLocaleString( undefined, { maximumFractionDigits: v < 10 ? 1 : 0 } ) );
}

export function signed( value ) {
	if ( value === null || value === undefined ) {
		return '–';
	}
	const v = num( value );
	return `${ v > 0 ? '+' : '' }${ percent( v ) }`;
}

/**
 * Formats by format id.
 *
 * @param {*}       value   Value.
 * @param {string}  format  money|number|percent|days|change|text.
 * @param {boolean} compact Compact.
 * @return {string} Text.
 */
export function fmt( value, format, compact = false ) {
	if ( value === null || value === undefined || value === '' ) {
		return '–';
	}
	switch ( format ) {
		case 'money':
			return money( value, compact );
		case 'percent':
			return percent( value );
		case 'days':
			return days( value );
		case 'change':
			return signed( value );
		case 'number':
			return number( value, compact );
		default:
			return String( value );
	}
}

/**
 * Nice axis ticks between 0 (or min) and max.
 *
 * @param {number} min   Min.
 * @param {number} max   Max.
 * @param {number} count Wanted ticks.
 * @return {number[]} Ticks.
 */
export function niceTicks( min, max, count = 4 ) {
	if ( ! isFinite( min ) || ! isFinite( max ) ) {
		return [ 0 ];
	}
	if ( max === min ) {
		max = min === 0 ? 1 : min * 1.2;
	}
	const span = max - min;
	const raw = span / Math.max( 1, count );
	const mag = Math.pow( 10, Math.floor( Math.log10( raw ) ) );
	const norm = raw / mag;
	const step = ( norm >= 5 ? 10 : norm >= 2 ? 5 : norm >= 1 ? 2 : 1 ) * mag;
	const start = Math.floor( min / step ) * step;
	const ticks = [];
	for ( let t = start; t <= max + step * 0.999; t += step ) {
		ticks.push( Math.round( t / step ) * step );
		if ( ticks.length > 12 ) {
			break;
		}
	}
	return ticks;
}

/**
 * Cuts a label to fit roughly `width` px at ~7px per character.
 *
 * @param {string} text  Text.
 * @param {number} width Width px.
 * @return {string} Text.
 */
export function fit( text, width ) {
	const s = String( text || '' );
	const max = Math.max( 4, Math.floor( width / 7 ) );
	return s.length > max ? `${ s.slice( 0, max - 1 ) }…` : s;
}
