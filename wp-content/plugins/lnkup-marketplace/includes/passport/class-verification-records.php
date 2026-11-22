<?php
/**
 * Append-only vendor verification records with provenance, expiry and revocation.
 *
 * Business identity is the record that drives the public "Business Identity Verified" badge.
 * The legacy `_lnkup_verification_status` profile meta is kept in sync for existing callers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Verification_Records {

	const TYPES = array( 'account', 'business_identity', 'business_document', 'license_permit', 'insurance', 'event_requirement' );

	const STATUSES = array( 'not_submitted', 'pending', 'info_required', 'verified', 'rejected', 'expired', 'revoked' );

	/** Decisions staff may record. */
	const DECISIONS = array( 'verified', 'rejected', 'info_required', 'revoked', 'pending' );

	/** @var bool Guards the legacy meta listener while this class writes meta itself. */
	private static $syncing = false;

	public static function init(): void {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_legacy_meta' ), 5, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_legacy_meta' ), 5, 4 );
	}

	public static function type_labels(): array {
		return array(
			'account'           => __( 'Account ownership', 'lnkup-marketplace' ),
			'business_identity' => __( 'Business identity', 'lnkup-marketplace' ),
			'business_document' => __( 'Business documentation', 'lnkup-marketplace' ),
			'license_permit'    => __( 'Licenses & permits', 'lnkup-marketplace' ),
			'insurance'         => __( 'Insurance', 'lnkup-marketplace' ),
			'event_requirement' => __( 'Event-specific requirement', 'lnkup-marketplace' ),
		);
	}

	public static function status_labels(): array {
		return array(
			'not_submitted' => __( 'Not submitted', 'lnkup-marketplace' ),
			'pending'       => __( 'Pending verification', 'lnkup-marketplace' ),
			'info_required' => __( 'Additional information required', 'lnkup-marketplace' ),
			'verified'      => __( 'Verified', 'lnkup-marketplace' ),
			'rejected'      => __( 'Not approved', 'lnkup-marketplace' ),
			'expired'       => __( 'Expired', 'lnkup-marketplace' ),
			'revoked'       => __( 'Revoked', 'lnkup-marketplace' ),
		);
	}

	private static function ready(): bool {
		return LNKup_Passport_Tables::exists( LNKup_Passport_Tables::verification_table() );
	}

	/**
	 * Append a record.
	 *
	 * @param int    $profile_id Vendor profile ID.
	 * @param string $type       Record type.
	 * @param string $status     Status.
	 * @param array  $args       authority, source, document_id, reviewer_id, verified_at, expires_at, reason.
	 * @return int|WP_Error Row ID.
	 */
	public static function record( int $profile_id, string $type, string $status, array $args = array() ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'not_ready', __( 'Verification records are not available yet.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) || ! in_array( $type, self::TYPES, true ) || ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'invalid_record', __( 'Invalid verification record.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$now         = current_time( 'mysql' );
		$verified_at = 'verified' === $status ? ( $args['verified_at'] ?? $now ) : ( $args['verified_at'] ?? null );
		$row         = array(
			'vendor_profile_id' => $profile_id,
			'type'              => $type,
			'status'            => $status,
			'authority'         => substr( sanitize_text_field( (string) ( $args['authority'] ?? 'LNKup' ) ), 0, 120 ),
			'source'            => sanitize_key( (string) ( $args['source'] ?? 'staff_review' ) ),
			'document_id'       => absint( $args['document_id'] ?? 0 ),
			'reviewer_id'       => absint( $args['reviewer_id'] ?? 0 ),
			'verified_at'       => $verified_at,
			'expires_at'        => self::normalize_datetime( $args['expires_at'] ?? null ),
			'revoked_at'        => 'revoked' === $status ? $now : null,
			'reason'            => sanitize_textarea_field( (string) ( $args['reason'] ?? '' ) ),
			'created_at'        => $now,
		);
		$wpdb->insert( LNKup_Passport_Tables::verification_table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;

		if ( 'business_identity' === $type ) {
			self::sync_legacy_meta( $profile_id, $status );
		}
		LNKup_Vendor_Reputation::log_activity(
			'verification_' . $status,
			$profile_id,
			array(
				'payload' => array(
					'record_id' => $id,
					'type'      => $type,
					'source'    => $row['source'],
				),
			)
		);
		do_action( 'lnkup_vendor_verification_changed', $profile_id, $type, $status, $id );
		if ( class_exists( 'LNKup_Passport_Projection' ) ) {
			LNKup_Passport_Projection::flush_public_cache( $profile_id );
		}
		return $id;
	}

	/**
	 * Vendor submits (or resubmits) a verification request.
	 *
	 * @param int    $profile_id  Profile ID.
	 * @param string $type        Record type.
	 * @param string $note        Vendor note.
	 * @param int    $document_id Supporting document (must belong to the vendor).
	 * @return int|WP_Error
	 */
	public static function submit( int $profile_id, string $type, string $note = '', int $document_id = 0 ) {
		if ( ! in_array( $type, array( 'business_identity', 'business_document', 'license_permit', 'insurance' ), true ) ) {
			return new WP_Error( 'invalid_type', __( 'This verification type cannot be requested.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = self::current( $profile_id, $type );
		if ( in_array( $current['status'], array( 'pending' ), true ) ) {
			return new WP_Error( 'already_pending', __( 'Your verification is already being reviewed.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( 'verified' === $current['status'] ) {
			return new WP_Error( 'already_verified', __( 'This is already verified.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( $document_id ) {
			$owner = (int) get_post_meta( $document_id, '_lnkup_vendor_user_id', true );
			if ( 'lnkup_document' !== get_post_type( $document_id ) || $owner !== (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ) ) {
				return new WP_Error( 'invalid_document', __( 'Choose one of your own documents.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
		}
		return self::record(
			$profile_id,
			$type,
			'pending',
			array(
				'source'      => 'vendor_submission',
				'document_id' => $document_id,
				'reviewer_id' => 0,
				'reason'      => $note,
			)
		);
	}

	/**
	 * Staff decision.
	 *
	 * @param int    $profile_id Profile ID.
	 * @param string $type       Record type.
	 * @param string $decision   One of DECISIONS.
	 * @param array  $args       reason, expires_at, authority, document_id.
	 * @return int|WP_Error
	 */
	public static function decide( int $profile_id, string $type, string $decision, array $args = array() ) {
		$reviewer = absint( $args['reviewer_id'] ?? get_current_user_id() );
		if ( ! LNKup_Capabilities::user_has_manage_all( $reviewer ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( ! in_array( $decision, self::DECISIONS, true ) ) {
			return new WP_Error( 'invalid_decision', __( 'Invalid verification decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( in_array( $decision, array( 'rejected', 'info_required', 'revoked' ), true ) && '' === trim( (string) ( $args['reason'] ?? '' ) ) ) {
			return new WP_Error( 'reason_required', __( 'Record a reason for this decision.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$args['reviewer_id'] = $reviewer;
		$args['source']      = $args['source'] ?? 'staff_review';
		return self::record( $profile_id, $type, $decision, $args );
	}

	/**
	 * Latest record for a type with effective status (verified past expiry reads as expired).
	 *
	 * @param int    $profile_id Profile ID.
	 * @param string $type       Record type.
	 * @return array<string,mixed>
	 */
	public static function current( int $profile_id, string $type ): array {
		$empty = array(
			'id'          => 0,
			'type'        => $type,
			'status'      => 'not_submitted',
			'label'       => self::status_labels()['not_submitted'],
			'verified_at' => null,
			'expires_at'  => null,
			'authority'   => '',
			'source'      => '',
			'reason'      => '',
		);
		if ( ! self::ready() ) {
			if ( 'business_identity' === $type ) {
				$legacy = (string) get_post_meta( $profile_id, '_lnkup_verification_status', true );
				$map    = array( 'approved' => 'verified', 'pending' => 'pending', 'rejected' => 'rejected' );
				if ( isset( $map[ $legacy ] ) ) {
					$empty['status'] = $map[ $legacy ];
					$empty['label']  = self::status_labels()[ $map[ $legacy ] ];
				}
			}
			return $empty;
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_profile_id = %d AND type = %s ORDER BY id DESC LIMIT 1", $profile_id, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $row ) {
			return $empty;
		}
		$status = (string) $row['status'];
		if ( 'verified' === $status && ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) < time() ) {
			$status = 'expired';
		}
		return array(
			'id'          => (int) $row['id'],
			'type'        => $type,
			'status'      => $status,
			'label'       => self::status_labels()[ $status ] ?? $status,
			'verified_at' => $row['verified_at'],
			'expires_at'  => $row['expires_at'],
			'authority'   => (string) $row['authority'],
			'source'      => (string) $row['source'],
			'reason'      => (string) $row['reason'],
		);
	}

	/**
	 * Current state for every type.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function current_all( int $profile_id ): array {
		$out = array();
		foreach ( self::TYPES as $type ) {
			$out[ $type ] = self::current( $profile_id, $type );
		}
		$owner = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( 'not_submitted' === $out['account']['status'] && $owner && get_userdata( $owner ) ) {
			$out['account']['status'] = 'verified';
			$out['account']['label']  = self::status_labels()['verified'];
			$out['account']['source'] = 'account_registration';
		}
		return $out;
	}

	/**
	 * Full history (newest first). Staff only at the REST layer.
	 *
	 * @param int $profile_id Profile ID.
	 * @param int $limit      Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( int $profile_id, int $limit = 50 ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_profile_id = %d ORDER BY id DESC LIMIT %d", $profile_id, max( 1, min( 200, $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $rows as &$row ) {
			$reviewer               = get_userdata( (int) $row['reviewer_id'] );
			$row['reviewer_name']   = $reviewer ? $reviewer->display_name : '';
			$row['status_label']    = self::status_labels()[ $row['status'] ] ?? $row['status'];
			$row['type_label']      = self::type_labels()[ $row['type'] ] ?? $row['type'];
		}
		return (array) $rows;
	}

	/**
	 * Daily: append an `expired` record when a verified record passes its expiry.
	 *
	 * @return int Records expired.
	 */
	public static function expire_due(): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.vendor_profile_id, v.type FROM {$table} v
				INNER JOIN ( SELECT vendor_profile_id, type, MAX(id) AS max_id FROM {$table} GROUP BY vendor_profile_id, type ) latest ON latest.max_id = v.id
				WHERE v.status = 'verified' AND v.expires_at IS NOT NULL AND v.expires_at < %s LIMIT 200",
				current_time( 'mysql' )
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			self::record( (int) $row['vendor_profile_id'], (string) $row['type'], 'expired', array( 'source' => 'system_expiry', 'reason' => __( 'Verification period ended.', 'lnkup-marketplace' ) ) );
		}
		return count( (array) $rows );
	}

	/**
	 * Verified records expiring within N days (for reminders and admin health).
	 *
	 * @param int $days Window.
	 * @return array<int,array<string,mixed>>
	 */
	public static function expiring_within( int $days ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.* FROM {$table} v
				INNER JOIN ( SELECT vendor_profile_id, type, MAX(id) AS max_id FROM {$table} GROUP BY vendor_profile_id, type ) latest ON latest.max_id = v.id
				WHERE v.status = 'verified' AND v.expires_at IS NOT NULL AND v.expires_at BETWEEN %s AND %s LIMIT 500",
				current_time( 'mysql' ),
				gmdate( 'Y-m-d H:i:s', strtotime( '+' . max( 1, $days ) . ' days', current_time( 'timestamp' ) ) ) // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			),
			ARRAY_A
		);
	}

	/**
	 * Staff queue: latest record per profile/type in pending or info_required.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function queue( int $limit = 50 ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT v.* FROM {$table} v
				INNER JOIN ( SELECT vendor_profile_id, type, MAX(id) AS max_id FROM {$table} GROUP BY vendor_profile_id, type ) latest ON latest.max_id = v.id
				WHERE v.status IN ('pending','info_required') ORDER BY v.created_at ASC LIMIT %d",
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);
	}

	/**
	 * Backfill one `legacy_meta` business-identity record per profile that has legacy status and no records. Idempotent.
	 *
	 * @param int $batch Batch size.
	 * @return int Rows inserted.
	 */
	public static function backfill_from_meta( int $batch = 200 ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$table = LNKup_Passport_Tables::verification_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, pm.meta_value, p.post_modified FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_lnkup_verification_status'
				LEFT JOIN {$table} v ON v.vendor_profile_id = p.ID AND v.type = 'business_identity'
				WHERE p.post_type = 'lnkup_vendor_profile' AND pm.meta_value IN ('approved','pending','rejected') AND v.id IS NULL
				LIMIT %d",
				max( 1, $batch )
			),
			ARRAY_A
		);
		$map = array( 'approved' => 'verified', 'pending' => 'pending', 'rejected' => 'rejected' );
		foreach ( (array) $rows as $row ) {
			$status = $map[ $row['meta_value'] ];
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'vendor_profile_id' => (int) $row['ID'],
					'type'              => 'business_identity',
					'status'            => $status,
					'authority'         => 'LNKup',
					'source'            => 'legacy_meta',
					'document_id'       => 0,
					'reviewer_id'       => 0,
					'verified_at'       => 'verified' === $status ? $row['post_modified'] : null,
					'expires_at'        => null,
					'revoked_at'        => null,
					'reason'            => __( 'Imported from the previous verification status.', 'lnkup-marketplace' ),
					'created_at'        => current_time( 'mysql' ),
				)
			);
		}
		return count( (array) $rows );
	}

	/**
	 * Legacy writers (wp-admin handler, REST admin/manual, other code) still update the meta directly.
	 * Mirror those changes into an appended record so no decision is lost.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function on_legacy_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id );
		if ( self::$syncing || '_lnkup_verification_status' !== $meta_key || 'lnkup_vendor_profile' !== get_post_type( (int) $post_id ) || ! self::ready() ) {
			return;
		}
		$map = array( 'approved' => 'verified', 'pending' => 'pending', 'rejected' => 'rejected' );
		$new = $map[ (string) $meta_value ] ?? '';
		if ( '' === $new || self::current( (int) $post_id, 'business_identity' )['status'] === $new ) {
			return;
		}
		self::$syncing = true;
		self::record( (int) $post_id, 'business_identity', $new, array( 'source' => 'legacy_admin', 'reviewer_id' => get_current_user_id() ) );
		self::$syncing = false;
	}

	private static function sync_legacy_meta( int $profile_id, string $status ): void {
		$map    = array(
			'verified'      => 'approved',
			'pending'       => 'pending',
			'info_required' => 'pending',
			'rejected'      => 'rejected',
			'expired'       => 'expired',
			'revoked'       => 'revoked',
		);
		$legacy = $map[ $status ] ?? '';
		if ( '' === $legacy ) {
			return;
		}
		self::$syncing = true;
		update_post_meta( $profile_id, '_lnkup_verification_status', $legacy );
		self::$syncing = false;
	}

	/**
	 * @param mixed $value Date or datetime.
	 */
	private static function normalize_datetime( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}
		$ts = strtotime( (string) $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}
}
