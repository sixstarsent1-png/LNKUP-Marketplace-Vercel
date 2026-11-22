<?php
/**
 * Follow-up tasks and meetings for organizer leads, plus a daily digest to assignees.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Tasks {

	const TYPES = array( 'follow_up', 'call', 'meeting', 'demo', 'research', 'verify_contact' );

	public static function init(): void {
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'send_digests' ), 55 );
	}

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TASKS );
	}

	/**
	 * @param array<string, mixed> $args type, title, notes, due_at, assigned_user_id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create( int $lead_id, array $args, int $actor ) {
		global $wpdb;
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		if ( ! $lead ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$type  = sanitize_key( (string) ( $args['type'] ?? 'follow_up' ) );
		$type  = in_array( $type, self::TYPES, true ) ? $type : 'follow_up';
		$title = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = ucwords( str_replace( '_', ' ', $type ) ) . ': ' . (string) $lead['name'];
		}
		$due_ts = ! empty( $args['due_at'] ) ? strtotime( (string) $args['due_at'] ) : false;
		if ( ! $due_ts ) {
			return new WP_Error( 'lnkup_task_due_required', __( 'Pick a due date for this task.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$due      = gmdate( 'Y-m-d H:i:s', $due_ts );
		$assignee = (int) ( $args['assigned_user_id'] ?? 0 );
		if ( ! $assignee ) {
			$assignee = (int) $lead['assigned_user_id'] ?: $actor;
		}
		$wpdb->insert(
			self::table(),
			array(
				'lead_id'          => $lead_id,
				'type'             => $type,
				'title'            => substr( $title, 0, 191 ),
				'notes'            => sanitize_textarea_field( (string) ( $args['notes'] ?? '' ) ),
				'assigned_user_id' => $assignee,
				'due_at'           => $due,
				'status'           => 'open',
				'created_by'       => $actor,
				'created_at'       => LNKup_Org_Growth::now(),
			)
		);
		$task_id = (int) $wpdb->insert_id;
		if ( ! $task_id ) {
			return new WP_Error( 'lnkup_task_failed', __( 'The task could not be saved.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$next = (string) ( $lead['next_follow_up_at'] ?? '' );
		if ( '' === $next || $due < $next ) {
			$wpdb->update( LNKup_Org_Leads::table(), array( 'next_follow_up_at' => $due ), array( 'id' => $lead_id ) );
		}
		LNKup_Org_Leads::log_activity( $lead_id, 'task_created', array( 'actor' => $actor, 'summary' => $title, 'payload' => array( 'task_id' => $task_id, 'type' => $type, 'due_at' => $due ) ) );
		if ( in_array( $type, array( 'meeting', 'demo' ), true ) && LNKup_Org_Lead_Pipeline::rank( 'meeting_scheduled' ) > LNKup_Org_Lead_Pipeline::rank( (string) $lead['stage'] ) ) {
			LNKup_Org_Leads::move( $lead_id, 'meeting_scheduled', '', $actor, __( 'Meeting scheduled', 'lnkup-marketplace' ) );
		}
		return (array) self::get( $task_id );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $task_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $task_id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * @param array<string, mixed> $row Task row.
	 * @return array<string, mixed>
	 */
	public static function format( array $row ): array {
		$user = ! empty( $row['assigned_user_id'] ) ? get_userdata( (int) $row['assigned_user_id'] ) : false;
		$due  = (string) ( $row['due_at'] ?? '' );
		return array(
			'id'               => (int) $row['id'],
			'lead_id'          => (int) $row['lead_id'],
			'lead_name'        => (string) ( $row['lead_name'] ?? '' ),
			'type'             => (string) $row['type'],
			'title'            => (string) $row['title'],
			'notes'            => (string) $row['notes'],
			'assigned_user_id' => (int) $row['assigned_user_id'],
			'assigned_name'    => $user ? (string) $user->display_name : '',
			'due_at'           => $due,
			'is_overdue'       => 'open' === $row['status'] && '' !== $due && $due < LNKup_Org_Growth::now(),
			'status'           => (string) $row['status'],
			'completed_at'     => (string) ( $row['completed_at'] ?? '' ),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_lead( int $lead_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE lead_id = %d ORDER BY status = 'open' DESC, due_at ASC LIMIT 100", $lead_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * Task queue for the Tasks view.
	 *
	 * @param array<string, mixed> $args assigned_user_id, window (overdue|today|upcoming|all), status.
	 * @return array<int, array<string, mixed>>
	 */
	public static function queue( array $args ): array {
		global $wpdb;
		$where  = array( 't.status = %s' );
		$values = array( 'done' === ( $args['status'] ?? '' ) ? 'done' : 'open' );
		if ( ! empty( $args['assigned_user_id'] ) ) {
			$where[]  = 't.assigned_user_id = %d';
			$values[] = (int) $args['assigned_user_id'];
		}
		$now   = LNKup_Org_Growth::now();
		$today = substr( $now, 0, 10 );
		switch ( (string) ( $args['window'] ?? 'all' ) ) {
			case 'overdue':
				$where[]  = 't.due_at < %s';
				$values[] = $now;
				break;
			case 'today':
				$where[]  = 't.due_at BETWEEN %s AND %s';
				$values[] = $today . ' 00:00:00';
				$values[] = $today . ' 23:59:59';
				break;
			case 'upcoming':
				$where[]  = 't.due_at > %s';
				$values[] = $today . ' 23:59:59';
				break;
		}
		$leads = LNKup_Org_Leads::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.*, l.name AS lead_name FROM ' . self::table() . " t INNER JOIN {$leads} l ON l.id = t.lead_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY t.due_at ASC LIMIT 200', $values ), ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), (array) $rows );
	}

	/**
	 * @return array<string, mixed>|WP_Error
	 */
	public static function complete( int $task_id, int $actor, string $outcome_note = '' ) {
		global $wpdb;
		$task = self::get( $task_id );
		if ( ! $task ) {
			return new WP_Error( 'lnkup_task_not_found', __( 'Task not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( 'open' !== $task['status'] ) {
			return $task;
		}
		$wpdb->update( self::table(), array( 'status' => 'done', 'completed_at' => LNKup_Org_Growth::now() ), array( 'id' => $task_id ) );
		LNKup_Org_Leads::log_activity( (int) $task['lead_id'], 'task_completed', array( 'actor' => $actor, 'summary' => (string) $task['title'], 'payload' => array( 'task_id' => $task_id, 'note' => sanitize_textarea_field( $outcome_note ) ) ) );
		// Next follow-up becomes the earliest remaining open task.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(due_at) FROM ' . self::table() . " WHERE lead_id = %d AND status = 'open'", (int) $task['lead_id'] ) );
		$wpdb->update( LNKup_Org_Leads::table(), array( 'next_follow_up_at' => $next ?: null ), array( 'id' => (int) $task['lead_id'] ) );
		return (array) self::get( $task_id );
	}

	/**
	 * One email per assignee per day listing due and overdue follow-ups.
	 */
	public static function send_digests(): void {
		global $wpdb;
		if ( ! LNKup_Org_Growth::is_enabled() || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::TASKS ) ) {
			return;
		}
		$today = substr( LNKup_Org_Growth::now(), 0, 10 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$assignees = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT assigned_user_id FROM ' . self::table() . " WHERE status = 'open' AND assigned_user_id > 0 AND due_at <= %s", $today . ' 23:59:59' ) );
		foreach ( $assignees as $user_id ) {
			$user_id = (int) $user_id;
			$user    = get_userdata( $user_id );
			if ( ! $user || ! user_can( $user, LNKup_Org_Growth::CAP ) || get_user_meta( $user_id, '_lnkup_org_task_digest_date', true ) === $today ) {
				continue;
			}
			$tasks = array_merge(
				self::queue( array( 'assigned_user_id' => $user_id, 'window' => 'overdue' ) ),
				self::queue( array( 'assigned_user_id' => $user_id, 'window' => 'today' ) )
			);
			$seen  = array();
			$items = '';
			foreach ( $tasks as $task ) {
				if ( isset( $seen[ $task['id'] ] ) ) {
					continue;
				}
				$seen[ $task['id'] ] = true;
				$items              .= '<li>' . esc_html( $task['title'] ) . ' — ' . esc_html( $task['lead_name'] ) . ( $task['is_overdue'] ? ' <strong>(' . esc_html__( 'overdue', 'lnkup-marketplace' ) . ')</strong>' : '' ) . '</li>';
			}
			if ( '' === $items ) {
				continue;
			}
			update_user_meta( $user_id, '_lnkup_org_task_digest_date', $today );
			$url  = admin_url( 'admin.php?page=lnkup-organizer-growth#tasks' );
			$body = '<p>' . esc_html__( 'Organizer follow-ups due today:', 'lnkup-marketplace' ) . '</p><ul>' . $items . '</ul><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open Organizer Growth', 'lnkup-marketplace' ) . '</a></p>';
			LNKup_Notifications::send( (string) $user->user_email, __( 'Your organizer follow-ups for today', 'lnkup-marketplace' ), $body );
		}
	}
}
