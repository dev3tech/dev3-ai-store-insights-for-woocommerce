import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, Checkbox, Columns, EmptyState, ErrorNotice, FormGrid, Inline, Modal, NumberField, PageHeader, Select, SkeletonCard, Stack, TextField, Toggle, useToast } from '../core3/ui';

const WEEKDAYS = [ __( 'Sunday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Monday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Tuesday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Wednesday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Thursday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Friday', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Saturday', 'dev3-ai-store-insights-for-woocommerce' ) ];
const FREQ = {
	daily: __( 'Daily', 'dev3-ai-store-insights-for-woocommerce' ),
	weekly: __( 'Weekly', 'dev3-ai-store-insights-for-woocommerce' ),
	monthly: __( 'Monthly', 'dev3-ai-store-insights-for-woocommerce' ),
};
const COVERS = {
	daily: __( 'yesterday', 'dev3-ai-store-insights-for-woocommerce' ),
	weekly: __( 'last week', 'dev3-ai-store-insights-for-woocommerce' ),
	monthly: __( 'last month', 'dev3-ai-store-insights-for-woocommerce' ),
};

const hourLabel = ( h ) => `${ String( h ).padStart( 2, '0' ) }:00`;

/**
 * Human schedule text.
 *
 * @param {Object} d Digest.
 * @return {string} Text.
 */
export function scheduleText( d ) {
	if ( d.frequency === 'weekly' ) {
		/* translators: 1: weekday, 2: time */
		return sprintf( __( 'Every %1$s at %2$s', 'dev3-ai-store-insights-for-woocommerce' ), WEEKDAYS[ d.weekday ], hourLabel( d.hour ) );
	}
	if ( d.frequency === 'monthly' ) {
		/* translators: 1: day of month, 2: time */
		return sprintf( __( 'Monthly on day %1$d at %2$s', 'dev3-ai-store-insights-for-woocommerce' ), d.monthday, hourLabel( d.hour ) );
	}
	/* translators: %s: time */
	return sprintf( __( 'Every day at %s', 'dev3-ai-store-insights-for-woocommerce' ), hourLabel( d.hour ) );
}

const BLANK = { name: '', frequency: 'weekly', weekday: 1, monthday: 1, hour: 8, recipients: '', include_kpis: true, question_ids: [], ai_summary: true, enabled: true };

/**
 * Scheduled e-mail digests.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.meta    Meta.
 * @param {Function} props.onUsage Usage refresh.
 * @return {Element} Screen.
 */
export default function DigestsScreen( { meta, onUsage } ) {
	const toast = useToast();
	const [ list, setList ] = useState( null );
	const [ saved, setSaved ] = useState( [] );
	const [ error, setError ] = useState( null );
	const [ edit, setEdit ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ remove, setRemove ] = useState( null );
	const aiReady = meta.ai && meta.ai.key_set && meta.ai.enabled;

	const load = () =>
		request( 'digests' )
			.then( setList )
			.catch( setError );
	useEffect( () => {
		load();
		request( 'questions?view=saved' )
			.then( setSaved )
			.catch( () => {} );
	}, [] );

	const set = ( key ) => ( value ) => setEdit( { ...edit, [ key ]: value } );
	const save = () => {
		setBusy( 'save' );
		request( 'digests', { method: 'POST', data: edit } )
			.then( () => {
				toast.success( __( 'Digest saved.', 'dev3-ai-store-insights-for-woocommerce' ) );
				setEdit( null );
				return load();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const test = ( d ) => {
		setBusy( `test-${ d.id }` );
		request( `digests/${ d.id }/test`, { method: 'POST' } )
			.then( ( res ) => toast.success( sprintf( /* translators: %s: email */ __( 'Test digest sent to %s.', 'dev3-ai-store-insights-for-woocommerce' ), res.sent ) ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setBusy( '' );
				onUsage();
			} );
	};
	const showPreview = ( d ) => {
		setBusy( `preview-${ d.id }` );
		request( `digests/${ d.id }/preview` )
			.then( setPreview )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( '' ) );
	};
	const toggle = ( d ) =>
		request( 'digests', { method: 'POST', data: { ...d, enabled: ! d.enabled } } )
			.then( load )
			.catch( ( err ) => toast.error( err ) );

	return (
		<Stack gap={ 16 }>
			<PageHeader
				title={ __( 'E-mail digests', 'dev3-ai-store-insights-for-woocommerce' ) }
				description={ __( 'Send your key numbers and saved questions by e-mail every day, week or month.', 'dev3-ai-store-insights-for-woocommerce' ) }
				actions={
					<Button variant="primary" icon="plus" onClick={ () => setEdit( { ...BLANK } ) }>
						{ __( 'New digest', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				}
			/>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ list === null && <SkeletonCard lines={ 3 } /> }
			{ list && list.length === 0 && (
				<Card>
					<EmptyState
						icon="inbox"
						title={ __( 'No digests yet', 'dev3-ai-store-insights-for-woocommerce' ) }
						description={ __( 'A weekly digest with your KPIs and best sellers is a good start.', 'dev3-ai-store-insights-for-woocommerce' ) }
						action={
							<Button variant="primary" onClick={ () => setEdit( { ...BLANK } ) }>
								{ __( 'Create a digest', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						}
					/>
				</Card>
			) }
			{ list && list.length > 0 && (
				<Columns min={ 300 }>
					{ list.map( ( d ) => (
						<Card
							key={ d.id }
							title={ d.name }
							description={ scheduleText( d ) }
							actions={ <Toggle label={ __( 'Active', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! d.enabled } onChange={ () => toggle( d ) } /> }
							footer={
								<Inline gap={ 8 } align="end">
									<Button size="sm" variant="ghost" icon="trash" onClick={ () => setRemove( d ) }>
										{ __( 'Delete', 'dev3-ai-store-insights-for-woocommerce' ) }
									</Button>
									<Button size="sm" variant="ghost" icon="search" loading={ busy === `preview-${ d.id }` } onClick={ () => showPreview( d ) }>
										{ __( 'Preview', 'dev3-ai-store-insights-for-woocommerce' ) }
									</Button>
									<Button size="sm" variant="ghost" icon="bolt" loading={ busy === `test-${ d.id }` } onClick={ () => test( d ) }>
										{ __( 'Send test', 'dev3-ai-store-insights-for-woocommerce' ) }
									</Button>
									<Button size="sm" icon="edit" onClick={ () => setEdit( { ...d } ) }>
										{ __( 'Edit', 'dev3-ai-store-insights-for-woocommerce' ) }
									</Button>
								</Inline>
							}
						>
							<dl className="dev3si-dl">
								<dt>{ __( 'Covers', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
								<dd>{ COVERS[ d.frequency ] }</dd>
								<dt>{ __( 'Recipients', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
								<dd>{ d.recipients || <Badge tone="warning">{ __( 'None yet', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> }</dd>
								<dt>{ __( 'Contents', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
								<dd>
									{ [ d.include_kpis ? __( 'Key numbers', 'dev3-ai-store-insights-for-woocommerce' ) : null, d.question_ids.length ? sprintf( /* translators: %d: count */ __( '%d saved questions', 'dev3-ai-store-insights-for-woocommerce' ), d.question_ids.length ) : null, d.ai_summary ? __( 'DEV3 AI summary', 'dev3-ai-store-insights-for-woocommerce' ) : null ]
										.filter( Boolean )
										.join( ' · ' ) || '–' }
								</dd>
								<dt>{ __( 'Next send', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
								<dd>{ d.enabled && d.next_run ? d.next_run_label || new Date( d.next_run * 1000 ).toLocaleString() : __( 'Paused', 'dev3-ai-store-insights-for-woocommerce' ) }</dd>
							</dl>
						</Card>
					) ) }
				</Columns>
			) }
			<Modal
				open={ !! edit }
				onClose={ () => setEdit( null ) }
				size="lg"
				title={ edit && edit.id ? __( 'Edit digest', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'New digest', 'dev3-ai-store-insights-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setEdit( null ) }>
							{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
						<Button variant="primary" loading={ busy === 'save' } onClick={ save }>
							{ __( 'Save digest', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				{ edit && (
					<Stack gap={ 16 }>
						<FormGrid>
							<TextField label={ __( 'Name', 'dev3-ai-store-insights-for-woocommerce' ) } value={ edit.name } onChange={ set( 'name' ) } placeholder={ __( 'Weekly store digest', 'dev3-ai-store-insights-for-woocommerce' ) } maxLength={ 80 } data-autofocus />
							<Select label={ __( 'Frequency', 'dev3-ai-store-insights-for-woocommerce' ) } value={ edit.frequency } onChange={ set( 'frequency' ) } options={ Object.keys( FREQ ).map( ( k ) => ( { value: k, label: FREQ[ k ] } ) ) } help={ sprintf( /* translators: %s: period */ __( 'Reports on %s.', 'dev3-ai-store-insights-for-woocommerce' ), COVERS[ edit.frequency ] ) } />
							{ edit.frequency === 'weekly' && <Select label={ __( 'Day', 'dev3-ai-store-insights-for-woocommerce' ) } value={ String( edit.weekday ) } onChange={ ( v ) => set( 'weekday' )( parseInt( v, 10 ) ) } options={ WEEKDAYS.map( ( w, i ) => ( { value: String( i ), label: w } ) ) } /> }
							{ edit.frequency === 'monthly' && <NumberField label={ __( 'Day of month', 'dev3-ai-store-insights-for-woocommerce' ) } value={ edit.monthday } min={ 1 } max={ 28 } onChange={ set( 'monthday' ) } /> }
							<Select label={ __( 'Send at (site time)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ String( edit.hour ) } onChange={ ( v ) => set( 'hour' )( parseInt( v, 10 ) ) } options={ Array.from( { length: 24 }, ( _, h ) => ( { value: String( h ), label: hourLabel( h ) } ) ) } />
						</FormGrid>
						<TextField label={ __( 'Recipients', 'dev3-ai-store-insights-for-woocommerce' ) } value={ edit.recipients } onChange={ set( 'recipients' ) } help={ __( 'Comma-separated e-mail addresses.', 'dev3-ai-store-insights-for-woocommerce' ) } placeholder="owner@example.com, team@example.com" />
						<Toggle label={ __( 'Include key numbers (net sales, orders, AOV, refunds…)', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! edit.include_kpis } onChange={ set( 'include_kpis' ) } />
						<Toggle label={ __( 'Start with a DEV3 AI summary paragraph', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! edit.ai_summary } onChange={ set( 'ai_summary' ) } help={ aiReady ? __( 'Written from the numbers in the digest. Skipped if AI is unavailable.', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Needs a Google Gemini API key (API Config). The digest is sent without it until then.', 'dev3-ai-store-insights-for-woocommerce' ) } />
						<fieldset className="dev3si-fieldset">
							<legend>{ __( 'Saved questions to include', 'dev3-ai-store-insights-for-woocommerce' ) }</legend>
							{ saved.length === 0 && <p className="dev3si-muted">{ __( 'Save questions from Ask or Explore to add them here.', 'dev3-ai-store-insights-for-woocommerce' ) }</p> }
							<div className="dev3si-checklist">
								{ saved.map( ( q ) => (
									<Checkbox
										key={ q.id }
										label={ q.title }
										checked={ edit.question_ids.includes( q.id ) }
										onChange={ ( on ) => set( 'question_ids' )( on ? [ ...edit.question_ids, q.id ] : edit.question_ids.filter( ( id ) => id !== q.id ) ) }
									/>
								) ) }
							</div>
						</fieldset>
						<Toggle label={ __( 'Active', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! edit.enabled } onChange={ set( 'enabled' ) } />
					</Stack>
				) }
			</Modal>
			<Modal open={ !! preview } onClose={ () => setPreview( null ) } size="xl" title={ preview ? preview.subject : '' } description={ __( 'Preview with current data (the AI summary is added when the digest is sent).', 'dev3-ai-store-insights-for-woocommerce' ) }>
				{ preview && <iframe className="dev3si-preview" title={ __( 'Digest preview', 'dev3-ai-store-insights-for-woocommerce' ) } sandbox="" srcDoc={ preview.html } /> }
			</Modal>
			<Modal
				open={ !! remove }
				onClose={ () => setRemove( null ) }
				size="sm"
				title={ __( 'Delete this digest?', 'dev3-ai-store-insights-for-woocommerce' ) }
				description={ remove ? remove.name : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemove( null ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
						<Button
							variant="danger"
							onClick={ () =>
								request( `digests/${ remove.id }`, { method: 'DELETE' } )
									.then( () => {
										setRemove( null );
										toast.success( __( 'Digest deleted.', 'dev3-ai-store-insights-for-woocommerce' ) );
										return load();
									} )
									.catch( ( err ) => toast.error( err ) )
							}
						>
							{ __( 'Delete', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
					</>
				}
			/>
		</Stack>
	);
}
