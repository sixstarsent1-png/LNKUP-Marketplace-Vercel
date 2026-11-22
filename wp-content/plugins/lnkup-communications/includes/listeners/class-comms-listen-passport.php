<?php
/**
 * Vendor Passport notifications: verification decisions/expiry, reviews, responses, moderation,
 * shared documents, document expiry relevance and grouped tour invites.
 *
 * Core decides what happened and who is involved; this listener applies consent, dedupe and delivery.
 * Messages never contain document contents, private notes or internal scores.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Passport {

	public static function init(): void {
		add_action( 'lnkup_vendor_verification_changed', array( __CLASS__, 'on_verification' ), 20, 4 );
		add_action( 'lnkup_vendor_verification_expiring', array( __CLASS__, 'on_verification_expiring' ), 20, 3 );
		add_action( 'lnkup_vendor_review_submitted', array( __CLASS__, 'on_review' ), 20, 2 );
		add_action( 'lnkup_review_response_posted', array( __CLASS__, 'on_response' ), 20, 3 );
		add_action( 'lnkup_review_moderated', array( __CLASS__, 'on_moderated' ), 20, 4 );
		add_action( 'lnkup_document_shared', array( __CLASS__, 'on_shared' ), 20, 4 );
		add_action( 'lnkup_document_expiring', array( __CLASS__, 'on_document_expiring' ), 20, 4 );
		add_action( 'lnkup_vendor_invited_to_tour', array( __CLASS__, 'on_tour_invite' ), 20, 4 );
	}

	private static function vendor_of_profile( int $profile_id ): int {
		return (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
	}

	/**
	 * @param int    $profile_id Profile.
	 * @param string $type       Record type.
	 * @param string $status     New status.
	 * @param int    $record_id  Record ID.
	 */
	public static function on_verification( $profile_id, $type, $status, $record_id ): void {
		$profile_id = absint( $profile_id );
		if ( ! in_array( $status, array( 'verified', 'rejected', 'info_required', 'revoked', 'expired' ), true ) ) {
			return;
		}
		$vendor = self::vendor_of_profile( $profile_id );
		if ( ! $vendor || ! class_exists( 'LNKup_Verification_Records' ) ) {
			return;
		}
		$types    = LNKup_Verification_Records::type_labels();
		$statuses = LNKup_Verification_Records::status_labels();
		$current  = LNKup_Verification_Records::current( $profile_id, (string) $type );
		$note     = in_array( $status, array( 'rejected', 'info_required', 'revoked' ), true ) ? wp_trim_words( sanitize_text_field( (string) $current['reason'] ), 40, '…' ) : '';
		LNKup_Comms_Engine::dispatch(
			'v_verification_update',
			$vendor,
			array(
				'profile_id'          => $profile_id,
				'verification_type'   => strtolower( (string) ( $types[ $type ] ?? $type ) ),
				'verification_status' => (string) ( $statuses[ $status ] ?? $status ),
				'verification_note'   => $note,
				'passport_tab'        => 'verification',
			),
			array( 'window' => 'rec' . absint( $record_id ), 'source' => 'passport_verification' )
		);
	}

	public static function on_verification_expiring( $profile_id, $type, $expires_at ): void {
		$profile_id = absint( $profile_id );
		$vendor     = self::vendor_of_profile( $profile_id );
		if ( ! $vendor || ! class_exists( 'LNKup_Verification_Records' ) ) {
			return;
		}
		$types = LNKup_Verification_Records::type_labels();
		LNKup_Comms_Engine::dispatch(
			'v_verification_expiring',
			$vendor,
			array(
				'profile_id'        => $profile_id,
				'verification_type' => strtolower( (string) ( $types[ $type ] ?? $type ) ),
				'expires_on'        => wp_date( 'M j, Y', (int) strtotime( (string) $expires_at ) ),
				'passport_tab'      => 'verification',
			),
			array( 'window' => 'exp_' . sanitize_key( (string) $type ) . '_' . sanitize_key( (string) $expires_at ), 'source' => 'passport_verification_expiry' )
		);
	}

	public static function on_review( $review_id, $profile_id ): void {
		$review_id = absint( $review_id );
		$vendor    = (int) get_post_meta( $review_id, '_lnkup_vendor_user_id', true );
		if ( ! $vendor ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'v_review_received',
			$vendor,
			array(
				'profile_id'     => absint( $profile_id ),
				'event_id'       => (int) get_post_meta( $review_id, '_lnkup_event_id', true ),
				'passport_tab'   => 'reviews',
			),
			array( 'window' => 'review' . $review_id, 'source' => 'passport_review' )
		);
	}

	public static function on_response( $review_id, $profile_id, $is_new ): void {
		if ( ! $is_new ) {
			return;
		}
		$review_id = absint( $review_id );
		$organizer = (int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true );
		if ( ! $organizer ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'o_review_response',
			$organizer,
			array(
				'profile_id' => absint( $profile_id ),
				'event_id'   => (int) get_post_meta( $review_id, '_lnkup_event_id', true ),
			),
			array( 'window' => 'response' . $review_id, 'source' => 'passport_review_response' )
		);
	}

	public static function on_moderated( $review_id, $decision, $dispute_id, $profile_id ): void {
		$review_id = absint( $review_id );
		$outcomes  = array(
			'uphold'       => __( 'the review stays published', 'lnkup-communications' ),
			'hide'         => __( 'the review was hidden under our review standards', 'lnkup-communications' ),
			'request_edit' => __( 'the organizer was asked to correct the review', 'lnkup-communications' ),
		);
		$ctx = array(
			'profile_id'         => absint( $profile_id ),
			'event_id'           => (int) get_post_meta( $review_id, '_lnkup_event_id', true ),
			'moderation_outcome' => (string) ( $outcomes[ $decision ] ?? $decision ),
			'passport_tab'       => 'reviews',
		);
		$opts   = array( 'window' => 'mod' . absint( $dispute_id ) . '_' . sanitize_key( (string) $decision ), 'source' => 'passport_moderation' );
		$vendor = (int) get_post_meta( $review_id, '_lnkup_vendor_user_id', true );
		if ( $vendor ) {
			LNKup_Comms_Engine::dispatch( 'v_review_moderated', $vendor, $ctx, $opts );
		}
		$organizer = (int) get_post_meta( $review_id, '_lnkup_organizer_user_id', true );
		if ( $organizer ) {
			LNKup_Comms_Engine::dispatch( 'o_review_moderated', $organizer, $ctx, $opts );
		}
	}

	public static function on_shared( $share_id, $document_id, $organizer_id, $application_id ): void {
		$organizer_id = absint( $organizer_id );
		if ( ! $organizer_id ) {
			return;
		}
		LNKup_Comms_Engine::dispatch(
			'o_document_shared',
			$organizer_id,
			array(
				'application_id' => absint( $application_id ),
				'document_label' => LNKup_Comms_Listen_Documents::label( absint( $document_id ) ),
			),
			array( 'window' => 'share' . absint( $share_id ), 'source' => 'passport_document_shared' )
		);
	}

	/**
	 * Vendor reminder (relevance already decided by core) + organizers who hold an active share
	 * for an event that happens after the document expires.
	 *
	 * @param int   $doc_id    Document.
	 * @param int   $vendor_id Vendor.
	 * @param int   $days      Days until expiry.
	 * @param array $context   relevant, event_ids, reason.
	 */
	public static function on_document_expiring( $doc_id, $vendor_id, $days, $context ): void {
		$doc_id  = absint( $doc_id );
		$expires = (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true );
		$label   = LNKup_Comms_Listen_Documents::label( $doc_id );
		if ( absint( $vendor_id ) ) {
			LNKup_Comms_Engine::dispatch(
				'v_document_expiring',
				absint( $vendor_id ),
				array(
					'document_id'    => $doc_id,
					'document_label' => $label,
					'expires_on'     => wp_date( 'M j, Y', (int) strtotime( $expires ) ),
				),
				array( 'window' => 'd' . absint( $days ) . '_' . sanitize_key( $expires ), 'source' => 'passport_document_expiry' )
			);
		}
		if ( 'expires_before_event' !== ( $context['reason'] ?? '' ) || ! class_exists( 'LNKup_Document_Vault' ) ) {
			return;
		}
		$profile_id = function_exists( 'lnkup_get_vendor_profile_id' ) ? (int) lnkup_get_vendor_profile_id( absint( $vendor_id ) ) : 0;
		foreach ( $profile_id ? LNKup_Document_Vault::shares_for_profile( $profile_id ) : array() as $share ) {
			if ( (int) $share['document_id'] !== $doc_id || empty( $share['active'] ) || ! in_array( (int) $share['event_id'], array_map( 'intval', (array) ( $context['event_ids'] ?? array() ) ), true ) ) {
				continue;
			}
			$share_row = LNKup_Document_Vault::get_share( (int) $share['id'] );
			if ( ! $share_row ) {
				continue;
			}
			LNKup_Comms_Engine::dispatch(
				'o_shared_document_expiring',
				(int) $share_row['organizer_user_id'],
				array(
					'application_id' => (int) $share['application_id'],
					'document_label' => $label,
					'expires_on'     => wp_date( 'M j, Y', (int) strtotime( $expires ) ),
				),
				array( 'window' => 'shexp' . (int) $share['id'] . '_' . sanitize_key( $expires ), 'source' => 'passport_document_expiry' )
			);
		}
	}

	/**
	 * One grouped message per tour invite batch (per-stop invites are created silently by core).
	 *
	 * @param int   $tour_id      Tour.
	 * @param int   $vendor_id    Vendor.
	 * @param int   $organizer_id Organizer.
	 * @param int[] $event_ids    Invited stops.
	 */
	public static function on_tour_invite( $tour_id, $vendor_id, $organizer_id, $event_ids ): void {
		$event_ids = array_values( array_filter( array_map( 'absint', (array) $event_ids ) ) );
		if ( ! $event_ids || ! absint( $vendor_id ) ) {
			return;
		}
		sort( $event_ids );
		LNKup_Comms_Engine::dispatch(
			'v_tour_invite',
			absint( $vendor_id ),
			array(
				'tour_id'           => absint( $tour_id ),
				'organizer_user_id' => absint( $organizer_id ),
				'stop_count'        => (string) count( $event_ids ),
			),
			array( 'window' => 'tour' . absint( $tour_id ) . '_' . md5( implode( ',', $event_ids ) ), 'source' => 'passport_tour_invite' )
		);
	}
}
