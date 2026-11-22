<?php
/**
 * Affiliate and strategic partner applications.
 *
 * An application is a pending partner-account row in lnkup_connectors with program
 * `affiliate` or `strategic_partner`. Approval reuses LNKup_Connector::approve(), so an
 * approved applicant gets the same code, attribution, ledger and dashboard as a City Connector.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Applications {

	const HOURLY_LIMIT = 5;
	const HONEYPOT     = 'company_fax';

	/**
	 * Status => label. Values are shared with City Connector rows.
	 */
	const STATUSES = array(
		'pending'      => 'Submitted',
		'under_review' => 'Under review',
		'info_needed'  => 'Additional information needed',
		'active'       => 'Approved',
		'rejected'     => 'Declined',
		'paused'       => 'Suspended',
		'closed'       => 'Closed',
	);

	/**
	 * Action => array( allowed from-statuses, to-status ).
	 */
	const TRANSITIONS = array(
		'review'       => array( array( 'pending', 'info_needed' ), 'under_review' ),
		'request-info' => array( array( 'pending', 'under_review' ), 'info_needed' ),
		'approve'      => array( array( 'pending', 'under_review', 'info_needed', 'paused' ), 'active' ),
		'decline'      => array( array( 'pending', 'under_review', 'info_needed' ), 'rejected' ),
		'suspend'      => array( array( 'active' ), 'paused' ),
		'close'        => array( array( 'pending', 'under_review', 'info_needed', 'active', 'rejected', 'paused' ), 'closed' ),
	);

	public static function table(): string {
		return LNKup_Connector_Tables::connectors_table();
	}

	// ------------------------------------------------------------------ Submit.

	/**
	 * Public application. Duplicate emails, honeypot hits and rate limits never reveal details.
	 *
	 * @param array<string, mixed> $data Raw form fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function submit( string $program, array $data, string $ip = '' ) {
		$program = sanitize_key( $program );
		if ( ! LNKup_Partner_Programs::accepts_applications( $program ) ) {
			return new WP_Error( 'lnkup_apply_program', __( 'This partner program is not accepting applications right now.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$received = array(
			'success' => true,
			'status'  => 'received',
			'message' => __( 'Thanks! We received your application. LNKup reviews every application; approval is not guaranteed.', 'lnkup-marketplace' ),
		);
		if ( '' !== trim( (string) ( $data[ self::HONEYPOT ] ?? '' ) ) ) {
			return $received;
		}
		if ( self::rate_limited( $ip ) ) {
			return new WP_Error( 'lnkup_apply_rate_limited', __( 'Too many applications from this connection. Please try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}

		$name         = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		$email        = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$organization = trim( sanitize_text_field( (string) ( $data['organization'] ?? '' ) ) );
		$website      = esc_url_raw( self::with_scheme( (string) ( $data['website'] ?? '' ) ) );
		$city         = trim( sanitize_text_field( (string) ( $data['city'] ?? '' ) ) );
		$state        = strtoupper( substr( trim( sanitize_text_field( (string) ( $data['state'] ?? '' ) ) ), 0, 8 ) );
		$errors       = array();
		if ( '' === $name ) {
			$errors[] = __( 'your name', 'lnkup-marketplace' );
		}
		if ( ! $email || ! is_email( $email ) ) {
			$errors[] = __( 'a valid business email', 'lnkup-marketplace' );
		}
		if ( '' === $city ) {
			$errors[] = __( 'your city', 'lnkup-marketplace' );
		}
		if ( LNKup_Partner_Programs::STRATEGIC_PARTNER === $program && '' === $organization ) {
			$errors[] = __( 'your organization', 'lnkup-marketplace' );
		}
		if ( empty( $data['disclosures'] ) ) {
			$errors[] = __( 'agreement to the program terms and disclosures', 'lnkup-marketplace' );
		}
		if ( $errors ) {
			/* translators: %s: comma-separated missing fields */
			return new WP_Error( 'lnkup_apply_invalid', sprintf( __( 'Please add %s.', 'lnkup-marketplace' ), implode( ', ', $errors ) ), array( 'status' => 400 ) );
		}
		self::count_attempt( $ip );

		if ( self::existing_for_email( $email ) ) {
			return array_merge( $received, array( 'message' => __( 'We already have an application or partner account for this email. We will be in touch.', 'lnkup-marketplace' ) ) );
		}

		$partner_id = 0;
		if ( LNKup_Partner_Programs::STRATEGIC_PARTNER === $program ) {
			$partner_id = LNKup_Partner_Organizations::find_or_create(
				array(
					'name'          => $organization,
					'website'       => $website,
					'category'      => (string) ( $data['category'] ?? '' ),
					'contact_name'  => $name,
					'contact_email' => $email,
					'cities_served' => (string) ( $data['cities_served'] ?? $city ),
				)
			);
		}

		global $wpdb;
		$now         = current_time( 'mysql' );
		$application = array(
			'experience'            => sanitize_textarea_field( (string) ( $data['experience'] ?? '' ) ),
			'audience'              => sanitize_textarea_field( (string) ( $data['audience'] ?? '' ) ),
			'preferred_program'     => $program,
			'category'              => sanitize_key( (string) ( $data['category'] ?? '' ) ),
			'cities_served'         => sanitize_text_field( (string) ( $data['cities_served'] ?? '' ) ),
			'disclosures_accepted'  => $now,
		);
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'program'       => $program,
				'display_name'  => $name,
				'email'         => $email,
				'phone'         => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
				'city'          => $city,
				'state'         => $state,
				'organization'  => $organization,
				'website'       => $website,
				'social_url'    => $website,
				'partner_id'    => $partner_id,
				'interest_note' => $application['experience'],
				'application'   => wp_json_encode( $application ),
				'status'        => 'pending',
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
		if ( ! $inserted ) {
			return new WP_Error( 'lnkup_apply_failed', __( 'We could not save your application. Please try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'partner_application_submitted', 'partner_application', $id, array( 'program' => $program, 'partner_id' => $partner_id, 'actor_user_id' => 0 ) );
		do_action( 'lnkup_partner_application_submitted', $id, $program );
		return array_merge( $received, array( 'id' => $id ) );
	}

	private static function with_scheme( string $url ): string {
		$url = trim( $url );
		return ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) ? 'https://' . $url : $url;
	}

	private static function existing_for_email( string $email ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE email = %s AND status IN ('pending','under_review','info_needed','active','paused') LIMIT 1", $email ) );
	}

	private static function rate_key( string $ip ): string {
		return 'lnkup_papply_' . substr( hash_hmac( 'sha256', $ip ?: 'unknown', wp_salt( 'nonce' ) ), 0, 32 );
	}

	private static function rate_limited( string $ip ): bool {
		return (int) get_transient( self::rate_key( $ip ) ) >= self::HOURLY_LIMIT;
	}

	private static function count_attempt( string $ip ): void {
		$key = self::rate_key( $ip );
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}

	// ------------------------------------------------------------------ Review.

	/**
	 * Apply an admin action. Status moves are conditional so two admins can't both act.
	 *
	 * @return array<string, mixed>|WP_Error Updated application.
	 */
	public static function transition( int $id, string $action, int $actor = 0, string $note = '' ) {
		global $wpdb;
		$action = sanitize_key( $action );
		if ( ! isset( self::TRANSITIONS[ $action ] ) ) {
			return new WP_Error( 'lnkup_application_action', __( 'Unknown application action.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$row = LNKup_Connector::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'lnkup_application_missing', __( 'Application not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		list( $from, $to ) = self::TRANSITIONS[ $action ];
		$status = (string) $row['status'];
		if ( ! in_array( $status, $from, true ) ) {
			return new WP_Error( 'lnkup_application_state', __( 'This application cannot take that action in its current status. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$actor = $actor ?: get_current_user_id();
		$note  = sanitize_textarea_field( $note );
		$now   = current_time( 'mysql' );

		if ( 'approve' === $action ) {
			$approved = LNKup_Connector::approve( $id, $actor );
			if ( is_wp_error( $approved ) ) {
				return $approved;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s', $to, $now, $id, $status ) );
			if ( 1 !== (int) $updated ) {
				return new WP_Error( 'lnkup_application_state', __( 'This application changed before your action was saved. Refresh and try again.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
			}
		}
		$set = array( 'reviewed_by' => $actor, 'reviewed_at' => $now );
		if ( '' !== $note ) {
			$set['admin_notes'] = trim( (string) $row['admin_notes'] . "\n[" . $now . '] ' . $action . ': ' . $note );
		}
		$wpdb->update( self::table(), $set, array( 'id' => $id ) );

		if ( 'request-info' === $action && class_exists( 'LNKup_Notifications' ) ) {
			LNKup_Notifications::send(
				(string) $row['email'],
				__( 'LNKup partner application: more information needed', 'lnkup-marketplace' ),
				'<p>' . esc_html( sprintf( /* translators: %s name */ __( 'Hi %s,', 'lnkup-marketplace' ), (string) $row['display_name'] ) ) . '</p>'
				. '<p>' . esc_html__( 'Thanks for applying to partner with LNKup. Before we can finish reviewing your application we need a little more information:', 'lnkup-marketplace' ) . '</p>'
				. '<p>' . nl2br( esc_html( $note ?: __( 'Please reply to this email with more detail about your organizer relationships.', 'lnkup-marketplace' ) ) ) . '</p>'
				. '<p>' . esc_html__( 'Reply to this email and our partnerships team will pick it up.', 'lnkup-marketplace' ) . '</p>'
			);
		}
		if ( in_array( $action, array( 'suspend', 'close' ), true ) && (int) $row['user_id'] ) {
			// Revoked accounts stop resolving codes immediately (resolve_code requires an active account).
			$wpdb->update( LNKup_Treasury_Tables::referral_codes_table(), array( 'is_active' => 0 ), array( 'connector_id' => $id ) );
		}
		if ( 'approve' === $action ) {
			$wpdb->update( LNKup_Treasury_Tables::referral_codes_table(), array( 'is_active' => 1 ), array( 'connector_id' => $id ) );
		}
		LNKup_Financial_Audit::log( 'partner_application_' . str_replace( '-', '_', $action ), 'partner_application', $id, array( 'from' => $status, 'to' => $to, 'program' => LNKup_Connector::program_of( $row ), 'note' => $note ) );
		do_action( 'lnkup_partner_application_transitioned', $id, $action, $status, $to );
		return self::present( (array) LNKup_Connector::get( $id ) );
	}

	// ------------------------------------------------------------------ Read.

	/**
	 * @param array<string, mixed> $args program, status, page, per_page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$per   = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where = array( '1=1' );
		$vals  = array();
		$program = sanitize_key( (string) ( $args['program'] ?? '' ) );
		if ( $program && LNKup_Partner_Programs::is_account_program( $program ) ) {
			$where[] = 'program = %s';
			$vals[]  = $program;
		} else {
			$where[] = "program IN ('affiliate','strategic_partner')";
		}
		$status = sanitize_key( (string) ( $args['status'] ?? '' ) );
		if ( $status && isset( self::STATUSES[ $status ] ) ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		$sql = ' FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*)' . $sql, $vals ) ) : $wpdb->get_var( 'SELECT COUNT(*)' . $sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT *' . $sql . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d', array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array( 'items' => array_map( array( __CLASS__, 'present' ), (array) $rows ), 'total' => $total, 'page' => $page, 'per_page' => $per );
	}

	public static function count_pending(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE program IN ('affiliate','strategic_partner') AND status IN ('pending','under_review','info_needed')" );
	}

	/**
	 * @param array<string, mixed> $row Connector row.
	 * @return array<string, mixed>
	 */
	public static function present( array $row ): array {
		$program = LNKup_Connector::program_of( $row );
		$status  = (string) ( $row['status'] ?? '' );
		$code    = (int) ( $row['id'] ?? 0 ) ? LNKup_Connector::get_code_for_connector( (int) $row['id'] ) : null;
		$actions = array();
		foreach ( self::TRANSITIONS as $action => $def ) {
			if ( in_array( $status, $def[0], true ) ) {
				$actions[] = $action;
			}
		}
		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'program'       => $program,
			'program_label' => LNKup_Partner_Programs::label( $program ),
			'name'          => (string) ( $row['display_name'] ?? '' ),
			'email'         => (string) ( $row['email'] ?? '' ),
			'organization'  => (string) ( $row['organization'] ?? '' ),
			'website'       => (string) ( $row['website'] ?? '' ),
			'city'          => (string) ( $row['city'] ?? '' ),
			'state'         => (string) ( $row['state'] ?? '' ),
			'partner_id'    => (int) ( $row['partner_id'] ?? 0 ),
			'status'        => $status,
			'status_label'  => self::STATUSES[ $status ] ?? ucfirst( $status ),
			'application'   => json_decode( (string) ( $row['application'] ?? '' ), true ) ?: array(),
			'admin_notes'   => (string) ( $row['admin_notes'] ?? '' ),
			'code'          => (string) ( $code['code'] ?? '' ),
			'actions'       => $actions,
			'created_at'    => (string) ( $row['created_at'] ?? '' ),
			'reviewed_at'   => (string) ( $row['reviewed_at'] ?? '' ),
		);
	}
}
