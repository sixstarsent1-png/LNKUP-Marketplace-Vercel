<?php
/**
 * Vendor booking contract shared by the web dashboard and the native app.
 *
 * Resolves the event behind an application (live event, the opportunity's event, then a stored
 * snapshot), derives end-date-aware event state, and splits application / booking / payment /
 * credential state so clients never infer one from another.
 *
 * Nothing here changes stored statuses, payments or invoices.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Booking_Contract {

	const SNAPSHOT_META = '_lnkup_event_snapshot';

	/** Payment statuses that still represent money owed. */
	const OPEN_PAYMENT_STATUSES = array( 'pending', 'failed', 'partially_paid', 'overdue' );

	/** Payment statuses that revoke a credential. */
	const REVOKING_PAYMENT_STATUSES = array( 'refunded', 'chargeback_lost', 'disputed' );

	public static function init(): void {
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'capture_snapshot' ), 20 );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'capture_snapshot' ), 20 );
		add_action( 'save_post_lnkup_event', array( __CLASS__, 'refresh_event_snapshots' ), 30 );
	}

	/**
	 * Valid event post ID or 0.
	 *
	 * @param int $event_id Candidate.
	 */
	private static function valid_event( int $event_id ): int {
		return $event_id > 0 && 'lnkup_event' === get_post_type( $event_id ) ? $event_id : 0;
	}

	/**
	 * Event behind an application and where it came from.
	 *
	 * @param int $app_id Application ID.
	 * @return array{event_id:int,source:string,snapshot:array<string,mixed>}
	 */
	public static function resolve_event( int $app_id ): array {
		$stored = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( self::valid_event( $stored ) ) {
			return array( 'event_id' => $stored, 'source' => 'live', 'snapshot' => array() );
		}
		$opp_id    = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$opp_event = $opp_id ? self::valid_event( (int) get_post_meta( $opp_id, '_lnkup_event_id', true ) ) : 0;
		if ( $opp_event ) {
			return array( 'event_id' => $opp_event, 'source' => 'opportunity', 'snapshot' => array() );
		}
		$snapshot = self::snapshot( $app_id );
		if ( '' !== (string) ( $snapshot['title'] ?? '' ) ) {
			return array( 'event_id' => 0, 'source' => 'snapshot', 'snapshot' => $snapshot );
		}
		return array( 'event_id' => 0, 'source' => 'unrecoverable', 'snapshot' => array() );
	}

	/**
	 * Stored snapshot (empty array when none).
	 *
	 * @param int $app_id Application ID.
	 * @return array<string,mixed>
	 */
	public static function snapshot( int $app_id ): array {
		$raw = get_post_meta( $app_id, self::SNAPSHOT_META, true );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Organizer public name for an event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function organizer_name( int $event_id ): string {
		if ( ! $event_id ) {
			return '';
		}
		$org_id = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( ! $org_id ) {
			$org_id = (int) get_post_field( 'post_author', $event_id );
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
	 * Values copied from a live event so a booking stays recognizable if the event is removed.
	 *
	 * @param int $event_id Event ID.
	 * @param int $opp_id   Opportunity ID.
	 * @return array<string,mixed>
	 */
	public static function build_snapshot( int $event_id, int $opp_id ): array {
		$media = class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::resolve( $event_id, $opp_id ) : array();
		return array(
			'event_id'          => $event_id,
			'title'             => class_exists( 'LNKup_Vendor_Presentation' ) ? LNKup_Vendor_Presentation::customer_event_title( $event_id ) : (string) get_the_title( $event_id ),
			'date'              => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
			'end_date'          => (string) get_post_meta( $event_id, '_lnkup_event_end_date', true ),
			'start_time'        => (string) get_post_meta( $event_id, '_lnkup_start_time', true ),
			'timezone'          => (string) get_post_meta( $event_id, '_lnkup_timezone', true ),
			'city'              => (string) get_post_meta( $event_id, '_lnkup_city', true ),
			'state'             => (string) get_post_meta( $event_id, '_lnkup_state', true ),
			'venue'             => (string) get_post_meta( $event_id, '_lnkup_venue_name', true ),
			'organizer_name'    => self::organizer_name( $event_id ),
			'media_url'         => (string) ( $media['medium_url'] ?? $media['url'] ?? '' ),
			'opportunity_title' => $opp_id && function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $opp_id ) : '',
			'captured_at'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Write the snapshot from the live event. Never overwrites with empty data.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function capture_snapshot( $app_id ): void {
		$app_id = (int) $app_id;
		if ( $app_id < 1 || 'lnkup_application' !== get_post_type( $app_id ) ) {
			return;
		}
		$resolved = self::resolve_event( $app_id );
		if ( ! $resolved['event_id'] ) {
			return;
		}
		$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$snapshot = self::build_snapshot( (int) $resolved['event_id'], $opp_id );
		if ( '' === (string) $snapshot['title'] || __( 'Event details unavailable', 'lnkup-marketplace' ) === $snapshot['title'] ) {
			return;
		}
		update_post_meta( $app_id, self::SNAPSHOT_META, $snapshot );
	}

	/**
	 * Refresh snapshots of applications linked to an edited event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function refresh_event_snapshots( $event_id ): void {
		$event_id = (int) $event_id;
		if ( wp_is_post_revision( $event_id ) || wp_is_post_autosave( $event_id ) ) {
			return;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		foreach ( $apps as $app_id ) {
			self::capture_snapshot( (int) $app_id );
		}
	}

	/**
	 * Lifecycle state of an event: upcoming, active, completed, cancelled, postponed or unknown.
	 * Uses the end date when one exists, the start date otherwise, in the event's timezone.
	 *
	 * @param int                 $event_id Event ID (0 to use the snapshot).
	 * @param array<string,mixed> $snapshot Snapshot values used when the event is gone.
	 */
	public static function event_state( int $event_id, array $snapshot = array() ): string {
		if ( $event_id ) {
			$status = (string) get_post_meta( $event_id, '_lnkup_event_status', true );
			if ( in_array( $status, array( 'cancelled', 'postponed' ), true ) ) {
				return $status;
			}
			$start = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			$end   = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
			$tz    = (string) get_post_meta( $event_id, '_lnkup_timezone', true );
		} else {
			$start = (string) ( $snapshot['date'] ?? '' );
			$end   = (string) ( $snapshot['end_date'] ?? '' );
			$tz    = (string) ( $snapshot['timezone'] ?? '' );
		}
		return self::state_for_dates( $start, $end, $tz );
	}

	/**
	 * Pure date comparison so it can be unit tested.
	 *
	 * @param string $start Y-m-d start.
	 * @param string $end   Y-m-d end or empty.
	 * @param string $tz    Timezone name or empty for site timezone.
	 * @param string $today Y-m-d override for tests.
	 */
	public static function state_for_dates( string $start, string $end, string $tz = '', string $today = '' ): string {
		$start = substr( trim( $start ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			return 'unknown';
		}
		$end = substr( trim( $end ), 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) || $end < $start ) {
			$end = $start;
		}
		if ( '' === $today ) {
			$zone = wp_timezone();
			if ( '' !== $tz ) {
				try {
					$zone = new DateTimeZone( $tz );
				} catch ( Exception $e ) {
					unset( $e );
				}
			}
			$today = ( new DateTimeImmutable( 'now', $zone ) )->format( 'Y-m-d' );
		}
		if ( $today < $start ) {
			return 'upcoming';
		}
		if ( $today > $end ) {
			return 'completed';
		}
		return 'active';
	}

	/**
	 * Separate customer-facing state dimensions from stored statuses.
	 *
	 * @param string $app_status  Stored application status.
	 * @param string $pay_status  Payment CPT status or empty.
	 * @param string $event_state event_state().
	 * @return array{application_state:string,booking_state:string,payment_state:string}
	 */
	public static function state_dimensions( string $app_status, string $pay_status, string $event_state ): array {
		$application = 'submitted';
		if ( in_array( $app_status, array( 'draft' ), true ) ) {
			$application = 'draft';
		} elseif ( in_array( $app_status, array( 'under_review', 'info_requested' ), true ) ) {
			$application = 'info_requested' === $app_status ? 'info_requested' : 'reviewing';
		} elseif ( in_array( $app_status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
			$application = 'approved';
		} elseif ( 'rejected' === $app_status ) {
			$application = 'declined';
		} elseif ( 'waitlisted' === $app_status ) {
			$application = 'waitlisted';
		} elseif ( 'cancelled' === $app_status ) {
			$application = 'withdrawn';
		}

		$booking = 'none';
		if ( in_array( $app_status, array( 'paid', 'completed' ), true ) ) {
			$booking = ( 'completed' === $app_status || 'completed' === $event_state ) ? 'completed' : 'confirmed';
		} elseif ( in_array( $app_status, array( 'approved', 'payment_required' ), true ) ) {
			$booking = 'pending_confirmation';
		} elseif ( 'cancelled' === $app_status ) {
			$booking = 'cancelled';
		}
		if ( 'cancelled' === $event_state && in_array( $booking, array( 'confirmed', 'pending_confirmation' ), true ) ) {
			$booking = 'cancelled';
		}

		$payment = 'not_required';
		if ( in_array( $pay_status, array( 'paid' ), true ) || ( '' === $pay_status && in_array( $app_status, array( 'paid', 'completed' ), true ) ) ) {
			$payment = 'paid';
		} elseif ( 'partially_paid' === $pay_status ) {
			$payment = 'partially_paid';
		} elseif ( in_array( $pay_status, array( 'refunded', 'partially_refunded' ), true ) ) {
			$payment = 'refunded';
		} elseif ( in_array( $pay_status, array( 'disputed', 'chargeback_lost' ), true ) ) {
			$payment = 'disputed';
		} elseif ( in_array( $pay_status, self::OPEN_PAYMENT_STATUSES, true ) ) {
			$payment = 'completed' === $event_state || 'overdue' === $pay_status ? 'overdue' : 'unpaid';
		} elseif ( 'processing' === $pay_status ) {
			$payment = 'processing';
		} elseif ( in_array( $pay_status, array( 'void', 'cancelled' ), true ) ) {
			$payment = 'void';
		}

		return array(
			'application_state' => $application,
			'booking_state'     => $booking,
			'payment_state'     => $payment,
		);
	}

	/**
	 * Whether an open balance exists and how it relates to the event.
	 * Collectibility follows the payment record only; nothing is written off here.
	 *
	 * @param string $app_status  Application status.
	 * @param string $pay_status  Payment status.
	 * @param string $event_state Event state.
	 * @return array{has_balance:bool,past_event_invoice:bool,collectible:bool}
	 */
	public static function payment_context( string $app_status, string $pay_status, string $event_state ): array {
		$open        = in_array( $pay_status, self::OPEN_PAYMENT_STATUSES, true );
		$collectible = $open && ! in_array( $app_status, array( 'rejected', 'cancelled' ), true );
		return array(
			'has_balance'        => $open,
			'past_event_invoice' => $open && 'completed' === $event_state,
			'collectible'        => $collectible,
		);
	}

	/**
	 * Credential state for the event pass.
	 *
	 * @param bool   $unlocked    Pass unlocked by existing rules.
	 * @param string $app_status  Application status.
	 * @param string $pay_status  Payment status.
	 * @param string $event_state Event state.
	 */
	public static function credential_state( bool $unlocked, string $app_status, string $pay_status, string $event_state ): string {
		if ( in_array( $pay_status, self::REVOKING_PAYMENT_STATUSES, true ) || in_array( $app_status, array( 'rejected', 'cancelled' ), true ) || 'cancelled' === $event_state ) {
			return 'revoked';
		}
		if ( ! $unlocked ) {
			return 'locked';
		}
		if ( 'completed' === $event_state ) {
			return 'ended';
		}
		return 'active';
	}

	/**
	 * Booking timeline built only from stored timestamps. Steps without evidence stay pending.
	 *
	 * @param int    $app_id     Application ID.
	 * @param string $app_status Application status.
	 * @param string $pay_status Payment status.
	 * @param int    $payment_id Payment ID.
	 * @return array<int,array{key:string,label:string,state:string,at:string}>
	 */
	public static function timeline( int $app_id, string $app_status, string $pay_status, int $payment_id ): array {
		$history = class_exists( 'LNKup_Applications' ) ? LNKup_Applications::status_history( $app_id ) : array();
		$at      = static function ( array $statuses ) use ( $history ): string {
			foreach ( $history as $row ) {
				if ( is_array( $row ) && in_array( (string) ( $row['to'] ?? $row['status'] ?? '' ), $statuses, true ) ) {
					return (string) ( $row['at'] ?? $row['date'] ?? $row['created_at'] ?? '' );
				}
			}
			return '';
		};
		$submitted = (string) ( get_post_meta( $app_id, '_lnkup_submitted_at', true ) ?: get_post_field( 'post_date', $app_id ) );
		$reviewed  = $at( array( 'under_review', 'info_requested' ) );
		$approved  = (string) ( get_post_meta( $app_id, '_lnkup_approved_at', true ) ?: $at( array( 'approved', 'payment_required' ) ) );
		$paid_at   = $payment_id ? (string) get_post_meta( $payment_id, '_lnkup_paid_at', true ) : '';
		$checked   = '';
		if ( class_exists( 'LNKup_Checkins' ) && class_exists( 'LNKup_Operations_Tables' ) ) {
			foreach ( (array) LNKup_Checkins::history( $app_id ) as $row ) {
				if ( in_array( (string) ( $row['action'] ?? '' ), array( 'checked_in', 'late_arrival' ), true ) ) {
					$checked = (string) ( $row['created_at'] ?? '' );
					break;
				}
			}
		}
		$approved_done = in_array( $app_status, array( 'approved', 'payment_required', 'paid', 'completed' ), true );
		$paid_done     = 'paid' === $pay_status || in_array( $app_status, array( 'paid', 'completed' ), true );
		$reviewed_done = $approved_done || in_array( $app_status, array( 'under_review', 'info_requested', 'rejected', 'waitlisted' ), true );

		$steps = array(
			array( 'key' => 'submitted', 'label' => __( 'Application submitted', 'lnkup-marketplace' ), 'done' => true, 'at' => $submitted ),
			array( 'key' => 'reviewed', 'label' => __( 'Organizer review', 'lnkup-marketplace' ), 'done' => $reviewed_done, 'at' => $reviewed ),
			array( 'key' => 'approved', 'label' => __( 'Approved', 'lnkup-marketplace' ), 'done' => $approved_done, 'at' => $approved ),
			array( 'key' => 'paid', 'label' => __( 'Payment completed', 'lnkup-marketplace' ), 'done' => $paid_done, 'at' => $paid_at ),
			array( 'key' => 'checked_in', 'label' => __( 'Checked in', 'lnkup-marketplace' ), 'done' => '' !== $checked, 'at' => $checked ),
		);
		$out        = array();
		$found_next = false;
		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				$state = 'done';
			} elseif ( ! $found_next ) {
				$state      = 'current';
				$found_next = true;
			} else {
				$state = 'pending';
			}
			$out[] = array(
				'key'   => $step['key'],
				'label' => $step['label'],
				'state' => $state,
				'at'    => $step['done'] ? $step['at'] : '',
			);
		}
		return $out;
	}

	/**
	 * Image and organizer for a booking without depending on the marketplace index
	 * (closed, full and past opportunities are not indexed).
	 *
	 * @param int $event_id Event ID (0 when unresolved).
	 * @param int $opp_id   Opportunity ID.
	 * @return array{media:?array<string,mixed>,featured_image:string,organizer_name:string}
	 */
	public static function presentation( int $event_id, int $opp_id ): array {
		$media = null;
		$image = '';
		if ( $event_id && class_exists( 'LNKup_Event_Media' ) ) {
			$resolved = LNKup_Event_Media::resolve( $event_id, $opp_id );
			if ( ! empty( $resolved ) ) {
				$media = LNKup_Event_Media::to_rest( $resolved );
				$image = (string) ( $media['medium_url'] ?? $media['url'] ?? '' );
			}
		}
		return array(
			'media'          => $media,
			'featured_image' => $image,
			'organizer_name' => self::organizer_name( $event_id ),
		);
	}

	/**
	 * One vendor booking item. Superset of the original `/applications/vendor` item.
	 *
	 * @param WP_Post $app     Application.
	 * @param int     $user_id Vendor user ID.
	 * @param bool    $detail  Include the timeline.
	 * @return array<string,mixed>
	 */
	public static function item( WP_Post $app, int $user_id, bool $detail = false ): array {
		$resolved   = self::resolve_event( (int) $app->ID );
		$event_id   = (int) $resolved['event_id'];
		$snapshot   = $resolved['snapshot'];
		$meta       = LNKup_Vendor_Dashboard_Data::application_event_meta( $app );
		$status     = (string) get_post_meta( $app->ID, '_lnkup_application_status', true );
		$opp_id     = (int) get_post_meta( $app->ID, '_lnkup_opportunity_id', true );
		$payment    = class_exists( 'LNKup_Payments' ) ? LNKup_Payments::get_for_application( $app->ID ) : null;
		$pay_status = $payment ? (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) : '';
		$look       = self::presentation( $event_id, $opp_id );

		if ( 'approved' === $status && $payment && 'pending' === $pay_status ) {
			$status = 'payment_required';
		}

		$amount_due = (float) $meta['fee'];
		if ( $payment ) {
			foreach ( array( '_lnkup_total_amount', '_lnkup_amount', '_lnkup_total' ) as $key ) {
				$amount_due = (float) get_post_meta( $payment->ID, $key, true );
				if ( $amount_due > 0 ) {
					break;
				}
			}
		}

		$event_name = (string) $meta['event_title'];
		$event_date = (string) $meta['date'];
		$city       = (string) $meta['city'];
		$state      = (string) $meta['state'];
		$place      = (string) ( $meta['place'] ?? '' );
		$organizer  = $look['organizer_name'];
		$image      = $look['featured_image'];
		if ( 'snapshot' === $resolved['source'] ) {
			$event_name = (string) $snapshot['title'];
			$event_date = (string) ( $snapshot['date'] ?? '' );
			$city       = function_exists( 'lnkup_display_label' ) ? lnkup_display_label( (string) ( $snapshot['city'] ?? '' ) ) : (string) ( $snapshot['city'] ?? '' );
			$state      = function_exists( 'lnkup_display_state' ) ? lnkup_display_state( (string) ( $snapshot['state'] ?? '' ) ) : (string) ( $snapshot['state'] ?? '' );
			$place      = function_exists( 'lnkup_display_place' ) ? lnkup_display_place( (string) ( $snapshot['city'] ?? '' ), (string) ( $snapshot['state'] ?? '' ) ) : '';
			$organizer  = (string) ( $snapshot['organizer_name'] ?? '' );
			$image      = (string) ( $snapshot['media_url'] ?? '' );
		}

		$event_state  = self::event_state( $event_id, $snapshot );
		$dimensions   = self::state_dimensions( $status, $pay_status, $event_state );
		$unlocked     = class_exists( 'LNKup_Packets' ) && in_array( $dimensions['payment_state'], array( 'paid', 'not_required' ), true )
			? LNKup_Packets::is_pass_unlocked_for_application( (int) $app->ID )
			: false;
		$end_date     = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_end_date', true ) : (string) ( $snapshot['end_date'] ?? '' );

		$item = array(
			'application_id'    => (int) $app->ID,
			'opportunity_id'    => $opp_id,
			'event_id'          => $event_id,
			'event_name'        => $event_name,
			'event_date'        => $event_date,
			'event_end_date'    => $end_date,
			'city'              => $city,
			'state'             => $state,
			'place'             => $place,
			'venue'             => 'snapshot' === $resolved['source'] ? (string) ( $snapshot['venue'] ?? '' ) : (string) $meta['venue'],
			'primary_status'    => class_exists( 'LNKup_Vendor_Presentation' ) ? (string) ( LNKup_Vendor_Presentation::lifecycle( $app->ID )['primary'] ?? '' ) : '',
			'organizer_name'    => $organizer,
			'category'          => (string) get_post_meta( $app->ID, '_lnkup_selected_category', true ),
			'opportunity_title' => $opp_id && function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( $opp_id ) : (string) ( $snapshot['opportunity_title'] ?? '' ),
			'status'            => $status ?: 'submitted',
			'submitted_at'      => (string) ( get_post_meta( $app->ID, '_lnkup_submitted_at', true ) ?: $app->post_date ),
			'approved_at'       => (string) ( get_post_meta( $app->ID, '_lnkup_approved_at', true ) ?: get_post_meta( $app->ID, '_lnkup_decided_at', true ) ),
			'payment_due_at'    => (string) get_post_meta( $app->ID, '_lnkup_payment_due_at', true ),
			'amount_due'        => $amount_due,
			'currency'          => 'USD',
			'vendor_fee'        => (float) $meta['fee'],
			'featured_image'    => $image,
			'media'             => 'snapshot' === $resolved['source'] ? null : $look['media'],
			'payment_id'        => $payment ? (int) $payment->ID : 0,
			'payment_status'    => $pay_status,
			'rejection_reason'  => (string) get_post_meta( $app->ID, '_lnkup_rejection_reason', true ),
			'info_request'      => LNKup_Applications::open_info_request( $app->ID ),
			'status_history'    => LNKup_Applications::status_history( $app->ID ),
			'group_id'          => (string) get_post_meta( $app->ID, '_lnkup_submission_group_id', true ),
			'event_resolution'  => $resolved['source'],
			'event_state'       => $event_state,
			'application_state' => $dimensions['application_state'],
			'booking_state'     => $dimensions['booking_state'],
			'payment_state'     => $dimensions['payment_state'],
			'payment_context'   => self::payment_context( $status, $pay_status, $event_state ),
			'credential_state'  => self::credential_state( $unlocked, $status, $pay_status, $event_state ),
		);
		if ( $detail ) {
			$item['timeline'] = self::timeline( (int) $app->ID, $status, $pay_status, $payment ? (int) $payment->ID : 0 );
		}
		return $item;
	}

	/**
	 * Backfill event IDs (from the opportunity) and snapshots. Reports what it cannot recover.
	 *
	 * @param bool $write Persist changes.
	 * @return array{total:int,ok:int,relinked:int,snapshotted:int,unrecoverable:array<int,int>}
	 */
	public static function repair( bool $write ): array {
		$report = array( 'total' => 0, 'ok' => 0, 'relinked' => 0, 'snapshotted' => 0, 'unrecoverable' => array() );
		$paged  = 1;
		do {
			$ids = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => 'any',
					'posts_per_page' => 200,
					'paged'          => $paged,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $app_id ) {
				$app_id = (int) $app_id;
				++$report['total'];
				$resolved = self::resolve_event( $app_id );
				if ( 'live' === $resolved['source'] ) {
					++$report['ok'];
				} elseif ( 'opportunity' === $resolved['source'] ) {
					++$report['relinked'];
					if ( $write ) {
						update_post_meta( $app_id, '_lnkup_event_id', (int) $resolved['event_id'] );
					}
				} elseif ( 'unrecoverable' === $resolved['source'] ) {
					$report['unrecoverable'][] = $app_id;
				}
				if ( $resolved['event_id'] && array() === self::snapshot( $app_id ) ) {
					++$report['snapshotted'];
					if ( $write ) {
						self::capture_snapshot( $app_id );
					}
				}
			}
			++$paged;
		} while ( count( $ids ) === 200 );
		return $report;
	}
}

/**
 * `wp lnkup bookings repair-events [--dry-run]`
 */
class LNKup_Vendor_Booking_CLI_Command {

	/**
	 * Relink applications whose stored event is missing (using the opportunity's event) and store
	 * event snapshots. Never invents values; unrecoverable applications are listed.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report only.
	 *
	 * @param array<int,string>    $args  Positional.
	 * @param array<string,string> $assoc Options.
	 */
	public function repair_events( $args, $assoc ): void {
		unset( $args );
		$write  = empty( $assoc['dry-run'] );
		$report = LNKup_Vendor_Booking_Contract::repair( $write );
		WP_CLI::line( sprintf( 'Applications: %d', $report['total'] ) );
		WP_CLI::line( sprintf( '  linked to a live event:        %d', $report['ok'] ) );
		WP_CLI::line( sprintf( '  relinked via opportunity:      %d', $report['relinked'] ) );
		WP_CLI::line( sprintf( '  snapshots %s:  %d', $write ? 'written' : 'to write', $report['snapshotted'] ) );
		WP_CLI::line( sprintf( '  unrecoverable (no event, no snapshot): %d', count( $report['unrecoverable'] ) ) );
		if ( $report['unrecoverable'] ) {
			WP_CLI::line( '    IDs: ' . implode( ', ', array_slice( $report['unrecoverable'], 0, 200 ) ) );
		}
		if ( $write ) {
			WP_CLI::success( 'Done. Statuses, payments and invoices were not changed.' );
		} else {
			WP_CLI::line( 'Dry run: nothing was written.' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'lnkup bookings', 'LNKup_Vendor_Booking_CLI_Command' );
}
