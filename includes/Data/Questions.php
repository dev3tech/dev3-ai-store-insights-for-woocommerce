<?php
/**
 * Question history, saved questions and dashboard pins (table dev3si_questions).
 *
 * @package Dev3\AiStoreInsights
 */

namespace Dev3\AiStoreInsights\Data;

use Dev3\AiStoreInsights\Core3\Core\Settings_Store as Settings;
use Dev3\AiStoreInsights\Query\Library;

defined( 'ABSPATH' ) || exit;

/**
 * Repository.
 */
class Questions {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3si_questions';
	}

	/**
	 * CREATE TABLE statements (hooked to dev3si_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		global $wpdb;
		$q     = self::table();
		$a     = $wpdb->prefix . 'dev3si_alerts';
		$sql[] = "CREATE TABLE {$q} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(190) NOT NULL DEFAULT '',
				question varchar(500) NOT NULL DEFAULT '',
				query longtext NOT NULL,
				source varchar(10) NOT NULL DEFAULT 'rules',
				saved tinyint(1) NOT NULL DEFAULT 0,
				pinned tinyint(1) NOT NULL DEFAULT 0,
				pin_order int(11) NOT NULL DEFAULT 0,
				pin_size varchar(10) NOT NULL DEFAULT 'half',
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_run_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY user_saved (user_id, saved),
				KEY pinned (pinned, pin_order)
			) {$charset};";
		$sql[] = "CREATE TABLE {$a} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				rule_id varchar(40) NOT NULL DEFAULT '',
				metric varchar(40) NOT NULL DEFAULT '',
				day date NOT NULL DEFAULT '0000-00-00',
				value double NOT NULL DEFAULT 0,
				baseline double NOT NULL DEFAULT 0,
				change_pct double NOT NULL DEFAULT 0,
				zscore double NOT NULL DEFAULT 0,
				severity varchar(10) NOT NULL DEFAULT 'medium',
				status varchar(10) NOT NULL DEFAULT 'new',
				detail longtext NULL,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY rule_day (rule_id, day),
				KEY status (status, day)
			) {$charset};";
		return $sql;
	}

	/**
	 * Row → API shape.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function shape( $row ) {
		if ( ! $row ) {
			return null;
		}
		$query = json_decode( (string) $row['query'], true );
		$query = is_array( $query ) ? $query : array(
			'fn'   => 'store_summary',
			'args' => array(),
		);
		return array(
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'title'       => (string) $row['title'],
			'question'    => (string) $row['question'],
			'query'       => $query,
			'description' => Library::describe( $query ),
			'source'      => (string) $row['source'],
			'saved'       => (bool) $row['saved'],
			'pinned'      => (bool) $row['pinned'],
			'pin_order'   => (int) $row['pin_order'],
			'pin_size'    => 'full' === $row['pin_size'] ? 'full' : 'half',
			'created_at'  => (string) $row['created_at'],
			'updated_at'  => (string) $row['updated_at'],
			'last_run_at' => $row['last_run_at'] ? (string) $row['last_run_at'] : null,
		);
	}

	/**
	 * Validated query JSON.
	 *
	 * @param array $query { fn, args }.
	 * @return array|\WP_Error
	 */
	public static function clean_query( $query ) {
		$query = is_array( $query ) ? $query : array();
		return Library::sanitize( isset( $query['fn'] ) ? $query['fn'] : '', isset( $query['args'] ) ? $query['args'] : array() );
	}

	/**
	 * Logs an asked question in the history (not saved).
	 *
	 * @param string $question Question.
	 * @param array  $query    Query.
	 * @param string $source   ai|rules.
	 * @return int Id.
	 */
	public static function log( $question, array $query, $source ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'    => get_current_user_id(),
				'title'      => mb_substr( $question, 0, 190 ),
				'question'   => mb_substr( $question, 0, 500 ),
				'query'      => wp_json_encode( $query ),
				'source'     => in_array( $source, array( 'ai', 'rules', 'builder' ), true ) ? $source : 'rules',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Creates a saved question.
	 *
	 * @param array $data { title, question, query, pinned, pin_size, source }.
	 * @return array|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$query = self::clean_query( isset( $data['query'] ) ? $data['query'] : array() );
		if ( is_wp_error( $query ) ) {
			return $query;
		}
		$title = mb_substr( sanitize_text_field( (string) ( $data['title'] ?? '' ) ), 0, 190 );
		if ( '' === $title ) {
			$title = mb_substr( Library::describe( $query ), 0, 190 );
		}
		$now    = current_time( 'mysql' );
		$pinned = ! empty( $data['pinned'] );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'    => get_current_user_id(),
				'title'      => $title,
				'question'   => mb_substr( sanitize_textarea_field( (string) ( $data['question'] ?? '' ) ), 0, 500 ),
				'query'      => wp_json_encode( $query ),
				'source'     => in_array( $data['source'] ?? '', array( 'ai', 'rules', 'builder' ), true ) ? $data['source'] : 'builder',
				'saved'      => 1,
				'pinned'     => $pinned ? 1 : 0,
				'pin_order'  => $pinned ? self::next_order() : 0,
				'pin_size'   => 'full' === ( $data['pin_size'] ?? '' ) ? 'full' : 'half',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return self::get( (int) $wpdb->insert_id );
	}

	/**
	 * Next pin position.
	 *
	 * @return int
	 */
	private static function next_order() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( "SELECT COALESCE(MAX(pin_order), 0) + 1 FROM {$t} WHERE pinned = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return self::shape( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Updates a row (title, query, saved, pinned, pin_size).
	 *
	 * @param int   $id   Id.
	 * @param array $data Data.
	 * @return array|\WP_Error|null
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return null;
		}
		$set = array( 'updated_at' => current_time( 'mysql' ) );
		if ( isset( $data['title'] ) ) {
			$title = mb_substr( sanitize_text_field( (string) $data['title'] ), 0, 190 );
			if ( '' !== $title ) {
				$set['title'] = $title;
			}
		}
		if ( isset( $data['query'] ) ) {
			$query = self::clean_query( $data['query'] );
			if ( is_wp_error( $query ) ) {
				return $query;
			}
			$set['query'] = wp_json_encode( $query );
		}
		if ( isset( $data['saved'] ) ) {
			$set['saved'] = $data['saved'] ? 1 : 0;
		}
		if ( isset( $data['pinned'] ) ) {
			$set['pinned'] = $data['pinned'] ? 1 : 0;
			if ( $data['pinned'] ) {
				$set['saved'] = 1;
				if ( ! $row['pinned'] ) {
					$set['pin_order'] = self::next_order();
				}
			}
		}
		if ( isset( $data['pin_size'] ) ) {
			$set['pin_size'] = 'full' === $data['pin_size'] ? 'full' : 'half';
		}
		$wpdb->update( self::table(), $set, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return self::get( $id );
	}

	/**
	 * Marks a row as run now.
	 *
	 * @param int $id Id.
	 */
	public static function touch( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'last_run_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Deletes a row.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lists rows.
	 *
	 * @param string $view saved|pinned|history.
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function all( $view = 'saved', $limit = 200 ) {
		global $wpdb;
		$t = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		if ( 'pinned' === $view ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE pinned = 1 ORDER BY pin_order ASC, id ASC LIMIT %d", $limit ), ARRAY_A );
		} elseif ( 'history' === $view ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE saved = 0 AND user_id = %d ORDER BY id DESC LIMIT %d", get_current_user_id(), min( 50, $limit ) ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE saved = 1 ORDER BY updated_at DESC, id DESC LIMIT %d", $limit ), ARRAY_A );
		}
		// phpcs:enable
		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $row ) {
			$key = 'history' === $view ? mb_strtolower( trim( (string) $row['question'] ) ) : (string) $row['id'];
			if ( isset( $seen[ $key ] ) ) {
				continue; // History shows each question once (latest first).
			}
			$seen[ $key ] = true;
			$out[]        = self::shape( $row );
		}
		return array_values( array_filter( $out ) );
	}

	/**
	 * Sets pin order.
	 *
	 * @param int[] $ids Ids in order.
	 */
	public static function reorder( array $ids ) {
		global $wpdb;
		$i = 1;
		foreach ( $ids as $id ) {
			$wpdb->update( self::table(), array( 'pin_order' => $i++ ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Daily: drops history rows older than the retention setting.
	 */
	public static function prune() {
		global $wpdb;
		$t    = self::table();
		$days = (int) Settings::get( 'history_days', 90 );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE saved = 0 AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * GDPR export of a user's questions.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_personal( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$t    = self::table();
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, question, title, created_at FROM {$t} WHERE user_id = %d ORDER BY id DESC LIMIT 500", $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			foreach ( (array) $rows as $row ) {
				$data[] = array(
					'group_id'    => 'dev3si-questions',
					'group_label' => __( 'Store Insights questions', 'dev3-ai-store-insights-for-woocommerce' ),
					'item_id'     => 'dev3si-question-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Question', 'dev3-ai-store-insights-for-woocommerce' ),
							'value' => '' !== $row['question'] ? $row['question'] : $row['title'],
						),
						array(
							'name'  => __( 'Date', 'dev3-ai-store-insights-for-woocommerce' ),
							'value' => $row['created_at'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * GDPR erase: deletes the user's unsaved history and detaches saved questions.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_personal( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$t        = self::table();
			$removed  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE user_id = %d AND saved = 0", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$removed += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET user_id = 0 WHERE user_id = %d", $user->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
