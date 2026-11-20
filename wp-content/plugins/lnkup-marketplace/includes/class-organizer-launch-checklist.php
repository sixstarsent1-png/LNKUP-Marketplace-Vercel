<?php
/**
 * Organizer-wide launch checklist (derived from existing data).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Computes launch progress for organizer dashboard onboarding.
 */
class LNKup_Organizer_Launch_Checklist {

	const STATUS_NOT_STARTED = 'not_started';
	const STATUS_IN_PROGRESS = 'in_progress';
	const STATUS_COMPLETE    = 'complete';

	const META_DISMISSED = 'lnkup_organizer_tutorial_dismissed';
	const META_WELCOME   = 'lnkup_organizer_welcome_seen';

	/**
	 * Build full checklist payload.
	 *
	 * @param int                  $organizer_id Organizer user ID.
	 * @param array<string, mixed> $preload      Optional preloaded events, opps, apps, payments.
	 * @return array<string, mixed>
	 */
	public static function get_checklist( int $organizer_id, array $preload = array() ): array {
		$organizer_id = absint( $organizer_id );
		$dash_url     = lnkup_page_url_helper( 'organizer-dashboard' );

		$events   = isset( $preload['events'] ) ? $preload['events'] : self::load_events( $organizer_id );
		$events   = self::sort_events_asc( $events );
		$opps     = isset( $preload['opps'] ) ? $preload['opps'] : self::load_opportunities( $organizer_id, $events );
		$apps     = isset( $preload['apps'] ) ? $preload['apps'] : lnkup_get_applications_for_user( $organizer_id, 'organizer' );
		$payments = isset( $preload['payments'] ) ? $preload['payments'] : lnkup_get_payments_for_user( $organizer_id, 'organizer' );

		$primary_event = self::primary_event( $events );
		$primary_opp   = self::primary_opportunity( $opps, $primary_event );
		$event_id      = $primary_event ? (int) $primary_event->ID : 0;
		$opp_id        = $primary_opp ? (int) $primary_opp->ID : 0;

		$items = array(
			self::item_create_event( $events, $dash_url ),
			self::item_vendor_categories( $primary_opp, $dash_url ),
			self::item_booth_fee( $primary_opp, $dash_url ),
			self::item_vendor_packet( $primary_event, $dash_url ),
			self::item_payout_account( $organizer_id, $dash_url ),
			self::item_invite_vendors( $organizer_id, $primary_opp, $dash_url ),
			self::item_review_applications( $apps, $dash_url ),
			self::item_approve_vendor( $apps, $dash_url ),
			self::item_collect_payment( $apps, $payments, $dash_url ),
		);

		$completed = 0;
		foreach ( $items as $item ) {
			if ( self::STATUS_COMPLETE === $item['status'] ) {
				++$completed;
			}
		}
		$total        = count( $items );
		$all_complete = $completed >= $total && $total > 0;
		$dismissed    = $organizer_id > 0 && (bool) get_user_meta( $organizer_id, self::META_DISMISSED, true );
		$welcome_seen = $organizer_id > 0 && (bool) get_user_meta( $organizer_id, self::META_WELCOME, true );

		return array(
			'completed'            => $completed,
			'total'                => $total,
			'percent'              => $total > 0 ? (int) round( ( $completed / $total ) * 100 ) : 0,
			'all_complete'         => $all_complete,
			'dismissed'            => $dismissed,
			'show'                 => $all_complete || ! $dismissed,
			'show_welcome'         => ! $welcome_seen && ! $dismissed && ! $all_complete,
			'items'                => $items,
			'primary_event_id'     => $event_id,
			'primary_opportunity_id' => $opp_id,
		);
	}

	/**
	 * Dismiss incomplete launch checklist (keeps welcome marked seen).
	 *
	 * @param int $user_id Organizer user ID.
	 */
	public static function dismiss( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::META_DISMISSED, 1 );
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}
	}

	/**
	 * Mark organizer welcome modal as seen.
	 *
	 * @param int $user_id Organizer user ID.
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
	 * @param int $user_id Organizer user ID.
	 */
	public static function reset( int $user_id ): void {
		$user_id = absint( $user_id );
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_DISMISSED );
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}
	}

	/**
	 * @param int $organizer_id Organizer ID.
	 * @return array<int, WP_Post>
	 */
	private static function load_events( int $organizer_id ): array {
		return get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
	}

	/**
	 * @param int              $organizer_id Organizer ID.
	 * @param array<int, WP_Post> $events    Events.
	 * @return array<int, WP_Post>
	 */
	private static function load_opportunities( int $organizer_id, array $events ): array {
		if ( empty( $events ) ) {
			return array();
		}
		$event_ids = wp_list_pluck( $events, 'ID' );
		return get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => array(
					array(
						'key'     => '_lnkup_event_id',
						'value'   => $event_ids,
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * @param array<int, WP_Post> $events Events.
	 * @return array<int, WP_Post>
	 */
	private static function sort_events_asc( array $events ): array {
		if ( empty( $events ) ) {
			return $events;
		}
		usort(
			$events,
			function ( $a, $b ) {
				return strtotime( $a->post_date ) <=> strtotime( $b->post_date );
			}
		);
		return $events;
	}

	/**
	 * @param array<int, WP_Post> $events Events.
	 */
	private static function primary_event( array $events ): ?WP_Post {
		return ! empty( $events ) ? $events[0] : null;
	}

	/**
	 * @param array<int, WP_Post> $opps   Opportunities.
	 * @param WP_Post|null        $event Primary event.
	 */
	private static function primary_opportunity( array $opps, ?WP_Post $event ): ?WP_Post {
		if ( empty( $opps ) ) {
			return null;
		}
		if ( $event ) {
			foreach ( $opps as $opp ) {
				if ( (int) get_post_meta( $opp->ID, '_lnkup_event_id', true ) === (int) $event->ID ) {
					return $opp;
				}
			}
		}
		return $opps[0];
	}

	/**
	 * @param array<int, WP_Post> $events Events.
	 * @param string              $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_create_event( array $events, string $dash_url ): array {
		$complete = count( $events ) > 0;
		return array(
			'key'         => 'create_event',
			'title'       => lnkup_copy( 'launch_checklist_create_event' ),
			'description' => lnkup_copy( 'launch_checklist_create_event_desc' ),
			'status'      => $complete ? self::STATUS_COMPLETE : self::STATUS_NOT_STARTED,
			'cta_label'   => lnkup_copy( 'launch_checklist_create_event_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'create' ),
		);
	}

	/**
	 * @param WP_Post|null $opp Opportunity.
	 * @param string       $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_vendor_categories( ?WP_Post $opp, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		if ( $opp ) {
			$terms = wp_get_post_terms( $opp->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$status = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? self::STATUS_COMPLETE : self::STATUS_IN_PROGRESS;
		}
		return array(
			'key'         => 'vendor_categories',
			'title'       => lnkup_copy( 'launch_checklist_vendor_categories' ),
			'description' => lnkup_copy( 'launch_checklist_vendor_categories_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_vendor_categories_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'create' ),
			'event_id'    => $opp ? (int) get_post_meta( $opp->ID, '_lnkup_event_id', true ) : 0,
		);
	}

	/**
	 * @param WP_Post|null $opp Opportunity.
	 * @param string       $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_booth_fee( ?WP_Post $opp, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		if ( $opp ) {
			$fee    = (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true );
			$status = $fee > 0 ? self::STATUS_COMPLETE : self::STATUS_IN_PROGRESS;
		}
		return array(
			'key'         => 'booth_fee',
			'title'       => lnkup_copy( 'launch_checklist_booth_fee' ),
			'description' => lnkup_copy( 'launch_checklist_booth_fee_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_booth_fee_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'create' ),
			'event_id'    => $opp ? (int) get_post_meta( $opp->ID, '_lnkup_event_id', true ) : 0,
		);
	}

	/**
	 * @param WP_Post|null $event Event.
	 * @param string       $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_vendor_packet( ?WP_Post $event, string $dash_url ): array {
		$status  = self::STATUS_NOT_STARTED;
		$cta_url = lnkup_organizer_view_url( 'events' );
		if ( $event ) {
			$event_id = (int) $event->ID;
			$cta_url  = add_query_arg( array( 'event' => $event_id, 'ops' => 1, 'ops_tab' => 'vendor-pass' ), $dash_url );
			if ( class_exists( 'LNKup_Packets' ) ) {
				$completeness = LNKup_Packets::get_pass_completeness( $event_id );
				$complete     = (int) ( $completeness['complete'] ?? 0 );
				$total        = (int) ( $completeness['total'] ?? 6 );
				if ( $complete >= $total && $total > 0 ) {
					$status = self::STATUS_COMPLETE;
				} elseif ( $complete > 0 ) {
					$status = self::STATUS_IN_PROGRESS;
				}
			} elseif ( self::event_has_packet_essentials( $event_id ) ) {
				$status = self::STATUS_COMPLETE;
			} else {
				$status = self::STATUS_IN_PROGRESS;
			}
		}
		return array(
			'key'         => 'vendor_packet',
			'title'       => lnkup_copy( 'launch_checklist_vendor_packet' ),
			'description' => lnkup_copy( 'launch_checklist_vendor_packet_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_vendor_packet_cta' ),
			'cta_url'     => $cta_url,
			'event_id'    => $event ? (int) $event->ID : 0,
		);
	}

	/**
	 * @param int $event_id Event ID.
	 */
	private static function event_has_packet_essentials( int $event_id ): bool {
		if ( ! class_exists( 'LNKup_Packets' ) ) {
			return false;
		}
		$completeness = LNKup_Packets::get_pass_completeness( $event_id );
		$complete     = (int) ( $completeness['complete'] ?? 0 );
		$total        = (int) ( $completeness['total'] ?? 0 );
		return $total > 0 && $complete >= $total;
	}

	/**
	 * @param int    $organizer_id Organizer ID.
	 * @param string $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_payout_account( int $organizer_id, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		if ( class_exists( 'LNKup_Stripe_Connect' ) && ! LNKup_Stripe_Connect::is_enabled() ) {
			$status = self::STATUS_COMPLETE;
		} elseif ( class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_connect_ready( $organizer_id ) ) {
			$status = self::STATUS_COMPLETE;
		} elseif ( class_exists( 'LNKup_Stripe_Connect' ) ) {
			$connect = LNKup_Stripe_Connect::get_status( $organizer_id );
			if ( ! empty( $connect['account_id'] ) || ! empty( $connect['details_submitted'] ) ) {
				$status = self::STATUS_IN_PROGRESS;
			}
		}
		return array(
			'key'         => 'payout_account',
			'title'       => lnkup_copy( 'launch_checklist_payout_account' ),
			'description' => lnkup_copy( 'launch_checklist_payout_account_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_payout_account_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'financial' ),
		);
	}

	/**
	 * @param int          $organizer_id Organizer ID.
	 * @param WP_Post|null $opp Opportunity.
	 * @param string       $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_invite_vendors( int $organizer_id, ?WP_Post $opp, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		if ( self::organizer_has_sent_invites( $organizer_id ) ) {
			$status = self::STATUS_COMPLETE;
		} elseif ( $opp ) {
			$status = self::STATUS_IN_PROGRESS;
		}
		return array(
			'key'         => 'invite_vendors',
			'title'       => lnkup_copy( 'launch_checklist_invite_vendors' ),
			'description' => lnkup_copy( 'launch_checklist_invite_vendors_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_invite_vendors_cta' ),
			'cta_url'     => function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'vendors' ) : add_query_arg( 'view', 'vendors', $dash_url ),
		);
	}

	/**
	 * @param int $organizer_id Organizer ID.
	 */
	private static function organizer_has_sent_invites( int $organizer_id ): bool {
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_organizer_user_id',
				'meta_value'     => $organizer_id,
			)
		);
		if ( ! empty( $invites ) ) {
			return true;
		}
		if ( class_exists( 'LNKup_Outreach_Tables' ) && LNKup_Outreach_Tables::table_exists( LNKup_Outreach_Tables::CAMPAIGNS ) ) {
			global $wpdb;
			$table = LNKup_Outreach_Tables::table_name( LNKup_Outreach_Tables::CAMPAIGNS );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sent = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(sent), 0) FROM {$table} WHERE created_by = %d",
					$organizer_id
				)
			);
			if ( $sent > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int, WP_Post> $apps Applications.
	 * @param string              $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_review_applications( array $apps, string $dash_url ): array {
		$status   = self::STATUS_NOT_STARTED;
		$has_submitted = false;
		$has_reviewed  = false;
		foreach ( $apps as $app ) {
			$s = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( 'submitted' === $s ) {
				$has_submitted = true;
			}
			if ( ! in_array( $s, array( 'submitted', '' ), true ) ) {
				$has_reviewed = true;
			}
		}
		if ( $has_reviewed ) {
			$status = self::STATUS_COMPLETE;
		} elseif ( $has_submitted ) {
			$status = self::STATUS_IN_PROGRESS;
		} elseif ( ! empty( $apps ) ) {
			$status = self::STATUS_IN_PROGRESS;
		}
		return array(
			'key'         => 'review_applications',
			'title'       => lnkup_copy( 'launch_checklist_review_applications' ),
			'description' => lnkup_copy( 'launch_checklist_review_applications_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_review_applications_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'applications' ),
		);
	}

	/**
	 * @param array<int, WP_Post> $apps Applications.
	 * @param string              $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_approve_vendor( array $apps, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		$approved_statuses = array( 'approved', 'payment_required', 'paid', 'completed' );
		$has_approved = false;
		foreach ( $apps as $app ) {
			$s = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( in_array( $s, $approved_statuses, true ) ) {
				$has_approved = true;
				break;
			}
		}
		if ( $has_approved ) {
			$status = self::STATUS_COMPLETE;
		} elseif ( ! empty( $apps ) ) {
			$status = self::STATUS_IN_PROGRESS;
		}
		return array(
			'key'         => 'approve_vendor',
			'title'       => lnkup_copy( 'launch_checklist_approve_vendor' ),
			'description' => lnkup_copy( 'launch_checklist_approve_vendor_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_approve_vendor_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'applications' ),
		);
	}

	/**
	 * @param array<int, WP_Post> $apps Applications.
	 * @param array<int, WP_Post> $payments Payments.
	 * @param string              $dash_url Dashboard URL.
	 * @return array<string, mixed>
	 */
	private static function item_collect_payment( array $apps, array $payments, string $dash_url ): array {
		$status = self::STATUS_NOT_STARTED;
		foreach ( $payments as $payment ) {
			if ( 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				$status = self::STATUS_COMPLETE;
				break;
			}
		}
		if ( self::STATUS_COMPLETE !== $status ) {
			foreach ( $apps as $app ) {
				$s = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
				if ( in_array( $s, array( 'approved', 'payment_required' ), true ) ) {
					$status = self::STATUS_IN_PROGRESS;
					break;
				}
			}
		}
		return array(
			'key'         => 'collect_payment',
			'title'       => lnkup_copy( 'launch_checklist_collect_payment' ),
			'description' => lnkup_copy( 'launch_checklist_collect_payment_desc' ),
			'status'      => $status,
			'cta_label'   => lnkup_copy( 'launch_checklist_collect_payment_cta' ),
			'cta_url'     => lnkup_organizer_view_url( 'financial' ),
		);
	}

	/**
	 * Human-readable status label.
	 *
	 * @param string $status Status key.
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case self::STATUS_COMPLETE:
				return lnkup_copy( 'launch_checklist_status_complete' );
			case self::STATUS_IN_PROGRESS:
				return lnkup_copy( 'launch_checklist_status_in_progress' );
			default:
				return lnkup_copy( 'launch_checklist_status_not_started' );
		}
	}
}
