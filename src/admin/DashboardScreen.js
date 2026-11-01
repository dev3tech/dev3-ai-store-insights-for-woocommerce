import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Card, EmptyState, ErrorNotice, IconButton, Inline, JobProgress, Notice, PageHeader, Select, SkeletonCard, Stack, cx, useToast } from '../core3/ui';
import { KpiTiles } from './charts';
import ResultView from './ResultView';

/**
 * One pinned insight, fetched with the dashboard period override.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.pin     Saved question.
 * @param {Object}   props.over    Period override.
 * @param {Function} props.onMove  Move (-1 / +1).
 * @param {Function} props.onSize  Toggle size.
 * @param {Function} props.onUnpin Unpin.
 * @param {boolean}  props.first   First card.
 * @param {boolean}  props.last    Last card.
 * @param {Function} props.explore Open in Explore.
 * @return {Element} Card.
 */
function PinCard( { pin, over, onMove, onSize, onUnpin, first, last, explore } ) {
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( true );
	useEffect( () => {
		setBusy( true );
		request( 'run', { method: 'POST', data: { fn: pin.query.fn, args: pin.query.args, over, question_id: pin.id } } )
			.then( ( res ) => {
				setResult( res.result );
				setError( null );
			} )
			.catch( setError )
			.finally( () => setBusy( false ) );
	}, [ pin.id, JSON.stringify( pin.query ), JSON.stringify( over ) ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const actions = (
		<Inline gap={ 2 }>
			<IconButton icon="sort" label={ first ? __( 'Move down', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Move up', 'dev3-ai-store-insights-for-woocommerce' ) } size="sm" onClick={ () => onMove( first ? 1 : -1 ) } disabled={ first && last } />
			<IconButton icon="columns" label={ pin.pin_size === 'full' ? __( 'Make half width', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Make full width', 'dev3-ai-store-insights-for-woocommerce' ) } size="sm" onClick={ onSize } />
			<IconButton icon="edit" label={ __( 'Open in Explore', 'dev3-ai-store-insights-for-woocommerce' ) } size="sm" onClick={ () => explore( pin.query ) } />
			<IconButton icon="x" label={ __( 'Unpin', 'dev3-ai-store-insights-for-woocommerce' ) } size="sm" onClick={ onUnpin } />
		</Inline>
	);
	return (
		<div className={ cx( 'dev3si-pin', pin.pin_size === 'full' && 'is-full' ) }>
			{ ! result && busy && <SkeletonCard lines={ 4 } /> }
			{ error && (
				<Card title={ pin.title } actions={ actions }>
					<ErrorNotice error={ error } />
				</Card>
			) }
			{ result && ! error && <ResultView result={ result } title={ pin.title } compact loading={ busy } over={ over } extraActions={ actions } /> }
		</div>
	);
}

/**
 * Dashboard: KPI row + pinned insights, one period row scoping everything.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.meta     Meta.
 * @param {Object}   props.settings Settings.
 * @param {Function} props.explore  Open query in Explore.
 * @param {Function} props.reloadMeta Reload meta (after import).
 * @return {Element} Screen.
 */
export default function DashboardScreen( { meta, settings, explore, reloadMeta } ) {
	const toast = useToast();
	const [ period, setPeriod ] = useState( 'saved' );
	const [ pins, setPins ] = useState( null );
	const [ kpis, setKpis ] = useState( null );
	const [ kpiBusy, setKpiBusy ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ job, setJob ] = useState( meta.health && meta.health.job ? meta.health.job : null );
	const over = period === 'saved' ? {} : { period };
	const kpiPeriod = period === 'saved' ? settings.default_period || 'last_30_days' : period;
	const health = meta.health || {};
	const aiReady = meta.ai && meta.ai.key_set && meta.ai.enabled;

	const loadPins = () =>
		request( 'questions?view=pinned' )
			.then( setPins )
			.catch( setError );

	useEffect( () => {
		loadPins();
	}, [] );

	useEffect( () => {
		setKpiBusy( true );
		request( 'run', { method: 'POST', data: { fn: 'store_summary', args: { period: kpiPeriod, compare: 'previous_period' } } } )
			.then( ( res ) => setKpis( res.result ) )
			.catch( setError )
			.finally( () => setKpiBusy( false ) );
	}, [ kpiPeriod ] );

	const update = ( pin, data ) =>
		request( `questions/${ pin.id }`, { method: 'POST', data } )
			.then( loadPins )
			.catch( ( err ) => toast.error( err ) );

	const move = ( index, dir ) => {
		const next = pins.slice();
		const target = index + dir;
		if ( target < 0 || target >= next.length ) {
			return;
		}
		[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
		setPins( next );
		request( 'questions/reorder', { method: 'POST', data: { ids: next.map( ( p ) => p.id ) } } ).catch( ( err ) => toast.error( err ) );
	};

	const startImport = () =>
		request( 'health/import', { method: 'POST' } )
			.then( setJob )
			.catch( ( err ) => toast.error( err ) );

	const periodOptions = [ { value: 'saved', label: __( 'Each card’s saved period', 'dev3-ai-store-insights-for-woocommerce' ) }, ...meta.periods.filter( ( p ) => p.value !== 'custom' ) ];

	return (
		<Stack gap={ 16 }>
			<PageHeader
				title={ __( 'Dashboard', 'dev3-ai-store-insights-for-woocommerce' ) }
				description={ __( 'Your key numbers and pinned insights, always on fresh data.', 'dev3-ai-store-insights-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="sparkles" href="#/ask">
						{ __( 'Ask your store', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				}
			/>
			<div className="dev3si-filterbar">
				<Select label={ __( 'Date range', 'dev3-ai-store-insights-for-woocommerce' ) } value={ period } onChange={ setPeriod } options={ periodOptions } />
			</div>
			{ ! health.woocommerce && <Notice tone="warning" title={ __( 'WooCommerce is not active', 'dev3-ai-store-insights-for-woocommerce' ) }>{ __( 'Activate WooCommerce 8.0 or later to see store insights.', 'dev3-ai-store-insights-for-woocommerce' ) }</Notice> }
			{ health.ready && health.missing > 0 && ! job && (
				<Notice
					tone="warning"
					title={ __( 'Some orders are not in WooCommerce Analytics yet', 'dev3-ai-store-insights-for-woocommerce' ) }
					actions={
						<Button size="sm" variant="primary" onClick={ startImport }>
							{ __( 'Import them now', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
					}
				>
					{ sprintf(
						/* translators: %d: number of orders */
						__( '%d orders are missing from the Analytics tables, so the numbers may be low. Importing runs in the background.', 'dev3-ai-store-insights-for-woocommerce' ),
						health.missing
					) }
				</Notice>
			) }
			{ job && (
				<JobProgress
					jobId={ job.id }
					job={ job }
					title={ __( 'Importing orders into Analytics', 'dev3-ai-store-insights-for-woocommerce' ) }
					onDone={ () => {
						toast.success( __( 'Import finished.', 'dev3-ai-store-insights-for-woocommerce' ) );
						reloadMeta();
					} }
				/>
			) }
			{ ! aiReady && (
				<Notice tone="ai" title={ __( 'Connect DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) } actions={ <Button size="sm" href="#/api">{ __( 'Add API key', 'dev3-ai-store-insights-for-woocommerce' ) }</Button> }>
					{ __( 'Everything works without AI. With your Google Gemini key, DEV3 AI understands free-form questions and explains every answer.', 'dev3-ai-store-insights-for-woocommerce' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } /> }
			<Card title={ __( 'Key numbers', 'dev3-ai-store-insights-for-woocommerce' ) } description={ kpis ? `${ kpis.period.label } · ${ kpis.period.range }` : '' } className={ cx( 'dev3si-kpicard', kpiBusy && kpis && 'is-loading' ) }>
				{ kpis ? <KpiTiles items={ kpis.chart.items } spark={ kpis.chart.spark } compare={ kpis.compare_period ? kpis.compare_period.label : null } /> : <SkeletonCard lines={ 2 } /> }
			</Card>
			{ pins === null && <SkeletonCard lines={ 5 } /> }
			{ pins && pins.length === 0 && (
				<Card>
					<EmptyState
						icon="chart"
						title={ __( 'No pinned insights yet', 'dev3-ai-store-insights-for-woocommerce' ) }
						description={ __( 'Ask a question or build a report, then choose “Save or pin” to keep it here.', 'dev3-ai-store-insights-for-woocommerce' ) }
						action={
							<Button variant="primary" href="#/ask">
								{ __( 'Ask a question', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						}
						secondary={ <Button href="#/explore">{ __( 'Open Explore', 'dev3-ai-store-insights-for-woocommerce' ) }</Button> }
					/>
				</Card>
			) }
			{ pins && pins.length > 0 && (
				<div className="dev3si-pins">
					{ pins.map( ( pin, i ) => (
						<PinCard
							key={ pin.id }
							pin={ pin }
							over={ over }
							first={ i === 0 }
							last={ i === pins.length - 1 }
							onMove={ ( dir ) => move( i, dir ) }
							onSize={ () => update( pin, { pin_size: pin.pin_size === 'full' ? 'half' : 'full' } ) }
							onUnpin={ () => update( pin, { pinned: false } ).then( () => toast.info( __( 'Unpinned. It stays in Saved questions.', 'dev3-ai-store-insights-for-woocommerce' ) ) ) }
							explore={ explore }
						/>
					) ) }
				</div>
			) }
		</Stack>
	);
}
