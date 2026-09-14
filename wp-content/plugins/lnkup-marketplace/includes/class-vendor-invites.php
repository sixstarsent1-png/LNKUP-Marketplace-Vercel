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

	/**
	 * Create invite.
	 */
	public static function create( $organizer_id, $vendor_id, $args = array() ) {
		$vendor_id = absint( $vendor_id );
		if ( ! $vendor_id ) {
			return new WP_Error( 'invalid_vendor', __( 'Vendor is required.', 'lnkup-marketplace' ) );
		}
		if ( ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			LNKup_Organizer_CRM::ensure_relationship( $organizer_id, $vendor_id );
		}
		$type = sanitize_key( $args['type'] ?? 'apply' );
		$opp_id = absint( $args['opportunity_id'] ?? 0 );
		$event_id = absint( $args['event_id'] ?? 0 );
		if ( $opp_id && ! $event_id ) {
			$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		}
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
		$link = add_query_arg( 'invite', $token, lnkup_page_url_helper( 'opportunities' ) );
		LNKup_Notifications::send_vendor_invite( $invite_id, $link );
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
		return array(
			'invite_id' => $invite_id,
			'token'     => $token,
			'link'      => $link,
			'expires'   => $expires,
		);
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
	public static function respond( $token, $accept ) {
		$invite = self::get_by_token( $token );
		if ( ! $invite ) {
			return new WP_Error( 'invalid', __( 'Invalid invite.', 'lnkup-marketplace' ) );
		}
		$expires = get_post_meta( $invite->ID, '_lnkup_expires_at', true );
		if ( $expires && strtotime( $expires ) < time() ) {
			update_post_meta( $invite->ID, '_lnkup_status', self::STATUS_EXPIRED );
			return new WP_Error( 'expired', __( 'This invite has expired.', 'lnkup-marketplace' ) );
		}
		$status = $accept ? self::STATUS_ACCEPTED : self::STATUS_DECLINED;
		update_post_meta( $invite->ID, '_lnkup_status', $status );
		$organizer_id = (int) get_post_meta( $invite->ID, '_lnkup_organizer_user_id', true );
		$vendor_id = (int) get_post_meta( $invite->ID, '_lnkup_vendor_user_id', true );
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
					'label'           => $opp->post_title,
					'status'          => $status ? (string) $status : 'open',
					'spots_total'     => (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true ),
					'spots_remaining' => (int) get_post_meta( $opp->ID, '_lnkup_spots_remaining', true ),
					'vendor_fee'      => (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true ),
				);
			}
			if ( ! $include_all && ! $opp_items ) {
				continue;
			}
			$items[] = array(
				'event_id'      => $event->ID,
				'event_title'   => $event->post_title,
				'date'          => (string) get_post_meta( $event->ID, '_lnkup_event_date', true ),
				'city'          => (string) get_post_meta( $event->ID, '_lnkup_city', true ),
				'state'         => (string) get_post_meta( $event->ID, '_lnkup_state', true ),
				'opportunities' => $opp_items,
			);
		}
		return array(
			'items'       => $items,
			'needs_event' => empty( $items ),
		);
	}

	/**
	 * Mark invite opened.
	 */
	public static function mark_opened( $token ) {
		$invite = self::get_by_token( $token );
		if ( $invite && self::STATUS_SENT === get_post_meta( $invite->ID, '_lnkup_status', true ) ) {
			update_post_meta( $invite->ID, '_lnkup_status', self::STATUS_OPENED );
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
				'event_title'    => $event_post ? $event_post->post_title : '',
				'opportunity_id' => $opp_id,
				'created'        => $invite->post_date,
				'link'           => $link,
			);
		}
		return $items;
	}
}
