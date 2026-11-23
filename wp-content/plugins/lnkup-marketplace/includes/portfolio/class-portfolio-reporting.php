<?php
/**
 * Tour / series reporting built only from event-level records.
 *
 * Money comes from lnkup_payment posts (the same source of truth as
 * LNKup_Financial::event_collected_revenue()), each payment counted once by ID, and refunds from
 * the transactions ledger. Gross booking value, platform fees, pending amounts and refunds are
 * reported separately. The tour total is compared with the sum of the per-stop totals and the
 * response says whether they agree, so a reporting drift is visible instead of hidden.
 *
 * Imported or organizer-reported historical payments are not lnkup_payment records and never
 * appear here.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Reporting {

	const COLLECTED_STATUSES = array( 'paid', 'refunded', 'partially_refunded', 'disputed', 'partially_paid' );
	const PENDING_STATUSES   = array( 'pending', 'processing' );

	/**
	 * Tour dashboard summary.
	 *
	 * @param int $tour_id Tour.
	 * @return array
	 */
	public static function summary( $tour_id ) {
		$event_ids = LNKup_Tours::stop_event_ids( $tour_id );
		$apps      = self::application_counts( $event_ids );
		$money     = self::money_by_event( $event_ids );
		$stops     = array();
		$sum       = array( 'collected' => 0.0, 'platform_fees' => 0.0, 'pending' => 0.0, 'refunds' => 0.0 );
		$inventory = array( 'spots_total' => 0, 'spots_held' => 0 );
		foreach ( $event_ids as $event_id ) {
			$spots_total = 0;
			$spots_held  = 0;
			foreach ( LNKup_Organizer_Onboarding::event_slot_posts( (int) $event_id ) as $opp ) {
				$spots_total += (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true );
				$spots_held  += LNKup_Opportunity_Inventory::holds( $opp->ID );
			}
			$inventory['spots_total'] += $spots_total;
			$inventory['spots_held']  += $spots_held;
			$m = $money['by_event'][ $event_id ] ?? array( 'collected' => 0.0, 'platform_fees' => 0.0, 'pending' => 0.0, 'refunds' => 0.0, 'paid_bookings' => 0 );
			foreach ( $sum as $k => $v ) {
				$sum[ $k ] = $v + $m[ $k ];
			}
			$stops[] = array(
				'event_id'     => (int) $event_id,
				'title'        => lnkup_plain_title( $event_id ),
				'city'         => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'event_date'   => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
				'venue_name'   => (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
				'state'        => LNKup_Portfolio_Tours::stop_state( $event_id ),
				'order'        => (int) get_post_meta( $event_id, '_lnkup_tour_stop_order', true ),
				'applications' => $apps['by_event'][ $event_id ] ?? self::empty_counts(),
				'spots_total'  => $spots_total,
				'spots_held'   => $spots_held,
				'spots_open'   => max( 0, $spots_total - $spots_held ),
				'money'        => self::round_money( $m ),
				'next_deadline'=> self::next_deadline( (int) $event_id ),
				'manage_url'   => add_query_arg( 'event', (int) $event_id, lnkup_page_url_helper( 'organizer-dashboard' ) ),
			);
		}
		usort(
			$stops,
			static function ( $a, $b ) {
				return array( $a['event_date'] ?: '9999', $a['order'] ) <=> array( $b['event_date'] ?: '9999', $b['order'] );
			}
		);
		$totals     = self::round_money( $money['totals'] );
		$sum        = self::round_money( $sum );
		$reconciled = true;
		foreach ( array( 'collected', 'platform_fees', 'pending', 'refunds' ) as $k ) {
			$reconciled = $reconciled && abs( $totals[ $k ] - $sum[ $k ] ) < 0.01;
		}
		return array(
			'tour'         => LNKup_Portfolio_Tours::card( $tour_id ),
			'applications' => $apps['totals'],
			'inventory'    => array_merge( $inventory, array( 'spots_open' => max( 0, $inventory['spots_total'] - $inventory['spots_held'] ) ) ),
			'money'        => array_merge(
				$totals,
				array(
					'paid_bookings'     => (int) $money['totals']['paid_bookings'],
					'organizer_net_est' => round( $totals['collected'] - $totals['platform_fees'] - $totals['refunds'], 2 ),
					'reconciled'        => $reconciled,
					'source'            => 'lnkup_payment',
				)
			),
			'stops'        => $stops,
			'tasks'        => self::tasks( $tour_id, $stops ),
		);
	}

	private static function empty_counts() {
		return array( 'total' => 0, 'pending' => 0, 'approved_unpaid' => 0, 'paid' => 0, 'waitlisted' => 0, 'rejected' => 0, 'cancelled' => 0 );
	}

	/**
	 * Application counts per stop and in total.
	 *
	 * @param int[] $event_ids Stops.
	 */
	private static function application_counts( array $event_ids ) {
		$by_event = array();
		$totals   = self::empty_counts();
		if ( ! $event_ids ) {
			return array( 'by_event' => $by_event, 'totals' => $totals );
		}
		global $wpdb;
		$ids  = implode( ',', array_map( 'absint', $event_ids ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT e.meta_value AS event_id, s.meta_value AS status, COUNT(*) AS n
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft') AND e.meta_value IN ({$ids})
			GROUP BY e.meta_value, s.meta_value",
			ARRAY_A
		);
		$map = array(
			'submitted'        => 'pending',
			'under_review'     => 'pending',
			'info_requested'   => 'pending',
			'approved'         => 'approved_unpaid',
			'payment_required' => 'approved_unpaid',
			'paid'             => 'paid',
			'completed'        => 'paid',
			'waitlisted'       => 'waitlisted',
			'rejected'         => 'rejected',
			'cancelled'        => 'cancelled',
		);
		foreach ( $rows as $row ) {
			$event_id = (int) $row['event_id'];
			$bucket   = $map[ $row['status'] ] ?? null;
			if ( ! isset( $by_event[ $event_id ] ) ) {
				$by_event[ $event_id ] = self::empty_counts();
			}
			$n = (int) $row['n'];
			$by_event[ $event_id ]['total'] += $n;
			$totals['total']                += $n;
			if ( $bucket ) {
				$by_event[ $event_id ][ $bucket ] += $n;
				$totals[ $bucket ]                += $n;
			}
		}
		return array( 'by_event' => $by_event, 'totals' => $totals );
	}

	/**
	 * Money per stop and for the whole tour, each payment counted once.
	 *
	 * @param int[] $event_ids Stops.
	 */
	private static function money_by_event( array $event_ids ) {
		$blank    = array( 'collected' => 0.0, 'platform_fees' => 0.0, 'pending' => 0.0, 'refunds' => 0.0, 'paid_bookings' => 0 );
		$by_event = array();
		$totals   = $blank;
		if ( ! $event_ids ) {
			return array( 'by_event' => $by_event, 'totals' => $totals );
		}
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $event_ids ), 'compare' => 'IN' ),
				),
			)
		);
		$seen = array();
		foreach ( $payments as $payment_id ) {
			$payment_id = (int) $payment_id;
			if ( isset( $seen[ $payment_id ] ) ) {
				continue;
			}
			$seen[ $payment_id ] = true;
			$event_id = (int) get_post_meta( $payment_id, '_lnkup_event_id', true );
			$status   = (string) get_post_meta( $payment_id, '_lnkup_payment_status', true );
			if ( ! isset( $by_event[ $event_id ] ) ) {
				$by_event[ $event_id ] = $blank;
			}
			$row = array( 'collected' => 0.0, 'platform_fees' => 0.0, 'pending' => 0.0, 'paid_bookings' => 0 );
			if ( in_array( $status, self::COLLECTED_STATUSES, true ) ) {
				$row['collected'] = LNKup_Financial::collected_amount( $payment_id );
				if ( 'partially_paid' !== $status ) {
					$row['platform_fees'] = (float) get_post_meta( $payment_id, '_lnkup_lnkup_fee', true );
					$row['paid_bookings'] = 1;
				}
			} elseif ( in_array( $status, self::PENDING_STATUSES, true ) ) {
				$row['pending'] = LNKup_Financial::payment_booth_amount( $payment_id );
			}
			foreach ( $row as $k => $v ) {
				$by_event[ $event_id ][ $k ] += $v;
				$totals[ $k ]                += $v;
			}
		}

		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		if ( LNKup_Financial_Tables::table_exists( $table ) ) {
			$ids  = implode( ',', array_map( 'absint', $event_ids ) );
			$rows = (array) $wpdb->get_results( "SELECT event_id, COALESCE(SUM(amount), 0) AS refunds FROM {$table} WHERE type = 'refund' AND event_id IN ({$ids}) GROUP BY event_id", ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$event_id = (int) $r['event_id'];
				if ( ! isset( $by_event[ $event_id ] ) ) {
					$by_event[ $event_id ] = $blank;
				}
				$amount                           = abs( (float) $r['refunds'] );
				$by_event[ $event_id ]['refunds'] += $amount;
				$totals['refunds']                += $amount;
			}
		}
		return array( 'by_event' => $by_event, 'totals' => $totals );
	}

	/**
	 * @param array $m Money row.
	 */
	private static function round_money( array $m ) {
		foreach ( array( 'collected', 'platform_fees', 'pending', 'refunds' ) as $k ) {
			$m[ $k ] = round( (float) ( $m[ $k ] ?? 0 ), 2 );
		}
		return $m;
	}

	/**
	 * Earliest open application deadline on a stop.
	 *
	 * @param int $event_id Stop.
	 */
	private static function next_deadline( $event_id ) {
		$today = current_time( 'Y-m-d' );
		$next  = '';
		foreach ( LNKup_Organizer_Onboarding::event_slot_posts( $event_id ) as $opp ) {
			$deadline = (string) get_post_meta( $opp->ID, '_lnkup_application_deadline', true );
			if ( $deadline >= $today && ( '' === $next || $deadline < $next ) ) {
				$next = $deadline;
			}
		}
		return $next;
	}

	/**
	 * Outstanding work across the tour.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $stops   Summary stop rows.
	 */
	private static function tasks( $tour_id, array $stops ) {
		$tasks       = array();
		$not_ready   = 0;
		$pending     = 0;
		$unpaid      = 0;
		foreach ( $stops as $stop ) {
			if ( 'draft' === $stop['state'] && ! LNKup_Portfolio_Tours::readiness( $tour_id, $stop['event_id'] )['ready'] ) {
				++$not_ready;
			}
			$pending += $stop['applications']['pending'];
			$unpaid  += $stop['applications']['approved_unpaid'];
		}
		if ( $not_ready ) {
			$tasks[] = array( 'key' => 'stops_not_ready', 'count' => $not_ready, 'message' => sprintf( _n( '%d draft stop still needs details before it can be published.', '%d draft stops still need details before they can be published.', $not_ready, 'lnkup-marketplace' ), $not_ready ) );
		}
		if ( $pending ) {
			$tasks[] = array( 'key' => 'applications_pending', 'count' => $pending, 'message' => sprintf( _n( '%d application is waiting for review.', '%d applications are waiting for review.', $pending, 'lnkup-marketplace' ), $pending ) );
		}
		if ( $unpaid ) {
			$tasks[] = array( 'key' => 'payments_due', 'count' => $unpaid, 'message' => sprintf( _n( '%d approved vendor has not paid yet.', '%d approved vendors have not paid yet.', $unpaid, 'lnkup-marketplace' ), $unpaid ) );
		}
		return $tasks;
	}

	/**
	 * Applications across the tour's stops for the centralized review table.
	 *
	 * @param int   $tour_id Tour.
	 * @param array $filters event_id, status, category, payment (paid|unpaid|none), q, page.
	 */
	public static function applications( $tour_id, array $filters ) {
		$stops    = LNKup_Tours::stop_event_ids( $tour_id );
		$event_id = absint( $filters['event_id'] ?? 0 );
		$ids      = $event_id && in_array( $event_id, $stops, true ) ? array( $event_id ) : $stops;
		$page     = max( 1, absint( $filters['page'] ?? 1 ) );
		if ( ! $ids ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 0, 'page' => $page );
		}
		$meta_query = array(
			array( 'key' => '_lnkup_event_id', 'value' => array_map( 'strval', $ids ), 'compare' => 'IN' ),
		);
		$status = sanitize_key( (string) ( $filters['status'] ?? '' ) );
		if ( '' !== $status ) {
			$groups = array(
				'pending'         => array( 'submitted', 'under_review', 'info_requested' ),
				'approved_unpaid' => array( 'approved', 'payment_required' ),
				'paid'            => array( 'paid', 'completed' ),
			);
			$meta_query[] = array( 'key' => '_lnkup_application_status', 'value' => $groups[ $status ] ?? array( $status ), 'compare' => 'IN' );
		}
		$category = sanitize_text_field( (string) ( $filters['category'] ?? '' ) );
		if ( '' !== $category ) {
			$meta_query[] = array( 'key' => '_lnkup_selected_category', 'value' => $category );
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'pending', 'private', 'draft' ),
				'posts_per_page' => 25,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$items = array();
		foreach ( $query->posts as $app ) {
			$snapshot = json_decode( (string) get_post_meta( $app->ID, '_lnkup_profile_snapshot', true ), true );
			$app_event = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$payment   = self::payment_for_application( $app->ID );
			$items[]   = array(
				'application_id' => (int) $app->ID,
				'event_id'       => $app_event,
				'stop'           => trim( get_post_meta( $app_event, '_lnkup_city', true ) . ' · ' . get_post_meta( $app_event, '_lnkup_event_date', true ), ' ·' ),
				'opportunity'    => lnkup_plain_title( (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true ) ),
				'business_name'  => is_array( $snapshot ) ? (string) ( $snapshot['business_name'] ?? '' ) : '',
				'vendor_profile_id' => (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true ),
				'vendor_user_id' => (int) get_post_meta( $app->ID, '_lnkup_vendor_user_id', true ),
				'status'         => (string) get_post_meta( $app->ID, '_lnkup_application_status', true ),
				'category'       => (string) get_post_meta( $app->ID, '_lnkup_selected_category', true ),
				'payment_status' => $payment ? (string) get_post_meta( $payment, '_lnkup_payment_status', true ) : '',
				'documents_missing' => (bool) get_post_meta( $app->ID, '_lnkup_documents_missing', true ),
				'group_id'       => (string) get_post_meta( $app->ID, '_lnkup_submission_group_id', true ),
				'submitted_at'   => (string) get_post_meta( $app->ID, '_lnkup_submitted_at', true ),
			);
		}
		$payment = sanitize_key( (string) ( $filters['payment'] ?? '' ) );
		if ( '' !== $payment ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( $row ) use ( $payment ) {
						if ( 'none' === $payment ) {
							return '' === $row['payment_status'];
						}
						$paid = in_array( $row['payment_status'], array( 'paid', 'partially_refunded', 'disputed', 'refunded' ), true );
						return 'paid' === $payment ? $paid : ( '' !== $row['payment_status'] && ! $paid );
					}
				)
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'page'  => $page,
		);
	}

	/**
	 * @param int $app_id Application.
	 */
	private static function payment_for_application( $app_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_application_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) absint( $app_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Bulk decision on applications across stops. Each application is validated against this tour
	 * and goes through LNKup_Applications::handle_organizer_action() (ownership, transition rules,
	 * inventory locks and payment creation stay there). One failure never stops the others.
	 *
	 * @param int    $tour_id  Tour.
	 * @param int[]  $app_ids  Applications.
	 * @param string $action   approve|reject|waitlist|review|request_info.
	 * @param int    $user_id  Acting organizer.
	 * @param array  $extra    info_message for request_info.
	 */
	public static function bulk_action( $tour_id, array $app_ids, $action, $user_id, array $extra = array() ) {
		$allowed = array( 'approve', 'reject', 'waitlist', 'review', 'request_info' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return new WP_Error( 'lnkup_invalid_action', __( 'Choose a supported action.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$app_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $app_ids ) ) ) ), 0, 100 );
		if ( ! $app_ids ) {
			return new WP_Error( 'lnkup_no_applications', __( 'Select at least one application.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$stops   = LNKup_Tours::stop_event_ids( $tour_id );
		$results = array();
		foreach ( $app_ids as $app_id ) {
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			if ( 'lnkup_application' !== get_post_type( $app_id ) || ! in_array( $event_id, $stops, true ) ) {
				$results[] = array( 'application_id' => $app_id, 'result' => 'failed', 'message' => __( 'This application is not part of the tour.', 'lnkup-marketplace' ) );
				continue;
			}
			$done = LNKup_Applications::handle_organizer_action( $app_id, $action, (int) $user_id, $extra );
			if ( is_wp_error( $done ) ) {
				$results[] = array( 'application_id' => $app_id, 'result' => 'failed', 'message' => $done->get_error_message() );
				continue;
			}
			$results[] = array( 'application_id' => $app_id, 'result' => 'ok', 'status' => (string) get_post_meta( $app_id, '_lnkup_application_status', true ) );
		}
		$tally = array_count_values( wp_list_pluck( $results, 'result' ) );
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'bulk_application_' . $action, array( 'ok' => (int) ( $tally['ok'] ?? 0 ), 'failed' => (int) ( $tally['failed'] ?? 0 ) ), (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) );
		return array(
			'results' => $results,
			'ok'      => (int) ( $tally['ok'] ?? 0 ),
			'failed'  => (int) ( $tally['failed'] ?? 0 ),
		);
	}
}
