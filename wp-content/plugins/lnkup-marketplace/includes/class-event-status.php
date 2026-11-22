<?php
/**
 * Organizer event cancellation and postponement.
 *
 * Sets `_lnkup_event_status` to cancelled/postponed and closes open vendor slots.
 * Payments are never touched here — refunds stay in the Financial Service.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Status {

	const NS = 'lnkup/v1';

	/**
	 * Listing statuses that sync_event_listing_status() must not overwrite.
	 */
	const TERMINAL = array( 'cancelled', 'postponed' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/organizer/events/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_set_status' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array(
						'required' => true,
						'enum'     => array( 'cancelled', 'postponed', 'reinstated' ),
					),
					'note'   => array( 'sanitize_callback' => 'sanitize_textarea_field' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_manage( WP_REST_Request $request ) {
		$event_id = absint( $request['id'] );
		return is_user_logged_in()
			&& 'lnkup_event' === get_post_type( $event_id )
			&& LNKup_Capabilities::organizer_owns_event( $event_id, get_current_user_id() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_set_status( WP_REST_Request $request ) {
		$result = self::set_status( absint( $request['id'] ), sanitize_key( (string) $request['status'] ), (string) $request['note'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response(
			array(
				'event_id' => absint( $request['id'] ),
				'status'   => $result,
			)
		);
	}

	/**
	 * @param int    $event_id Event ID.
	 * @param string $status   cancelled|postponed|reinstated.
	 * @param string $note     Organizer note shown to vendors.
	 * @param int    $actor    Acting user.
	 * @return string|WP_Error New listing status.
	 */
	public static function set_status( $event_id, $status, $note, $actor ) {
		$event_id = absint( $event_id );
		if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'lnkup_invalid_event', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$current = (string) get_post_meta( $event_id, '_lnkup_event_status', true );

		if ( 'reinstated' === $status ) {
			if ( ! in_array( $current, self::TERMINAL, true ) ) {
				return new WP_Error( 'lnkup_invalid_status', __( 'This event is not cancelled or postponed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
			delete_post_meta( $event_id, '_lnkup_event_status_note' );
			// Leave slots closed; the organizer reopens them deliberately.
			update_post_meta( $event_id, '_lnkup_event_status', 'draft' );
			$new = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::sync_event_listing_status( $event_id ) : 'draft';
			self::log( $event_id, 'event_reinstated', $actor );
			return $new;
		}

		if ( ! in_array( $status, self::TERMINAL, true ) ) {
			return new WP_Error( 'lnkup_invalid_status', __( 'Unsupported event status.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'cancelled' === $current ) {
			return new WP_Error( 'lnkup_invalid_status', __( 'This event is already cancelled.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}

		update_post_meta( $event_id, '_lnkup_event_status_note', sanitize_textarea_field( $note ) );
		update_post_meta( $event_id, '_lnkup_event_status_changed_at', current_time( 'mysql' ) );
		update_post_meta( $event_id, '_lnkup_event_status', $status );

		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $opps as $opp_id ) {
			update_post_meta( (int) $opp_id, '_lnkup_opportunity_status', 'closed' );
			if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
				LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
			}
		}

		self::log( $event_id, 'event_' . $status, $actor );
		do_action( 'lnkup_event_cancelled', $event_id, $status, sanitize_textarea_field( $note ) );
		return $status;
	}

	/**
	 * @param int    $event_id Event ID.
	 * @param string $action   Log action.
	 * @param int    $actor    User.
	 */
	private static function log( $event_id, $action, $actor ) {
		if ( class_exists( 'LNKup_Operations_Log' ) ) {
			LNKup_Operations_Log::log( $event_id, $action, array( 'actor' => absint( $actor ) ) );
		}
	}
}
