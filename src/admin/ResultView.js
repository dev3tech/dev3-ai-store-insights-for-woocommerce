import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import config, { restUrl } from '../core3/config';
import { Badge, Button, Card, EmptyState, ErrorNotice, Icon, Inline, ResponsiveTable, cx } from '../core3/ui';
import { BarChart, Heatmap, KpiTiles, LineChart, ShareBar, Delta } from './charts';
import { fmt } from './format';

/**
 * CSV download URL for a query (REST nonce in the query string, served raw by the plugin).
 *
 * @param {string} fn   Function.
 * @param {Object} args Args.
 * @param {Object} over Overrides.
 * @return {string} URL.
 */
export function exportUrl( fn, args, over ) {
	const q = new URLSearchParams( { fn, args: JSON.stringify( args || {} ), _wpnonce: config.nonce || '' } );
	if ( over && over.period ) {
		q.set( 'over', JSON.stringify( over ) );
	}
	return restUrl( `export?${ q.toString() }` );
}

/**
 * Data table of a result (collapses to cards on phones).
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Result.
 * @return {Element} Table.
 */
export function ResultTable( { result } ) {
	const columns = result.columns.map( ( c, i ) => ( {
		key: c.key,
		label: String( c.label ),
		primary: i === 0,
		align: c.format === 'text' ? undefined : 'end',
		sortable: result.chart.type !== 'line' && result.chart.type !== 'heatmap',
		sortValue: ( row ) => row[ c.key ],
		render: ( row ) => {
			const format = c.format === 'auto' ? row.format : c.format;
			if ( format === 'change' ) {
				return <Delta change={ row[ c.key ] } good={ row.good } />;
			}
			return fmt( row[ c.key ], format );
		},
	} ) );
	return (
		<ResponsiveTable
			columns={ columns }
			rows={ result.rows }
			rowKey={ ( row ) => row.key || row.id || row.name || row.cohort || row.label }
			caption={ result.title }
			pageSize={ result.rows.length > 25 ? 25 : 0 }
			empty={ { title: __( 'No rows', 'dev3-ai-store-insights-for-woocommerce' ), description: __( 'Nothing matched this period and filters.', 'dev3-ai-store-insights-for-woocommerce' ) } }
		/>
	);
}

/**
 * The chart for a result.
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Result.
 * @param {number} props.height Line chart height.
 * @return {Element} Chart.
 */
export function ResultChart( { result, height } ) {
	const c = result.chart;
	const name = `${ result.title }, ${ result.period ? result.period.label : '' }`;
	if ( c.type === 'kpi' ) {
		return <KpiTiles items={ c.items } spark={ c.spark } compare={ result.compare_period ? result.compare_period.label : null } />;
	}
	if ( ! result.rows.length ) {
		return <EmptyState compact icon="chart" title={ __( 'No data for this period', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Try a longer period or remove a filter.', 'dev3-ai-store-insights-for-woocommerce' ) } />;
	}
	if ( c.type === 'line' ) {
		return (
			<>
				{ result.extra && <KpiTiles items={ result.extra } compare={ result.compare_period ? result.compare_period.label : null } /> }
				<LineChart rows={ result.rows } series={ c.series } format={ c.format } height={ height } label={ name } />
				{ c.series.length > 1 && (
					<ul className="dev3si-legend is-inline">
						{ c.series.map( ( s ) => (
							<li key={ s.key }>
								<span className={ cx( 'dev3si-legend__line', s.muted && 'is-muted' ) } aria-hidden="true" />
								<span className="dev3si-legend__label">{ s.label }</span>
							</li>
						) ) }
					</ul>
				) }
			</>
		);
	}
	if ( c.type === 'bar' ) {
		return <BarChart rows={ result.rows } label={ c.label } value={ c.value } format={ c.format } columns={ result.columns } name={ name } />;
	}
	if ( c.type === 'share' ) {
		return <ShareBar rows={ result.rows } label={ c.label } value={ c.value } format={ c.format } name={ name } />;
	}
	if ( c.type === 'heatmap' ) {
		return <Heatmap rows={ result.rows } columns={ result.columns } cells={ c.cells } format={ c.format } name={ name } />;
	}
	return <ResultTable result={ result } />;
}

/**
 * Interpretation chips ("DEV3 AI read this as …").
 *
 * @param {Object} props        Props.
 * @param {Object} props.result Result.
 * @param {string} props.source ai|rules|builder.
 * @return {Element} Chips.
 */
export function Interpretation( { result, source } ) {
	const parts = String( result.description || '' ).split( ' · ' ).filter( Boolean );
	const lead = source === 'ai' ? __( 'DEV3 AI read this as', 'dev3-ai-store-insights-for-woocommerce' ) : source === 'rules' ? __( 'Understood as', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Report', 'dev3-ai-store-insights-for-woocommerce' );
	return (
		<div className="dev3si-interp">
			<span className="dev3si-interp__lead">{ lead }:</span>
			{ parts.map( ( p, i ) => (
				<span key={ i } className="dev3si-chip">
					{ p }
				</span>
			) ) }
		</div>
	);
}

/**
 * Explanation panel.
 *
 * @param {Object}   props             Props.
 * @param {Object}   props.explanation { headline, summary, highlights, follow_ups, source }.
 * @param {Function} props.onAsk       Follow-up click.
 * @param {Function} props.onExplainAi Ask DEV3 AI to explain (when the template was used).
 * @param {boolean}  props.explaining  Busy.
 * @return {Element|null} Panel.
 */
export function Explanation( { explanation, onAsk, onExplainAi, explaining } ) {
	if ( ! explanation ) {
		return null;
	}
	const ai = explanation.source === 'ai';
	return (
		<div className={ cx( 'dev3si-explain', ai && 'is-ai' ) }>
			<div className="dev3si-explain__head">
				<Badge tone={ ai ? 'brand' : 'neutral' }>
					{ ai ? (
						<>
							<Icon name="sparkles" size={ 12 } /> { __( 'DEV3 AI explanation', 'dev3-ai-store-insights-for-woocommerce' ) }
						</>
					) : (
						__( 'Summary', 'dev3-ai-store-insights-for-woocommerce' )
					) }
				</Badge>
				{ ! ai && onExplainAi && (
					<Button size="sm" variant="ghost" icon="sparkles" loading={ explaining } onClick={ onExplainAi }>
						{ __( 'Explain with DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				) }
			</div>
			{ explanation.headline && <p className="dev3si-explain__headline">{ explanation.headline }</p> }
			{ explanation.summary && <p className="dev3si-explain__summary">{ explanation.summary }</p> }
			{ explanation.highlights && explanation.highlights.length > 0 && (
				<ul className="dev3si-explain__list">
					{ explanation.highlights.map( ( h, i ) => (
						<li key={ i }>{ h }</li>
					) ) }
				</ul>
			) }
			{ explanation.error && <ErrorNotice error={ explanation.error } /> }
			{ onAsk && explanation.follow_ups && explanation.follow_ups.length > 0 && (
				<div className="dev3si-followups">
					<span className="dev3si-followups__lead">{ __( 'Ask next', 'dev3-ai-store-insights-for-woocommerce' ) }</span>
					{ explanation.follow_ups.map( ( q, i ) => (
						<button type="button" key={ i } className="dev3si-suggest" onClick={ () => onAsk( q ) }>
							{ q }
						</button>
					) ) }
				</div>
			) }
		</div>
	);
}

/**
 * A full answer card: title, interpretation, chart/table toggle, notes, explanation and actions.
 *
 * @param {Object}   props             Props.
 * @param {Object}   props.result      Result.
 * @param {Object}   props.explanation Explanation.
 * @param {string}   props.source      ai|rules|builder.
 * @param {Function} props.onSave      Save handler.
 * @param {Function} props.onExplore   Open in Explore.
 * @param {Function} props.onAsk       Follow-up.
 * @param {Function} props.onExplainAi Explain with AI.
 * @param {boolean}  props.explaining  Busy.
 * @param {*}        props.extraActions More action buttons.
 * @param {Object}   props.over        Period override (CSV).
 * @param {boolean}  props.compact     Smaller (dashboard).
 * @param {boolean}  props.loading     Refetching.
 * @param {string}   props.title       Title override.
 * @return {Element} Card.
 */
export default function ResultView( { result, explanation, source = 'builder', onSave, onExplore, onAsk, onExplainAi, explaining, extraActions, over, compact = false, loading = false, title } ) {
	const [ view, setView ] = useState( 'chart' );
	const tableOnly = result.chart.type === 'table';
	const showTable = view === 'table' || tableOnly;
	const period = result.period ? `${ result.period.label }${ result.period.range && result.period.range !== result.period.label ? ` · ${ result.period.range }` : '' }` : '';
	return (
		<Card
			className={ cx( 'dev3si-answer', compact && 'is-compact', loading && 'is-loading' ) }
			title={ title || result.title }
			description={ period }
			actions={
				<Inline gap={ 4 }>
					{ ! tableOnly && result.chart.type !== 'kpi' && (
						<div className="dev3si-seg-toggle" role="group" aria-label={ __( 'View', 'dev3-ai-store-insights-for-woocommerce' ) }>
							<button type="button" aria-pressed={ ! showTable } onClick={ () => setView( 'chart' ) }>
								{ __( 'Chart', 'dev3-ai-store-insights-for-woocommerce' ) }
							</button>
							<button type="button" aria-pressed={ showTable } onClick={ () => setView( 'table' ) }>
								{ __( 'Table', 'dev3-ai-store-insights-for-woocommerce' ) }
							</button>
						</div>
					) }
					{ extraActions }
				</Inline>
			}
			footer={
				onSave || onExplore || ! compact ? (
					<Inline gap={ 8 } align="end">
						<Button size="sm" variant="ghost" icon="file" href={ exportUrl( result.fn, result.args, over ) } download>
							{ __( 'CSV', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
						{ onExplore && (
							<Button size="sm" variant="ghost" icon="edit" onClick={ onExplore }>
								{ __( 'Edit query', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						) }
						{ onSave && (
							<Button size="sm" variant="secondary" icon="plus" onClick={ onSave }>
								{ __( 'Save or pin', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						) }
					</Inline>
				) : undefined
			}
		>
			{ ! compact && <Interpretation result={ result } source={ source } /> }
			<div className="dev3si-answer__body">{ showTable ? <ResultTable result={ result } /> : <ResultChart result={ result } height={ compact ? 200 : 260 } /> }</div>
			{ result.summary && result.chart.type === 'bar' && result.summary.top && ! compact && (
				<p className="dev3si-answer__total">
					{ sprintf(
						/* translators: 1: metric, 2: total */
						__( 'Total %1$s: %2$s', 'dev3-ai-store-insights-for-woocommerce' ),
						String( result.summary.label || '' ).toLowerCase(),
						fmt( result.summary.value, result.summary.format )
					) }
				</p>
			) }
			{ result.notes && result.notes.length > 0 && (
				<ul className="dev3si-notes">
					{ result.notes.map( ( n, i ) => (
						<li key={ i }>{ n }</li>
					) ) }
				</ul>
			) }
			<Explanation explanation={ explanation } onAsk={ onAsk } onExplainAi={ onExplainAi } explaining={ explaining } />
		</Card>
	);
}
