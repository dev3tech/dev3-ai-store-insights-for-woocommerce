import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Button, Modal, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';

/**
 * Save a question (and optionally pin it to the dashboard).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.target   { query, question, source, title } or null (closed).
 * @param {Function} props.onClose  Close.
 * @param {Function} props.onSaved  Called with the saved row.
 * @return {Element} Modal.
 */
export default function SaveModal( { target, onClose, onSaved } ) {
	const toast = useToast();
	const [ title, setTitle ] = useState( '' );
	const [ pinned, setPinned ] = useState( true );
	const [ size, setSize ] = useState( 'half' );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		if ( target ) {
			setTitle( target.title || target.question || '' );
			setPinned( true );
			setSize( target.query && [ 'sales_trend', 'store_summary', 'cohorts' ].includes( target.query.fn ) ? 'full' : 'half' );
		}
	}, [ target ] );

	const submit = ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setBusy( true );
		request( 'questions', { method: 'POST', data: { title, question: target.question || '', query: target.query, pinned, pin_size: size, source: target.source || 'builder' } } )
			.then( ( row ) => {
				toast.success( pinned ? __( 'Saved and pinned to the dashboard.', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'Question saved.', 'dev3-ai-store-insights-for-woocommerce' ) );
				if ( onSaved ) {
					onSaved( row );
				}
				onClose();
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<Modal
			open={ !! target }
			onClose={ onClose }
			size="sm"
			title={ __( 'Save question', 'dev3-ai-store-insights-for-woocommerce' ) }
			description={ __( 'Saved questions re-run on fresh data every time you open them.', 'dev3-ai-store-insights-for-woocommerce' ) }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose }>
						{ __( 'Cancel', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
					<Button variant="primary" loading={ busy } onClick={ submit } disabled={ ! title.trim() }>
						{ __( 'Save', 'dev3-ai-store-insights-for-woocommerce' ) }
					</Button>
				</>
			}
		>
			<form onSubmit={ submit }>
				<Stack gap={ 16 }>
					<TextField label={ __( 'Title', 'dev3-ai-store-insights-for-woocommerce' ) } value={ title } onChange={ setTitle } maxLength={ 190 } required data-autofocus />
					<Toggle label={ __( 'Pin to the dashboard', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ pinned } onChange={ setPinned } />
					{ pinned && (
						<Select
							label={ __( 'Card width', 'dev3-ai-store-insights-for-woocommerce' ) }
							value={ size }
							onChange={ setSize }
							options={ [
								{ value: 'half', label: __( 'Half width', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'full', label: __( 'Full width', 'dev3-ai-store-insights-for-woocommerce' ) },
							] }
						/>
					) }
				</Stack>
			</form>
		</Modal>
	);
}
