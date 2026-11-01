/**
 * SVG charts: line (time series + comparison), ranked bars, part-to-whole share bar, cohort heatmap,
 * KPI tiles and sparklines. Colours are CSS tokens (--dev3si-c-*) selected separately for light and
 * dark (see admin.css). Every chart has a hover/focus tooltip and keyboard navigation; values are also
 * available through the table view.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, cx } from '../core3/ui';
import { fit, fmt, niceTicks } from './format';

/**
 * Width of an element (ResizeObserver), for crisp responsive SVGs.
 *
 * @param {number} fallback Width before measuring.
 * @return {Array} [ ref, width ].
 */
export function useWidth( fallback = 600 ) {
	const ref = useRef( null );
	const [ width, setWidth ] = useState( fallback );
	useEffect( () => {
		const node = ref.current;
		if ( ! node ) {
			return undefined;
		}
		const measure = () => {
			const w = Math.floor( node.getBoundingClientRect().width );
			if ( w > 0 ) {
				setWidth( w );
			}
		};
		measure();
		if ( typeof window.ResizeObserver === 'undefined' ) {
			window.addEventListener( 'resize', measure );
			return () => window.removeEventListener( 'resize', measure );
		}
		const ro = new window.ResizeObserver( measure );
		ro.observe( node );
		return () => ro.disconnect();
	}, [] );
	return [ ref, width ];
}

/**
 * Floating tooltip (HTML, positioned inside the chart box).
 *
 * @param {Object} props       Props.
 * @param {Object} props.tip   { x, y, title, rows: [ { label, value, color, line } ] } or null.
 * @param {number} props.width Box width (keeps the tooltip inside).
 * @return {Element|null} Tooltip.
 */
export function Tooltip( { tip, width } ) {
	if ( ! tip ) {
		return null;
	}
	const left = Math.max( 4, Math.min( tip.x + 12, width - 196 ) );
	return (
		<div className="dev3si-tip" style={ { left, top: Math.max( 0, tip.y - 8 ) } } role="status" aria-live="polite">
			{ tip.title && <div className="dev3si-tip__title">{ tip.title }</div> }
			{ tip.rows.map( ( r, i ) => (
				<div className="dev3si-tip__row" key={ i }>
					{ r.color && <span className={ cx( 'dev3si-tip__key', r.line && 'is-line' ) } style={ { background: r.color } } aria-hidden="true" /> }
					<strong className="dev3si-tip__value">{ r.value }</strong>
					{ r.label && <span className="dev3si-tip__label">{ r.label }</span> }
				</div>
			) ) }
		</div>
	);
}

const SERIES = [ 'var(--dev3si-c-1)', 'var(--dev3si-c-2)' ];

/**
 * Line chart for one series over time, with an optional comparison series in the de-emphasis grey.
 *
 * @param {Object}   props        Props.
 * @param {Object[]} props.rows   Rows ({ label, value, previous, previous_label }).
 * @param {Object[]} props.series [ { key, label, muted } ].
 * @param {string}   props.format Value format.
 * @param {number}   props.height Height px.
 * @param {string}   props.label  Accessible name.
 * @return {Element} Chart.
 */
export function LineChart( { rows, series, format, height = 260, label } ) {
	const [ ref, width ] = useWidth();
	const [ active, setActive ] = useState( null );
	const n = rows.length;
	const main = series.find( ( s ) => ! s.muted ) || series[ 0 ];
	const values = [];
	rows.forEach( ( r ) => series.forEach( ( s ) => r[ s.key ] !== null && r[ s.key ] !== undefined && values.push( Number( r[ s.key ] ) ) ) );
	const minV = Math.min( 0, ...values );
	const maxV = Math.max( 0, ...values );
	const ticks = niceTicks( minV, maxV, height > 200 ? 4 : 3 );
	const lo = ticks[ 0 ];
	const hi = ticks[ ticks.length - 1 ] || 1;
	const tickW = Math.max( ...ticks.map( ( t ) => fmt( t, format, true ).length ) ) * 7 + 10;
	const pad = { l: Math.max( 34, tickW ), r: 16, t: 20, b: 28 };
	const iw = Math.max( 10, width - pad.l - pad.r );
	const ih = height - pad.t - pad.b;
	const x = ( i ) => pad.l + ( n <= 1 ? iw / 2 : ( i / ( n - 1 ) ) * iw );
	const y = ( v ) => pad.t + ih - ( ( Number( v ) - lo ) / ( hi - lo || 1 ) ) * ih;
	const path = ( key ) =>
		rows
			.map( ( r, i ) => ( r[ key ] === null || r[ key ] === undefined ? null : `${ x( i ).toFixed( 1 ) },${ y( r[ key ] ).toFixed( 1 ) }` ) )
			.filter( Boolean )
			.join( ' L' );
	const step = Math.max( 1, Math.ceil( n / Math.max( 1, Math.floor( iw / 72 ) ) ) );
	const colorOf = ( s ) => ( s.muted ? 'var(--dev3si-c-muted)' : SERIES[ 0 ] );
	const last = n - 1;
	const pick = ( clientX ) => {
		const box = ref.current.getBoundingClientRect();
		const rel = clientX - box.left - pad.l;
		setActive( Math.max( 0, Math.min( last, Math.round( n <= 1 ? 0 : ( rel / iw ) * ( n - 1 ) ) ) ) );
	};
	const onKey = ( e ) => {
		const map = { ArrowRight: 1, ArrowLeft: -1 };
		if ( map[ e.key ] ) {
			e.preventDefault();
			setActive( ( a ) => Math.max( 0, Math.min( last, ( a === null ? last : a ) + map[ e.key ] ) ) );
		} else if ( e.key === 'Home' || e.key === 'End' ) {
			e.preventDefault();
			setActive( e.key === 'Home' ? 0 : last );
		} else if ( e.key === 'Escape' ) {
			setActive( null );
		}
	};
	const tip =
		active !== null && rows[ active ]
			? {
					x: x( active ),
					y: pad.t,
					title: rows[ active ].label,
					rows: series
						.filter( ( s ) => rows[ active ][ s.key ] !== null && rows[ active ][ s.key ] !== undefined )
						.map( ( s ) => ( {
							color: colorOf( s ),
							line: true,
							value: fmt( rows[ active ][ s.key ], format ),
							label: s.muted && rows[ active ].previous_label ? `${ s.label } (${ rows[ active ].previous_label })` : s.label,
						} ) ),
			  }
			: null;
	const lastVal = n ? rows[ last ][ main.key ] : null;
	return (
		<div
			ref={ ref }
			className="dev3si-chart dev3si-chart--line"
			tabIndex={ 0 }
			role="group"
			aria-label={ `${ label || '' } ${ __( 'Use the left and right arrow keys to read each value.', 'dev3-ai-store-insights-for-woocommerce' ) }` }
			onKeyDown={ onKey }
			onFocus={ () => setActive( ( a ) => ( a === null ? last : a ) ) }
			onBlur={ () => setActive( null ) }
		>
			<svg width={ width } height={ height } viewBox={ `0 0 ${ width } ${ height }` } aria-hidden="true" focusable="false">
				{ ticks.map( ( t ) => (
					<g key={ t }>
						<line x1={ pad.l } x2={ width - pad.r } y1={ y( t ) } y2={ y( t ) } className={ t === 0 ? 'dev3si-axis' : 'dev3si-grid' } />
						<text x={ pad.l - 8 } y={ y( t ) } dy="0.32em" textAnchor="end" className="dev3si-tick">
							{ fmt( t, format, true ) }
						</text>
					</g>
				) ) }
				{ rows.map( ( r, i ) =>
					i % step === 0 || ( i === last && ( last % step ) / step > 0.6 ) ? (
						<text key={ i } x={ x( i ) } y={ height - 8 } textAnchor={ n > 1 && i === 0 ? 'start' : i === last && n > 1 ? 'end' : 'middle' } className="dev3si-tick">
							{ r.label }
						</text>
					) : null
				) }
				{ n > 1 && <path d={ `M${ x( 0 ) },${ y( Math.max( lo, 0 ) ) } L${ path( main.key ) } L${ x( last ) },${ y( Math.max( lo, 0 ) ) } Z` } className="dev3si-area" /> }
				{ series
					.slice()
					.sort( ( a, b ) => ( a.muted === b.muted ? 0 : a.muted ? -1 : 1 ) )
					.map( ( s ) => ( n > 1 ? <path key={ s.key } d={ `M${ path( s.key ) }` } className={ cx( 'dev3si-line', s.muted && 'is-muted' ) } /> : null ) ) }
				{ n > 0 && lastVal !== null && (
					<>
						<circle cx={ x( last ) } cy={ y( lastVal ) } r="4" className="dev3si-dot" />
						<text x={ x( last ) } y={ y( lastVal ) - 10 } textAnchor="end" className="dev3si-endlabel">
							{ fmt( lastVal, format, true ) }
						</text>
					</>
				) }
				{ active !== null && (
					<g>
						<line x1={ x( active ) } x2={ x( active ) } y1={ pad.t } y2={ pad.t + ih } className="dev3si-cross" />
						{ series.map( ( s ) =>
							rows[ active ][ s.key ] === null || rows[ active ][ s.key ] === undefined ? null : (
								<circle key={ s.key } cx={ x( active ) } cy={ y( rows[ active ][ s.key ] ) } r="4" className={ cx( 'dev3si-dot', s.muted && 'is-muted' ) } />
							)
						) }
					</g>
				) }
				<rect
					x={ pad.l }
					y={ pad.t }
					width={ iw }
					height={ ih }
					fill="transparent"
					onPointerMove={ ( e ) => pick( e.clientX ) }
					onPointerDown={ ( e ) => pick( e.clientX ) }
					onPointerLeave={ () => setActive( null ) }
				/>
			</svg>
			<Tooltip tip={ tip } width={ width } />
		</div>
	);
}

/**
 * Horizontal ranked bars (label + value above each bar). One sequential hue: length carries the value.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.rows    Rows.
 * @param {string}   props.label   Label key.
 * @param {string}   props.value   Value key.
 * @param {string}   props.format  Format.
 * @param {Object[]} props.columns Columns (secondary values shown in the tooltip).
 * @param {string}   props.name    Accessible name.
 * @param {number}   props.max     Max rows.
 * @return {Element} Chart.
 */
export function BarChart( { rows, label = 'name', value, format, columns = [], name, max = 12 } ) {
	const [ ref, width ] = useWidth();
	const [ active, setActive ] = useState( null );
	const list = rows.slice( 0, max );
	const top = Math.max( 0.0001, ...list.map( ( r ) => Math.max( 0, Number( r[ value ] ) || 0 ) ) );
	const rowH = 44;
	const barH = 12;
	const height = list.length * rowH + 4;
	const extra = columns.filter( ( c ) => c.key !== label && c.key !== value && c.format !== 'text' );
	const tipFor = ( r, i ) => ( {
		x: 0,
		y: i * rowH + rowH,
		title: r[ label ],
		rows: [
			{ value: fmt( r[ value ], format ), label: ( columns.find( ( c ) => c.key === value ) || {} ).label, color: 'var(--dev3si-c-1)' },
			...extra.map( ( c ) => ( { value: fmt( r[ c.key ], c.format ), label: c.label } ) ),
		],
	} );
	return (
		<div ref={ ref } className="dev3si-chart dev3si-chart--bars" role="group" aria-label={ name }>
			<svg width={ width } height={ height } viewBox={ `0 0 ${ width } ${ height }` } role="list" aria-label={ name }>
				{ list.map( ( r, i ) => {
					const v = Math.max( 0, Number( r[ value ] ) || 0 );
					const w = Math.max( v > 0 ? 3 : 0, ( v / top ) * width );
					const y0 = i * rowH;
					const valueText = fmt( r[ value ], format );
					const labelW = width - valueText.length * 7.5 - 16;
					const radius = Math.min( 4, w / 2 );
					const d = w > 0 ? `M0,${ y0 + 22 } H${ w - radius } A${ radius },${ radius } 0 0 1 ${ w },${ y0 + 22 + radius } V${ y0 + 22 + barH - radius } A${ radius },${ radius } 0 0 1 ${ w - radius },${ y0 + 22 + barH } H0 Z` : '';
					return (
						<g
							key={ i }
							role="listitem"
							tabIndex={ 0 }
							aria-label={ `${ r[ label ] }: ${ valueText }${ r.share !== undefined && r.share !== null ? `, ${ fmt( r.share, 'percent' ) }` : '' }` }
							className={ cx( 'dev3si-bar', active === i && 'is-active' ) }
							onPointerEnter={ () => setActive( i ) }
							onPointerLeave={ () => setActive( null ) }
							onFocus={ () => setActive( i ) }
							onBlur={ () => setActive( null ) }
						>
							<rect x="0" y={ y0 } width={ width } height={ rowH } fill="transparent" />
							<text x="0" y={ y0 + 14 } className="dev3si-barlabel">
								<title>{ r[ label ] }</title>
								{ fit( r[ label ], labelW ) }
							</text>
							<text x={ width } y={ y0 + 14 } textAnchor="end" className="dev3si-barvalue">
								{ valueText }
							</text>
							<rect x="0" y={ y0 + 22 } width={ width } height={ barH } rx="4" className="dev3si-bartrack" />
							{ d && <path d={ d } className="dev3si-barfill" /> }
						</g>
					);
				} ) }
			</svg>
			<Tooltip tip={ active !== null && list[ active ] ? tipFor( list[ active ], active ) : null } width={ width } />
		</div>
	);
}

/**
 * Part-to-whole: one stacked bar (2px surface gaps) plus a legend with values and shares.
 *
 * @param {Object}   props        Props.
 * @param {Object[]} props.rows   Rows.
 * @param {string}   props.label  Label key.
 * @param {string}   props.value  Value key.
 * @param {string}   props.format Format.
 * @param {string}   props.name   Accessible name.
 * @return {Element} Chart.
 */
export function ShareBar( { rows, label = 'name', value, format, name } ) {
	const [ ref, width ] = useWidth();
	const [ active, setActive ] = useState( null );
	const total = rows.reduce( ( s, r ) => s + Math.max( 0, Number( r[ value ] ) || 0 ), 0 );
	const gap = 2;
	const visible = rows.filter( ( r ) => Number( r[ value ] ) > 0 );
	const usable = width - gap * Math.max( 0, visible.length - 1 );
	let cursor = 0;
	const segs = rows.map( ( r, i ) => {
		const v = Math.max( 0, Number( r[ value ] ) || 0 );
		const w = total > 0 ? ( v / total ) * usable : 0;
		const seg = { r, i, x: cursor, w, pct: total > 0 ? ( v / total ) * 100 : 0 };
		if ( w > 0 ) {
			cursor += w + gap;
		}
		return seg;
	} );
	const color = ( i ) => `var(--dev3si-c-${ ( i % 8 ) + 1 })`;
	return (
		<div ref={ ref } className="dev3si-chart dev3si-chart--share">
			<svg width={ width } height={ 28 } viewBox={ `0 0 ${ width } 28` } role="img" aria-label={ `${ name }: ${ segs.map( ( s ) => `${ s.r[ label ] } ${ fmt( s.pct, 'percent' ) }` ).join( ', ' ) }` }>
				{ total <= 0 && <rect x="0" y="2" width={ width } height="24" rx="4" className="dev3si-bartrack" /> }
				{ segs.map( ( s ) =>
					s.w > 0 ? (
						<rect
							key={ s.i }
							x={ s.x }
							y="2"
							width={ s.w }
							height="24"
							rx={ 4 }
							style={ { fill: color( s.i ) } }
							className={ cx( 'dev3si-seg', active === s.i && 'is-active' ) }
							onPointerEnter={ () => setActive( s.i ) }
							onPointerLeave={ () => setActive( null ) }
						/>
					) : null
				) }
			</svg>
			<ul className="dev3si-legend">
				{ segs.map( ( s ) => (
					<li key={ s.i } className={ cx( active === s.i && 'is-active' ) }>
						<span className="dev3si-legend__swatch" style={ { background: color( s.i ) } } aria-hidden="true" />
						<span className="dev3si-legend__label">{ s.r[ label ] }</span>
						<strong className="dev3si-legend__value">{ fmt( s.r[ value ], format ) }</strong>
						<span className="dev3si-legend__pct">{ fmt( s.pct, 'percent' ) }</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

/**
 * Sequential one-hue heatmap table (cohorts). Ink switches to white on the dark steps.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.rows    Rows.
 * @param {Object[]} props.columns Columns.
 * @param {string[]} props.cells   Cell keys.
 * @param {string}   props.format  Format.
 * @param {string}   props.name    Caption.
 * @return {Element} Heatmap.
 */
export function Heatmap( { rows, columns, cells, format, name } ) {
	const values = [];
	rows.forEach( ( r ) => cells.slice( 1 ).forEach( ( c ) => r[ c ] !== null && r[ c ] !== undefined && values.push( Number( r[ c ] ) ) ) );
	const top = Math.max( 0.0001, ...values );
	const step = ( v, first ) => ( first ? 9 : Math.max( 0, Math.min( 9, Math.round( ( Number( v ) / top ) * 9 ) ) ) );
	return (
		<div className="dev3si-heat">
			<table>
				<caption className="dev3si-ui-sr-only">{ name }</caption>
				<thead>
					<tr>
						{ columns.map( ( c ) => (
							<th key={ c.key } scope="col">
								{ c.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( r, ri ) => (
						<tr key={ ri }>
							<th scope="row">{ r.cohort }</th>
							<td className="dev3si-heat__size">{ fmt( r.size, 'number' ) }</td>
							{ cells.map( ( c, ci ) =>
								r[ c ] === null || r[ c ] === undefined ? (
									<td key={ c } className="dev3si-heat__na">
										<span className="dev3si-ui-sr-only">{ __( 'Not yet', 'dev3-ai-store-insights-for-woocommerce' ) }</span>
									</td>
								) : (
									<td key={ c } className={ `dev3si-heat__cell is-s${ step( r[ c ], ci === 0 && format === 'percent' ) }` } title={ `${ r.cohort } · ${ ( columns.find( ( col ) => col.key === c ) || {} ).label }: ${ fmt( r[ c ], format ) }` }>
										{ fmt( r[ c ], format, format === 'money' ) }
									</td>
								)
							) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * Sparkline (12+ points, de-emphasis line with the latest point in the accent).
 *
 * @param {Object}   props        Props.
 * @param {number[]} props.values Values.
 * @param {number}   props.height Height.
 * @param {string}   props.label  Accessible label.
 * @return {Element|null} Sparkline.
 */
export function Sparkline( { values, height = 32, label } ) {
	const [ ref, width ] = useWidth( 160 );
	if ( ! values || values.length < 2 ) {
		return null;
	}
	const top = Math.max( 0.0001, ...values.map( Number ) );
	const pts = values.map( ( v, i ) => [ ( i / ( values.length - 1 ) ) * ( width - 6 ) + 3, height - 4 - ( Math.max( 0, Number( v ) ) / top ) * ( height - 8 ) ] );
	const d = pts.map( ( p ) => `${ p[ 0 ].toFixed( 1 ) },${ p[ 1 ].toFixed( 1 ) }` ).join( ' L' );
	const end = pts[ pts.length - 1 ];
	return (
		<div ref={ ref } className="dev3si-spark">
			<svg width={ width } height={ height } viewBox={ `0 0 ${ width } ${ height }` } role="img" aria-label={ label }>
				<path d={ `M3,${ height } L${ d } L${ end[ 0 ] },${ height } Z` } className="dev3si-area" />
				<path d={ `M${ d }` } className="dev3si-line is-spark" />
				<circle cx={ end[ 0 ] } cy={ end[ 1 ] } r="3.5" className="dev3si-dot" />
			</svg>
		</div>
	);
}

/**
 * Signed delta with arrow icon + text (never colour alone).
 *
 * @param {Object} props        Props.
 * @param {number} props.change Percent change or null.
 * @param {string} props.good   'up'|'down'.
 * @param {string} props.vs     Comparison label.
 * @return {Element|null} Delta.
 */
export function Delta( { change, good = 'up', vs } ) {
	if ( change === null || change === undefined ) {
		return vs ? <span className="dev3si-delta is-flat">{ __( 'No comparison data', 'dev3-ai-store-insights-for-woocommerce' ) }</span> : null;
	}
	const up = change > 0;
	const flat = change === 0;
	const isGood = flat ? null : ( good === 'up' ) === up;
	return (
		<span className={ cx( 'dev3si-delta', flat ? 'is-flat' : isGood ? 'is-good' : 'is-bad' ) }>
			<span aria-hidden="true">{ flat ? '→' : up ? '▲' : '▼' }</span> { fmt( change, 'change' ) }
			{ vs && <span className="dev3si-delta__vs"> { sprintf( /* translators: %s: comparison period */ __( 'vs %s', 'dev3-ai-store-insights-for-woocommerce' ), vs.toLowerCase() ) }</span> }
			<span className="dev3si-ui-sr-only">{ isGood === null ? '' : isGood ? __( '(better)', 'dev3-ai-store-insights-for-woocommerce' ) : __( '(worse)', 'dev3-ai-store-insights-for-woocommerce' ) }</span>
		</span>
	);
}

/**
 * KPI tiles with deltas; the first tile carries the period sparkline.
 *
 * @param {Object}   props         Props.
 * @param {Object[]} props.items   KPI items.
 * @param {Object[]} props.spark   Spark points.
 * @param {string}   props.compare Comparison label.
 * @return {Element} Tiles.
 */
export function KpiTiles( { items, spark, compare } ) {
	return (
		<div className="dev3si-kpis">
			{ items.map( ( item, i ) => (
				<div key={ item.key } className={ cx( 'dev3si-kpi', i === 0 && 'is-hero' ) }>
					<span className="dev3si-kpi__label">{ item.label }</span>
					<span className="dev3si-kpi__value">{ fmt( item.value, item.format, item.format === 'money' && Math.abs( item.value ) >= 100000 ) }</span>
					<Delta change={ item.change } good={ item.good } vs={ compare } />
					{ i === 0 && spark && spark.length > 2 && <Sparkline values={ spark.map( ( p ) => p.value ) } label={ __( 'Net sales trend in this period', 'dev3-ai-store-insights-for-woocommerce' ) } /> }
				</div>
			) ) }
		</div>
	);
}

/**
 * Icon + label severity badge (status colour never alone).
 *
 * @param {Object} props          Props.
 * @param {string} props.severity low|medium|high.
 * @return {Element} Badge.
 */
export function Severity( { severity } ) {
	const map = {
		high: [ 'error', __( 'High', 'dev3-ai-store-insights-for-woocommerce' ) ],
		medium: [ 'alert', __( 'Medium', 'dev3-ai-store-insights-for-woocommerce' ) ],
		low: [ 'info', __( 'Low', 'dev3-ai-store-insights-for-woocommerce' ) ],
	};
	const [ icon, text ] = map[ severity ] || map.low;
	return (
		<span className={ `dev3si-sev is-${ severity }` }>
			<Icon name={ icon } size={ 14 } /> { text }
		</span>
	);
}
