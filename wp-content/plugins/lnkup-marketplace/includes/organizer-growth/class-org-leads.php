<?php
/**
 * Organizer leads (organizations) — CRUD, query, activity, tags, export.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Leads {

	/** Editable columns and their sanitizers. Stage/outcome have dedicated methods. */
	const FIELDS = array(
		'name'                  => 'text',
		'org_type'              => 'org_type',
		'parent_lead_id'        => 'int',
		'partner_id'            => 'int',
		'category'              => 'text',
		'website'               => 'url',
		'email'                 => 'email',
		'phone'                 => 'text',
		'instagram'             => 'text',
		'facebook'              => 'url',
		'socials'               => 'json',
		'city'                  => 'text',
		'state'                 => 'text',
		'service_area'          => 'text',
		'description'           => 'textarea',
		'event_frequency'       => 'frequency',
		'cities_served'         => 'text',
		'event_categories'      => 'text',
		'estimated_booths'      => 'int',
		'application_process'   => 'text',
		'next_event_date'       => 'date',
		'application_url'       => 'url',
		'source_verified_at'    => 'datetime',
		'source'                => 'key',
		'source_campaign'       => 'text',
		'source_detail'         => 'text',
		'referral_code'         => 'text',
		'referrer_user_id'      => 'int',
		'assigned_user_id'      => 'int',
		'assigned_connector_id' => 'int',
		'priority'              => 'priority',
		'next_follow_up_at'     => 'datetime',
		'contact_preference'    => 'key',
		'qualification_notes'   => 'textarea',
		'objections'            => 'textarea',
	);

	const ORG_TYPES   = array( 'single_event', 'event_series', 'organizer_org', 'multi_brand' );
	const FREQUENCIES = array( '', 'one_off', 'seasonal', 'annual', 'quarterly', 'monthly', 'weekly', 'recurring' );
	const SORTABLE    = array( 'score', 'name', 'updated_at', 'created_at', 'next_follow_up_at', 'next_event_date', 'last_contacted_at', 'city' );

	public static function table(): string {
		return LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::LEADS );
	}

	// ------------------------------------------------------------------ Sanitizing.

	/**
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Column => value for the keys present in $input.
	 */
	public static function sanitize( array $input ): array {
		$out = array();
		foreach ( self::FIELDS as $field => $type ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$out[ $field ] = self::sanitize_value( $type, $input[ $field ] );
		}
		if ( isset( $out['name'] ) ) {
			$out['name'] = substr( (string) $out['name'], 0, 191 );
		}
		if ( isset( $out['state'] ) ) {
			$out['state'] = strtoupper( substr( (string) $out['state'], 0, 32 ) );
		}
		if ( class_exists( 'LNKup_Location_Service' ) && ( ! empty( $out['city'] ) || ! empty( $out['state'] ) ) ) {
			$location = LNKup_Location_Service::canonical_profile_location( (string) ( $out['city'] ?? '' ), (string) ( $out['state'] ?? '' ), '', 'lenient' );
			if ( is_array( $location ) ) {
				if ( isset( $out['city'] ) ) {
					$out['city'] = $location['city'];
				}
				if ( isset( $out['state'] ) ) {
					$out['state'] = $location['state'];
				}
			}
		}
		return $out;
	}

	/**
	 * @param string $type  Sanitizer.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_value( string $type, $value ) {
		$scalar = is_scalar( $value ) ? (string) $value : '';
		switch ( $type ) {
			case 'int':
				return max( 0, (int) $value );
			case 'email':
				return LNKup_Org_Lead_Dedupe::normalize_email( sanitize_email( $scalar ) );
			case 'url':
				$scalar = trim( $scalar );
				if ( '' !== $scalar && ! preg_match( '#^https?://#i', $scalar ) ) {
					$scalar = 'https://' . $scalar;
				}
				return substr( esc_url_raw( $scalar ), 0, 255 );
			case 'textarea':
				return sanitize_textarea_field( $scalar );
			case 'key':
				return sanitize_key( $scalar );
			case 'date':
				$ts = '' !== $scalar ? strtotime( $scalar ) : false;
				return $ts ? gmdate( 'Y-m-d', $ts ) : null;
			case 'datetime':
				$ts = '' !== $scalar ? strtotime( $scalar ) : false;
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
			case 'json':
				$list = is_array( $value ) ? $value : ( '' !== $scalar ? array_map( 'trim', explode( ',', $scalar ) ) : array() );
				$list = array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $list ) ) ) );
				return $list ? wp_json_encode( $list ) : null;
			case 'org_type':
				$scalar = sanitize_key( $scalar );
				return in_array( $scalar, self::ORG_TYPES, true ) ? $scalar : 'organizer_org';
			case 'frequency':
				$scalar = sanitize_key( $scalar );
				return in_array( $scalar, self::FREQUENCIES, true ) ? $scalar : '';
			case 'priority':
				$scalar = strtoupper( substr( sanitize_text_field( $scalar ), 0, 1 ) );
				return in_array( $scalar, array( 'A', 'B', 'C' ), true ) ? $scalar : '';
			default:
				return sanitize_text_field( $scalar );
		}
	}

	// ------------------------------------------------------------------ Create / update.

	/**
	 * @param array<string, mixed> $input Raw lead fields.
	 * @param array<string, mixed> $opts  actor, import_id, allow_duplicate, stage, tags.
	 * @return int|WP_Error Lead ID.
	 */
	public static function create( array $input, array $opts = array() ) {
		global $wpdb;
		$data = self::sanitize( $input );
		if ( '' === trim( (string) ( $data['name'] ?? '' ) ) ) {
			return new WP_Error( 'lnkup_lead_name_required', __( 'Organizer or business name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$identity = LNKup_Org_Lead_Dedupe::identity( $data );
		$matches  = LNKup_Org_Lead_Dedupe::find_matches( $identity, (string) ( $data['state'] ?? '' ) );
		$hard     = LNKup_Org_Lead_Dedupe::first_hard( $matches );
		if ( $hard && empty( $opts['allow_duplicate'] ) ) {
			return new WP_Error(
				'lnkup_lead_duplicate',
				__( 'A lead with the same email, website, Instagram or account already exists.', 'lnkup-marketplace' ),
				array(
					'status'       => 409,
					'duplicate_of' => $hard['lead_id'],
					'reason'       => $hard['reason'],
				)
			);
		}
		$soft = $matches ? $matches[0]['lead_id'] : 0;

		$stage = sanitize_key( (string) ( $opts['stage'] ?? '' ) );
		if ( ! LNKup_Org_Lead_Pipeline::is_stage( $stage ) || in_array( $stage, LNKup_Org_Lead_Pipeline::MILESTONE_STAGES, true ) || in_array( $stage, LNKup_Org_Lead_Pipeline::ACCOUNT_STAGES, true ) ) {
			$stage = self::has_contact( $data + $identity ) ? 'discovered' : 'research_needed';
		}

		$now    = LNKup_Org_Growth::now();
		$actor  = (int) ( $opts['actor'] ?? get_current_user_id() );
		$row    = array_merge(
			$data,
			$identity,
			array(
				'stage'                 => $stage,
				'possible_duplicate_of' => $hard ? $hard['lead_id'] : $soft,
				'import_id'             => (int) ( $opts['import_id'] ?? 0 ),
				'created_by'            => $actor,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'lnkup_lead_save_failed', __( 'The lead could not be saved.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$lead_id = (int) $wpdb->insert_id;
		if ( ! empty( $opts['tags'] ) ) {
			self::set_tags( $lead_id, (array) $opts['tags'] );
		}
		self::log_activity(
			$lead_id,
			'created',
			array(
				'actor'   => $actor,
				'summary' => ! empty( $opts['import_id'] ) ? __( 'Imported from CSV', 'lnkup-marketplace' ) : __( 'Lead created', 'lnkup-marketplace' ),
				'payload' => array(
					'source'    => (string) ( $data['source'] ?? '' ),
					'import_id' => (int) ( $opts['import_id'] ?? 0 ),
				),
			)
		);
		LNKup_Org_Lead_Scoring::score_lead( $lead_id );
		LNKup_Org_Lead_Lifecycle::match_existing_account( $lead_id );
		LNKup_Org_Growth_Metrics::flush_cache();
		return $lead_id;
	}

	/**
	 * @param array<string, mixed> $data Lead columns.
	 */
	private static function has_contact( array $data ): bool {
		return '' !== (string) ( $data['email'] ?? '' ) || '' !== (string) ( $data['phone'] ?? '' ) || '' !== (string) ( $data['instagram'] ?? '' ) || '' !== (string) ( $data['website'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $input Raw fields.
	 * @return array<string, mixed>|WP_Error Formatted lead.
	 */
	public static function update( int $lead_id, array $input, int $actor ) {
		global $wpdb;
		$lead = self::get_row( $lead_id );
		if ( ! $lead ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$data = self::sanitize( $input );
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error( 'lnkup_lead_name_required', __( 'Organizer or business name is required.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['parent_lead_id'] ) && (int) $data['parent_lead_id'] === $lead_id ) {
			$data['parent_lead_id'] = 0;
		}
		$merged   = array_merge( $lead, $data );
		$identity = LNKup_Org_Lead_Dedupe::identity( $merged );
		if ( array_intersect( array_keys( $data ), array( 'email', 'website', 'instagram' ) ) ) {
			$hard = LNKup_Org_Lead_Dedupe::first_hard( LNKup_Org_Lead_Dedupe::find_matches( $identity, (string) $merged['state'], $lead_id ) );
			if ( $hard && 'similar_name' !== $hard['reason'] ) {
				return new WP_Error(
					'lnkup_lead_duplicate',
					__( 'Another lead already uses this email, website or Instagram. Merge the two instead.', 'lnkup-marketplace' ),
					array(
						'status'       => 409,
						'duplicate_of' => $hard['lead_id'],
						'reason'       => $hard['reason'],
					)
				);
			}
		}
		$changes = array();
		foreach ( $data as $col => $value ) {
			if ( (string) ( $lead[ $col ] ?? '' ) !== (string) ( $value ?? '' ) ) {
				$changes[ $col ] = array(
					'from' => $lead[ $col ] ?? null,
					'to'   => $value,
				);
			}
		}
		if ( ! $changes ) {
			return self::format( $lead );
		}
		$row               = array_merge( $data, $identity );
		$row['updated_at'] = LNKup_Org_Growth::now();
		$wpdb->update( self::table(), $row, array( 'id' => $lead_id ) );

		foreach ( array( 'assigned_user_id', 'assigned_connector_id' ) as $col ) {
			if ( isset( $changes[ $col ] ) ) {
				self::log_activity(
					$lead_id,
					'assigned',
					array(
						'actor'   => $actor,
						'summary' => self::assignment_summary( $col, (int) $changes[ $col ]['to'] ),
						'payload' => array( $col => $changes[ $col ] ),
					)
				);
				unset( $changes[ $col ] );
			}
		}
		if ( $changes ) {
			self::log_activity(
				$lead_id,
				'updated',
				array(
					'actor'   => $actor,
					'summary' => sprintf( /* translators: %s: field list */ __( 'Updated %s', 'lnkup-marketplace' ), implode( ', ', array_keys( $changes ) ) ),
					'payload' => array( 'fields' => array_keys( $changes ) ),
				)
			);
		}
		LNKup_Org_Lead_Scoring::score_lead( $lead_id );
		LNKup_Org_Growth_Metrics::flush_cache();
		return self::format( (array) self::get_row( $lead_id ) );
	}

	private static function assignment_summary( string $col, int $to ): string {
		if ( ! $to ) {
			return 'assigned_user_id' === $col ? __( 'Owner removed', 'lnkup-marketplace' ) : __( 'City Connector removed', 'lnkup-marketplace' );
		}
		if ( 'assigned_user_id' === $col ) {
			$user = get_userdata( $to );
			/* translators: %s: teammate name */
			return sprintf( __( 'Assigned to %s', 'lnkup-marketplace' ), $user ? $user->display_name : '#' . $to );
		}
		$name = '#' . $to;
		foreach ( LNKup_Org_Growth::connectors() as $connector ) {
			if ( $connector['id'] === $to ) {
				$name = $connector['name'];
			}
		}
		/* translators: %s: connector name */
		return sprintf( __( 'City Connector set to %s', 'lnkup-marketplace' ), $name );
	}

	/**
	 * Manual stage move (validated) or outcome.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function move( int $lead_id, string $stage, string $outcome, int $actor, string $note = '' ) {
		global $wpdb;
		$lead = self::get_row( $lead_id );
		if ( ! $lead ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( (int) $lead['merged_into_id'] ) {
			return new WP_Error( 'lnkup_lead_merged', __( 'This lead was merged into another record.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$update = array( 'updated_at' => LNKup_Org_Growth::now() );
		if ( '' !== $outcome ) {
			if ( 'clear' === $outcome ) {
				$update['outcome'] = '';
			} elseif ( ! LNKup_Org_Lead_Pipeline::is_outcome( $outcome ) ) {
				return new WP_Error( 'lnkup_invalid_outcome', __( 'Unknown outcome.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			} else {
				$update['outcome'] = $outcome;
			}
		}
		if ( '' !== $stage ) {
			$valid = LNKup_Org_Lead_Pipeline::validate_manual_move( $lead, $stage );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$update['stage'] = $stage;
			if ( ! isset( $update['outcome'] ) ) {
				$update['outcome'] = '';
			}
		}
		$wpdb->update( self::table(), $update, array( 'id' => $lead_id ) );
		if ( isset( $update['stage'] ) && $update['stage'] !== $lead['stage'] ) {
			self::log_activity(
				$lead_id,
				'stage_changed',
				array(
					'actor'   => $actor,
					'summary' => sprintf( '%s → %s', LNKup_Org_Lead_Pipeline::STAGES[ $lead['stage'] ] ?? $lead['stage'], LNKup_Org_Lead_Pipeline::STAGES[ $stage ] ),
					'payload' => array(
						'from' => $lead['stage'],
						'to'   => $stage,
						'note' => sanitize_textarea_field( $note ),
					),
				)
			);
		}
		if ( isset( $update['outcome'] ) && $update['outcome'] !== $lead['outcome'] ) {
			$label = '' === $update['outcome'] ? __( 'Reopened', 'lnkup-marketplace' ) : LNKup_Org_Lead_Pipeline::OUTCOMES[ $update['outcome'] ];
			self::log_activity(
				$lead_id,
				'outcome_set',
				array(
					'actor'   => $actor,
					'summary' => $label,
					'payload' => array(
						'outcome' => $update['outcome'],
						'note'    => sanitize_textarea_field( $note ),
					),
				)
			);
			if ( 'do_not_contact' === $update['outcome'] ) {
				LNKup_Contact_Suppression::suppress_lead( $lead_id, 'do_not_contact', $actor );
			}
			if ( '' !== $note && in_array( $update['outcome'], array( 'not_interested', 'unqualified' ), true ) ) {
				$objections = trim( (string) $lead['objections'] . "\n" . gmdate( 'Y-m-d' ) . ': ' . sanitize_textarea_field( $note ) );
				$wpdb->update( self::table(), array( 'objections' => $objections ), array( 'id' => $lead_id ) );
			}
		}
		LNKup_Org_Growth_Metrics::flush_cache();
		if ( isset( $update['stage'] ) && $update['stage'] !== $lead['stage'] ) {
			do_action( 'lnkup_org_lead_stage_changed', $lead_id, (string) $lead['stage'], (string) $update['stage'], false );
		}
		if ( isset( $update['outcome'] ) && $update['outcome'] !== $lead['outcome'] ) {
			do_action( 'lnkup_org_lead_outcome_changed', $lead_id, (string) $lead['outcome'], (string) $update['outcome'] );
		}
		return self::format( (array) self::get_row( $lead_id ) );
	}

	/**
	 * Milestone-driven forward move (no validation against milestone-only stages).
	 */
	public static function advance_to( int $lead_id, string $stage, string $reason ): void {
		global $wpdb;
		$lead = self::get_row( $lead_id );
		if ( ! $lead ) {
			return;
		}
		$next = LNKup_Org_Lead_Pipeline::rank( $stage ) > LNKup_Org_Lead_Pipeline::rank( (string) $lead['stage'] ) ? $stage : (string) $lead['stage'];
		$update = array( 'updated_at' => LNKup_Org_Growth::now() );
		if ( $next !== $lead['stage'] ) {
			$update['stage'] = $next;
		}
		if ( in_array( (string) $lead['outcome'], array( 'unresponsive', 'not_interested', 'archived' ), true ) ) {
			$update['outcome'] = '';
		}
		if ( count( $update ) === 1 ) {
			return;
		}
		$wpdb->update( self::table(), $update, array( 'id' => $lead_id ) );
		if ( isset( $update['stage'] ) ) {
			self::log_activity(
				$lead_id,
				'stage_changed',
				array(
					'actor'   => 0,
					'summary' => sprintf( '%s → %s', LNKup_Org_Lead_Pipeline::STAGES[ $lead['stage'] ] ?? $lead['stage'], LNKup_Org_Lead_Pipeline::STAGES[ $next ] ?? $next ),
					'payload' => array(
						'from'      => $lead['stage'],
						'to'        => $next,
						'automatic' => true,
						'reason'    => $reason,
					),
				)
			);
			do_action( 'lnkup_org_lead_stage_changed', $lead_id, (string) $lead['stage'], $next, true );
		}
	}

	/**
	 * Bulk update a set of leads.
	 *
	 * @param int[]  $ids    Lead IDs.
	 * @param string $action assign_user|assign_connector|stage|outcome|priority|add_tag|remove_tag.
	 * @param mixed  $value  Value.
	 * @return array{updated:int,errors:array<int,string>}
	 */
	public static function bulk( array $ids, string $action, $value, int $actor ): array {
		$ids     = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), 0, 500 );
		$updated = 0;
		$errors  = array();
		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'assign_user':
					$result = self::update( $id, array( 'assigned_user_id' => (int) $value ), $actor );
					break;
				case 'assign_connector':
					$result = self::update( $id, array( 'assigned_connector_id' => (int) $value ), $actor );
					break;
				case 'priority':
					$result = self::update( $id, array( 'priority' => (string) $value ), $actor );
					break;
				case 'stage':
					$result = self::move( $id, sanitize_key( (string) $value ), '', $actor );
					break;
				case 'outcome':
					$result = self::move( $id, '', sanitize_key( (string) $value ), $actor );
					break;
				case 'add_tag':
					$result = self::add_tags( $id, array( (string) $value ) );
					break;
				case 'remove_tag':
					$result = self::remove_tag( $id, (string) $value );
					break;
				default:
					return array(
						'updated' => 0,
						'errors'  => array( 0 => __( 'Unknown bulk action.', 'lnkup-marketplace' ) ),
					);
			}
			if ( is_wp_error( $result ) ) {
				$errors[ $id ] = $result->get_error_message();
				continue;
			}
			++$updated;
		}
		return array(
			'updated' => $updated,
			'errors'  => $errors,
		);
	}

	// ------------------------------------------------------------------ Reads.

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $lead_id ): ?array {
		global $wpdb;
		if ( $lead_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $lead_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @param array<string, mixed> $row Lead row.
	 * @return array<string, mixed>
	 */
	public static function format( array $row ): array {
		$id          = (int) $row['id'];
		$assignee    = ! empty( $row['assigned_user_id'] ) ? get_userdata( (int) $row['assigned_user_id'] ) : false;
		$explanation = json_decode( (string) ( $row['score_explanation'] ?? '' ), true );
		$socials     = json_decode( (string) ( $row['socials'] ?? '' ), true );
		$stale_days  = (int) LNKup_Org_Growth::setting( 'org_growth_contact_stale_days' );
		$verified    = (string) ( $row['source_verified_at'] ?? '' );
		return array(
			'id'                    => $id,
			'name'                  => (string) $row['name'],
			'org_type'              => (string) $row['org_type'],
			'parent_lead_id'        => (int) $row['parent_lead_id'],
			'partner_id'            => (int) $row['partner_id'],
			'category'              => (string) $row['category'],
			'website'               => (string) $row['website'],
			'domain'                => (string) $row['domain'],
			'email'                 => (string) $row['email'],
			'phone'                 => (string) $row['phone'],
			'instagram'             => (string) $row['instagram'],
			'facebook'              => (string) $row['facebook'],
			'socials'               => is_array( $socials ) ? $socials : array(),
			'city'                  => (string) $row['city'],
			'state'                 => (string) $row['state'],
			'service_area'          => (string) $row['service_area'],
			'description'           => (string) $row['description'],
			'event_frequency'       => (string) $row['event_frequency'],
			'cities_served'         => (string) $row['cities_served'],
			'event_categories'      => (string) $row['event_categories'],
			'estimated_booths'      => (int) $row['estimated_booths'],
			'application_process'   => (string) $row['application_process'],
			'next_event_date'       => (string) ( $row['next_event_date'] ?? '' ),
			'application_url'       => (string) $row['application_url'],
			'source_verified_at'    => $verified,
			'contact_is_stale'      => '' === $verified || ( $stale_days > 0 && strtotime( $verified ) < time() - $stale_days * DAY_IN_SECONDS ),
			'source'                => (string) $row['source'],
			'source_campaign'       => (string) $row['source_campaign'],
			'source_detail'         => (string) $row['source_detail'],
			'referral_code'         => (string) $row['referral_code'],
			'referrer_user_id'      => (int) $row['referrer_user_id'],
			'assigned_user_id'      => (int) $row['assigned_user_id'],
			'assigned_user_name'    => $assignee ? (string) $assignee->display_name : '',
			'assigned_connector_id' => (int) $row['assigned_connector_id'],
			'priority'              => (string) $row['priority'],
			'stage'                 => (string) $row['stage'],
			'stage_label'           => LNKup_Org_Lead_Pipeline::STAGES[ (string) $row['stage'] ] ?? (string) $row['stage'],
			'outcome'               => (string) $row['outcome'],
			'outcome_label'         => LNKup_Org_Lead_Pipeline::OUTCOMES[ (string) $row['outcome'] ] ?? '',
			'last_contacted_at'     => (string) ( $row['last_contacted_at'] ?? '' ),
			'next_follow_up_at'     => (string) ( $row['next_follow_up_at'] ?? '' ),
			'contact_preference'    => (string) $row['contact_preference'],
			'qualification_notes'   => (string) $row['qualification_notes'],
			'objections'            => (string) $row['objections'],
			'score'                 => (int) $row['score'],
			'tier'                  => (string) $row['tier'],
			'score_explanation'     => is_array( $explanation ) ? $explanation : array(),
			'is_house_affiliated'   => (bool) (int) $row['is_house_affiliated'],
			'possible_duplicate_of' => (int) $row['possible_duplicate_of'],
			'merged_into_id'        => (int) $row['merged_into_id'],
			'import_id'             => (int) $row['import_id'],
			'is_suppressed'         => LNKup_Contact_Suppression::is_lead_suppressed( $row ),
			'tags'                  => self::get_tags( $id ),
			'created_at'            => (string) $row['created_at'],
			'updated_at'            => (string) $row['updated_at'],
		);
	}

	/**
	 * Full lead detail for the drawer.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function detail( int $lead_id ): ?array {
		global $wpdb;
		$row = self::get_row( $lead_id );
		if ( ! $row ) {
			return null;
		}
		$lead = self::format( $row );
		$t    = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lead['events']     = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, url, event_date, city, state, category, verified_at FROM ' . $t( LNKup_Org_Growth_Tables::LEAD_EVENTS ) . ' WHERE lead_id = %d ORDER BY event_date IS NULL, event_date ASC LIMIT 50', $lead_id ), ARRAY_A );
		$lead['milestones'] = $wpdb->get_results( $wpdb->prepare( 'SELECT milestone, user_id, source_object_id, status, achieved_at FROM ' . $t( LNKup_Org_Growth_Tables::MILESTONES ) . ' WHERE lead_id = %d ORDER BY achieved_at ASC', $lead_id ), ARRAY_A );
		$accounts           = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, link_method, status, created_at FROM ' . $t( LNKup_Org_Growth_Tables::ACCOUNTS ) . ' WHERE lead_id = %d ORDER BY id ASC', $lead_id ), ARRAY_A );
		$children           = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, org_type, stage FROM ' . self::table() . ' WHERE parent_lead_id = %d AND merged_into_id = 0 ORDER BY name ASC LIMIT 100', $lead_id ), ARRAY_A );
		// phpcs:enable
		$lead['accounts'] = array();
		foreach ( (array) $accounts as $account ) {
			$user               = get_userdata( (int) $account['user_id'] );
			$activation         = class_exists( 'LNKup_Organizer_Activation' ) && $user ? LNKup_Organizer_Activation::for_user( (int) $account['user_id'] ) : null;
			$lead['accounts'][] = array(
				'user_id'     => (int) $account['user_id'],
				'name'        => $user ? (string) $user->display_name : '',
				'email'       => $user ? (string) $user->user_email : '',
				'link_method' => (string) $account['link_method'],
				'status'      => (string) $account['status'],
				'linked_at'   => (string) $account['created_at'],
				'activation'  => $activation ? array(
					'percent'       => (int) $activation['percent'],
					'current_stage' => (string) $activation['current_stage'],
					'stages'        => array_map(
						static fn( $s ) => array(
							'key'     => $s['key'],
							'title'   => $s['title'],
							'status'  => $s['status'],
							'missing' => $s['missing'],
						),
						$activation['stages']
					),
				) : null,
			);
		}
		$lead['brands']     = (array) $children;
		$lead['activity']   = self::activity( $lead_id, 100 );
		$lead['tasks']      = LNKup_Org_Lead_Tasks::for_lead( $lead_id );
		$lead['duplicates'] = LNKup_Org_Lead_Dedupe::find_matches( LNKup_Org_Lead_Dedupe::identity( $row ), (string) $row['state'], $lead_id );
		$lead['outreach']   = LNKup_Org_Outreach::history_for_lead( $lead_id );
		return $lead;
	}

	/**
	 * Server-side filtered, paginated query.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		list( $where, $values, $joins ) = self::build_where( $args );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$orderby  = in_array( (string) ( $args['orderby'] ?? '' ), self::SORTABLE, true ) ? (string) $args['orderby'] : 'score';
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$table    = self::table();
		$base     = "FROM {$table} l {$joins} WHERE " . implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(DISTINCT l.id) {$base}";
		$list_sql  = "SELECT DISTINCT l.* {$base} ORDER BY l.{$orderby} {$order}, l.id DESC LIMIT %d OFFSET %d";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $values, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items'    => array_map( array( __CLASS__, 'format' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * @param array<string, mixed> $args Filters.
	 * @return array{0:string[],1:array<int,mixed>,2:string}
	 */
	public static function build_where( array $args ): array {
		global $wpdb;
		$where  = array( 'l.merged_into_id = 0' );
		$values = array();
		$joins  = '';

		$outcome = (string) ( $args['outcome'] ?? '' );
		if ( '' === $outcome ) {
			$where[] = "l.outcome = ''";
		} elseif ( 'any' !== $outcome ) {
			$where[]  = 'l.outcome = %s';
			$values[] = sanitize_key( $outcome );
		}
		foreach ( array( 'stage', 'tier', 'state', 'category', 'source', 'org_type', 'priority' ) as $col ) {
			$val = (string) ( $args[ $col ] ?? '' );
			if ( '' === $val ) {
				continue;
			}
			$list = array_values( array_filter( array_map( 'sanitize_text_field', explode( ',', $val ) ) ) );
			if ( ! $list ) {
				continue;
			}
			$where[] = "l.{$col} IN (" . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
			$values  = array_merge( $values, 'state' === $col || 'tier' === $col || 'priority' === $col ? array_map( 'strtoupper', $list ) : $list );
		}
		if ( '' !== (string) ( $args['city'] ?? '' ) ) {
			$where[]  = 'l.city = %s';
			$values[] = sanitize_text_field( (string) $args['city'] );
		}
		foreach ( array( 'assigned_user_id', 'assigned_connector_id', 'import_id', 'partner_id', 'parent_lead_id' ) as $col ) {
			if ( isset( $args[ $col ] ) && '' !== (string) $args[ $col ] ) {
				$where[]  = "l.{$col} = %d";
				$values[] = (int) $args[ $col ];
			}
		}
		if ( ! empty( $args['unassigned'] ) ) {
			$where[] = 'l.assigned_user_id = 0';
		}
		$house = (string) ( $args['affiliation'] ?? '' );
		if ( 'house' === $house ) {
			$where[] = 'l.is_house_affiliated = 1';
		} elseif ( 'independent' === $house ) {
			$where[] = 'l.is_house_affiliated = 0';
		}
		if ( ! empty( $args['follow_up_due'] ) ) {
			$where[]  = 'l.next_follow_up_at IS NOT NULL AND l.next_follow_up_at <= %s';
			$values[] = LNKup_Org_Growth::now();
		}
		if ( ! empty( $args['possible_duplicates'] ) ) {
			$where[] = 'l.possible_duplicate_of > 0';
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(l.name LIKE %s OR l.email LIKE %s OR l.city LIKE %s OR l.domain LIKE %s OR l.instagram LIKE %s)';
			$values   = array_merge( $values, array( $like, $like, $like, $like, $like ) );
		}
		$need = sanitize_key( (string) ( $args['market_signal'] ?? '' ) );
		if ( in_array( $need, LNKup_Org_Market_Need::FILTERS, true ) ) {
			$keys = LNKup_Org_Market_Need::city_keys( $need );
			if ( $keys ) {
				$where[] = "CONCAT(LOWER(TRIM(l.city)), '|', UPPER(TRIM(l.state))) IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
				$values  = array_merge( $values, $keys );
			} else {
				$where[] = '1 = 0';
			}
		}
		$tag = sanitize_title( (string) ( $args['tag'] ?? '' ) );
		if ( '' !== $tag ) {
			$joins   .= ' INNER JOIN ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ) . ' tg ON tg.lead_id = l.id';
			$where[]  = 'tg.tag = %s';
			$values[] = $tag;
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', is_array( $args['ids'] ) ? $args['ids'] : explode( ',', (string) $args['ids'] ) ) ) );
			if ( $ids ) {
				$where[] = 'l.id IN (' . implode( ',', $ids ) . ')';
			}
		}
		return array( $where, $values, $joins );
	}

	/**
	 * Leads per stage for the Kanban (capped per column).
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function pipeline( array $args, int $per_column = 50 ): array {
		global $wpdb;
		list( $where, $values, $joins ) = self::build_where( $args );
		$table = self::table();
		$sql   = "SELECT l.stage, COUNT(DISTINCT l.id) AS n FROM {$table} l {$joins} WHERE " . implode( ' AND ', $where ) . ' GROUP BY l.stage';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$counts = $wpdb->get_results( $values ? $wpdb->prepare( $sql, $values ) : $sql, ARRAY_A );
		$by     = array();
		foreach ( (array) $counts as $c ) {
			$by[ (string) $c['stage'] ] = (int) $c['n'];
		}
		$columns = array();
		foreach ( LNKup_Org_Lead_Pipeline::describe() as $stage ) {
			$items = array();
			if ( ! empty( $by[ $stage['key'] ] ) ) {
				$res   = self::query( array_merge( $args, array( 'stage' => $stage['key'], 'per_page' => $per_column, 'page' => 1 ) ) );
				$items = array_map(
					static fn( $l ) => array_intersect_key( $l, array_flip( array( 'id', 'name', 'city', 'state', 'tier', 'score', 'assigned_user_name', 'next_follow_up_at', 'is_house_affiliated', 'outcome', 'category' ) ) ),
					$res['items']
				);
			}
			$columns[] = $stage + array(
				'count' => (int) ( $by[ $stage['key'] ] ?? 0 ),
				'items' => $items,
			);
		}
		return $columns;
	}

	// ------------------------------------------------------------------ Activity.

	/**
	 * @param array<string, mixed> $args actor, channel, outcome, summary, payload, occurred_at.
	 */
	public static function log_activity( int $lead_id, string $type, array $args = array() ): int {
		global $wpdb;
		$now = LNKup_Org_Growth::now();
		$wpdb->insert(
			LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ),
			array(
				'lead_id'       => $lead_id,
				'type'          => sanitize_key( $type ),
				'channel'       => sanitize_key( (string) ( $args['channel'] ?? '' ) ),
				'outcome'       => sanitize_key( (string) ( $args['outcome'] ?? '' ) ),
				'summary'       => substr( sanitize_text_field( (string) ( $args['summary'] ?? '' ) ), 0, 255 ),
				'payload'       => ! empty( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : null,
				'actor_user_id' => (int) ( $args['actor'] ?? 0 ),
				'occurred_at'   => ! empty( $args['occurred_at'] ) ? (string) $args['occurred_at'] : $now,
				'created_at'    => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function activity( int $lead_id, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY ) . ' WHERE lead_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d', $lead_id, max( 1, min( 500, $limit ) ) ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$actor   = (int) $row['actor_user_id'];
			$user    = $actor ? get_userdata( $actor ) : false;
			$payload = json_decode( (string) $row['payload'], true );
			$out[]   = array(
				'id'          => (int) $row['id'],
				'type'        => (string) $row['type'],
				'channel'     => (string) $row['channel'],
				'outcome'     => (string) $row['outcome'],
				'summary'     => (string) $row['summary'],
				'payload'     => is_array( $payload ) ? $payload : array(),
				'actor'       => $user ? (string) $user->display_name : ( $actor ? '#' . $actor : __( 'LNKup (automatic)', 'lnkup-marketplace' ) ),
				'occurred_at' => (string) $row['occurred_at'],
			);
		}
		return $out;
	}

	/**
	 * Record a manual or email touch and keep contact dates current.
	 *
	 * @param array<string, mixed> $args channel, outcome, summary, occurred_at, next_follow_up_at.
	 * @return int|WP_Error Activity ID.
	 */
	public static function record_contact( int $lead_id, array $args, int $actor ) {
		global $wpdb;
		$lead = self::get_row( $lead_id );
		if ( ! $lead ) {
			return new WP_Error( 'lnkup_lead_not_found', __( 'Lead not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$channel  = sanitize_key( (string) ( $args['channel'] ?? 'note' ) );
		$channels = array( 'note', 'email', 'phone', 'instagram_dm', 'facebook_dm', 'contact_form', 'meeting', 'in_person', 'other' );
		if ( ! in_array( $channel, $channels, true ) ) {
			return new WP_Error( 'lnkup_invalid_channel', __( 'Choose how you contacted this organizer.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$outcome  = sanitize_key( (string) ( $args['outcome'] ?? '' ) );
		$outcomes = array( '', 'sent', 'no_answer', 'left_message', 'replied', 'meeting_booked', 'demo_done', 'not_interested', 'bounced', 'wrong_contact' );
		if ( ! in_array( $outcome, $outcomes, true ) ) {
			$outcome = '';
		}
		$is_touch = 'note' !== $channel;
		if ( $is_touch && LNKup_Contact_Suppression::is_lead_suppressed( $lead ) && in_array( $channel, array( 'email', 'phone' ), true ) && 'replied' !== $outcome ) {
			return new WP_Error( 'lnkup_lead_suppressed', __( 'This contact asked not to be contacted. Only log replies they send you.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$occurred = ! empty( $args['occurred_at'] ) && strtotime( (string) $args['occurred_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $args['occurred_at'] ) ) : LNKup_Org_Growth::now();
		$id       = self::log_activity(
			$lead_id,
			$is_touch ? 'contact' : 'note',
			array(
				'actor'       => $actor,
				'channel'     => $channel,
				'outcome'     => $outcome,
				'summary'     => (string) ( $args['summary'] ?? '' ),
				'occurred_at' => $occurred,
				'payload'     => array( 'details' => sanitize_textarea_field( (string) ( $args['details'] ?? '' ) ) ),
			)
		);
		$update = array( 'updated_at' => LNKup_Org_Growth::now() );
		if ( $is_touch ) {
			$update['last_contacted_at'] = $occurred;
		}
		if ( ! empty( $args['next_follow_up_at'] ) ) {
			$update['next_follow_up_at'] = self::sanitize_value( 'datetime', $args['next_follow_up_at'] );
		}
		$wpdb->update( self::table(), $update, array( 'id' => $lead_id ) );

		if ( 'bounced' === $outcome ) {
			LNKup_Contact_Suppression::add_email( (string) $lead['email'], 'bounce', 'manual_log', $lead_id, $actor );
		}
		$implied = array(
			'sent'           => 'contacted',
			'no_answer'      => 'contacted',
			'left_message'   => 'contacted',
			'replied'        => 'responded',
			'meeting_booked' => 'meeting_scheduled',
			'demo_done'      => 'demo_completed',
		);
		$stage   = $implied[ $outcome ] ?? ( $is_touch ? 'contacted' : '' );
		if ( '' !== $stage && LNKup_Org_Lead_Pipeline::rank( $stage ) > LNKup_Org_Lead_Pipeline::rank( (string) $lead['stage'] ) ) {
			self::move( $lead_id, $stage, '', $actor, __( 'From logged contact', 'lnkup-marketplace' ) );
		}
		if ( 'not_interested' === $outcome ) {
			self::move( $lead_id, '', 'not_interested', $actor, (string) ( $args['summary'] ?? '' ) );
		}
		LNKup_Org_Growth_Metrics::flush_cache();
		return $id;
	}

	// ------------------------------------------------------------------ Tags.

	/**
	 * @return string[]
	 */
	public static function get_tags( int $lead_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT tag FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ) . ' WHERE lead_id = %d ORDER BY tag', $lead_id ) ) );
	}

	/**
	 * @param string[] $tags Tags.
	 */
	public static function set_tags( int $lead_id, array $tags ): bool {
		global $wpdb;
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ), array( 'lead_id' => $lead_id ) );
		return self::add_tags( $lead_id, $tags );
	}

	/**
	 * @param string[] $tags Tags.
	 */
	public static function add_tags( int $lead_id, array $tags ): bool {
		global $wpdb;
		$table = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS );
		foreach ( array_slice( $tags, 0, 30 ) as $tag ) {
			$tag = substr( sanitize_title( (string) $tag ), 0, 64 );
			if ( '' !== $tag ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (lead_id, tag) VALUES (%d, %s)", $lead_id, $tag ) );
			}
		}
		return true;
	}

	public static function remove_tag( int $lead_id, string $tag ): bool {
		global $wpdb;
		$wpdb->delete( LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ), array( 'lead_id' => $lead_id, 'tag' => sanitize_title( $tag ) ) );
		return true;
	}

	/**
	 * @return array<int, array{tag:string,count:int}>
	 */
	public static function all_tags(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( 'SELECT tag, COUNT(*) AS n FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS ) . ' GROUP BY tag ORDER BY n DESC LIMIT 200', ARRAY_A );
		return array_map( static fn( $r ) => array( 'tag' => (string) $r['tag'], 'count' => (int) $r['n'] ), (array) $rows );
	}

	// ------------------------------------------------------------------ Accounts.

	/**
	 * @return int[]
	 */
	public static function linked_user_ids( int $lead_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS ) . " WHERE lead_id = %d AND status = 'linked'", $lead_id ) ) );
	}

	public static function lead_for_user( int $user_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT lead_id FROM ' . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS ) . " WHERE user_id = %d AND status = 'linked' LIMIT 1", $user_id ) );
	}

	/**
	 * Re-flag house-affiliated organizations from the configured organizer IDs.
	 */
	public static function refresh_house_flags(): void {
		global $wpdb;
		$table    = self::table();
		$accounts = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		$ids      = LNKup_Org_Growth::house_organizer_ids();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$table} SET is_house_affiliated = 0" );
		if ( $ids ) {
			$wpdb->query( "UPDATE {$table} l INNER JOIN {$accounts} a ON a.lead_id = l.id AND a.status = 'linked' SET l.is_house_affiliated = 1 WHERE a.user_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' );
		}
		// phpcs:enable
	}

	// ------------------------------------------------------------------ Export.

	const EXPORT_COLUMNS = array( 'id', 'name', 'org_type', 'category', 'website', 'email', 'phone', 'instagram', 'facebook', 'city', 'state', 'service_area', 'event_frequency', 'cities_served', 'event_categories', 'estimated_booths', 'application_process', 'next_event_date', 'application_url', 'source_verified_at', 'source', 'source_campaign', 'assigned_user_name', 'assigned_connector_id', 'priority', 'stage_label', 'outcome_label', 'last_contacted_at', 'next_follow_up_at', 'score', 'tier', 'is_house_affiliated', 'is_suppressed', 'tags', 'created_at' );

	/**
	 * CSV text for the filtered lead set (max 10,000 rows). Cells starting with formula
	 * characters are prefixed so spreadsheets do not execute them.
	 *
	 * @param array<string, mixed> $args Filters.
	 */
	public static function export_csv( array $args ): string {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return '';
		}
		fputcsv( $handle, self::EXPORT_COLUMNS );
		$page = 1;
		do {
			$res = self::query( array_merge( $args, array( 'per_page' => 200, 'page' => $page ) ) );
			foreach ( $res['items'] as $lead ) {
				$line = array();
				foreach ( self::EXPORT_COLUMNS as $col ) {
					$value  = $lead[ $col ] ?? '';
					$value  = is_array( $value ) ? implode( '|', $value ) : ( is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value );
					$line[] = preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
				}
				fputcsv( $handle, $line );
			}
			++$page;
		} while ( $page <= $res['pages'] && $page <= 50 );
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}
}
