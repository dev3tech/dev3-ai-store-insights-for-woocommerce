import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import config from '../core3/config';
import ApiTab from '../core3/ApiTab';
import UsageTab from '../core3/UsageTab';
import { AppShell, ErrorNotice, PageHeader, SkeletonCard, UsageMeter, useHashRoute } from '../core3/ui';
import AlertsScreen from './AlertsScreen';
import AskScreen from './AskScreen';
import DashboardScreen from './DashboardScreen';
import DigestsScreen from './DigestsScreen';
import ExploreScreen from './ExploreScreen';
import SavedScreen from './SavedScreen';
import SettingsTab from './SettingsTab';
import { setCurrency } from './format';
import './admin.css';

const IDS = [ 'dashboard', 'ask', 'explore', 'saved', 'digests', 'alerts', 'settings', 'api', 'usage' ];

/**
 * Applies the appearance setting: dark/light tokens on <body> so portals (modals, drawers) follow.
 *
 * @param {string} appearance auto|light|dark.
 */
export function useTheme( appearance ) {
	useEffect( () => {
		const body = document.body;
		const media = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;
		const apply = () => {
			const dark = appearance === 'dark' || ( appearance === 'auto' && media && media.matches );
			body.classList.toggle( 'dev3si-theme-dark', !! dark );
			body.classList.toggle( 'dev3si-theme-light', ! dark );
		};
		apply();
		if ( media && media.addEventListener ) {
			media.addEventListener( 'change', apply );
			return () => media.removeEventListener( 'change', apply );
		}
		return undefined;
	}, [ appearance ] );
}

export default function App() {
	const [ route, navigate ] = useHashRoute( IDS, 'dashboard' );
	const [ settings, setSettings ] = useState( null );
	const [ meta, setMeta ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ meter, setMeter ] = useState( 0 );
	const [ seed, setSeed ] = useState( null );
	const [ alertCount, setAlertCount ] = useState( null );
	useTheme( settings ? settings.appearance : 'auto' );

	const loadMeta = () =>
		request( 'meta' ).then( ( m ) => {
			setCurrency( m.currency );
			setMeta( m );
			return m;
		} );
	const load = () => {
		setError( null );
		Promise.all( [ request( 'settings' ), loadMeta() ] )
			.then( ( [ s ] ) => setSettings( s ) )
			.catch( setError );
		request( 'alerts?status=new&per_page=1' )
			.then( ( res ) => setAlertCount( parseInt( ( res.counts && res.counts.new ) || 0, 10 ) ) )
			.catch( () => {} );
	};
	useEffect( load, [] );

	const save = ( patch ) =>
		request( 'settings', { method: 'POST', data: patch } ).then( ( next ) => {
			setSettings( next );
			setMeter( ( n ) => n + 1 );
			loadMeta().catch( () => {} );
			return next;
		} );

	const usage = () => setMeter( ( n ) => n + 1 );
	const explore = ( query ) => {
		setSeed( { explore: query } );
		navigate( `explore/${ Date.now() }` );
	};

	const NAV = [
		{ id: 'dashboard', label: __( 'Dashboard', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'dashboard' },
		{ id: 'ask', label: __( 'Ask', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'sparkles' },
		{ id: 'explore', label: __( 'Explore', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'chart' },
		{ id: 'saved', label: __( 'Saved', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'list' },
		{ id: 'digests', label: __( 'Digests', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'inbox' },
		{ id: 'alerts', label: __( 'Alerts', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'alert', badge: alertCount || undefined },
		{ id: 'settings', label: __( 'Settings', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'settings' },
		{ id: 'api', label: __( 'API Config', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'key' },
		{ id: 'usage', label: __( 'Usage Logs', 'dev3-ai-store-insights-for-woocommerce' ), icon: 'clock' },
	];

	const screens = {
		dashboard: () => <DashboardScreen meta={ meta } settings={ settings } explore={ explore } reloadMeta={ loadMeta } />,
		ask: () => <AskScreen key={ route.path } meta={ meta } settings={ settings } onUsage={ usage } explore={ explore } seed={ route.params[ 0 ] ? decodeURIComponent( route.params.join( '/' ) ) : '' } />,
		explore: () => <ExploreScreen key={ route.path } meta={ meta } seed={ seed && seed.explore } onUsage={ usage } />,
		saved: () => <SavedScreen explore={ explore } />,
		digests: () => <DigestsScreen meta={ meta } onUsage={ usage } />,
		alerts: () => <AlertsScreen meta={ meta } onUsage={ usage } onCount={ setAlertCount } />,
		settings: () => <SettingsTab settings={ settings } save={ save } meta={ meta } reloadMeta={ loadMeta } />,
		api: () => (
			<>
				<PageHeader title={ __( 'API Config', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Connect Google Gemini, pick models and set a monthly AI budget.', 'dev3-ai-store-insights-for-woocommerce' ) } />
				<ApiTab settings={ settings } save={ save } />
			</>
		),
		usage: () => (
			<>
				<PageHeader title={ __( 'Usage Logs', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Requests, tokens and estimated cost of every DEV3 AI feature.', 'dev3-ai-store-insights-for-woocommerce' ) } />
				<UsageTab />
			</>
		),
	};

	return (
		<AppShell
			name={ config.name || 'DEV3 AI Store Insights for WooCommerce' }
			version={ config.version }
			icon={ config.assetsUrl ? `${ config.assetsUrl }img/icon.svg` : undefined }
			nav={ NAV }
			active={ route.id }
			actions={ <UsageMeter compact refreshKey={ meter } /> }
			footer={ __( 'Made by DEV3 Technologies · AI by Google Gemini, using your own API key.', 'dev3-ai-store-insights-for-woocommerce' ) }
		>
			{ error && <ErrorNotice error={ error } onRetry={ load } /> }
			{ ( ! settings || ! meta ) && ! error && <SkeletonCard lines={ 5 } /> }
			{ settings && meta && screens[ route.id ]() }
		</AppShell>
	);
}
