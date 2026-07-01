<?php
/**
 * Event risk detection engine.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Risk_Engine {

	public static function levels() {
		return array( 'low', 'medium', 'high', 'critical' );
	}

	/**
	 * Evaluate risks for event and persist alerts.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function evaluate_event( $event_id ) {
		$event_id = absint( $event_id );
		$alerts   = array_merge(
			self::compliance_risks( $event_id ),
			self::payment_risks( $event_id ),
			self::operations_risks( $event_id ),
			self::application_risks( $event_id )
		);
		self::sync_alerts( 'event', $event_id, $alerts );
		return $alerts;
	}

	/**
	 * Get open alerts for scope.
	 *
	 * @param string $scope_type Scope type.
	 * @param int    $scope_id   Scope ID.
	 */
	public static function get_alerts( $scope_type, $scope_id ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::risk_alerts_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scope_type = %s AND scope_id = %d AND status = 'open' ORDER BY FIELD(level,'critical','high','medium','low'), created_at DESC",
				sanitize_key( $scope_type ),
				absint( $scope_id )
			),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public static function refresh_all_events() {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'post_status'    => 'publish',
			)
		);
		foreach ( $events as $event_id ) {
			self::evaluate_event( $event_id );
		}
	}

	private static function compliance_risks( $event_id ) {
		$alerts = array();
		if ( ! class_exists( 'LNKup_Event_Operations' ) ) {
			return $alerts;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		foreach ( LNKup_Event_Operations::compliance_alerts( $event_id, $apps ) as $item ) {
			$alerts[] = array(
				'level'       => ( 'high' === ( $item['severity'] ?? '' ) ) ? 'high' : 'medium',
				'category'    => 'compliance',
				'message'     => $item['message'] ?? '',
				'action_text' => __( 'Review vendor readiness', 'lnkup-marketplace' ),
				'action_key'  => 'view_compliance',
			);
		}
		return $alerts;
	}

	private static function payment_risks( $event_id ) {
		$alerts = array();
		if ( ! class_exists( 'LNKup_Funnel_Analytics' ) ) {
			return $alerts;
		}
		$funnel = LNKup_Funnel_Analytics::payment_funnel( $event_id );
		$unpaid = max( 0, (int) $funnel['approved_vendors'] - (int) $funnel['payment_completed'] );
		$days   = self::days_until_event( $event_id );
		if ( $unpaid > 0 && $days <= 7 ) {
			$level = $days <= 3 ? 'critical' : 'high';
			$alerts[] = array(
				'level'       => $level,
				'category'    => 'payment',
				'message'     => sprintf(
					/* translators: 1: unpaid count 2: days */
					__( '%1$d approved vendors are unpaid and event starts in %2$d days.', 'lnkup-marketplace' ),
					$unpaid,
					max( 0, $days )
				),
				'action_text' => __( 'Send payment reminder', 'lnkup-marketplace' ),
				'action_key'  => 'send_payment_reminder',
				'deadline_at' => get_post_meta( $event_id, '_lnkup_event_date', true ),
			);
		}
		if ( ! empty( $funnel['completion_rate'] ) && $funnel['completion_rate'] < 60 && $funnel['approved_vendors'] > 2 ) {
			$alerts[] = array(
				'level'       => 'medium',
				'category'    => 'payment',
				'message'     => __( 'Payment completion is below average for this event.', 'lnkup-marketplace' ),
				'action_text' => __( 'Review payment funnel', 'lnkup-marketplace' ),
				'action_key'  => 'view_payment_funnel',
			);
		}
		return $alerts;
	}

	private static function operations_risks( $event_id ) {
		$alerts = array();
		if ( ! class_exists( 'LNKup_Ops_Analytics' ) ) {
			return $alerts;
		}
		$ops = LNKup_Ops_Analytics::for_event( $event_id );
		if ( ! empty( $ops['open_support'] ) && (int) $ops['open_support'] >= 3 ) {
			$alerts[] = array(
				'level'       => 'medium',
				'category'    => 'support',
				'message'     => sprintf( __( '%d open support requests.', 'lnkup-marketplace' ), (int) $ops['open_support'] ),
				'action_text' => __( 'Open support queue', 'lnkup-marketplace' ),
				'action_key'  => 'view_support',
			);
		}
		if ( ! empty( $ops['open_incidents'] ) ) {
			$alerts[] = array(
				'level'       => 'high',
				'category'    => 'incidents',
				'message'     => sprintf( __( '%d open incidents.', 'lnkup-marketplace' ), (int) $ops['open_incidents'] ),
				'action_text' => __( 'Review incidents', 'lnkup-marketplace' ),
				'action_key'  => 'view_incidents',
			);
		}
		$days = self::days_until_event( $event_id );
		if ( $days <= 1 && class_exists( 'LNKup_Funnel_Analytics' ) ) {
			$funnel = LNKup_Funnel_Analytics::application_funnel( $event_id );
			if ( $funnel['paid'] > 0 && $funnel['checked_in'] < max( 1, $funnel['paid'] * 0.2 ) ) {
				$alerts[] = array(
					'level'       => 'high',
					'category'    => 'checkin',
					'message'     => __( 'No check-in progress close to event start.', 'lnkup-marketplace' ),
					'action_text' => __( 'Open QR scan', 'lnkup-marketplace' ),
					'action_key'  => 'open_qr_scan',
				);
			}
		}
		return $alerts;
	}

	private static function application_risks( $event_id ) {
		$alerts = array();
		$opp_id = self::primary_opportunity( $event_id );
		if ( ! $opp_id ) {
			return $alerts;
		}
		$views = (int) get_post_meta( $opp_id, '_lnkup_view_count', true );
		$apps  = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		if ( $views > 15 && count( $apps ) < 3 ) {
			$alerts[] = array(
				'level'       => 'medium',
				'category'    => 'applications',
				'message'     => __( 'Low application volume despite opportunity views.', 'lnkup-marketplace' ),
				'action_text' => __( 'Improve opportunity listing', 'lnkup-marketplace' ),
				'action_key'  => 'improve_opportunity',
			);
		}
		return $alerts;
	}

	private static function sync_alerts( $scope_type, $scope_id, $alerts ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::risk_alerts_table();
		$wpdb->update(
			$table,
			array( 'status' => 'resolved', 'updated_at' => current_time( 'mysql' ) ),
			array(
				'scope_type' => sanitize_key( $scope_type ),
				'scope_id'   => absint( $scope_id ),
				'status'     => 'open',
			)
		);
		foreach ( $alerts as $alert ) {
			$wpdb->insert(
				$table,
				array(
					'scope_type'  => sanitize_key( $scope_type ),
					'scope_id'    => absint( $scope_id ),
					'level'       => sanitize_key( $alert['level'] ?? 'medium' ),
					'category'    => sanitize_key( $alert['category'] ?? 'general' ),
					'message'     => sanitize_text_field( $alert['message'] ?? '' ),
					'action_text' => sanitize_text_field( $alert['action_text'] ?? '' ),
					'action_key'  => sanitize_key( $alert['action_key'] ?? '' ),
					'status'      => 'open',
					'deadline_at' => ! empty( $alert['deadline_at'] ) ? $alert['deadline_at'] : null,
					'created_at'  => current_time( 'mysql' ),
					'updated_at'  => current_time( 'mysql' ),
				)
			);
		}
	}

	private static function days_until_event( $event_id ) {
		$date = get_post_meta( $event_id, '_lnkup_event_date', true );
		if ( ! $date ) {
			return 999;
		}
		return (int) floor( ( strtotime( $date ) - time() ) / DAY_IN_SECONDS );
	}

	private static function primary_opportunity( $event_id ) {
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
		return $opps ? (int) $opps[0] : 0;
	}
}
