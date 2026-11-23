<?php
/**
 * Per-event organizer document review.
 *
 * Each confirmed or approved application needs the document types its vendor slot requires.
 * The organizer can request a document, accept or reject what the vendor shared, ask for a
 * replacement, or waive the requirement. These decisions apply to that application only:
 * the platform-wide `_lnkup_verification_status` written by LNKup admins is never changed.
 *
 * Files are only reachable through Passport shares the vendor consented to
 * (LNKup_Document_Vault); this class never widens document download permissions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Documents {

	const REVIEW_STATUSES = array( 'requested', 'accepted', 'rejected', 'replacement_requested', 'waived' );
	const APP_STATUSES    = array( 'approved', 'payment_required', 'paid', 'completed' );
	const REQUEST_THROTTLE = DAY_IN_SECONDS;

	public static function init() {
		add_action( 'lnkup_document_shared', array( __CLASS__, 'on_document_shared' ), 20, 4 );
	}

	/**
	 * A new share supersedes an earlier rejection or replacement request: the vendor sent a new file.
	 *
	 * @param int $share_id       Share.
	 * @param int $document_id    Document.
	 * @param int $organizer_id   Organizer.
	 * @param int $application_id Application.
	 */
	public static function on_document_shared( $share_id, $document_id, $organizer_id, $application_id ) {
		global $wpdb;
		unset( $share_id, $organizer_id );
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return;
		}
		$type = (string) get_post_meta( (int) $document_id, '_lnkup_document_type', true );
		$row  = self::review_row( (int) $application_id, $type );
		if ( $row && in_array( $row['status'], array( 'requested', 'rejected', 'replacement_requested' ), true ) ) {
			$wpdb->update( LNKup_Organizer_OS_Tables::document_reviews_table(), array( 'status' => 'requested', 'document_id' => (int) $document_id, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		do_action( 'lnkup_organizer_document_reviewed', (int) $application_id );
	}

	/**
	 * Requirement rows for an event (one per application × required type).
	 *
	 * @param int   $event_id Event.
	 * @param array $filters  status, type, application_ids, viewer.
	 * @return array<int, array<string, mixed>>
	 */
	public static function requirements_for_event( $event_id, array $filters = array() ) {
		$apps = ! empty( $filters['application_ids'] )
			? array_map( 'absint', (array) $filters['application_ids'] )
			: get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => array( 'publish', 'pending', 'private', 'draft', 'future' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_event_id', 'value' => (string) absint( $event_id ) ),
						array( 'key' => '_lnkup_application_status', 'value' => self::APP_STATUSES, 'compare' => 'IN' ),
					),
				)
			);
		if ( $apps ) {
			update_meta_cache( 'post', $apps );
		}
		$viewer = absint( $filters['viewer'] ?? 0 );
		$rows   = array();
		foreach ( $apps as $app_id ) {
			foreach ( self::requirements_for_application( (int) $app_id, $viewer ) as $row ) {
				if ( ! empty( $filters['status'] ) && $row['status'] !== $filters['status'] ) {
					continue;
				}
				if ( ! empty( $filters['type'] ) && $row['type'] !== $filters['type'] ) {
					continue;
				}
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * @param int $app_id Application.
	 * @param int $viewer Viewer (download links only for the event owner, through Passport).
	 * @return array<int, array<string, mixed>>
	 */
	public static function requirements_for_application( $app_id, $viewer = 0 ) {
		$app_id   = absint( $app_id );
		$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$required = $opp_id ? (array) lnkup_get_opportunity_required_documents( $opp_id ) : array();
		if ( ! $required ) {
			return array();
		}
		$owner      = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$vendor_id  = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$labels     = LNKup_Documents::document_types();
		$shares     = self::shares_by_type( $owner, $app_id );
		$snapshot   = array();
		foreach ( LNKup_Applications::get_documents_snapshot( $app_id ) as $snap ) {
			if ( ! empty( $snap['type'] ) && ! empty( $snap['doc_id'] ) ) {
				$snapshot[ (string) $snap['type'] ] = (int) $snap['doc_id'];
			}
		}
		$reviews = self::reviews_for_application( $app_id );
		$name    = $profile_id ? ( (string) get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: lnkup_plain_title( $profile_id ) ) : '';
		$out     = array();
		foreach ( $required as $type ) {
			$type   = (string) $type;
			$share  = $shares[ $type ] ?? null;
			$doc_id = $share ? (int) $share['document_id'] : ( $snapshot[ $type ] ?? 0 );
			if ( $doc_id && (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true ) !== $vendor_id ) {
				$doc_id = 0;
			}
			$review  = $reviews[ $type ] ?? null;
			$expires = $doc_id ? (string) get_post_meta( $doc_id, '_lnkup_expiration_date', true ) : '';
			$status  = self::status( $review, $doc_id, $expires, (bool) $share );
			$out[]   = array(
				'application_id' => $app_id,
				'event_id'       => (int) get_post_meta( $app_id, '_lnkup_event_id', true ),
				'vendor_name'    => $name,
				'profile_id'     => $profile_id,
				'type'           => $type,
				'label'          => $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ),
				'status'         => $status,
				'document_id'    => $doc_id,
				'expires_on'     => $expires,
				'shared'         => (bool) $share,
				'download_url'   => $share && $viewer && $viewer === $owner ? (string) ( $share['download_url'] ?? '' ) : '',
				'reason'         => $review ? (string) $review['reason'] : '',
				'message'        => $review ? (string) $review['message'] : '',
				'requested_at'   => $review ? (string) $review['requested_at'] : '',
				'reviewed_at'    => $review ? (string) $review['reviewed_at'] : '',
				'reviewed_by'    => $review && $review['reviewed_by'] ? LNKup_Application_Review::user_label( (int) $review['reviewed_by'] )['name'] : '',
			);
		}
		return $out;
	}

	/**
	 * Displayed status: waived > expired > organizer decision > submitted > requested > missing.
	 * An expired file is never shown as accepted.
	 *
	 * @param array|null $review  Review row.
	 * @param int        $doc_id  Document on file for this requirement.
	 * @param string     $expires Expiration date.
	 * @param bool       $shared  Vendor shared it with this organizer.
	 */
	public static function status( $review, $doc_id, $expires, $shared ) {
		$state = $review ? (string) $review['status'] : '';
		if ( 'waived' === $state ) {
			return 'waived';
		}
		if ( $doc_id && LNKup_Documents::is_expired( $expires ) ) {
			return 'expired';
		}
		if ( in_array( $state, array( 'accepted', 'rejected', 'replacement_requested' ), true ) ) {
			return $state;
		}
		if ( $shared || $doc_id ) {
			return 'submitted';
		}
		return 'requested' === $state ? 'requested' : 'missing';
	}

	/**
	 * @param int $owner  Organizer.
	 * @param int $app_id Application.
	 * @return array<string, array> type => newest active share (with download_url).
	 */
	private static function shares_by_type( $owner, $app_id ) {
		if ( ! class_exists( 'LNKup_Document_Vault' ) || ! $owner ) {
			return array();
		}
		$out = array();
		foreach ( LNKup_Document_Vault::shares_for_application( $owner, $app_id ) as $share ) {
			$type = (string) get_post_meta( (int) $share['document_id'], '_lnkup_document_type', true );
			if ( '' !== $type && ! isset( $out[ $type ] ) && ! empty( $share['active'] ) ) {
				$out[ $type ] = $share;
			}
		}
		return $out;
	}

	/**
	 * @param int $app_id Application.
	 * @return array<string, array> type => row.
	 */
	private static function reviews_for_application( $app_id ) {
		global $wpdb;
		if ( ! LNKup_Organizer_OS_Tables::ready( '2' ) ) {
			return array();
		}
		$table = LNKup_Organizer_OS_Tables::document_reviews_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", absint( $app_id ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['requirement_type'] ] = $row;
		}
		return $out;
	}

	private static function review_row( $app_id, $type ) {
		return self::reviews_for_application( $app_id )[ (string) $type ] ?? null;
	}

	/**
	 * @param int    $app_id Application.
	 * @param string $type   Requirement type.
	 * @return string|WP_Error The type when it is required for this application.
	 */
	private static function assert_required( $app_id, $type ) {
		$type   = sanitize_key( (string) $type );
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( ! in_array( $type, (array) lnkup_get_opportunity_required_documents( $opp_id ), true ) ) {
			return new WP_Error( 'lnkup_document_not_required', __( 'That document is not required for this vendor slot.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( (string) get_post_meta( $app_id, '_lnkup_application_status', true ), self::APP_STATUSES, true ) ) {
			return new WP_Error( 'lnkup_document_not_booked', __( 'Documents are reviewed once the vendor is approved.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		return $type;
	}

	/**
	 * Insert or update one review row and append to its history.
	 *
	 * @param int    $app_id Application.
	 * @param string $type   Type.
	 * @param array  $fields Columns.
	 * @param int    $actor  Actor.
	 */
	private static function upsert( $app_id, $type, array $fields, $actor ) {
		global $wpdb;
		$table   = LNKup_Organizer_OS_Tables::document_reviews_table();
		$row     = self::review_row( $app_id, $type );
		$now     = current_time( 'mysql' );
		$history = $row ? json_decode( (string) $row['history'], true ) : array();
		$history = is_array( $history ) ? $history : array();
		$history[] = array( 'status' => $fields['status'], 'by' => absint( $actor ), 'at' => $now, 'reason' => (string) ( $fields['reason'] ?? '' ) );
		$fields['history']    = wp_json_encode( array_slice( $history, -30 ) );
		$fields['updated_at'] = $now;
		if ( $row ) {
			$wpdb->update( $table, $fields, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array_merge(
					array( 'application_id' => absint( $app_id ), 'event_id' => (int) get_post_meta( $app_id, '_lnkup_event_id', true ), 'requirement_type' => $type, 'created_at' => $now ),
					$fields
				)
			);
		}
		do_action( 'lnkup_organizer_document_reviewed', absint( $app_id ), $type, $fields['status'] );
	}

	/**
	 * Ask the vendor for specific document types (one request per type per day).
	 *
	 * @param int    $app_id  Application.
	 * @param array  $types   Requirement types.
	 * @param string $message Optional note.
	 * @param int    $actor   Organizer or team member with review_documents.
	 * @return array|WP_Error Updated requirement rows.
	 */
	public static function request( $app_id, array $types, $message, $actor ) {
		$app_id  = absint( $app_id );
		$message = mb_substr( trim( sanitize_textarea_field( (string) $message ) ), 0, 1000 );
		$asked   = array();
		foreach ( array_unique( $types ) as $raw_type ) {
			$type = self::assert_required( $app_id, $raw_type );
			if ( is_wp_error( $type ) ) {
				return $type;
			}
			$throttle = 'lnkup_docreq_' . $app_id . '_' . $type;
			if ( get_transient( $throttle ) ) {
				continue;
			}
			set_transient( $throttle, 1, self::REQUEST_THROTTLE );
			self::upsert( $app_id, $type, array( 'status' => 'requested', 'message' => $message, 'requested_at' => current_time( 'mysql' ), 'reason' => '' ), $actor );
			$asked[] = $type;
		}
		if ( ! $asked ) {
			return new WP_Error( 'lnkup_document_request_throttled', __( 'You already asked for these documents today.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$labels = LNKup_Documents::document_types();
		self::notify_vendor(
			$app_id,
			/* translators: %s: event name */
			__( 'Documents needed for %s', 'lnkup-marketplace' ),
			sprintf(
				/* translators: 1: event, 2: document list */
				__( 'The organizer of %1$s needs these documents from you: %2$s. Upload them and share them with the organizer from your documents page.', 'lnkup-marketplace' ),
				lnkup_plain_title( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) ),
				implode( ', ', array_map( static function ( $t ) use ( $labels ) { return $labels[ $t ] ?? $t; }, $asked ) )
			),
			$message
		);
		LNKup_Application_Review::audit( 'organizer_document_requested', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $actor, array( 'application_id' => $app_id, 'types' => $asked ) );
		do_action( 'lnkup_organizer_document_requested', $app_id, $asked, $message, absint( $actor ) );
		return self::requirements_for_application( $app_id, $actor );
	}

	/**
	 * Accept, reject or ask for a replacement of the document the vendor shared.
	 *
	 * @param int    $app_id   Application.
	 * @param string $type     Requirement type.
	 * @param string $decision accept|reject|replacement.
	 * @param string $reason   Required for reject and replacement.
	 * @param int    $actor    Reviewer.
	 * @return array|WP_Error Updated rows.
	 */
	public static function review( $app_id, $type, $decision, $reason, $actor ) {
		$app_id = absint( $app_id );
		$type   = self::assert_required( $app_id, $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$map = array( 'accept' => 'accepted', 'reject' => 'rejected', 'replacement' => 'replacement_requested' );
		if ( ! isset( $map[ $decision ] ) ) {
			return new WP_Error( 'lnkup_document_decision', __( 'Choose accept, reject or ask for a replacement.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$reason = mb_substr( trim( sanitize_textarea_field( (string) $reason ) ), 0, 1000 );
		if ( 'accept' !== $decision && mb_strlen( $reason ) < 3 ) {
			return new WP_Error( 'lnkup_document_reason', __( 'Tell the vendor what needs to change.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$current = null;
		foreach ( self::requirements_for_application( $app_id ) as $row ) {
			if ( $row['type'] === $type ) {
				$current = $row;
			}
		}
		if ( ! $current || ! $current['document_id'] ) {
			return new WP_Error( 'lnkup_document_missing', __( 'The vendor has not provided this document yet. Request it first.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		if ( 'accept' === $decision && 'expired' === $current['status'] ) {
			return new WP_Error( 'lnkup_document_expired', __( 'This document has expired. Ask the vendor for a current one.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		self::upsert(
			$app_id,
			$type,
			array( 'status' => $map[ $decision ], 'reason' => 'accept' === $decision ? '' : $reason, 'document_id' => (int) $current['document_id'], 'reviewed_by' => absint( $actor ), 'reviewed_at' => current_time( 'mysql' ) ),
			$actor
		);
		if ( 'accept' !== $decision ) {
			self::notify_vendor(
				$app_id,
				/* translators: %s: event name */
				__( 'Document update needed for %s', 'lnkup-marketplace' ),
				sprintf(
					/* translators: 1: document, 2: event */
					__( 'The organizer reviewed your %1$s for %2$s and needs a new version.', 'lnkup-marketplace' ),
					$current['label'],
					lnkup_plain_title( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) )
				),
				$reason
			);
		}
		LNKup_Application_Review::audit( 'organizer_document_' . $map[ $decision ], (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $actor, array( 'application_id' => $app_id, 'type' => $type, 'document_id' => (int) $current['document_id'] ) );
		return self::requirements_for_application( $app_id, $actor );
	}

	/**
	 * Waive a requirement for one application (owner or organization admin only).
	 *
	 * @param int    $app_id Application.
	 * @param string $type   Type.
	 * @param string $reason Why.
	 * @param int    $actor  Actor.
	 * @return array|WP_Error
	 */
	public static function waive( $app_id, $type, $reason, $actor ) {
		$app_id = absint( $app_id );
		if ( ! self::can_waive( $app_id, $actor ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'Only the event owner or an organization admin can waive a requirement.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$type = self::assert_required( $app_id, $type );
		if ( is_wp_error( $type ) ) {
			return $type;
		}
		$reason = mb_substr( trim( sanitize_textarea_field( (string) $reason ) ), 0, 1000 );
		if ( mb_strlen( $reason ) < 3 ) {
			return new WP_Error( 'lnkup_document_reason', __( 'Add a short reason for waiving this requirement.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		self::upsert( $app_id, $type, array( 'status' => 'waived', 'reason' => $reason, 'reviewed_by' => absint( $actor ), 'reviewed_at' => current_time( 'mysql' ) ), $actor );
		LNKup_Application_Review::audit( 'organizer_document_waived', (int) get_post_meta( $app_id, '_lnkup_event_id', true ), $actor, array( 'application_id' => $app_id, 'type' => $type, 'reason' => $reason ) );
		return self::requirements_for_application( $app_id, $actor );
	}

	/**
	 * @param int $app_id Application.
	 * @param int $actor  User.
	 */
	public static function can_waive( $app_id, $actor ) {
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( LNKup_Capabilities::organizer_owns_event( $event_id, $actor ) ) {
			return true;
		}
		$owner = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		return class_exists( 'LNKup_Org_Teams' ) && LNKup_Org_Teams::can_org( $actor, $owner, 'manage_team' );
	}

	/**
	 * Counts by status for an event (Overview, readiness evidence).
	 *
	 * @param int $event_id Event.
	 */
	public static function counts_for_event( $event_id ) {
		$out = array( 'missing' => 0, 'requested' => 0, 'submitted' => 0, 'accepted' => 0, 'rejected' => 0, 'replacement_requested' => 0, 'expired' => 0, 'waived' => 0 );
		foreach ( self::requirements_for_event( $event_id ) as $row ) {
			$out[ $row['status'] ] = ( $out[ $row['status'] ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * @param int    $app_id  Application.
	 * @param string $subject Subject with %s for the event.
	 * @param string $body    Body text.
	 * @param string $note    Organizer note.
	 */
	private static function notify_vendor( $app_id, $subject, $body, $note ) {
		$vendor = get_userdata( (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) );
		if ( ! $vendor || ! class_exists( 'LNKup_Notifications' ) ) {
			return;
		}
		$html = wpautop( esc_html( $body ) );
		if ( '' !== $note ) {
			$html .= '<blockquote>' . esc_html( $note ) . '</blockquote>';
		}
		$html .= '<p><a href="' . esc_url( add_query_arg( 'view', 'documents', lnkup_page_url_helper( 'vendor-dashboard' ) ) ) . '">' . esc_html__( 'Open my documents', 'lnkup-marketplace' ) . '</a></p>';
		LNKup_Notifications::send( $vendor->user_email, sprintf( $subject, lnkup_plain_title( (int) get_post_meta( $app_id, '_lnkup_event_id', true ) ) ), $html );
	}
}
