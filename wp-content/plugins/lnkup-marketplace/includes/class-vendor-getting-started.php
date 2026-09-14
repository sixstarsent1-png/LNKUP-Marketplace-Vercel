<?php
/**
 * Vendor getting-started checklist (Phase 21 parity).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Derives vendor home tutorial progress from existing dashboard data.
 */
class LNKup_Vendor_Getting_Started {

	const META_DISMISSED   = 'lnkup_vendor_tutorial_dismissed';
	const META_WELCOME     = 'lnkup_vendor_welcome_seen';
	const PROFILE_READY_MIN = 70;

	/**
	 * Build checklist payload for vendor home.
	 *
	 * @param int                  $user_id Vendor user ID.
	 * @param array<string, mixed> $preload Optional context from LNKup_Vendor_Dashboard_Data::context().
	 * @return array<string, mixed>
	 */
	public static function get_checklist( int $user_id, array $preload = array() ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return self::empty_payload();
		}

		$ctx = ! empty( $preload ) ? $preload : ( class_exists( 'LNKup_Vendor_Dashboard_Data' ) ? LNKup_Vendor_Dashboard_Data::context() : array() );
		$applications = isset( $ctx['applications'] ) && is_array( $ctx['applications'] ) ? $ctx['applications'] : array();
		$week         = class_exists( 'LNKup_Vendor_Dashboard_Data' ) ? LNKup_Vendor_Dashboard_Data::week_snapshot( $ctx ) : array();
		$strength     = (int) ( $week['profile_strength'] ?? 0 );

		$favs            = class_exists( 'LNKup_Vendor_Personalization' ) ? LNKup_Vendor_Personalization::get_favorites( $user_id ) : array();
		$favorites_count = count( (array) ( $favs['opportunities'] ?? array() ) );
		$app_count       = count( $applications );
		$bookings        = class_exists( 'LNKup_Vendor_Dashboard_Data' )
			? LNKup_Vendor_Dashboard_Data::count_confirmed_bookings( $applications )
			: 0;

		$profile_ready     = $strength >= self::PROFILE_READY_MIN;
		$has_discovery     = $favorites_count > 0 || $app_count > 0;
		$has_application   = $app_count > 0;
		$payment_complete  = $bookings > 0;

		$profile_url  = lnkup_page_url_helper( 'vendor-profile' );
		$find_url     = lnkup_page_url_helper( 'opportunities' );
		$apps_url     = add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$payments_url = add_query_arg( 'view', 'payments', lnkup_page_url_helper( 'vendor-dashboard' ) );
		$ops_url      = add_query_arg( 'view', 'operations', lnkup_page_url_helper( 'vendor-dashboard' ) );

		$items = array(
			self::step(
				'profile_readiness',
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_profile_readiness' ) : __( 'Complete profile and readiness', 'lnkup-marketplace' ),
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_profile_readiness_desc' ) : __( 'Add business details and required documents so organizers can approve you faster.', 'lnkup-marketplace' ),
				$profile_ready
					? ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_profile_readiness_cta_done' ) : __( 'Review profile', 'lnkup-marketplace' ) )
					: ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_profile_readiness_cta' ) : __( 'Continue setup', 'lnkup-marketplace' ) ),
				$profile_url,
				$profile_ready
			),
			self::step(
				'discover_opportunity',
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_discover' ) : __( 'Find and save an opportunity', 'lnkup-marketplace' ),
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_discover_desc' ) : __( 'Browse events near you and save booths you want to apply for.', 'lnkup-marketplace' ),
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_discover_cta' ) : __( 'Find events', 'lnkup-marketplace' ),
				$find_url,
				$has_discovery
			),
			self::step(
				'submit_application',
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_apply' ) : __( 'Apply to an event', 'lnkup-marketplace' ),
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_apply_desc' ) : __( 'Submit your application so organizers can review your fit.', 'lnkup-marketplace' ),
				$has_application
					? ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_apply_cta_done' ) : __( 'View requests', 'lnkup-marketplace' ) )
					: ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_apply_cta' ) : __( 'Apply now', 'lnkup-marketplace' ) ),
				$has_application ? $apps_url : $find_url,
				$has_application
			),
			self::step(
				'complete_payment_pass',
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_payment' ) : __( 'Complete payment and open your pass', 'lnkup-marketplace' ),
				function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_payment_desc' ) : __( 'Pay your booth fee and unlock load-in details for event day.', 'lnkup-marketplace' ),
				$payment_complete
					? ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_payment_cta_done' ) : __( 'Open pass', 'lnkup-marketplace' ) )
					: ( function_exists( 'lnkup_copy' ) ? lnkup_copy( 'vendor_tutorial_payment_cta' ) : __( 'Open wallet', 'lnkup-marketplace' ) ),
				$payment_complete ? $ops_url : $payments_url,
				$payment_complete
			),
		);

		$completed = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['done'] ) ) {
				++$completed;
			}
		}
		$total       = count( $items );
		$all_complete = $completed >= $total && $total > 0;
		$dismissed    = (bool) get_user_meta( $user_id, self::META_DISMISSED, true );
		$welcome_seen = (bool) get_user_meta( $user_id, self::META_WELCOME, true );

		return array(
			'completed'    => $completed,
			'total'        => $total,
			'percent'      => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0,
			'all_complete' => $all_complete,
			'dismissed'    => $dismissed,
			'show'         => ! $dismissed && ! $all_complete,
			'show_welcome' => ! $welcome_seen && ! $dismissed && ! $all_complete,
			'items'        => $items,
		);
	}

	/**
	 * Mark tutorial dismissed for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function dismiss( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_DISMISSED, 1 );
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}
	}

	/**
	 * Mark welcome popup as seen.
	 *
	 * @param int $user_id User ID.
	 */
	public static function mark_welcome_seen( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}
	}

	/**
	 * Re-show checklist without reopening the welcome modal.
	 *
	 * @param int $user_id User ID.
	 */
	public static function reset( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_DISMISSED );
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}
	}

	/**
	 * @param string $key       Step key.
	 * @param string $title     Title.
	 * @param string $desc      Description.
	 * @param string $cta_label CTA label.
	 * @param string $cta_url   CTA URL.
	 * @param bool   $done      Whether complete.
	 * @return array<string, mixed>
	 */
	private static function step( string $key, string $title, string $desc, string $cta_label, string $cta_url, bool $done ): array {
		return array(
			'key'         => $key,
			'title'       => $title,
			'description' => $desc,
			'cta_label'   => $cta_label,
			'cta_url'     => $cta_url,
			'done'        => $done,
			'status'      => $done ? 'complete' : 'not_started',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_payload(): array {
		return array(
			'completed'    => 0,
			'total'        => 0,
			'percent'      => 0,
			'all_complete' => false,
			'dismissed'    => false,
			'show'         => false,
			'show_welcome' => false,
			'items'        => array(),
		);
	}
}
