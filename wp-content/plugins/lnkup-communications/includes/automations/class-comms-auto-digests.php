<?php
/**
 * Digests and summaries.
 *
 * - Email digests: messages a user chose to receive daily/weekly are held as
 *   "digest_pending" and grouped into one email. Items are re-validated, so a
 *   closed opportunity or resolved task drops out of the digest.
 * - Organizer operations summary: one email covering all upcoming events, real counts.
 * - Pending-review reminder: only when applications waited longer than the setting.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Auto_Digests {

	public static function init(): void {
		add_action( 'lnkup_comms_daily', array( __CLASS__, 'daily' ), 30 );
		add_action( 'lnkup_comms_weekly', array( __CLASS__, 'weekly' ), 30 );
	}

	public static function daily(): void {
		self::email_digests( 'daily' );
		if ( LNKup_Comms_Settings::enabled( 'flow_digests' ) ) {
			self::pending_review();
			self::ops_summaries( 'daily' );
		}
	}

	public static function weekly(): void {
		self::email_digests( 'weekly' );
		if ( LNKup_Comms_Settings::enabled( 'flow_digests' ) ) {
			self::ops_summaries( 'weekly' );
		}
	}

	/**
	 * Group held messages into one email per user.
	 */
	public static function email_digests( string $mode ): void {
		global $wpdb;
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id AS delivery_id, m.id AS message_id, m.recipient_user_id, m.recipient_role, m.trigger_key, m.title, m.context
				FROM {$deliveries} d INNER JOIN {$messages} m ON m.id = d.message_id
				WHERE d.channel = 'email' AND d.status = 'digest_pending' AND d.provider_ref = %s
				ORDER BY m.recipient_user_id ASC, m.id DESC LIMIT 5000",
				$mode
			),
			ARRAY_A
		);
		$by_user = array();
		foreach ( $rows as $row ) {
			$by_user[ (int) $row['recipient_user_id'] ][] = $row;
		}
		foreach ( $by_user as $user_id => $user_rows ) {
			$items        = array();
			$delivery_ids = array();
			foreach ( $user_rows as $row ) {
				$delivery_ids[] = (int) $row['delivery_id'];
				$def            = LNKup_Comms_Registry::get( (string) $row['trigger_key'] );
				$stored         = json_decode( (string) $row['context'], true );
				$ctx            = LNKup_Comms_Context::build( is_array( $stored ) ? $stored : array() );
				if ( ! $def || '' !== LNKup_Comms_Context::check( (string) ( $def['validity'] ?? '' ), $ctx ) || '' === (string) $row['title'] ) {
					continue;
				}
				$vars    = LNKup_Comms_Context::variables( $ctx, $user_id );
				$link    = (string) ( ! empty( $stored['_link'] ) ? $stored['_link'] : $def['link'] );
				$items[] = array(
					'title' => (string) $row['title'],
					'meta'  => trim( $vars['event_date'] . ( '' !== $vars['event_city'] ? ' · ' . $vars['event_city'] : '' ), ' ·' ),
					'url'   => LNKup_Comms_Links::tracked( (int) $row['message_id'], 'digest', LNKup_Comms_Context::web_url( $link, $ctx, (string) $row['recipient_role'] ) ),
				);
				if ( count( $items ) >= 10 ) {
					break;
				}
			}
			if ( $items ) {
				LNKup_Comms_Engine::dispatch(
					'digest_email',
					$user_id,
					array( 'count' => (string) count( $items ), 'digest_period' => 'daily' === $mode ? __( 'daily', 'lnkup-communications' ) : __( 'weekly', 'lnkup-communications' ) ),
					array( 'window' => $mode . '_' . gmdate( 'Ymd' ), 'items' => $items, 'role' => (string) $user_rows[0]['recipient_role'], 'source' => 'digest' )
				);
			}
			$ids = implode( ',', array_map( 'intval', $delivery_ids ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$deliveries} SET status = %s, updated_at = %s WHERE id IN ({$ids})", $items ? 'digested' : 'digest_empty', LNKup_Comms_Schema::now() ) );
		}
	}

	/**
	 * Organizers with applications waiting longer than the configured hours.
	 */
	public static function pending_review(): void {
		$hours = max( 1, LNKup_Comms_Settings::int( 'pending_review_hours' ) );
		$apps  = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 2000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_application_status', 'value' => LNKup_Comms_Context::PENDING_STATUSES, 'compare' => 'IN' ),
				),
				'date_query'     => array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ), 'column' => 'post_date_gmt' ) ),
			)
		);
		$organizers = array();
		foreach ( array_map( 'intval', (array) $apps ) as $app_id ) {
			$org = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
			if ( $org ) {
				$organizers[ $org ] = true;
			}
		}
		foreach ( array_keys( $organizers ) as $org ) {
			LNKup_Comms_Engine::dispatch( 'o_pending_review', (int) $org, array( 'organizer_user_id' => (int) $org ), array( 'window' => gmdate( 'Ymd' ), 'source' => 'pending_review' ) );
		}
	}

	/**
	 * One operations summary per organizer covering all upcoming events. Organizers
	 * choose daily or weekly through the "Summaries & digests" email setting.
	 */
	public static function ops_summaries( string $mode ): void {
		$organizers = get_users( array( 'role__in' => array( 'lnkup_organizer' ), 'fields' => 'ID', 'number' => 3000 ) );
		foreach ( array_map( 'intval', (array) $organizers ) as $org ) {
			if ( LNKup_Comms_Preferences::email_mode( $org, 'digests' ) !== $mode ) {
				continue;
			}
			$events = LNKup_Comms_Context::organizer_upcoming_events( $org );
			if ( array() === $events ) {
				continue;
			}
			$items = array();
			foreach ( array_slice( $events, 0, 10 ) as $event_id ) {
				$items[] = array(
					'title' => (string) get_the_title( $event_id ),
					'meta'  => LNKup_Comms_Context::event_summary_line( $event_id ),
					'url'   => LNKup_Comms_Context::web_url( 'org_event', LNKup_Comms_Context::build( array( 'event_id' => $event_id ) ), 'organizer' ),
				);
			}
			LNKup_Comms_Engine::dispatch( 'o_ops_summary', $org, array( 'organizer_user_id' => $org ), array( 'window' => $mode . '_' . gmdate( 'Ymd' ), 'items' => $items, 'source' => 'ops_summary' ) );
		}
	}
}
