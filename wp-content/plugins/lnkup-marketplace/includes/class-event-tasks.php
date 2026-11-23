<?php
/**
 * Event preparation tasks: records with an assignee, due date, priority, status and comments,
 * plus organizer task templates whose due dates are relative to the event date.
 *
 * Access is per event through LNKup_Capabilities::staff_can():
 *   view_roster  see the event's tasks (and be assigned one)
 *   edit_ops     create, assign, edit, cancel, apply templates
 * The assignee may also complete or reopen their own task. A task never grants access:
 * an assignee must already hold view_roster on the event.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Tasks {

	const STATUSES    = array( 'open', 'done', 'cancelled' );
	const PRIORITIES  = array( 'low', 'normal', 'high' );
	const DIGEST_CRON = 'lnkup_event_tasks_due';
	const DUE_SOON    = 2 * DAY_IN_SECONDS;
	const MAX_ITEMS   = 50;

	public static function init() {
		add_action( self::DIGEST_CRON, array( __CLASS__, 'send_due_digest' ) );
		add_action( 'init', array( __CLASS__, 'schedule_cron' ), 20 );
	}

	public static function schedule_cron() {
		if ( LNKup_Organizer_OS_Tables::ready( '2' ) && ! wp_next_scheduled( self::DIGEST_CRON ) ) {
			wp_schedule_event( strtotime( 'tomorrow 13:00:00' ), 'daily', self::DIGEST_CRON );
		}
	}

	/* ---------------------------------------------------------------------
	 * Reads
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $task_id Task.
	 * @return array|null Raw row.
	 */
	public static function get_row( $task_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::event_tasks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $task_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Tasks for one event, open first, then by due date.
	 *
	 * @param int   $event_id Event.
	 * @param int   $user_id  Viewer.
	 * @param array $args     status (open|done|cancelled|all).
	 */
	public static function for_event( $event_id, $user_id, array $args = array() ) {
		global $wpdb;
		$table  = LNKup_Organizer_OS_Tables::event_tasks_table();
		$status = sanitize_key( (string) ( $args['status'] ?? 'all' ) );
		$where  = 'event_id = %d';
		$params = array( absint( $event_id ) );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		} else {
			$where .= " AND status <> 'cancelled'";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY FIELD(status,'open','done','cancelled'), due_at IS NULL, due_at ASC, id ASC LIMIT 500", $params ), ARRAY_A );
		return array_map(
			static function ( $row ) use ( $user_id ) {
				return self::format( $row, $user_id );
			},
			$rows
		);
	}

	/**
	 * Cross-event task list for a person.
	 *
	 * @param int   $user_id Viewer.
	 * @param array $args    view (mine|all|due_soon|overdue|completed), event_id, page, per_page.
	 * @return array{items:array,total:int,page:int,pages:int}
	 */
	public static function list_for_user( $user_id, array $args ) {
		global $wpdb;
		$events = self::scoped_event_ids( $user_id );
		$page   = max( 1, absint( $args['page'] ?? 1 ) );
		$per    = min( 100, max( 1, absint( $args['per_page'] ?? 50 ) ) );
		$empty  = array( 'items' => array(), 'total' => 0, 'page' => $page, 'pages' => 0, 'counts' => self::counts( $user_id, array() ) );
		$event  = absint( $args['event_id'] ?? 0 );
		if ( $event ) {
			if ( is_array( $events ) && ! in_array( $event, $events, true ) ) {
				return $empty;
			}
			$events = array( $event );
		}
		if ( is_array( $events ) && ! $events ) {
			return $empty;
		}
		list( $where, $params ) = self::view_where( sanitize_key( (string) ( $args['view'] ?? 'mine' ) ), $user_id, $events );
		$table = LNKup_Organizer_OS_Tables::event_tasks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY due_at IS NULL, due_at ASC, id ASC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		return array(
			'items'  => array_map(
				static function ( $row ) use ( $user_id ) {
					return self::format( $row, $user_id, true );
				},
				$rows
			),
			'total'  => $total,
			'page'   => $page,
			'pages'  => (int) ceil( $total / $per ),
			'counts' => self::counts( $user_id, $events ),
		);
	}

	/**
	 * Counts per view for the current scope (used for tabs and the Overview).
	 *
	 * @param int        $user_id Viewer.
	 * @param int[]|null $events  Scope (null = every event).
	 */
	public static function counts( $user_id, $events ) {
		global $wpdb;
		$out = array( 'mine' => 0, 'all' => 0, 'due_soon' => 0, 'overdue' => 0, 'completed' => 0 );
		if ( is_array( $events ) && ! $events ) {
			return $out;
		}
		$table = LNKup_Organizer_OS_Tables::event_tasks_table();
		foreach ( array_keys( $out ) as $view ) {
			list( $where, $params ) = self::view_where( $view, $user_id, $events );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $view ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
		}
		return $out;
	}

	/**
	 * Open and overdue counts for one event (lifecycle bar, readiness, Overview).
	 *
	 * @param int $event_id Event.
	 * @return array{open:int,overdue:int}
	 */
	public static function event_counts( $event_id ) {
		global $wpdb;
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return array( 'open' => 0, 'overdue' => 0 );
		}
		$table = LNKup_Organizer_OS_Tables::event_tasks_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(status = 'open') AS open_n, SUM(status = 'open' AND due_at IS NOT NULL AND due_at < %s) AS overdue_n FROM {$table} WHERE event_id = %d", current_time( 'mysql' ), absint( $event_id ) ), ARRAY_A );
		return array( 'open' => (int) ( $row['open_n'] ?? 0 ), 'overdue' => (int) ( $row['overdue_n'] ?? 0 ) );
	}

	/**
	 * @param string     $view    View.
	 * @param int        $user_id Viewer.
	 * @param int[]|null $events  Scope.
	 * @return array{0:string,1:array}
	 */
	private static function view_where( $view, $user_id, $events ) {
		$now    = current_time( 'mysql' );
		$where  = '1=1';
		$params = array();
		if ( is_array( $events ) ) {
			$where  = 'event_id IN (' . implode( ',', array_fill( 0, count( $events ), '%d' ) ) . ')';
			$params = array_map( 'absint', $events );
		}
		switch ( $view ) {
			case 'completed':
				$where .= " AND status = 'done'";
				break;
			case 'overdue':
				$where   .= " AND status = 'open' AND due_at IS NOT NULL AND due_at < %s";
				$params[] = $now;
				break;
			case 'due_soon':
				$where   .= " AND status = 'open' AND due_at IS NOT NULL AND due_at >= %s AND due_at <= %s";
				$params[] = $now;
				$params[] = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + self::DUE_SOON );
				break;
			case 'all':
				$where .= " AND status = 'open'";
				break;
			case 'mine':
			default:
				$where   .= " AND status = 'open' AND assignee_user_id = %d";
				$params[] = absint( $user_id );
		}
		return array( $where, $params );
	}

	/**
	 * Events whose tasks the user may see; null means all (platform admins).
	 *
	 * @param int $user_id User.
	 * @return int[]|null
	 */
	public static function scoped_event_ids( $user_id ) {
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return null;
		}
		$ids = class_exists( 'LNKup_Org_Teams' ) ? LNKup_Org_Teams::event_ids_for( $user_id, 'view_roster' ) : array();
		if ( class_exists( 'LNKup_Event_Staff' ) ) {
			global $wpdb;
			$staff = LNKup_Operations_Tables::staff_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$staff} WHERE user_id = %d", absint( $user_id ) ) ) ) );
		}
		return array_values(
			array_filter(
				array_unique( $ids ),
				static function ( $id ) use ( $user_id ) {
					return LNKup_Capabilities::staff_can( (int) $id, 'view_roster', $user_id );
				}
			)
		);
	}

	/**
	 * @param array $row        Task row.
	 * @param int   $viewer     Viewer.
	 * @param bool  $with_event Include event title/date.
	 */
	public static function format( array $row, $viewer, $with_event = false ) {
		$event_id = (int) $row['event_id'];
		$assignee = (int) $row['assignee_user_id'];
		$open     = 'open' === $row['status'];
		$due_ts   = $row['due_at'] ? strtotime( (string) $row['due_at'] ) : 0;
		$now      = strtotime( current_time( 'mysql' ) );
		$can_edit = LNKup_Capabilities::staff_can( $event_id, 'edit_ops', $viewer );
		$out      = array(
			'id'          => (int) $row['id'],
			'event_id'    => $event_id,
			'title'       => (string) $row['title'],
			'description' => (string) $row['description'],
			'assignee'    => $assignee ? self::user_label( $assignee ) : null,
			'due_at'      => (string) $row['due_at'],
			'overdue'     => $open && $due_ts && $due_ts < $now,
			'due_soon'    => $open && $due_ts && $due_ts >= $now && $due_ts <= $now + self::DUE_SOON,
			'priority'    => (string) $row['priority'],
			'status'      => (string) $row['status'],
			'source'      => (string) $row['source'],
			'completed_at' => (string) $row['completed_at'],
			'completed_by' => $row['completed_by'] ? self::user_label( (int) $row['completed_by'] ) : null,
			'can_edit'    => $can_edit,
			'can_complete' => $can_edit || ( $assignee && $assignee === (int) $viewer ),
		);
		if ( $with_event ) {
			$out['event_title'] = get_the_title( $event_id );
			$out['event_date']  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		}
		return $out;
	}

	private static function user_label( $user_id ) {
		$user = get_userdata( $user_id );
		return array( 'id' => (int) $user_id, 'name' => $user ? $user->display_name : __( 'Former team member', 'lnkup-marketplace' ) );
	}

	/**
	 * People who can be assigned tasks on an event: owner plus team/staff with view_roster.
	 *
	 * @param int $event_id Event.
	 */
	public static function assignee_candidates( $event_id ) {
		global $wpdb;
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$ids   = $owner ? array( $owner ) : array();
		if ( class_exists( 'LNKup_Org_Teams' ) && $owner ) {
			$team = LNKup_Org_Teams::list_team( $owner );
			$ids  = array_merge( $ids, array_map( 'intval', wp_list_pluck( (array) ( $team['members'] ?? array() ), 'user_id' ) ) );
		}
		$staff = LNKup_Operations_Tables::staff_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_merge( $ids, array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$staff} WHERE event_id = %d", absint( $event_id ) ) ) ) );
		$out = array();
		foreach ( array_unique( $ids ) as $uid ) {
			if ( LNKup_Capabilities::staff_can( $event_id, 'view_roster', $uid ) ) {
				$out[] = self::user_label( $uid );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Writes
	 * ------------------------------------------------------------------- */

	/**
	 * @param int   $event_id Event.
	 * @param array $data     title, description, assignee_user_id, due_at, priority.
	 * @param int   $actor    User (needs edit_ops).
	 * @param array $extra    source, template_item_key (internal).
	 * @return array|WP_Error Task.
	 */
	public static function create( $event_id, array $data, $actor, array $extra = array() ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_ops', $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot add tasks to this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$fields = self::sanitize_fields( $event_id, $data, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Organizer_OS_Tables::event_tasks_table(),
			array_merge(
				$fields,
				array(
					'event_id'          => $event_id,
					'tour_id'           => (int) get_post_meta( $event_id, '_lnkup_tour_id', true ),
					'status'            => 'open',
					'source'            => in_array( $extra['source'] ?? '', array( 'template', 'system' ), true ) ? $extra['source'] : 'manual',
					'template_item_key' => substr( sanitize_key( (string) ( $extra['template_item_key'] ?? '' ) ), 0, 80 ),
					'created_by'        => absint( $actor ),
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			)
		);
		$task_id = (int) $wpdb->insert_id;
		if ( ! $task_id ) {
			return new WP_Error( 'lnkup_task_save_failed', __( 'The task could not be saved. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		if ( ! empty( $fields['assignee_user_id'] ) ) {
			self::on_assigned( $task_id, (int) $fields['assignee_user_id'], $actor );
		}
		self::touch_event( $event_id );
		return self::format( self::get_row( $task_id ), $actor );
	}

	/**
	 * Edit fields or change status. Assignees may only change status.
	 *
	 * @param int   $task_id Task.
	 * @param array $data    Fields and/or status.
	 * @param int   $actor   User.
	 * @return array|WP_Error Task.
	 */
	public static function update( $task_id, array $data, $actor ) {
		global $wpdb;
		$row = self::get_row( $task_id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_not_found', __( 'Task not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$event_id = (int) $row['event_id'];
		$can_edit = LNKup_Capabilities::staff_can( $event_id, 'edit_ops', $actor );
		$assignee = (int) $row['assignee_user_id'] === (int) $actor && LNKup_Capabilities::staff_can( $event_id, 'view_roster', $actor );
		$fields   = array_intersect_key( $data, array_flip( array( 'title', 'description', 'assignee_user_id', 'due_at', 'priority' ) ) );
		if ( $fields && ! $can_edit ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only mark your own tasks done.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! $can_edit && ! $assignee ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot change this task.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$update = array();
		if ( $fields ) {
			$clean = self::sanitize_fields( $event_id, $fields, false );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$update = $clean;
		}
		if ( isset( $data['status'] ) ) {
			$status = sanitize_key( (string) $data['status'] );
			if ( ! in_array( $status, self::STATUSES, true ) ) {
				return new WP_Error( 'lnkup_bad_status', __( 'Choose open, done or cancelled.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			if ( 'cancelled' === $status && ! $can_edit ) {
				return new WP_Error( 'lnkup_forbidden', __( 'Only the event team can cancel tasks.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			$update['status'] = $status;
			if ( 'done' === $status && 'done' !== $row['status'] ) {
				$update['completed_by'] = absint( $actor );
				$update['completed_at'] = current_time( 'mysql' );
			} elseif ( 'done' !== $status ) {
				$update['completed_by'] = 0;
				$update['completed_at'] = null;
			}
		}
		if ( ! $update ) {
			return self::format( $row, $actor );
		}
		$update['updated_at'] = current_time( 'mysql' );
		$wpdb->update( LNKup_Organizer_OS_Tables::event_tasks_table(), $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( isset( $update['assignee_user_id'] ) && (int) $update['assignee_user_id'] && (int) $update['assignee_user_id'] !== (int) $row['assignee_user_id'] ) {
			self::on_assigned( (int) $row['id'], (int) $update['assignee_user_id'], $actor );
		}
		if ( isset( $update['status'] ) && $update['status'] !== $row['status'] ) {
			LNKup_Application_Review::audit( 'event_task_' . $update['status'], $event_id, $actor, array( 'task_id' => (int) $row['id'], 'title' => (string) $row['title'] ) );
		}
		self::touch_event( $event_id );
		return self::format( self::get_row( (int) $row['id'] ), $actor );
	}

	/**
	 * @param int    $task_id Task.
	 * @param string $body    Comment.
	 * @param int    $actor   User (view_roster on the event).
	 * @return array|WP_Error Comments.
	 */
	public static function add_comment( $task_id, $body, $actor ) {
		global $wpdb;
		$row = self::get_row( $task_id );
		if ( ! $row || ! LNKup_Capabilities::staff_can( (int) $row['event_id'], 'view_roster', $actor ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Task not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$body = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $body ) {
			return new WP_Error( 'lnkup_comment_empty', __( 'Write a comment first.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Organizer_OS_Tables::task_comments_table(),
			array( 'task_id' => (int) $row['id'], 'user_id' => absint( $actor ), 'body' => mb_substr( $body, 0, 2000 ), 'created_at' => current_time( 'mysql' ) )
		);
		return self::comments( (int) $row['id'] );
	}

	/**
	 * @param int $task_id Task.
	 */
	public static function comments( $task_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::task_comments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE task_id = %d ORDER BY id ASC LIMIT 200", absint( $task_id ) ), ARRAY_A );
		return array_map(
			static function ( $row ) {
				return array( 'id' => (int) $row['id'], 'author' => self::user_label( (int) $row['user_id'] ), 'body' => (string) $row['body'], 'created_at' => (string) $row['created_at'] );
			},
			$rows
		);
	}

	/**
	 * @param int   $event_id Event.
	 * @param array $data     Raw fields.
	 * @param bool  $creating Title required.
	 * @return array|WP_Error
	 */
	private static function sanitize_fields( $event_id, array $data, $creating ) {
		$out = array();
		if ( $creating || array_key_exists( 'title', $data ) ) {
			$title = trim( sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
			if ( '' === $title ) {
				return new WP_Error( 'lnkup_task_title', __( 'Give the task a title.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$out['title'] = mb_substr( $title, 0, 190 );
		}
		if ( $creating || array_key_exists( 'description', $data ) ) {
			$out['description'] = mb_substr( sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ), 0, 4000 );
		}
		if ( $creating || array_key_exists( 'priority', $data ) ) {
			$priority        = sanitize_key( (string) ( $data['priority'] ?? 'normal' ) );
			$out['priority'] = in_array( $priority, self::PRIORITIES, true ) ? $priority : 'normal';
		}
		if ( $creating || array_key_exists( 'due_at', $data ) ) {
			$raw = trim( (string) ( $data['due_at'] ?? '' ) );
			if ( '' === $raw ) {
				$out['due_at'] = null;
			} else {
				$ts = strtotime( $raw );
				if ( false === $ts ) {
					return new WP_Error( 'lnkup_task_due', __( 'Enter a valid due date.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
				}
				$out['due_at'] = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		if ( $creating || array_key_exists( 'assignee_user_id', $data ) ) {
			$assignee = absint( $data['assignee_user_id'] ?? 0 );
			if ( $assignee && ! LNKup_Capabilities::staff_can( $event_id, 'view_roster', $assignee ) ) {
				return new WP_Error( 'lnkup_task_assignee', __( 'That person does not have access to this event. Add them to your team first.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$out['assignee_user_id'] = $assignee;
		}
		return $out;
	}

	/**
	 * @param int $task_id  Task.
	 * @param int $assignee New assignee.
	 * @param int $actor    Actor.
	 */
	private static function on_assigned( $task_id, $assignee, $actor ) {
		$row = self::get_row( $task_id );
		if ( ! $row ) {
			return;
		}
		LNKup_Application_Review::audit( 'event_task_assigned', (int) $row['event_id'], $actor, array( 'task_id' => (int) $task_id, 'assignee_user_id' => $assignee ) );
		do_action( 'lnkup_event_task_assigned', (int) $task_id, $assignee, absint( $actor ) );
		$user = get_userdata( $assignee );
		if ( ! $user || $assignee === (int) $actor || ! class_exists( 'LNKup_Notifications' ) ) {
			return;
		}
		$event = get_the_title( (int) $row['event_id'] );
		$due   = $row['due_at'] ? wp_date( get_option( 'date_format' ), strtotime( (string) $row['due_at'] ) ) : '';
		LNKup_Notifications::send_keyed(
			'task_assigned',
			array( (int) $task_id, (int) $assignee ),
			$user->user_email,
			/* translators: %s: event name */
			sprintf( __( 'New task for %s', 'lnkup-marketplace' ), $event ),
			wpautop(
				esc_html(
					'' !== $due
						/* translators: 1: task, 2: event, 3: due date */
						? sprintf( __( 'You were assigned "%1$s" for %2$s, due %3$s.', 'lnkup-marketplace' ), $row['title'], $event, $due )
						/* translators: 1: task, 2: event */
						: sprintf( __( 'You were assigned "%1$s" for %2$s.', 'lnkup-marketplace' ), $row['title'], $event )
				)
			) . '<p><a href="' . esc_url( lnkup_organizer_view_url( 'tasks' ) ) . '">' . esc_html__( 'Open my tasks', 'lnkup-marketplace' ) . '</a></p>'
		);
	}

	/**
	 * @param int $event_id Event.
	 */
	private static function touch_event( $event_id ) {
		do_action( 'lnkup_event_tasks_changed', absint( $event_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Templates
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $owner_id Organizer.
	 */
	public static function templates_for( $owner_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::task_templates_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_user_id = %d ORDER BY name ASC", absint( $owner_id ) ), ARRAY_A );
		return array_map( array( __CLASS__, 'format_template' ), $rows );
	}

	public static function format_template( array $row ) {
		$items = json_decode( (string) $row['items'], true );
		return array( 'id' => (int) $row['id'], 'name' => (string) $row['name'], 'items' => is_array( $items ) ? $items : array(), 'updated_at' => (string) $row['updated_at'] );
	}

	/**
	 * Create (id 0) or update an organizer's template.
	 *
	 * @param int   $owner_id    Organizer.
	 * @param int   $template_id Template (0 = new).
	 * @param array $data        name, items[{title, offset_days_before_event, priority}].
	 * @return array|WP_Error
	 */
	public static function save_template( $owner_id, $template_id, array $data ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::task_templates_table();
		$name  = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_template_name', __( 'Name the template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$items = array();
		$keys  = array();
		foreach ( array_slice( (array) ( $data['items'] ?? array() ), 0, self::MAX_ITEMS ) as $item ) {
			$title = trim( sanitize_text_field( (string) ( $item['title'] ?? '' ) ) );
			if ( '' === $title ) {
				continue;
			}
			$key  = sanitize_key( (string) ( $item['key'] ?? '' ) ) ?: substr( sanitize_key( $title ), 0, 40 );
			$base = $key;
			$i    = 2;
			while ( in_array( $key, $keys, true ) ) {
				$key = $base . '_' . $i++;
			}
			$keys[]   = $key;
			$priority = sanitize_key( (string) ( $item['priority'] ?? 'normal' ) );
			$items[]  = array(
				'key'                      => $key,
				'title'                    => mb_substr( $title, 0, 190 ),
				'offset_days_before_event' => max( -30, min( 365, (int) ( $item['offset_days_before_event'] ?? 0 ) ) ),
				'priority'                 => in_array( $priority, self::PRIORITIES, true ) ? $priority : 'normal',
			);
		}
		if ( ! $items ) {
			return new WP_Error( 'lnkup_template_empty', __( 'Add at least one task to the template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql' );
		if ( $template_id ) {
			$existing = self::get_template( $template_id );
			if ( ! $existing || (int) $existing['owner_user_id'] !== absint( $owner_id ) ) {
				return new WP_Error( 'lnkup_not_found', __( 'Template not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			$wpdb->update( $table, array( 'name' => mb_substr( $name, 0, 120 ), 'items' => wp_json_encode( $items ), 'updated_at' => $now ), array( 'id' => absint( $template_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( $table, array( 'owner_user_id' => absint( $owner_id ), 'name' => mb_substr( $name, 0, 120 ), 'items' => wp_json_encode( $items ), 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$template_id = (int) $wpdb->insert_id;
		}
		return self::format_template( self::get_template( $template_id ) );
	}

	/**
	 * @param int $template_id Template.
	 */
	public static function get_template( $template_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::task_templates_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $template_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param int $owner_id    Organizer.
	 * @param int $template_id Template.
	 * @return true|WP_Error
	 */
	public static function delete_template( $owner_id, $template_id ) {
		global $wpdb;
		$existing = self::get_template( $template_id );
		if ( ! $existing || (int) $existing['owner_user_id'] !== absint( $owner_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Template not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$wpdb->delete( LNKup_Organizer_OS_Tables::task_templates_table(), array( 'id' => absint( $template_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Create the template's tasks on an event. Items already applied (same template_item_key) are
	 * skipped, so applying twice is safe. Due dates are N days before the event date at 17:00.
	 *
	 * @param int $event_id    Event.
	 * @param int $template_id Template (must belong to the event owner).
	 * @param int $actor       User (edit_ops).
	 * @return array|WP_Error {created:int, skipped:int, tasks:[]}
	 */
	public static function apply_template( $event_id, $template_id, $actor ) {
		global $wpdb;
		$event_id = absint( $event_id );
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_ops', $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot add tasks to this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$template = self::get_template( $template_id );
		$owner    = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $template || (int) $template['owner_user_id'] !== $owner ) {
			return new WP_Error( 'lnkup_not_found', __( 'Template not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$table    = LNKup_Organizer_OS_Tables::event_tasks_table();
		$prefix   = 't' . (int) $template['id'] . '_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (array) $wpdb->get_col( $wpdb->prepare( "SELECT template_item_key FROM {$table} WHERE event_id = %d AND template_item_key LIKE %s", $event_id, $wpdb->esc_like( $prefix ) . '%' ) );
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$base     = $date ? strtotime( $date . ' 17:00:00' ) : false;
		$created  = array();
		$skipped  = 0;
		foreach ( self::format_template( $template )['items'] as $item ) {
			$key = substr( $prefix . $item['key'], 0, 80 );
			if ( in_array( $key, $existing, true ) ) {
				++$skipped;
				continue;
			}
			$due    = $base ? gmdate( 'Y-m-d H:i:s', $base - (int) $item['offset_days_before_event'] * DAY_IN_SECONDS ) : '';
			$result = self::create( $event_id, array( 'title' => $item['title'], 'priority' => $item['priority'], 'due_at' => $due ), $actor, array( 'source' => 'template', 'template_item_key' => $key ) );
			if ( ! is_wp_error( $result ) ) {
				$created[] = $result;
			}
		}
		LNKup_Application_Review::audit( 'event_task_template_applied', $event_id, $actor, array( 'template_id' => (int) $template['id'], 'created' => count( $created ) ) );
		return array( 'created' => count( $created ), 'skipped' => $skipped, 'tasks' => $created );
	}

	/* ---------------------------------------------------------------------
	 * Digest
	 * ------------------------------------------------------------------- */

	/**
	 * Daily: one email per assignee listing overdue tasks and tasks due within 48 hours.
	 *
	 * @return int Emails sent.
	 */
	public static function send_due_digest() {
		global $wpdb;
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) || ! LNKup_Feature_Flags::organizer_os_anywhere( LNKup_Feature_Flags::ORGANIZER_OPS ) || ! class_exists( 'LNKup_Notifications' ) ) {
			return 0;
		}
		$table = LNKup_Organizer_OS_Tables::event_tasks_table();
		$limit = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + self::DUE_SOON );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = 'open' AND assignee_user_id > 0 AND due_at IS NOT NULL AND due_at <= %s ORDER BY assignee_user_id, due_at LIMIT 2000", $limit ), ARRAY_A );
		$by   = array();
		foreach ( $rows as $row ) {
			if ( LNKup_Capabilities::staff_can( (int) $row['event_id'], 'view_roster', (int) $row['assignee_user_id'] ) ) {
				$by[ (int) $row['assignee_user_id'] ][] = $row;
			}
		}
		$sent = 0;
		foreach ( $by as $user_id => $tasks ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}
			$lines = '';
			foreach ( array_slice( $tasks, 0, 25 ) as $task ) {
				$lines .= '<li>' . esc_html( $task['title'] . ' — ' . get_the_title( (int) $task['event_id'] ) . ' (' . wp_date( get_option( 'date_format' ), strtotime( (string) $task['due_at'] ) ) . ')' ) . '</li>';
			}
			LNKup_Notifications::send_keyed(
				'task_digest',
				array( (int) $user_id, count( $tasks ) ),
				$user->user_email,
				/* translators: %d: number of tasks */
				sprintf( _n( '%d event task needs attention', '%d event tasks need attention', count( $tasks ), 'lnkup-marketplace' ), count( $tasks ) ),
				'<p>' . esc_html__( 'These tasks are overdue or due within two days:', 'lnkup-marketplace' ) . '</p><ul>' . $lines . '</ul><p><a href="' . esc_url( lnkup_organizer_view_url( 'tasks' ) ) . '">' . esc_html__( 'Open my tasks', 'lnkup-marketplace' ) . '</a></p>'
			);
			++$sent;
		}
		return $sent;
	}
}
