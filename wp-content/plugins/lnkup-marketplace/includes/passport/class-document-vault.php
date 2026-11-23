<?php
/**
 * Reusable vendor Document Vault: versions, per-event reuse checks, consented sharing, signed downloads, reminders.
 *
 * A document reviewed for one event is never treated as sufficient for another automatically:
 * reuse checks only suggest, the organizer still reviews, and sharing always requires vendor consent.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Document_Vault {

	const SHARE_DAYS_AFTER_EVENT = 60;

	public static function init(): void {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status' ), 30, 2 );
	}

	/**
	 * Organizer access ends when an application is withdrawn or cancelled.
	 *
	 * @param int    $application_id Application ID.
	 * @param string $status         New status (second arg varies across callers).
	 */
	public static function on_application_status( $application_id, $status = '' ): void {
		$status = is_string( $status ) ? $status : '';
		$now    = (string) get_post_meta( (int) $application_id, '_lnkup_application_status', true );
		if ( ! in_array( 'cancelled', array( $status, $now ), true ) || ! self::ready() ) {
			return;
		}
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . LNKup_Passport_Tables::share_table() . ' SET revoked_at = %s WHERE application_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				current_time( 'mysql' ),
				(int) $application_id
			)
		);
	}

	private static function ready(): bool {
		return LNKup_Passport_Tables::exists( LNKup_Passport_Tables::share_table() );
	}

	/**
	 * Give legacy documents a version group (themselves) and version 1. Idempotent.
	 *
	 * @param int $batch Batch size.
	 */
	public static function backfill_groups( int $batch = 200 ): int {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_document',
				'post_status'    => 'any',
				'posts_per_page' => $batch,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_document_group_id', 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		foreach ( $ids as $doc_id ) {
			update_post_meta( (int) $doc_id, '_lnkup_document_group_id', (int) $doc_id );
			if ( ! get_post_meta( (int) $doc_id, '_lnkup_version', true ) ) {
				update_post_meta( (int) $doc_id, '_lnkup_version', 1 );
			}
		}
		return count( $ids );
	}

	/**
	 * Owner vault: one entry per document group (current version + history + active shares).
	 *
	 * @param int $user_id Vendor user ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function vault( int $user_id ): array {
		$types  = LNKup_Documents::document_types();
		$groups = array();
		foreach ( LNKup_Documents::get_for_vendor( $user_id ) as $doc ) {
			$group = (int) get_post_meta( $doc->ID, '_lnkup_document_group_id', true ) ?: (int) $doc->ID;
			$groups[ $group ][] = $doc;
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( $user_id );
		$shares     = $profile_id ? self::shares_for_profile( $profile_id ) : array();
		$out        = array();
		foreach ( $groups as $group_id => $docs ) {
			usort(
				$docs,
				static function ( $a, $b ) {
					return (int) get_post_meta( $b->ID, '_lnkup_version', true ) <=> (int) get_post_meta( $a->ID, '_lnkup_version', true ) ?: $b->ID <=> $a->ID;
				}
			);
			$current = null;
			foreach ( $docs as $doc ) {
				if ( ! get_post_meta( $doc->ID, '_lnkup_superseded_by', true ) ) {
					$current = $doc;
					break;
				}
			}
			$current = $current ?: $docs[0];
			$type    = (string) get_post_meta( $current->ID, '_lnkup_document_type', true );
			$out[]   = array(
				'group_id' => (int) $group_id,
				'type'     => $type,
				'label'    => $types[ $type ] ?? $type,
				'current'  => self::describe( (int) $current->ID ),
				'versions' => array_map( static fn( $d ) => self::describe( (int) $d->ID ), $docs ),
				'shares'   => array_values( array_filter( $shares, static fn( $s ) => in_array( (int) $s['document_id'], array_map( static fn( $d ) => (int) $d->ID, $docs ), true ) ) ),
			);
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );
		return $out;
	}

	/**
	 * Owner-facing description of one document version (never includes a direct file URL).
	 *
	 * @param int $doc_id Document ID.
	 * @return array<string,mixed>
	 */
	public static function describe( int $doc_id ): array {
		$types = LNKup_Documents::document_types();
		$type  = (string) get_post_meta( $doc_id, '_lnkup_document_type', true );
		return array(
			'id'               => $doc_id,
			'type'             => $type,
			'label'            => $types[ $type ] ?? $type,
			'display_name'     => (string) get_post_meta( $doc_id, '_lnkup_display_name', true ),
			'version'          => max( 1, (int) get_post_meta( $doc_id, '_lnkup_version', true ) ),
			'status'           => LNKup_Documents::effective_status( $doc_id ),
			'review_status'    => (string) get_post_meta( $doc_id, '_lnkup_verification_status', true ),
			'rejection_reason' => (string) get_post_meta( $doc_id, '_lnkup_rejection_reason', true ),
			'expires_at'       => (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true ),
			'uploaded_at'      => (string) get_post_field( 'post_date_gmt', $doc_id ),
			'superseded_by'    => (int) get_post_meta( $doc_id, '_lnkup_superseded_by', true ),
			'insured_name'     => (string) get_post_meta( $doc_id, '_lnkup_insured_name', true ),
			'coverage_amount'  => (float) get_post_meta( $doc_id, '_lnkup_coverage_amount', true ),
			'download_url'     => rest_url( 'lnkup/v1/documents/' . $doc_id . '/file' ),
		);
	}

	/**
	 * Per-requirement reuse check for an opportunity. Suggestions only; never approves for the event.
	 *
	 * @param int $profile_id     Vendor profile ID.
	 * @param int $opportunity_id Opportunity ID.
	 * @return array{items:array,all_satisfied:bool,event_date:string}
	 */
	public static function find_reusable( int $profile_id, int $opportunity_id ): array {
		$vendor_id    = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$event_id     = (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true );
		$organizer_id = (int) get_post_meta( $opportunity_id, '_lnkup_organizer_user_id', true ) ?: (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$event_date   = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$event_ts     = $event_date ? (int) strtotime( $event_date ) : 0;
		$required     = function_exists( 'lnkup_get_opportunity_required_documents' ) ? lnkup_get_opportunity_required_documents( $opportunity_id ) : array();
		$rules        = self::requirement_rules( $opportunity_id );
		$current      = LNKup_Documents::current_by_type( $vendor_id );
		$types        = LNKup_Documents::document_types();
		$items        = array();
		$all_ok       = true;

		foreach ( $required as $type ) {
			$doc     = $current[ $type ] ?? null;
			$doc_id  = $doc ? (int) $doc->ID : 0;
			$reasons = array();
			$result  = 'missing';
			if ( $doc_id ) {
				$status = LNKup_Documents::effective_status( $doc_id, $event_ts && $event_ts > time() ? $event_ts : 0 );
				switch ( $status ) {
					case 'approved':
					case 'expiring':
						$result = 'satisfies';
						break;
					case 'pending':
						$result    = 'needs_review';
						$reasons[] = __( 'Waiting for LNKup review.', 'lnkup-marketplace' );
						break;
					case 'expired':
						$result    = 'insufficient';
						$reasons[] = $event_ts ? __( 'Expires before this event.', 'lnkup-marketplace' ) : __( 'Expired.', 'lnkup-marketplace' );
						break;
					case 'rejected':
						$result    = 'insufficient';
						$reasons[] = __( 'Not approved — upload a new version.', 'lnkup-marketplace' );
						break;
				}
				if ( 'satisfies' === $result && ! empty( $rules[ $type ] ) ) {
					list( $result, $rule_reasons ) = self::check_rules( $doc_id, $rules[ $type ] );
					$reasons = array_merge( $reasons, $rule_reasons );
				}
			} else {
				$reasons[] = __( 'Not in your Document Vault yet.', 'lnkup-marketplace' );
			}
			if ( 'satisfies' !== $result ) {
				$all_ok = false;
			}
			$items[] = array(
				'type'          => $type,
				'label'         => $types[ $type ] ?? $type,
				'document_id'   => $doc_id,
				'version'       => $doc_id ? max( 1, (int) get_post_meta( $doc_id, '_lnkup_version', true ) ) : 0,
				'expires_at'    => $doc_id ? (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true ) : '',
				'result'        => $result,
				'reasons'       => $reasons,
				'already_shared' => $doc_id && $organizer_id ? (bool) self::active_share( $doc_id, $organizer_id, $event_id ) : false,
				'requirements'  => $rules[ $type ] ?? array(),
			);
		}
		return array(
			'items'         => $items,
			'all_satisfied' => $all_ok,
			'event_date'    => $event_date,
			'organizer_review_required' => true,
		);
	}

	/**
	 * Optional per-type rules on the opportunity, e.g. {"certificate_of_insurance":{"min_coverage":1000000,"named_insured":"Org LLC"}}.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function requirement_rules( int $opportunity_id ): array {
		$raw   = (string) get_post_meta( $opportunity_id, '_lnkup_document_requirements', true );
		$rules = '' !== $raw ? json_decode( $raw, true ) : array();
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * @param int   $doc_id Document ID.
	 * @param array $rule   Rule.
	 * @return array{0:string,1:string[]}
	 */
	private static function check_rules( int $doc_id, array $rule ): array {
		$reasons = array();
		$result  = 'satisfies';
		if ( ! empty( $rule['min_coverage'] ) ) {
			$coverage = (float) get_post_meta( $doc_id, '_lnkup_coverage_amount', true );
			if ( $coverage <= 0 ) {
				$result    = 'needs_review';
				$reasons[] = __( 'Coverage amount not recorded — the organizer will check it.', 'lnkup-marketplace' );
			} elseif ( $coverage < (float) $rule['min_coverage'] ) {
				/* translators: %s: required coverage amount */
				$reasons[] = sprintf( __( 'Coverage is below the required %s.', 'lnkup-marketplace' ), number_format_i18n( (float) $rule['min_coverage'] ) );
				return array( 'insufficient', $reasons );
			}
		}
		if ( ! empty( $rule['named_insured'] ) ) {
			$named = strtolower( (string) get_post_meta( $doc_id, '_lnkup_insured_name', true ) );
			if ( '' === $named || false === strpos( $named, strtolower( (string) $rule['named_insured'] ) ) ) {
				$result    = 'needs_review';
				$reasons[] = __( 'Additional insured must be confirmed by the organizer.', 'lnkup-marketplace' );
			}
		}
		return array( $result, $reasons );
	}

	/**
	 * Vendor consents to share one specific document version with one organizer for one event/application.
	 *
	 * @param int $profile_id     Vendor profile ID.
	 * @param int $document_id    Document ID (current version).
	 * @param int $application_id Application the share supports.
	 * @param int $granted_by     Vendor user ID.
	 * @return int|WP_Error Share ID.
	 */
	public static function share( int $profile_id, int $document_id, int $application_id, int $granted_by ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'not_ready', __( 'Document sharing is not available yet.', 'lnkup-marketplace' ), array( 'status' => 503 ) );
		}
		$vendor_id = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		if ( ! $vendor_id || $vendor_id !== $granted_by && ! LNKup_Capabilities::user_has_manage_all( $granted_by ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( 'lnkup_document' !== get_post_type( $document_id ) || (int) get_post_meta( $document_id, '_lnkup_vendor_user_id', true ) !== $vendor_id ) {
			return new WP_Error( 'invalid_document', __( 'Choose one of your own documents.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( get_post_meta( $document_id, '_lnkup_superseded_by', true ) ) {
			return new WP_Error( 'superseded', __( 'Share the newest version of this document.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( in_array( LNKup_Documents::effective_status( $document_id ), array( 'expired', 'rejected' ), true ) ) {
			return new WP_Error( 'not_shareable', __( 'Expired or rejected documents cannot be shared.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'lnkup_application' !== get_post_type( $application_id ) || (int) get_post_meta( $application_id, '_lnkup_vendor_user_id', true ) !== $vendor_id ) {
			return new WP_Error( 'invalid_application', __( 'Choose one of your applications.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( in_array( (string) get_post_meta( $application_id, '_lnkup_application_status', true ), array( 'cancelled', 'rejected' ), true ) ) {
			return new WP_Error( 'inactive_application', __( 'This application is no longer active.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$organizer_id = (int) get_post_meta( $application_id, '_lnkup_organizer_user_id', true );
		$event_id     = (int) get_post_meta( $application_id, '_lnkup_event_id', true );
		if ( ! $organizer_id ) {
			return new WP_Error( 'invalid_application', __( 'This application has no organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$existing = self::active_share( $document_id, $organizer_id, $event_id );
		if ( $existing ) {
			return $existing;
		}
		$event_date = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$expires    = $event_date && strtotime( $event_date ) ? gmdate( 'Y-m-d H:i:s', strtotime( $event_date . ' +' . self::SHARE_DAYS_AFTER_EVENT . ' days' ) ) : gmdate( 'Y-m-d H:i:s', strtotime( '+180 days' ) );

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Passport_Tables::share_table(),
			array(
				'document_id'       => $document_id,
				'vendor_profile_id' => $profile_id,
				'organizer_user_id' => $organizer_id,
				'event_id'          => $event_id,
				'application_id'    => $application_id,
				'granted_by'        => $granted_by,
				'granted_at'        => current_time( 'mysql' ),
				'revoked_at'        => null,
				'expires_at'        => $expires,
			)
		);
		$share_id = (int) $wpdb->insert_id;
		self::record_on_application( $application_id, $document_id );
		LNKup_Vendor_Reputation::log_activity(
			'document_shared',
			$profile_id,
			array(
				'organizer_user_id' => $organizer_id,
				'event_id'          => $event_id,
				'application_id'    => $application_id,
				'payload'           => array( 'document_id' => $document_id, 'share_id' => $share_id ),
			)
		);
		do_action( 'lnkup_document_shared', $share_id, $document_id, $organizer_id, $application_id );
		return $share_id;
	}

	/**
	 * Vendor revokes a share. Access stops immediately.
	 *
	 * @param int $share_id Share ID.
	 * @param int $user_id  Vendor user ID.
	 * @return true|WP_Error
	 */
	public static function revoke_share( int $share_id, int $user_id ) {
		$share = self::get_share( $share_id );
		if ( ! $share ) {
			return new WP_Error( 'not_found', __( 'Share not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$owner = (int) get_post_meta( (int) $share['vendor_profile_id'], '_lnkup_owner_user_id', true );
		if ( $owner !== $user_id && ! LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		global $wpdb;
		$wpdb->update( LNKup_Passport_Tables::share_table(), array( 'revoked_at' => current_time( 'mysql' ) ), array( 'id' => $share_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		LNKup_Vendor_Reputation::log_activity( 'document_share_revoked', (int) $share['vendor_profile_id'], array( 'payload' => array( 'share_id' => $share_id ) ) );
		do_action( 'lnkup_document_share_revoked', $share_id );
		return true;
	}

	/**
	 * @param int $share_id Share ID.
	 * @return array<string,mixed>|null
	 */
	public static function get_share( int $share_id ): ?array {
		if ( ! self::ready() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE id = %d', $share_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $row ?: null;
	}

	/**
	 * Active share ID for a document version, organizer and event.
	 */
	public static function active_share( int $document_id, int $organizer_id, int $event_id ): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE document_id = %d AND organizer_user_id = %d AND event_id = %d AND revoked_at IS NULL AND ( expires_at IS NULL OR expires_at > %s ) ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$document_id,
				$organizer_id,
				$event_id,
				current_time( 'mysql' )
			)
		);
	}

	/**
	 * Whether the share is active and belongs to this organizer (or the viewer is staff).
	 *
	 * @param array $share   Share row.
	 * @param int   $user_id Viewer.
	 */
	public static function can_access_share( array $share, int $user_id ): bool {
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		if ( ! empty( $share['revoked_at'] ) || ( ! empty( $share['expires_at'] ) && strtotime( (string) $share['expires_at'] ) < current_time( 'timestamp' ) ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			return false;
		}
		return (int) $share['organizer_user_id'] === $user_id && $user_id > 0;
	}

	/**
	 * All shares for a vendor profile (owner view).
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function shares_for_profile( int $profile_id ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE vendor_profile_id = %d ORDER BY id DESC LIMIT 200', $profile_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'shape_share' ), $rows );
	}

	/**
	 * Shares an organizer may see for one application (metadata + short-lived links only).
	 *
	 * @param int $organizer_id   Organizer.
	 * @param int $application_id Application.
	 * @return array<int,array<string,mixed>>
	 */
	public static function shares_for_application( int $organizer_id, int $application_id ): array {
		if ( ! self::ready() ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE application_id = %d AND organizer_user_id = %d AND revoked_at IS NULL ORDER BY id DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$application_id,
				$organizer_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! self::can_access_share( $row, $organizer_id ) ) {
				continue;
			}
			$shaped                 = self::shape_share( $row );
			$shaped['download_url'] = self::signed_url( (int) $row['id'], $organizer_id );
			$out[]                  = $shaped;
		}
		return $out;
	}

	/**
	 * @param array $row Share row.
	 * @return array<string,mixed>
	 */
	private static function shape_share( array $row ): array {
		$doc_id    = (int) $row['document_id'];
		$organizer = get_userdata( (int) $row['organizer_user_id'] );
		$types     = LNKup_Documents::document_types();
		$type      = (string) get_post_meta( $doc_id, '_lnkup_document_type', true );
		$active    = empty( $row['revoked_at'] ) && ( empty( $row['expires_at'] ) || strtotime( (string) $row['expires_at'] ) > current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return array(
			'id'             => (int) $row['id'],
			'document_id'    => $doc_id,
			'document_label' => $types[ $type ] ?? $type,
			'version'        => max( 1, (int) get_post_meta( $doc_id, '_lnkup_version', true ) ),
			'status'         => LNKup_Documents::effective_status( $doc_id ),
			'expires_on'     => (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true ),
			'organizer_name' => $organizer ? $organizer->display_name : '',
			'event_id'       => (int) $row['event_id'],
			'event_title'    => (int) $row['event_id'] ? lnkup_plain_title( (int) $row['event_id'] ) : '',
			'application_id' => (int) $row['application_id'],
			'granted_at'     => (string) $row['granted_at'],
			'revoked_at'     => $row['revoked_at'],
			'access_until'   => $row['expires_at'],
			'active'         => $active,
		);
	}

	/**
	 * Short-lived HMAC-signed download URL bound to the share and the organizer.
	 *
	 * @param int $share_id Share ID.
	 * @param int $user_id  Organizer.
	 */
	public static function signed_url( int $share_id, int $user_id ): string {
		$expires = time() + LNKup_Passport_Settings::int( 'passport_share_token_minutes' ) * MINUTE_IN_SECONDS;
		return add_query_arg(
			array(
				'expires' => $expires,
				'token'   => self::token( $share_id, $user_id, $expires ),
			),
			rest_url( 'lnkup/v1/documents/shares/' . $share_id . '/file' )
		);
	}

	public static function token( int $share_id, int $user_id, int $expires ): string {
		return hash_hmac( 'sha256', $share_id . '|' . $user_id . '|' . $expires, wp_salt( 'auth' ) . 'lnkup_doc_share' );
	}

	public static function verify_token( int $share_id, int $user_id, int $expires, string $token ): bool {
		if ( $expires < time() || '' === $token ) {
			return false;
		}
		return hash_equals( self::token( $share_id, $user_id, $expires ), $token );
	}

	/**
	 * Remember which exact version was provided for an application.
	 *
	 * @param int $application_id Application.
	 * @param int $document_id    Document version.
	 */
	public static function record_on_application( int $application_id, int $document_id ): void {
		$raw  = (string) get_post_meta( $application_id, '_lnkup_shared_document_versions', true );
		$list = '' !== $raw ? json_decode( $raw, true ) : array();
		$list = is_array( $list ) ? $list : array();
		$type = (string) get_post_meta( $document_id, '_lnkup_document_type', true );
		$list[ $type ] = array(
			'document_id' => $document_id,
			'version'     => max( 1, (int) get_post_meta( $document_id, '_lnkup_version', true ) ),
			'shared_at'   => current_time( 'mysql' ),
		);
		update_post_meta( $application_id, '_lnkup_shared_document_versions', wp_json_encode( $list ) );
	}

	/**
	 * Daily: count shares that lapsed (access is enforced at read time; this is for health reporting).
	 */
	public static function expire_shares(): int {
		if ( ! self::ready() ) {
			return 0;
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Passport_Tables::share_table() . ' WHERE revoked_at IS NULL AND expires_at < %s', current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'lnkup_passport_lapsed_shares', $count, false );
		return $count;
	}

	/**
	 * Daily: fire `lnkup_document_expiring` at configured thresholds, only for current documents that matter
	 * (required by an upcoming booking/application, or suggested for the vendor's categories).
	 */
	public static function send_expiry_reminders(): int {
		$sent = 0;
		foreach ( LNKup_Passport_Settings::reminder_days() as $days ) {
			$target = wp_date( 'Y-m-d', strtotime( '+' . $days . ' days' ) );
			$docs   = get_posts(
				array(
					'post_type'      => 'lnkup_document',
					'post_status'    => 'publish',
					'posts_per_page' => 500,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_verification_status', 'value' => 'approved' ),
						array( 'key' => '_lnkup_expiration_date', 'value' => $target ),
						array( 'key' => '_lnkup_superseded_by', 'compare' => 'NOT EXISTS' ),
					),
				)
			);
			foreach ( $docs as $doc_id ) {
				$doc_id = (int) $doc_id;
				$flag   = '_lnkup_expiry_reminded_' . $days . '_' . sanitize_key( $target );
				if ( get_post_meta( $doc_id, $flag, true ) ) {
					continue;
				}
				$vendor_id = (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true );
				$context   = self::reminder_context( $doc_id, $vendor_id );
				if ( ! $context['relevant'] ) {
					continue;
				}
				update_post_meta( $doc_id, $flag, current_time( 'mysql' ) );
				do_action( 'lnkup_document_expiring', $doc_id, $vendor_id, $days, $context );
				++$sent;
			}
		}
		foreach ( LNKup_Verification_Records::expiring_within( 14 ) as $row ) {
			$key = '_lnkup_verif_expiry_reminded_' . (int) $row['id'];
			if ( get_post_meta( (int) $row['vendor_profile_id'], $key, true ) ) {
				continue;
			}
			update_post_meta( (int) $row['vendor_profile_id'], $key, current_time( 'mysql' ) );
			do_action( 'lnkup_vendor_verification_expiring', (int) $row['vendor_profile_id'], (string) $row['type'], (string) $row['expires_at'] );
			++$sent;
		}
		return $sent;
	}

	/**
	 * Why (and for which events) a document's expiry matters.
	 *
	 * @param int $doc_id    Document.
	 * @param int $vendor_id Vendor user ID.
	 * @return array{relevant:bool,event_ids:int[],reason:string}
	 */
	public static function reminder_context( int $doc_id, int $vendor_id ): array {
		$type    = (string) get_post_meta( $doc_id, '_lnkup_document_type', true );
		$expires = (int) strtotime( (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true ) );
		$events  = array();
		$apps    = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'submitted', 'under_review', 'approved', 'payment_required', 'paid' ), 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $apps as $app_id ) {
			$opp_id   = (int) get_post_meta( (int) $app_id, '_lnkup_opportunity_id', true );
			$event_id = (int) get_post_meta( (int) $app_id, '_lnkup_event_id', true );
			$date     = $event_id ? (int) strtotime( (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ) : 0;
			if ( $date && $date >= strtotime( 'today' ) && $opp_id && in_array( $type, lnkup_get_opportunity_required_documents( $opp_id ), true ) ) {
				$events[] = $event_id;
			}
		}
		if ( $events ) {
			$before = array_filter( $events, static fn( $e ) => (int) strtotime( (string) get_post_meta( $e, '_lnkup_event_date', true ) ) > $expires );
			return array(
				'relevant'  => true,
				'event_ids' => array_values( array_unique( $events ) ),
				'reason'    => $before ? 'expires_before_event' : 'required_for_upcoming_event',
			);
		}
		$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
		$suggested  = $profile_id ? LNKup_Verification::get_category_suggested_documents( $profile_id ) : array();
		return array(
			'relevant'  => in_array( $type, $suggested, true ) || 'certificate_of_insurance' === $type,
			'event_ids' => array(),
			'reason'    => 'recommended_for_category',
		);
	}

	/**
	 * Stream a document with audit logging (used by owner/verifier route and organizer share route).
	 *
	 * @param int    $doc_id  Document.
	 * @param string $channel Audit channel.
	 * @param array  $extra   Extra audit context.
	 * @return WP_Error|void
	 */
	public static function stream( int $doc_id, string $channel, array $extra = array() ) {
		$attachment_id = (int) get_post_meta( $doc_id, '_lnkup_attachment_id', true );
		$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'not_found', __( 'File not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( class_exists( 'LNKup_Audit_Log' ) ) {
			LNKup_Audit_Log::log(
				'document_downloaded',
				'document',
				$doc_id,
				array(
					'new' => array_merge(
						array(
							'channel'        => $channel,
							'vendor_user_id' => (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true ),
							'document_type'  => (string) get_post_meta( $doc_id, '_lnkup_document_type', true ),
						),
						$extra
					),
				)
			);
		}
		nocache_headers();
		header( 'Content-Type: ' . ( get_post_mime_type( $attachment_id ) ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: ' . LNKup_Documents::download_filename( $doc_id, (string) $file ) );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
