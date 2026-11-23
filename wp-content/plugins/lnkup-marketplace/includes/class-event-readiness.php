<?php
/**
 * Event readiness checklist.
 *
 * Each item is evaluated from canonical records. Its system result is pass, fail or unknown:
 *   pass    → verified (the system confirmed it)
 *   fail    → incomplete, even if someone confirmed it by hand (the mark is still shown)
 *   unknown → confirmed when a person confirmed it, otherwise incomplete
 * "Not applicable" is a separate manual mark (edit_ops, audited) for items that may not apply.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Readiness {

	const MARKS_META = '_lnkup_readiness_marks';
	const CACHE_TTL  = 60;
	const CACHE_KEY  = 'lnkup_evt_ready_';

	/** Items a person may confirm by hand when the system cannot decide. */
	const MANUAL_CONFIRM = array( 'setup_instructions_sent', 'checkin_ready', 'team_assigned' );

	/** Items that may be marked not applicable. */
	const NA_ALLOWED = array( 'booth_inventory', 'booth_assignments', 'documents_collected', 'packet_prepared', 'setup_instructions_sent', 'checkin_ready', 'team_assigned' );

	public static function init() {
		foreach ( array( 'lnkup_application_status_changed', 'lnkup_booth_assigned', 'lnkup_event_tasks_changed', 'lnkup_organizer_document_reviewed', 'lnkup_document_shared', 'lnkup_document_reviewed' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_from_hook' ), 50, 1 );
		}
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_from_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_from_meta' ), 10, 4 );
	}

	public static function labels() {
		return array(
			'event_details'           => __( 'Event details confirmed', 'lnkup-marketplace' ),
			'application_settings'    => __( 'Vendor application settings complete', 'lnkup-marketplace' ),
			'booth_inventory'         => __( 'Booth inventory configured', 'lnkup-marketplace' ),
			'applications_reviewed'   => __( 'Vendor applications reviewed', 'lnkup-marketplace' ),
			'payments_reconciled'     => __( 'Vendor payments collected', 'lnkup-marketplace' ),
			'booth_assignments'       => __( 'Booth assignments completed', 'lnkup-marketplace' ),
			'documents_collected'     => __( 'Required documents collected', 'lnkup-marketplace' ),
			'packet_prepared'         => __( 'Vendor packet prepared', 'lnkup-marketplace' ),
			'setup_instructions_sent' => __( 'Setup instructions sent', 'lnkup-marketplace' ),
			'checkin_ready'           => __( 'Check-in ready', 'lnkup-marketplace' ),
			'team_assigned'           => __( 'Event team assigned', 'lnkup-marketplace' ),
		);
	}

	/**
	 * @param int $event_id Event.
	 * @return array{event_id:int,days_until:int|null,percent:int,items:array,next_actions:array}
	 */
	public static function evaluate( $event_id ) {
		$event_id = absint( $event_id );
		$cached   = get_transient( self::CACHE_KEY . $event_id );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ctx    = self::context( $event_id );
		$marks  = self::marks( $event_id );
		$checks = self::checks( $event_id, $ctx );
		$items  = array();
		foreach ( self::labels() as $key => $label ) {
			list( $result, $evidence, $action ) = $checks[ $key ];
			$mark  = $marks[ $key ] ?? null;
			$state = 'incomplete';
			if ( $mark && 'not_applicable' === $mark['state'] && in_array( $key, self::NA_ALLOWED, true ) ) {
				$state = 'not_applicable';
			} elseif ( 'pass' === $result ) {
				$state = 'verified';
			} elseif ( 'na' === $result ) {
				$state = 'not_applicable';
			} elseif ( 'unknown' === $result && $mark && 'confirmed' === $mark['state'] ) {
				$state = 'confirmed';
			}
			$items[] = array(
				'key'            => $key,
				'label'          => $label,
				'state'          => $state,
				'system'         => $result,
				'evidence'       => $evidence,
				'action_url'     => $action,
				'manual_allowed' => in_array( $key, self::MANUAL_CONFIRM, true ) && 'unknown' === $result,
				'na_allowed'     => in_array( $key, self::NA_ALLOWED, true ),
				'mark'           => $mark ? array( 'state' => $mark['state'], 'by' => self::user_name( (int) $mark['by'] ), 'at' => (string) $mark['at'], 'note' => (string) ( $mark['note'] ?? '' ) ) : null,
			);
		}
		$counted = array_filter(
			$items,
			static function ( $item ) {
				return 'not_applicable' !== $item['state'];
			}
		);
		$done    = array_filter(
			$counted,
			static function ( $item ) {
				return in_array( $item['state'], array( 'verified', 'confirmed' ), true );
			}
		);
		$next    = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'incomplete' === $item['state'];
				}
			)
		);
		$result  = array(
			'event_id'     => $event_id,
			'event_title'  => get_the_title( $event_id ),
			'event_date'   => $ctx['date'],
			'days_until'   => $ctx['days_until'],
			'percent'      => $counted ? (int) round( count( $done ) / count( $counted ) * 100 ) : 100,
			'items'        => $items,
			'next_actions' => array_map(
				static function ( $item ) {
					return array( 'key' => $item['key'], 'label' => $item['label'], 'evidence' => $item['evidence'], 'action_url' => $item['action_url'] );
				},
				array_slice( $next, 0, 3 )
			),
		);
		set_transient( self::CACHE_KEY . $event_id, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Record a manual mark. "confirmed" is only accepted where the system cannot decide;
	 * "not_applicable" only for items that may not apply; "clear" removes a mark.
	 *
	 * @param int    $event_id Event.
	 * @param string $key      Item key.
	 * @param string $state    confirmed|not_applicable|clear.
	 * @param string $note     Optional note.
	 * @param int    $actor    User (edit_ops).
	 * @return array|WP_Error Updated readiness.
	 */
	public static function mark( $event_id, $key, $state, $note, $actor ) {
		$event_id = absint( $event_id );
		$key      = sanitize_key( (string) $key );
		$state    = sanitize_key( (string) $state );
		if ( ! isset( self::labels()[ $key ] ) ) {
			return new WP_Error( 'lnkup_readiness_item', __( 'Unknown checklist item.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_ops', $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot update this checklist.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$current = self::evaluate( $event_id );
		$item    = null;
		foreach ( $current['items'] as $row ) {
			if ( $row['key'] === $key ) {
				$item = $row;
			}
		}
		if ( 'confirmed' === $state && ! $item['manual_allowed'] ) {
			return new WP_Error(
				'lnkup_readiness_system',
				'pass' === $item['system']
					? __( 'LNKup already verified this item.', 'lnkup-marketplace' )
					: __( 'LNKup checks this item from your event records, so it cannot be confirmed by hand. Fix the items listed instead.', 'lnkup-marketplace' ),
				array( 'status' => 409 )
			);
		}
		if ( 'not_applicable' === $state && ! $item['na_allowed'] ) {
			return new WP_Error( 'lnkup_readiness_na', __( 'This item applies to every event.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $state, array( 'confirmed', 'not_applicable', 'clear' ), true ) ) {
			return new WP_Error( 'lnkup_readiness_state', __( 'Choose confirmed, not applicable or clear.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$marks = self::marks( $event_id );
		if ( 'clear' === $state ) {
			unset( $marks[ $key ] );
		} else {
			$marks[ $key ] = array( 'state' => $state, 'by' => absint( $actor ), 'at' => current_time( 'mysql' ), 'note' => mb_substr( sanitize_text_field( (string) $note ), 0, 300 ) );
		}
		update_post_meta( $event_id, self::MARKS_META, $marks );
		LNKup_Application_Review::audit( 'event_readiness_marked', $event_id, $actor, array( 'event_id' => $event_id, 'item' => $key, 'state' => $state ) );
		self::flush( $event_id );
		return self::evaluate( $event_id );
	}

	public static function flush( $event_id ) {
		delete_transient( self::CACHE_KEY . absint( $event_id ) );
	}

	/**
	 * Hooks pass an application, booth, task-event or document ID first; resolve the event when possible.
	 *
	 * @param mixed $id First hook argument.
	 */
	public static function flush_from_hook( $id ) {
		$id   = absint( $id );
		$type = $id ? get_post_type( $id ) : '';
		if ( 'lnkup_event' === $type ) {
			self::flush( $id );
		} elseif ( 'lnkup_application' === $type ) {
			self::flush( (int) get_post_meta( $id, '_lnkup_event_id', true ) );
		}
	}

	/**
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Post.
	 * @param string $meta_key  Key.
	 * @param mixed  $value     Value.
	 */
	public static function flush_from_meta( $meta_id, $object_id, $meta_key, $value ) {
		unset( $meta_id, $value );
		if ( in_array( $meta_key, array( '_lnkup_booth_placeholder', '_lnkup_qr_pass', '_lnkup_event_date', '_lnkup_venue_name' ), true ) ) {
			self::flush_from_hook( $object_id );
			if ( '_lnkup_qr_pass' === $meta_key ) {
				self::flush_from_hook( (int) get_post_meta( $object_id, '_lnkup_application_id', true ) );
			}
		}
	}

	/**
	 * @param int $event_id Event.
	 */
	private static function marks( $event_id ) {
		$marks = get_post_meta( $event_id, self::MARKS_META, true );
		return is_array( $marks ) ? $marks : array();
	}

	private static function user_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? $user->display_name : '';
	}

	/**
	 * Load what every check needs in a few queries.
	 *
	 * @param int $event_id Event.
	 */
	private static function context( $event_id ) {
		$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$days = null;
		if ( $date ) {
			$days = (int) floor( ( strtotime( $date ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => (string) $event_id,
			)
		);
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'pending', 'private', 'draft', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => (string) $event_id,
			)
		);
		if ( $apps ) {
			update_meta_cache( 'post', $apps );
		}
		$by_status = array();
		foreach ( $apps as $app_id ) {
			$by_status[ (string) get_post_meta( $app_id, '_lnkup_application_status', true ) ][] = (int) $app_id;
		}
		return array( 'date' => $date, 'days_until' => $days, 'opps' => array_map( 'intval', $opps ), 'apps' => $by_status );
	}

	/**
	 * @param array $ctx      Context.
	 * @param array $statuses Statuses.
	 * @return int[]
	 */
	private static function apps_in( array $ctx, array $statuses ) {
		$out = array();
		foreach ( $statuses as $status ) {
			$out = array_merge( $out, $ctx['apps'][ $status ] ?? array() );
		}
		return $out;
	}

	/**
	 * @param int   $event_id Event.
	 * @param array $ctx      Context.
	 * @return array<string, array{0:string,1:string,2:string}> key => [result, evidence, action_url]
	 */
	private static function checks( $event_id, array $ctx ) {
		$dash      = lnkup_page_url_helper( 'organizer-dashboard' );
		$edit      = add_query_arg( array( 'event' => $event_id, 'edit' => 1 ), $dash );
		$ops       = add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash );
		$paid      = self::apps_in( $ctx, array( 'paid', 'completed' ) );
		$out       = array();

		$missing = array();
		if ( ! $ctx['date'] ) {
			$missing[] = __( 'date', 'lnkup-marketplace' );
		}
		if ( '' === (string) get_post_meta( $event_id, '_lnkup_venue_name', true ) ) {
			$missing[] = __( 'venue', 'lnkup-marketplace' );
		}
		if ( '' === (string) get_post_meta( $event_id, '_lnkup_city', true ) || '' === (string) get_post_meta( $event_id, '_lnkup_state', true ) ) {
			$missing[] = __( 'city and state', 'lnkup-marketplace' );
		}
		/* translators: %s: missing fields */
		$out['event_details'] = $missing ? array( 'fail', sprintf( __( 'Missing: %s.', 'lnkup-marketplace' ), implode( ', ', $missing ) ), $edit ) : array( 'pass', __( 'Date, venue and location are set.', 'lnkup-marketplace' ), $edit );

		$ready_opps = 0;
		foreach ( $ctx['opps'] as $opp_id ) {
			$c = class_exists( 'LNKup_Organizer_Activation' ) ? LNKup_Organizer_Activation::opportunity_completeness( $opp_id ) : array( 'categories' => true, 'price' => true, 'deadline' => true );
			if ( ! empty( $c['categories'] ) && ! empty( $c['price'] ) && ! empty( $c['deadline'] ) ) {
				++$ready_opps;
			}
		}
		if ( ! $ctx['opps'] ) {
			$out['application_settings'] = array( 'fail', __( 'Add a vendor slot so vendors can apply.', 'lnkup-marketplace' ), $edit );
		} elseif ( $ready_opps < count( $ctx['opps'] ) ) {
			/* translators: 1: ready slots, 2: total slots */
			$out['application_settings'] = array( 'fail', sprintf( __( '%1$d of %2$d vendor slots have categories, a price and a deadline.', 'lnkup-marketplace' ), $ready_opps, count( $ctx['opps'] ) ), $edit );
		} else {
			$out['application_settings'] = array( 'pass', __( 'Every vendor slot has categories, a price and a deadline.', 'lnkup-marketplace' ), $edit );
		}

		$capacity = 0;
		foreach ( $ctx['opps'] as $opp_id ) {
			$capacity += LNKup_Opportunity_Inventory::capacity( $opp_id );
		}
		$booths = count( (array) LNKup_Booths::get_for_event( $event_id ) );
		/* translators: 1: spots, 2: booths */
		$out['booth_inventory'] = $capacity > 0
			? array( 'pass', sprintf( __( '%1$d vendor spots, %2$d booths on the map.', 'lnkup-marketplace' ), $capacity, $booths ), $edit )
			: array( 'fail', __( 'Set how many vendor spots each slot has.', 'lnkup-marketplace' ), $edit );

		$pending = count( self::apps_in( $ctx, array( 'submitted', 'under_review', 'info_requested' ) ) );
		$total   = array_sum( array_map( 'count', $ctx['apps'] ) );
		$review  = add_query_arg( array( 'view' => 'applications', 'group' => 'pending', 'event_id' => $event_id ), $dash );
		if ( ! $total ) {
			$out['applications_reviewed'] = array( 'fail', __( 'No applications yet.', 'lnkup-marketplace' ), $review );
		} elseif ( $pending ) {
			/* translators: %d: applications */
			$out['applications_reviewed'] = array( 'fail', sprintf( _n( '%d application is waiting for a decision.', '%d applications are waiting for a decision.', $pending, 'lnkup-marketplace' ), $pending ), $review );
		} else {
			$out['applications_reviewed'] = array( 'pass', __( 'Every application has a decision.', 'lnkup-marketplace' ), $review );
		}

		$unpaid  = self::apps_in( $ctx, array( 'approved', 'payment_required' ) );
		$overdue = 0;
		foreach ( $unpaid as $app_id ) {
			$due = (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true );
			if ( $due && strtotime( $due . ' UTC' ) < time() ) {
				++$overdue;
			}
		}
		$money = add_query_arg( array( 'view' => 'financial', 'fin_tab' => 'vendor-revenue' ), $dash );
		if ( $unpaid ) {
			/* translators: 1: unpaid vendors, 2: overdue */
			$out['payments_reconciled'] = array( 'fail', sprintf( __( '%1$d approved vendors have not paid (%2$d past due).', 'lnkup-marketplace' ), count( $unpaid ), $overdue ), $money );
		} elseif ( ! $paid ) {
			$out['payments_reconciled'] = array( 'fail', __( 'No confirmed vendors yet.', 'lnkup-marketplace' ), $money );
		} else {
			$out['payments_reconciled'] = array( 'pass', __( 'Every approved vendor has paid.', 'lnkup-marketplace' ), $money );
		}

		$unassigned = array_filter(
			$paid,
			static function ( $app_id ) {
				return '' === (string) get_post_meta( $app_id, '_lnkup_booth_placeholder', true );
			}
		);
		if ( ! $paid ) {
			$out['booth_assignments'] = array( 'fail', __( 'Assign booths once vendors confirm.', 'lnkup-marketplace' ), $ops );
		} elseif ( $unassigned ) {
			/* translators: %d: vendors */
			$out['booth_assignments'] = array( 'fail', sprintf( _n( '%d confirmed vendor has no booth.', '%d confirmed vendors have no booth.', count( $unassigned ), 'lnkup-marketplace' ), count( $unassigned ) ), $ops );
		} else {
			$out['booth_assignments'] = array( 'pass', __( 'Every confirmed vendor has a booth.', 'lnkup-marketplace' ), $ops );
		}

		$out['documents_collected'] = self::documents_check( $event_id, $ctx, $paid, add_query_arg( array( 'event' => $event_id, 'tab' => 'documents' ), $dash ) );

		$pass_info = class_exists( 'LNKup_Packets' ) ? (array) LNKup_Packets::get_pass_completeness( $event_id ) : array();
		$complete  = (int) ( $pass_info['complete'] ?? 0 );
		$pass_tot  = (int) ( $pass_info['total'] ?? 0 );
		/* translators: 1: complete, 2: total */
		$out['packet_prepared'] = $pass_tot && $complete >= $pass_tot
			? array( 'pass', __( 'Vendor packet details are complete.', 'lnkup-marketplace' ), $edit )
			: array( 'fail', sprintf( __( '%1$d of %2$d vendor packet sections filled in.', 'lnkup-marketplace' ), $complete, $pass_tot ), $edit );

		global $wpdb;
		$broadcasts = LNKup_Operations_Tables::broadcasts_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$broadcasts} WHERE event_id = %d", $event_id ) );
		$out['setup_instructions_sent'] = $sent
			/* translators: %d: messages */
			? array( 'pass', sprintf( _n( '%d message sent to vendors.', '%d messages sent to vendors.', $sent, 'lnkup-marketplace' ), $sent ), $ops )
			: array( 'unknown', __( 'No vendor messages sent from LNKup yet. Confirm if you sent instructions another way.', 'lnkup-marketplace' ), $ops );

		$tokens = 0;
		if ( $paid && LNKup_Organizer_OS_Tables::ready() ) {
			$table        = LNKup_Organizer_OS_Tables::pass_tokens_table();
			$placeholders = implode( ',', array_fill( 0, count( $paid ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$tokens = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT application_id) FROM {$table} WHERE application_id IN ({$placeholders})", $paid ) );
		}
		$checkers = self::team_count( $event_id, 'check_in' );
		$scan     = add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash );
		if ( ! $paid ) {
			$out['checkin_ready'] = array( 'fail', __( 'Event passes are issued when vendors confirm.', 'lnkup-marketplace' ), $scan );
		} elseif ( $tokens < count( $paid ) ) {
			/* translators: 1: passes, 2: vendors */
			$out['checkin_ready'] = array( 'fail', sprintf( __( '%1$d of %2$d confirmed vendors have an event pass.', 'lnkup-marketplace' ), $tokens, count( $paid ) ), $scan );
		} elseif ( $checkers ) {
			/* translators: %d: people */
			$out['checkin_ready'] = array( 'pass', sprintf( _n( 'Every vendor has a pass; %d team member can check vendors in.', 'Every vendor has a pass; %d team members can check vendors in.', $checkers, 'lnkup-marketplace' ), $checkers ), $scan );
		} else {
			$out['checkin_ready'] = array( 'unknown', __( 'Every vendor has a pass. Only you can check vendors in; confirm if that is the plan.', 'lnkup-marketplace' ), $scan );
		}

		$team = self::team_count( $event_id, 'view_roster' );
		/* translators: %d: people */
		$out['team_assigned'] = $team
			? array( 'pass', sprintf( _n( '%d team member has access to this event.', '%d team members have access to this event.', $team, 'lnkup-marketplace' ), $team ), add_query_arg( 'view', 'team', $dash ) )
			: array( 'unknown', __( 'No one else has access to this event. Confirm if you are running it yourself.', 'lnkup-marketplace' ), add_query_arg( 'view', 'team', $dash ) );

		return $out;
	}

	/**
	 * @param int    $event_id Event.
	 * @param array  $ctx      Context.
	 * @param int[]  $paid     Confirmed applications.
	 * @param string $url      Documents tab.
	 */
	private static function documents_check( $event_id, array $ctx, array $paid, $url ) {
		$required = false;
		foreach ( $ctx['opps'] as $opp_id ) {
			if ( lnkup_get_opportunity_required_documents( $opp_id ) ) {
				$required = true;
			}
		}
		if ( ! $required ) {
			return array( 'na', __( 'No documents are required for this event.', 'lnkup-marketplace' ), $url );
		}
		if ( ! $paid ) {
			return array( 'fail', __( 'Documents are collected from confirmed vendors.', 'lnkup-marketplace' ), $url );
		}
		$outstanding = 0;
		if ( LNKup_Feature_Flags::document_review() && class_exists( 'LNKup_Organizer_Documents' ) ) {
			foreach ( LNKup_Organizer_Documents::requirements_for_event( $event_id, array( 'application_ids' => $paid ) ) as $row ) {
				if ( ! in_array( $row['status'], array( 'accepted', 'waived' ), true ) ) {
					++$outstanding;
				}
			}
			/* translators: %d: documents */
			$message = _n( '%d required document is not accepted yet.', '%d required documents are not accepted yet.', $outstanding, 'lnkup-marketplace' );
		} else {
			foreach ( $paid as $app_id ) {
				$code = (string) ( LNKup_Verification::get_application_document_context( $app_id )['code'] ?? '' );
				if ( in_array( $code, array( 'pending', 'missing' ), true ) ) {
					++$outstanding;
				}
			}
			/* translators: %d: vendors */
			$message = _n( '%d confirmed vendor still has documents outstanding.', '%d confirmed vendors still have documents outstanding.', $outstanding, 'lnkup-marketplace' );
		}
		return $outstanding
			? array( 'fail', sprintf( $message, $outstanding ), $url )
			: array( 'pass', __( 'Every confirmed vendor has the required documents.', 'lnkup-marketplace' ), $url );
	}

	/**
	 * People other than the owner who hold a capability on the event (team grants + event staff).
	 *
	 * @param int    $event_id   Event.
	 * @param string $capability Capability.
	 */
	private static function team_count( $event_id, $capability ) {
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$count = 0;
		foreach ( LNKup_Event_Tasks::assignee_candidates( $event_id ) as $person ) {
			if ( (int) $person['id'] !== $owner && LNKup_Capabilities::staff_can( $event_id, $capability, (int) $person['id'] ) ) {
				++$count;
			}
		}
		return $count;
	}
}
