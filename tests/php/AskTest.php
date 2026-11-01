<?php
/**
 * Ask your store: library validation, rule parser, DEV3 AI function calling (mocked) and explanations.
 *
 * @package Dev3\AiStoreInsights
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiStoreInsights\Ask\Asker;
use Dev3\AiStoreInsights\Ask\Explainer;
use Dev3\AiStoreInsights\Ask\Rule_Parser;
use Dev3\AiStoreInsights\Core3\Core\Settings_Store;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Query\Library;

class AskTest extends Dev3si_Base_Case {

	public function test_declarations_are_valid_function_schemas() {
		$decl  = Library::declarations();
		$names = wp_list_pluck( $decl, 'name' );
		$this->assertSame( array( 'store_summary', 'sales_trend', 'top_products', 'top_categories', 'sales_by_location', 'customer_segments', 'top_customers', 'refunds', 'cohorts', 'stock_status', 'top_coupons' ), $names );
		foreach ( $decl as $fn ) {
			$this->assertSame( 'OBJECT', $fn['parameters']['type'] );
			foreach ( $fn['parameters']['properties'] as $prop ) {
				$this->assertContains( $prop['type'], array( 'STRING', 'INTEGER' ) );
				$this->assertNotEmpty( $prop['description'] );
			}
		}
		$top = $decl[2]['parameters']['properties'];
		$this->assertSame( array( 'revenue', 'quantity', 'orders' ), $top['metric']['enum'] );
		$this->assertArrayNotHasKey( 'product', $top, 'top_products cannot filter by product.' );
	}

	public function test_sanitize_rejects_unknown_functions_and_clamps_args() {
		$this->assertWPError( Library::sanitize( 'drop_tables', array() ) );
		$this->assertWPError( Library::sanitize( 'sql', array( 'query' => 'SELECT 1' ) ) );
		$clean = Library::sanitize(
			'top_products',
			array(
				'limit'    => 999,
				'metric'   => 'DROP',
				'period'   => 'custom',
				'from'     => '2026-02-30',
				'to'       => '2026-03-31',
				'location' => str_repeat( 'x', 200 ) . "'; DROP TABLE wp_users; --",
				'sql'      => 'SELECT * FROM wp_users',
				'customer_type' => 'any',
			)
		);
		$this->assertSame( 50, $clean['args']['limit'] );
		$this->assertSame( 'revenue', $clean['args']['metric'] );
		$this->assertSame( 'last_30_days', $clean['args']['period'], 'An invalid custom date falls back to the default.' );
		$this->assertArrayNotHasKey( 'from', $clean['args'] );
		$this->assertSame( 80, mb_strlen( $clean['args']['location'] ) );
		$this->assertArrayNotHasKey( 'sql', $clean['args'] );
		$this->assertArrayNotHasKey( 'customer_type', $clean['args'] );
		$neg = Library::sanitize( 'cohorts', array( 'months' => -4 ) );
		$this->assertSame( 3, $neg['args']['months'] );
	}

	public function test_hostile_strings_are_values_not_sql() {
		$this->seed_store();
		global $wpdb;
		$users = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$r     = Library::run(
			'top_products',
			array(
				'period'   => 'all_time',
				'location' => "Dubai' OR '1'='1",
				'category' => "x') OR 1=1 -- ",
			)
		);
		$this->assertNotWPError( $r );
		$this->assertSame( array(), $r['rows'] );
		$this->assertSame( $users, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
	}

	/**
	 * @dataProvider questions
	 */
	public function test_rule_parser( $question, $fn, $expect ) {
		$this->seed_store();
		$parsed = Rule_Parser::parse( $question );
		$this->assertSame( $fn, $parsed['fn'], $question );
		foreach ( $expect as $key => $value ) {
			$this->assertSame( $value, $parsed['args'][ $key ] ?? null, "{$question}: {$key}" );
		}
	}

	public function questions() {
		return array(
			array( 'best sellers in Dubai last month', 'top_products', array( 'period' => 'last_month', 'location' => 'Dubai', 'metric' => 'revenue' ) ),
			array( 'Top 5 products by units sold this year', 'top_products', array( 'period' => 'this_year', 'limit' => 5, 'metric' => 'quantity' ) ),
			array( 'How did the store do yesterday?', 'store_summary', array( 'period' => 'yesterday' ) ),
			array( 'net sales by week for the last 90 days', 'sales_trend', array( 'period' => 'last_90_days', 'interval' => 'week' ) ),
			array( 'average order value per month', 'sales_trend', array( 'metric' => 'aov', 'interval' => 'month' ) ),
			array( 'which countries do sales come from?', 'sales_by_location', array( 'level' => 'country' ) ),
			array( 'top cities by orders in March 2026', 'sales_by_location', array( 'level' => 'city', 'metric' => 'orders', 'period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-03-31' ) ),
			array( 'new vs returning customers last 30 days', 'customer_segments', array( 'segment' => 'new_vs_returning' ) ),
			array( 'who are my best customers?', 'top_customers', array( 'metric' => 'revenue' ) ),
			array( 'refunds by product last 90 days', 'refunds', array( 'view' => 'products' ) ),
			array( 'customer retention cohorts', 'cohorts', array( 'metric' => 'retention' ) ),
			array( 'Which products will run out of stock first?', 'stock_status', array( 'view' => 'cover' ) ),
			array( 'what is out of stock', 'stock_status', array( 'view' => 'out' ) ),
			array( 'most used coupon codes', 'top_coupons', array() ),
			array( 'sales of fragrance in the past 14 days', 'sales_trend', array( 'category' => 'Fragrance', 'period' => 'custom', 'from' => '2026-10-12', 'to' => '2026-10-25' ) ),
			array( 'top categories in Germany', 'top_categories', array( 'location' => 'Germany' ) ),
		);
	}

	public function test_ask_without_key_uses_rules_and_explains_with_template() {
		$this->seed_store();
		$res = Asker::ask( 'best sellers in Dubai last month', 'ai' );
		$this->assertNotWPError( $res );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertSame( 'dev3si_ai_unavailable', $res['ai_error']['code'] );
		$this->assertSame( 'Desert Rose Perfume', $res['results'][0]['rows'][0]['name'] );
		$this->assertSame( 'template', $res['explanation']['source'] );
		$this->assertStringContainsString( 'Desert Rose Perfume leads with $200', $res['explanation']['headline'] );
		$this->assertNotEmpty( $res['explanation']['follow_ups'] );
		$this->assertSame( array(), $this->requests, 'No HTTP without a key.' );
		$history = Questions::all( 'history' );
		$this->assertSame( 'best sellers in Dubai last month', $history[0]['question'] );
	}

	public function test_ask_with_ai_function_calling_and_explanation() {
		$this->seed_store();
		$this->with_key();
		$this->replies = array(
			$this->gemini_calls(
				array(
					array(
						'top_products',
						array(
							'metric'   => 'revenue',
							'period'   => 'last_month',
							'location' => 'Dubai',
							'limit'    => 500,
						),
					),
					array( 'not_a_function', array( 'x' => 1 ) ),
				)
			),
			$this->gemini_text(
				array(
					'headline'   => 'Desert Rose Perfume was the Dubai best seller in September.',
					'summary'    => 'It made $200 of the $300 sold to Dubai.',
					'highlights' => array( '<b>Perfume</b> leads', 'Candles second' ),
					'follow_ups' => array( 'Top categories in Dubai last month' ),
				)
			),
		);
		$res = Asker::ask( 'what sold best for Dubai customers in September?', 'ai' );
		$this->assertNotWPError( $res );
		$this->assertSame( 'ai', $res['source'] );
		$this->assertCount( 1, $res['plan'], 'Unknown function calls are dropped.' );
		$this->assertSame( 50, $res['plan'][0]['args']['limit'] );
		$this->assertSame( array( 'Desert Rose Perfume', 'Oud Candle' ), wp_list_pluck( $res['results'][0]['rows'], 'name' ) );
		$this->assertSame( 'ai', $res['explanation']['source'] );
		$this->assertSame( 'Perfume leads', $res['explanation']['highlights'][0], 'Model output is sanitised.' );

		$plan = $this->requests[0]['body'];
		$this->assertSame( 'ANY', $plan['toolConfig']['functionCallingConfig']['mode'] );
		$this->assertCount( 11, $plan['tools'][0]['functionDeclarations'] );
		$this->assertStringContainsString( 'never write SQL', $plan['systemInstruction']['parts'][0]['text'] );
		$this->assertStringContainsString( 'Dubai', $plan['systemInstruction']['parts'][0]['text'], 'Known cities are given as context.' );
		$explain = wp_json_encode( $this->requests[1]['body'] );
		$this->assertStringNotContainsString( 'aisha@example.com', $explain, 'No e-mails reach the model.' );
	}

	public function test_ai_failure_falls_back_to_rules() {
		$this->seed_store();
		$this->with_key();
		$this->replies = array( array( 500, array( 'error' => array( 'message' => 'boom' ) ) ) );
		$res           = Asker::ask( 'best sellers last month', 'ai' );
		$this->assertNotWPError( $res );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertNotEmpty( $res['ai_error']['code'] );
		$this->assertSame( 'template', $res['explanation']['source'] );
	}

	public function test_customer_names_are_anonymised_for_the_model() {
		$this->seed_store();
		$result  = Library::run( 'top_customers', array( 'period' => 'all_time' ) );
		$payload = Explainer::payload( array( $result ) );
		$this->assertSame( 'Customer 1', $payload[0]['rows'][0]['name'] );
		Settings_Store::update( array( 'send_customer_names' => true ) );
		Settings_Store::flush();
		$payload = Explainer::payload( array( $result ) );
		$this->assertSame( 'Aisha Khan', $payload[0]['rows'][0]['name'] );
	}

	public function test_rules_mode_never_calls_ai() {
		$this->seed_store();
		$this->with_key();
		Settings_Store::update( array( 'explain_answers' => false ) );
		Settings_Store::flush();
		$res = Asker::ask( 'store summary this month', 'rules' );
		$this->assertSame( 'rules', $res['source'] );
		$this->assertSame( array(), $this->requests );
		$this->assertStringContainsString( 'Net sales', $res['explanation']['headline'] );
	}
}
