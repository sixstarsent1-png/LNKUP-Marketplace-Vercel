<?php
/**
 * Organizer application review: cross-event queue, review workspace, reviewer
 * assignment, rubric scoring and previewed bulk decisions.
 *
 * Status changes always go through LNKup_Applications (the canonical state machine).
 * Scores and recommendations are advisory and never change an application on their own.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Application_Review {

	const MAX_PER_PAGE      = 50;
	const BULK_SYNC_LIMIT   = 50;
	const BULK_MAX          = 200;
	const BULK_PREVIEW_TTL  = 600;
	const BULK_JOB_CRON     = 'lnkup_application_bulk_job';
	const BULK_JOB_BATCH    = 25;
	const MAX_CRITERIA      = 10;
	const SCORE_MIN         = 1;
	const SCORE_MAX         = 5;
	const RECOMMENDATIONS   = array( 'approve', 'decline', 'waitlist', 'abstain' );
	const BULK_ACTIONS      = array( 'approve', 'reject', 'waitlist', 'review' );
	const OPEN_FOR_SCORING  = array( 'submitted', 'under_review', 'info_requested', 'waitlisted' );

	/**
	 * UI groups over canonical statuses. Payment state is reported separately.
	 *
	 * @return array<string, array{label:string, statuses:string[]}>
	 */
	public static function status_groups() {
		return array(
			'pending'    => array( 'label' => __( 'Needs review', 'lnkup-marketplace' ), 'statuses' => array( 'submitted', 'under_review' ) ),
			'info'       => array( 'label' => __( 'Info requested', 'lnkup-marketplace' ), 'statuses' => array( 'info_requested' ) ),
			'booking'    => array( 'label' => __( 'Approved, awaiting payment', 'lnkup-marketplace' ), 'statuses' => array( 'approved', 'payment_required' ) ),
			'confirmed'  => array( 'label' => __( 'Confirmed', 'lnkup-marketplace' ), 'statuses' => array( 'paid', 'completed' ) ),
			'waitlisted' => array( 'label' => __( 'Waitlisted', 'lnkup-marketplace' ), 'statuses' => array( 'waitlisted' ) ),
			'declined'   => array( 'label' => __( 'Declined', 'lnkup-marketplace' ), 'statuses' => array( 'rejected' ) ),
			'closed'     => array( 'label' => __( 'Withdrawn or cancelled', 'lnkup-marketplace' ), 'statuses' => array( 'cancelled' ) ),
		);
	}

	/**
	 * @param string $status Canonical application status.
	 */
	public static function status_group( $status ) {
		foreach ( self::status_groups() as $key => $group ) {
			if ( in_array( (string) $status, $group['statuses'], true ) ) {
				return $key;
			}
		}
		return 'pending';
	}

	/**
	 * Payment state, independent of the review decision.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $status Canonical status.
	 */
	public static function payment_state( $app_id, $status ) {
		if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
			return 'paid';
		}
		if ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
			$payment = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app_id ) : null;
			$pay     = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
			return 'processing' === $pay ? 'processing' : 'due';
		}
		return 'not_due';
	}

	public static function init() {
		add_action( self::BULK_JOB_CRON, array( __CLASS__, 'run_bulk_job' ), 10, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Access
	 * ------------------------------------------------------------------- */

	/**
	 * Events the user may review in; null means every event (platform admins).
	 *
	 * @param int    $user_id    User.
	 * @param string $capability Capability.
	 * @return int[]|null
	 */
	public static function scoped_event_ids( $user_id, $capability = 'review_applications' ) {
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return null;
		}
		$ids = class_exists( 'LNKup_Org_Teams' ) ? LNKup_Org_Teams::event_ids_for( $user_id, $capability ) : array();
		return array_values(
			array_filter(
				$ids,
				static function ( $event_id ) use ( $user_id, $capability ) {
					return LNKup_Capabilities::staff_can( (int) $event_id, $capability, $user_id );
				}
			)
		);
	}

	/**
	 * Server-computed actions this user may take on an application right now.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id User.
	 * @return string[]
	 */
	public static function allowed_actions( $app_id, $user_id ) {
		$status  = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$manage  = LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $user_id );
		$score   = LNKup_Capabilities::can_on_application( $app_id, 'score_applications', $user_id );
		$actions = array();
		if ( $manage ) {
			foreach ( array( 'approve' => 'approved', 'reject' => 'rejected', 'waitlist' => 'waitlisted', 'request_info' => 'info_requested' ) as $action => $target ) {
				if ( LNKup_Applications::can_transition( $status, $target ) ) {
					$actions[] = $action;
				}
			}
			if ( 'submitted' === $status ) {
				$actions[] = 'review';
			}
			$actions[] = 'assign_reviewer';
			if ( 'waitlisted' === $status && LNKup_Feature_Flags::waitlist_offers() ) {
				$actions[] = 'offer_spot';
			}
			if ( 'payment_required' === $status && LNKup_Feature_Flags::waitlist_offers() && LNKup_Waitlist_Offers::active_offer( $app_id ) ) {
				$actions[] = 'revoke_offer';
			}
		}
		if ( $score && in_array( $status, self::OPEN_FOR_SCORING, true ) ) {
			$actions[] = 'score';
		}
		return $actions;
	}

	/* ---------------------------------------------------------------------
	 * Queue
	 * ------------------------------------------------------------------- */

	/**
	 * Paginated review queue across every event the user may review.
	 *
	 * @param int   $user_id User.
	 * @param array $args    group, status, event_id, tour_id, opportunity_id, assigned (me|user id),
	 *                       category, missing_docs, search, page, per_page, sort.
	 * @return array|WP_Error
	 */
	public static function queue( $user_id, array $args ) {
		$scope = self::scoped_event_ids( $user_id );
		$page  = max( 1, absint( $args['page'] ?? 1 ) );
		$per   = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ?? 25 ) ) );
		$empty = array(
			'items'  => array(),
			'total'  => 0,
			'page'   => $page,
			'pages'  => 0,
			'counts' => array_fill_keys( array_keys( self::status_groups() ), 0 ),
			'groups' => self::group_labels(),
			'events' => array(),
		);
		if ( is_array( $scope ) && ! $scope ) {
			return $empty;
		}

		$events = $scope;
		$event  = absint( $args['event_id'] ?? 0 );
		if ( $event ) {
			if ( is_array( $scope ) && ! in_array( $event, $scope, true ) ) {
				return new WP_Error( 'lnkup_forbidden', __( 'You cannot review applications for that event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			$events = array( $event );
		}
		$tour = absint( $args['tour_id'] ?? 0 );
		if ( $tour ) {
			$tour_events = array_map(
				'intval',
				get_posts(
					array(
						'post_type'      => 'lnkup_event',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => '_lnkup_tour_id',
						'meta_value'     => (string) $tour,
					)
				)
			);
			$events = is_array( $events ) ? array_values( array_intersect( $events, $tour_events ) ) : $tour_events;
			if ( ! $events ) {
				return $empty;
			}
		}

		$meta_query = array( 'relation' => 'AND' );
		if ( is_array( $events ) ) {
			$meta_query[] = array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $events ), 'compare' => 'IN' );
		}
		$base_meta = $meta_query;

		$statuses = array();
		$group    = sanitize_key( (string) ( $args['group'] ?? '' ) );
		$groups   = self::status_groups();
		if ( $group && isset( $groups[ $group ] ) ) {
			$statuses = $groups[ $group ]['statuses'];
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( $status ) {
			$statuses = array( $status );
		}
		if ( $statuses ) {
			$meta_query[] = array( 'key' => '_lnkup_application_status', 'value' => $statuses, 'compare' => 'IN' );
		}
		$opp = absint( $args['opportunity_id'] ?? 0 );
		if ( $opp ) {
			$meta_query[] = array( 'key' => '_lnkup_opportunity_id', 'value' => (string) $opp );
		}
		$category = sanitize_text_field( (string) ( $args['category'] ?? '' ) );
		if ( '' !== $category ) {
			$meta_query[] = array( 'key' => '_lnkup_selected_category', 'value' => $category );
		}
		if ( ! empty( $args['missing_docs'] ) ) {
			$meta_query[] = array( 'key' => '_lnkup_readiness_status', 'value' => LNKup_Vendor_Readiness::STATUS_NEEDS_DOCUMENTS );
		}

		$post_in  = null;
		$assigned = (string) ( $args['assigned'] ?? '' );
		if ( '' !== $assigned ) {
			$reviewer = 'me' === $assigned ? (int) $user_id : absint( $assigned );
			$post_in  = self::assigned_application_ids( $reviewer );
			if ( ! $post_in ) {
				return array_merge( $empty, array( 'counts' => self::group_counts( $base_meta ), 'events' => self::event_options( $scope ) ) );
			}
		}

		$query_args = array(
			'post_type'      => 'lnkup_application',
			'post_status'    => array( 'publish', 'pending', 'private', 'draft', 'future' ),
			'posts_per_page' => $per,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'oldest' === ( $args['sort'] ?? '' ) ? 'ASC' : 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( null !== $post_in ) {
			$query_args['post__in'] = $post_in;
		}
		$search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}
		$query = new WP_Query( $query_args );
		$ids   = wp_list_pluck( $query->posts, 'ID' );

		$reviews   = self::reviews_for( $ids );
		$assignees = self::assignees_for( $ids );
		$returning = self::returning_vendor_map( $query->posts );
		$items     = array();
		foreach ( $query->posts as $post ) {
			$items[] = self::format_row( $post, (int) $user_id, $reviews[ $post->ID ] ?? array(), $assignees[ $post->ID ] ?? array(), ! empty( $returning[ $post->ID ] ) );
		}

		return array(
			'items'  => $items,
			'total'  => (int) $query->found_posts,
			'page'   => $page,
			'pages'  => (int) $query->max_num_pages,
			'counts' => self::group_counts( $base_meta ),
			'groups' => self::group_labels(),
			'events' => self::event_options( $scope ),
		);
	}

	private static function group_labels() {
		$out = array();
		foreach ( self::status_groups() as $key => $group ) {
			$out[ $key ] = $group['label'];
		}
		return $out;
	}

	/**
	 * Count per status group in one grouped query.
	 *
	 * @param array $base_meta Event scope meta query.
	 */
	private static function group_counts( array $base_meta ) {
		global $wpdb;
		$counts = array_fill_keys( array_keys( self::status_groups() ), 0 );
		$where  = '';
		$params = array();
		foreach ( $base_meta as $clause ) {
			if ( is_array( $clause ) && '_lnkup_event_id' === ( $clause['key'] ?? '' ) ) {
				$values = array_map( 'absint', (array) $clause['value'] );
				if ( ! $values ) {
					return $counts;
				}
				$where  = ' AND e.meta_value IN (' . implode( ',', array_fill( 0, count( $values ), '%s' ) ) . ')';
				$params = array_map( 'strval', $values );
			}
		}
		$sql = "SELECT s.meta_value AS status, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id'
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft'){$where}
			GROUP BY s.meta_value";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$counts[ self::status_group( (string) $row['status'] ) ] += (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * @param int[]|null $scope Events in scope (null = all).
	 */
	private static function event_options( $scope ) {
		if ( null === $scope ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $scope, 0, 200 ) as $event_id ) {
			$out[] = array(
				'id'    => (int) $event_id,
				'title' => get_the_title( $event_id ),
				'date'  => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( (string) $b['date'], (string) $a['date'] );
			}
		);
		return $out;
	}

	/**
	 * @param WP_Post $post       Application.
	 * @param int     $user_id    Viewer.
	 * @param array   $reviews    Review rows for this application.
	 * @param int[]   $assignees  Assigned reviewer IDs.
	 * @param bool    $returning  Vendor was booked at this organizer before.
	 */
	private static function format_row( $post, $user_id, array $reviews, array $assignees, $returning ) {
		$app_id     = (int) $post->ID;
		$status     = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$opp_id     = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$vendor_id  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$name       = $profile_id ? ( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id ) ) : '';
		if ( '' === $name && $vendor_id ) {
			$user = get_userdata( $vendor_id );
			$name = $user ? $user->display_name : '';
		}
		$summary = self::score_summary( $reviews, $user_id, $app_id );
		return array(
			'id'              => $app_id,
			'event_id'        => $event_id,
			'event_title'     => $event_id ? get_the_title( $event_id ) : '',
			'event_date'      => $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
			'opportunity_id'  => $opp_id,
			'opportunity'     => $opp_id ? get_the_title( $opp_id ) : '',
			'vendor_name'     => $name,
			'profile_id'      => $profile_id,
			'category'        => (string) get_post_meta( $app_id, '_lnkup_selected_category', true ),
			'status'          => $status,
			'status_label'    => lnkup_application_status_label( $status ),
			'group'           => self::status_group( $status ),
			'payment_state'   => self::payment_state( $app_id, $status ),
			'readiness'       => (string) get_post_meta( $app_id, '_lnkup_readiness_status', true ),
			'submitted_at'    => get_post_time( 'Y-m-d H:i:s', true, $post ),
			'returning'       => (bool) $returning,
			'reviewers'       => array_map( array( __CLASS__, 'user_label' ), $assignees ),
			'assigned_to_me'  => in_array( (int) $user_id, $assignees, true ),
			'review'          => $summary,
			'waitlist_position' => 'waitlisted' === $status ? (int) get_post_meta( $app_id, LNKup_Waitlist_Offers::POSITION_META, true ) : 0,
			'actions'         => self::allowed_actions( $app_id, $user_id ),
		);
	}

	/**
	 * @param int $user_id User.
	 */
	public static function user_label( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return array( 'id' => (int) $user_id, 'name' => $user ? $user->display_name : __( 'Former team member', 'lnkup-marketplace' ) );
	}

	/**
	 * @param int $reviewer_id Reviewer.
	 * @return int[]
	 */
	private static function assigned_application_ids( $reviewer_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::review_assignments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT application_id FROM {$table} WHERE reviewer_user_id = %d", absint( $reviewer_id ) ) ) );
	}

	/**
	 * @param int[] $app_ids Applications.
	 * @return array<int, array<int, array>>
	 */
	private static function reviews_for( array $app_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $app_ids ) {
			return $out;
		}
		$table        = LNKup_Organizer_OS_Tables::reviews_table();
		$placeholders = implode( ',', array_fill( 0, count( $app_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id IN ({$placeholders})", array_map( 'absint', $app_ids ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['application_id'] ][] = $row;
		}
		return $out;
	}

	/**
	 * @param int[] $app_ids Applications.
	 * @return array<int, int[]>
	 */
	private static function assignees_for( array $app_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $app_ids ) {
			return $out;
		}
		$table        = LNKup_Organizer_OS_Tables::review_assignments_table();
		$placeholders = implode( ',', array_fill( 0, count( $app_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT application_id, reviewer_user_id FROM {$table} WHERE application_id IN ({$placeholders}) ORDER BY id ASC", array_map( 'absint', $app_ids ) ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['application_id'] ][] = (int) $row['reviewer_user_id'];
		}
		return $out;
	}

	/**
	 * Applications whose vendor has a paid or completed booking at another event by the same organizer.
	 *
	 * @param WP_Post[] $posts Applications on the page.
	 * @return array<int, bool>
	 */
	private static function returning_vendor_map( array $posts ) {
		global $wpdb;
		$out = array();
		foreach ( $posts as $post ) {
			$vendor    = (int) get_post_meta( $post->ID, '_lnkup_vendor_user_id', true );
			$organizer = (int) get_post_meta( $post->ID, '_lnkup_organizer_user_id', true );
			$event     = (int) get_post_meta( $post->ID, '_lnkup_event_id', true );
			if ( ! $vendor || ! $organizer ) {
				continue;
			}
			$key = $vendor . ':' . $organizer . ':' . $event;
			if ( ! isset( $out[ $key ] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$out[ $key ] = (bool) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT 1 FROM {$wpdb->posts} p
						INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = %s
						INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = %s
						INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status' AND s.meta_value IN ('paid','completed')
						INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value <> %s
						WHERE p.post_type = 'lnkup_application' LIMIT 1",
						(string) $vendor,
						(string) $organizer,
						(string) $event
					)
				);
			}
			$out[ $post->ID ] = $out[ $key ];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Workspace
	 * ------------------------------------------------------------------- */

	/**
	 * Everything needed to decide on one application, in one payload.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id Viewer (must hold review_applications on the event).
	 * @return array|WP_Error
	 */
	public static function workspace( $app_id, $user_id ) {
		$app_id = absint( $app_id );
		$post   = get_post( $app_id );
		if ( ! $post || 'lnkup_application' !== $post->post_type ) {
			return new WP_Error( 'lnkup_not_found', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'review_applications', $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot review this application.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$reviews   = self::reviews_for( array( $app_id ) );
		$assignees = self::assignees_for( array( $app_id ) );
		$returning = self::returning_vendor_map( array( $post ) );
		$row       = self::format_row( $post, (int) $user_id, $reviews[ $app_id ] ?? array(), $assignees[ $app_id ] ?? array(), ! empty( $returning[ $app_id ] ) );
		$event_id  = (int) $row['event_id'];
		$organizer = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$vendor_id = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$can_docs  = LNKup_Capabilities::can_on_application( $app_id, 'view_documents', $user_id );

		$history = array();
		foreach ( LNKup_Applications::status_history( $app_id ) as $entry ) {
			$history[] = array(
				'from'   => (string) ( $entry['from'] ?? '' ),
				'to'     => (string) ( $entry['to'] ?? '' ),
				'label'  => lnkup_application_status_label( (string) ( $entry['to'] ?? '' ) ),
				'at'     => (string) ( $entry['at'] ?? '' ),
				'by'     => ! empty( $entry['by'] ) ? self::user_label( (int) $entry['by'] )['name'] : '',
				'reason' => (string) ( $entry['reason'] ?? '' ),
			);
		}

		$past = array();
		if ( $vendor_id && $organizer ) {
			$prior = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => 'any',
					'posts_per_page' => 20,
					'post__not_in'   => array( $app_id ),
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $vendor_id ),
						array( 'key' => '_lnkup_organizer_user_id', 'value' => (string) $organizer ),
					),
				)
			);
			foreach ( $prior as $p ) {
				$prior_event = (int) get_post_meta( $p->ID, '_lnkup_event_id', true );
				$prior_state = (string) get_post_meta( $p->ID, '_lnkup_application_status', true );
				$past[]      = array(
					'application_id' => (int) $p->ID,
					'event_title'    => $prior_event ? get_the_title( $prior_event ) : '',
					'event_date'     => $prior_event ? (string) get_post_meta( $prior_event, '_lnkup_event_date', true ) : '',
					'status_label'   => lnkup_application_status_label( $prior_state ),
				);
			}
		}

		$rubric = self::rubric_for_event( $event_id );
		$mine   = null;
		foreach ( $reviews[ $app_id ] ?? array() as $review ) {
			if ( (int) $review['reviewer_user_id'] === (int) $user_id ) {
				$mine = self::format_review( $review, true );
			}
		}

		return array_merge(
			$row,
			array(
				'message'          => (string) $post->post_content,
				'answers'          => class_exists( 'LNKup_Application_Questions' ) ? LNKup_Application_Questions::answers_for_application( $app_id ) : array(),
				'profile'          => LNKup_Applications::get_profile_snapshot( $app_id ),
				'documents'        => $can_docs ? LNKup_Applications::get_documents_snapshot( $app_id ) : array(),
				'documents_hidden' => ! $can_docs,
				'info_history'     => LNKup_Applications::info_history( $app_id ),
				'status_history'   => $history,
				'past_with_you'    => $past,
				'rubric'           => $rubric,
				'my_review'        => $mine,
				'reviews'          => self::visible_reviews( $reviews[ $app_id ] ?? array(), (int) $user_id, $app_id ),
				'waitlist_offer'   => LNKup_Feature_Flags::waitlist_offers() ? LNKup_Waitlist_Offers::active_offer( $app_id ) : null,
				'document_review'  => $can_docs && LNKup_Feature_Flags::document_review() && class_exists( 'LNKup_Organizer_Documents' )
					? array(
						'items'      => LNKup_Organizer_Documents::requirements_for_application( $app_id, (int) $user_id ),
						'can_review' => LNKup_Capabilities::can_on_application( $app_id, 'review_documents', $user_id ),
					)
					: null,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Rubrics and scoring
	 * ------------------------------------------------------------------- */

	public static function default_criteria() {
		return array(
			array( 'key' => 'category_fit', 'label' => __( 'Category fit', 'lnkup-marketplace' ), 'weight' => 1 ),
			array( 'key' => 'product_quality', 'label' => __( 'Product quality', 'lnkup-marketplace' ), 'weight' => 1 ),
			array( 'key' => 'event_fit', 'label' => __( 'Event fit', 'lnkup-marketplace' ), 'weight' => 1 ),
			array( 'key' => 'booth_readiness', 'label' => __( 'Booth readiness', 'lnkup-marketplace' ), 'weight' => 1 ),
			array( 'key' => 'completeness', 'label' => __( 'Application completeness', 'lnkup-marketplace' ), 'weight' => 1 ),
		);
	}

	/**
	 * Event rubric, or the default (id 0) when the organizer has not customized one.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function rubric_for_event( $event_id ) {
		global $wpdb;
		$table = LNKup_Organizer_OS_Tables::rubrics_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope_type = 'event' AND scope_id = %d", absint( $event_id ) ), ARRAY_A );
		if ( ! $row ) {
			return array( 'id' => 0, 'version' => 0, 'criteria' => self::default_criteria(), 'scale' => array( self::SCORE_MIN, self::SCORE_MAX ), 'is_default' => true );
		}
		$criteria = json_decode( (string) $row['criteria'], true );
		return array(
			'id'         => (int) $row['id'],
			'version'    => (int) $row['version'],
			'criteria'   => is_array( $criteria ) && $criteria ? $criteria : self::default_criteria(),
			'scale'      => array( self::SCORE_MIN, self::SCORE_MAX ),
			'is_default' => false,
		);
	}

	/**
	 * @param array $raw Criteria from the request.
	 * @return array|WP_Error
	 */
	public static function sanitize_criteria( $raw ) {
		if ( ! is_array( $raw ) || ! $raw ) {
			return new WP_Error( 'lnkup_rubric_empty', __( 'Add at least one review criterion.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( count( $raw ) > self::MAX_CRITERIA ) {
			return new WP_Error( 'lnkup_rubric_too_long', __( 'Use ten criteria or fewer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$out  = array();
		$keys = array();
		foreach ( $raw as $item ) {
			$label = trim( sanitize_text_field( (string) ( is_array( $item ) ? ( $item['label'] ?? '' ) : $item ) ) );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( is_array( $item ) && ! empty( $item['key'] ) ? $item['key'] : $label ) );
			$key = substr( '' !== $key ? $key : 'criterion', 0, 40 );
			$base = $key;
			$i    = 2;
			while ( in_array( $key, $keys, true ) ) {
				$key = $base . '_' . $i++;
			}
			$keys[] = $key;
			$out[]  = array(
				'key'    => $key,
				'label'  => mb_substr( $label, 0, 80 ),
				'weight' => max( 1, min( 10, absint( is_array( $item ) ? ( $item['weight'] ?? 1 ) : 1 ) ) ),
			);
		}
		if ( ! $out ) {
			return new WP_Error( 'lnkup_rubric_empty', __( 'Add at least one review criterion.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Save an event rubric. Existing reviews keep the rubric version they were scored against.
	 *
	 * @param int   $event_id Event.
	 * @param array $raw      Criteria.
	 * @param int   $user_id  Actor.
	 * @return array|WP_Error
	 */
	public static function save_rubric( $event_id, $raw, $user_id ) {
		global $wpdb;
		$criteria = self::sanitize_criteria( $raw );
		if ( is_wp_error( $criteria ) ) {
			return $criteria;
		}
		$table   = LNKup_Organizer_OS_Tables::rubrics_table();
		$current = self::rubric_for_event( $event_id );
		$now     = current_time( 'mysql' );
		if ( $current['id'] ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'criteria' => wp_json_encode( $criteria ), 'version' => (int) $current['version'] + 1, 'updated_by' => absint( $user_id ), 'updated_at' => $now ),
				array( 'id' => (int) $current['id'] )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array( 'scope_type' => 'event', 'scope_id' => absint( $event_id ), 'criteria' => wp_json_encode( $criteria ), 'version' => 1, 'updated_by' => absint( $user_id ), 'created_at' => $now, 'updated_at' => $now )
			);
		}
		self::audit( 'review_rubric_saved', $event_id, $user_id, array( 'event_id' => absint( $event_id ), 'criteria' => wp_list_pluck( $criteria, 'label' ) ) );
		return self::rubric_for_event( $event_id );
	}

	/**
	 * Save the current user's review. Never changes the application status.
	 *
	 * @param int   $app_id  Application ID.
	 * @param int   $user_id Reviewer.
	 * @param array $data    scores {key:1-5}, recommendation, comment, complete.
	 * @return array|WP_Error
	 */
	public static function save_review( $app_id, $user_id, array $data ) {
		global $wpdb;
		$app_id = absint( $app_id );
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'score_applications', $user_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot score this application.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! in_array( $status, self::OPEN_FOR_SCORING, true ) ) {
			return new WP_Error( 'lnkup_review_closed', __( 'This application has already been decided, so it can no longer be scored.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$rubric   = self::rubric_for_event( $event_id );
		$input    = is_array( $data['scores'] ?? null ) ? $data['scores'] : array();
		$scores   = array();
		$sum      = 0;
		$weights  = 0;
		foreach ( $rubric['criteria'] as $criterion ) {
			$key = (string) $criterion['key'];
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || null === $input[ $key ] ) {
				continue;
			}
			$value = (int) $input[ $key ];
			if ( $value < self::SCORE_MIN || $value > self::SCORE_MAX ) {
				return new WP_Error( 'lnkup_score_range', __( 'Scores must be between 1 and 5.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$scores[ $key ] = $value;
			$sum           += $value * (int) $criterion['weight'];
			$weights       += (int) $criterion['weight'];
		}
		$complete = ! empty( $data['complete'] );
		if ( $complete && count( $scores ) < count( $rubric['criteria'] ) ) {
			return new WP_Error( 'lnkup_score_incomplete', __( 'Score every criterion before submitting your review.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$recommendation = sanitize_key( (string) ( $data['recommendation'] ?? '' ) );
		if ( '' !== $recommendation && ! in_array( $recommendation, self::RECOMMENDATIONS, true ) ) {
			return new WP_Error( 'lnkup_bad_recommendation', __( 'Choose approve, decline, waitlist or abstain.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$table = LNKup_Organizer_OS_Tables::reviews_table();
		$now   = current_time( 'mysql' );
		$row   = array(
			'application_id'   => $app_id,
			'event_id'         => $event_id,
			'reviewer_user_id' => absint( $user_id ),
			'rubric_id'        => (int) $rubric['id'],
			'rubric_version'   => (int) $rubric['version'],
			'scores'           => wp_json_encode( $scores ),
			'weighted_score'   => $weights ? round( $sum / $weights, 2 ) : null,
			'recommendation'   => $recommendation,
			'comment'          => mb_substr( sanitize_textarea_field( (string) ( $data['comment'] ?? '' ) ), 0, 4000 ),
			'completed_at'     => $complete ? $now : null,
			'updated_at'       => $now,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE application_id = %d AND reviewer_user_id = %d", $app_id, absint( $user_id ) ) );
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( $complete ) {
			self::audit( 'application_scored', $event_id, $user_id, array( 'application_id' => $app_id, 'weighted_score' => $row['weighted_score'], 'recommendation' => $recommendation ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$saved = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d AND reviewer_user_id = %d", $app_id, absint( $user_id ) ), ARRAY_A );
		return $saved ? self::format_review( $saved, true ) : new WP_Error( 'lnkup_review_save_failed', __( 'Your review could not be saved. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
	}

	/**
	 * @param array $row        Review row.
	 * @param bool  $with_notes Include the private comment.
	 */
	private static function format_review( array $row, $with_notes ) {
		$scores = json_decode( (string) $row['scores'], true );
		return array(
			'reviewer'       => self::user_label( (int) $row['reviewer_user_id'] ),
			'scores'         => is_array( $scores ) ? $scores : array(),
			'weighted_score' => null === $row['weighted_score'] ? null : (float) $row['weighted_score'],
			'recommendation' => (string) $row['recommendation'],
			'comment'        => $with_notes ? (string) $row['comment'] : '',
			'complete'       => ! empty( $row['completed_at'] ),
			'completed_at'   => (string) $row['completed_at'],
			'rubric_version' => (int) $row['rubric_version'],
		);
	}

	/**
	 * Reviewers see others' scores only after submitting their own; decision-makers always do.
	 *
	 * @param array $rows    Review rows.
	 * @param int   $user_id Viewer.
	 * @param int   $app_id  Application.
	 */
	private static function can_see_others( array $rows, $user_id, $app_id ) {
		if ( LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $user_id ) ) {
			return true;
		}
		foreach ( $rows as $row ) {
			if ( (int) $row['reviewer_user_id'] === (int) $user_id && ! empty( $row['completed_at'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array $rows    Review rows.
	 * @param int   $user_id Viewer.
	 * @param int   $app_id  Application.
	 */
	private static function visible_reviews( array $rows, $user_id, $app_id ) {
		if ( ! self::can_see_others( $rows, $user_id, $app_id ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! empty( $row['completed_at'] ) ) {
				$out[] = self::format_review( $row, true );
			}
		}
		return $out;
	}

	/**
	 * @param array $rows    Review rows.
	 * @param int   $user_id Viewer.
	 * @param int   $app_id  Application.
	 */
	private static function score_summary( array $rows, $user_id, $app_id ) {
		$completed = array_values(
			array_filter(
				$rows,
				static function ( $row ) {
					return ! empty( $row['completed_at'] );
				}
			)
		);
		$mine_done = false;
		foreach ( $rows as $row ) {
			if ( (int) $row['reviewer_user_id'] === (int) $user_id && ! empty( $row['completed_at'] ) ) {
				$mine_done = true;
			}
		}
		$summary = array(
			'completed'   => count( $completed ),
			'my_complete' => $mine_done,
			'average'     => null,
			'recommend'   => array(),
		);
		if ( ! $completed || ! self::can_see_others( $rows, $user_id, $app_id ) ) {
			return $summary;
		}
		$scores = array_filter(
			array_map(
				static function ( $row ) {
					return null === $row['weighted_score'] ? null : (float) $row['weighted_score'];
				},
				$completed
			),
			static function ( $v ) {
				return null !== $v;
			}
		);
		$summary['average'] = $scores ? round( array_sum( $scores ) / count( $scores ), 2 ) : null;
		foreach ( $completed as $row ) {
			$rec = (string) $row['recommendation'];
			if ( '' !== $rec ) {
				$summary['recommend'][ $rec ] = ( $summary['recommend'][ $rec ] ?? 0 ) + 1;
			}
		}
		return $summary;
	}

	/* ---------------------------------------------------------------------
	 * Reviewer assignment
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $app_id      Application.
	 * @param int $reviewer_id Reviewer to add.
	 * @param int $actor       Acting user (needs manage_applications).
	 * @return true|WP_Error
	 */
	public static function assign_reviewer( $app_id, $reviewer_id, $actor ) {
		global $wpdb;
		$app_id      = absint( $app_id );
		$reviewer_id = absint( $reviewer_id );
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot assign reviewers for this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! $reviewer_id || ! LNKup_Capabilities::can_on_application( $app_id, 'score_applications', $reviewer_id ) ) {
			return new WP_Error( 'lnkup_reviewer_no_access', __( 'That person does not have review access to this event. Add them to your team first.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$table    = LNKup_Organizer_OS_Tables::review_assignments_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (application_id, event_id, reviewer_user_id, assigned_by, created_at) VALUES (%d, %d, %d, %d, %s)", $app_id, $event_id, $reviewer_id, absint( $actor ), current_time( 'mysql' ) ) );
		if ( $wpdb->rows_affected ) {
			self::audit( 'application_reviewer_assigned', $event_id, $actor, array( 'application_id' => $app_id, 'reviewer_user_id' => $reviewer_id ) );
			do_action( 'lnkup_application_reviewer_assigned', $app_id, $reviewer_id, absint( $actor ) );
			if ( $reviewer_id !== absint( $actor ) ) {
				self::notify_reviewer( $app_id, $reviewer_id, $actor );
			}
		}
		return true;
	}

	/**
	 * Tell a reviewer they were assigned. Only names the vendor and event; answers stay in the workspace.
	 *
	 * @param int $app_id      Application.
	 * @param int $reviewer_id Reviewer.
	 * @param int $actor       Who assigned them.
	 */
	private static function notify_reviewer( $app_id, $reviewer_id, $actor ) {
		$reviewer = get_userdata( $reviewer_id );
		if ( ! $reviewer || ! class_exists( 'LNKup_Notifications' ) ) {
			return;
		}
		$label    = self::bulk_label( $app_id );
		$assigner = get_userdata( absint( $actor ) );
		$url      = add_query_arg( array( 'view' => 'applications', 'assigned' => 'me', 'group' => 'pending', 'app' => $app_id ), lnkup_page_url_helper( 'organizer-dashboard' ) );
		LNKup_Notifications::send_keyed(
			'review_assigned',
			array( (int) $app_id, (int) $reviewer_id ),
			$reviewer->user_email,
			/* translators: %s: event name */
			sprintf( __( 'Application to review for %s', 'lnkup-marketplace' ), $label['event'] ),
			wpautop(
				esc_html(
					sprintf(
						/* translators: 1: person, 2: vendor, 3: event */
						__( '%1$s asked you to review %2$s\'s application for %3$s.', 'lnkup-marketplace' ),
						$assigner ? $assigner->display_name : __( 'The event team', 'lnkup-marketplace' ),
						$label['vendor'],
						$label['event']
					)
				)
			) . '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the review', 'lnkup-marketplace' ) . '</a></p>'
		);
	}

	/**
	 * @param int $app_id      Application.
	 * @param int $reviewer_id Reviewer to remove.
	 * @param int $actor       Acting user.
	 * @return true|WP_Error
	 */
	public static function remove_reviewer( $app_id, $reviewer_id, $actor ) {
		global $wpdb;
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You cannot change reviewers for this event.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$deleted = $wpdb->delete( LNKup_Organizer_OS_Tables::review_assignments_table(), array( 'application_id' => absint( $app_id ), 'reviewer_user_id' => absint( $reviewer_id ) ), array( '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $deleted ) {
			self::audit( 'application_reviewer_removed', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $actor, array( 'application_id' => absint( $app_id ), 'reviewer_user_id' => absint( $reviewer_id ) ) );
		}
		return true;
	}

	/**
	 * Team members who can be assigned to review on an event.
	 *
	 * @param int $event_id Event.
	 */
	public static function reviewer_candidates( $event_id ) {
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$out   = array();
		if ( $owner ) {
			$out[ $owner ] = self::user_label( $owner );
		}
		if ( class_exists( 'LNKup_Org_Teams' ) && $owner ) {
			$team = LNKup_Org_Teams::list_team( $owner );
			foreach ( (array) ( $team['members'] ?? array() ) as $member ) {
				$uid = (int) $member['user_id'];
				if ( LNKup_Capabilities::staff_can( $event_id, 'score_applications', $uid ) ) {
					$out[ $uid ] = self::user_label( $uid );
				}
			}
		}
		return array_values( $out );
	}

	/* ---------------------------------------------------------------------
	 * Bulk decisions
	 * ------------------------------------------------------------------- */

	/**
	 * Validate a bulk selection without changing anything.
	 *
	 * @param int[]  $app_ids Selected applications.
	 * @param string $action  approve|reject|waitlist|review.
	 * @param int    $user_id Actor.
	 * @return array|WP_Error {eligible:[], ineligible:[], token}
	 */
	public static function bulk_preview( array $app_ids, $action, $user_id ) {
		$action  = sanitize_key( (string) $action );
		$app_ids = array_values( array_unique( array_filter( array_map( 'absint', $app_ids ) ) ) );
		if ( ! in_array( $action, self::BULK_ACTIONS, true ) ) {
			return new WP_Error( 'lnkup_bulk_action', __( 'Choose approve, decline, waitlist or mark as in review.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! $app_ids ) {
			return new WP_Error( 'lnkup_bulk_empty', __( 'Select at least one application.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( count( $app_ids ) > self::BULK_MAX ) {
			return new WP_Error( 'lnkup_bulk_too_many', __( 'Select 200 applications or fewer at a time.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$target     = self::bulk_target( $action );
		$eligible   = array();
		$ineligible = array();
		$events     = array();
		foreach ( $app_ids as $app_id ) {
			$reason = self::bulk_ineligible_reason( $app_id, $target, $user_id );
			$label  = self::bulk_label( $app_id );
			if ( '' !== $reason ) {
				$ineligible[] = array( 'id' => $app_id, 'vendor_name' => $label['vendor'], 'event_title' => $label['event'], 'reason' => $reason );
				continue;
			}
			$eligible[]                = array( 'id' => $app_id, 'vendor_name' => $label['vendor'], 'event_title' => $label['event'], 'status' => (string) get_post_meta( $app_id, '_lnkup_application_status', true ) );
			$events[ $label['event'] ] = true;
		}
		$token = '';
		if ( $eligible ) {
			$token = wp_generate_password( 32, false, false );
			set_transient(
				'lnkup_bulk_preview_' . $token,
				array( 'user_id' => absint( $user_id ), 'action' => $action, 'ids' => wp_list_pluck( $eligible, 'id' ) ),
				self::BULK_PREVIEW_TTL
			);
		}
		return array(
			'action'     => $action,
			'eligible'   => $eligible,
			'ineligible' => $ineligible,
			'events'     => array_keys( $events ),
			'token'      => $token,
			'expires_in' => self::BULK_PREVIEW_TTL,
		);
	}

	/**
	 * Apply a previewed bulk decision. Each application is re-checked at apply time.
	 *
	 * @param string $token   Preview token.
	 * @param int    $user_id Actor (must be the one who previewed).
	 * @param array  $extra   rejection_reason.
	 * @return array|WP_Error
	 */
	public static function bulk_apply( $token, $user_id, array $extra = array() ) {
		$key     = 'lnkup_bulk_preview_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		$preview = get_transient( $key );
		if ( ! is_array( $preview ) || (int) $preview['user_id'] !== absint( $user_id ) ) {
			return new WP_Error( 'lnkup_bulk_preview_expired', __( 'This preview expired. Review the selection again before applying.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		delete_transient( $key );
		$ids    = array_map( 'absint', (array) $preview['ids'] );
		$action = (string) $preview['action'];
		$extra  = array( 'rejection_reason' => sanitize_textarea_field( (string) ( $extra['rejection_reason'] ?? '' ) ) );
		if ( count( $ids ) > self::BULK_SYNC_LIMIT ) {
			$job_id = wp_generate_password( 20, false, false );
			set_transient(
				'lnkup_bulk_job_' . $job_id,
				array( 'user_id' => absint( $user_id ), 'action' => $action, 'pending' => $ids, 'results' => array(), 'extra' => $extra, 'status' => 'queued' ),
				DAY_IN_SECONDS
			);
			wp_schedule_single_event( time(), self::BULK_JOB_CRON, array( $job_id ) );
			return array( 'job_id' => $job_id, 'status' => 'queued', 'total' => count( $ids ) );
		}
		$results = array();
		foreach ( $ids as $app_id ) {
			$results[] = self::bulk_apply_one( $app_id, $action, $user_id, $extra );
		}
		self::bulk_audit( $results, $action, $user_id );
		return array( 'status' => 'done', 'results' => $results, 'summary' => self::bulk_summary( $results ) );
	}

	/**
	 * Background batch for large bulk decisions; runs as the user who confirmed it.
	 *
	 * @param string $job_id Job ID.
	 */
	public static function run_bulk_job( $job_id ) {
		$key = 'lnkup_bulk_job_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $job_id );
		$job = get_transient( $key );
		if ( ! is_array( $job ) || empty( $job['pending'] ) ) {
			return;
		}
		$previous_user = get_current_user_id();
		wp_set_current_user( (int) $job['user_id'] );
		try {
			$batch          = array_splice( $job['pending'], 0, self::BULK_JOB_BATCH );
			$batch_results  = array();
			foreach ( $batch as $app_id ) {
				$batch_results[] = self::bulk_apply_one( (int) $app_id, (string) $job['action'], (int) $job['user_id'], (array) $job['extra'] );
			}
			$job['results'] = array_merge( (array) $job['results'], $batch_results );
			self::bulk_audit( $batch_results, (string) $job['action'], (int) $job['user_id'] );
			$job['status'] = $job['pending'] ? 'running' : 'done';
			set_transient( $key, $job, DAY_IN_SECONDS );
			if ( $job['pending'] ) {
				wp_schedule_single_event( time() + 5, self::BULK_JOB_CRON, array( $job_id ) );
			}
		} finally {
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * @param string $job_id  Job.
	 * @param int    $user_id Viewer (must own the job).
	 * @return array|WP_Error
	 */
	public static function bulk_job_status( $job_id, $user_id ) {
		$job = get_transient( 'lnkup_bulk_job_' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $job_id ) );
		if ( ! is_array( $job ) || (int) $job['user_id'] !== absint( $user_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'That bulk update was not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return array(
			'status'    => (string) $job['status'],
			'remaining' => count( (array) $job['pending'] ),
			'results'   => (array) $job['results'],
			'summary'   => self::bulk_summary( (array) $job['results'] ),
		);
	}

	private static function bulk_target( $action ) {
		$map = array( 'approve' => 'approved', 'reject' => 'rejected', 'waitlist' => 'waitlisted', 'review' => 'under_review' );
		return $map[ $action ] ?? '';
	}

	/**
	 * @param int    $app_id  Application.
	 * @param string $target  Target status.
	 * @param int    $user_id Actor.
	 */
	private static function bulk_ineligible_reason( $app_id, $target, $user_id ) {
		if ( 'lnkup_application' !== get_post_type( $app_id ) || in_array( get_post_status( $app_id ), array( 'trash', false ), true ) ) {
			return __( 'Application no longer exists.', 'lnkup-marketplace' );
		}
		if ( ! LNKup_Capabilities::can_on_application( $app_id, 'manage_applications', $user_id ) ) {
			return __( 'You cannot decide applications for this event.', 'lnkup-marketplace' );
		}
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! LNKup_Applications::can_transition( $status, $target ) ) {
			/* translators: %s: current status label */
			return sprintf( __( 'Already %s.', 'lnkup-marketplace' ), strtolower( lnkup_application_status_label( $status ) ?: $status ) );
		}
		if ( 'approved' === $target ) {
			$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
			if ( ! LNKup_Opportunity_Inventory::has_room( $opp_id, $app_id ) ) {
				return __( 'No spots left on this vendor slot.', 'lnkup-marketplace' );
			}
		}
		return '';
	}

	/**
	 * @param int    $app_id  Application.
	 * @param string $action  Bulk action.
	 * @param int    $user_id Actor.
	 * @param array  $extra   Extras.
	 */
	private static function bulk_apply_one( $app_id, $action, $user_id, array $extra ) {
		$label  = self::bulk_label( $app_id );
		$reason = self::bulk_ineligible_reason( $app_id, self::bulk_target( $action ), $user_id );
		if ( '' !== $reason ) {
			return array( 'id' => $app_id, 'vendor_name' => $label['vendor'], 'ok' => false, 'message' => $reason );
		}
		$result = LNKup_Applications::handle_organizer_action( $app_id, $action, $user_id, $extra );
		if ( is_wp_error( $result ) ) {
			return array( 'id' => $app_id, 'vendor_name' => $label['vendor'], 'ok' => false, 'message' => $result->get_error_message() );
		}
		return array( 'id' => $app_id, 'vendor_name' => $label['vendor'], 'ok' => true, 'status' => (string) get_post_meta( $app_id, '_lnkup_application_status', true ) );
	}

	private static function bulk_summary( array $results ) {
		$ok = count( array_filter( wp_list_pluck( $results, 'ok' ) ) );
		return array( 'updated' => $ok, 'skipped' => count( $results ) - $ok );
	}

	/**
	 * One audit entry per organization touched.
	 *
	 * @param array  $results Results.
	 * @param string $action  Action.
	 * @param int    $user_id Actor.
	 */
	private static function bulk_audit( array $results, $action, $user_id ) {
		$by_owner = array();
		foreach ( $results as $row ) {
			if ( empty( $row['ok'] ) ) {
				continue;
			}
			$owner                = (int) get_post_meta( (int) $row['id'], '_lnkup_organizer_user_id', true );
			$by_owner[ $owner ][] = (int) $row['id'];
		}
		foreach ( $by_owner as $owner => $ids ) {
			if ( class_exists( 'LNKup_Audit_Log' ) ) {
				LNKup_Audit_Log::log( 'application_bulk_decision', 'org', $owner, array( 'actor_user_id' => absint( $user_id ), 'new' => array( 'action' => $action, 'application_ids' => $ids ) ) );
			}
		}
	}

	private static function bulk_label( $app_id ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		return array(
			'vendor' => $profile_id ? ( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id ) ) : '#' . $app_id,
			'event'  => $event_id ? get_the_title( $event_id ) : '',
		);
	}

	/**
	 * Team activity entry, filed under the event owner's organization.
	 *
	 * @param string $action   Action.
	 * @param int    $event_id Event.
	 * @param int    $actor    Actor.
	 * @param array  $payload  New state.
	 */
	public static function audit( $action, $event_id, $actor, array $payload ) {
		if ( ! class_exists( 'LNKup_Audit_Log' ) ) {
			return;
		}
		$owner = (int) get_post_meta( absint( $event_id ), '_lnkup_organizer_user_id', true );
		LNKup_Audit_Log::log( $action, 'org', $owner, array( 'actor_user_id' => absint( $actor ), 'new' => $payload ) );
	}
}
