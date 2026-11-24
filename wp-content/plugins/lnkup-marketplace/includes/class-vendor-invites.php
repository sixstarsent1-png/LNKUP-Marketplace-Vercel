<?php
/**
 * Vendor invites and rebooking.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Invites {

	const STATUS_SENT     = 'sent';
	const STATUS_OPENED   = 'opened';
	const STATUS_ACCEPTED = 'accepted';
	const STATUS_DECLINED = 'declined';
	const STATUS_EXPIRED  = 'expired';

	const EXPIRY_CRON  = 'lnkup_vendor_invites_expire';
	const EXPIRY_BATCH = 200;

	public static function init() {
		add_action( self::EXPIRY_CRON, array( __CLASS__, 'expire_overdue' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 30 );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::EXPIRY_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::EXPIRY_CRON );
		}
	}

	/**
	 * Close open invites whose expiry has passed so lists, pipelines and alerts stop treating them as open.
	 *
	 * @param int $limit Max invites per run (the next run continues).
	 * @return int Invites expired.
	 */
	public static function expire_overdue( $limit = self::EXPIRY_BATCH ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_status', 'value' => array( self::STATUS_SENT, self::STATUS_OPENED ), 'compare' => 'IN' ),
					array( 'key' => '_lnkup_expires_at', 'value' => gmdate( 'Y-m-d H:i:s' ), 'compare' => '<', 'type' => 'DATETIME' ),
				),
			)
		);
		foreach ( array_map( 'intval', $ids ) as $invite_id ) {
			update_post_meta( $invite_id, '_lnkup_status', self::STATUS_EXPIRED );
			do_action( 'lnkup_vendor_invite_status_changed', $invite_id, self::STATUS_EXPIRED );
		}
		return count( $ids );
	}

	/**
	 * Invites addressed to a vendor, newest first. Open invites past their expiry are reported as expired.
	 *
	 * @param int    $vendor_id Vendor user ID.
	 * @param string $scope     open|all.
	 * @param int    $limit     Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_vendor( $vendor_id, $scope = 'open', $limit = 50 ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id ) {
			return array();
		}
		$meta = array( array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ) );
		if ( 'open' === $scope ) {
			$meta[] = array( 'key' => '_lnkup_status', 'value' => array( self::STATUS_SENT, self::STATUS_OPENED ), 'compare' => 'IN' );
		}
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 100, (int) $limit ) ),
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		$items = array();
		foreach ( $invites as $invite ) {
			$status  = (string) get_post_meta( $invite->ID, '_lnkup_status', true );
			$expires = (string) get_post_meta( $invite->ID, '_lnkup_expires_at', true );
			if ( in_array( $status, array( self::STATUS_SENT, self::STATUS_OPENED ), true ) && '' !== $expires && strtotime( $expires ) < time() ) {
				$status = self::STATUS_EXPIRED;
				if ( 'open' === $scope ) {
					continue;
				}
			}
			$event_id     = (int) get_post_meta( $invite->ID, '_lnkup_event_id', true );
			$opp_id       = (int) get_post_meta( $invite->ID, '_lnkup_opportunity_id', true );
			$organizer_id = (int) get_post_meta( $invite->ID, '_lnkup_organizer_user_id', true );
			$organizer    = get_userdata( $organizer_id );
			$is_open      = in_array( $status, array( self::STATUS_SENT, self::STATUS_OPENED ), true );
			$items[]      = array(
				'id'                => (int) $invite->ID,
				'status'            => $status,
				'type'              => (string) get_post_meta( $invite->ID, '_lnkup_type', true ),
				'message'           => (string) get_post_meta( $invite->ID, '_lnkup_invite_message', true ),
				'organizer_name'    => $organizer ? (string) $organizer->display_name : '',
				'event_id'          => $event_id,
				'event_title'       => $event_id ? lnkup_plain_title( get_post( $event_id ) ) : '',
				'event_date'        => $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
				'city'              => $event_id ? (string) get_post_meta( $event_id, '_lnkup_city', true ) : '',
				'state'             => $event_id ? (string) get_post_meta( $event_id, '_lnkup_state', true ) : '',
				'image_url'         => $event_id ? (string) get_the_post_thumbnail_url( $event_id, 'medium' ) : '',
				'opportunity_id'    => $opp_id,
				'opportunity_title' => $opp_id ? lnkup_plain_title( get_post( $opp_id ) ) : '',
				'created_at'        => (string) $invite->post_date_gmt,
				'expires_at'        => $expires,
				'can_respond'       => $is_open,
			);
		}
		return $items;
	}

	/**
	 * Accept or decline an invite by ID for the signed-in vendor (apps that never see the email token).
	 *
	 * @param int  $invite_id Invite ID.
	 * @param bool $accept    Accept or decline.
	 * @param int  $user_id   Acting vendor.
	 * @return array|WP_Error
	 */
	public static function respond_by_id( $invite_id, $accept, $user_id ) {
		$invite_id = absint( $invite_id );
		$user_id   = absint( $user_id );
		if ( 'lnkup_vendor_invite' !== get_post_type( $invite_id ) || (int) get_post_meta( $invite_id, '_lnkup_vendor_user_id', true ) !== $user_id ) {
			return new WP_Error( 'invite_not_found', __( 'This invite is no longer available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return self::respond( (string) get_post_meta( $invite_id, '_lnkup_token', true ), (bool) $accept, $user_id );
	}

	/**
	 * Create invite.
	 */
	public static function create( $organizer_id, $vendor_id, $args = array() ) {
		$organizer_id = absint( $organizer_id );
		$vendor_id    = absint( $vendor_id );
		if ( ! $vendor_id || ! get_userdata( $vendor_id ) || ! LNKup_Roles::is_vendor( $vendor_id ) ) {
			return new WP_Error( 'invalid_vendor', __( 'Choose a vendor with an LNKup account.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$target = self::resolve_target( $organizer_id, absint( $args['event_id'] ?? 0 ), absint( $args['opportunity_id'] ?? 0 ) );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		list( $event_id, $opp_id ) = $target;
		$existing = self::active_invite( $organizer_id, $vendor_id, $event_id, $opp_id );
		if ( $existing ) {
			return new WP_Error(
				'invite_exists',
				__( 'This vendor already has an open invite for this event.', 'lnkup-marketplace' ),
				array( 'status' => 409, 'invite_id' => $existing )
			);
		}
		$guard = self::cold_invite_guard( $organizer_id, $vendor_id );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}
		if ( ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			LNKup_Organizer_CRM::ensure_relationship( $organizer_id, $vendor_id );
		}
		$type  = in_array( sanitize_key( $args['type'] ?? 'apply' ), array( 'apply', 'rebook' ), true ) ? sanitize_key( $args['type'] ?? 'apply' ) : 'apply';
		$token = wp_generate_password( 32, false );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) );
		$vendor = get_userdata( $vendor_id );
		$invite_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_vendor_invite',
				'post_status' => 'publish',
				'post_title'  => sprintf(
					__( 'Invite: %1$s', 'lnkup-marketplace' ),
					$vendor ? $vendor->display_name : '#' . $vendor_id
				),
				'post_author' => $organizer_id,
			)
		);
		update_post_meta( $invite_id, '_lnkup_organizer_user_id', $organizer_id );
		update_post_meta( $invite_id, '_lnkup_vendor_user_id', $vendor_id );
		update_post_meta( $invite_id, '_lnkup_opportunity_id', $opp_id );
		update_post_meta( $invite_id, '_lnkup_event_id', $event_id );
		update_post_meta( $invite_id, '_lnkup_status', self::STATUS_SENT );
		update_post_meta( $invite_id, '_lnkup_token', $token );
		update_post_meta( $invite_id, '_lnkup_expires_at', $expires );
		update_post_meta( $invite_id, '_lnkup_type', $type );
		if ( ! empty( $args['message'] ) ) {
			update_post_meta( $invite_id, '_lnkup_invite_message', sanitize_textarea_field( $args['message'] ) );
		}
		update_post_meta( $invite_id, '_lnkup_invite_source', sanitize_key( $args['source'] ?? 'manual' ) );
		$link = add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) );
		if ( empty( $args['silent'] ) ) {
			LNKup_Notifications::send_vendor_invite( $invite_id, $link );
		}
		self::add_vendor_notice( $vendor_id, $invite_id );
		LNKup_Organizer_CRM::log_activity(
			'invite_sent',
			$organizer_id,
			$vendor_id,
			array(
				'event_id'       => $event_id,
				'payload'        => array( 'invite_id' => $invite_id, 'type' => $type ),
			)
		);
		do_action( 'lnkup_vendor_invite_created', $invite_id, $organizer_id, $vendor_id, $event_id, $opp_id );
		return array(
			'invite_id' => $invite_id,
			'token'     => $token,
			'link'      => $link,
			'expires'   => $expires,
		);
	}

	/**
	 * Invite a vendor to specific stops of the organizer's tour or series. One independent invite per stop;
	 * approvals, pricing and bookings stay per event.
	 *
	 * @param int   $organizer_id   Organizer user ID.
	 * @param int   $vendor_id      Vendor user ID.
	 * @param int   $tour_id        Tour post ID.
	 * @param int[] $stop_event_ids Event IDs (stops) to invite to; empty = all upcoming stops.
	 * @param array $args           message, source.
	 * @return array{created:array,skipped:array}|WP_Error
	 */
	public static function create_for_tour( $organizer_id, $vendor_id, $tour_id, $stop_event_ids = array(), $args = array() ) {
		$tour_id = absint( $tour_id );
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) ) {
			return new WP_Error( 'invalid_tour', __( 'That tour no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true ) !== (int) $organizer_id && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You can only invite vendors to your own tours.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$stops = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => 'any',
				'posts_per_page' => 60,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_tour_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $tour_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$wanted  = array_filter( array_map( 'absint', (array) $stop_event_ids ) );
		$created = array();
		$skipped = array();
		// One grouped tour message (communications) instead of one email per stop, when that flow is on.
		$grouped = class_exists( 'LNKup_Comms_Settings' ) && LNKup_Comms_Settings::enabled( 'flow_passport' );
		foreach ( $stops as $event_id ) {
			$event_id = (int) $event_id;
			if ( $wanted && ! in_array( $event_id, $wanted, true ) ) {
				continue;
			}
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( $date && strtotime( $date ) < strtotime( 'today' ) ) {
				$skipped[] = array( 'event_id' => $event_id, 'reason' => __( 'This stop has already happened.', 'lnkup-marketplace' ) );
				continue;
			}
			$result = self::create( $organizer_id, $vendor_id, array_merge( $args, array( 'event_id' => $event_id, 'type' => 'apply', 'source' => 'tour_invite', 'silent' => $grouped ) ) );
			if ( is_wp_error( $result ) ) {
				$skipped[] = array( 'event_id' => $event_id, 'reason' => $result->get_error_message() );
				if ( 'invite_rate_limited' === $result->get_error_code() || 'invite_not_allowed' === $result->get_error_code() ) {
					break;
				}
				continue;
			}
			update_post_meta( (int) $result['invite_id'], '_lnkup_tour_id', $tour_id );
			$created[] = array( 'event_id' => $event_id, 'invite_id' => (int) $result['invite_id'] );
		}
		if ( $created ) {
			do_action( 'lnkup_vendor_invited_to_tour', $tour_id, (int) $vendor_id, (int) $organizer_id, wp_list_pluck( $created, 'event_id' ) );
		}
		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * Anti-spam guard for invites to vendors the organizer has no booking history with:
	 * respects the vendor's invite/discovery settings and platform-wide frequency caps.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 * @return true|WP_Error
	 */
	private static function cold_invite_guard( $organizer_id, $vendor_id ) {
		if ( ! class_exists( 'LNKup_Recruitment' ) || LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) {
			return true;
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
		if ( $profile_id && LNKup_Capabilities::organizer_can_view_vendor_contact( $organizer_id, $profile_id ) ) {
			return true;
		}
		if ( $profile_id ) {
			if ( '0' === (string) get_post_meta( $profile_id, '_lnkup_discoverable', true ) ) {
				return new WP_Error( 'invite_not_allowed', __( 'This vendor is not available for invites.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			if ( '0' === (string) get_post_meta( $profile_id, '_lnkup_accept_invites', true ) ) {
				return new WP_Error( 'invite_not_allowed', __( 'This vendor is not accepting invites right now.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
		}
		if ( LNKup_Recruitment::invites_to_vendor_since( $vendor_id, 7 * DAY_IN_SECONDS ) >= LNKup_Match_Settings::vendor_weekly_invite_cap() ) {
			return new WP_Error( 'invite_rate_limited', __( 'This vendor has received several invites this week. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		if ( LNKup_Recruitment::invites_from_organizer_since( $organizer_id, DAY_IN_SECONDS ) >= LNKup_Match_Settings::organizer_daily_invite_cap() ) {
			return new WP_Error( 'invite_rate_limited', __( "You've reached today's invite limit. Try again tomorrow.", 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Validate the invite target belongs to the inviting organizer.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $event_id     Requested event ID (0 = derive from opportunity / none).
	 * @param int $opp_id       Requested opportunity ID (0 = event-level invite).
	 * @return array{0:int,1:int}|WP_Error [event_id, opportunity_id].
	 */
	private static function resolve_target( $organizer_id, $event_id, $opp_id ) {
		if ( $opp_id ) {
			if ( 'lnkup_opportunity' !== get_post_type( $opp_id ) ) {
				return new WP_Error( 'invalid_opportunity', __( 'That vendor slot no longer exists.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
			}
			$opp_event = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			if ( $event_id && $opp_event && $opp_event !== $event_id ) {
				return new WP_Error( 'opportunity_event_mismatch', __( 'That vendor slot belongs to a different event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			$event_id = $opp_event ?: $event_id;
			$owner_ok = $event_id
				? LNKup_Capabilities::organizer_owns_event( $event_id, $organizer_id )
				: LNKup_Capabilities::user_owns_post( $opp_id, $organizer_id, '_lnkup_organizer_user_id' ) || LNKup_Capabilities::user_has_manage_all( $organizer_id );
			if ( ! $owner_ok ) {
				return new WP_Error( 'forbidden', __( 'You can only invite vendors to your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
			}
			return array( $event_id, $opp_id );
		}
		if ( $event_id && ! LNKup_Capabilities::organizer_owns_event( $event_id, $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'You can only invite vendors to your own events.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return array( $event_id, 0 );
	}

	/**
	 * Open (sent/opened, unexpired) invite from this organizer to this vendor for the same target.
	 *
	 * @return int Invite ID or 0.
	 */
	private static function active_invite( $organizer_id, $vendor_id, $event_id, $opp_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => $organizer_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opp_id ),
					array( 'key' => '_lnkup_status', 'value' => array( self::STATUS_SENT, self::STATUS_OPENED ), 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			$expires = get_post_meta( $id, '_lnkup_expires_at', true );
			if ( ! $expires || strtotime( $expires ) >= time() ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Add dashboard notice for vendor.
	 */
	public static function add_vendor_notice( $vendor_id, $invite_id ) {
		$notices = get_user_meta( $vendor_id, 'lnkup_vendor_notices', true );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}
		array_unshift( $notices, array( 'invite_id' => $invite_id, 'at' => current_time( 'mysql' ) ) );
		update_user_meta( $vendor_id, 'lnkup_vendor_notices', array_slice( $notices, 0, 20 ) );
	}

	/**
	 * Whether vendor has a non-expired invite for an opportunity.
	 *
	 * @param int $vendor_id      Vendor user ID.
	 * @param int $opportunity_id Opportunity post ID.
	 */
	public static function vendor_has_invite_for_opportunity( $vendor_id, $opportunity_id ) {
		$vendor_id      = absint( $vendor_id );
		$opportunity_id = absint( $opportunity_id );
		if ( ! $vendor_id || ! $opportunity_id ) {
			return false;
		}
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opportunity_id ),
					array(
						'key'     => '_lnkup_status',
						'value'   => array( self::STATUS_EXPIRED, self::STATUS_DECLINED ),
						'compare' => 'NOT IN',
					),
				),
			)
		);
		if ( empty( $invites ) ) {
			return false;
		}
		$expires = get_post_meta( $invites[0], '_lnkup_expires_at', true );
		if ( $expires && strtotime( $expires ) < time() ) {
			return false;
		}
		return true;
	}

	/**
	 * Get invite by token.
	 */
	public static function get_by_token( $token ) {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_token',
				'meta_value'     => sanitize_text_field( $token ),
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Vendor respond to invite.
	 */
	public static function respond( $token, $accept, $user_id = 0 ) {
		$token   = (string) $token;
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$invite  = '' !== $token ? self::get_by_token( $token ) : null;
		if ( ! $invite || ! hash_equals( (string) get_post_meta( $invite->ID, '_lnkup_token', true ), $token ) ) {
			return new WP_Error( 'invalid', __( 'Invalid invite.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$organizer_id = (int) get_post_meta( $invite->ID, '_lnkup_organizer_user_id', true );
		$vendor_id    = (int) get_post_meta( $invite->ID, '_lnkup_vendor_user_id', true );
		if ( ! $user_id || $user_id !== $vendor_id ) {
			return new WP_Error( 'invite_wrong_account', __( 'This invite was sent to a different account. Log in as the invited vendor.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$current = (string) get_post_meta( $invite->ID, '_lnkup_status', true );
		if ( ! in_array( $current, array( '', self::STATUS_SENT, self::STATUS_OPENED ), true ) ) {
			return new WP_Error( 'invite_closed', __( 'You already responded to this invite.', 'lnkup-marketplace' ), array( 'status' => 409, 'invite_status' => $current ) );
		}
		$expires = get_post_meta( $invite->ID, '_lnkup_expires_at', true );
		if ( $expires && strtotime( $expires ) < time() ) {
			update_post_meta( $invite->ID, '_lnkup_status', self::STATUS_EXPIRED );
			do_action( 'lnkup_vendor_invite_status_changed', $invite->ID, self::STATUS_EXPIRED );
			return new WP_Error( 'expired', __( 'This invite has expired.', 'lnkup-marketplace' ), array( 'status' => 410 ) );
		}
		$status = $accept ? self::STATUS_ACCEPTED : self::STATUS_DECLINED;
		update_post_meta( $invite->ID, '_lnkup_status', $status );
		do_action( 'lnkup_vendor_invite_status_changed', $invite->ID, $status );
		LNKup_Organizer_CRM::log_activity(
			$accept ? 'invite_accepted' : 'invite_declined',
			$organizer_id,
			$vendor_id,
			array( 'payload' => array( 'invite_id' => $invite->ID ) )
		);
		if ( $accept ) {
			LNKup_Organizer_CRM::log_activity( 'rebooked', $organizer_id, $vendor_id, array( 'payload' => array( 'invite_id' => $invite->ID ) ) );
			return array(
				'success'   => true,
				'apply_url' => self::get_apply_url( $invite->ID, $token ),
			);
		}
		return array( 'success' => true );
	}

	/**
	 * Build Find Events apply URL for an invite.
	 *
	 * @param int    $invite_id Invite post ID.
	 * @param string $token     Invite token.
	 */
	public static function get_apply_url( $invite_id, $token = '' ) {
		$opp_id = (int) get_post_meta( absint( $invite_id ), '_lnkup_opportunity_id', true );
		$args   = array();
		if ( $opp_id ) {
			$args['apply'] = $opp_id;
		}
		if ( $token ) {
			$args['invite'] = sanitize_text_field( $token );
		}
		return add_query_arg( $args, lnkup_page_url_helper( 'opportunities' ) );
	}

	/**
	 * Events and opportunities available for organizer invites.
	 *
	 * @param int                  $organizer_id Organizer user ID.
	 * @param array<string, mixed> $args         Optional. Pass include_all => true to include closed / empty events (mobile Events list).
	 * @return array<string, mixed>
	 */
	public static function get_invite_options_for_organizer( $organizer_id, $args = array() ) {
		$include_all = ! empty( $args['include_all'] );
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => absint( $organizer_id ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $events as $event ) {
			$opportunities = get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'posts_per_page' => -1,
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => $event->ID,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			$opp_items = array();
			foreach ( $opportunities as $opp ) {
				$status = get_post_meta( $opp->ID, '_lnkup_opportunity_status', true );
				if ( ! $include_all && $status && 'open' !== $status ) {
					continue;
				}
				$opp_items[] = array(
					'id'              => $opp->ID,
					'label'           => lnkup_plain_title( $opp ),
					'status'          => $status ? (string) $status : 'open',
					'spots_total'     => (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true ),
					'spots_remaining' => (int) get_post_meta( $opp->ID, '_lnkup_spots_remaining', true ),
					'vendor_fee'      => (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true ),
				);
			}
			if ( ! $include_all && ! $opp_items ) {
				continue;
			}
			$row = array(
				'event_id'      => $event->ID,
				'event_title'   => lnkup_plain_title( $event ),
				'date'          => (string) get_post_meta( $event->ID, '_lnkup_event_date', true ),
				'city'          => (string) get_post_meta( $event->ID, '_lnkup_city', true ),
				'state'         => (string) get_post_meta( $event->ID, '_lnkup_state', true ),
				'opportunities' => $opp_items,
			);
			if ( $include_all ) {
				// Mobile Events/workspace: editable listing fields + counts, so the app neither
				// pre-fills edits with blanks nor fetches a roster per event.
				$row['post_status']    = $event->post_status;
				$row['listing_status'] = (string) get_post_meta( $event->ID, '_lnkup_event_status', true );
				$row['venue_name']    = (string) get_post_meta( $event->ID, '_lnkup_venue_name', true );
				$row['venue_address'] = (string) get_post_meta( $event->ID, '_lnkup_venue_address', true );
				$row['start_time']    = (string) get_post_meta( $event->ID, '_lnkup_start_time', true );
				$row['end_time']      = (string) get_post_meta( $event->ID, '_lnkup_end_time', true );
				$row['description']   = (string) $event->post_content;
				$row['image_url']     = (string) get_the_post_thumbnail_url( $event->ID, 'medium_large' );
				$row['counts']        = self::application_counts_for_event( (int) $event->ID );
			}
			$items[] = $row;
		}
		return array(
			'items'       => $items,
			'needs_event' => empty( $items ),
		);
	}

	/**
	 * Application counts for an event by organizer-facing bucket (one meta query).
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, int>
	 */
	private static function application_counts_for_event( int $event_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.meta_value AS status, COUNT(*) AS total
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id' AND e.meta_value = %s
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status'
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash', 'auto-draft', 'lnkup_app_draft')
				GROUP BY s.meta_value",
				(string) $event_id
			),
			ARRAY_A
		);
		$counts = array(
			'total'            => 0,
			'to_review'        => 0,
			'needs_info'       => 0,
			'approved'         => 0,
			'payment_required' => 0,
			'confirmed'        => 0,
			'waitlisted'       => 0,
			'declined'         => 0,
		);
		foreach ( (array) $rows as $row ) {
			$n      = (int) $row['total'];
			$status = (string) $row['status'];
			$counts['total'] += $n;
			if ( in_array( $status, array( '', 'submitted', 'under_review' ), true ) ) {
				$counts['to_review'] += $n;
			} elseif ( 'info_requested' === $status ) {
				$counts['needs_info'] += $n;
			} elseif ( 'approved' === $status ) {
				$counts['approved'] += $n;
			} elseif ( 'payment_required' === $status ) {
				$counts['payment_required'] += $n;
			} elseif ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$counts['confirmed'] += $n;
			} elseif ( 'waitlisted' === $status ) {
				$counts['waitlisted'] += $n;
			} elseif ( in_array( $status, array( 'rejected', 'cancelled' ), true ) ) {
				$counts['declined'] += $n;
			}
		}
		return $counts;
	}

	/**
	 * Mark invite opened.
	 */
	public static function mark_opened( $token ) {
		$invite = self::get_by_token( $token );
		if ( $invite && self::STATUS_SENT === get_post_meta( $invite->ID, '_lnkup_status', true ) ) {
			update_post_meta( $invite->ID, '_lnkup_status', self::STATUS_OPENED );
			do_action( 'lnkup_vendor_invite_status_changed', $invite->ID, self::STATUS_OPENED );
		}
	}

	/**
	 * Get invites for organizer.
	 */
	public static function get_for_organizer( $organizer_id, $limit = 20 ) {
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => $limit,
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $invites as $invite ) {
			$vendor_id = (int) get_post_meta( $invite->ID, '_lnkup_vendor_user_id', true );
			$vendor = get_userdata( $vendor_id );
			$token  = (string) get_post_meta( $invite->ID, '_lnkup_token', true );
			$link   = $token ? add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) ) : '';
			$event_id    = (int) get_post_meta( $invite->ID, '_lnkup_event_id', true );
			$event_post  = $event_id ? get_post( $event_id ) : null;
			$opp_id      = (int) get_post_meta( $invite->ID, '_lnkup_opportunity_id', true );
			$items[] = array(
				'id'             => $invite->ID,
				'vendor'         => $vendor ? $vendor->display_name : '',
				'status'         => get_post_meta( $invite->ID, '_lnkup_status', true ),
				'type'           => get_post_meta( $invite->ID, '_lnkup_type', true ),
				'event_id'       => $event_id,
				'event_title'    => lnkup_plain_title( $event_post ),
				'opportunity_id' => $opp_id,
				'created'        => $invite->post_date,
				'link'           => $link,
			);
		}
		return $items;
	}
}
