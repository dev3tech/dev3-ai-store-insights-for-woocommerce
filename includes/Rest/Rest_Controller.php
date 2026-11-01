<?php
/**
 * Plugin REST routes (dev3si/v1). Every route needs the REST nonce and the manage capability.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Rest;

use Dev3\AiStoreInsights\Ask\Asker;
use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Api\Api_Error;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Core3\Rest\Base_Controller;
use Dev3\AiStoreInsights\Data\Csv;
use Dev3\AiStoreInsights\Data\Health;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Insights\Alerts;
use Dev3\AiStoreInsights\Insights\Digests;
use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
class Rest_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			array( '/meta', 'GET', 'meta' ),
			array( '/ask', 'POST', 'ask' ),
			array( '/run', 'POST', 'run' ),
			array( '/explain', 'POST', 'explain' ),
			array( '/questions', 'GET', 'questions' ),
			array( '/questions', 'POST', 'create_question' ),
			array( '/questions/reorder', 'POST', 'reorder' ),
			array( '/questions/(?P<id>\d+)', 'POST', 'update_question' ),
			array( '/questions/(?P<id>\d+)', 'DELETE', 'delete_question' ),
			array( '/export', 'GET', 'export' ),
			array( '/digests', 'GET', 'digests' ),
			array( '/digests', 'POST', 'save_digest' ),
			array( '/digests/(?P<id>[a-z0-9-]+)', 'DELETE', 'delete_digest' ),
			array( '/digests/(?P<id>[a-z0-9-]+)/test', 'POST', 'test_digest' ),
			array( '/digests/(?P<id>[a-z0-9-]+)/preview', 'GET', 'preview_digest' ),
			array( '/alerts', 'GET', 'alerts' ),
			array( '/alerts/(?P<id>\d+)', 'POST', 'update_alert' ),
			array( '/alerts/(?P<id>\d+)/explain', 'POST', 'explain_alert' ),
			array( '/alert-rules', 'GET', 'alert_rules' ),
			array( '/alert-rules', 'POST', 'save_alert_rules' ),
			array( '/alert-rules/check', 'POST', 'check_alerts' ),
			array( '/alert-rules/advice', 'POST', 'alert_advice' ),
			array( '/health', 'GET', 'health' ),
			array( '/health/import', 'POST', 'import' ),
		);
		foreach ( $routes as $route ) {
			register_rest_route(
				self::NS,
				$route[0],
				array(
					'methods'             => $route[1],
					'callback'            => array( $this, $route[2] ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Decodes a JSON-or-array param.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function arr( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		return is_array( $value ) ? $value : array();
	}

	/**
	 * GET /meta.
	 *
	 * @return \WP_REST_Response
	 */
	public function meta() {
		$known = Store::known_locations();
		$names = array();
		foreach ( $known['countries'] as $code ) {
			$names[] = Store::country_name( $code );
		}
		return $this->respond(
			array(
				'functions'   => Library::catalogue(),
				'periods'     => array_map(
					static function ( $id, $label ) {
						return array(
							'value' => $id,
							'label' => $label,
						);
					},
					array_keys( Period::labels() ),
					array_values( Period::labels() )
				),
				'locations'   => array_values( array_unique( array_merge( $names, array_slice( $known['cities'], 0, 60 ) ) ) ),
				'categories'  => Store::category_names( 100 ),
				'currency'    => Store::currency(),
				'health'      => Health::status(),
				'ai'          => Ai::status(),
				'today'       => Period::now()->format( 'Y-m-d' ),
				'suggestions' => array(
					__( 'Best sellers last month', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'How did the store do this month?', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'Net sales by week for the last 90 days', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'Top 5 cities by revenue this year', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'New vs returning customers last 30 days', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'Which products will run out of stock first?', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'Customer retention cohorts', 'dev3-ai-store-insights-for-woocommerce' ),
					__( 'Refunds over the last 90 days', 'dev3-ai-store-insights-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * POST /ask { question, mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ask( \WP_REST_Request $request ) {
		return $this->respond( Asker::ask( (string) $request['question'], (string) $request['mode'] ) );
	}

	/**
	 * POST /run { fn, args, over, question_id, explain }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function run( \WP_REST_Request $request ) {
		$over = self::arr( $request['over'] );
		$over = array_intersect_key( $over, array_flip( array( 'period', 'from', 'to' ) ) );
		if ( isset( $over['period'] ) && ( ! in_array( $over['period'], Period::PRESETS, true ) || 'saved' === $over['period'] ) ) {
			$over = array();
		}
		$result = Library::run( (string) $request['fn'], self::arr( $request['args'] ), $over );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$result['description'] = Library::describe( $result );
		if ( $request['question_id'] ) {
			Questions::touch( (int) $request['question_id'] );
		}
		$out = array( 'result' => $result );
		if ( $request['explain'] ) {
			$out['explanation'] = Asker::explain( '', array( $result ) );
		}
		return $this->respond( $out );
	}

	/**
	 * POST /explain { fn, args, question }: re-runs the query server-side and explains it with DEV3 AI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain( \WP_REST_Request $request ) {
		$result = Library::run( (string) $request['fn'], self::arr( $request['args'] ) );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		if ( ! Ai::enabled( 'admin' ) ) {
			return $this->respond( Api_Error::missing_key() );
		}
		$question = mb_substr( sanitize_textarea_field( (string) $request['question'] ), 0, 500 );
		return $this->respond( Asker::explain( $question, array( $result ), true ) );
	}

	/**
	 * GET /questions?view=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function questions( \WP_REST_Request $request ) {
		$view = in_array( $request['view'], array( 'saved', 'pinned', 'history' ), true ) ? $request['view'] : 'saved';
		return $this->respond( Questions::all( $view ) );
	}

	/**
	 * POST /questions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function create_question( \WP_REST_Request $request ) {
		$row = Questions::create(
			array(
				'title'    => $request['title'],
				'question' => $request['question'],
				'query'    => self::arr( $request['query'] ),
				'pinned'   => (bool) $request['pinned'],
				'pin_size' => $request['pin_size'],
				'source'   => $request['source'],
			)
		);
		return $this->respond( $row, 201 );
	}

	/**
	 * POST /questions/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_question( \WP_REST_Request $request ) {
		$data = array();
		foreach ( array( 'title', 'saved', 'pinned', 'pin_size' ) as $key ) {
			if ( null !== $request[ $key ] ) {
				$data[ $key ] = in_array( $key, array( 'saved', 'pinned' ), true ) ? rest_sanitize_boolean( $request[ $key ] ) : (string) $request[ $key ];
			}
		}
		if ( null !== $request['query'] ) {
			$data['query'] = self::arr( $request['query'] );
		}
		$row = Questions::update( (int) $request['id'], $data );
		if ( null === $row ) {
			return $this->respond( Api_Error::make( 'dev3si_not_found', __( 'Question not found.', 'dev3-ai-store-insights-for-woocommerce' ), '', 404 ) );
		}
		return $this->respond( $row );
	}

	/**
	 * DELETE /questions/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_question( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Questions::delete( (int) $request['id'] ) ) );
	}

	/**
	 * POST /questions/reorder { ids }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reorder( \WP_REST_Request $request ) {
		Questions::reorder( array_map( 'absint', (array) $request['ids'] ) );
		return $this->respond( Questions::all( 'pinned' ) );
	}

	/**
	 * GET /export?fn=&args=&over= → CSV.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$over   = array_intersect_key( self::arr( $request['over'] ), array_flip( array( 'period' ) ) );
		$result = Library::run( (string) $request['fn'], self::arr( $request['args'] ), $over );
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$response = new \WP_REST_Response( Csv::build( $result ), 200 );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . Csv::filename( $result ) . '"' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}

	/**
	 * Serves the export route's CSV body raw instead of JSON.
	 *
	 * @param bool              $served  Served.
	 * @param \WP_HTTP_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 * @return bool
	 */
	public static function serve_csv( $served, $result, $request ) {
		if ( $served || ! ( $request instanceof \WP_REST_Request ) || '/' . self::NS . '/export' !== $request->get_route() || ! ( $result instanceof \WP_HTTP_Response ) || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, cells are neutralised in Csv::cell().
		return true;
	}

	/**
	 * GET /digests.
	 *
	 * @return \WP_REST_Response
	 */
	public function digests() {
		return $this->respond( array_map( array( Digests::class, 'present' ), Digests::all() ) );
	}

	/**
	 * POST /digests.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_digest( \WP_REST_Request $request ) {
		$raw = $request->get_json_params();
		$raw = is_array( $raw ) && $raw ? $raw : $request->get_params();
		return $this->respond( Digests::present( Digests::save( (array) $raw ) ) );
	}

	/**
	 * DELETE /digests/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_digest( \WP_REST_Request $request ) {
		return $this->respond( array( 'deleted' => Digests::delete( (string) $request['id'] ) ) );
	}

	/**
	 * Digest or 404.
	 *
	 * @param string $id Id.
	 * @return array|\WP_Error
	 */
	private static function digest( $id ) {
		$digest = Digests::get( (string) $id );
		return $digest ? $digest : Api_Error::make( 'dev3si_not_found', __( 'Digest not found.', 'dev3-ai-store-insights-for-woocommerce' ), '', 404 );
	}

	/**
	 * POST /digests/<id>/test { to }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_digest( \WP_REST_Request $request ) {
		$digest = self::digest( $request['id'] );
		if ( is_wp_error( $digest ) ) {
			return $this->respond( $digest );
		}
		$to = sanitize_email( (string) $request['to'] );
		if ( ! $to ) {
			$user = wp_get_current_user();
			$to   = $user && $user->user_email ? $user->user_email : (string) get_option( 'admin_email' );
		}
		$sent = Digests::send( $digest, $to );
		return $this->respond( is_wp_error( $sent ) ? $sent : array( 'sent' => $to ) );
	}

	/**
	 * GET /digests/<id>/preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preview_digest( \WP_REST_Request $request ) {
		$digest = self::digest( $request['id'] );
		if ( is_wp_error( $digest ) ) {
			return $this->respond( $digest );
		}
		$email = Digests::render( $digest, false );
		return $this->respond(
			array(
				'subject' => $email['subject'],
				'html'    => $email['html'],
			)
		);
	}

	/**
	 * GET /alerts?status=&page=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alerts( \WP_REST_Request $request ) {
		$status = in_array( $request['status'], array( 'new', 'ack' ), true ) ? $request['status'] : 'all';
		return $this->respond( Alerts::query( $status, max( 1, (int) $request['page'] ), min( 100, max( 1, (int) ( $request['per_page'] ? $request['per_page'] : 20 ) ) ) ) );
	}

	/**
	 * POST /alerts/<id> { status }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function update_alert( \WP_REST_Request $request ) {
		$alert = Alerts::set_status( (int) $request['id'], (string) $request['status'] );
		return $this->respond( $alert ? $alert : Api_Error::make( 'dev3si_not_found', __( 'Alert not found.', 'dev3-ai-store-insights-for-woocommerce' ), '', 404 ) );
	}

	/**
	 * POST /alerts/<id>/explain.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function explain_alert( \WP_REST_Request $request ) {
		return $this->respond( Alerts::explain( (int) $request['id'], (bool) $request['force'] ) );
	}

	/**
	 * GET /alert-rules.
	 *
	 * @return \WP_REST_Response
	 */
	public function alert_rules() {
		return $this->respond(
			array(
				'rules'   => Alerts::rules(),
				'checked' => get_option( Alerts::CHECKED, null ),
				'enabled' => (bool) Settings::get( 'alerts_enabled', true ),
			)
		);
	}

	/**
	 * POST /alert-rules { rules }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_alert_rules( \WP_REST_Request $request ) {
		return $this->respond( array( 'rules' => Alerts::save_rules( (array) $request['rules'] ) ) );
	}

	/**
	 * POST /alert-rules/check { days }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function check_alerts( \WP_REST_Request $request ) {
		$created = Alerts::check( max( 1, min( 60, (int) ( $request['days'] ? $request['days'] : 1 ) ) ) );
		return $this->respond( array( 'created' => $created ) );
	}

	/**
	 * POST /alert-rules/advice → draft rules.
	 *
	 * @return \WP_REST_Response
	 */
	public function alert_advice() {
		return $this->respond( Alerts::advice() );
	}

	/**
	 * GET /health.
	 *
	 * @return \WP_REST_Response
	 */
	public function health() {
		return $this->respond( Health::status() );
	}

	/**
	 * POST /health/import.
	 *
	 * @return \WP_REST_Response
	 */
	public function import() {
		return $this->respond( Health::start_import(), 201 );
	}
}
