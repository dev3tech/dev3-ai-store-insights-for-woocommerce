import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, ErrorNotice, FormGrid, NumberField, PageHeader, Select, SkeletonCard, Stack, TextField, useToast } from '../core3/ui';
import ResultView from './ResultView';
import SaveModal from './SaveModal';

const PARAM_LABELS = {
	metric: __( 'Measure', 'dev3-ai-store-insights-for-woocommerce' ),
	interval: __( 'Interval', 'dev3-ai-store-insights-for-woocommerce' ),
	period: __( 'Period', 'dev3-ai-store-insights-for-woocommerce' ),
	from: __( 'From', 'dev3-ai-store-insights-for-woocommerce' ),
	to: __( 'To', 'dev3-ai-store-insights-for-woocommerce' ),
	compare: __( 'Compare with', 'dev3-ai-store-insights-for-woocommerce' ),
	limit: __( 'Rows', 'dev3-ai-store-insights-for-woocommerce' ),
	location: __( 'Location', 'dev3-ai-store-insights-for-woocommerce' ),
	category: __( 'Category', 'dev3-ai-store-insights-for-woocommerce' ),
	product: __( 'Product (name or SKU)', 'dev3-ai-store-insights-for-woocommerce' ),
	customer_type: __( 'Customers', 'dev3-ai-store-insights-for-woocommerce' ),
	customer_kind: __( 'Accounts', 'dev3-ai-store-insights-for-woocommerce' ),
	level: __( 'Group by', 'dev3-ai-store-insights-for-woocommerce' ),
	segment: __( 'Segments', 'dev3-ai-store-insights-for-woocommerce' ),
	view: __( 'View', 'dev3-ai-store-insights-for-woocommerce' ),
	months: __( 'Cohort months', 'dev3-ai-store-insights-for-woocommerce' ),
};

/**
 * Defaults for a function's parameters.
 *
 * @param {Object} def Catalogue entry.
 * @return {Object} Args.
 */
export function defaultsFor( def ) {
	const out = {};
	( def ? def.params : [] ).forEach( ( p ) => {
		if ( p.default !== undefined ) {
			out[ p.id ] = p.default;
		}
	} );
	return out;
}

/**
 * Parameter form for any library function.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.meta     Meta.
 * @param {Object}   props.query    { fn, args }.
 * @param {Function} props.onChange Change.
 * @return {Element} Form.
 */
export function QueryForm( { meta, query, onChange } ) {
	const def = meta.functions.find( ( f ) => f.id === query.fn ) || meta.functions[ 0 ];
	const args = query.args || {};
	const set = ( id ) => ( value ) => onChange( { fn: def.id, args: { ...args, [ id ]: value } } );
	const lists = { location: meta.locations || [], category: meta.categories || [] };
	return (
		<FormGrid>
			<Select
				label={ __( 'Report', 'dev3-ai-store-insights-for-woocommerce' ) }
				value={ def.id }
				onChange={ ( fn ) => {
					const next = meta.functions.find( ( f ) => f.id === fn );
					const keep = {};
					next.params.forEach( ( p ) => {
						if ( args[ p.id ] !== undefined && ( p.type !== 'enum' || p.options.some( ( o ) => o.value === args[ p.id ] ) ) ) {
							keep[ p.id ] = args[ p.id ];
						}
					} );
					onChange( { fn, args: { ...defaultsFor( next ), ...keep } } );
				} }
				options={ meta.functions.map( ( f ) => ( { value: f.id, label: f.label } ) ) }
			/>
			{ def.params.map( ( p ) => {
				const label = PARAM_LABELS[ p.id ] || p.id;
				if ( ( p.id === 'from' || p.id === 'to' ) && args.period !== 'custom' ) {
					return null;
				}
				if ( p.type === 'enum' ) {
					return <Select key={ p.id } label={ label } value={ args[ p.id ] !== undefined ? args[ p.id ] : p.default } onChange={ set( p.id ) } options={ p.options } />;
				}
				if ( p.type === 'int' ) {
					return <NumberField key={ p.id } label={ label } value={ args[ p.id ] !== undefined ? args[ p.id ] : p.default } min={ p.min } max={ p.max } onChange={ set( p.id ) } />;
				}
				if ( p.type === 'date' ) {
					return <TextField key={ p.id } type="date" label={ label } value={ args[ p.id ] || '' } onChange={ set( p.id ) } />;
				}
				const listId = lists[ p.id ] && lists[ p.id ].length ? `dev3si-list-${ p.id }` : undefined;
				return (
					<div key={ p.id }>
						<TextField label={ label } value={ args[ p.id ] || '' } onChange={ set( p.id ) } maxLength={ 80 } list={ listId } placeholder={ __( 'Any', 'dev3-ai-store-insights-for-woocommerce' ) } />
						{ listId && (
							<datalist id={ listId }>
								{ lists[ p.id ].map( ( v ) => (
									<option key={ v } value={ v } />
								) ) }
							</datalist>
						) }
					</div>
				);
			} ) }
		</FormGrid>
	);
}

/**
 * Explore: build any report by hand (works with AI off).
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.meta    Meta.
 * @param {Object}   props.seed    Initial query.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Screen.
 */
export default function ExploreScreen( { meta, seed, onUsage } ) {
	const toast = useToast();
	const first = meta.functions.find( ( f ) => f.id === 'top_products' ) || meta.functions[ 0 ];
	const [ query, setQuery ] = useState( seed && seed.fn ? seed : { fn: first.id, args: defaultsFor( first ) } );
	const [ result, setResult ] = useState( null );
	const [ explanation, setExplanation ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( null );
	const [ explaining, setExplaining ] = useState( false );
	const aiReady = meta.ai && meta.ai.key_set && meta.ai.enabled;

	const run = ( q = query ) => {
		setBusy( true );
		setError( null );
		request( 'run', { method: 'POST', data: { fn: q.fn, args: q.args, explain: true } } )
			.then( ( res ) => {
				setResult( res.result );
				setExplanation( res.explanation );
			} )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	useEffect( () => {
		run();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const explainAi = () => {
		setExplaining( true );
		request( 'explain', { method: 'POST', data: { fn: result.fn, args: result.args } } )
			.then( setExplanation )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setExplaining( false );
				onUsage();
			} );
	};

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Explore', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Build any report by hand: pick a report, a period and filters. Works without AI.', 'dev3-ai-store-insights-for-woocommerce' ) } />
			<Card
				title={ __( 'Report builder', 'dev3-ai-store-insights-for-woocommerce' ) }
				footer={
					<Button variant="primary" icon="chart" loading={ busy } onClick={ () => run() }>
						{ __( 'Run report', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				}
			>
				<form
					onSubmit={ ( e ) => {
						e.preventDefault();
						run();
					} }
				>
					<QueryForm meta={ meta } query={ query } onChange={ setQuery } />
				</form>
			</Card>
			{ error && <ErrorNotice error={ error } onRetry={ () => run() } /> }
			{ busy && ! result && <SkeletonCard lines={ 6 } /> }
			{ result && (
				<div className={ busy ? 'dev3si-refetch' : undefined }>
					<ResultView
						result={ result }
						explanation={ explanation }
						source="builder"
						onExplainAi={ aiReady ? explainAi : null }
						explaining={ explaining }
						onSave={ () => setSaving( { query: { fn: result.fn, args: result.args }, title: result.description, source: 'builder' } ) }
					/>
				</div>
			) }
			<SaveModal target={ saving } onClose={ () => setSaving( null ) } />
		</Stack>
	);
}
