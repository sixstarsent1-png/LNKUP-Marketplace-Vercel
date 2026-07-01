<?php
/**
 * Recommended actions engine.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Recommended_Actions {

	/**
	 * Get actions for event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  Organizer user ID.
	 */
	public static function for_event( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$actions = array();
		$risks   = LNKup_Risk_Engine::get_alerts( 'event', $event_id );
		foreach ( $risks as $risk ) {
			$actions[] = array(
				'priority'   => $risk['level'],
				'action_key' => $risk['action_key'],
				'title'      => $risk['action_text'] ?: $risk['message'],
				'reason'     => $risk['message'],
				'deadline'   => $risk['deadline_at'],
				'cta'        => self::cta_for_key( $risk['action_key'], $event_id ),
			);
		}
		if ( class_exists( 'LNKup_Opportunity_Score' ) ) {
			$opp_id = self::primary_opportunity( $event_id );
			if ( $opp_id ) {
				$quality = LNKup_Opportunity_Score::compute( $opp_id );
				if ( $quality && $quality['quality_score'] < 60 && ! empty( $quality['issues'] ) ) {
					$actions[] = array(
						'priority'   => 'medium',
						'action_key' => 'improve_opportunity',
						'title'      => __( 'Improve opportunity listing', 'lnkup-marketplace' ),
						'reason'     => $quality['issues'][0],
						'deadline'   => null,
						'cta'        => self::cta_for_key( 'improve_opportunity', $event_id ),
					);
				}
			}
		}
		$funnel = class_exists( 'LNKup_Funnel_Analytics' ) ? LNKup_Funnel_Analytics::application_funnel( $event_id ) : array();
		if ( ! empty( $funnel['waitlisted'] ) && $funnel['waitlisted'] > 0 ) {
			$actions[] = array(
				'priority'   => 'low',
				'action_key' => 'approve_waitlisted',
				'title'      => __( 'Approve waitlisted vendors', 'lnkup-marketplace' ),
				'reason'     => sprintf( __( '%d vendors are waitlisted.', 'lnkup-marketplace' ), $funnel['waitlisted'] ),
				'deadline'   => null,
				'cta'        => self::cta_for_key( 'approve_waitlisted', $event_id ),
			);
		}
		self::persist_for_user( $user_id, 'event', $event_id, $actions );
		return $actions;
	}

	public static function refresh_all() {
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);
		foreach ( $events as $event_id ) {
			$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
			if ( $organizer ) {
				LNKup_Risk_Engine::evaluate_event( $event_id );
				self::for_event( $event_id, $organizer );
			}
		}
	}

	private static function cta_for_key( $key, $event_id ) {
		$dash = lnkup_page_url_helper( 'organizer-dashboard' );
		$map  = array(
			'send_payment_reminder' => array( 'label' => __( 'Send reminder', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) . '#comms' ),
			'view_payment_funnel'   => array( 'label' => __( 'View funnel', 'lnkup-marketplace' ), 'url' => $dash . '#intelligence' ),
			'view_support'          => array( 'label' => __( 'Support queue', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) . '#support' ),
			'view_incidents'        => array( 'label' => __( 'Incidents', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) . '#incidents' ),
			'open_qr_scan'          => array( 'label' => __( 'QR scan', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $event_id, 'scan' => 1 ), $dash ) ),
			'improve_opportunity'   => array( 'label' => __( 'Edit event', 'lnkup-marketplace' ), 'url' => $dash . '#events' ),
			'approve_waitlisted'    => array( 'label' => __( 'Review apps', 'lnkup-marketplace' ), 'url' => $dash . '#applications' ),
			'view_compliance'       => array( 'label' => __( 'Compliance', 'lnkup-marketplace' ), 'url' => add_query_arg( array( 'event' => $event_id, 'ops' => 1 ), $dash ) ),
		);
		return $map[ $key ] ?? array( 'label' => __( 'View', 'lnkup-marketplace' ), 'url' => $dash . '#intelligence' );
	}

	private static function persist_for_user( $user_id, $scope_type, $scope_id, $actions ) {
		global $wpdb;
		$table = LNKup_Intelligence_Tables::recommendations_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND scope_type = %s AND scope_id = %d AND status = 'open'",
				absint( $user_id ),
				sanitize_key( $scope_type ),
				absint( $scope_id )
			)
		);
		foreach ( $actions as $action ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'     => absint( $user_id ),
					'scope_type'  => sanitize_key( $scope_type ),
					'scope_id'    => absint( $scope_id ),
					'priority'    => sanitize_key( $action['priority'] ?? 'medium' ),
					'action_key'  => sanitize_key( $action['action_key'] ?? '' ),
					'reason'      => sanitize_text_field( $action['reason'] ?? '' ),
					'payload'     => wp_json_encode( $action ),
					'status'      => 'open',
					'expires_at'  => ! empty( $action['deadline'] ) ? $action['deadline'] : null,
					'created_at'  => current_time( 'mysql' ),
				)
			);
		}
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
