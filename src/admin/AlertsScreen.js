import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, AiDraftReview, Badge, Button, Card, Drawer, ErrorNotice, FormGrid, IconButton, Inline, Modal, NumberField, Notice, PageHeader, ResponsiveTable, Select, Stack, Tabs, TextField, Toggle, useToast } from '../core3/ui';
import { BarChart, LineChart, Severity } from './charts';
import { fmt } from './format';
import { Explanation } from './ResultView';

const METRICS = [
	{ value: 'net_sales', label: __( 'Net sales', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'orders', label: __( 'Orders', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'aov', label: __( 'Average order value', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'items_sold', label: __( 'Items sold', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'refunds', label: __( 'Refunds', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'refund_rate', label: __( 'Refund rate', 'dev3-ai-store-insights-for-woocommerce' ) },
];
const DIRECTIONS = [
	{ value: 'drop', label: __( 'Drops', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'spike', label: __( 'Spikes', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'both', label: __( 'Drops and spikes', 'dev3-ai-store-insights-for-woocommerce' ) },
];
const SENS = [
	{ value: 'low', label: __( 'Low (only big surprises)', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'medium', label: __( 'Medium', 'dev3-ai-store-insights-for-woocommerce' ) },
	{ value: 'high', label: __( 'High (more alerts)', 'dev3-ai-store-insights-for-woocommerce' ) },
];

/**
 * Plain description of a rule.
 *
 * @param {Object} r Rule.
 * @return {string} Text.
 */
export function ruleText( r ) {
	if ( r.type === 'stock' ) {
		/* translators: %d: days */
		return sprintf( __( 'A top-10 seller has less than %d days of stock left', 'dev3-ai-store-insights-for-woocommerce' ), r.days );
	}
	const metric = ( METRICS.find( ( m ) => m.value === r.metric ) || {} ).label || r.metric;
	const dir = ( DIRECTIONS.find( ( d ) => d.value === r.direction ) || {} ).label || '';
	/* translators: 1: direction, 2: metric, 3: percent */
	return sprintf( __( '%1$s in daily %2$s of at least %3$s%% vs the 28-day average', 'dev3-ai-store-insights-for-woocommerce' ), dir, metric.toLowerCase(), r.min_change_pct );
}

/**
 * Alert detail drawer.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.alert    Alert.
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onChange Updated alert.
 * @param {Function} props.onUsage  Usage refresh.
 * @return {Element} Drawer.
 */
function AlertDrawer( { alert, onClose, onChange, onUsage } ) {
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	if ( ! alert ) {
		return <Drawer open={ false } onClose={ onClose } title="" />;
	}
	const d = alert.detail || {};
	const explain = ( force ) => {
		setBusy( true );
		request( `alerts/${ alert.id }/explain`, { method: 'POST', data: { force } } )
			.then( onChange )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};
	const ack = () =>
		request( `alerts/${ alert.id }`, { method: 'POST', data: { status: alert.status === 'ack' ? 'new' : 'ack' } } )
			.then( onChange )
			.catch( ( err ) => toast.error( err ) );
	const moverCols = [ { key: 'name', label: __( 'Name', 'dev3-ai-store-insights-for-woocommerce' ), format: 'text' }, { key: 'day', label: __( 'That day', 'dev3-ai-store-insights-for-woocommerce' ), format: 'money' }, { key: 'baseline', label: __( 'Usual per day', 'dev3-ai-store-insights-for-woocommerce' ), format: 'money' } ];
	const moverRows = ( list ) => ( list || [] ).map( ( m ) => ( { ...m, gap: Math.abs( m.diff ) } ) );
	return (
		<Drawer
			open
			onClose={ onClose }
			size="lg"
			title={ alert.rule }
			description={ `${ alert.day } · ${ alert.label }` }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Close', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
					<Button variant={ alert.status === 'ack' ? 'secondary' : 'primary' } icon="check" onClick={ ack }>
						{ alert.status === 'ack' ? __( 'Mark as new', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Acknowledge', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			<Stack gap={ 16 }>
				<Inline gap={ 8 }>
					<Severity severity={ alert.severity } />
					{ alert.metric !== 'stock' && (
						<span className="dev3si-muted">
							{ sprintf(
								/* translators: 1: value, 2: baseline, 3: change */
								__( '%1$s vs usual %2$s (%3$s)', 'dev3-ai-store-insights-for-woocommerce' ),
								fmt( alert.value, alert.format ),
								fmt( alert.baseline, alert.format ),
								fmt( alert.change_pct, 'change' )
							) }
						</span>
					) }
				</Inline>
				{ d.series && <LineChart rows={ d.series } series={ [ { key: 'value', label: alert.label } ] } format={ alert.format } height={ 200 } label={ `${ alert.label }, 29 days` } /> }
				{ d.items && <BarChart rows={ d.items } value="cover" format="days" name={ __( 'Days of stock left', 'dev3-ai-store-insights-for-woocommerce' ) } columns={ [ { key: 'cover', label: __( 'Days left', 'dev3-ai-store-insights-for-woocommerce' ), format: 'days' }, { key: 'stock', label: __( 'In stock', 'dev3-ai-store-insights-for-woocommerce' ), format: 'number' } ] } /> }
				{ d.movers && (
					<>
						<h4 className="dev3si-h4">{ __( 'Products that moved most', 'dev3-ai-store-insights-for-woocommerce' ) }</h4>
						{ d.movers.products && d.movers.products.length ? <BarChart rows={ moverRows( d.movers.products ) } value="gap" format="money" columns={ moverCols } name={ __( 'Products that moved most', 'dev3-ai-store-insights-for-woocommerce' ) } /> : <p className="dev3si-muted">{ __( 'No product stood out.', 'dev3-ai-store-insights-for-woocommerce' ) }</p> }
						<h4 className="dev3si-h4">{ __( 'Countries that moved most', 'dev3-ai-store-insights-for-woocommerce' ) }</h4>
						{ d.movers.locations && d.movers.locations.length ? <BarChart rows={ moverRows( d.movers.locations ) } value="gap" format="money" columns={ moverCols } name={ __( 'Countries that moved most', 'dev3-ai-store-insights-for-woocommerce' ) } /> : <p className="dev3si-muted">{ __( 'No country stood out.', 'dev3-ai-store-insights-for-woocommerce' ) }</p> }
						<p className="dev3si-muted">{ __( 'Bar length = gap between that day and the usual daily net sales.', 'dev3-ai-store-insights-for-woocommerce' ) }</p>
					</>
				) }
				{ d.explanation ? (
					<Explanation explanation={ d.explanation } onExplainAi={ () => explain( true ) } explaining={ busy } />
				) : (
					<div>
						<AiButton loading={ busy } onClick={ () => explain( false ) }>
							{ __( 'Explain this alert', 'dev3-ai-store-insights-for-woocommerce' ) }
						</AiButton>
					</div>
				) }
			</Stack>
		</Drawer>
	);
}

/**
 * Rule editor modal.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.rule    Rule or null.
 * @param {Function} props.onClose Close.
 * @param {Function} props.onSave  Save(rule).
 * @return {Element} Modal.
 */
function RuleModal( { rule, onClose, onSave } ) {
	const [ form, setForm ] = useState( rule );
	useEffect( () => setForm( rule ), [ rule ] );
	const set = ( k ) => ( v ) => setForm( { ...form, [ k ]: v } );
	return (
		<Modal
			open={ !! rule }
			onClose={ onClose }
			size="lg"
			title={ rule && rule.id ? __( 'Edit alert rule', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'New alert rule', 'dev3-ai-store-insights-for-woocommerce' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
					<Button variant="primary" onClick={ () => onSave( form ) }>
						{ __( 'Save rule', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			{ form && (
				<Stack gap={ 16 }>
					<TextField label={ __( 'Name', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.name || '' } onChange={ set( 'name' ) } maxLength={ 80 } data-autofocus />
					{ form.type === 'stock' ? (
						<NumberField label={ __( 'Alert when a top-10 seller has fewer days of stock than', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.days } min={ 1 } max={ 60 } onChange={ set( 'days' ) } />
					) : (
						<FormGrid>
							<Select label={ __( 'Metric', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.metric } onChange={ set( 'metric' ) } options={ METRICS } />
							<Select label={ __( 'Watch for', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.direction } onChange={ set( 'direction' ) } options={ DIRECTIONS } />
							<Select label={ __( 'Sensitivity', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.sensitivity } onChange={ set( 'sensitivity' ) } options={ SENS } />
							<NumberField label={ __( 'Minimum change (%)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.min_change_pct } min={ 0 } max={ 500 } onChange={ set( 'min_change_pct' ) } />
							<NumberField label={ __( 'Stay quiet below a daily average of', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.min_baseline } min={ 0 } step={ 1 } onChange={ set( 'min_baseline' ) } />
							<TextField label={ __( 'Only this location (optional)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.location || '' } onChange={ set( 'location' ) } maxLength={ 80 } />
							<TextField label={ __( 'Only this category (optional)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.category || '' } onChange={ set( 'category' ) } maxLength={ 80 } />
						</FormGrid>
					) }
					<Toggle label={ __( 'E-mail me when it triggers', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ form.notify !== false } onChange={ set( 'notify' ) } />
					{ form.notify !== false && <TextField label={ __( 'Recipients (optional)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.recipients || '' } onChange={ set( 'recipients' ) } help={ __( 'Leave empty to use the alert recipients from Settings (or the site admin e-mail).', 'dev3-ai-store-insights-for-woocommerce' ) } /> }
					<Toggle label={ __( 'Rule is active', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ form.enabled !== false } onChange={ set( 'enabled' ) } />
				</Stack>
			) }
		</Modal>
	);
}

/**
 * Anomaly alerts: log + rules.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.meta    Meta.
 * @param {Function} props.onUsage Usage refresh.
 * @param {Function} props.onCount New-alert count changed.
 * @return {Element} Screen.
 */
export default function AlertsScreen( { meta, onUsage, onCount } ) {
	const toast = useToast();
	const [ tab, setTab ] = useState( 'new' );
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ error, setError ] = useState( null );
	const [ open, setOpen ] = useState( null );
	const [ rules, setRules ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	const [ days, setDays ] = useState( '14' );
	const [ checking, setChecking ] = useState( false );
	const [ advice, setAdvice ] = useState( null );
	const [ advising, setAdvising ] = useState( false );
	const [ adviceError, setAdviceError ] = useState( null );
	const aiReady = meta.ai && meta.ai.key_set && meta.ai.enabled;

	const load = () => {
		if ( tab === 'rules' ) {
			return request( 'alert-rules' )
				.then( setRules )
				.catch( setError );
		}
		return request( `alerts?status=${ tab }&page=${ page }` )
			.then( ( res ) => {
				setData( res );
				onCount( parseInt( ( res.counts && res.counts.new ) || 0, 10 ) );
			} )
			.catch( setError );
	};
	useEffect( () => {
		load();
	}, [ tab, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const saveRules = ( list, message ) =>
		request( 'alert-rules', { method: 'POST', data: { rules: list } } )
			.then( ( res ) => {
				setRules( { ...rules, rules: res.rules } );
				toast.success( message );
			} )
			.catch( ( err ) => toast.error( err ) );

	const check = () => {
		setChecking( true );
		request( 'alert-rules/check', { method: 'POST', data: { days: parseInt( days, 10 ) } } )
			.then( ( res ) => {
				const n = res.created.length;
				toast[ n ? 'warning' : 'success' ]( n ? sprintf( /* translators: %d: alerts */ __( '%d new alerts found.', 'dev3-ai-store-insights-for-woocommerce' ), n ) : __( 'All clear: nothing unusual found.', 'dev3-ai-store-insights-for-woocommerce' ) );
				if ( n ) {
					setTab( 'new' );
					setPage( 1 );
					load();
				}
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setChecking( false ) );
	};

	const getAdvice = () => {
		setAdvising( true );
		setAdviceError( null );
		request( 'alert-rules/advice', { method: 'POST' } )
			.then( setAdvice )
			.catch( setAdviceError )
			.finally( () => {
				setAdvising( false );
				onUsage();
			} );
	};

	const columns = [
		{ key: 'rule', label: __( 'Alert', 'dev3-ai-store-insights-for-woocommerce' ), primary: true },
		{ key: 'day', label: __( 'Day', 'dev3-ai-store-insights-for-woocommerce' ), nowrap: true },
		{ key: 'value', label: __( 'Value', 'dev3-ai-store-insights-for-woocommerce' ), align: 'end', render: ( a ) => ( a.metric === 'stock' ? sprintf( /* translators: %d: products */ __( '%d products', 'dev3-ai-store-insights-for-woocommerce' ), a.value ) : fmt( a.value, a.format ) ) },
		{ key: 'change_pct', label: __( 'vs usual', 'dev3-ai-store-insights-for-woocommerce' ), align: 'end', render: ( a ) => ( a.metric === 'stock' ? '–' : fmt( a.change_pct, 'change' ) ) },
		{ key: 'severity', label: __( 'Severity', 'dev3-ai-store-insights-for-woocommerce' ), render: ( a ) => <Severity severity={ a.severity } /> },
	];
	const counts = ( data && data.counts ) || {};

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Anomaly alerts', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Every morning each rule compares yesterday with the 28 days before it and flags real surprises.', 'dev3-ai-store-insights-for-woocommerce' ) } />
			<Tabs
				label={ __( 'Alert views', 'dev3-ai-store-insights-for-woocommerce' ) }
				value={ tab }
				onChange={ ( t ) => {
					setTab( t );
					setPage( 1 );
				} }
				tabs={ [
					{ id: 'new', label: __( 'New', 'dev3-ai-store-insights-for-woocommerce' ), badge: counts.new || undefined },
					{ id: 'ack', label: __( 'Acknowledged', 'dev3-ai-store-insights-for-woocommerce' ) },
					{ id: 'rules', label: __( 'Rules', 'dev3-ai-store-insights-for-woocommerce' ) },
				] }
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ tab !== 'rules' && (
				<Card flush>
					<ResponsiveTable
						columns={ columns }
						rows={ data ? data.items : [] }
						loading={ ! data }
						onRowClick={ setOpen }
						pagination={ data && data.total > 20 ? { page, perPage: 20, total: data.total, onPageChange: setPage } : undefined }
						empty={ {
							icon: 'success',
							title: tab === 'new' ? __( 'No new alerts', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Nothing acknowledged yet', 'dev3-ai-store-insights-for-woocommerce' ),
							description: __( 'Alerts appear here when a day looks unusual. Use “Check now” on the Rules tab to scan past days.', 'dev3-ai-store-insights-for-woocommerce' ),
						} }
					/>
				</Card>
			) }
			{ tab === 'rules' && rules && (
				<>
					{ ! rules.enabled && <Notice tone="warning">{ __( 'Alerts are turned off in Settings. Rules are kept but not checked.', 'dev3-ai-store-insights-for-woocommerce' ) }</Notice> }
					<Card
						title={ __( 'Rules', 'dev3-ai-store-insights-for-woocommerce' ) }
						description={ rules.checked ? sprintf( /* translators: %s: date */ __( 'Last checked through %s.', 'dev3-ai-store-insights-for-woocommerce' ), rules.checked ) : __( 'Not checked yet.', 'dev3-ai-store-insights-for-woocommerce' ) }
						actions={
							<Inline gap={ 8 }>
								<Button size="sm" icon="plus" onClick={ () => setEditing( { type: 'metric', name: '', metric: 'net_sales', direction: 'drop', sensitivity: 'medium', min_change_pct: 30, min_baseline: 0, notify: true, enabled: true } ) }>
									{ __( 'Add rule', 'dev3-ai-store-insights-for-woocommerce' ) }
								</Button>
							</Inline>
						}
						flush
					>
						<ResponsiveTable
							columns={ [
								{ key: 'name', label: __( 'Rule', 'dev3-ai-store-insights-for-woocommerce' ), primary: true },
								{ key: 'text', label: __( 'Triggers on', 'dev3-ai-store-insights-for-woocommerce' ), render: ( r ) => <span className="dev3si-muted">{ ruleText( r ) }</span> },
								{ key: 'enabled', label: __( 'Status', 'dev3-ai-store-insights-for-woocommerce' ), render: ( r ) => ( r.enabled ? <Badge tone="success" dot>{ __( 'Active', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> : <Badge>{ __( 'Paused', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> ) },
							] }
							rows={ rules.rules }
							onRowClick={ ( r ) => setEditing( r ) }
							rowActions={ ( r ) => (
								<>
									<IconButton
										icon="edit"
										label={ __( 'Edit', 'dev3-ai-store-insights-for-woocommerce' ) }
										onClick={ ( e ) => {
											e.stopPropagation();
											setEditing( r );
										} }
									/>
									<IconButton
										icon="trash"
										label={ __( 'Delete', 'dev3-ai-store-insights-for-woocommerce' ) }
										onClick={ ( e ) => {
											e.stopPropagation();
											saveRules(
												rules.rules.filter( ( x ) => x.id !== r.id ),
												__( 'Rule deleted.', 'dev3-ai-store-insights-for-woocommerce' )
											);
										} }
									/>
								</>
							) }
						/>
					</Card>
					<Card title={ __( 'Check now', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Scan recent days with the current rules (each rule raises at most one alert per day).', 'dev3-ai-store-insights-for-woocommerce' ) }>
						<Inline gap={ 12 } align="start">
							<Select
								label={ __( 'Days to scan', 'dev3-ai-store-insights-for-woocommerce' ) }
								value={ days }
								onChange={ setDays }
								options={ [ '1', '7', '14', '30' ].map( ( v ) => ( { value: v, label: v === '1' ? __( 'Yesterday', 'dev3-ai-store-insights-for-woocommerce' ) : sprintf( /* translators: %s: days */ __( 'Last %s days', 'dev3-ai-store-insights-for-woocommerce' ), v ) } ) ) }
							/>
							<Button variant="primary" icon="search" loading={ checking } onClick={ check } className="dev3si-align-end">
								{ __( 'Check now', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						</Inline>
					</Card>
					<Card tone="ai" title={ __( 'Suggest rules with DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'DEV3 AI looks at 90 days of daily totals and proposes thresholds that fit your store. Nothing changes until you accept.', 'dev3-ai-store-insights-for-woocommerce' ) }>
						<Stack gap={ 12 }>
							{ ! aiReady && <Notice tone="info">{ __( 'Add a Google Gemini API key in API Config to use this.', 'dev3-ai-store-insights-for-woocommerce' ) }</Notice> }
							{ ! advice && (
								<div>
									<AiButton loading={ advising } onClick={ getAdvice } disabled={ ! aiReady }>
										{ __( 'Suggest rules with DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) }
									</AiButton>
								</div>
							) }
							<ErrorNotice error={ adviceError } onRetry={ getAdvice } />
							{ advice && (
								<AiDraftReview
									title={ __( 'DEV3 AI · Suggested alert rules', 'dev3-ai-store-insights-for-woocommerce' ) }
									format="json"
									draft={ advice.rules }
									original={ rules.rules.filter( ( r ) => r.type !== 'stock' ) }
									meta={ { model: advice.model, tokens: advice.tokens } }
									acceptLabel={ __( 'Add these rules', 'dev3-ai-store-insights-for-woocommerce' ) }
									onAccept={ ( value ) => {
										const added = ( Array.isArray( value ) ? value : [] ).map( ( r ) => ( { ...r, id: undefined, type: 'metric' } ) );
										saveRules( [ ...rules.rules, ...added ], __( 'Suggested rules added.', 'dev3-ai-store-insights-for-woocommerce' ) ).then( () => setAdvice( null ) );
									} }
									onReject={ () => setAdvice( null ) }
									onRegenerate={ getAdvice }
									regenerating={ advising }
								/>
							) }
						</Stack>
					</Card>
				</>
			) }
			<AlertDrawer
				alert={ open }
				onClose={ () => setOpen( null ) }
				onUsage={ onUsage }
				onChange={ ( a ) => {
					setOpen( a );
					load();
				} }
			/>
			<RuleModal
				rule={ editing }
				onClose={ () => setEditing( null ) }
				onSave={ ( r ) => {
					const exists = r.id && rules.rules.some( ( x ) => x.id === r.id );
					const list = exists ? rules.rules.map( ( x ) => ( x.id === r.id ? r : x ) ) : [ ...rules.rules, r ];
					saveRules( list, __( 'Rule saved.', 'dev3-ai-store-insights-for-woocommerce' ) ).then( () => setEditing( null ) );
				} }
			/>
		</Stack>
	);
}
