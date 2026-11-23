<?php
/**
 * External event claim review: who may take control of a discovered listing.
 *
 * Claim links are bound to the email they were sent to. A claim from a
 * different account, or a second organizer claiming the same event, becomes
 * a trust case instead of an automatic transfer.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Claims {

	const MATCH_EMAIL  = 'email';
	const MATCH_DOMAIN = 'domain';
	const MATCH_NONE   = 'none';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';
	const STATUS_DISPUTED = 'disputed';

	const RATE_PER_USER_HOUR  = 10;
	const RATE_PER_EVENT_HOUR = 20;

	/**
	 * How the claiming account relates to the invited email.
	 *
	 * @param int    $organizer_id Claiming user.
	 * @param string $token_email  Email the claim link was issued to.
	 */
	public static function binding( $organizer_id, $token_email ) {
		$user = get_userdata( absint( $organizer_id ) );
		if ( ! $user || '' === (string) $token_email ) {
			return self::MATCH_NONE;
		}
		if ( strtolower( trim( $user->user_email ) ) === strtolower( trim( (string) $token_email ) ) ) {
			return self::MATCH_EMAIL;
		}
		$token_domain = LNKup_Trust::domain_of( $token_email );
		if ( '' === $token_domain || LNKup_Trust::is_free_mail_domain( $token_domain ) ) {
			return self::MATCH_NONE;
		}
		if ( LNKup_Trust::domain_of( $user->user_email ) === $token_domain ) {
			return self::MATCH_DOMAIN;
		}
		if ( class_exists( 'LNKup_Organizer_Verification' ) && LNKup_Organizer_Verification::verified_domain( $organizer_id ) === $token_domain ) {
			return self::MATCH_DOMAIN;
		}
		return self::MATCH_NONE;
	}

	/**
	 * @param int $organizer_id Organizer.
	 * @param int $external_id  External event.
	 * @return true|WP_Error
	 */
	public static function check_rate( $organizer_id, $external_id ) {
		if ( ! LNKup_Trust::rate_limit( 'claim_u_' . absint( $organizer_id ), self::RATE_PER_USER_HOUR, HOUR_IN_SECONDS )
			|| ! LNKup_Trust::rate_limit( 'claim_e_' . absint( $external_id ), self::RATE_PER_EVENT_HOUR, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many claim attempts. Try again in an hour or contact LNKup support.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Record a claim attempt.
	 *
	 * @param array $args external_event_id, event_id, organizer_user_id, token_id, match_type, status, evidence, case_id.
	 * @return int Row ID.
	 */
	public static function record( array $args ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::claim_evidence_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return 0;
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'external_event_id' => absint( $args['external_event_id'] ?? 0 ),
				'event_id'          => absint( $args['event_id'] ?? 0 ),
				'organizer_user_id' => absint( $args['organizer_user_id'] ?? 0 ),
				'token_id'          => absint( $args['token_id'] ?? 0 ),
				'match_type'        => sanitize_key( $args['match_type'] ?? self::MATCH_NONE ),
				'status'            => sanitize_key( $args['status'] ?? self::STATUS_PENDING ),
				'evidence'          => wp_json_encode( LNKup_Audit_Log::scrub( (array) ( $args['evidence'] ?? array() ) ) ),
				'case_id'           => absint( $args['case_id'] ?? 0 ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function get( $claim_id ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::claim_evidence_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $claim_id ) ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Claims for the admin Event Authenticity queue.
	 *
	 * @param string $status Status filter ('' = pending + disputed).
	 * @param int    $limit  Limit.
	 */
	public static function list_for_review( $status = '', $limit = 50 ) {
		global $wpdb;
		$table = LNKup_Trust_Tables::claim_evidence_table();
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return array();
		}
		if ( '' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('pending','disputed') ORDER BY id DESC LIMIT %d", absint( $limit ) ), ARRAY_A );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d", sanitize_key( $status ), absint( $limit ) ), ARRAY_A );
	}

	public static function count_by_status() {
		global $wpdb;
		$table = LNKup_Trust_Tables::claim_evidence_table();
		$out   = array( 'pending' => 0, 'disputed' => 0 );
		if ( ! LNKup_Trust_Tables::exists( $table ) ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status IN ('pending','disputed') GROUP BY status", ARRAY_A ) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Admin approves a claim.
	 *
	 * Pending claim without an event: create the LNKup event for that organizer.
	 * Claim with an event: mark the listing organizer-controlled.
	 *
	 * @param int    $claim_id Claim row.
	 * @param string $note     Reviewer note.
	 * @return array|WP_Error
	 */
	public static function approve( $claim_id, $note ) {
		$claim = self::get( $claim_id );
		if ( ! $claim ) {
			return new WP_Error( 'lnkup_claim_not_found', __( 'Claim not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $claim['status'], array( self::STATUS_PENDING, self::STATUS_DISPUTED ), true ) ) {
			return new WP_Error( 'lnkup_claim_closed', __( 'This claim was already decided.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$organizer_id = (int) $claim['organizer_user_id'];
		$event_id     = (int) $claim['event_id'];
		$external_id  = (int) $claim['external_event_id'];

		if ( self::STATUS_DISPUTED === $claim['status'] ) {
			$row = LNKup_External_Events::get_row( $external_id );
			if ( $row && (int) $row['claimed_event_id'] > 0 && (int) get_post_meta( (int) $row['claimed_event_id'], '_lnkup_organizer_user_id', true ) !== $organizer_id ) {
				return new WP_Error(
					'lnkup_claim_transfer_manual',
					__( 'This event is already managed by another organizer. Transfer ownership manually after confirming with both parties, then resolve the case.', 'lnkup-marketplace' ),
					array( 'status' => 409 )
				);
			}
		}

		if ( ! $event_id ) {
			$created = LNKup_External_Events::create_event_for_approved_claim( $external_id, $organizer_id );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$event_id = (int) $created;
		}

		LNKup_Event_Authenticity::set_status( $event_id, LNKup_Event_Authenticity::ORGANIZER_CONTROLLED, 'Claim approved: ' . $note );
		self::decide( (int) $claim_id, self::STATUS_APPROVED, $event_id );
		self::close_case( (int) $claim['case_id'], 'resolved', 'Claim approved. ' . $note );
		LNKup_Audit_Log::log( 'event_claim_approved', 'event', $event_id, array( 'new' => array( 'claim_id' => (int) $claim_id, 'organizer_user_id' => $organizer_id ), 'reason' => $note ) );

		LNKup_Trust::notify_user(
			$organizer_id,
			__( 'Your event listing is verified', 'lnkup-marketplace' ),
			sprintf(
				/* translators: %s: event title */
				__( 'LNKup confirmed that you manage "%s". Vendors can now apply and pay through LNKup.', 'lnkup-marketplace' ),
				lnkup_plain_title( $event_id )
			),
			'trust',
			function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'events' ) : ''
		);
		return array( 'claim_id' => (int) $claim_id, 'event_id' => $event_id, 'status' => self::STATUS_APPROVED );
	}

	/**
	 * Admin rejects a claim. A created listing is restricted, never deleted.
	 *
	 * @param int    $claim_id Claim row.
	 * @param string $note     Reviewer note (shown internally only).
	 * @return array|WP_Error
	 */
	public static function reject( $claim_id, $note ) {
		$claim = self::get( $claim_id );
		if ( ! $claim ) {
			return new WP_Error( 'lnkup_claim_not_found', __( 'Claim not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $claim['status'], array( self::STATUS_PENDING, self::STATUS_DISPUTED ), true ) ) {
			return new WP_Error( 'lnkup_claim_closed', __( 'This claim was already decided.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$event_id    = (int) $claim['event_id'];
		$external_id = (int) $claim['external_event_id'];
		if ( $event_id && (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) === (int) $claim['organizer_user_id'] ) {
			LNKup_Event_Authenticity::set_status( $event_id, LNKup_Event_Authenticity::RESTRICTED, 'Claim rejected: ' . $note );
		}
		$row = LNKup_External_Events::get_row( $external_id );
		if ( $row && in_array( $row['claim_status'], array( LNKup_External_Events::CLAIM_PENDING, LNKup_External_Events::CLAIM_DISPUTED ), true ) ) {
			LNKup_External_Events::set_claim_review_status( $external_id, (int) $row['claimed_event_id'] > 0 ? LNKup_External_Events::CLAIM_CLAIMED : LNKup_External_Events::CLAIM_UNCLAIMED );
		}
		self::decide( (int) $claim_id, self::STATUS_REJECTED, $event_id );
		self::close_case( (int) $claim['case_id'], 'resolved', 'Claim rejected. ' . $note );
		LNKup_Audit_Log::log( 'event_claim_rejected', 'external_event', $external_id, array( 'new' => array( 'claim_id' => (int) $claim_id, 'organizer_user_id' => (int) $claim['organizer_user_id'] ), 'reason' => $note ) );

		LNKup_Trust::notify_user(
			(int) $claim['organizer_user_id'],
			__( 'We could not verify your event claim', 'lnkup-marketplace' ),
			__( 'LNKup could not confirm that your account manages this event, so the listing was not transferred. Reply to support with proof you run the event (official website email, organizer agreement) to ask for another review.', 'lnkup-marketplace' ),
			'trust'
		);
		return array( 'claim_id' => (int) $claim_id, 'status' => self::STATUS_REJECTED );
	}

	private static function decide( $claim_id, $status, $event_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			LNKup_Trust_Tables::claim_evidence_table(),
			array(
				'status'     => $status,
				'event_id'   => absint( $event_id ),
				'decided_by' => get_current_user_id(),
				'decided_at' => $now,
				'updated_at' => $now,
			),
			array( 'id' => absint( $claim_id ) )
		);
	}

	private static function close_case( $case_id, $outcome, $note ) {
		if ( ! $case_id ) {
			return;
		}
		$case = LNKup_Trust_Cases::get( $case_id );
		if ( $case && ! in_array( $case['status'], LNKup_Trust_Cases::CLOSED_STATUSES, true ) ) {
			LNKup_Trust_Cases::resolve( $case_id, $outcome, $note );
		}
	}
}
