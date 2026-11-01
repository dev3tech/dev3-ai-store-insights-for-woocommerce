<?php
/**
 * Scheduled e-mail digests: KPIs + chosen saved questions, daily / weekly / monthly.
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Insights;

use Dev3\AiStoreInsights\Ask\Prompts;
use Dev3\AiStoreInsights\Core3\Api\Ai;
use Dev3\AiStoreInsights\Core3\Api\Api_Error;
use Dev3\AiStoreInsights\Data\Questions;
use Dev3\AiStoreInsights\Query\Library;
use Dev3\AiStoreInsights\Query\Period;
use Dev3\AiStoreInsights\Query\Store;
use Dev3\AiStoreInsights\Settings as Plugin_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Digest schedules (option) + sender.
 */
class Digests {

	const OPTION = 'dev3si_digests';

	/**
	 * Period each frequency reports on.
	 */
	const PERIODS = array(
		'daily'   => 'yesterday',
		'weekly'  => 'last_week',
		'monthly' => 'last_month',
	);

	/**
	 * All digests.
	 *
	 * @return array
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * Digest for the API: adds the next send time formatted in site time.
	 *
	 * @param array $digest Digest.
	 * @return array
	 */
	public static function present( array $digest ) {
		$digest['next_run_label'] = ! empty( $digest['next_run'] ) ? wp_date( get_option( 'date_format', 'j M Y' ) . ' ' . get_option( 'time_format', 'H:i' ), (int) $digest['next_run'] ) : '';
		return $digest;
	}

	/**
	 * One digest.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $digest ) {
			if ( $digest['id'] === $id ) {
				return $digest;
			}
		}
		return null;
	}

	/**
	 * Sanitises a digest.
	 *
	 * @param array      $raw      Raw.
	 * @param array|null $existing Existing.
	 * @return array
	 */
	public static function clean( array $raw, $existing = null ) {
		$existing = is_array( $existing ) ? $existing : array();
		$freq     = isset( $raw['frequency'] ) && isset( self::PERIODS[ $raw['frequency'] ] ) ? $raw['frequency'] : 'weekly';
		$out      = array(
			'id'           => isset( $existing['id'] ) ? $existing['id'] : 'dg-' . strtolower( wp_generate_password( 10, false ) ),
			'name'         => mb_substr( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ), 0, 80 ),
			'frequency'    => $freq,
			'weekday'      => max( 0, min( 6, (int) ( $raw['weekday'] ?? 1 ) ) ),
			'monthday'     => max( 1, min( 28, (int) ( $raw['monthday'] ?? 1 ) ) ),
			'hour'         => max( 0, min( 23, (int) ( $raw['hour'] ?? 8 ) ) ),
			'recipients'   => Plugin_Settings::clean_emails( (string) ( $raw['recipients'] ?? '' ) ),
			'include_kpis' => ! isset( $raw['include_kpis'] ) || (bool) $raw['include_kpis'],
			'question_ids' => array_values( array_unique( array_slice( array_map( 'absint', (array) ( $raw['question_ids'] ?? array() ) ), 0, 12 ) ) ),
			'ai_summary'   => ! empty( $raw['ai_summary'] ),
			'enabled'      => ! isset( $raw['enabled'] ) || (bool) $raw['enabled'],
			'last_sent'    => isset( $existing['last_sent'] ) ? $existing['last_sent'] : null,
		);
		if ( '' === $out['name'] ) {
			$names       = array(
				'daily'   => __( 'Daily store digest', 'dev3-ai-store-insights-for-woocommerce' ),
				'weekly'  => __( 'Weekly store digest', 'dev3-ai-store-insights-for-woocommerce' ),
				'monthly' => __( 'Monthly store digest', 'dev3-ai-store-insights-for-woocommerce' ),
			);
			$out['name'] = $names[ $freq ];
		}
		$out['next_run'] = self::next_run( $out, time() );
		return $out;
	}

	/**
	 * Next send time (unix) strictly after `$after`.
	 *
	 * @param array $digest Digest.
	 * @param int   $after  Unix time.
	 * @return int
	 */
	public static function next_run( array $digest, $after ) {
		$tz   = wp_timezone();
		$base = ( new \DateTimeImmutable( '@' . (int) $after ) )->setTimezone( $tz );
		$cand = $base->setTime( (int) $digest['hour'], 0, 0 );
		for ( $i = 0; $i < 400; $i++ ) {
			$ok = $cand->getTimestamp() > $after;
			if ( $ok && 'weekly' === $digest['frequency'] ) {
				$ok = (int) $cand->format( 'w' ) === (int) $digest['weekday'];
			} elseif ( $ok && 'monthly' === $digest['frequency'] ) {
				$ok = (int) $cand->format( 'j' ) === (int) $digest['monthday'];
			}
			if ( $ok ) {
				return $cand->getTimestamp();
			}
			$cand = $cand->modify( '+1 day' );
		}
		return $after + DAY_IN_SECONDS;
	}

	/**
	 * Creates or updates.
	 *
	 * @param array $raw Raw (with optional id).
	 * @return array
	 */
	public static function save( array $raw ) {
		$list  = self::all();
		$id    = isset( $raw['id'] ) ? sanitize_key( (string) $raw['id'] ) : '';
		$found = false;
		foreach ( $list as $i => $digest ) {
			if ( '' !== $id && $digest['id'] === $id ) {
				$list[ $i ] = self::clean( $raw, $digest );
				$found      = $list[ $i ];
			}
		}
		if ( ! $found ) {
			$found  = self::clean( $raw );
			$list[] = $found;
		}
		update_option( self::OPTION, array_slice( $list, 0, 20 ), false );
		return $found;
	}

	/**
	 * Deletes.
	 *
	 * @param string $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$list = self::all();
		$keep = array_values(
			array_filter(
				$list,
				static function ( $d ) use ( $id ) {
					return $d['id'] !== $id;
				}
			)
		);
		update_option( self::OPTION, $keep, false );
		return count( $keep ) !== count( $list );
	}

	/**
	 * Results for a digest.
	 *
	 * @param array $digest Digest.
	 * @return array [ { title, result } ]
	 */
	public static function results( array $digest ) {
		$period = self::PERIODS[ $digest['frequency'] ];
		$out    = array();
		if ( $digest['include_kpis'] ) {
			$kpis = Library::run(
				'store_summary',
				array(
					'period'  => $period,
					'compare' => 'previous_period',
				)
			);
			if ( ! is_wp_error( $kpis ) ) {
				$out[] = array(
					'title'  => __( 'Key numbers', 'dev3-ai-store-insights-for-woocommerce' ),
					'result' => $kpis,
				);
			}
		}
		foreach ( $digest['question_ids'] as $qid ) {
			$q = Questions::get( $qid );
			if ( ! $q ) {
				continue;
			}
			$fns = Library::functions();
			// Rankings follow the digest period; trends keep their saved range (one bucket is not a trend).
			$trend  = in_array( $q['query']['fn'], array( 'sales_trend', 'refunds' ), true );
			$over   = ! $trend && isset( $fns[ $q['query']['fn'] ] ) && in_array( 'period', $fns[ $q['query']['fn'] ]['params'], true ) ? array( 'period' => $period ) : array();
			$result = Library::run( $q['query']['fn'], $q['query']['args'], $over );
			if ( ! is_wp_error( $result ) ) {
				$out[] = array(
					'title'  => $q['title'],
					'result' => $result,
				);
			}
		}
		return $out;
	}

	/**
	 * Optional AI paragraph (cron context; never blocks sending).
	 *
	 * @param array $blocks Results.
	 * @return string
	 */
	public static function summary( array $blocks ) {
		if ( ! Ai::enabled( 'cron' ) || ! $blocks ) {
			return '';
		}
		$facts = array();
		foreach ( $blocks as $block ) {
			$r       = $block['result'];
			$facts[] = array(
				'title'   => $block['title'],
				'period'  => $r['period']['label'] ?? '',
				'summary' => $r['summary'],
				'kpis'    => 'kpi' === $r['chart']['type'] ? $r['chart']['items'] : null,
				'top'     => 'kpi' === $r['chart']['type'] || ! empty( $r['personal'] ) ? null : array_slice( $r['rows'], 0, 5 ),
			);
		}
		$result = Ai::run(
			'digest_summary',
			array(
				'system'      => Prompts::digest_system(),
				'prompt'      => 'Currency: ' . Store::currency()['code'] . "\n" . wp_json_encode( $facts ),
				'temperature' => 0.4,
				'max_tokens'  => 600,
				'profile'     => 'fast',
			),
			'cron'
		);
		return is_wp_error( $result ) ? '' : mb_substr( sanitize_textarea_field( $result['text'] ), 0, 900 );
	}

	/**
	 * Renders a digest.
	 *
	 * @param array $digest Digest.
	 * @param bool  $use_ai Whether to ask for the AI summary.
	 * @return array { subject, html, text, blocks }
	 */
	public static function render( array $digest, $use_ai = true ) {
		$blocks = self::results( $digest );
		$intro  = $use_ai && $digest['ai_summary'] ? self::summary( $blocks ) : '';
		$html   = '';
		foreach ( $blocks as $block ) {
			$html .= self::divider() . Mailer::result_html( $block['result'], array( 'title' => $block['title'] ) );
		}
		if ( ! $blocks ) {
			$html .= '<tr><td style="padding:16px 24px">' . esc_html__( 'Nothing to report for this period.', 'dev3-ai-store-insights-for-woocommerce' ) . '</td></tr>';
		}
		$period = Period::resolve( self::PERIODS[ $digest['frequency'] ] );
		/* translators: 1: digest name, 2: date range */
		$subject = sprintf( __( '%1$s: %2$s', 'dev3-ai-store-insights-for-woocommerce' ), $digest['name'], $period['range'] );
		/**
		 * Filters a digest e-mail before sending.
		 *
		 * @param array $email  { subject, html, text, blocks }.
		 * @param array $digest Digest.
		 */
		return apply_filters(
			'dev3si_digest_email',
			array(
				'subject' => $subject,
				'html'    => Mailer::wrap( $digest['name'], $intro, $html, admin_url( 'admin.php?page=' . DEV3SI_SLUG . '#/dashboard' ) ),
				'text'    => ( '' !== $intro ? $intro . "\n\n" : '' ) . Mailer::text( wp_list_pluck( $blocks, 'result' ) ),
				'blocks'  => count( $blocks ),
			),
			$digest
		);
	}

	/**
	 * Section divider row.
	 *
	 * @return string
	 */
	private static function divider() {
		return '<tr><td style="padding:8px 24px 0"><div style="border-top:1px solid ' . Mailer::LINE . ';font-size:0;line-height:0">&nbsp;</div></td></tr>';
	}

	/**
	 * Sends a digest.
	 *
	 * @param array  $digest Digest.
	 * @param string $to     Override recipients (test).
	 * @return true|\WP_Error
	 */
	public static function send( array $digest, $to = '' ) {
		$recipients = '' !== $to ? $to : $digest['recipients'];
		$recipients = array_filter( array_map( 'trim', explode( ',', (string) $recipients ) ) );
		if ( ! $recipients ) {
			return Api_Error::make( 'dev3si_no_recipients', __( 'Add at least one recipient.', 'dev3-ai-store-insights-for-woocommerce' ), '', 400 );
		}
		$email = self::render( $digest );
		$ok    = Mailer::send( $recipients, $email['subject'], $email['html'], $email['text'] );
		if ( ! $ok ) {
			return Api_Error::make( 'dev3si_mail_failed', __( 'WordPress could not send the e-mail.', 'dev3-ai-store-insights-for-woocommerce' ), __( 'Check your site\'s mail setup (an SMTP plugin helps).', 'dev3-ai-store-insights-for-woocommerce' ), 500 );
		}
		/**
		 * Fires after a digest is sent.
		 *
		 * @param array    $digest     Digest.
		 * @param string[] $recipients Recipients.
		 */
		do_action( 'dev3si_digest_sent', $digest, $recipients );
		return true;
	}

	/**
	 * Hourly: sends due digests once and schedules the next run.
	 *
	 * @param int|null $now Unix time (tests).
	 * @return int Number sent.
	 */
	public static function tick( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$list = self::all();
		$sent = 0;
		foreach ( $list as $i => $digest ) {
			if ( empty( $digest['enabled'] ) || (int) $digest['next_run'] > $now ) {
				continue;
			}
			$list[ $i ]['next_run'] = self::next_run( $digest, $now );
			update_option( self::OPTION, $list, false );
			if ( true === self::send( $digest ) ) {
				$list[ $i ]['last_sent'] = gmdate( 'Y-m-d H:i:s', $now );
				++$sent;
			}
		}
		update_option( self::OPTION, $list, false );
		return $sent;
	}
}
