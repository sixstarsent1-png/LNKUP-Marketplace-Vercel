<?php
/**
 * Organizer CRM bootstrap and shared helpers.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_CRM {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ), 10, 1 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status_changed' ), 10, 3 );
	}

	/**
	 * Ensure organizer-vendor relationship row exists.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 * @param int $profile_id   Vendor profile post ID.
	 */
	public static function ensure_relationship( $organizer_id, $vendor_id, $profile_id = 0 ) {
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		$organizer_id = absint( $organizer_id );
		$vendor_id    = absint( $vendor_id );
		if ( ! $organizer_id || ! $vendor_id ) {
			return false;
		}
		if ( ! $profile_id ) {
			$profile_id = lnkup_get_vendor_profile_id( $vendor_id );
		}
		$now = current_time( 'mysql' );
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE organizer_user_id = %d AND vendor_user_id = %d",
				$organizer_id,
				$vendor_id
			)
		);
		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'vendor_profile_id' => absint( $profile_id ),
					'last_activity_at'    => $now,
				),
				array( 'id' => $existing ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing;
		}
		$wpdb->insert(
			$table,
			array(
				'organizer_user_id'  => $organizer_id,
				'vendor_user_id'     => $vendor_id,
				'vendor_profile_id'  => absint( $profile_id ),
				'is_favorite'        => 0,
				'is_preferred'       => 0,
				'first_connected_at' => $now,
				'last_activity_at'   => $now,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get relationship row.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 */
	public static function get_relationship( $organizer_id, $vendor_id ) {
		global $wpdb;
		$table = LNKup_Organizer_CRM_Tables::rel_table();
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE organizer_user_id = %d AND vendor_user_id = %d",
				absint( $organizer_id ),
				absint( $vendor_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Log CRM activity.
	 *
	 * @param string $type           Activity type.
	 * @param int    $organizer_id   Organizer user ID.
	 * @param int    $vendor_id      Vendor user ID.
	 * @param array  $args           Optional event_id, application_id, payload.
	 */
	public static function log_activity( $type, $organizer_id, $vendor_id, $args = array() ) {
		global $wpdb;
		if ( ! $organizer_id || ! $vendor_id ) {
			return;
		}
		self::ensure_relationship( $organizer_id, $vendor_id, $args['profile_id'] ?? 0 );
		$wpdb->insert(
			LNKup_Organizer_CRM_Tables::activity_table(),
			array(
				'organizer_user_id' => absint( $organizer_id ),
				'vendor_user_id'    => absint( $vendor_id ),
				'event_id'          => absint( $args['event_id'] ?? 0 ),
				'application_id'    => absint( $args['application_id'] ?? 0 ),
				'activity_type'     => sanitize_key( $type ),
				'payload'           => wp_json_encode( $args['payload'] ?? array() ),
				'actor_user_id'     => absint( $args['actor_user_id'] ?? get_current_user_id() ),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Hook: application submitted.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_application_submitted( $app_id ) {
		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$vendor_id    = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$event_id     = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$profile_id   = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		self::ensure_relationship( $organizer_id, $vendor_id, $profile_id );
		self::log_activity(
			'applied',
			$organizer_id,
			$vendor_id,
			array(
				'event_id'        => $event_id,
				'application_id'  => $app_id,
				'profile_id'      => $profile_id,
				'payload'         => array( 'status' => 'submitted' ),
			)
		);
	}

	/**
	 * Hook: application status changed.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $prev   Previous status.
	 * @param string $status New status.
	 */
	public static function on_application_status_changed( $app_id, $prev, $status ) {
		$map = array(
			'approved'         => 'approved',
			'payment_required' => 'payment_requested',
			'rejected'         => 'rejected',
			'waitlisted'       => 'waitlisted',
			'paid'             => 'payment_completed',
			'under_review'     => 'under_review',
			'completed'        => 'event_completed',
		);
		$type = $map[ $status ] ?? 'status_changed';
		$organizer_id = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$vendor_id    = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		self::log_activity(
			$type,
			$organizer_id,
			$vendor_id,
			array(
				'event_id'       => (int) get_post_meta( $app_id, '_lnkup_event_id', true ),
				'application_id' => $app_id,
				'payload'        => array( 'from' => $prev, 'to' => $status ),
			)
		);
		if ( 'paid' === $status && class_exists( 'LNKup_Vendor_Readiness' ) ) {
			LNKup_Vendor_Readiness::refresh( $app_id );
		}
	}

	/**
	 * Backfill relationships from existing applications.
	 */
	public static function backfill_relationships() {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		foreach ( $apps as $app_id ) {
			self::on_application_submitted( $app_id );
		}
	}
}
