<?php
/**
 * "Ask your store": question → plan (DEV3 AI function calling or rules) → safe library calls → explanation.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Ask;

use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Api\Api_Error;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Query\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrator.
 */
class Asker {

	const MAX_CALLS = 2;

	/**
	 * Plans a question with Gemini function calling. The model only returns function names and args.
	 *
	 * @param string $question Question.
	 * @return array|\WP_Error [ { fn, args } ]
	 */
	public static function plan_ai( $question ) {
		$ctx    = Prompts::context();
		$result = Ai::run(
			'ask_plan',
			array(
				'system'      => Prompts::plan_system( $ctx ),
				'prompt'      => $question,
				'tools'       => Library::declarations(),
				'tool_mode'   => 'ANY',
				'temperature' => 0,
				'max_tokens'  => 1024,
				'timeout'     => 45,
				'cache'       => HOUR_IN_SECONDS,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$calls = array();
		foreach ( array_slice( (array) $result['function_calls'], 0, self::MAX_CALLS ) as $call ) {
			$clean = Library::sanitize( isset( $call['name'] ) ? $call['name'] : '', isset( $call['args'] ) ? $call['args'] : array() );
			if ( ! is_wp_error( $clean ) ) {
				$calls[] = $clean;
			}
		}
		if ( ! $calls ) {
			return Api_Error::make( 'dev3si_no_plan', __( 'DEV3 AI could not map that question to a report.', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Rephrase it, or use Explore to build the report.', 'dev3-ai-store-insights-for-woocommerce' ), 422 );
		}
		return array(
			'calls'  => $calls,
			'model'  => $result['model'],
			'tokens' => $result['usage']['total'],
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Plans with rules.
	 *
	 * @param string $question Question.
	 * @return array
	 */
	public static function plan_rules( $question ) {
		$parsed = Rule_Parser::parse( $question );
		$clean  = Library::sanitize( $parsed['fn'], $parsed['args'] );
		return array(
			'calls'      => array( $clean ),
			'confidence' => $parsed['confidence'],
		);
	}

	/**
	 * Answers a question.
	 *
	 * @param string $question Question (max 500 chars).
	 * @param string $mode     ai|rules ('' = setting).
	 * @return array|\WP_Error
	 */
	public static function ask( $question, $mode = '' ) {
		$question = mb_substr( trim( sanitize_textarea_field( (string) $question ) ), 0, 500 );
		if ( '' === $question ) {
			return Api_Error::make( 'dev3si_empty_question', __( 'Type a question about your store.', 'dev3-ai-store-insights-for-woocommerce' ), '', 400 );
		}
		$mode     = in_array( $mode, array( 'ai', 'rules' ), true ) ? $mode : (string) Settings::get( 'ask_mode', 'ai' );
		$ai_error = null;
		$meta     = array();
		$source   = 'rules';
		$plan     = null;
		if ( 'ai' === $mode ) {
			if ( Ai::enabled( 'admin' ) ) {
				$plan = self::plan_ai( $question );
				if ( is_wp_error( $plan ) ) {
					$ai_error = Api_Error::to_response( $plan )->get_data();
					$plan     = null;
				} else {
					$source = 'ai';
					$meta   = array(
						'model'  => $plan['model'],
						'tokens' => $plan['tokens'],
						'cached' => $plan['cached'],
					);
				}
			} else {
				$ai_error = array(
					'code'      => 'dev3si_ai_unavailable',
					'message'   => __( 'DEV3 AI is not connected, so the question was answered with the built-in rules.', 'dev3-ai-store-insights-for-woocommerce' ),
					'hint'      => __( 'Add a Google Gemini API key in API Config for better understanding of free-form questions.', 'dev3-ai-store-insights-for-woocommerce' ),
					'retryable' => false,
				);
			}
		}
		if ( null === $plan ) {
			$plan = self::plan_rules( $question );
		}
		$results = array();
		foreach ( $plan['calls'] as $call ) {
			$result = Library::run( $call['fn'], $call['args'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$result['description'] = Library::describe( $result );
			$results[]             = $result;
		}
		$explanation = self::explain( $question, $results );
		$history_id  = Questions::log( $question, $plan['calls'][0], $source );
		/**
		 * Fires after a question is answered.
		 *
		 * @param string $question Question.
		 * @param array  $plan     Plan.
		 * @param array  $results  Results.
		 */
		do_action( 'dev3si_question_asked', $question, $plan, $results );
		return array(
			'question'    => $question,
			'source'      => $source,
			'confidence'  => isset( $plan['confidence'] ) ? $plan['confidence'] : null,
			'plan'        => $plan['calls'],
			'results'     => $results,
			'explanation' => $explanation,
			'ai_error'    => $ai_error,
			'meta'        => $meta,
			'history_id'  => $history_id,
		);
	}

	/**
	 * Explanation with AI when allowed, else the template.
	 *
	 * @param string $question Question.
	 * @param array  $results  Results.
	 * @param bool   $force_ai Ignore the explain setting.
	 * @return array
	 */
	public static function explain( $question, array $results, $force_ai = false ) {
		if ( ( $force_ai || Settings::get( 'explain_answers', true ) ) && Ai::enabled( 'admin' ) ) {
			$ai = Explainer::ai( $question, $results );
			if ( ! is_wp_error( $ai ) && '' !== $ai['headline'] ) {
				return $ai;
			}
			$fallback          = Explainer::template( $results );
			$fallback['error'] = is_wp_error( $ai ) ? Api_Error::to_response( $ai )->get_data() : null;
			return $fallback;
		}
		return Explainer::template( $results );
	}
}
