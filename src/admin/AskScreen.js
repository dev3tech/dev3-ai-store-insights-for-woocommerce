import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../core3/api';
import { AiButton, Button, Card, ErrorNotice, Notice, PageHeader, Select, SkeletonCard, Stack, useToast } from '../core3/ui';
import ResultView from './ResultView';
import SaveModal from './SaveModal';

/**
 * "Ask your store" screen.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.meta     Meta (suggestions, AI status).
 * @param {Object}   props.settings Settings.
 * @param {Function} props.onUsage  Refresh usage pill.
 * @param {Function} props.explore  Open a query in Explore.
 * @param {string}   props.seed     Question to ask on mount.
 * @return {Element} Screen.
 */
export default function AskScreen( { meta, settings, onUsage, explore, seed } ) {
	const toast = useToast();
	const [ question, setQuestion ] = useState( seed || '' );
	const [ mode, setMode ] = useState( settings.ask_mode || 'ai' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ answer, setAnswer ] = useState( null );
	const [ history, setHistory ] = useState( [] );
	const [ saving, setSaving ] = useState( null );
	const [ explaining, setExplaining ] = useState( false );
	const inputRef = useRef( null );
	const aiReady = meta.ai && meta.ai.key_set && meta.ai.enabled;

	const loadHistory = () =>
		request( 'questions?view=history' )
			.then( setHistory )
			.catch( () => {} );

	const ask = ( q ) => {
		const text = String( q !== undefined ? q : question ).trim();
		if ( ! text || busy ) {
			return;
		}
		setQuestion( text );
		setBusy( true );
		setError( null );
		request( 'ask', { method: 'POST', data: { question: text, mode } } )
			.then( ( res ) => {
				setAnswer( res );
				loadHistory();
			} )
			.catch( setError )
			.finally( () => {
				setBusy( false );
				onUsage();
			} );
	};

	useEffect( () => {
		loadHistory();
		if ( seed ) {
			ask( seed );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const explainAi = () => {
		const r = answer.results[ 0 ];
		setExplaining( true );
		request( 'explain', { method: 'POST', data: { fn: r.fn, args: r.args, question: answer.question } } )
			.then( ( exp ) => setAnswer( { ...answer, explanation: exp } ) )
			.catch( ( err ) => toast.error( err ) )
			.finally( () => {
				setExplaining( false );
				onUsage();
			} );
	};

	const onKeyDown = ( e ) => {
		if ( e.key === 'Enter' && ! e.shiftKey ) {
			e.preventDefault();
			ask();
		}
	};

	return (
		<Stack gap={ 16 }>
			<PageHeader title={ __( 'Ask your store', 'dev3-ai-store-insights-for-woocommerce' ) } description={ __( 'Ask in plain English. DEV3 AI picks a safe, ready-made report and fills in the details; it never writes database queries.', 'dev3-ai-store-insights-for-woocommerce' ) } />
			<Card tone="ai" className="dev3si-ask">
				<form
					onSubmit={ ( e ) => {
						e.preventDefault();
						ask();
					} }
				>
					<label className="dev3si-ask__label" htmlFor="dev3si-ask-input">
						{ __( 'Your question', 'dev3-ai-store-insights-for-woocommerce' ) }
					</label>
					<div className="dev3si-ask__row">
						<textarea
							id="dev3si-ask-input"
							ref={ inputRef }
							className="dev3si-ui-input dev3si-ask__input"
							rows={ 2 }
							maxLength={ 500 }
							value={ question }
							placeholder={ __( 'e.g. Best sellers in Dubai last month', 'dev3-ai-store-insights-for-woocommerce' ) }
							onChange={ ( e ) => setQuestion( e.target.value ) }
							onKeyDown={ onKeyDown }
						/>
						{ mode === 'ai' ? (
							<AiButton type="submit" loading={ busy } loadingText={ __( 'Thinking…', 'dev3-ai-store-insights-for-woocommerce' ) } disabled={ ! question.trim() }>
								{ __( 'Ask DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) }
							</AiButton>
						) : (
							<Button type="submit" variant="primary" icon="search" loading={ busy } disabled={ ! question.trim() }>
								{ __( 'Ask', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Button>
						) }
					</div>
					<div className="dev3si-ask__meta">
						<Select
							label={ __( 'Answer with', 'dev3-ai-store-insights-for-woocommerce' ) }
							value={ mode }
							onChange={ setMode }
							options={ [
								{ value: 'ai', label: __( 'DEV3 AI (free-form questions)', 'dev3-ai-store-insights-for-woocommerce' ) },
								{ value: 'rules', label: __( 'Built-in rules (no AI)', 'dev3-ai-store-insights-for-woocommerce' ) },
							] }
						/>
						<p className="dev3si-ask__hint">{ __( 'Press Enter to ask, Shift+Enter for a new line.', 'dev3-ai-store-insights-for-woocommerce' ) }</p>
					</div>
				</form>
				<div className="dev3si-suggestions" aria-label={ __( 'Suggested questions', 'dev3-ai-store-insights-for-woocommerce' ) } role="group">
					{ ( meta.suggestions || [] ).map( ( s ) => (
						<button type="button" key={ s } className="dev3si-suggest" onClick={ () => ask( s ) } disabled={ busy }>
							{ s }
						</button>
					) ) }
				</div>
			</Card>
			{ mode === 'ai' && ! aiReady && (
				<Notice tone="info" title={ __( 'DEV3 AI is not connected', 'dev3-ai-store-insights-for-woocommerce' ) } actions={ <Button size="sm" href="#/api">{ __( 'Connect DEV3 AI', 'dev3-ai-store-insights-for-woocommerce' ) }</Button> }>
					{ __( 'Questions are answered with the built-in rules until you add a Google Gemini API key.', 'dev3-ai-store-insights-for-woocommerce' ) }
				</Notice>
			) }
			{ error && <ErrorNotice error={ error } onRetry={ () => ask() } /> }
			{ busy && ! answer && <SkeletonCard lines={ 6 } /> }
			{ answer && (
				<div className={ busy ? 'dev3si-refetch' : undefined }>
					<Stack gap={ 16 }>
						{ answer.ai_error && answer.ai_error.code !== 'dev3si_ai_unavailable' && <ErrorNotice error={ { ...answer.ai_error, message: `${ answer.ai_error.message } ${ __( 'Answered with the built-in rules instead.', 'dev3-ai-store-insights-for-woocommerce' ) }` } } /> }
						{ answer.source === 'rules' && answer.confidence !== null && answer.confidence < 0.5 && (
							<Notice tone="warning" title={ __( 'Best guess', 'dev3-ai-store-insights-for-woocommerce' ) }>
								{ __( 'The built-in rules were not sure what you meant. Check the interpretation below, use Edit query, or connect DEV3 AI.', 'dev3-ai-store-insights-for-woocommerce' ) }
							</Notice>
						) }
						{ answer.results.map( ( r, i ) => (
							<ResultView
								key={ `${ answer.history_id }-${ i }` }
								result={ r }
								source={ answer.source }
								explanation={ i === 0 ? answer.explanation : null }
								onAsk={ ( q ) => ask( q ) }
								onExplainAi={ aiReady ? explainAi : null }
								explaining={ explaining }
								onSave={ () => setSaving( { query: { fn: r.fn, args: r.args }, question: answer.question, title: answer.question, source: answer.source } ) }
								onExplore={ () => explore( { fn: r.fn, args: r.args } ) }
							/>
						) ) }
					</Stack>
				</div>
			) }
			{ history.length > 0 && (
				<Card title={ __( 'Recent questions', 'dev3-ai-store-insights-for-woocommerce' ) }>
					<ul className="dev3si-history">
						{ history.slice( 0, 8 ).map( ( h ) => (
							<li key={ h.id }>
								<button type="button" className="dev3si-history__q" onClick={ () => ask( h.question ) }>
									{ h.question }
								</button>
								<span className="dev3si-history__d">{ h.description }</span>
							</li>
						) ) }
					</ul>
				</Card>
			) }
			<SaveModal target={ saving } onClose={ () => setSaving( null ) } />
		</Stack>
	);
}
