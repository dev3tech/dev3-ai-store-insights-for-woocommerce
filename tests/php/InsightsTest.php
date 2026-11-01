<?php
/**
 * Saved questions, anomaly alerts, e-mail digests, CSV and data health.
 *
 * @package Dev3\AiStoreInsights
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiStoreInsights\Data\Csv;
use Dev3\AiStoreInsights\Data\Health;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Insights\Alerts;
use Dev3\AiStoreInsights\Insights\Digests;
use Dev3\AiStoreInsights\Insights\Mailer;
use Dev3\AiStoreInsights\Query\Library;

class InsightsTest extends Dev3si_Base_Case {

	/**
	 * 28 steady days (2 orders × $50) then a weak day on 24 Oct (yesterday).
	 *
	 * @param int $last_day_orders Orders on the last day.
	 * @return WC_Product
	 */
	private function steady_then( $last_day_orders ) {
		$mug = $this->product( 'Steady Mug', 50, array(), 1000 );
		for ( $d = 28; $d >= 1; $d-- ) {
			$day = gmdate( 'Y-m-d', strtotime( '2026-10-24 -' . $d . ' days' ) );
			for ( $i = 0; $i < 2 + ( $d % 2 ); $i++ ) {
				$this->order(
					array(
						'date'  => $day . ' 1' . $i . ':00:00',
						'items' => array( array( $mug, 1 ) ),
						'email' => "c{$d}{$i}@example.com",
					)
				);
			}
		}
		for ( $i = 0; $i < $last_day_orders; $i++ ) {
			$this->order(
				array(
					'date'  => '2026-10-24 11:00:00',
					'items' => array( array( $mug, 1 ) ),
					'email' => "late{$i}@example.com",
				)
			);
		}
		return $mug;
	}

	public function test_saved_questions_and_pins() {
		$a = Questions::create(
			array(
				'title'  => 'Best sellers',
				'query'  => array(
					'fn'   => 'top_products',
					'args' => array( 'limit' => 900 ),
				),
				'pinned' => true,
			)
		);
		$b = Questions::create(
			array(
				'query'  => array( 'fn' => 'store_summary' ),
				'pinned' => true,
			)
		);
		$this->assertSame( 50, $a['query']['args']['limit'], 'Queries are sanitised on save.' );
		$this->assertSame( 'Store summary (KPIs) · Last 30 days', $b['title'], 'Untitled saves are named from the query.' );
		$this->assertWPError( Questions::create( array( 'query' => array( 'fn' => 'evil' ) ) ) );
		$this->assertSame( array( $a['id'], $b['id'] ), wp_list_pluck( Questions::all( 'pinned' ), 'id' ) );
		Questions::reorder( array( $b['id'], $a['id'] ) );
		$this->assertSame( array( $b['id'], $a['id'] ), wp_list_pluck( Questions::all( 'pinned' ), 'id' ) );
		$upd = Questions::update(
			$a['id'],
			array(
				'pinned'   => false,
				'pin_size' => 'full',
				'title'    => 'Renamed',
			)
		);
		$this->assertFalse( $upd['pinned'] );
		$this->assertTrue( $upd['saved'] );
		$this->assertSame( 'full', $upd['pin_size'] );
		$this->assertCount( 2, Questions::all( 'saved' ) );
		$this->assertTrue( Questions::delete( $a['id'] ) );
		$this->assertNull( Questions::get( $a['id'] ) );
	}

	public function test_statistics_flag_real_surprises_only() {
		$rule = Alerts::clean_rule(
			array(
				'metric'         => 'net_sales',
				'direction'      => 'drop',
				'sensitivity'    => 'medium',
				'min_change_pct' => 30,
				'min_baseline'   => 10,
			)
		);
		$base = array_merge( array_fill( 0, 14, 100.0 ), array_fill( 0, 14, 120.0 ) );
		$hit  = Alerts::evaluate( $rule, 20.0, $base );
		$this->assertNotNull( $hit );
		$this->assertSame( 'drop', $hit['direction'] );
		$this->assertEqualsWithDelta( -81.8, $hit['change_pct'], 0.1 );
		$this->assertSame( 'high', $hit['severity'] );
		$this->assertNull( Alerts::evaluate( $rule, 105.0, $base ), 'Normal day.' );
		$this->assertNull( Alerts::evaluate( $rule, 300.0, $base ), 'Spikes ignored by a drop rule.' );
		$rule['min_baseline'] = 500;
		$this->assertNull( Alerts::evaluate( $rule, 20.0, $base ), 'Quiet below the minimum baseline.' );
		$this->assertNull( Alerts::evaluate( $rule, 20.0, array( 1.0, 2.0 ) ), 'Not enough history.' );
	}

	public function test_a_sales_drop_creates_one_alert_with_movers_and_an_email() {
		$this->steady_then( 0 );
		Alerts::save_rules(
			array(
				array(
					'id'             => 'drop',
					'name'           => 'Net sales dropped',
					'metric'         => 'net_sales',
					'direction'      => 'drop',
					'sensitivity'    => 'medium',
					'min_change_pct' => 30,
					'min_baseline'   => 10,
					'recipients'     => 'owner@example.org',
				),
			)
		);
		$created = Alerts::check( 1 );
		$this->assertCount( 1, $created );
		$alert = $created[0];
		$this->assertSame( '2026-10-24', $alert['day'] );
		$this->assertEquals( 0, $alert['value'] );
		$this->assertEqualsWithDelta( 125.0, $alert['baseline'], 0.01 );
		$this->assertCount( 29, $alert['detail']['series'] );
		$this->assertSame( 'Steady Mug', $alert['detail']['movers']['products'][0]['name'] );
		$this->assertSame( array(), Alerts::check( 1 ), 'One alert per rule and day.' );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'owner@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'Net sales dropped', $mail->subject );
		$this->assertSame( '2026-10-24', get_option( Alerts::CHECKED ) );
		$explained = Alerts::explain( $alert['id'] );
		$this->assertSame( 'template', $explained['detail']['explanation']['source'] );
		$this->assertStringContainsString( 'below the 28-day daily average', $explained['detail']['explanation']['summary'] );
		$this->assertSame( 'ack', Alerts::set_status( $alert['id'], 'ack' )['status'] );
		$list = Alerts::query( 'ack' );
		$this->assertSame( 1, $list['total'] );
	}

	public function test_normal_day_raises_nothing_and_ai_explains_alerts() {
		$this->steady_then( 2 );
		Alerts::save_rules( Alerts::default_rules() );
		$this->assertSame( array(), Alerts::check( 1 ) );
		$this->with_key();
		$this->replies = array(
			$this->gemini_text(
				array(
					'headline'   => 'A quiet day.',
					'summary'    => 'Fewer mug orders than usual.',
					'highlights' => array(),
					'follow_ups' => array(),
				)
			),
		);
		global $wpdb;
		$wpdb->insert(
			Alerts::table(),
			array(
				'rule_id'    => 'manual',
				'metric'     => 'orders',
				'day'        => '2026-10-24',
				'value'      => 1,
				'baseline'   => 2.5,
				'change_pct' => -60,
				'severity'   => 'medium',
				'status'     => 'new',
				'detail'     => wp_json_encode( array( 'rule' => 'Manual' ) ),
				'created_at' => '2026-10-25 06:00:00',
			)
		);
		$alert = Alerts::explain( (int) $wpdb->insert_id );
		$this->assertSame( 'ai', $alert['detail']['explanation']['source'] );
		$this->assertSame( 'A quiet day.', $alert['detail']['explanation']['headline'] );
	}

	public function test_stock_rule_flags_best_sellers_running_out() {
		$p = $this->seed_store();
		Alerts::save_rules(
			array(
				array(
					'id'     => 'stock',
					'type'   => 'stock',
					'days'   => 60,
					'notify' => false,
				),
			)
		);
		$created = Alerts::check( 1 );
		$this->assertCount( 1, $created );
		$this->assertSame( 'stock', $created[0]['metric'] );
		$this->assertContains( 'Amber Soap', wp_list_pluck( $created[0]['detail']['items'], 'name' ) );
		unset( $p );
	}

	public function test_ai_rule_advice_is_a_sanitised_draft() {
		$this->with_key();
		$this->replies = array(
			$this->gemini_text(
				array(
					'rules' => array(
						array(
							'name'           => 'Weekend dip',
							'metric'         => 'net_sales',
							'direction'      => 'drop',
							'sensitivity'    => 'extreme',
							'min_change_pct' => 900,
							'reason'         => 'Sales are steady.',
						),
						array(
							'name'      => 'Bad',
							'metric'    => 'profit',
							'direction' => 'both',
						),
					),
				)
			),
		);
		$advice = Alerts::advice();
		$this->assertNotWPError( $advice );
		$this->assertCount( 2, $advice['rules'] );
		$this->assertSame( 'medium', $advice['rules'][0]['sensitivity'] );
		$this->assertSame( 500, $advice['rules'][0]['min_change_pct'] );
		$this->assertSame( 'net_sales', $advice['rules'][1]['metric'], 'Unknown metrics fall back.' );
		$this->assertFalse( get_option( Alerts::OPTION ), 'Nothing is saved until the admin accepts.' );
	}

	public function test_digest_schedule_render_and_send_once() {
		$this->seed_store();
		$q      = Questions::create(
			array(
				'title' => 'Best sellers',
				'query' => array(
					'fn'   => 'top_products',
					'args' => array( 'period' => 'last_30_days' ),
				),
			)
		);
		$digest = Digests::save(
			array(
				'name'         => 'Monday digest',
				'frequency'    => 'weekly',
				'weekday'      => 1,
				'hour'         => 8,
				'recipients'   => 'owner@example.org, not-an-email',
				'question_ids' => array( $q['id'] ),
				'ai_summary'   => true,
			)
		);
		$this->assertSame( 'owner@example.org', $digest['recipients'] );
		$monday = Digests::next_run( $digest, strtotime( '2026-10-25 12:00:00' ) );
		$this->assertSame( '2026-10-26 08:00', gmdate( 'Y-m-d H:i', $monday ) );
		$list                = Digests::all();
		$list[0]['next_run'] = $monday;
		update_option( Digests::OPTION, $list );
		$digest = Digests::get( $digest['id'] );
		$monthly = Digests::next_run(
			array(
				'frequency' => 'monthly',
				'monthday'  => 1,
				'hour'      => 9,
			),
			strtotime( '2026-10-25 12:00:00' )
		);
		$this->assertSame( '2026-11-01 09:00', gmdate( 'Y-m-d H:i', $monthly ) );

		$email = Digests::render( $digest, false );
		$this->assertStringContainsString( 'Key numbers', $email['html'] );
		$this->assertStringContainsString( 'Best sellers', $email['html'] );
		$this->assertStringNotContainsString( '<svg', $email['html'] );
		$this->assertStringContainsString( 'Monday digest: 12 Oct 2026 – 18 Oct 2026', $email['subject'] );
		$this->assertStringContainsString( 'NET SALES', strtoupper( $email['text'] ) );

		$this->assertSame( 0, Digests::tick( $monday - 60 ) );
		$this->assertSame( 1, Digests::tick( $monday + 60 ) );
		$this->assertSame( 0, Digests::tick( $monday + 120 ), 'Sent once per period.' );
		$after = Digests::get( $digest['id'] );
		$this->assertSame( '2026-11-02 08:00', gmdate( 'Y-m-d H:i', $after['next_run'] ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'owner@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'multipart/alternative', $mail->header, 'HTML with a plain-text part.' );
		$this->assertEmpty( $this->requests, 'No key → no AI summary request, digest still sent.' );
		$this->assertTrue( Digests::delete( $digest['id'] ) );
		$this->assertSame( array(), Digests::all() );
	}

	public function test_csv_neutralises_formulas() {
		$this->seed_store();
		$result                    = Library::run( 'top_products', array( 'period' => 'all_time' ) );
		$result['rows'][0]['name'] = '=HYPERLINK("http://evil")';
		$csv                       = Csv::build( $result );
		$this->assertStringStartsWith( "\xEF\xBB\xBFProduct,Net sales", $csv );
		$this->assertStringContainsString( "\"'=HYPERLINK(\"\"http://evil\"\")\"", $csv );
		$this->assertSame( "'-5", Csv::cell( '-5' ) );
		$this->assertSame( '-5', Csv::cell( -5 ) );
		$this->assertSame( "'@x", Csv::cell( '@x' ) );
	}

	public function test_mailer_renders_every_chart_type() {
		$this->seed_store();
		foreach ( array( 'store_summary', 'sales_trend', 'top_products', 'customer_segments', 'cohorts', 'stock_status' ) as $fn ) {
			$html = Mailer::chart_html( Library::run( $fn, array( 'period' => 'last_month' ) ) );
			$this->assertNotEmpty( $html, $fn );
			$this->assertStringNotContainsString( '<script', $html );
		}
	}

	public function test_health_finds_orders_missing_from_analytics() {
		$p = $this->seed_store();
		$this->assertSame( array(), Health::missing_ids() );
		$order = wc_create_order();
		$order->add_product( $p['candle'], 1 );
		$order->calculate_totals( false );
		$order->set_status( 'completed' );
		$order->save();
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'wc_order_stats', array( 'order_id' => $order->get_id() ) );
		$this->assertSame( array( $order->get_id() ), Health::missing_ids() );
		$job = Health::start_import();
		$this->assertNotWPError( $job );
		\Dev3\AiStoreInsights\Core3\Core\Jobs::run( $job['id'], 10 );
		$this->assertSame( array(), Health::missing_ids() );
		$this->assertSame( 'done', \Dev3\AiStoreInsights\Core3\Core\Jobs::get( $job['id'] )['status'] );
	}
}
