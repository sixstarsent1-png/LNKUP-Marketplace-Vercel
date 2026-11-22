<?php
/**
 * Event authenticity: who controls a listing, and whether it may take bookings.
 *
 * Status lives on the event as `_lnkup_authenticity_status`. Events without the
 * meta (everything created before 12.28.0 and every organizer-created event)
 * are treated as organizer_controlled, so existing listings keep working.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Authenticity {

	const META = '_lnkup_authenticity_status';

	const UNCLAIMED_EXTERNAL      = 'unclaimed_external';
	const CLAIM_PENDING           = 'claim_pending';
	const ORGANIZER_CONTROLLED    = 'organizer_controlled';
	const ADDITIONAL_VERIFICATION = 'additional_verification';
	const DISPUTED                = 'disputed';
	const RESTRICTED              = 'restricted';

	const STATUSES = array(
		self::UNCLAIMED_EXTERNAL,
		self::CLAIM_PENDING,
		self::ORGANIZER_CONTROLLED,
		self::ADDITIONAL_VERIFICATION,
		self::DISPUTED,
		self::RESTRICTED,
	);

	/**
	 * Statuses that may take applications and payments when the publish gate is on.
	 * additional_verification keeps a verified listing live while staff review a change.
	 */
	const BOOKABLE = array( self::ORGANIZER_CONTROLLED, self::ADDITIONAL_VERIFICATION );

	public static function init() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_event_meta_changed' ), 10, 4 );
		add_action( 'lnkup_connect_account_changed', array( __CLASS__, 'on_connect_account_changed' ), 10, 4 );
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'on_event_saved' ), 30, 3 );
	}

	/**
	 * @param int $event_id Event ID.
	 */
	public static function get_status( $event_id ) {
		$status = (string) get_post_meta( absint( $event_id ), self::META, true );
		return in_array( $status, self::STATUSES, true ) ? $status : self::ORGANIZER_CONTROLLED;
	}

	/**
	 * Whether the event may take new applications and payments.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function is_bookable( $event_id ) {
		if ( ! LNKup_Trust::flag( 'trust_publish_gate' ) ) {
			return self::RESTRICTED !== self::get_status( $event_id );
		}
		return in_array( self::get_status( $event_id ), self::BOOKABLE, true );
	}

	/**
	 * Change status with an audit record and re-index the event's opportunities.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $status   New status.
	 * @param string $reason   Reason.
	 * @param int    $actor    Actor user ID.
	 * @return true|WP_Error
	 */
	public static function set_status( $event_id, $status, $reason = '', $actor = 0 ) {
		$event_id = absint( $event_id );
		if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
			return new WP_Error( 'lnkup_invalid_event', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'lnkup_invalid_authenticity_status', __( 'Unknown listing status.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$prev = self::get_status( $event_id );
		if ( $prev === $status && '' !== (string) get_post_meta( $event_id, self::META, true ) ) {
			return true;
		}
		update_post_meta( $event_id, self::META, $status );
		update_post_meta( $event_id, '_lnkup_authenticity_updated_at', current_time( 'mysql', true ) );
		LNKup_Audit_Log::log(
			'event_authenticity_changed',
			'event',
			$event_id,
			array(
				'actor_user_id' => $actor ? absint( $actor ) : get_current_user_id(),
				'prev'          => array( 'status' => $prev ),
				'new'           => array( 'status' => $status ),
				'reason'        => $reason,
			)
		);
		self::reindex_event( $event_id );
		do_action( 'lnkup_event_authenticity_changed', $event_id, $prev, $status );
		return true;
	}

	/**
	 * Vendor-facing label. Never implies a guarantee that the event will happen.
	 *
	 * @param int $event_id Event ID.
	 * @return array{status: string, label: string, description: string, bookable: bool}
	 */
	public static function public_label( $event_id ) {
		$status = self::get_status( $event_id );
		$labels = array(
			self::UNCLAIMED_EXTERNAL      => array( __( 'Listing from a public source', 'lnkup-marketplace' ), __( 'The organizer has not set up this listing on LNKup yet.', 'lnkup-marketplace' ) ),
			self::CLAIM_PENDING           => array( __( 'Listing claimed, verification pending', 'lnkup-marketplace' ), __( 'An organizer claimed this listing. LNKup is confirming they manage this event.', 'lnkup-marketplace' ) ),
			self::ORGANIZER_CONTROLLED    => array( __( 'Organizer-managed listing', 'lnkup-marketplace' ), __( 'This listing is managed by the organizer account shown. LNKup does not guarantee that events take place.', 'lnkup-marketplace' ) ),
			self::ADDITIONAL_VERIFICATION => array( __( 'Organizer-managed listing', 'lnkup-marketplace' ), __( 'LNKup is reviewing a recent change to this listing.', 'lnkup-marketplace' ) ),
			self::DISPUTED                => array( __( 'Listing under review', 'lnkup-marketplace' ), __( 'Applications are paused while LNKup reviews who manages this event.', 'lnkup-marketplace' ) ),
			self::RESTRICTED              => array( __( 'Listing unavailable', 'lnkup-marketplace' ), __( 'This listing is not accepting applications.', 'lnkup-marketplace' ) ),
		);
		return array(
			'status'      => $status,
			'label'       => $labels[ $status ][0],
			'description' => $labels[ $status ][1],
			'bookable'    => self::is_bookable( $event_id ),
		);
	}

	/**
	 * Duplicate scan against existing LNKup events. Opens a review case; never merges.
	 *
	 * @param int $event_id Event ID.
	 * @return array<int, array{event_id: int, confidence: int}>
	 */
	public static function scan_duplicates( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! class_exists( 'LNKup_External_Events_Dedup' ) ) {
			return array();
		}
		$matches = LNKup_External_Events_Dedup::lnk_event_candidates( $event_id );
		$foreign = array_values(
			array_filter(
				$matches,
				static function ( $m ) use ( $event_id ) {
					return (int) get_post_meta( (int) $m['event_id'], '_lnkup_organizer_user_id', true ) !== (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
				}
			)
		);
		if ( $foreign ) {
			LNKup_Trust_Cases::open(
				array(
					'case_type'    => 'possible_duplicate',
					'severity'     => $foreign[0]['confidence'] >= 90 ? 'elevated' : 'review',
					'subject_type' => 'event',
					'subject_id'   => $event_id,
					'event_id'     => $event_id,
					'signal_key'   => 'dup_' . implode( '_', wp_list_pluck( array_slice( $foreign, 0, 3 ), 'event_id' ) ),
					'summary'      => sprintf( 'Possible duplicate of event #%d (confidence %d%%) owned by a different organizer.', (int) $foreign[0]['event_id'], (int) $foreign[0]['confidence'] ),
					'evidence'     => array( array( 'source' => 'dedup', 'matches' => array_slice( $foreign, 0, 5 ), 'at' => current_time( 'mysql', true ) ) ),
					'next_step'    => __( 'Compare both listings. Merge only after confirming they are the same event; different dates or venues are separate events.', 'lnkup-marketplace' ),
				)
			);
		}
		return $foreign;
	}

	/**
	 * Owner transfer on a controlled event triggers review; existing bookings are untouched.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_event_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id );
		if ( '_lnkup_organizer_user_id' !== $meta_key || 'lnkup_event' !== get_post_type( $object_id ) ) {
			return;
		}
		if ( self::ORGANIZER_CONTROLLED !== self::get_status( $object_id ) ) {
			return;
		}
		self::set_status( $object_id, self::ADDITIONAL_VERIFICATION, 'Event organizer changed.' );
		LNKup_Trust_Cases::open(
			array(
				'case_type'    => 'event_material_change',
				'severity'     => 'review',
				'subject_type' => 'event',
				'subject_id'   => absint( $object_id ),
				'event_id'     => absint( $object_id ),
				'signal_key'   => 'owner_change_' . absint( $meta_value ),
				'summary'      => sprintf( 'Organizer of event #%d changed to user #%d.', absint( $object_id ), absint( $meta_value ) ),
				'next_step'    => __( 'Confirm the new organizer is authorized. Existing bookings stay active during review.', 'lnkup-marketplace' ),
			)
		);
	}

	/**
	 * A payout account swap is a high-risk change: record a case for the organizer.
	 *
	 * @param int    $user_id  User.
	 * @param string $role     Role type.
	 * @param string $old_acct Old account.
	 * @param string $new_acct New account.
	 */
	public static function on_connect_account_changed( $user_id, $role, $old_acct, $new_acct ) {
		if ( 'organizer' !== $role ) {
			return;
		}
		LNKup_Audit_Log::log(
			'connect_account_changed',
			'user',
			absint( $user_id ),
			array(
				'prev' => array( 'account' => self::mask_account( $old_acct ) ),
				'new'  => array( 'account' => self::mask_account( $new_acct ) ),
			)
		);
		LNKup_Trust_Cases::open(
			array(
				'case_type'    => 'event_material_change',
				'severity'     => 'elevated',
				'subject_type' => 'user',
				'subject_id'   => absint( $user_id ),
				'signal_key'   => 'connect_swap_' . md5( $new_acct ),
				'summary'      => sprintf( 'Organizer #%d payout account changed (%s to %s).', absint( $user_id ), self::mask_account( $old_acct ), self::mask_account( $new_acct ) ),
				'next_step'    => __( 'Confirm the change with the organizer through a known contact before the next payout.', 'lnkup-marketplace' ),
			)
		);
		LNKup_Trust::notify_user(
			absint( $user_id ),
			__( 'Your LNKup payout account changed', 'lnkup-marketplace' ),
			__( "The payout account connected to your LNKup organizer account was changed.\n\nIf you made this change, no action is needed. If you did not, contact LNKup support right away.", 'lnkup-marketplace' ),
			'security'
		);
	}

	/**
	 * Run the duplicate scan once for newly published organizer events.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update flag.
	 */
	public static function on_event_saved( $post_id, $post, $update ) {
		unset( $update );
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || get_post_meta( $post_id, '_lnkup_dup_scanned', true ) ) {
			return;
		}
		update_post_meta( $post_id, '_lnkup_dup_scanned', 1 );
		wp_schedule_single_event( time() + 30, 'lnkup_trust_scan_event_duplicates', array( (int) $post_id ) );
	}

	private static function reindex_event( $event_id ) {
		if ( ! class_exists( 'LNKup_Marketplace_Index' ) ) {
			return;
		}
		$opps = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $event_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $opps as $opp_id ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
	}

	private static function mask_account( $acct ) {
		$acct = (string) $acct;
		return strlen( $acct ) > 4 ? 'acct_…' . substr( $acct, -4 ) : '';
	}
}

add_action( 'lnkup_trust_scan_event_duplicates', array( 'LNKup_Event_Authenticity', 'scan_duplicates' ) );
