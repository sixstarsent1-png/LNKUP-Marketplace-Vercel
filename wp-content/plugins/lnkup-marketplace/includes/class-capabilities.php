<?php
/**
 * Capability and ownership checks.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Capabilities {

	/**
	 * Init hooks.
	 */
	public static function init() {
		// Ownership checks use user_has_manage_all() directly — never user_can()
		// inside map_meta_cap, which causes infinite recursion.
	}

	/**
	 * Check if user has lnkup_manage_all without triggering map_meta_cap recursion.
	 *
	 * @param int $user_id User ID.
	 */
	public static function user_has_manage_all( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$user = get_userdata( $user_id );
		return $user && ! empty( $user->allcaps['lnkup_manage_all'] );
	}

	/**
	 * Check if user owns a post via meta key.
	 *
	 * @param int    $post_id  Post ID.
	 * @param int    $user_id  User ID.
	 * @param string $meta_key Meta key for owner user ID.
	 */
	public static function user_owns_post( $post_id, $user_id, $meta_key = '_lnkup_owner_user_id' ) {
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$owner = (int) get_post_meta( $post_id, $meta_key, true );
		if ( $owner && $owner === (int) $user_id ) {
			return true;
		}
		$vendor_user = (int) get_post_meta( $post_id, '_lnkup_vendor_user_id', true );
		if ( $vendor_user && $vendor_user === (int) $user_id ) {
			return true;
		}
		$organizer_user = (int) get_post_meta( $post_id, '_lnkup_organizer_user_id', true );
		if ( $organizer_user && $organizer_user === (int) $user_id ) {
			return true;
		}
		return false;
	}

	/**
	 * Require capability or die.
	 *
	 * @param string $cap Capability.
	 */
	public static function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) && ! self::user_has_manage_all() ) {
			LNKup_Security::deny( __( 'Permission denied.', 'lnkup-marketplace' ) );
		}
	}

	/**
	 * Require login.
	 */
	public static function require_login() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( lnkup_login_url_for( (string) get_permalink() ) );
			exit;
		}
	}

	/**
	 * Organizer can view vendor (discover or connected).
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 */
	public static function organizer_can_access_vendor( $organizer_id, $vendor_id ) {
		if ( self::user_has_manage_all( $organizer_id ) ) {
			return true;
		}
		if ( LNKup_Organizer_CRM::get_relationship( $organizer_id, $vendor_id ) ) {
			return true;
		}
		return (bool) lnkup_get_vendor_profile_id( $vendor_id );
	}

	/**
	 * Organizer can manage vendor (notes, tags, flags).
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $vendor_id    Vendor user ID.
	 */
	public static function organizer_can_manage_vendor( $organizer_id, $vendor_id ) {
		if ( self::user_has_manage_all( $organizer_id ) ) {
			return true;
		}
		return (bool) LNKup_Organizer_CRM::get_relationship( $organizer_id, $vendor_id );
	}

	/**
	 * Organizer owns CRM CPT object.
	 *
	 * @param int $post_id      CRM post ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function organizer_owns_crm_object( $post_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $organizer_id ) ) {
			return true;
		}
		return (int) get_post_meta( $post_id, '_lnkup_organizer_user_id', true ) === (int) $organizer_id;
	}

	/**
	 * Vendor can view own reputation data.
	 *
	 * @param int $profile_id Profile post ID.
	 * @param int $user_id    User ID.
	 */
	public static function vendor_can_view_reputation( $profile_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) === (int) $user_id;
	}

	/**
	 * Organizer can submit review for application.
	 */
	public static function organizer_can_review_vendor( $organizer_id, $application_id ) {
		$check = LNKup_Vendor_Reviews::can_review( $application_id, $organizer_id );
		return ! empty( $check['allowed'] );
	}

	/**
	 * Admin reputation moderation.
	 */
	public static function admin_can_moderate_reputation() {
		return self::user_has_manage_all();
	}

	/**
	 * Organizer owns event.
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function organizer_owns_event( $event_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		$event_id     = absint( $event_id );
		if ( ! $organizer_id || ! $event_id ) {
			return false;
		}
		if ( self::user_has_manage_all( $organizer_id ) ) {
			return true;
		}
		if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		return (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) === (int) $organizer_id;
	}

	/**
	 * Event-scoped capability for the owner, admins, or staff per LNKup_Event_Staff::capability_matrix().
	 *
	 * Capabilities: view_roster, check_in, edit_ops, edit_packets, edit_map, broadcast,
	 * view_intelligence, view_financials, manage_staff, owner.
	 *
	 * @param int    $event_id   Event ID.
	 * @param string $capability Capability key.
	 * @param int    $user_id    User ID (defaults to current user).
	 */
	public static function staff_can( $event_id, $capability, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::organizer_owns_event( $event_id, $user_id ) ) {
			return true;
		}
		if ( in_array( $capability, array( 'owner', 'manage_staff' ), true ) || ! class_exists( 'LNKup_Event_Staff' ) ) {
			return false;
		}
		$role   = LNKup_Event_Staff::role_for( $event_id, $user_id );
		$matrix = LNKup_Event_Staff::capability_matrix();
		return '' !== $role && in_array( $capability, $matrix[ $role ] ?? array(), true );
	}

	/**
	 * Vendor owns application.
	 *
	 * @param int $app_id    Application ID.
	 * @param int $vendor_id Vendor user ID.
	 */
	public static function vendor_owns_application( $app_id, $vendor_id = 0 ) {
		$vendor_id = $vendor_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $vendor_id ) ) {
			return true;
		}
		return (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) === (int) $vendor_id;
	}

	/**
	 * Whether an application belongs to an event (guards event-scoped writes).
	 *
	 * @param int $app_id   Application ID.
	 * @param int $event_id Event ID.
	 */
	public static function application_in_event( $app_id, $event_id ) {
		$app_id = absint( $app_id );
		return $app_id
			&& 'lnkup_application' === get_post_type( $app_id )
			&& (int) get_post_meta( $app_id, '_lnkup_event_id', true ) === absint( $event_id );
	}

	/**
	 * Vendor owns a vendor profile (owner is stored in `_lnkup_owner_user_id`).
	 *
	 * @param int $profile_id Vendor profile post ID.
	 * @param int $vendor_id  User ID (defaults to current user).
	 */
	public static function vendor_owns_profile( $profile_id, $vendor_id = 0 ) {
		$vendor_id  = $vendor_id ?: get_current_user_id();
		$profile_id = absint( $profile_id );
		if ( ! $profile_id || ! $vendor_id || 'lnkup_vendor_profile' !== get_post_type( $profile_id ) ) {
			return false;
		}
		return (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) === (int) $vendor_id;
	}

	/**
	 * Staff or organizer can access event ops.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  User ID.
	 */
	public static function staff_can_access_event( $event_id, $user_id = 0 ) {
		return self::staff_can( $event_id, 'view_roster', $user_id );
	}

	/**
	 * Admin marketplace-wide ops view.
	 */
	public static function admin_can_view_ops() {
		return self::user_has_manage_all();
	}

	/**
	 * Organizer or staff can view event intelligence.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  User ID.
	 */
	public static function can_view_event_intelligence( $event_id, $user_id = 0 ) {
		return self::staff_can( $event_id, 'view_intelligence', $user_id );
	}

	/**
	 * Admin marketplace-wide intelligence.
	 */
	public static function can_view_market_intelligence() {
		return self::user_has_manage_all();
	}

	/**
	 * Vendor can view own intelligence only.
	 *
	 * @param int $user_id User ID.
	 */
	public static function can_view_vendor_intelligence( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return LNKup_Roles::is_vendor( $user_id );
	}

	/**
	 * View event map (organizer, staff, vendor with assignment, admin).
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  User ID.
	 */
	public static function can_view_event_map( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		if ( self::organizer_owns_event( $event_id, $user_id ) ) {
			return true;
		}
		if ( self::staff_can_access_event( $event_id, $user_id ) ) {
			return true;
		}
		if ( LNKup_Roles::is_vendor( $user_id ) ) {
			$apps = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
						array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					),
				)
			);
			return ! empty( $apps );
		}
		return false;
	}

	/**
	 * Edit event map (organizer owner or admin).
	 */
	public static function can_edit_event_map( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		return self::staff_can( $event_id, 'edit_map', $user_id );
	}
   
	/**
	 * Admin marketplace city map.
	 */
	public static function can_view_market_map() {
		return self::user_has_manage_all();
	}

	/**
	 * Vendor packet map for own application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function can_view_packet_map( $app_id ) {
		$user_id = get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		if ( self::vendor_owns_application( $app_id, $user_id ) ) {
			return true;
		}
		return self::user_owns_post( $app_id, $user_id, '_lnkup_organizer_user_id' );
	}

	/**
	 * User can view own financial data.
	 */
	public static function can_view_financials( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && ! empty( $user->allcaps['lnkup_view_own_financials'] );
	}

	/**
	 * Organizer can manage event financials.
	 */
	public static function can_manage_event_financials( $event_id, $user_id = 0 ) {
		return self::staff_can( $event_id, 'view_financials', $user_id );
	}

	/**
	 * Admin marketplace financial center.
	 */
	public static function can_manage_marketplace_financials() {
		return self::user_has_manage_all() || current_user_can( 'lnkup_manage_marketplace_financials' );
	}

	/**
	 * Vendor can view own growth dashboard.
	 */
	public static function can_view_growth( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		return $user && ! empty( $user->allcaps['lnkup_view_own_growth'] );
	}

	/**
	 * Admin marketplace growth center.
	 */
	public static function can_manage_marketplace_growth() {
		return self::user_has_manage_all() || current_user_can( 'lnkup_manage_marketplace_growth' );
	}

	/**
	 * Vendor owns growth goal.
	 */
	public static function vendor_owns_goal( $goal_id, $user_id = 0 ) {
		return LNKup_Growth_Goals::owns_goal( $goal_id, $user_id ?: get_current_user_id() );
	}

	/**
	 * User owns transaction row.
	 */
	public static function user_owns_transaction( $txn, $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( self::user_has_manage_all( $user_id ) ) {
			return true;
		}
		if ( ! is_array( $txn ) ) {
			return false;
		}
		return (int) $txn['vendor_user_id'] === (int) $user_id || (int) $txn['organizer_user_id'] === (int) $user_id;
	}
}
