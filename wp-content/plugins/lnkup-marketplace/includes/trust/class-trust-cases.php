<?php
/**
 * Trust & Safety case queue.
 *
 * Every significant trust signal (claim conflicts, fraud flags, chargebacks,
 * audit-chain breaks, verification requests) becomes a reviewable case with
 * a timeline. Cases never take consequential action on their own.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Trust_Cases {

	const SEVERITIES = array( 'low', 'review', 'elevated', 'critical' );

	const STATUSES = array( 'open', 'in_review', 'info_requested', 'awaiting_response', 'resolved', 'dismissed_false_positive' );

	const CLOSED_STATUSES = array( 'resolved', 'dismissed_false_positive' );

	/**
	 * Allowed transitions; anything else is refused.
	 */
	const TRANSITIONS = array(
		'open'                     => array( 'in_review', 'info_requested', 'awaiting_response', 'resolved', 'dismissed_false_positive' ),
		'in_review'                => array( 'info_requested', 'awaiting_response', 'resolved', 'dismissed_false_positive' ),
		'info_requested'           => array( 'in_review', 'awaiting_response', 'resolved', 'dismissed_false_positive' ),
		'awaiting_response'        => array( 'in_review', 'info_requested', 'resolved', 'dismissed_false_positive' ),
		'resolved'                 => array( 'in_review' ),
		'dismissed_false_positive' => array( 'in_review' ),
	);

	const CASE_TYPES = array(
		'event_claim_mismatch'      => 'Event claim: email mismatch',
		'event_claim_conflict'      => 'Event claim: ownership conflict',
		'event_claim_review'        => 'Event claim: pending review',
		'possible_duplicate'        => 'Possible duplicate event',
		'event_material_change'     => 'Verified event changed organizer or payout',
		'organizer_verification'    => 'Organizer verification request',
		'fraud_flag'                => 'Payment risk flag',
		'payment_mismatch'          => 'Payment confirmation rejected',
		'chargeback'                => 'Stripe chargeback',
		'audit_integrity'           => 'Audit log integrity',
		'team_access'               => 'Team access change',
	);

	public static function init() {
		add_action( 'lnkup_chargeback_closed', array( __CLASS__, 'on_chargeback_closed' ), 10, 2 );
	}

	/**
	 * Open (or refresh) a case. Idempotent on case_type + subject + signal_key while the case is open.
	 *
	 * @param array $args case_type, severity, subject_type, subject_id, event_id, application_id,
	 *                    payment_id, signal_key, summary, evidence (array), next_step, opened_by.
	 * @return int Case ID (0 on failure).
	 */
	public static function open( array $args ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return 0;
		}

		$type         = sanitize_key( (string) ( $args['case_type'] ?? '' ) );
		$subject_type = sanitize_key( (string) ( $args['subject_type'] ?? '' ) );
		$subject_id   = absint( $args['subject_id'] ?? 0 );
		$signal_key   = substr( sanitize_text_field( (string) ( $args['signal_key'] ?? '' ) ), 0, 120 );
		$severity     = in_array( $args['severity'] ?? '', self::SEVERITIES, true ) ? $args['severity'] : 'review';
		if ( '' === $type ) {
			return 0;
		}

		$existing = self::find_open( $type, $subject_type, $subject_id, $signal_key );
		if ( $existing ) {
			$evidence = array_merge( (array) json_decode( (string) $existing['evidence'], true ), (array) ( $args['evidence'] ?? array() ) );
			$update   = array(
				'evidence'   => wp_json_encode( LNKup_Audit_Log::scrub( array_slice( $evidence, -50 ) ) ),
				'updated_at' => current_time( 'mysql', true ),
			);
			if ( array_search( $severity, self::SEVERITIES, true ) > array_search( $existing['severity'], self::SEVERITIES, true ) ) {
				$update['severity'] = $severity;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, $update, array( 'id' => (int) $existing['id'] ) );
			self::add_event( (int) $existing['id'], 'signal_repeated', '', '', __( 'Signal seen again.', 'lnkup-marketplace' ) );
			return (int) $existing['id'];
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'case_ref'       => 'TMP-' . wp_generate_password( 12, false ),
				'case_type'      => $type,
				'severity'       => $severity,
				'status'         => 'open',
				'subject_type'   => $subject_type,
				'subject_id'     => $subject_id,
				'event_id'       => absint( $args['event_id'] ?? 0 ),
				'application_id' => absint( $args['application_id'] ?? 0 ),
				'payment_id'     => absint( $args['payment_id'] ?? 0 ),
				'signal_key'     => $signal_key,
				'summary'        => substr( sanitize_text_field( (string) ( $args['summary'] ?? '' ) ), 0, 500 ),
				'evidence'       => wp_json_encode( LNKup_Audit_Log::scrub( (array) ( $args['evidence'] ?? array() ) ) ),
				'next_step'      => substr( sanitize_text_field( (string) ( $args['next_step'] ?? '' ) ), 0, 255 ),
				'opened_by'      => absint( $args['opened_by'] ?? 0 ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		$case_id = (int) $wpdb->insert_id;
		if ( ! $case_id ) {
			return 0;
		}
		$ref = sprintf( 'TS-%s-%06d', gmdate( 'Y' ), $case_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'case_ref' => $ref ), array( 'id' => $case_id ) );

		self::add_event( $case_id, 'opened', '', 'open', (string) ( $args['summary'] ?? '' ), absint( $args['opened_by'] ?? 0 ) );
		LNKup_Audit_Log::log(
			'trust_case_opened',
			'trust_case',
			$case_id,
			array(
				'actor_user_id' => absint( $args['opened_by'] ?? 0 ),
				'new'           => array( 'case_ref' => $ref, 'case_type' => $type, 'severity' => $severity, 'subject_type' => $subject_type, 'subject_id' => $subject_id ),
			)
		);
		do_action( 'lnkup_trust_case_opened', $case_id, $type, $severity );
		return $case_id;
	}

	/**
	 * Move a case to a new status.
	 *
	 * @param int    $case_id Case ID.
	 * @param string $to      Target status.
	 * @param string $note    Reviewer note (required for closing).
	 * @param int    $actor   Actor user ID.
	 * @return true|WP_Error
	 */
	public static function transition( $case_id, $to, $note = '', $actor = 0 ) {
		global $wpdb;
		$case = self::get( $case_id );
		if ( ! $case ) {
			return new WP_Error( 'lnkup_case_not_found', __( 'Case not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$to   = sanitize_key( $to );
		$from = (string) $case['status'];
		if ( ! in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true ) ) {
			return new WP_Error( 'lnkup_case_bad_transition', __( 'That status change is not allowed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );
		if ( in_array( $to, self::CLOSED_STATUSES, true ) && mb_strlen( $note ) < 5 ) {
			return new WP_Error( 'lnkup_case_note_required', __( 'Add a short resolution note before closing a case.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$actor = $actor ? absint( $actor ) : get_current_user_id();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			LNKup_Trust_Tables::cases_table(),
			array(
				'status'      => $to,
				'updated_at'  => $now,
				'resolved_at' => in_array( $to, self::CLOSED_STATUSES, true ) ? $now : null,
			),
			array( 'id' => (int) $case_id, 'status' => $from )
		);
		if ( ! $updated ) {
			return new WP_Error( 'lnkup_case_conflict', __( 'This case was updated by someone else. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::add_event( (int) $case_id, 'status_changed', $from, $to, $note, $actor );
		LNKup_Audit_Log::log(
			'trust_case_status_changed',
			'trust_case',
			(int) $case_id,
			array(
				'actor_user_id' => $actor,
				'prev'          => array( 'status' => $from ),
				'new'           => array( 'status' => $to ),
				'reason'        => $note,
			)
		);
		do_action( 'lnkup_trust_case_transitioned', (int) $case_id, $from, $to, $actor );
		return true;
	}

	/**
	 * @param int $case_id  Case ID.
	 * @param int $assignee Reviewer user ID (0 = unassign).
	 * @param int $actor    Actor.
	 * @return true|WP_Error
	 */
	public static function assign( $case_id, $assignee, $actor = 0 ) {
		global $wpdb;
		$case = self::get( $case_id );
		if ( ! $case ) {
			return new WP_Error( 'lnkup_case_not_found', __( 'Case not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$assignee = absint( $assignee );
		if ( $assignee && ! LNKup_Trust::user_can_manage( $assignee ) ) {
			return new WP_Error( 'lnkup_case_bad_assignee', __( 'Cases can only be assigned to Trust & Safety reviewers.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$actor = $actor ? absint( $actor ) : get_current_user_id();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( LNKup_Trust_Tables::cases_table(), array( 'assigned_to' => $assignee, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $case_id ) );
		self::add_event( (int) $case_id, 'assigned', '', '', $assignee ? sprintf( 'Assigned to user #%d', $assignee ) : 'Unassigned', $actor );
		LNKup_Audit_Log::log( 'trust_case_assigned', 'trust_case', (int) $case_id, array( 'actor_user_id' => $actor, 'prev' => array( 'assigned_to' => (int) $case['assigned_to'] ), 'new' => array( 'assigned_to' => $assignee ) ) );
		return true;
	}

	/**
	 * @param int    $case_id Case ID.
	 * @param string $note    Note.
	 * @param int    $actor   Actor.
	 * @return true|WP_Error
	 */
	public static function add_note( $case_id, $note, $actor = 0 ) {
		if ( ! self::get( $case_id ) ) {
			return new WP_Error( 'lnkup_case_not_found', __( 'Case not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );
		if ( '' === $note ) {
			return new WP_Error( 'lnkup_case_note_required', __( 'Note cannot be empty.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$actor = $actor ? absint( $actor ) : get_current_user_id();
		self::add_event( (int) $case_id, 'note', '', '', mb_substr( $note, 0, 4000 ), $actor );
		LNKup_Audit_Log::log( 'trust_case_note_added', 'trust_case', (int) $case_id, array( 'actor_user_id' => $actor ) );
		return true;
	}

	/**
	 * Close a case as resolved or false positive.
	 *
	 * @param int    $case_id Case ID.
	 * @param string $outcome resolved|dismissed_false_positive.
	 * @param string $note    Note.
	 * @param int    $actor   Actor.
	 */
	public static function resolve( $case_id, $outcome, $note, $actor = 0 ) {
		$outcome = in_array( $outcome, self::CLOSED_STATUSES, true ) ? $outcome : 'resolved';
		return self::transition( $case_id, $outcome, $note, $actor );
	}

	public static function get( $case_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $case_id ) ), ARRAY_A );
		return $row ?: null;
	}

	public static function timeline( $case_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::case_events_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE case_id = %d ORDER BY id ASC", absint( $case_id ) ), ARRAY_A );
	}

	/**
	 * Filtered queue.
	 *
	 * @param array $args status (string|'active'), severity, case_type, assigned_to, subject_type, subject_id, event_id, limit, offset.
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		$where = array( '1=1' );
		$vals  = array();
		$status = (string) ( $args['status'] ?? 'active' );
		if ( 'active' === $status ) {
			$where[] = "status NOT IN ('resolved','dismissed_false_positive')";
		} elseif ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		if ( ! empty( $args['severity'] ) && in_array( $args['severity'], self::SEVERITIES, true ) ) {
			$where[] = 'severity = %s';
			$vals[]  = $args['severity'];
		}
		foreach ( array( 'case_type', 'subject_type' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "{$col} = %s";
				$vals[]  = sanitize_key( $args[ $col ] );
			}
		}
		foreach ( array( 'assigned_to', 'subject_id', 'event_id' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== $args[ $col ] ) {
				$where[] = "{$col} = %d";
				$vals[]  = absint( $args[ $col ] );
			}
		}
		$where_sql = implode( ' AND ', $where );
		$limit     = min( 100, max( 1, absint( $args['limit'] ?? 25 ) ) );
		$offset    = max( 0, absint( $args['offset'] ?? 0 ) );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY FIELD(severity,'critical','elevated','review','low'), updated_at DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( $limit, $offset ) ) ), ARRAY_A );

		return array( 'items' => array_map( array( __CLASS__, 'format' ), $items ), 'total' => $total );
	}

	/**
	 * Open-case counts by severity and type for the overview.
	 */
	public static function open_counts() {
		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		$out   = array( 'total' => 0, 'by_severity' => array_fill_keys( self::SEVERITIES, 0 ), 'by_type' => array() );
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( "SELECT case_type, severity, COUNT(*) AS n FROM {$table} WHERE status NOT IN ('resolved','dismissed_false_positive') GROUP BY case_type, severity", ARRAY_A );
		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			$out['total'] += $n;
			if ( isset( $out['by_severity'][ $row['severity'] ] ) ) {
				$out['by_severity'][ $row['severity'] ] += $n;
			}
			$out['by_type'][ $row['case_type'] ] = ( $out['by_type'][ $row['case_type'] ] ?? 0 ) + $n;
		}
		return $out;
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'             => (int) $row['id'],
			'case_ref'       => (string) $row['case_ref'],
			'case_type'      => (string) $row['case_type'],
			'case_label'     => self::CASE_TYPES[ $row['case_type'] ] ?? (string) $row['case_type'],
			'severity'       => (string) $row['severity'],
			'status'         => (string) $row['status'],
			'subject_type'   => (string) $row['subject_type'],
			'subject_id'     => (int) $row['subject_id'],
			'event_id'       => (int) $row['event_id'],
			'application_id' => (int) $row['application_id'],
			'payment_id'     => (int) $row['payment_id'],
			'summary'        => (string) $row['summary'],
			'evidence'       => (array) json_decode( (string) $row['evidence'], true ),
			'assigned_to'    => (int) $row['assigned_to'],
			'next_step'      => (string) $row['next_step'],
			'created_at'     => (string) $row['created_at'],
			'updated_at'     => (string) $row['updated_at'],
			'resolved_at'    => (string) ( $row['resolved_at'] ?? '' ),
		);
	}

	/**
	 * Stripe decided a chargeback: record it on the matching case (the money side stays in Financial OS).
	 *
	 * @param int    $payment_id Payment ID.
	 * @param string $outcome    won|lost.
	 */
	public static function on_chargeback_closed( $payment_id, $outcome ) {
		$case = self::find_open( 'chargeback', 'payment', absint( $payment_id ), 'chargeback_' . absint( $payment_id ) );
		if ( ! $case ) {
			return;
		}
		self::add_event( (int) $case['id'], 'provider_decision', '', '', 'Stripe decision: ' . sanitize_key( $outcome ) );
		self::transition( (int) $case['id'], 'resolved', sprintf( 'Chargeback %s at the card network (recorded from Stripe webhook).', sanitize_key( $outcome ) ), 0 );
	}

	private static function find_open( $type, $subject_type, $subject_id, $signal_key ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::cases_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE case_type = %s AND subject_type = %s AND subject_id = %d AND signal_key = %s AND status NOT IN ('resolved','dismissed_false_positive') ORDER BY id DESC LIMIT 1",
				$type,
				$subject_type,
				$subject_id,
				$signal_key
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	private static function add_event( $case_id, $event_type, $from, $to, $note = '', $actor = 0 ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::case_events_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'case_id'       => absint( $case_id ),
				'event_type'    => sanitize_key( $event_type ),
				'actor_user_id' => absint( $actor ),
				'from_status'   => sanitize_key( $from ),
				'to_status'     => sanitize_key( $to ),
				'note'          => (string) $note,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}
}
