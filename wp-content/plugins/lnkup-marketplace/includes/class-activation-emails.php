<?php
/**
 * Activation drip emails + push for vendors and organizers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Checklist-driven welcome and setup nudges (Phase 2 activation).
 */
class LNKup_Activation_Emails {

	const META_WELCOME      = 'lnkup_activation_welcome_sent';
	const META_PROFILE      = 'lnkup_activation_profile_sent';
	const META_FIND_EVENTS  = 'lnkup_activation_find_events_sent';
	const META_APPLY        = 'lnkup_activation_apply_sent';
	const META_CREATE_EVENT = 'lnkup_activation_create_event_sent';
	const META_ORG_REVIEW   = 'lnkup_activation_org_review_sent';
	const META_VENDOR_DONE  = 'lnkup_activation_vendor_done';
	const META_ORG_DONE     = 'lnkup_activation_organizer_done';

	const DAILY_BATCH = 100;

	/**
	 * Hook welcome on register / role assign.
	 */
	public static function init(): void {
		add_action( 'user_register', array( __CLASS__, 'maybe_send_welcome_on_register' ), 30, 1 );
		add_action( 'set_user_role', array( __CLASS__, 'maybe_send_welcome_on_role' ), 20, 3 );
		add_action( 'add_user_role', array( __CLASS__, 'maybe_send_welcome_on_add_role' ), 20, 2 );
	}

	/**
	 * Whether activation emails are enabled.
	 */
	public static function enabled(): bool {
		$settings = lnkup_get_settings();
		return ! empty( $settings['activation_emails_enabled'] );
	}

	/**
	 * Communications OS owns an organizer sequence, so this legacy drip stays quiet for it
	 * (one welcome, one set of setup nudges). Vendor drips are unaffected.
	 *
	 * @param string $flag flow_onboarding | flow_activation.
	 */
	public static function comms_handles( string $flag ): bool {
		return class_exists( 'LNKup_Comms_Settings' ) && LNKup_Comms_Settings::enabled( $flag );
	}

	/**
	 * Daily drip for incomplete vendors/organizers (including pre-tutorial backfill).
	 */
	public static function run_daily(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$processed = 0;
		$cap       = (int) ( lnkup_get_settings()['activation_daily_batch'] ?? self::DAILY_BATCH );
		$cap       = max( 10, min( 500, $cap ) );

		$vendors = get_users(
			array(
				'role'      => 'lnkup_vendor',
				'number'    => $cap,
				'orderby'   => 'registered',
				'order'     => 'ASC',
				'fields'    => array( 'ID', 'user_registered' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_VENDOR_DONE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $vendors as $user ) {
			if ( $processed >= $cap ) {
				break;
			}
			if ( self::process_vendor_daily( (int) $user->ID, (string) $user->user_registered ) ) {
				++$processed;
			}
		}

		$remaining = $cap - $processed;
		if ( $remaining <= 0 || self::comms_handles( 'flow_onboarding' ) || self::comms_handles( 'flow_activation' ) ) {
			return;
		}

		$organizers = get_users(
			array(
				'role'       => 'lnkup_organizer',
				'number'     => $remaining,
				'orderby'    => 'registered',
				'order'      => 'ASC',
				'fields'     => array( 'ID', 'user_registered' ),
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_ORG_DONE,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $organizers as $user ) {
			if ( self::process_organizer_daily( (int) $user->ID, (string) $user->user_registered ) ) {
				++$processed;
			}
			if ( $processed >= $cap ) {
				break;
			}
		}
	}

	/**
	 * @param int $user_id New user ID.
	 */
	public static function maybe_send_welcome_on_register( $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! self::enabled() ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$roles = (array) $user->roles;
		if ( in_array( 'lnkup_vendor', $roles, true ) ) {
			self::send_welcome_vendor( $user_id );
		} elseif ( in_array( 'lnkup_organizer', $roles, true ) ) {
			self::send_welcome_organizer( $user_id );
		}
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    New role.
	 * @param array  $old     Old roles.
	 */
	public static function maybe_send_welcome_on_role( $user_id, $role, $old = array() ): void {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! self::enabled() ) {
			return;
		}
		if ( 'lnkup_vendor' === $role ) {
			self::send_welcome_vendor( $user_id );
		} elseif ( 'lnkup_organizer' === $role ) {
			self::send_welcome_organizer( $user_id );
		}
	}

	/**
	 * @param int    $user_id User ID.
	 * @param string $role    Added role.
	 */
	public static function maybe_send_welcome_on_add_role( $user_id, $role ): void {
		self::maybe_send_welcome_on_role( $user_id, $role, array() );
	}

	/**
	 * Welcome once; skip if already registered > 1 day (backfill safety).
	 *
	 * @param int $user_id Vendor ID.
	 * @return bool
	 */
	public static function send_welcome_vendor( int $user_id ): bool {
		if ( get_user_meta( $user_id, self::META_WELCOME, true ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || self::days_since_registered( (string) $user->user_registered ) > 1 ) {
			return false;
		}
		if ( ! class_exists( 'LNKup_Notifications' ) ) {
			return false;
		}
		$sent = LNKup_Notifications::send_vendor_welcome( $user_id );
		if ( $sent ) {
			update_user_meta( $user_id, self::META_WELCOME, time() );
		}
		return $sent;
	}

	/**
	 * @param int $user_id Organizer ID.
	 * @return bool
	 */
	public static function send_welcome_organizer( int $user_id ): bool {
		if ( get_user_meta( $user_id, self::META_WELCOME, true ) || self::comms_handles( 'flow_onboarding' ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || self::days_since_registered( (string) $user->user_registered ) > 1 ) {
			return false;
		}
		if ( ! class_exists( 'LNKup_Notifications' ) ) {
			return false;
		}
		$sent = LNKup_Notifications::send_organizer_welcome( $user_id );
		if ( $sent ) {
			update_user_meta( $user_id, self::META_WELCOME, time() );
		}
		return $sent;
	}

	/**
	 * Process one vendor; send at most one drip email.
	 *
	 * @param int    $user_id     Vendor ID.
	 * @param string $registered  MySQL datetime.
	 * @return bool True if an email was sent.
	 */
	private static function process_vendor_daily( int $user_id, string $registered ): bool {
		$days = self::days_since_registered( $registered );
		if ( ! class_exists( 'LNKup_Notifications' ) ) {
			return false;
		}

		$strength  = self::vendor_profile_strength( $user_id );
		$app_count = self::vendor_application_count( $user_id );
		$fav_count = self::vendor_favorite_opportunity_count( $user_id );
		$ready_min = class_exists( 'LNKup_Vendor_Getting_Started' )
			? (int) LNKup_Vendor_Getting_Started::PROFILE_READY_MIN
			: 70;

		// Fully activated: applied at least once.
		if ( $app_count > 0 ) {
			update_user_meta( $user_id, self::META_VENDOR_DONE, time() );
			return false;
		}

		// Profile nudge: ≥2 days (all backfill users qualify by age).
		if ( $days >= 2
			&& $strength < $ready_min
			&& ! get_user_meta( $user_id, self::META_PROFILE, true )
		) {
			if ( LNKup_Notifications::send_vendor_complete_profile( $user_id ) ) {
				update_user_meta( $user_id, self::META_PROFILE, time() );
				return true;
			}
		}

		// Find events: ≥5 days, no discovery signal.
		if ( $days >= 5
			&& 0 === $app_count
			&& 0 === $fav_count
			&& ! get_user_meta( $user_id, self::META_FIND_EVENTS, true )
		) {
			if ( LNKup_Notifications::send_vendor_find_events( $user_id ) ) {
				update_user_meta( $user_id, self::META_FIND_EVENTS, time() );
				return true;
			}
		}

		// Apply nudge: ≥10 days, still no applications.
		if ( $days >= 10
			&& 0 === $app_count
			&& ! get_user_meta( $user_id, self::META_APPLY, true )
		) {
			if ( LNKup_Notifications::send_vendor_apply_nudge( $user_id ) ) {
				update_user_meta( $user_id, self::META_APPLY, time() );
				update_user_meta( $user_id, self::META_VENDOR_DONE, time() );
				return true;
			}
		}

		// Exhausted drip or profile ready + has discovery (no further emails).
		$profile_done = $strength >= $ready_min || get_user_meta( $user_id, self::META_PROFILE, true );
		$find_done    = $fav_count > 0 || get_user_meta( $user_id, self::META_FIND_EVENTS, true );
		$apply_done   = get_user_meta( $user_id, self::META_APPLY, true );
		if ( $days >= 10 && $profile_done && $find_done && $apply_done ) {
			update_user_meta( $user_id, self::META_VENDOR_DONE, time() );
		} elseif ( $days >= 10 && $strength >= $ready_min && $fav_count > 0 && $apply_done ) {
			update_user_meta( $user_id, self::META_VENDOR_DONE, time() );
		} elseif ( $days >= 10 && $strength >= $ready_min && get_user_meta( $user_id, self::META_FIND_EVENTS, true ) && $apply_done ) {
			update_user_meta( $user_id, self::META_VENDOR_DONE, time() );
		}

		return false;
	}

	/**
	 * Process one organizer; send at most one drip email.
	 *
	 * @param int    $user_id    Organizer ID.
	 * @param string $registered MySQL datetime.
	 * @return bool
	 */
	private static function process_organizer_daily( int $user_id, string $registered ): bool {
		$days = self::days_since_registered( $registered );
		if ( ! class_exists( 'LNKup_Notifications' ) ) {
			return false;
		}

		$event_count  = self::organizer_event_count( $user_id );
		$pending_apps = self::organizer_pending_application_count( $user_id );
		$invite_count = self::organizer_invite_count( $user_id );

		if ( $days >= 3
			&& 0 === $event_count
			&& ! get_user_meta( $user_id, self::META_CREATE_EVENT, true )
		) {
			if ( LNKup_Notifications::send_organizer_create_event( $user_id ) ) {
				update_user_meta( $user_id, self::META_CREATE_EVENT, time() );
				return true;
			}
		}

		if ( $days >= 7
			&& $event_count > 0
			&& ( $pending_apps > 0 || 0 === $invite_count )
			&& ! get_user_meta( $user_id, self::META_ORG_REVIEW, true )
		) {
			if ( LNKup_Notifications::send_organizer_review_vendors( $user_id ) ) {
				update_user_meta( $user_id, self::META_ORG_REVIEW, time() );
				update_user_meta( $user_id, self::META_ORG_DONE, time() );
				return true;
			}
		}

		// Has event and already invited or reviewed — drip complete.
		if ( $event_count > 0 && ( get_user_meta( $user_id, self::META_ORG_REVIEW, true ) || $invite_count > 0 ) ) {
			update_user_meta( $user_id, self::META_ORG_DONE, time() );
		} elseif ( $days >= 7 && $event_count > 0 && 0 === $pending_apps && $invite_count > 0 ) {
			update_user_meta( $user_id, self::META_ORG_DONE, time() );
		} elseif ( $days >= 3 && $event_count > 0 && get_user_meta( $user_id, self::META_CREATE_EVENT, true ) && get_user_meta( $user_id, self::META_ORG_REVIEW, true ) ) {
			update_user_meta( $user_id, self::META_ORG_DONE, time() );
		}

		return false;
	}

	/**
	 * @param string $registered MySQL datetime.
	 * @return int
	 */
	private static function days_since_registered( string $registered ): int {
		$ts = strtotime( $registered );
		if ( ! $ts ) {
			return 0;
		}
		return (int) max( 0, floor( ( time() - $ts ) / DAY_IN_SECONDS ) );
	}

	/**
	 * @param int $user_id Vendor ID.
	 * @return int Score 0–100.
	 */
	private static function vendor_profile_strength( int $user_id ): int {
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		if ( $profile_id <= 0 || ! class_exists( 'LNKup_Growth_Profile_Strength' ) ) {
			return 0;
		}
		$result = LNKup_Growth_Profile_Strength::calculate( $profile_id );
		return (int) ( $result['score'] ?? 0 );
	}

	/**
	 * @param int $user_id Vendor ID.
	 * @return int
	 */
	private static function vendor_application_count( int $user_id ): int {
		if ( function_exists( 'lnkup_get_applications_for_user' ) ) {
			$apps = lnkup_get_applications_for_user( $user_id, 'vendor' );
			return is_array( $apps ) ? count( $apps ) : 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $user_id,
			)
		);
		return count( $posts );
	}

	/**
	 * @param int $user_id Vendor ID.
	 * @return int
	 */
	private static function vendor_favorite_opportunity_count( int $user_id ): int {
		if ( ! class_exists( 'LNKup_Vendor_Personalization' ) ) {
			return 0;
		}
		$favs = LNKup_Vendor_Personalization::get_favorites( $user_id );
		return count( (array) ( $favs['opportunities'] ?? array() ) );
	}

	/**
	 * @param int $user_id Organizer ID.
	 * @return int
	 */
	private static function organizer_event_count( int $user_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		return count( $posts );
	}

	/**
	 * @param int $user_id Organizer ID.
	 * @return int
	 */
	private static function organizer_pending_application_count( int $user_id ): int {
		if ( ! function_exists( 'lnkup_get_applications_for_user' ) ) {
			return 0;
		}
		$apps  = lnkup_get_applications_for_user( $user_id, 'organizer' );
		$count = 0;
		foreach ( (array) $apps as $app ) {
			$id     = is_object( $app ) ? (int) $app->ID : (int) $app;
			$status = (string) get_post_meta( $id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'submitted', 'under_review' ), true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * @param int $user_id Organizer ID.
	 * @return int
	 */
	private static function organizer_invite_count( int $user_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $user_id,
			)
		);
		return count( $posts );
	}
}
