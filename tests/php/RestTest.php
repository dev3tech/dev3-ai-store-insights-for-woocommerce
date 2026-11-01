<?php
/**
 * REST routes: permissions, nonce, ask/run/export, questions, digests, alerts.
 *
 * @package Dev3\AiStoreInsights
 */

require_once __DIR__ . '/Base_Case.php';

class RestTest extends Dev3si_Base_Case {

	/**
	 * Admin id.
	 *
	 * @var int
	 */
	private $admin = 0;

	public function set_up() {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string $method Method.
	 * @param string $route  Route under dev3si/v1.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send the REST nonce.
	 * @return WP_REST_Response
	 */
	private function call( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3si/v1/' . ltrim( $route, '/' ) );
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_permissions_need_manager_and_nonce() {
		$this->assertContains( $this->call( 'GET', 'meta' )->get_status(), array( 401, 403 ), 'Logged out.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertContains( $this->call( 'GET', 'meta' )->get_status(), array( 401, 403 ) );
		$this->assertContains( $this->call( 'POST', 'ask', array( 'question' => 'sales' ) )->get_status(), array( 401, 403 ) );
		wp_set_current_user( $this->admin );
		$this->assertSame( 403, $this->call( 'GET', 'meta', array(), false )->get_status(), 'Nonce required.' );
		$this->assertSame( 200, $this->call( 'GET', 'meta' )->get_status() );
	}

	public function test_meta_ask_run_and_export() {
		$this->seed_store();
		wp_set_current_user( $this->admin );
		$meta = $this->call( 'GET', 'meta' )->get_data();
		$this->assertCount( 11, $meta['functions'] );
		$this->assertContains( 'Dubai', $meta['locations'] );
		$this->assertContains( 'Fragrance', $meta['categories'] );
		$this->assertTrue( $meta['health']['ready'] );

		$ask = $this->call( 'POST', 'ask', array( 'question' => 'best sellers in Dubai last month' ) );
		$this->assertSame( 200, $ask->get_status() );
		$this->assertSame( 'top_products', $ask->get_data()['plan'][0]['fn'] );
		$this->assertSame( 400, $this->call( 'POST', 'ask', array( 'question' => '   ' ) )->get_status() );

		$run = $this->call(
			'POST',
			'run',
			array(
				'fn'      => 'sales_by_location',
				'args'    => array( 'level' => 'city' ),
				'over'    => array( 'period' => 'last_month' ),
				'explain' => true,
			)
		)->get_data();
		$this->assertSame( 'last_month', $run['result']['args']['period'], 'Dashboard period override applies.' );
		$this->assertSame( 'Dubai, AE', $run['result']['rows'][0]['name'] );
		$this->assertSame( 'template', $run['explanation']['source'] );
		$this->assertSame( 400, $this->call( 'POST', 'run', array( 'fn' => 'nope' ) )->get_status() );

		$csv = $this->call(
			'GET',
			'export',
			array(
				'fn'   => 'top_products',
				'args' => wp_json_encode( array( 'period' => 'last_month' ) ),
			)
		);
		$this->assertSame( 200, $csv->get_status() );
		$this->assertStringContainsString( 'text/csv', $csv->get_headers()['Content-Type'] );
		$this->assertStringContainsString( 'Desert Rose Perfume', $csv->get_data() );
		$this->assertGreaterThanOrEqual( 400, $this->call( 'POST', 'explain', array( 'fn' => 'top_products' ) )->get_status(), 'Explain with AI needs a key.' );
	}

	public function test_questions_digests_and_alert_rules_routes() {
		wp_set_current_user( $this->admin );
		$created = $this->call(
			'POST',
			'questions',
			array(
				'title'  => 'Weekly sales',
				'query'  => array( 'fn' => 'sales_trend' ),
				'pinned' => true,
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$id = $created->get_data()['id'];
		$this->assertCount( 1, $this->call( 'GET', 'questions', array( 'view' => 'pinned' ) )->get_data() );
		$this->assertSame( 'full', $this->call( 'POST', "questions/{$id}", array( 'pin_size' => 'full' ) )->get_data()['pin_size'] );
		$this->assertSame( 404, $this->call( 'POST', 'questions/999999', array( 'title' => 'x' ) )->get_status() );
		$this->assertTrue( $this->call( 'DELETE', "questions/{$id}" )->get_data()['deleted'] );

		$digest = $this->call(
			'POST',
			'digests',
			array(
				'name'       => 'Daily',
				'frequency'  => 'daily',
				'recipients' => 'a@example.org',
			)
		)->get_data();
		$this->assertSame( 'daily', $digest['frequency'] );
		$preview = $this->call( 'GET', "digests/{$digest['id']}/preview" )->get_data();
		$this->assertStringContainsString( '<html>', $preview['html'] );
		$this->assertSame( 'admin-test@example.org', $this->call( 'POST', "digests/{$digest['id']}/test", array( 'to' => 'admin-test@example.org' ) )->get_data()['sent'] );
		$this->assertSame( 404, $this->call( 'POST', 'digests/nope/test' )->get_status() );

		$rules = $this->call( 'GET', 'alert-rules' )->get_data();
		$this->assertCount( 4, $rules['rules'] );
		$saved = $this->call( 'POST', 'alert-rules', array( 'rules' => array( array( 'name' => 'Only', 'metric' => 'orders' ) ) ) )->get_data();
		$this->assertCount( 1, $saved['rules'] );
		$this->assertSame( array(), $this->call( 'POST', 'alert-rules/check', array( 'days' => 3 ) )->get_data()['created'] );
		$this->assertSame( 0, $this->call( 'GET', 'alerts' )->get_data()['total'] );
		$this->assertSame( 404, $this->call( 'POST', 'alerts/999/explain' )->get_status() );
	}
}
