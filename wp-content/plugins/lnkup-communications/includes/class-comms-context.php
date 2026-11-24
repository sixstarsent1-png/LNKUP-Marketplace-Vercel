<?php
/**
 * Resolves canonical marketplace state for messages.
 *
 * Everything here READS lnkup-marketplace records (CPT meta, verification,
 * readiness, payments). Nothing writes business state. Validity rules are
 * evaluated when a message is scheduled AND again right before delivery.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Context {

	const PENDING_STATUSES   = array( 'submitted', 'under_review' );
	const ACTIVE_BOOKING     = array( 'approved', 'payment_required', 'paid', 'completed' );
	const CONFIRMED_BOOKING  = array( 'paid', 'completed' );
	const PAID_PAYMENT       = array( 'paid', 'completed', 'succeeded' );
	const PAYABLE_PAYMENT    = array( '', 'pending', 'sent' );
	const CANCELLED_EVENT    = array( 'cancelled', 'postponed' );

	/**
	 * Fill related IDs from whatever the caller supplied. Never trusts client input:
	 * callers pass IDs from verified server hooks only.
	 *
	 * @param array $ctx Partial context.
	 */
	public static function build( array $ctx ): array {
		$ctx = array_merge(
			array(
				'application_id'    => 0,
				'event_id'          => 0,
				'opportunity_id'    => 0,
				'payment_id'        => 0,
				'document_id'       => 0,
				'vendor_user_id'    => 0,
				'organizer_user_id' => 0,
				'profile_id'        => 0,
			),
			$ctx
		);
		foreach ( array( 'application_id', 'event_id', 'opportunity_id', 'payment_id', 'document_id', 'vendor_user_id', 'organizer_user_id', 'profile_id' ) as $key ) {
			$ctx[ $key ] = absint( $ctx[ $key ] );
		}

		if ( $ctx['payment_id'] && ! $ctx['application_id'] ) {
			$ctx['application_id'] = (int) get_post_meta( $ctx['payment_id'], '_lnkup_application_id', true );
		}
		$app = $ctx['application_id'];
		if ( $app && 'lnkup_application' === get_post_type( $app ) ) {
			$ctx['event_id']          = $ctx['event_id'] ?: (int) get_post_meta( $app, '_lnkup_event_id', true );
			$ctx['opportunity_id']    = $ctx['opportunity_id'] ?: (int) get_post_meta( $app, '_lnkup_opportunity_id', true );
			$ctx['vendor_user_id']    = $ctx['vendor_user_id'] ?: (int) get_post_meta( $app, '_lnkup_vendor_user_id', true );
			$ctx['organizer_user_id'] = $ctx['organizer_user_id'] ?: (int) get_post_meta( $app, '_lnkup_organizer_user_id', true );
			$ctx['profile_id']        = $ctx['profile_id'] ?: (int) get_post_meta( $app, '_lnkup_vendor_profile_id', true );
			if ( ! $ctx['payment_id'] && class_exists( 'LNKup_Payments' ) ) {
				$payment           = LNKup_Payments::get_for_application( $app );
				$ctx['payment_id'] = $payment ? (int) $payment->ID : 0;
			}
		}
		if ( $ctx['opportunity_id'] && ! $ctx['event_id'] ) {
			$ctx['event_id'] = (int) get_post_meta( $ctx['opportunity_id'], '_lnkup_event_id', true );
		}
		if ( $ctx['event_id'] && ! $ctx['organizer_user_id'] ) {
			$ctx['organizer_user_id'] = (int) get_post_meta( $ctx['event_id'], '_lnkup_organizer_user_id', true );
		}
		if ( $ctx['opportunity_id'] && ! $ctx['organizer_user_id'] ) {
			$ctx['organizer_user_id'] = (int) get_post_meta( $ctx['opportunity_id'], '_lnkup_organizer_user_id', true );
		}
		if ( $ctx['document_id'] && ! $ctx['vendor_user_id'] ) {
			$ctx['vendor_user_id'] = (int) get_post_meta( $ctx['document_id'], '_lnkup_vendor_user_id', true );
		}
		if ( $ctx['vendor_user_id'] && ! $ctx['profile_id'] && function_exists( 'lnkup_get_vendor_profile_id' ) ) {
			$ctx['profile_id'] = (int) lnkup_get_vendor_profile_id( $ctx['vendor_user_id'] );
		}
		return $ctx;
	}

	// ------------------------------------------------------------------ Canonical reads.

	public static function app_status( int $app_id ): string {
		return $app_id ? (string) get_post_meta( $app_id, '_lnkup_application_status', true ) : '';
	}

	public static function payment_status( int $payment_id ): string {
		return $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_payment_status', true ) : '';
	}

	public static function event_status( int $event_id ): string {
		return $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_status', true ) : '';
	}

	public static function event_cancelled( int $event_id ): bool {
		return in_array( self::event_status( $event_id ), self::CANCELLED_EVENT, true );
	}

	public static function event_timezone( int $event_id ): DateTimeZone {
		$tz = $event_id ? (string) get_post_meta( $event_id, '_lnkup_timezone', true ) : '';
		if ( '' !== $tz ) {
			try {
				return new DateTimeZone( $tz );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}
		return wp_timezone();
	}

	/**
	 * Event start in the event's timezone, or null when no date is stored.
	 */
	public static function event_start( int $event_id ): ?DateTimeImmutable {
		$date = $event_id ? trim( (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ) : '';
		if ( '' === $date ) {
			return null;
		}
		$time   = trim( (string) get_post_meta( $event_id, '_lnkup_start_time', true ) );
		$parsed = date_create_immutable( $date . ' ' . ( '' !== $time ? $time : '09:00' ), self::event_timezone( $event_id ) );
		return $parsed instanceof DateTimeImmutable ? $parsed : null;
	}

	public static function event_is_past( int $event_id ): bool {
		$start = self::event_start( $event_id );
		if ( ! $start ) {
			return false;
		}
		$end_of_day = $start->setTime( 23, 59, 59 );
		return new DateTimeImmutable( 'now', $start->getTimezone() ) > $end_of_day;
	}

	public static function payment_confirmed( int $app_id, int $payment_id ): bool {
		if ( $payment_id ) {
			return in_array( self::payment_status( $payment_id ), self::PAID_PAYMENT, true );
		}
		return class_exists( 'LNKup_Vendor_Readiness' ) && LNKup_Vendor_Readiness::application_payment_satisfied( $app_id );
	}

	public static function payment_due( int $app_id, int $payment_id ): bool {
		if ( ! in_array( self::app_status( $app_id ), array( 'approved', 'payment_required' ), true ) ) {
			return false;
		}
		if ( class_exists( 'LNKup_Vendor_Readiness' ) && LNKup_Vendor_Readiness::application_payment_satisfied( $app_id ) ) {
			return false;
		}
		if ( $payment_id ) {
			if ( get_post_meta( $payment_id, '_lnkup_voided_at', true ) ) {
				return false;
			}
			return in_array( self::payment_status( $payment_id ), self::PAYABLE_PAYMENT, true );
		}
		return true;
	}

	public static function document_code( int $app_id ): string {
		if ( ! $app_id || ! class_exists( 'LNKup_Verification' ) ) {
			return '';
		}
		$context = LNKup_Verification::get_application_document_context( $app_id );
		return (string) ( $context['code'] ?? '' );
	}

	/**
	 * Count applications by meta + status using found_posts (no row loading).
	 *
	 * @param array    $meta     meta_key => value equality filters.
	 * @param string[] $statuses Application statuses.
	 * @param int      $older_than_hours Only applications submitted before now-N hours.
	 */
	public static function count_apps( array $meta, array $statuses, int $older_than_hours = 0 ): int {
		$query = array( 'relation' => 'AND' );
		foreach ( $meta as $key => $value ) {
			$query[] = array(
				'key'   => $key,
				'value' => (string) $value,
			);
		}
		$query[] = array(
			'key'     => '_lnkup_application_status',
			'value'   => $statuses,
			'compare' => 'IN',
		);
		$args = array(
			'post_type'              => 'lnkup_application',
			'post_status'            => 'any',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'meta_query'             => $query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( $older_than_hours > 0 ) {
			$args['date_query'] = array(
				array(
					'before' => gmdate( 'Y-m-d H:i:s', time() - $older_than_hours * HOUR_IN_SECONDS ),
					'column' => 'post_date_gmt',
				),
			);
		}
		$wp_query = new WP_Query( $args );
		return (int) $wp_query->found_posts;
	}

	/**
	 * Application IDs for an event filtered by status.
	 *
	 * @return int[]
	 */
	public static function event_app_ids( int $event_id, array $statuses, int $limit = -1 ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_event_id',
						'value' => (string) $event_id,
					),
					array(
						'key'     => '_lnkup_application_status',
						'value'   => $statuses,
						'compare' => 'IN',
					),
				),
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Organizer events with a date on/after today (site time), excluding cancelled.
	 *
	 * @return int[]
	 */
	public static function organizer_upcoming_events( int $organizer_id ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_lnkup_organizer_user_id',
						'value' => (string) $organizer_id,
					),
					array(
						'key'     => '_lnkup_event_date',
						'value'   => wp_date( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			)
		);
		return array_values(
			array_filter(
				array_map( 'intval', (array) $ids ),
				static function ( int $id ): bool {
					return ! self::event_cancelled( $id );
				}
			)
		);
	}

	public static function opportunity_open( int $opp_id ): bool {
		if ( ! $opp_id || 'lnkup_opportunity' !== get_post_type( $opp_id ) || 'publish' !== get_post_status( $opp_id ) ) {
			return false;
		}
		$status = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
		if ( '' !== $status && 'open' !== $status ) {
			return false;
		}
		$deadline = trim( (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ) );
		if ( '' !== $deadline ) {
			$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			$due      = date_create_immutable( $deadline . ( strlen( $deadline ) <= 10 ? ' 23:59:59' : '' ), self::event_timezone( $event_id ) );
			if ( $due instanceof DateTimeImmutable && $due < new DateTimeImmutable( 'now', $due->getTimezone() ) ) {
				return false;
			}
		}
		$total = get_post_meta( $opp_id, '_lnkup_spots_total', true );
		if ( '' !== (string) $total && (int) $total > 0 && (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) <= 0 ) {
			return false;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		if ( $event_id && ( self::event_cancelled( $event_id ) || self::event_is_past( $event_id ) ) ) {
			return false;
		}
		return true;
	}

	public static function published_opportunity_count( int $event_id ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return count( (array) $ids );
	}

	public static function vendor_applied_to( int $vendor_id, int $opp_id ): bool {
		return self::count_apps(
			array(
				'_lnkup_vendor_user_id' => $vendor_id,
				'_lnkup_opportunity_id' => $opp_id,
			),
			array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'rejected', 'completed', 'cancelled' )
		) > 0;
	}

	// ------------------------------------------------------------------ Validity.

	/**
	 * @param string $rule Rule key from the trigger definition.
	 * @param array  $ctx  Built context.
	 * @return string '' when valid, otherwise a suppression reason.
	 */
	public static function check( string $rule, array $ctx ): string {
		if ( '' === $rule ) {
			return '';
		}
		if ( class_exists( 'LNKup_Comms_Partners' ) && LNKup_Comms_Partners::handles_rule( $rule ) ) {
			return LNKup_Comms_Partners::valid( $rule, $ctx ) ? '' : 'no_longer_relevant:' . $rule;
		}
		$app      = (int) $ctx['application_id'];
		$payment  = (int) $ctx['payment_id'];
		$event    = (int) $ctx['event_id'];
		$status   = self::app_status( $app );
		$valid    = true;

		switch ( $rule ) {
			case 'app_is_approved':
				$valid = in_array( $status, array( 'approved', 'payment_required' ), true );
				break;
			case 'app_is_rejected':
				$valid = 'rejected' === $status;
				break;
			case 'app_is_waitlisted':
				$valid = 'waitlisted' === $status;
				break;
			case 'app_info_requested':
				$valid = 'info_requested' === $status;
				break;
			case 'app_is_cancelled':
				$valid = 'cancelled' === $status;
				break;
			case 'payment_is_due':
				$valid = self::payment_due( $app, $payment ) && ! self::event_is_past( $event ) && ! self::event_cancelled( $event );
				break;
			case 'payment_failed':
				$valid = 'failed' === self::payment_status( $payment ) && ! get_post_meta( $payment, '_lnkup_voided_at', true ) && in_array( $status, array( 'approved', 'payment_required' ), true );
				break;
			case 'payment_confirmed':
				$valid = self::payment_confirmed( $app, $payment ) && in_array( $status, self::CONFIRMED_BOOKING, true );
				break;
			case 'payment_refunded':
				$valid = in_array( self::payment_status( $payment ), array( 'refunded', 'partially_refunded' ), true );
				break;
			case 'documents_outstanding':
				$valid = in_array( $status, self::ACTIVE_BOOKING, true ) && 'missing' === self::document_code( $app ) && ! self::event_is_past( $event );
				break;
			case 'document_approved':
				$valid = 'approved' === (string) get_post_meta( (int) $ctx['document_id'], '_lnkup_verification_status', true );
				break;
			case 'document_rejected':
				$valid = 'rejected' === (string) get_post_meta( (int) $ctx['document_id'], '_lnkup_verification_status', true );
				break;
			case 'booking_active':
				$valid = in_array( $status, self::ACTIVE_BOOKING, true ) && ! self::event_cancelled( $event ) && ! self::event_is_past( $event );
				break;
			case 'booking_upcoming':
				$valid = in_array( $status, self::CONFIRMED_BOOKING, true ) && ! self::event_cancelled( $event ) && ! self::event_is_past( $event );
				break;
			case 'event_is_cancelled':
				$valid = self::event_cancelled( $event );
				break;
			case 'event_not_cancelled_or_critical':
				$valid = 'critical' === ( $ctx['priority'] ?? '' ) || 'high' === ( $ctx['priority'] ?? '' ) || ! self::event_cancelled( $event );
				break;
			case 'event_upcoming':
				$valid = $event && ! self::event_cancelled( $event ) && ! self::event_is_past( $event );
				break;
			case 'event_accepting':
				$valid = $event && 'live' === self::event_status( $event ) && ! self::event_is_past( $event );
				break;
			case 'event_has_no_applications':
				$valid = $event && 'live' === self::event_status( $event ) && 0 === self::count_apps( array( '_lnkup_event_id' => $event ), array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'completed' ) );
				break;
			case 'org_has_pending':
				$meta  = $event ? array( '_lnkup_event_id' => $event ) : array( '_lnkup_organizer_user_id' => (int) $ctx['organizer_user_id'] );
				$valid = self::count_apps( $meta, self::PENDING_STATUSES ) > 0;
				break;
			case 'org_has_stale_pending':
				$valid = self::count_apps( array( '_lnkup_organizer_user_id' => (int) $ctx['organizer_user_id'] ), self::PENDING_STATUSES, max( 1, LNKup_Comms_Settings::int( 'pending_review_hours' ) ) ) > 0;
				break;
			case 'organizer_has_no_upcoming':
				$valid = array() === self::organizer_upcoming_events( (int) $ctx['organizer_user_id'] );
				break;
			case 'opportunity_still_matchable':
				$valid = self::opportunity_open( (int) $ctx['opportunity_id'] ) && ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				break;
			case 'match_still_recommended':
				$valid = self::opportunity_open( (int) $ctx['opportunity_id'] ) && ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				if ( $valid && LNKup_Comms_Matching::core_engine_ready() ) {
					$valid = LNKup_Match_Service::is_recommended( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				}
				break;
			case 'opportunity_still_eligible':
				$valid = self::opportunity_open( (int) $ctx['opportunity_id'] ) && ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				if ( $valid && LNKup_Comms_Matching::core_engine_ready() ) {
					$valid = LNKup_Match_Service::is_eligible( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				}
				break;
			case 'saved_still_open':
				$valid = self::opportunity_open( (int) $ctx['opportunity_id'] ) && ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] )
					&& '' !== trim( (string) get_post_meta( (int) $ctx['opportunity_id'], '_lnkup_application_deadline', true ) );
				break;
			case 'invite_still_open':
				$invite = (int) ( $ctx['invite_id'] ?? 0 );
				$state  = $invite ? (string) get_post_meta( $invite, '_lnkup_status', true ) : '';
				$expiry = $invite ? (string) get_post_meta( $invite, '_lnkup_expires_at', true ) : '';
				$valid  = 'lnkup_vendor_invite' === get_post_type( $invite )
					&& in_array( $state, array( 'sent', 'opened' ), true )
					&& ( '' === $expiry || strtotime( $expiry ) >= time() )
					&& ( ! (int) $ctx['opportunity_id'] || ( self::opportunity_open( (int) $ctx['opportunity_id'] ) && ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] ) ) );
				break;
			case 'tour_has_matching_stop':
				$tour  = (int) ( $ctx['tour_id'] ?? 0 );
				$valid = $tour && 'publish' === get_post_status( $tour ) && LNKup_Comms_Matching::core_engine_ready()
					&& array() !== LNKup_Match_Service::tour_matches_for_vendor( $tour, (int) $ctx['profile_id'] );
				break;
			case 'event_has_open_spots':
				$valid = $event && 'live' === self::event_status( $event ) && ! self::event_is_past( $event ) && LNKup_Comms_Matching::event_open_spots( $event ) > 0;
				break;
			case 'event_has_new_matches':
				$valid = $event && 'live' === self::event_status( $event ) && ! self::event_is_past( $event ) && (int) ( $ctx['count'] ?? 0 ) > 0;
				break;
			case 'vendor_onboarding_step':
				$valid = '' !== self::vendor_next_step( (int) $ctx['vendor_user_id'] )['key'];
				break;
			case 'organizer_onboarding_step':
				$valid = '' !== self::organizer_next_step( (int) $ctx['organizer_user_id'] )['key']
					&& self::guided_setup_open( (int) $ctx['organizer_user_id'] );
				break;
			case 'organizer_activation_stage':
				// Activation nudges: only while the stage they ask about is still incomplete.
				$valid = class_exists( 'LNKup_Organizer_Activation' )
					&& '' !== (string) ( $ctx['activation_stage'] ?? '' )
					&& 'complete' !== self::activation_stage_status( (int) $ctx['organizer_user_id'], (string) $ctx['activation_stage'] );
				break;
			case 'event_is_draft':
				$valid = $event && 'draft' === self::event_status( $event ) && ! self::event_is_past( $event );
				break;
			case 'invite_status_matches':
				$invite = (int) ( $ctx['invite_id'] ?? 0 );
				$valid  = 'lnkup_vendor_invite' === get_post_type( $invite ) && '' !== (string) ( $ctx['invite_status'] ?? '' )
					&& (string) get_post_meta( $invite, '_lnkup_status', true ) === (string) $ctx['invite_status'];
				break;
			case 'event_has_nearby_vendors':
				$valid = $event && 'live' === self::event_status( $event ) && ! self::event_is_past( $event )
					&& LNKup_Comms_Matching::event_open_spots( $event ) > 0 && (int) ( $ctx['count'] ?? 0 ) > 0;
				break;
			case 'event_has_no_slots':
				$valid = $event && 'publish' === get_post_status( $event ) && ! self::event_cancelled( $event ) && ! self::event_is_past( $event )
					&& 0 === self::published_opportunity_count( $event );
				break;
			case 'draft_still_open':
				$draft = (int) ( $ctx['draft_id'] ?? 0 );
				$valid = $draft && class_exists( 'LNKup_Application_Drafts' )
					&& LNKup_Application_Drafts::owns( $draft, (int) $ctx['vendor_user_id'] )
					&& LNKup_Application_Drafts::STATE_OPEN === (string) get_post_meta( $draft, '_lnkup_draft_state', true )
					&& self::opportunity_open( (int) $ctx['opportunity_id'] )
					&& ! self::vendor_applied_to( (int) $ctx['vendor_user_id'], (int) $ctx['opportunity_id'] );
				break;
			case 'vendor_still_inactive':
				$vendor = (int) $ctx['vendor_user_id'];
				$valid  = $vendor && LNKup_Comms_Engagement::vendor_last_activity( $vendor ) <= (int) ( $ctx['last_activity_ts'] ?? 0 )
					&& LNKup_Comms_Engagement::vendor_has_recommendations( $vendor );
				break;
			default:
				$valid = true;
		}
		return $valid ? '' : 'no_longer_relevant:' . $rule;
	}

	/**
	 * Status of one stage in the marketplace activation model (fresh, not cached).
	 */
	public static function activation_stage_status( int $user_id, string $stage ): string {
		if ( ! class_exists( 'LNKup_Organizer_Activation' ) || $user_id <= 0 ) {
			return '';
		}
		foreach ( LNKup_Organizer_Activation::for_user( $user_id, true )['stages'] as $s ) {
			if ( $s['key'] === $stage ) {
				return (string) $s['status'];
			}
		}
		return '';
	}

	// ------------------------------------------------------------------ Onboarding steps.

	/**
	 * First missing vendor profile item.
	 *
	 * @return array{key:string,label:string,link:string}
	 */
	public static function vendor_next_step( int $user_id ): array {
		$none       = array( 'key' => '', 'label' => '', 'link' => 'vendor_profile' );
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( $user_id ) : 0;
		if ( ! $profile_id ) {
			return array( 'key' => 'profile', 'label' => __( 'Create your vendor profile', 'lnkup-communications' ), 'link' => 'vendor_profile' );
		}
		$terms = wp_get_object_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array( 'key' => 'categories', 'label' => __( 'Choose your business categories', 'lnkup-communications' ), 'link' => 'vendor_profile' );
		}
		if ( '' === trim( wp_strip_all_tags( (string) get_post_field( 'post_content', $profile_id ) ) ) ) {
			return array( 'key' => 'description', 'label' => __( 'Add a short business description', 'lnkup-communications' ), 'link' => 'vendor_profile' );
		}
		if ( ! get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true ) ) {
			return array( 'key' => 'logo', 'label' => __( 'Upload your logo or a photo', 'lnkup-communications' ), 'link' => 'vendor_profile' );
		}
		if ( '' === trim( (string) get_post_meta( $profile_id, '_lnkup_city', true ) ) ) {
			return array( 'key' => 'city', 'label' => __( 'Add your city and the markets you serve', 'lnkup-communications' ), 'link' => 'vendor_profile' );
		}
		$applied = self::count_apps( array( '_lnkup_vendor_user_id' => $user_id ), array( 'submitted', 'under_review', 'info_requested', 'approved', 'payment_required', 'paid', 'waitlisted', 'rejected', 'completed', 'cancelled' ) );
		if ( 0 === $applied ) {
			return array( 'key' => 'first_application', 'label' => __( 'Apply to your first event', 'lnkup-communications' ), 'link' => 'vendor_find' );
		}
		return $none;
	}

	/**
	 * Organizer guided setup (marketplace onboarding v2) is still open: not finished and not
	 * dismissed. Without v2 there is no guided state, so onboarding nudges behave as before.
	 */
	public static function guided_setup_open( int $user_id ): bool {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding_Flow' ) || ! LNKup_Organizer_Onboarding_Flow::enabled() ) {
			return true;
		}
		$flow = LNKup_Organizer_Onboarding_Flow::for_user( $user_id );
		return ! $flow['is_complete'] && empty( $flow['state']['dismissed'] );
	}

	/**
	 * First missing organizer setup step, based only on data the product stores.
	 *
	 * @return array{key:string,label:string,link:string,event_id:int}
	 */
	public static function organizer_next_step( int $user_id ): array {
		// Same activation model as the organizer dashboard, when the marketplace provides it.
		if ( class_exists( 'LNKup_Organizer_Activation' ) && method_exists( 'LNKup_Organizer_Activation', 'comms_next_step' ) ) {
			return LNKup_Organizer_Activation::comms_next_step( $user_id );
		}
		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		if ( empty( $events ) ) {
			return array( 'key' => 'first_event', 'label' => __( 'Create your first event', 'lnkup-communications' ), 'link' => 'org_launch', 'event_id' => 0 );
		}
		foreach ( array_map( 'intval', $events ) as $event_id ) {
			if ( self::event_cancelled( $event_id ) || self::event_is_past( $event_id ) ) {
				continue;
			}
			$opps = get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => 'any',
					'posts_per_page' => 10,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (string) $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( empty( $opps ) ) {
				return array( 'key' => 'opportunity', 'label' => __( 'Add a vendor opportunity so vendors can apply', 'lnkup-communications' ), 'link' => 'org_event', 'event_id' => $event_id );
			}
			foreach ( array_map( 'intval', $opps ) as $opp_id ) {
				$terms = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'ids' ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					return array( 'key' => 'categories', 'label' => __( 'Select the vendor categories you are looking for', 'lnkup-communications' ), 'link' => 'org_event', 'event_id' => $event_id );
				}
				if ( '' === trim( (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ) ) ) {
					return array( 'key' => 'deadline', 'label' => __( 'Set an application deadline', 'lnkup-communications' ), 'link' => 'org_event', 'event_id' => $event_id );
				}
			}
			if ( 'draft' === self::event_status( $event_id ) ) {
				return array( 'key' => 'publish', 'label' => __( 'Open a vendor slot to publish your event', 'lnkup-communications' ), 'link' => 'org_event', 'event_id' => $event_id );
			}
		}
		if ( class_exists( 'LNKup_Stripe_Connect' ) && LNKup_Stripe_Connect::is_enabled() && ! LNKup_Stripe_Connect::is_connect_ready( $user_id ) ) {
			return array( 'key' => 'payouts', 'label' => __( 'Finish payout setup so vendor payments reach you', 'lnkup-communications' ), 'link' => 'org_money', 'event_id' => 0 );
		}
		return array( 'key' => '', 'label' => '', 'link' => 'org_launch', 'event_id' => 0 );
	}

	// ------------------------------------------------------------------ Variables.

	/**
	 * Template variables that can be safely resolved for this recipient.
	 *
	 * @param array $ctx       Built context (may include extra scalar keys from the caller).
	 * @param int   $recipient Recipient user ID.
	 */
	public static function variables( array $ctx, int $recipient ): array {
		$user      = get_userdata( $recipient );
		$first     = $user ? trim( (string) get_user_meta( $recipient, 'first_name', true ) ) : '';
		if ( '' === $first && $user ) {
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) ) ?: array( '' );
			$first = (string) $parts[0];
		}
		$event_id  = (int) $ctx['event_id'];
		$opp_id    = (int) $ctx['opportunity_id'];
		$event     = $event_id ? (string) get_the_title( $event_id ) : '';
		$opp_title = $opp_id ? (string) get_the_title( $opp_id ) : '';
		$start     = self::event_start( $event_id );
		$city      = $event_id ? trim( (string) get_post_meta( $event_id, '_lnkup_city', true ) ) : '';
		$state     = $event_id ? trim( (string) get_post_meta( $event_id, '_lnkup_state', true ) ) : '';
		$business  = (int) $ctx['profile_id'] ? (string) get_the_title( (int) $ctx['profile_id'] ) : '';
		$organizer = get_userdata( (int) $ctx['organizer_user_id'] );
		$due_raw   = (int) $ctx['application_id'] ? (string) get_post_meta( (int) $ctx['application_id'], '_lnkup_payment_due_at', true ) : '';
		$deadline  = $opp_id ? trim( (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true ) ) : '';
		$status    = self::app_status( (int) $ctx['application_id'] );

		$vars = array(
			'first_name'         => '' !== $first ? $first : '',
			'business_name'      => $business,
			'organizer_name'     => $organizer ? (string) $organizer->display_name : '',
			'event_name'         => '' !== $event ? $event : $opp_title,
			'opportunity_name'   => $opp_title,
			'event_date'         => $start ? wp_date( 'D, M j, Y', $start->getTimestamp(), $start->getTimezone() ) : '',
			'event_time'         => $start && get_post_meta( $event_id, '_lnkup_start_time', true ) ? wp_date( 'g:i a', $start->getTimestamp(), $start->getTimezone() ) : '',
			'event_city'         => trim( $city . ( '' !== $state ? ', ' . $state : '' ), ', ' ),
			'application_status' => '' !== $status && function_exists( 'lnkup_application_status_label' ) ? (string) lnkup_application_status_label( $status ) : $status,
			'payment_deadline'   => '' !== $due_raw ? wp_date( 'M j, Y', (int) strtotime( $due_raw . ' UTC' ), self::event_timezone( $event_id ) ) : '',
			'application_deadline' => '' !== $deadline ? wp_date( 'M j, Y', (int) strtotime( $deadline ), self::event_timezone( $event_id ) ) : '',
			'booking_reference'  => (int) $ctx['application_id'] ? 'LNK-' . (int) $ctx['application_id'] : '',
			'spots_remaining'    => $opp_id && '' !== (string) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) ? (string) (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) : '',
			'rejection_reason'   => (int) $ctx['application_id'] ? sanitize_text_field( (string) get_post_meta( (int) $ctx['application_id'], '_lnkup_rejection_reason', true ) ) : '',
			'event_status_note'  => $event_id ? sanitize_text_field( (string) get_post_meta( $event_id, '_lnkup_event_status_note', true ) ) : '',
			'match_reason'       => '',
			'tour_title'         => '',
		);
		if ( $opp_id && (int) $ctx['vendor_user_id'] && LNKup_Comms_Matching::core_engine_ready() ) {
			$reasons              = LNKup_Match_Service::alert_reasons( (int) $ctx['vendor_user_id'], $opp_id );
			$vars['match_reason'] = (string) ( $reasons[0] ?? '' );
		}
		$tour_id = (int) ( $ctx['tour_id'] ?? 0 );
		if ( $tour_id && 'lnkup_tour' === get_post_type( $tour_id ) ) {
			$vars['tour_title'] = (string) get_the_title( $tour_id );
		}

		// Caller-provided scalar extras (counts, windows, notes). Never overrides resolved records.
		foreach ( $ctx as $key => $value ) {
			if ( ! isset( $vars[ $key ] ) && is_string( $key ) && 0 !== strpos( $key, '_' ) && is_scalar( $value ) && ! in_array( $key, array( 'application_id', 'event_id', 'opportunity_id', 'payment_id', 'document_id', 'vendor_user_id', 'organizer_user_id', 'profile_id' ), true ) ) {
				$vars[ $key ] = (string) $value;
			}
		}
		return $vars;
	}

	/**
	 * Values that must reflect state at SEND time (counts, summaries, next step).
	 * Only computed when the template references them.
	 *
	 * @param string[] $needed Placeholders used by the template.
	 */
	public static function live_variables( array $needed, array $ctx, int $recipient ): array {
		$out   = array();
		$event = (int) $ctx['event_id'];
		$org   = (int) $ctx['organizer_user_id'] ?: $recipient;
		foreach ( $needed as $name ) {
			switch ( $name ) {
				case 'pending_count':
					$out[ $name ] = (string) self::count_apps( $event ? array( '_lnkup_event_id' => $event ) : array( '_lnkup_organizer_user_id' => $org ), self::PENDING_STATUSES );
					break;
				case 'stale_pending_count':
					$out[ $name ] = (string) self::count_apps( array( '_lnkup_organizer_user_id' => $org ), self::PENDING_STATUSES, max( 1, LNKup_Comms_Settings::int( 'pending_review_hours' ) ) );
					break;
				case 'hours':
					$out[ $name ] = (string) max( 1, LNKup_Comms_Settings::int( 'pending_review_hours' ) );
					break;
				case 'confirmed_count':
					$out[ $name ] = (string) self::count_apps( array( '_lnkup_event_id' => $event ), self::CONFIRMED_BOOKING );
					break;
				case 'checked_in_count':
					$checked = 0;
					foreach ( self::event_app_ids( $event, self::CONFIRMED_BOOKING, 1000 ) as $app_id ) {
						if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
							++$checked;
						}
					}
					$out[ $name ] = (string) $checked;
					break;
				case 'event_summary_line':
					$out[ $name ] = self::event_summary_line( $event );
					break;
				case 'next_step':
					$step         = 'organizer' === ( $ctx['_role'] ?? '' ) ? self::organizer_next_step( $org ) : self::vendor_next_step( (int) $ctx['vendor_user_id'] ?: $recipient );
					$out[ $name ] = (string) $step['label'];
					break;
			}
		}
		return $out;
	}

	/**
	 * One-line, real-number readiness summary for an event.
	 */
	public static function event_summary_line( int $event_id ): string {
		if ( ! $event_id ) {
			return '';
		}
		$meta      = array( '_lnkup_event_id' => $event_id );
		$confirmed = self::count_apps( $meta, self::CONFIRMED_BOOKING );
		$pending   = self::count_apps( $meta, self::PENDING_STATUSES );
		$unpaid    = self::count_apps( $meta, array( 'approved', 'payment_required' ) );
		$missing   = 0;
		foreach ( self::event_app_ids( $event_id, self::CONFIRMED_BOOKING, 500 ) as $app_id ) {
			if ( 'missing' === self::document_code( $app_id ) ) {
				++$missing;
			}
		}
		if ( 0 === $confirmed + $pending + $unpaid ) {
			return __( 'No vendor applications yet. Invite vendors to start filling your slots.', 'lnkup-communications' );
		}
		$parts = array(
			/* translators: %d: count */
			sprintf( _n( '%d confirmed vendor', '%d confirmed vendors', $confirmed, 'lnkup-communications' ), $confirmed ),
			/* translators: %d: count */
			sprintf( _n( '%d awaiting review', '%d awaiting review', $pending, 'lnkup-communications' ), $pending ),
			/* translators: %d: count */
			sprintf( _n( '%d approved but unpaid', '%d approved but unpaid', $unpaid, 'lnkup-communications' ), $unpaid ),
		);
		if ( $missing > 0 ) {
			/* translators: %d: count */
			$parts[] = sprintf( _n( '%d missing documents', '%d missing documents', $missing, 'lnkup-communications' ), $missing );
		}
		return implode( ' · ', $parts ) . '.';
	}

	// ------------------------------------------------------------------ Links.

	/**
	 * In-app route (validated against the mobile allowlist shapes).
	 */
	public static function deep_link( string $link, array $ctx, string $role ): string {
		if ( class_exists( 'LNKup_Comms_Partners' ) && LNKup_Comms_Partners::handles_link( $link ) ) {
			return LNKup_Comms_Partners::deep_link( $link, $ctx, $role );
		}
		$app   = (int) $ctx['application_id'];
		$event = (int) $ctx['event_id'];
		$opp   = (int) $ctx['opportunity_id'];
		switch ( $link ) {
			case 'vendor_step':
				return self::deep_link( self::vendor_next_step( (int) $ctx['vendor_user_id'] )['link'], $ctx, $role );
			case 'org_step':
				$step = self::organizer_next_step( (int) $ctx['organizer_user_id'] );
				return self::deep_link( $step['link'], array_merge( $ctx, array( 'event_id' => (int) $step['event_id'] ) ), $role );
			case 'booking':
				return $app ? '/booking/' . $app : '/(vendor)/requests';
			case 'approval':
				return self::payment_due( $app, (int) $ctx['payment_id'] ) ? '/(vendor)/wallet' : ( $app ? '/pass/' . $app : '/(vendor)/requests' );
			case 'pass':
				return $app ? '/pass/' . $app : '/(vendor)/operations';
			case 'wallet':
				return '/(vendor)/wallet';
			case 'documents':
				return '/(vendor)/documents';
			case 'opportunity':
				return $opp ? '/opportunity/' . $opp : '/(vendor)/find';
			case 'vendor_find':
				return '/(vendor)/find';
			case 'tour':
				return (int) ( $ctx['tour_id'] ?? 0 ) ? '/tour/' . (int) $ctx['tour_id'] : '/(vendor)/find';
			case 'invite':
				return $opp ? '/opportunity/' . $opp : '/(vendor)/find';
			case 'vendor_saved_searches':
				return '/(vendor)/saved-searches';
			case 'vendor_invites':
				return '/(vendor)/invitations';
			case 'org_invites':
				return '/(organizer)/vendors?tab=invites';
			case 'org_find_vendors':
				return $event ? '/(organizer)/vendors/find?event=' . $event : '/(organizer)/vendors/find';
			case 'vendor_profile':
				return '/(vendor)/profile';
			case 'passport':
				$tab = sanitize_key( (string) ( $ctx['passport_tab'] ?? '' ) );
				return '/(vendor)/passport' . ( in_array( $tab, array( 'reviews', 'history', 'portfolio', 'verification', 'documents', 'settings' ), true ) ? '?tab=' . $tab : '' );
			case 'org_review':
				return $event ? '/(organizer)/vendors?event=' . $event : '/(organizer)/vendors';
			case 'org_money':
				return '/(organizer)/money';
			case 'org_profile':
				return '/(organizer)/profile';
			case 'org_event':
				return $event ? '/(organizer)/events/' . $event : '/(organizer)/events';
			case 'org_vendors':
				return '/(organizer)/vendors';
			case 'org_launch':
				return '/(organizer)/launch';
			case 'org_home':
				return '/(organizer)';
			case 'org_checkin':
				return $event ? '/(organizer)/events/checkin?event=' . $event : '/(organizer)/events';
			case 'org_comms':
				return $event ? '/(organizer)/event-comms/' . $event : '/(organizer)/events';
			default:
				return 'organizer' === $role ? '/(organizer)/notifications' : '/(vendor)/notifications';
		}
	}

	/**
	 * Website URL for emails / web inbox.
	 */
	public static function web_url( string $link, array $ctx, string $role ): string {
		if ( class_exists( 'LNKup_Comms_Partners' ) && LNKup_Comms_Partners::handles_link( $link ) ) {
			return LNKup_Comms_Partners::web_url( $link, $ctx, $role );
		}
		$app        = (int) $ctx['application_id'];
		$event      = (int) $ctx['event_id'];
		$opp        = (int) $ctx['opportunity_id'];
		$vendor_dash = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'vendor-dashboard' ) : home_url( '/vendor-dashboard/' );
		$org        = static function ( string $view, array $args = array() ): string {
			return function_exists( 'lnkup_organizer_view_url' ) ? (string) lnkup_organizer_view_url( $view, $args ) : add_query_arg( array_merge( array( 'view' => $view ), $args ), home_url( '/organizer-dashboard/' ) );
		};
		switch ( $link ) {
			case 'vendor_step':
				return self::web_url( self::vendor_next_step( (int) $ctx['vendor_user_id'] )['link'], $ctx, $role );
			case 'org_step':
				$step = self::organizer_next_step( (int) $ctx['organizer_user_id'] );
				return self::web_url( $step['link'], array_merge( $ctx, array( 'event_id' => (int) $step['event_id'] ) ), $role );
			case 'booking':
				return add_query_arg( 'view', 'applications', $vendor_dash );
			case 'approval':
				return self::payment_due( $app, (int) $ctx['payment_id'] ) ? add_query_arg( 'view', 'payments', $vendor_dash ) : self::web_url( 'pass', $ctx, $role );
			case 'pass':
				return $app ? add_query_arg( array( 'view' => 'operations', 'app' => $app, 'tab' => 'pass' ), $vendor_dash ) : add_query_arg( 'view', 'operations', $vendor_dash );
			case 'wallet':
				return add_query_arg( 'view', 'payments', $vendor_dash );
			case 'documents':
				return add_query_arg( 'view', 'documents', $vendor_dash );
			case 'opportunity':
				$permalink = $opp ? get_permalink( $opp ) : false;
				return $permalink ? (string) $permalink : self::web_url( 'vendor_find', $ctx, $role );
			case 'vendor_find':
				return function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'opportunities' ) : home_url( '/opportunities/' );
			case 'invite':
				$invite_url = (string) ( $ctx['invite_url'] ?? '' );
				// Only same-site invite links; anything else falls back to the opportunity.
				return '' !== $invite_url && wp_validate_redirect( $invite_url, '' ) ? $invite_url : self::web_url( 'opportunity', $ctx, $role );
			case 'tour':
				$find = self::web_url( 'vendor_find', $ctx, $role );
				return (int) ( $ctx['tour_id'] ?? 0 ) ? add_query_arg( 'tour_id', (int) $ctx['tour_id'], $find ) : $find;
			case 'vendor_invites':
				return add_query_arg( 'view', 'invitations', $vendor_dash );
			case 'org_invites':
				return $org( 'invites' );
			case 'org_find_vendors':
				return $org( 'vendors', array( 'recruit' => $event ? $event : '' ) );
			case 'vendor_saved_searches':
				return ( function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'favorites' ) : home_url( '/favorites/' ) ) . '#lnkup-saved-searches-panel';
			case 'vendor_profile':
				return function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'vendor-profile' ) : $vendor_dash;
			case 'passport':
				$tab = sanitize_key( (string) ( $ctx['passport_tab'] ?? '' ) );
				return add_query_arg( array_filter( array( 'view' => 'passport', 'tab' => $tab ) ), $vendor_dash );
			case 'org_review':
				return $org( 'applications', array( 'event_filter' => $event ? $event : '', 'filter' => 'pending' ) );
			case 'org_money':
				return $org( 'financial' );
			case 'org_profile':
				return $org( 'start', array( 'step' => 'organization' ) );
			case 'org_event':
				return $org( 'events', array( 'event' => $event ? $event : '' ) );
			case 'org_vendors':
				return $org( 'vendors' );
			case 'org_launch':
				return $org( 'create' );
			case 'org_checkin':
				return $org( 'operations', array( 'event' => $event ? $event : '' ) );
			case 'org_comms':
				$page = function_exists( 'lnkup_page_url_helper' ) ? (string) lnkup_page_url_helper( 'event-communications' ) : home_url( '/event-communications/' );
				return $event ? add_query_arg( 'event', $event, $page ) : $org( 'events' );
			case 'org_home':
				return $org( 'home' );
			default:
				return 'organizer' === $role ? $org( 'home' ) : $vendor_dash;
		}
	}
}
