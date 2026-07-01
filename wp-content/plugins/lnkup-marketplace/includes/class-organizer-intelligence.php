<?php
/**
 * Organizer performance intelligence.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Intelligence {

	/**
	 * Organizer summary DTO.
	 *
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function summary( $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		$cached = get_transient( 'lnkup_intel_org_' . $organizer_id );
		if ( false !== $cached ) {
			return $cached;
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		$apps_total = 0;
		$approved = 0;
		$paid = 0;
		$scores = array();
		$top_events = array();
		$weak_events = array();
		foreach ( $events as $event_id ) {
			$apps = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => $event_id,
				)
			);
			$apps_total += count( $apps );
			foreach ( $apps as $app_id ) {
				$status = get_post_meta( $app_id, '_lnkup_application_status', true );
				if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
					$approved++;
				}
				if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
					$paid++;
				}
			}
			if ( class_exists( 'LNKup_Event_Score' ) ) {
				$sc = LNKup_Event_Score::compute( $event_id );
				$scores[] = $sc['score'];
				$row = array(
					'event_id' => $event_id,
					'title'    => get_the_title( $event_id ),
					'score'    => $sc['score'],
					'label'    => $sc['label'],
				);
				$top_events[] = $row;
				$weak_events[] = $row;
			}
		}
		usort(
			$top_events,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		usort(
			$weak_events,
			function ( $a, $b ) {
				return $a['score'] <=> $b['score'];
			}
		);
		$revenue = class_exists( 'LNKup_Revenue_Intelligence' ) ? LNKup_Revenue_Intelligence::for_organizer( $organizer_id ) : array();
		$data = array(
			'organizer_id'           => $organizer_id,
			'total_events'           => count( $events ),
			'active_opportunities'   => count( $opps ),
			'applications_received'  => $apps_total,
			'approved_vendors'       => $approved,
			'paid_vendors'           => $paid,
			'payment_completion_rate'=> $approved ? round( ( $paid / $approved ) * 100, 1 ) : 0,
			'event_score_average'    => $scores ? round( array_sum( $scores ) / count( $scores ), 1 ) : null,
			'revenue'                => $revenue,
			'top_events'             => array_slice( $top_events, 0, 5 ),
			'weak_events'            => array_slice( $weak_events, 0, 5 ),
			'category_trends'        => class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::categories( array( 'organizer_id' => $organizer_id, 'limit' => 5 ) ) : array(),
			'city_trends'            => class_exists( 'LNKup_Market_Trends' ) ? LNKup_Market_Trends::cities( array( 'organizer_id' => $organizer_id, 'limit' => 5 ) ) : array(),
			'recommended_actions'    => self::aggregate_actions( $events, $organizer_id ),
		);
		set_transient( 'lnkup_intel_org_' . $organizer_id, $data, 15 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * Marketplace health for organizer-scoped view.
	 *
	 * @param int $organizer_id Organizer ID.
	 */
	public static function health( $organizer_id = 0 ) {
		$summary = self::summary( $organizer_id );
		$score   = (int) ( $summary['event_score_average'] ?? 70 );
		return array(
			'score'  => $score,
			'label'  => $score >= 80 ? __( 'Healthy', 'lnkup-marketplace' ) : ( $score >= 60 ? __( 'Stable', 'lnkup-marketplace' ) : __( 'Needs Attention', 'lnkup-marketplace' ) ),
			'kpis'   => array(
				'events'       => $summary['total_events'],
				'applications' => $summary['applications_received'],
				'paid'         => $summary['paid_vendors'],
				'revenue'      => $summary['revenue']['total_revenue'] ?? 0,
			),
		);
	}

	private static function aggregate_actions( $events, $organizer_id ) {
		$all = array();
		if ( ! class_exists( 'LNKup_Recommended_Actions' ) ) {
			return $all;
		}
		foreach ( array_slice( $events, 0, 10 ) as $event_id ) {
			foreach ( LNKup_Recommended_Actions::for_event( $event_id, $organizer_id ) as $action ) {
				$all[] = $action;
			}
		}
		usort(
			$all,
			function ( $a, $b ) {
				$order = array( 'critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1 );
				return ( $order[ $b['priority'] ] ?? 0 ) <=> ( $order[ $a['priority'] ] ?? 0 );
			}
		);
		return array_slice( $all, 0, 8 );
	}

	/**
	 * Rule-based event intelligence summary for dashboard widgets.
	 *
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function event_intelligence_summary( $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( ! $organizer_id ) {
			return array(
				'kpis'     => array(),
				'insights' => array( __( 'More data needed.', 'lnkup-marketplace' ) ),
				'events'   => array(),
				'warnings' => array(),
			);
		}

		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);

		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);

		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);

		$pending_apps    = 0;
		$approved_vendors = 0;
		$paid_vendors    = 0;
		$apps_by_event   = array();

		foreach ( $apps as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
				$pending_apps++;
			}
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$approved_vendors++;
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$paid_vendors++;
			}
			$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
			if ( $event_id ) {
				$apps_by_event[ $event_id ] = ( $apps_by_event[ $event_id ] ?? 0 ) + 1;
			}
		}

		$pending_payments = 0;
		$estimated_gmv    = 0.0;
		$payments         = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => array( 'publish', 'private' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		foreach ( $payments as $payment_id ) {
			$pay_status = get_post_meta( $payment_id, '_lnkup_payment_status', true );
			$amount     = (float) get_post_meta( $payment_id, '_lnkup_total_amount', true );
			if ( in_array( $pay_status, array( 'pending', 'payment_required' ), true ) ) {
				$pending_payments++;
			}
			if ( in_array( $pay_status, array( 'paid', 'completed' ), true ) ) {
				$estimated_gmv += $amount;
			}
		}

		if ( class_exists( 'LNKup_Revenue_Intelligence' ) ) {
			$rev = LNKup_Revenue_Intelligence::for_organizer( $organizer_id );
			if ( ! empty( $rev['total_revenue'] ) ) {
				$estimated_gmv = (float) $rev['total_revenue'];
			}
		}

		$top_event_id    = 0;
		$top_event_count = 0;
		if ( $apps_by_event ) {
			arsort( $apps_by_event );
			$top_event_id    = (int) key( $apps_by_event );
			$top_event_count = (int) current( $apps_by_event );
		}

		$event_rows   = array();
		$warnings     = array();
		$dash_url     = lnkup_page_url_helper( 'organizer-dashboard' );

		foreach ( $events as $event_id ) {
			$app_count  = $apps_by_event[ $event_id ] ?? 0;
			$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
			$event_rows[] = array(
				'event_id'     => $event_id,
				'title'        => get_the_title( $event_id ),
				'applications' => $app_count,
				'date'         => $event_date,
				'url'          => add_query_arg( 'event', $event_id, $dash_url ),
			);
			if ( $app_count < 3 ) {
				$warnings[] = array(
					'level'   => 'medium',
					'message' => sprintf(
						/* translators: %s: event title */
						__( '%s has fewer than 3 applications — consider promoting the opportunity.', 'lnkup-marketplace' ),
						get_the_title( $event_id )
					),
					'event_id' => $event_id,
				);
			}
		}

		usort(
			$event_rows,
			function ( $a, $b ) {
				return $b['applications'] <=> $a['applications'];
			}
		);
		$event_rows = array_slice( $event_rows, 0, 5 );

		if ( $pending_apps > 0 ) {
			$warnings[] = array(
				'level'   => 'high',
				'message' => sprintf(
					/* translators: %d: pending application count */
					_n( '%d application awaiting review.', '%d applications awaiting review.', $pending_apps, 'lnkup-marketplace' ),
					$pending_apps
				),
			);
		}

		if ( $pending_payments > 0 ) {
			$warnings[] = array(
				'level'   => 'medium',
				'message' => sprintf(
					/* translators: %d: pending payment count */
					_n( '%d payment still pending.', '%d payments still pending.', $pending_payments, 'lnkup-marketplace' ),
					$pending_payments
				),
			);
		}

		$kpis = array(
			array(
				'key'   => 'total_events',
				'label' => __( 'Total events', 'lnkup-marketplace' ),
				'value' => count( $events ),
			),
			array(
				'key'   => 'open_opportunities',
				'label' => __( 'Open opportunities', 'lnkup-marketplace' ),
				'value' => count( $opps ),
			),
			array(
				'key'   => 'total_applications',
				'label' => __( 'Total applications', 'lnkup-marketplace' ),
				'value' => count( $apps ),
			),
			array(
				'key'   => 'pending_applications',
				'label' => __( 'Pending applications', 'lnkup-marketplace' ),
				'value' => $pending_apps,
			),
			array(
				'key'   => 'approved_vendors',
				'label' => __( 'Approved vendors', 'lnkup-marketplace' ),
				'value' => $approved_vendors,
			),
			array(
				'key'   => 'paid_vendors',
				'label' => __( 'Paid vendors', 'lnkup-marketplace' ),
				'value' => $paid_vendors,
			),
			array(
				'key'   => 'pending_payments',
				'label' => __( 'Pending payments', 'lnkup-marketplace' ),
				'value' => $pending_payments,
			),
			array(
				'key'   => 'estimated_gmv',
				'label' => __( 'Estimated GMV', 'lnkup-marketplace' ),
				'value' => function_exists( 'lnkup_currency' ) ? lnkup_currency( $estimated_gmv ) : number_format( $estimated_gmv, 2 ),
			),
		);

		$insights = array();
		if ( $top_event_id ) {
			$insights[] = sprintf(
				/* translators: 1: event title, 2: application count */
				__( 'Top event: %1$s with %2$d applications.', 'lnkup-marketplace' ),
				get_the_title( $top_event_id ),
				$top_event_count
			);
		}
		if ( ! $events ) {
			$insights[] = __( 'Create your first event to unlock intelligence insights.', 'lnkup-marketplace' );
		} elseif ( ! $apps ) {
			$insights[] = __( 'More data needed — publish opportunities to start receiving applications.', 'lnkup-marketplace' );
		}
		if ( $approved_vendors && ! $paid_vendors ) {
			$insights[] = __( 'Approved vendors are waiting on payment — follow up to improve collection.', 'lnkup-marketplace' );
		}

		return array(
			'kpis'     => $kpis,
			'insights' => $insights,
			'events'   => $event_rows,
			'warnings' => array_slice( $warnings, 0, 6 ),
		);
	}
}
