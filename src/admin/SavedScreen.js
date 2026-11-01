import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, ErrorNotice, IconButton, Modal, PageHeader, ResponsiveTable, Stack, TextField, useToast } from '../core3/ui';
import { exportUrl } from './ResultView';

/**
 * Saved questions list.
 *
 * @param {Object}   props         Props.
 * @param {Function} props.explore Open in Explore.
 * @return {Element} Screen.
 */
export default function SavedScreen( { explore } ) {
	const toast = useToast();
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ rename, setRename ] = useState( null );
	const [ remove, setRemove ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		request( 'questions?view=saved' )
			.then( setRows )
			.catch( setError );
	useEffect( () => {
		load();
	}, [] );

	const update = ( row, data, message ) =>
		request( `questions/${ row.id }`, { method: 'POST', data } )
			.then( () => {
				toast.success( message );
				return load();
			} )
			.catch( ( err ) => toast.error( err ) );

	const stop = ( fn ) => ( e ) => {
		e.stopPropagation();
		fn();
	};

	const columns = [
		{ key: 'title', label: __( 'Question', 'dev3-ai-store-insights-for-woocommerce' ), primary: true, sortable: true },
		{ key: 'description', label: __( 'Report', 'dev3-ai-store-insights-for-woocommerce' ), render: ( r ) => <span className="dev3si-muted">{ r.description }</span> },
		{
			key: 'pinned',
			label: __( 'Dashboard', 'dev3-ai-store-insights-for-woocommerce' ),
			render: ( r ) => ( r.pinned ? <Badge tone="brand">{ __( 'Pinned', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> : <Badge>{ __( 'Not pinned', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> ),
		},
		{ key: 'source', label: __( 'Made with', 'dev3-ai-store-insights-for-woocommerce' ), render: ( r ) => ( r.source === 'ai' ? 'DEV3 AI' : r.source === 'rules' ? __( 'Rules', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Explore', 'dev3-ai-store-insights-for-woocommerce' ) ), hideOnMobile: true },
		{ key: 'last_run_at', label: __( 'Last run', 'dev3-ai-store-insights-for-woocommerce' ), sortable: true, render: ( r ) => r.last_run_at || '–', nowrap: true },
	];

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Saved questions', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Saved questions re-run on fresh data. Pin them to the dashboard or add them to e-mail digests.', 'dev3-ai-store-insights-for-woocommerce' ) } />
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			<Card flush>
				<ResponsiveTable
					columns={ columns }
					rows={ rows || [] }
					loading={ rows === null }
					pageSize={ 20 }
					onRowClick={ ( r ) => explore( r.query ) }
					empty={ {
						icon: 'list',
						title: __( 'No saved questions', 'dev3-ai-store-insights-for-woocommerce' ),
						description: __( 'Use “Save or pin” under any answer.', 'dev3-ai-store-insights-for-woocommerce' ),
						action: (
							<Button variant="primary" href="#/ask">
								{ __( 'Ask a question', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						),
					} }
					rowActions={ ( r ) => (
						<>
							<IconButton icon="chart" label={ __( 'Open in Explore', 'dev3-ai-store-insights-for-woocommerce' ) } onClick={ stop( () => explore( r.query ) ) } />
							<IconButton
								icon={ r.pinned ? 'x' : 'plus' }
								label={ r.pinned ? __( 'Unpin from dashboard', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Pin to dashboard', 'dev3-ai-store-insights-for-woocommerce' ) }
								onClick={ stop( () => update( r, { pinned: ! r.pinned }, r.pinned ? __( 'Unpinned.', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Pinned to the dashboard.', 'dev3-ai-store-insights-for-woocommerce' ) ) ) }
							/>
							<IconButton icon="file" label={ __( 'Download CSV', 'dev3-ai-store-insights-for-woocommerce' ) } href={ exportUrl( r.query.fn, r.query.args ) } onClick={ ( e ) => e.stopPropagation() } />
							<IconButton icon="edit" label={ __( 'Rename', 'dev3-ai-store-insights-for-woocommerce' ) } onClick={ stop( () => setRename( { row: r, title: r.title } ) ) } />
							<IconButton icon="trash" label={ __( 'Delete', 'dev3-ai-store-insights-for-woocommerce' ) } onClick={ stop( () => setRemove( r ) ) } />
						</>
					) }
				/>
			</Card>
			<Modal
				open={ !! rename }
				onClose={ () => setRename( null ) }
				size="sm"
				title={ __( 'Rename question', 'dev3-ai-store-insights-for-woocommerce' ) }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRename( null ) }>
							{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
						<Button variant="primary" onClick={ () => update( rename.row, { title: rename.title }, __( 'Renamed.', 'dev3-ai-store-insights-for-woocommerce' ) ).then( () => setRename( null ) ) } disabled={ ! rename || ! rename.title.trim() }>
							{ __( 'Save', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				{ rename && <TextField label={ __( 'Title', 'dev3-ai-store-insights-for-woocommerce' ) } value={ rename.title } onChange={ ( title ) => setRename( { ...rename, title } ) } maxLength={ 190 } data-autofocus /> }
			</Modal>
			<Modal
				open={ !! remove }
				onClose={ () => setRemove( null ) }
				size="sm"
				title={ __( 'Delete this question?', 'dev3-ai-store-insights-for-woocommerce' ) }
				description={ remove ? remove.title : '' }
				footer={
					<>
						<Button variant="ghost" onClick={ () => setRemove( null ) } data-autofocus>
							{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
						<Button
							variant="danger"
							loading={ busy }
							onClick={ () => {
								setBusy( true );
								request( `questions/${ remove.id }`, { method: 'DELETE' } )
									.then( () => {
										toast.success( __( 'Deleted.', 'dev3-ai-store-insights-for-woocommerce' ) );
										setRemove( null );
										return load();
									} )
									.catch( ( err ) => toast.error( err ) )
									.finally( () => setBusy( false ) );
							} }
						>
							{ __( 'Delete', 'dev3-ai-store-insights-for-woocommerce' ) }
						</Button>
					</>
				}
			>
				<p>{ __( 'It is also removed from the dashboard and from any digest that includes it.', 'dev3-ai-store-insights-for-woocommerce' ) }</p>
			</Modal>
		</Stack>
	);
}
