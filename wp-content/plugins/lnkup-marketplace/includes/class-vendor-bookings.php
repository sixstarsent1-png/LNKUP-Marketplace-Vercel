<?php
/**
 * Vendor "My Bookings": paginated booking list, per-booking detail, and an evidence-based timeline.
 *
 * Read-only over canonical records (lnkup_application, lnkup_payment, invoices, receipts, refunds).
 * A booking is an application from approval onward, plus closed (cancelled / rejected / refunded)
 * ones; applications still in review stay on the Requests view.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Bookings {

	const BUCKETS      = array( 'all', 'upcoming', 'pending_payment', 'confirmed', 'past', 'cancelled', 'refunded' );
	const MAX_PER_PAGE = 50;
	const IN_REVIEW    = array( '', 'submitted', 'under_review', 'info_requested', 'waitlisted' );

	/**
	 * Paginated, filtered bookings for a vendor.
	 *
	 * @param int   $vendor_id Vendor user ID.
	 * @param array $args      bucket, tour_id, city, from (Y-m-d), to (Y-m-d), page, per_page.
	 * @return array{items:array,total:int,page:int,pages:int,per_page:int,counts:array,filters:array}
	 */
	public static function query( $vendor_id, array $args = array() ) {
		global $wpdb;
		$vendor_id = absint( $vendor_id );
		$filters   = self::normalize_args( $args );
		$today     = current_time( 'Y-m-d' );
		$base      = self::base_sql( $vendor_id );
		$conds     = self::bucket_conditions( $today );

		$where  = array( $conds[ $filters['bucket'] ] );
		$params = array();
		if ( $filters['tour_id'] ) {
			$where[]  = 'et.meta_value = %s';
			$params[] = (string) $filters['tour_id'];
		}
		if ( '' !== $filters['city'] ) {
			$where[]  = 'ec.meta_value = %s';
			$params[] = $filters['city'];
		}
		if ( '' !== $filters['from'] ) {
			$where[]  = 'ed.meta_value >= %s';
			$params[] = $filters['from'];
		}
		if ( '' !== $filters['to'] ) {
			$where[]  = 'ed.meta_value <= %s';
			$params[] = $filters['to'];
		}
		$where_sql = implode( ' AND ', $where );
		$order     = in_array( $filters['bucket'], array( 'upcoming', 'pending_payment', 'confirmed' ), true )
			? "COALESCE(NULLIF(ed.meta_value, ''), '9999-12-31') ASC, a.ID ASC"
			: "COALESCE(NULLIF(ed.meta_value, ''), '0000-01-01') DESC, a.ID DESC";

		$count_sql = "SELECT COUNT(*) {$base['from']} WHERE {$base['where']} AND {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset   = ( $filters['page'] - 1 ) * $filters['per_page'];
		$list_sql = "SELECT a.ID {$base['from']} WHERE {$base['where']} AND {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		$ids      = $wpdb->get_col( $wpdb->prepare( $list_sql, array_merge( $params, array( $filters['per_page'], $offset ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sums = array();
		foreach ( $conds as $key => $cond ) {
			$sums[] = "SUM(CASE WHEN {$cond} THEN 1 ELSE 0 END) AS `{$key}`";
		}
		$counts_row = $wpdb->get_row( 'SELECT ' . implode( ', ', $sums ) . " {$base['from']} WHERE {$base['where']}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts     = array();
		foreach ( self::BUCKETS as $key ) {
			$counts[ $key ] = (int) ( $counts_row[ $key ] ?? 0 );
		}

		return array(
			'items'    => array_map( array( __CLASS__, 'row' ), array_map( 'intval', (array) $ids ) ),
			'total'    => $total,
			'page'     => $filters['page'],
			'per_page' => $filters['per_page'],
			'pages'    => (int) max( 1, ceil( $total / $filters['per_page'] ) ),
			'counts'   => $counts,
			'filters'  => $filters,
		);
	}

	/**
	 * @param array $args Raw args.
	 * @return array{bucket:string,tour_id:int,city:string,from:string,to:string,page:int,per_page:int}
	 */
	public static function normalize_args( array $args ) {
		$bucket = sanitize_key( (string) ( $args['bucket'] ?? 'all' ) );
		$date   = static function ( $value ) {
			$value = (string) $value;
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		return array(
			'bucket'   => in_array( $bucket, self::BUCKETS, true ) ? $bucket : 'all',
			'tour_id'  => absint( $args['tour_id'] ?? 0 ),
			'city'     => mb_substr( sanitize_text_field( (string) ( $args['city'] ?? '' ) ), 0, 100 ),
			'from'     => $date( $args['from'] ?? '' ),
			'to'       => $date( $args['to'] ?? '' ),
			'page'     => max( 1, absint( $args['page'] ?? 1 ) ),
			'per_page' => min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ?? 12 ) ) ),
		);
	}

	/**
	 * FROM / WHERE shared by list, count and bucket totals. Event fields live on the event post;
	 * payment status comes from the newest payment row for each application.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array{from:string,where:string}
	 */
	private static function base_sql( $vendor_id ) {
		global $wpdb;
		$p  = $wpdb->posts;
		$pm = $wpdb->postmeta;
		$from = $wpdb->prepare(
			"FROM {$p} a
			INNER JOIN {$pm} v ON v.post_id = a.ID AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = %s
			LEFT JOIN {$pm} st ON st.post_id = a.ID AND st.meta_key = '_lnkup_application_status'
			LEFT JOIN {$pm} ev ON ev.post_id = a.ID AND ev.meta_key = '_lnkup_event_id'
			LEFT JOIN {$pm} ed ON ed.post_id = CAST(ev.meta_value AS UNSIGNED) AND ed.meta_key = '_lnkup_event_date'
			LEFT JOIN {$pm} ec ON ec.post_id = CAST(ev.meta_value AS UNSIGNED) AND ec.meta_key = '_lnkup_city'
			LEFT JOIN {$pm} et ON et.post_id = CAST(ev.meta_value AS UNSIGNED) AND et.meta_key = '_lnkup_tour_id'
			LEFT JOIN (
				SELECT pa.meta_value AS app_id, MAX(pp.ID) AS pay_id
				FROM {$p} pp
				INNER JOIN {$pm} pa ON pa.post_id = pp.ID AND pa.meta_key = '_lnkup_application_id'
				INNER JOIN {$pm} pv ON pv.post_id = pp.ID AND pv.meta_key = '_lnkup_vendor_user_id' AND pv.meta_value = %s
				WHERE pp.post_type = 'lnkup_payment' AND pp.post_status = 'publish'
				GROUP BY pa.meta_value
			) lp ON lp.app_id = a.ID
			LEFT JOIN {$pm} ps ON ps.post_id = lp.pay_id AND ps.meta_key = '_lnkup_payment_status'",
			(string) $vendor_id,
			(string) $vendor_id
		);
		return array(
			'from'  => $from,
			'where' => "a.post_type = 'lnkup_application' AND a.post_status = 'publish'",
		);
	}

	/**
	 * SQL condition per bucket (table aliases from base_sql()).
	 *
	 * @param string $today Site-local Y-m-d.
	 * @return array<string, string>
	 */
	private static function bucket_conditions( $today ) {
		global $wpdb;
		$status   = "COALESCE(st.meta_value, '')";
		$pay      = "COALESCE(ps.meta_value, '')";
		$date     = "COALESCE(ed.meta_value, '')";
		$future   = $wpdb->prepare( "({$date} = '' OR {$date} >= %s)", $today );
		$before   = $wpdb->prepare( "({$date} <> '' AND {$date} < %s)", $today );
		$review   = "'" . implode( "','", array_map( 'esc_sql', self::IN_REVIEW ) ) . "'";
		$refunded = "{$pay} IN ('refunded','partially_refunded') AND {$status} NOT IN ('paid','completed')";
		$pending  = "{$status} IN ('approved','payment_required') AND {$pay} IN ('','pending','failed','partially_paid','processing')";
		return array(
			'all'             => "{$status} NOT IN ({$review})",
			'pending_payment' => $pending,
			'confirmed'       => "{$status} = 'paid' AND {$future}",
			'upcoming'        => "(({$pending}) OR {$status} = 'paid') AND {$future}",
			'past'            => "({$status} = 'completed' OR ({$status} = 'paid' AND {$before}))",
			'cancelled'       => "{$status} IN ('cancelled','rejected') AND NOT ({$refunded})",
			'refunded'        => $refunded,
		);
	}

	/**
	 * Light card DTO (no readiness checklist: it writes meta on read).
	 *
	 * @param int $app_id Application ID.
	 * @return array<string, mixed>
	 */
	public static function row( $app_id ) {
		$app        = get_post( $app_id );
		$meta       = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		$event_id   = (int) $meta['event_id'];
		$opp_id     = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$status     = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$payment    = LNKup_Payments::get_for_application( $app_id );
		$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
		$org_id     = $event_id ? (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) : 0;
		$organizer  = $org_id ? get_userdata( $org_id ) : null;
		$tour_id    = $event_id ? (int) get_post_meta( $event_id, '_lnkup_tour_id', true ) : 0;
		$tour       = $tour_id && class_exists( 'LNKup_Tours' ) ? LNKup_Tours::card_summary( $tour_id ) : null;
		$image_id   = $event_id ? (int) get_post_thumbnail_id( $event_id ) : 0;
		$doc_code   = class_exists( 'LNKup_Verification' ) ? (string) ( LNKup_Verification::get_application_document_context( (int) $app_id )['code'] ?? '' ) : '';
		$pass       = LNKup_Packets::is_pass_unlocked_for_application( $app_id );
		$payable    = $payment && in_array( $status, array( 'approved', 'payment_required' ), true ) && LNKup_Payments::is_payable( $payment->ID );

		if ( $payable ) {
			$next = 'pay';
		} elseif ( in_array( $doc_code, array( 'missing', 'pending' ), true ) && 'paid' === $status ) {
			$next = 'documents';
		} elseif ( $pass && 'paid' === $status ) {
			$next = 'open_pass';
		} else {
			$next = 'view';
		}

		return array(
			'application_id'   => (int) $app_id,
			'event_id'         => $event_id,
			'event_name'       => (string) $meta['event_title'],
			'organizer_name'   => $organizer ? $organizer->display_name : '',
			'image'            => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'medium' ) : '',
			'event_date'       => (string) $meta['date'],
			'start_time'       => (string) $meta['start_time'],
			'venue'            => (string) $meta['venue'],
			'city'             => (string) $meta['city'],
			'state'            => (string) $meta['state'],
			'booth'            => $opp_id ? get_the_title( $opp_id ) : '',
			'status'           => $status,
			'status_label'     => LNKup_Vendor_Dashboard_Data::application_status_label( $status ),
			'payment_id'       => $payment ? (int) $payment->ID : 0,
			'payment_status'   => $pay_status,
			'amount_due'       => $payable ? LNKup_Financial_Service::stored_amount_due( $payment->ID ) : 0.0,
			'payment_due_at'   => (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true ),
			'documents_code'   => $doc_code,
			'pass_available'   => $pass,
			'checked_in'       => '' !== (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ),
			'next_action'      => $next,
			'group_id'         => (string) get_post_meta( $app_id, '_lnkup_submission_group_id', true ),
			'tour'             => $tour ? array( 'id' => (int) $tour['id'], 'title' => (string) $tour['title'], 'type' => (string) $tour['type'] ) : null,
			'detail_url'       => self::detail_url( $app_id ),
			'pass_url'         => self::pass_url( $app_id ),
		);
	}

	/**
	 * Full booking detail for its vendor (null when not theirs).
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $app_id    Application.
	 * @return array|null
	 */
	public static function detail( $vendor_id, $app_id ) {
		$app_id = absint( $app_id );
		if ( 'lnkup_application' !== get_post_type( $app_id ) || 'publish' !== get_post_status( $app_id )
			|| ! LNKup_Capabilities::vendor_owns_application( $app_id, absint( $vendor_id ) ) ) {
			return null;
		}
		$row     = self::row( $app_id );
		$payment = LNKup_Payments::get_for_application( $app_id );
		$event   = $row['event_id'] ? get_post( $row['event_id'] ) : null;

		$payment_block = null;
		if ( $payment ) {
			$pid           = (int) $payment->ID;
			$invoice       = LNKup_Invoices::get_by_application( $app_id );
			$payment_block = array(
				'payment_id'      => $pid,
				'status'          => (string) get_post_meta( $pid, '_lnkup_payment_status', true ),
				'total'           => round( (float) get_post_meta( $pid, '_lnkup_total_amount', true ), 2 ),
				'credit_applied'  => round( (float) get_post_meta( $pid, '_lnkup_credit_applied', true ), 2 ),
				'card_charged'    => round( (int) get_post_meta( $pid, '_lnkup_stripe_charge_amount_cents', true ) / 100, 2 ),
				'amount_due'      => $row['amount_due'],
				'refunded'        => round( (float) get_post_meta( $pid, '_lnkup_refund_amount', true ), 2 ),
				'credit_refunded' => round( (float) get_post_meta( $pid, '_lnkup_credit_refund_amount', true ), 2 ),
				'paid_at'         => (string) get_post_meta( $pid, '_lnkup_paid_at', true ),
				'due_at'          => $row['payment_due_at'],
				'invoice'         => $invoice ? LNKup_Invoices::format( $invoice ) : null,
				'receipts'        => self::receipts_for_payment( $pid ),
				'refunds'         => self::refunds_for_payment( $pid ),
			);
		}

		$readiness = class_exists( 'LNKup_Vendor_Readiness' ) && in_array( $row['status'], array( 'paid', 'completed' ), true )
			? LNKup_Vendor_Readiness::checklist( $app_id )
			: null;
		$pass_dto  = LNKup_Packets::get_vendor_event_pass( $app_id, (int) $vendor_id );

		return array_merge(
			$row,
			array(
				'event'        => array(
					'id'            => $row['event_id'],
					'title'         => $row['event_name'],
					'date'          => $row['event_date'],
					'start_time'    => $row['start_time'],
					'venue'         => $row['venue'],
					'venue_address' => $row['event_id'] ? (string) get_post_meta( $row['event_id'], '_lnkup_venue_address', true ) : '',
					'city'          => $row['city'],
					'state'         => $row['state'],
					'organizer'     => $row['organizer_name'],
					'url'           => $row['event_id'] && class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( (int) $row['event_id'] ) : '',
					'status'        => $event ? (string) get_post_meta( $event->ID, '_lnkup_event_status', true ) : '',
				),
				'booking'      => array(
					'booth'            => $row['booth'],
					'category'         => (string) get_post_meta( $app_id, '_lnkup_selected_category', true ),
					'submitted_at'     => (string) get_post_meta( $app_id, '_lnkup_submitted_at', true ),
					'answers'          => LNKup_Application_Questions::answers_for_application( $app_id ),
					'rejection_reason' => (string) get_post_meta( $app_id, '_lnkup_rejection_reason', true ),
					'cancel_reason'    => (string) get_post_meta( $app_id, '_lnkup_cancellation_reason', true ),
					'can_withdraw'     => in_array( $row['status'], LNKup_Applications::WITHDRAWABLE, true )
						&& ! ( $payment && in_array( (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ), array( 'processing', 'paid', 'partially_paid' ), true ) ),
				),
				'payment'      => $payment_block,
				'readiness'    => $readiness,
				'instructions' => is_array( $pass_dto ) ? $pass_dto : null,
				'support_url'  => add_query_arg( array( 'view' => 'operations', 'app' => $app_id, 'tab' => 'support' ), lnkup_page_url_helper( 'vendor-dashboard' ) ),
				'timeline'     => self::timeline( $app_id ),
			)
		);
	}

	/**
	 * Booking timeline from recorded evidence only (status history, payment and check-in timestamps).
	 * Applications from before status history existed get only what their current status proves.
	 *
	 * @param int $app_id Application ID.
	 * @return array<int, array{key:string,label:string,at:string,state:string,group:string,note:string}>
	 */
	public static function timeline( $app_id ) {
		$status  = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$history = LNKup_Applications::status_history( $app_id );
		$first   = static function ( array $targets, $reason = '' ) use ( $history ) {
			foreach ( $history as $entry ) {
				if ( in_array( $entry['to'] ?? '', $targets, true ) && ( '' === $reason || $reason === ( $entry['reason'] ?? '' ) ) ) {
					return (string) $entry['at'];
				}
			}
			return '';
		};
		$reached = static function ( array $targets ) use ( $history ) {
			foreach ( $history as $entry ) {
				if ( in_array( $entry['to'] ?? '', $targets, true ) ) {
					return true;
				}
			}
			return false;
		};
		$payment    = LNKup_Payments::get_for_application( $app_id );
		$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
		$is_free    = $payment && (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true ) <= 0;
		$paid_like  = in_array( $status, array( 'paid', 'completed' ), true ) || $reached( array( 'paid' ) );
		$approved   = $paid_like || in_array( $status, array( 'approved', 'payment_required' ), true ) || $reached( array( 'approved', 'payment_required' ) );

		$items   = array();
		$items[] = array( 'key' => 'submitted', 'group' => 'application', 'label' => __( 'Application submitted', 'lnkup-marketplace' ), 'at' => (string) get_post_meta( $app_id, '_lnkup_submitted_at', true ) ?: $first( array( 'submitted' ) ), 'done' => true );
		if ( $reached( array( 'info_requested' ) ) || 'info_requested' === $status ) {
			$items[] = array( 'key' => 'info', 'group' => 'application', 'label' => __( 'Organizer asked for more information', 'lnkup-marketplace' ), 'at' => $first( array( 'info_requested' ) ), 'done' => 'info_requested' !== $status );
		}
		$items[] = array( 'key' => 'review', 'group' => 'application', 'label' => __( 'Organizer reviewing', 'lnkup-marketplace' ), 'at' => $first( array( 'under_review' ) ), 'done' => $approved || in_array( $status, array( 'rejected', 'waitlisted' ), true ) );

		$stopped = '';
		if ( 'rejected' === $status ) {
			$items[] = array( 'key' => 'rejected', 'group' => 'application', 'label' => __( 'Not selected', 'lnkup-marketplace' ), 'at' => $first( array( 'rejected' ) ) ?: (string) get_post_meta( $app_id, '_lnkup_decided_at', true ), 'done' => true, 'stop' => true );
			$stopped = 'rejected';
		} elseif ( 'waitlisted' === $status ) {
			$items[] = array( 'key' => 'waitlisted', 'group' => 'application', 'label' => __( 'On the waitlist', 'lnkup-marketplace' ), 'at' => $first( array( 'waitlisted' ) ), 'done' => false, 'current' => true );
			$stopped = 'waitlisted';
		} else {
			$items[] = array( 'key' => 'approved', 'group' => 'application', 'label' => __( 'Approved', 'lnkup-marketplace' ), 'at' => $first( array( 'approved' ) ) ?: (string) get_post_meta( $app_id, '_lnkup_approved_at', true ), 'done' => $approved );
		}

		if ( ! $stopped ) {
			if ( ! $is_free ) {
				$due     = (string) get_post_meta( $app_id, '_lnkup_payment_due_at', true );
				$items[] = array(
					'key'   => 'payment',
					'group' => 'payment',
					'label' => $paid_like ? __( 'Payment completed', 'lnkup-marketplace' ) : __( 'Payment', 'lnkup-marketplace' ),
					'at'    => $paid_like && $payment ? (string) get_post_meta( $payment->ID, '_lnkup_paid_at', true ) : '',
					'note'  => ! $paid_like && $due && in_array( $status, array( 'approved', 'payment_required' ), true )
						/* translators: %s: date */
						? sprintf( __( 'Due by %s', 'lnkup-marketplace' ), mysql2date( get_option( 'date_format' ), get_date_from_gmt( $due ) ) )
						: ( 'processing' === $pay_status ? __( 'Bank payment processing', 'lnkup-marketplace' ) : '' ),
					'done'  => $paid_like,
				);
			}
			$items[] = array( 'key' => 'confirmed', 'group' => 'payment', 'label' => __( 'Booking confirmed', 'lnkup-marketplace' ), 'at' => $first( array( 'paid' ) ) ?: ( $payment ? (string) get_post_meta( $payment->ID, '_lnkup_paid_at', true ) : '' ), 'done' => $paid_like );

			$refunded = $payment ? (float) get_post_meta( $payment->ID, '_lnkup_refund_amount', true ) + (float) get_post_meta( $payment->ID, '_lnkup_credit_refund_amount', true ) : 0.0;
			if ( 'cancelled' === $status ) {
				$reason  = (string) get_post_meta( $app_id, '_lnkup_cancellation_reason', true );
				$label   = 'refunded' === $reason || $refunded > 0 ? __( 'Cancelled and refunded', 'lnkup-marketplace' ) : ( 'withdrawn' === $reason ? __( 'You withdrew this application', 'lnkup-marketplace' ) : __( 'Booking cancelled', 'lnkup-marketplace' ) );
				$items[] = array( 'key' => 'cancelled', 'group' => 'payment', 'label' => $label, 'at' => $first( array( 'cancelled' ) ), 'done' => true, 'stop' => true );
				$stopped = 'cancelled';
			} elseif ( $refunded > 0 ) {
				/* translators: %s: amount */
				$items[] = array( 'key' => 'refunded', 'group' => 'payment', 'label' => sprintf( __( 'Refund of %s issued', 'lnkup-marketplace' ), lnkup_currency( $refunded ) ), 'at' => '', 'done' => true );
			}
		}

		if ( ! $stopped ) {
			$pass    = LNKup_Packets::is_pass_unlocked_for_application( $app_id );
			$items[] = array( 'key' => 'pass', 'group' => 'readiness', 'label' => __( 'Event pass available', 'lnkup-marketplace' ), 'at' => '', 'done' => $pass );
			$checked = (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true );
			$items[] = array( 'key' => 'checked_in', 'group' => 'attendance', 'label' => __( 'Checked in', 'lnkup-marketplace' ), 'at' => $checked, 'done' => '' !== $checked );
			$done_at = (string) get_post_meta( $app_id, '_lnkup_event_completed_at', true );
			$items[] = array( 'key' => 'completed', 'group' => 'attendance', 'label' => __( 'Event completed', 'lnkup-marketplace' ), 'at' => 'completed' === $status ? ( $first( array( 'completed' ) ) ?: $done_at ) : '', 'done' => 'completed' === $status );
		}

		$current_set = false;
		$out         = array();
		foreach ( $items as $item ) {
			if ( ! empty( $item['stop'] ) ) {
				$state = 'stopped';
			} elseif ( $item['done'] ) {
				$state = 'done';
			} elseif ( ! $current_set ) {
				$state       = 'current';
				$current_set = true;
			} else {
				$state = 'upcoming';
			}
			$out[] = array(
				'key'   => $item['key'],
				'label' => $item['label'],
				'at'    => $item['done'] || ! empty( $item['stop'] ) ? (string) $item['at'] : '',
				'state' => $state,
				'group' => $item['group'],
				'note'  => (string) ( $item['note'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * @param int $payment_id Payment post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function receipts_for_payment( $payment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::receipts_table() . ' WHERE payment_post_id = %d ORDER BY id ASC LIMIT 20', $payment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( array( 'LNKup_Receipts', 'format' ), (array) $rows ) ) );
	}

	/**
	 * @param int $payment_id Payment post ID.
	 * @return array<int, array<string, mixed>>
	 */
	private static function refunds_for_payment( $payment_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::refunds_table() . ' WHERE payment_post_id = %d ORDER BY id ASC LIMIT 20', $payment_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_values( array_filter( array_map( array( 'LNKup_Refunds', 'format' ), (array) $rows ) ) );
	}

	/**
	 * Cities and tours available as filters for this vendor's bookings.
	 *
	 * @param int $vendor_id Vendor.
	 * @return array{cities:string[],tours:array}
	 */
	public static function filter_options( $vendor_id ) {
		global $wpdb;
		$base   = self::base_sql( absint( $vendor_id ) );
		$review = "'" . implode( "','", array_map( 'esc_sql', self::IN_REVIEW ) ) . "'";
		$scope  = "{$base['where']} AND COALESCE(st.meta_value, '') NOT IN ({$review})";
		$cities = $wpdb->get_col( "SELECT DISTINCT ec.meta_value {$base['from']} WHERE {$scope} AND ec.meta_value <> '' ORDER BY ec.meta_value LIMIT 100" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tours  = array();
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT et.meta_value {$base['from']} WHERE {$scope} AND et.meta_value > 0 LIMIT 50" ) as $tid ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$summary = class_exists( 'LNKup_Tours' ) ? LNKup_Tours::card_summary( (int) $tid, true ) : null;
			if ( $summary ) {
				$tours[] = array( 'id' => (int) $summary['id'], 'title' => (string) $summary['title'] );
			}
		}
		return array( 'cities' => array_values( (array) $cities ), 'tours' => $tours );
	}

	/**
	 * @param int $app_id Application ID.
	 */
	public static function detail_url( $app_id ) {
		return add_query_arg( array( 'view' => 'bookings', 'booking' => (int) $app_id ), lnkup_page_url_helper( 'vendor-dashboard' ) );
	}

	/**
	 * @param int $app_id Application ID.
	 */
	public static function pass_url( $app_id ) {
		return add_query_arg( array( 'view' => 'operations', 'app' => (int) $app_id, 'tab' => 'pass' ), lnkup_page_url_helper( 'vendor-dashboard' ) );
	}
}
