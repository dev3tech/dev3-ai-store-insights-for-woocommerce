import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { request } from '../core3/api';
import { Badge, Button, Card, FormGrid, JobProgress, NumberField, PageHeader, Select, Stack, TextField, Toggle, useToast } from '../core3/ui';

const KEYS = [ 'appearance', 'default_period', 'ask_mode', 'explain_answers', 'send_customer_names', 'location_basis', 'history_days', 'alerts_enabled', 'alert_recipients', 'dashboard_widget', 'delete_on_uninstall' ];

/**
 * Plugin settings (keys registered in includes/Settings.php).
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.settings   Settings.
 * @param {Function} props.save       Save.
 * @param {Object}   props.meta       Meta (periods, health).
 * @param {Function} props.reloadMeta Reload meta.
 * @return {Element} Settings.
 */
export default function SettingsTab( { settings, save, meta, reloadMeta } ) {
	const toast = useToast();
	const [ form, setForm ] = useState( settings );
	const [ busy, setBusy ] = useState( false );
	const [ job, setJob ] = useState( meta.health && meta.health.job ? meta.health.job : null );
	const set = ( key ) => ( value ) => setForm( { ...form, [ key ]: value } );
	const dirty = KEYS.some( ( k ) => form[ k ] !== settings[ k ] );
	const health = meta.health || {};

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		const patch = {};
		KEYS.forEach( ( k ) => ( patch[ k ] = form[ k ] ) );
		if ( patch.history_days === '' ) {
			patch.history_days = 90;
		}
		save( patch )
			.then( ( next ) => {
				setForm( next );
				toast.success( __( 'Settings saved.', 'dev3-ai-store-insights-for-woocommerce' ) );
			} )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => setBusy( false ) );
	};

	const startImport = () =>
		request( 'health/import', { method: 'POST' } )
			.then( setJob )
			.catch( ( err ) => toast.error( err ) );

	const footer = (
		<Button type="submit" variant="primary" loading={ busy } disabled={ ! dirty }>
			{ __( 'Save settings', 'dev3-ai-store-insights-for-woocommerce' ) }
		</Button>
	);

	return (
		<form onSubmit={ submit }>
			<Stack gap={ 16 }>
				<PageHeader title={ __( 'Settings', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'How Store Insights looks, answers and alerts.', 'dev3-ai-store-insights-for-woocommerce' ) } />
				<Card title={ __( 'Appearance and answers', 'dev3-ai-store-insights-for-woocommerce' ) } footer={ footer }>
					<FormGrid>
						<Select
							label={ __( 'Theme', 'dev3-ai-store-insights-for-woocommerce' ) }
							value={ form.appearance }
							onChange={ set( 'appearance' ) }
							help={ __( 'Applies to this screen and every chart.', 'dev3-ai-store-insights-for-woocommerce' ) }
							options={ [
								{ value: 'auto', label: __( 'Match my device', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'light', label: __( 'Light', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'dark', label: __( 'Dark', 'dev3-ai-store-insights-for-woocommerce' ) },
							] }
						/>
						<Select label={ __( 'Default dashboard period', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.default_period } onChange={ set( 'default_period' ) } options={ meta.periods.filter( ( p ) => p.value !== 'custom' ) } />
						<Select
							label={ __( 'Answer questions with', 'dev3-ai-store-insights-for-woocommerce' ) }
							value={ form.ask_mode }
							onChange={ set( 'ask_mode' ) }
							options={ [
								{ value: 'ai', label: __( 'DEV3 AI (falls back to rules)', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'rules', label: __( 'Built-in rules only', 'dev3-ai-store-insights-for-woocommerce' ) },
							] }
						/>
						<Select
							label={ __( 'Locations come from', 'dev3-ai-store-insights-for-woocommerce' ) }
							value={ form.location_basis }
							onChange={ set( 'location_basis' ) }
							options={ [
								{ value: 'billing', label: __( 'Billing address', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'shipping', label: __( 'Shipping address', 'dev3-ai-store-insights-for-woocommerce' ) },
							] }
						/>
					</FormGrid>
					<Stack gap={ 12 } className="dev3si-mt">
						<Toggle label={ __( 'Explain every answer with DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) } help={ __( 'Off: answers get a short summary written by the plugin (no AI tokens).', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! form.explain_answers } onChange={ set( 'explain_answers' ) } />
						<Toggle label={ __( 'Show the WordPress dashboard widget', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! form.dashboard_widget } onChange={ set( 'dashboard_widget' ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Privacy', 'dev3-ai-store-insights-for-woocommerce' ) } footer={ footer }>
					<Stack gap={ 12 }>
						<Toggle label={ __( 'Send customer names to Google Gemini when explaining top-customer reports', 'dev3-ai-store-insights-for-woocommerce' ) } help={ __( 'Off (recommended): names are replaced with “Customer 1, 2…”. E-mail addresses are never sent.', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! form.send_customer_names } onChange={ set( 'send_customer_names' ) } />
						<NumberField label={ __( 'Keep question history for (days)', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.history_days } min={ 7 } max={ 730 } onChange={ set( 'history_days' ) } help={ __( 'Saved questions are kept until you delete them.', 'dev3-ai-store-insights-for-woocommerce' ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Alerts', 'dev3-ai-store-insights-for-woocommerce' ) } footer={ footer }>
					<Stack gap={ 12 }>
						<Toggle label={ __( 'Check alert rules every day', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! form.alerts_enabled } onChange={ set( 'alerts_enabled' ) } />
						<TextField label={ __( 'Alert recipients', 'dev3-ai-store-insights-for-woocommerce' ) } value={ form.alert_recipients || '' } onChange={ set( 'alert_recipients' ) } placeholder={ __( 'Site admin e-mail', 'dev3-ai-store-insights-for-woocommerce' ) } help={ __( 'Comma-separated. Rules can override this.', 'dev3-ai-store-insights-for-woocommerce' ) } />
					</Stack>
				</Card>
				<Card title={ __( 'Data health', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Insights read WooCommerce Analytics tables, which WooCommerce fills in the background.', 'dev3-ai-store-insights-for-woocommerce' ) }>
					<Stack gap={ 12 }>
						<dl className="dev3si-dl">
							<dt>{ __( 'Order storage', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
							<dd>{ health.hpos ? __( 'High-performance order storage (HPOS)', 'dev3-ai-store-insights-for-woocommerce' ) : __( 'WordPress posts (legacy)', 'dev3-ai-store-insights-for-woocommerce' ) }</dd>
							<dt>{ __( 'Orders in Analytics', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
							<dd>{ ( health.orders || 0 ).toLocaleString() }</dd>
							<dt>{ __( 'Missing from Analytics', 'dev3-ai-store-insights-for-woocommerce' ) }</dt>
							<dd>{ health.missing ? <Badge tone="warning">{ sprintf( /* translators: %d: orders */ __( '%d orders', 'dev3-ai-store-insights-for-woocommerce' ), health.missing ) }</Badge> : <Badge tone="success">{ __( 'None', 'dev3-ai-store-insights-for-woocommerce' ) }</Badge> }</dd>
						</dl>
						{ health.missing > 0 && ! job && (
							<div>
								<Button onClick={ startImport } icon="refresh">
									{ __( 'Import missing orders', 'dev3-ai-store-insights-for-woocommerce' ) }
								</Button>
							</div>
						) }
						{ job && <JobProgress jobId={ job.id } job={ job } title={ __( 'Importing orders into Analytics', 'dev3-ai-store-insights-for-woocommerce' ) } onDone={ reloadMeta } /> }
					</Stack>
				</Card>
				<Card title={ __( 'Uninstall', 'dev3-ai-store-insights-for-woocommerce' ) } footer={ footer }>
					<Toggle label={ __( 'Delete saved questions, digests, alerts and settings when the plugin is deleted', 'dev3-ai-store-insights-for-woocommerce' ) } checked={ !! form.delete_on_uninstall } onChange={ set( 'delete_on_uninstall' ) } help={ __( 'WooCommerce data is never touched.', 'dev3-ai-store-insights-for-woocommerce' ) } />
				</Card>
			</Stack>
		</form>
	);
}
