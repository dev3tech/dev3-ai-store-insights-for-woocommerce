import apiFetch from '@wordpress/api-fetch';
import { ToastProvider } from '../../src/core3/ui';
import { BarChart, Delta, Heatmap, KpiTiles, LineChart, ShareBar } from '../../src/admin/charts';
import { fit, fmt, formatCompact, money, niceTicks, setCurrency } from '../../src/admin/format';
import ResultView, { exportUrl, ResultTable } from '../../src/admin/ResultView';
import AskScreen from '../../src/admin/AskScreen';
import ExploreScreen, { QueryForm, defaultsFor } from '../../src/admin/ExploreScreen';
import SaveModal from '../../src/admin/SaveModal';
import { scheduleText } from '../../src/admin/DigestsScreen';
import { ruleText } from '../../src/admin/AlertsScreen';
import { change, click, key, render } from './render';

afterEach( () => {
	document.body.innerHTML = '';
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => new Promise( () => {} ) );
} );

const LINE = {
	fn: 'sales_trend',
	args: { metric: 'net_sales', period: 'last_7_days' },
	title: 'Net sales by day',
	description: 'Sales trend over time · Net sales · Last 7 days',
	period: { label: 'Last 7 days', range: '19 Oct 2026 – 25 Oct 2026' },
	compare_period: { label: 'Previous period', range: '12 Oct 2026 – 18 Oct 2026' },
	chart: { type: 'line', format: 'money', series: [ { key: 'value', label: 'Last 7 days' }, { key: 'previous', label: 'Previous period', muted: true } ] },
	columns: [ { key: 'label', label: 'Day', format: 'text' }, { key: 'value', label: 'Net sales', format: 'money' }, { key: 'previous', label: 'Previous period', format: 'money' } ],
	rows: [ 1, 2, 3, 4, 5, 6, 7 ].map( ( i ) => ( { key: `2026-10-${ 18 + i }`, label: `${ 18 + i } Oct`, value: i * 100, previous: i * 80, previous_label: `${ 11 + i } Oct` } ) ),
	summary: { metric: 'net_sales', label: 'Net sales', value: 2800, previous: 2240, change: 25, format: 'money' },
	notes: [ 'Location: Dubai' ],
};

const BARS = {
	fn: 'top_products',
	args: { metric: 'revenue', period: 'last_month', location: 'Dubai' },
	title: 'Top products',
	description: 'Top products · Net sales · Last month · Dubai',
	period: { label: 'Last month', range: '1 Sep 2026 – 30 Sep 2026' },
	chart: { type: 'bar', label: 'name', value: 'revenue', format: 'money' },
	columns: [ { key: 'name', label: 'Product', format: 'text' }, { key: 'revenue', label: 'Net sales', format: 'money' }, { key: 'quantity', label: 'Items sold', format: 'number' }, { key: 'share', label: 'Share', format: 'percent' } ],
	rows: [
		{ id: 1, name: 'Desert Rose Perfume', revenue: 1200, quantity: 12, share: 60 },
		{ id: 2, name: '=HYPERLINK Oud Candle', revenue: 800, quantity: 20, share: 40 },
	],
	summary: { metric: 'revenue', label: 'Net sales', value: 2000, format: 'money', top: 'Desert Rose Perfume', share: 60 },
	notes: [],
};

describe( 'format', () => {
	it( 'formats money with the store currency and compact numbers', () => {
		setCurrency( { code: 'AED', symbol: 'AED', decimals: 2, position: 'right_space' } );
		expect( money( 1234.5 ) ).toBe( '1,235 AED' );
		expect( money( 12.5 ) ).toBe( '12.50 AED' );
		setCurrency( { code: 'USD', symbol: '$', decimals: 2, position: 'left' } );
		expect( money( 1500000, true ) ).toBe( '$1.5M' );
		expect( money( -20 ) ).toBe( '-$20.00' );
		expect( formatCompact( 12900 ) ).toBe( '12.9K' );
		expect( fmt( 12.345, 'percent' ) ).toBe( '12%' );
		expect( fmt( 4.25, 'change' ) ).toBe( '+4.3%' );
		expect( fmt( null, 'money' ) ).toBe( '–' );
	} );

	it( 'builds nice ticks and fits labels', () => {
		expect( niceTicks( 0, 950, 4 ) ).toEqual( [ 0, 500, 1000 ] );
		expect( niceTicks( 0, 0 ) ).toEqual( [ 0, 0.5, 1 ] );
		expect( fit( 'A very long product name indeed', 70 ) ).toBe( 'A very lo…' );
	} );
} );

describe( 'charts', () => {
	it( 'renders a keyboard-readable line chart with a comparison series', async () => {
		const view = await render( <LineChart rows={ LINE.rows } series={ LINE.chart.series } format="money" label="Net sales" /> );
		expect( view.$$( 'path.dev3si-line' ) ).toHaveLength( 2 );
		expect( view.$( 'path.dev3si-line.is-muted' ) ).not.toBeNull();
		const box = view.$( '.dev3si-chart' );
		expect( box.getAttribute( 'tabindex' ) ).toBe( '0' );
		await key( box, 'End' );
		expect( view.$( '.dev3si-tip' ).textContent ).toContain( '25 Oct' );
		expect( view.$( '.dev3si-tip' ).textContent ).toContain( '$700' );
		await key( box, 'ArrowLeft' );
		expect( view.$( '.dev3si-tip' ).textContent ).toContain( '24 Oct' );
		await key( box, 'Escape' );
		expect( view.$( '.dev3si-tip' ) ).toBeNull();
		view.unmount();
	} );

	it( 'renders ranked bars with focusable, labelled rows', async () => {
		const view = await render( <BarChart rows={ BARS.rows } value="revenue" format="money" columns={ BARS.columns } name="Top products" /> );
		const items = view.$$( 'g[role="listitem"]' );
		expect( items ).toHaveLength( 2 );
		expect( items[ 0 ].getAttribute( 'aria-label' ) ).toBe( 'Desert Rose Perfume: $1,200, 60%' );
		expect( view.$$( 'path.dev3si-barfill' ) ).toHaveLength( 2 );
		view.unmount();
	} );

	it( 'renders a share bar with a legend and percentages', async () => {
		const rows = [ { name: 'New customers', net_sales: 300 }, { name: 'Returning customers', net_sales: 700 } ];
		const view = await render( <ShareBar rows={ rows } value="net_sales" format="money" name="Segments" /> );
		expect( view.$$( 'rect.dev3si-seg' ) ).toHaveLength( 2 );
		expect( view.getByText( '70%' ) ).toBeTruthy();
		expect( view.$( 'svg' ).getAttribute( 'aria-label' ) ).toContain( 'Returning customers 70%' );
		view.unmount();
	} );

	it( 'renders a cohort heatmap with sequential steps and empty future cells', async () => {
		const rows = [
			{ cohort: 'Sep 2026', size: 10, m0: 100, m1: 30 },
			{ cohort: 'Oct 2026', size: 5, m0: 100, m1: null },
		];
		const columns = [ { key: 'cohort', label: 'First order' }, { key: 'size', label: 'Customers' }, { key: 'm0', label: 'Month 0' }, { key: 'm1', label: '+1 mo' } ];
		const view = await render( <Heatmap rows={ rows } columns={ columns } cells={ [ 'm0', 'm1' ] } format="percent" name="Retention" /> );
		expect( view.$$( 'td.dev3si-heat__cell' ) ).toHaveLength( 3 );
		expect( view.$( 'td.is-s9' ).textContent ).toBe( '100%' );
		expect( view.$$( 'td.dev3si-heat__na' ) ).toHaveLength( 1 );
		view.unmount();
	} );

	it( 'shows deltas with arrow, sign and better/worse text (not colour alone)', async () => {
		const view = await render(
			<div>
				<Delta change={ 12 } good="up" vs="Previous period" />
				<Delta change={ 12 } good="down" />
			</div>
		);
		const deltas = view.$$( '.dev3si-delta' );
		expect( deltas[ 0 ].className ).toContain( 'is-good' );
		expect( deltas[ 0 ].textContent ).toContain( '▲' );
		expect( deltas[ 0 ].textContent ).toContain( '(better)' );
		expect( deltas[ 1 ].className ).toContain( 'is-bad' );
		view.unmount();
	} );

	it( 'renders KPI tiles', async () => {
		const items = [ { key: 'net_sales', label: 'Net sales', value: 5000, change: -10, format: 'money', good: 'up' }, { key: 'orders', label: 'Orders', value: 42, change: null, format: 'number', good: 'up' } ];
		const view = await render( <KpiTiles items={ items } compare="Previous period" /> );
		expect( view.$$( '.dev3si-kpi' ) ).toHaveLength( 2 );
		expect( view.getByText( '$5,000' ) ).toBeTruthy();
		expect( view.$( '.dev3si-delta.is-bad' ) ).not.toBeNull();
		view.unmount();
	} );
} );

describe( 'answer card', () => {
	it( 'toggles chart/table, shows the interpretation, notes and a CSV link with the nonce', async () => {
		const view = await render(
			<ToastProvider>
				<ResultView result={ LINE } source="ai" explanation={ { headline: 'Net sales rose 25%.', summary: 'Good week.', highlights: [ 'Best day: 25 Oct' ], follow_ups: [ 'Top products last 7 days' ], source: 'ai' } } onSave={ () => {} } />
			</ToastProvider>
		);
		expect( view.getByText( 'DEV3 AI read this as' ) ).toBeTruthy();
		expect( view.getByText( 'Location: Dubai' ) ).toBeTruthy();
		expect( view.getByText( 'Net sales rose 25%.' ) ).toBeTruthy();
		expect( view.$( 'svg' ) ).not.toBeNull();
		await click( view.getByText( 'Table' ) );
		expect( view.$( 'table' ) ).not.toBeNull();
		expect( view.getByText( '$700' ) ).toBeTruthy();
		const csv = view.$$( 'a' ).find( ( a ) => a.textContent === 'CSV' );
		expect( csv.getAttribute( 'href' ) ).toContain( 'export?fn=sales_trend' );
		expect( csv.getAttribute( 'href' ) ).toContain( '_wpnonce=test-nonce' );
		view.unmount();
	} );

	it( 'builds export URLs with overrides', () => {
		const url = exportUrl( 'top_products', { limit: 5 }, { period: 'this_month' } );
		expect( url ).toContain( '/wp-json/dev3si/v1/export?' );
		expect( decodeURIComponent( url ) ).toContain( '"period":"this_month"' );
	} );

	it( 'renders a table with formatted values', async () => {
		const view = await render( <ResultTable result={ BARS } /> );
		expect( view.getByText( '$1,200' ) ).toBeTruthy();
		expect( view.getByText( '60%' ) ).toBeTruthy();
		view.unmount();
	} );
} );

const META = {
	functions: [
		{
			id: 'top_products',
			label: 'Top products',
			params: [
				{ id: 'metric', type: 'enum', default: 'revenue', options: [ { value: 'revenue', label: 'Net sales' }, { value: 'quantity', label: 'Items sold' } ] },
				{ id: 'limit', type: 'int', min: 1, max: 50, default: 10 },
				{ id: 'period', type: 'enum', default: 'last_30_days', options: [ { value: 'last_30_days', label: 'Last 30 days' }, { value: 'custom', label: 'Custom range' } ] },
				{ id: 'from', type: 'date' },
				{ id: 'to', type: 'date' },
				{ id: 'location', type: 'string' },
			],
		},
		{ id: 'cohorts', label: 'Customer cohorts', params: [ { id: 'months', type: 'int', min: 3, max: 12, default: 6 } ] },
	],
	periods: [ { value: 'last_30_days', label: 'Last 30 days' } ],
	locations: [ 'Dubai', 'Germany' ],
	categories: [ 'Perfume' ],
	suggestions: [ 'Best sellers last month' ],
	ai: { key_set: true, enabled: true },
};

describe( 'ask + explore', () => {
	it( 'asks a question and shows the answer, explanation and follow-ups', async () => {
		apiFetch.mockImplementation( ( { url, method } ) => {
			if ( url.includes( 'questions?view=history' ) ) {
				return Promise.resolve( [] );
			}
			if ( url.endsWith( '/ask' ) && method === 'POST' ) {
				return Promise.resolve( { question: 'best sellers in Dubai last month', source: 'ai', plan: [ { fn: 'top_products', args: BARS.args } ], results: [ BARS ], explanation: { headline: 'Desert Rose Perfume led with $1,200.', summary: '', highlights: [], follow_ups: [ 'Top categories last month' ], source: 'ai' }, ai_error: null, history_id: 7 } );
			}
			return new Promise( () => {} );
		} );
		const view = await render(
			<ToastProvider>
				<AskScreen meta={ META } settings={ { ask_mode: 'ai' } } onUsage={ () => {} } explore={ () => {} } />
			</ToastProvider>
		);
		await change( view.$( '#dev3si-ask-input' ), 'best sellers in Dubai last month' );
		await click( view.getByText( 'Ask DEV3 AI' ) );
		await view.flush();
		const call = apiFetch.mock.calls.find( ( c ) => c[ 0 ].url.endsWith( '/ask' ) );
		expect( call[ 0 ].data ).toEqual( { question: 'best sellers in Dubai last month', mode: 'ai' } );
		expect( call[ 0 ].headers[ 'X-WP-Nonce' ] ).toBe( 'test-nonce' );
		expect( view.getByText( 'Desert Rose Perfume led with $1,200.' ) ).toBeTruthy();
		expect( view.getByText( 'Top categories last month' ) ).toBeTruthy();
		expect( view.$$( 'g[role="listitem"]' ) ).toHaveLength( 2 );
		view.unmount();
	} );

	it( 'falls back to rules mode without AI and says so', async () => {
		apiFetch.mockImplementation( ( { url } ) => ( url.includes( 'history' ) ? Promise.resolve( [] ) : new Promise( () => {} ) ) );
		const view = await render(
			<ToastProvider>
				<AskScreen meta={ { ...META, ai: { key_set: false, enabled: true } } } settings={ { ask_mode: 'ai' } } onUsage={ () => {} } explore={ () => {} } />
			</ToastProvider>
		);
		expect( view.getByText( 'DEV3 AI is not connected' ) ).toBeTruthy();
		await change( view.$( 'select' ), 'rules' );
		expect( view.getByText( 'Ask' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'renders the query form, switches reports and shows custom dates only for custom periods', async () => {
		let query = { fn: 'top_products', args: defaultsFor( META.functions[ 0 ] ) };
		const onChange = jest.fn( ( q ) => ( query = q ) );
		const view = await render( <QueryForm meta={ META } query={ query } onChange={ onChange } /> );
		expect( view.$$( 'input[type="date"]' ) ).toHaveLength( 0 );
		expect( view.$( 'datalist#dev3si-list-location' ) ).not.toBeNull();
		await view.rerender( <QueryForm meta={ META } query={ { ...query, args: { ...query.args, period: 'custom' } } } onChange={ onChange } /> );
		expect( view.$$( 'input[type="date"]' ) ).toHaveLength( 2 );
		await change( view.$$( 'select' )[ 0 ], 'cohorts' );
		expect( onChange ).toHaveBeenLastCalledWith( { fn: 'cohorts', args: { months: 6 } } );
		view.unmount();
	} );

	it( 'runs the explore report on mount', async () => {
		apiFetch.mockImplementation( ( { url } ) => ( url.endsWith( '/run' ) ? Promise.resolve( { result: BARS, explanation: { headline: 'Template headline', source: 'template', follow_ups: [] } } ) : new Promise( () => {} ) ) );
		const view = await render(
			<ToastProvider>
				<ExploreScreen meta={ META } onUsage={ () => {} } />
			</ToastProvider>
		);
		await view.flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { fn: 'top_products', args: { metric: 'revenue', limit: 10, period: 'last_30_days' }, explain: true } );
		expect( view.getByText( 'Template headline' ) ).toBeTruthy();
		expect( view.getByText( 'Explain with DEV3 AI' ) ).toBeTruthy();
		view.unmount();
	} );

	it( 'saves and pins a question', async () => {
		apiFetch.mockResolvedValueOnce( { id: 3, title: 'Best sellers' } );
		const onSaved = jest.fn();
		const view = await render(
			<ToastProvider>
				<SaveModal target={ { query: { fn: 'top_products', args: {} }, question: 'best sellers', title: 'Best sellers', source: 'rules' } } onClose={ () => {} } onSaved={ onSaved } />
			</ToastProvider>
		);
		await click( view.getByText( 'Save' ) );
		await view.flush();
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { title: 'Best sellers', question: 'best sellers', query: { fn: 'top_products', args: {} }, pinned: true, pin_size: 'half', source: 'rules' } );
		expect( onSaved ).toHaveBeenCalledWith( { id: 3, title: 'Best sellers' } );
		view.unmount();
	} );
} );

describe( 'digests + alerts text', () => {
	it( 'describes schedules and rules', () => {
		expect( scheduleText( { frequency: 'weekly', weekday: 1, hour: 8 } ) ).toBe( 'Every Monday at 08:00' );
		expect( scheduleText( { frequency: 'monthly', monthday: 3, hour: 17 } ) ).toBe( 'Monthly on day 3 at 17:00' );
		expect( scheduleText( { frequency: 'daily', hour: 6 } ) ).toBe( 'Every day at 06:00' );
		expect( ruleText( { type: 'metric', metric: 'net_sales', direction: 'drop', min_change_pct: 30 } ) ).toBe( 'Drops in daily net sales of at least 30% vs the 28-day average' );
		expect( ruleText( { type: 'stock', days: 7 } ) ).toContain( '7 days' );
	} );
} );
