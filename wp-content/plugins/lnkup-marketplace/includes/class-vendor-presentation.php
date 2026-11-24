<?php
/**
 * Customer-facing vendor titles, places, lifecycle labels, and spend figures.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Shared presentation contract for vendor screens and REST payloads.
 */
class LNKup_Vendor_Presentation {

	/**
	 * Canonical event title. Never the application post title.
	 *
	 * @param int          $event_id Event post ID.
	 * @param WP_Post|null $application Unused. Kept so callers can pass the application.
	 */
	public static function customer_event_title( $event_id, $application = null ): string {
		unset( $application );
		$event_id = (int) $event_id;
		$event    = $event_id ? get_post( $event_id ) : null;
		if ( ! $event instanceof WP_Post || 'lnkup_event' !== $event->post_type ) {
			return __( 'Event details unavailable', 'lnkup-marketplace' );
		}
		$title = function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $event ) : $event->post_title;
		$date  = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$venue = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$org   = self::organizer_name( $event );
		if ( function_exists( 'lnkup_display_event_title' ) ) {
			$title = lnkup_display_event_title( $title, $date, $venue, $org );
		}
		$title = trim( (string) $title );
		if ( '' === $title || 0 === stripos( $title, 'Application:' ) ) {
			return __( 'Event details unavailable', 'lnkup-marketplace' );
		}
		return $title;
	}

	/**
	 * Organizer display name stored on the event, when one exists.
	 *
	 * @param WP_Post $event Event post.
	 */
	private static function organizer_name( WP_Post $event ): string {
		$org_id = (int) get_post_meta( $event->ID, '_lnkup_organizer_user_id', true );
		if ( ! $org_id ) {
			$org_id = (int) $event->post_author;
		}
		if ( ! $org_id ) {
			return '';
		}
		$public = (string) get_user_meta( $org_id, '_lnkup_organizer_public_name', true );
		if ( '' !== $public ) {
			return $public;
		}
		$user = get_userdata( $org_id );
		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Four customer-facing dimensions derived from existing statuses. No new states.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id Vendor user ID.
	 * @return array<string,mixed>
	 */
	public static function lifecycle( $app_id, $user_id = 0 ): array {
		$app_id = (int) $app_id;
		$status = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
		$passed = class_exists( 'LNKup_Vendor_Dashboard_Data' ) && LNKup_Vendor_Dashboard_Data::application_event_has_passed( $app_id );
		$payable = class_exists( 'LNKup_Vendor_Dashboard_Data' ) && LNKup_Vendor_Dashboard_Data::application_is_payable( $app_id, $user_id );
		$payment = class_exists( 'LNKup_Vendor_Dashboard_Data' ) ? LNKup_Vendor_Dashboard_Data::application_payment_for_vendor( $app_id, $user_id ) : null;
		$pay     = $payment instanceof WP_Post ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';

		$application = self::application_label( $status );
		$booking     = __( 'Not booked', 'lnkup-marketplace' );
		if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
			$booking = 'completed' === $status || $passed ? __( 'Completed', 'lnkup-marketplace' ) : __( 'Confirmed', 'lnkup-marketplace' );
		} elseif ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
			$booking = __( 'Awaiting confirmation', 'lnkup-marketplace' );
		} elseif ( in_array( $status, array( 'cancelled', 'rejected' ), true ) ) {
			$booking = __( 'Cancelled', 'lnkup-marketplace' );
		}

		if ( '' === $pay && in_array( $status, array( 'submitted', 'under_review', 'waitlisted', 'rejected' ), true ) ) {
			$payment_label = __( 'Not required yet', 'lnkup-marketplace' );
		} elseif ( 'paid' === $pay || 'paid' === $status ) {
			$payment_label = __( 'Paid', 'lnkup-marketplace' );
		} elseif ( in_array( $pay, array( 'refunded', 'partially_refunded' ), true ) ) {
			$payment_label = __( 'Refunded', 'lnkup-marketplace' );
		} elseif ( 'partially_paid' === $pay ) {
			$payment_label = __( 'Partially paid', 'lnkup-marketplace' );
		} elseif ( $payable && $passed ) {
			$payment_label = __( 'Past due', 'lnkup-marketplace' );
		} elseif ( $payable || in_array( $pay, array( 'pending', 'failed' ), true ) ) {
			$payment_label = __( 'Awaiting payment', 'lnkup-marketplace' );
		} else {
			$payment_label = $pay ? ucwords( str_replace( '_', ' ', $pay ) ) : __( 'Not required', 'lnkup-marketplace' );
		}

		if ( in_array( $status, array( 'cancelled', 'rejected' ), true ) ) {
			$event = __( 'Cancelled', 'lnkup-marketplace' );
		} elseif ( $passed ) {
			$event = __( 'Completed', 'lnkup-marketplace' );
		} else {
			$event = __( 'Upcoming', 'lnkup-marketplace' );
		}

		$primary = $application;
		if ( $payable && $passed ) {
			$primary = __( 'Invoice open', 'lnkup-marketplace' );
		} elseif ( $payable ) {
			$primary = __( 'Payment due', 'lnkup-marketplace' );
		} elseif ( 'paid' === $status && ! $passed ) {
			$primary = __( 'Confirmed', 'lnkup-marketplace' );
		} elseif ( in_array( $status, array( 'paid', 'completed' ), true ) && $passed ) {
			$primary = __( 'Completed', 'lnkup-marketplace' );
		}

		$withdrawable = class_exists( 'LNKup_Applications' ) && in_array( $status, LNKup_Applications::WITHDRAWABLE, true );
		$pay_blocks   = in_array( $pay, array( 'processing', 'paid', 'partially_paid' ), true );

		return array(
			'application' => $application,
			'booking'     => $booking,
			'payment'     => $payment_label,
			'event'       => $event,
			'primary'     => $primary,
			'is_past'     => (bool) $passed,
			'is_payable'  => (bool) $payable,
			'can_withdraw'=> $withdrawable && ! $pay_blocks && ! $passed,
		);
	}

	/**
	 * Customer label for a stored application status.
	 *
	 * @param string $status Stored status.
	 */
	private static function application_label( string $status ): string {
		if ( class_exists( 'LNKup_Vendor_Dashboard_Data' ) ) {
			return LNKup_Vendor_Dashboard_Data::application_status_label( $status );
		}
		return $status ? ucwords( str_replace( '_', ' ', $status ) ) : __( 'Submitted', 'lnkup-marketplace' );
	}

	/**
	 * Dollars from the uncapped payment CPT totals. Booth payments are spend.
	 *
	 * @param int $user_id Vendor user ID.
	 * @return array{outstanding:float,total_spent:float,currency:string,definition:string}
	 */
	public static function spend_summary( $user_id = 0 ): array {
		$user_id  = $user_id ? (int) $user_id : get_current_user_id();
		$payments = function_exists( 'lnkup_get_payments_for_user' ) ? lnkup_get_payments_for_user( $user_id, 'vendor' ) : array();
		$totals   = class_exists( 'LNKup_Vendor_Dashboard_Data' )
			? LNKup_Vendor_Dashboard_Data::payment_totals( $payments )
			: array( 'paid' => 0, 'outstanding' => 0 );
		$outstanding = (float) ( $totals['outstanding'] ?? 0 );
		if ( class_exists( 'LNKup_Financial_Service' ) ) {
			$contract = LNKup_Financial_Service::vendor_money_contract( $user_id );
			$cents    = (int) ( $contract['metrics']['outstanding']['cents'] ?? 0 );
			$outstanding = $cents / 100;
		}
		return array(
			'outstanding' => round( $outstanding, 2 ),
			'total_spent' => round( (float) ( $totals['paid'] ?? 0 ), 2 ),
			'currency'    => 'usd',
			'definition'  => 'vendor_booth_spend',
		);
	}

	/**
	 * Next action for a vendor on an event page, from existing drafts and applications.
	 * Returns an empty array when the vendor has no draft or application for the event.
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  Vendor user ID.
	 * @return array{state?:string,label?:string,href?:string,note?:string}
	 */
	public static function event_cta( int $event_id, int $user_id = 0 ): array {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $event_id || ! $user_id ) {
			return array();
		}
		$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
		$apps      = function_exists( 'lnkup_get_applications_for_user' ) ? lnkup_get_applications_for_user( $user_id, 'vendor' ) : array();
		foreach ( (array) $apps as $app ) {
			if ( ! $app instanceof WP_Post || (int) get_post_meta( $app->ID, '_lnkup_event_id', true ) !== $event_id ) {
				continue;
			}
			$status = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'rejected', 'cancelled' ), true ) ) {
				continue;
			}
			$detail = add_query_arg( array( 'view' => 'applications', 'app' => (int) $app->ID ), $dashboard );
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				return array(
					'state' => 'booked',
					'label' => __( 'View booking', 'lnkup-marketplace' ),
					'href'  => $detail,
					'note'  => __( 'You are booked for this event.', 'lnkup-marketplace' ),
				);
			}
			if ( LNKup_Vendor_Dashboard_Data::application_is_payable( $app, $user_id ) ) {
				return array(
					'state' => 'payment',
					'label' => __( 'Complete payment', 'lnkup-marketplace' ),
					'href'  => $detail,
					'note'  => __( 'You were approved. Pay the booth fee to confirm your spot.', 'lnkup-marketplace' ),
				);
			}
			return array(
				'state' => 'applied',
				'label' => __( 'View application', 'lnkup-marketplace' ),
				'href'  => $detail,
				'note'  => 'waitlisted' === $status
					? __( 'You are on the waitlist for this event.', 'lnkup-marketplace' )
					: __( 'You already applied. The organizer is reviewing it.', 'lnkup-marketplace' ),
			);
		}
		if ( class_exists( 'LNKup_Application_Drafts' ) ) {
			foreach ( LNKup_Application_Drafts::list_for_vendor( $user_id ) as $draft ) {
				if ( (int) ( $draft['event_id'] ?? 0 ) === $event_id && ! empty( $draft['can_submit'] ) ) {
					return array(
						'state' => 'draft',
						'label' => __( 'Continue application', 'lnkup-marketplace' ),
						'href'  => (string) $draft['resume_url'],
						'note'  => sprintf(
							/* translators: %d: percent complete */
							__( 'Your saved application is %d%% complete.', 'lnkup-marketplace' ),
							(int) ( $draft['progress_percent'] ?? 0 )
						),
					);
				}
			}
		}
		return array();
	}

	/**
	 * Rewrite unambiguous bad state codes once. Does not touch payment rows.
	 */
	public static function maybe_migrate_state_aliases(): void {
		if ( get_option( 'lnkup_state_alias_v1' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value = 'GA' WHERE meta_key = '_lnkup_state' AND LOWER(meta_value) = 'ge'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		update_option( 'lnkup_state_alias_v1', 1, false );
	}

	/**
	 * E2E seed rows carry `_lnkup_e2e_key`. They are test fixtures, never production data.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_e2e_fixture( int $post_id ): bool {
		return $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_lnkup_e2e_key', true );
	}

	/**
	 * Fixtures are hidden from reporting only on production. Local and staging keep them so tests see data.
	 */
	public static function exclude_fixtures_from_reporting(): bool {
		return 'production' === wp_get_environment_type();
	}

	/**
	 * One-time fix for E2E tour stops seeded with the default NC state. Only rows whose
	 * `_lnkup_e2e_key` names a known tour stop are touched, so real events are never changed.
	 */
	public static function maybe_fix_e2e_tour_states(): void {
		if ( get_option( 'lnkup_e2e_tour_state_v1' ) ) {
			return;
		}
		$stops = array(
			'event:tour-houston'   => 'TX',
			'event:tour-atlanta'   => 'GA',
			'event:tour-nashville' => 'TN',
		);
		foreach ( $stops as $key => $state ) {
			$ids = get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => 'any',
					'posts_per_page' => 5,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_e2e_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			foreach ( $ids as $event_id ) {
				update_post_meta( (int) $event_id, '_lnkup_state', $state );
				$opps = get_posts(
					array(
						'post_type'      => 'lnkup_opportunity',
						'post_status'    => 'any',
						'posts_per_page' => 20,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => (int) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				);
				foreach ( $opps as $opp_id ) {
					if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
						LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
					}
				}
			}
		}
		update_option( 'lnkup_e2e_tour_state_v1', 1, false );
	}
}

add_action( 'init', array( 'LNKup_Vendor_Presentation', 'maybe_migrate_state_aliases' ), 20 );
add_action( 'init', array( 'LNKup_Vendor_Presentation', 'maybe_fix_e2e_tour_states' ), 21 );
