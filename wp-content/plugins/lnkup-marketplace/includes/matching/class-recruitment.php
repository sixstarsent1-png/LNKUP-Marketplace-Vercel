<?php
/**
 * Organizer vendor recruitment: pipeline stages, invite guardrails,
 * batch invite jobs, and the deterministic recruitment assistant.
 *
 * An invited vendor is never counted as booked: only the `paid` stage
 * (driven by the canonical application transition) means a paid booking.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Recruitment {

	const STAGES   = array( 'identified', 'shortlisted', 'invited', 'opened', 'applied', 'approved', 'paid', 'declined' );
	const JOB_HOOK = 'lnkup_recruitment_process_job';
	const JOB_STEP = 20;

	public static function init() {
		add_action( self::JOB_HOOK, array( __CLASS__, 'process_job' ) );
		add_action( 'lnkup_vendor_invite_created', array( __CLASS__, 'on_invite_created' ), 10, 5 );
		add_action( 'lnkup_vendor_invite_status_changed', array( __CLASS__, 'on_invite_status' ), 10, 2 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ) );
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_event_status' ), 10, 3 );
	}

	/* ------------------------------------------------------------------
	 * Pipeline
	 * ---------------------------------------------------------------- */

	/**
	 * Move a vendor forward in an event's pipeline. Stages never move backwards
	 * (except `declined`, which records an explicit no).
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $event_id     Event ID.
	 * @param int    $profile_id   Vendor profile ID.
	 * @param string $stage        Stage.
	 * @param array  $extra        source, invite_id, application_id, opportunity_id, create (bool).
	 * @return bool
	 */
	public static function set_stage( $organizer_id, $event_id, $profile_id, $stage, array $extra = array() ) {
		if ( ! in_array( $stage, self::STAGES, true ) || ! $organizer_id || ! $event_id || ! $profile_id ) {
			return false;
		}
		global $wpdb;
		$table = LNKup_Match_Tables::pipeline_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE organizer_user_id = %d AND event_id = %d AND profile_id = %d", $organizer_id, $event_id, $profile_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$now   = current_time( 'mysql' );

		if ( ! $row ) {
			if ( isset( $extra['create'] ) && ! $extra['create'] ) {
				return false;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'organizer_user_id' => (int) $organizer_id,
					'event_id'          => (int) $event_id,
					'opportunity_id'    => (int) ( $extra['opportunity_id'] ?? 0 ),
					'profile_id'        => (int) $profile_id,
					'vendor_user_id'    => (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true ),
					'stage'             => $stage,
					'source'            => sanitize_key( $extra['source'] ?? 'manual' ),
					'invite_id'         => (int) ( $extra['invite_id'] ?? 0 ),
					'application_id'    => (int) ( $extra['application_id'] ?? 0 ),
					'stage_at'          => wp_json_encode( array( $stage => $now ) ),
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			);
			return true;
		}

		$current = array_search( $row['stage'], self::STAGES, true );
		$next    = array_search( $stage, self::STAGES, true );
		$update  = array( 'updated_at' => $now );
		if ( 'declined' === $stage || ( false !== $next && $next > $current && 'declined' !== $row['stage'] ) || ( 'declined' === $row['stage'] && in_array( $stage, array( 'applied', 'approved', 'paid' ), true ) ) ) {
			$stamps           = json_decode( (string) $row['stage_at'], true );
			$stamps           = is_array( $stamps ) ? $stamps : array();
			$stamps[ $stage ] = $now;
			$update['stage']    = $stage;
			$update['stage_at'] = wp_json_encode( $stamps );
		}
		foreach ( array( 'invite_id', 'application_id', 'opportunity_id' ) as $key ) {
			if ( ! empty( $extra[ $key ] ) ) {
				$update[ $key ] = (int) $extra[ $key ];
			}
		}
		$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Stage per profile for one event.
	 *
	 * @return array<int,string>
	 */
	public static function stages_for_event( $organizer_id, $event_id, array $profile_ids ) {
		if ( ! $profile_ids ) {
			return array();
		}
		global $wpdb;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT profile_id, stage FROM ' . LNKup_Match_Tables::pipeline_table() . ' WHERE organizer_user_id = %d AND event_id = %d AND profile_id IN (' . implode( ',', array_map( 'intval', $profile_ids ) ) . ')',
				$organizer_id,
				$event_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['profile_id'] ] = $row['stage'];
		}
		return $out;
	}

	/**
	 * Pipeline counts and rows for an event.
	 */
	public static function pipeline_for_event( $organizer_id, $event_id, $limit = 200 ) {
		global $wpdb;
		$table  = LNKup_Match_Tables::pipeline_table();
		$counts = array_fill_keys( self::STAGES, 0 );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT stage, COUNT(*) AS total FROM {$table} WHERE organizer_user_id = %d AND event_id = %d GROUP BY stage", $organizer_id, $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			$counts[ $row['stage'] ] = (int) $row['total'];
		}
		$items = (array) $wpdb->get_results( $wpdb->prepare( "SELECT profile_id, opportunity_id, stage, source, stage_at, updated_at FROM {$table} WHERE organizer_user_id = %d AND event_id = %d ORDER BY updated_at DESC LIMIT %d", $organizer_id, $event_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $items as &$item ) {
			$item['profile_id']     = (int) $item['profile_id'];
			$item['opportunity_id'] = (int) $item['opportunity_id'];
			$item['business_name']  = (string) ( get_post_meta( $item['profile_id'], '_lnkup_business_name', true ) ?: get_the_title( $item['profile_id'] ) );
			$stamps                 = json_decode( (string) $item['stage_at'], true );
			$item['stage_at']       = is_array( $stamps ) ? $stamps : array();
		}
		unset( $item );
		return array(
			'counts' => $counts,
			'items'  => $items,
		);
	}

	/**
	 * Add or remove vendors from an event shortlist.
	 *
	 * @param int    $organizer_id Organizer user ID.
	 * @param int    $event_id     Event ID (ownership checked by caller).
	 * @param int[]  $profile_ids  Profiles.
	 * @param string $action       add|remove.
	 * @param int    $list_id      Optional vendor list to mirror into.
	 * @return array|WP_Error
	 */
	public static function shortlist( $organizer_id, $event_id, array $profile_ids, $action = 'add', $list_id = 0 ) {
		$profile_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $profile_ids ) ) ) ), 0, 100 );
		if ( ! $profile_ids ) {
			return new WP_Error( 'lnkup_no_vendors', __( 'Choose at least one vendor.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$done    = array();
		$skipped = array();
		foreach ( $profile_ids as $profile_id ) {
			if ( 'remove' === $action ) {
				$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					LNKup_Match_Tables::pipeline_table(),
					array(
						'organizer_user_id' => (int) $organizer_id,
						'event_id'          => (int) $event_id,
						'profile_id'        => $profile_id,
						'stage'             => 'shortlisted',
					)
				);
				$done[] = $profile_id;
				continue;
			}
			$visible = self::vendor_visible_to( $organizer_id, $profile_id );
			if ( true !== $visible ) {
				$skipped[] = array( 'profile_id' => $profile_id, 'reason' => $visible );
				continue;
			}
			self::set_stage( $organizer_id, $event_id, $profile_id, 'shortlisted', array( 'source' => 'match' ) );
			if ( $list_id ) {
				$added = LNKup_Vendor_Lists::add_vendor( $list_id, $organizer_id, $profile_id );
				if ( is_wp_error( $added ) ) {
					$skipped[] = array( 'profile_id' => $profile_id, 'reason' => $added->get_error_code() );
					continue;
				}
			}
			$done[] = $profile_id;
		}
		return array(
			'updated' => $done,
			'skipped' => $skipped,
		);
	}

	/**
	 * Whether a profile can be seen/contacted by this organizer via recruitment.
	 *
	 * @return true|string True or a suppression code.
	 */
	public static function vendor_visible_to( $organizer_id, $profile_id ) {
		if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) || 'publish' !== get_post_status( $profile_id ) ) {
			return 'not_found';
		}
		$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		if ( ! $row ) {
			LNKup_Match_Vendor_Index::sync( $profile_id );
			$row = LNKup_Match_Vendor_Index::get_row( $profile_id );
		}
		if ( ! $row || 'active' !== $row['status'] ) {
			return 'inactive';
		}
		if ( ! (int) $row['discoverable'] && ! in_array( (int) $row['user_id'], LNKup_Match_Service::related_vendor_users( $organizer_id ), true ) ) {
			return 'not_discoverable';
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Invite guardrails
	 * ---------------------------------------------------------------- */

	/**
	 * Why an invite should not be sent (or '' when it may be sent).
	 *
	 * @param int   $organizer_id   Organizer.
	 * @param int   $event_id       Event.
	 * @param int   $opportunity_id Opportunity (0 = event-level).
	 * @param int   $profile_id     Vendor profile.
	 * @param array $context        related_users (int[]), applicants (int[]), batch_extra (int already queued this run).
	 * @return string Suppression code or ''.
	 */
	public static function invite_block_reason( $organizer_id, $event_id, $opportunity_id, $profile_id, array $context = array() ) {
		$visible = self::vendor_visible_to( $organizer_id, $profile_id );
		if ( true !== $visible ) {
			return $visible;
		}
		$row       = LNKup_Match_Vendor_Index::get_row( $profile_id );
		$vendor_id = (int) $row['user_id'];
		$related   = $context['related_users'] ?? LNKup_Match_Service::related_vendor_users( $organizer_id );
		$is_known  = in_array( $vendor_id, $related, true );
		if ( ! (int) $row['accept_invites'] && ! $is_known ) {
			return 'invites_disabled';
		}
		$applicants = $context['applicants'] ?? LNKup_Match_Service::event_applicant_users( $event_id );
		if ( in_array( $vendor_id, $applicants, true ) ) {
			return 'already_applied';
		}
		if ( self::has_open_invite( $organizer_id, $vendor_id, $event_id ) ) {
			return 'already_invited';
		}
		if ( $opportunity_id ) {
			$opp = LNKup_Match_Service::live_opportunity_input( $opportunity_id );
			if ( ! $opp ) {
				return 'ineligible';
			}
			$vendor = LNKup_Match_Service::vendor_inputs( array( $row ) )[ (int) $profile_id ];
			$check  = LNKup_Match_Engine::eligibility( $vendor, $opp, array( 'today' => current_time( 'Y-m-d' ), 'side' => 'organizer' ) );
			if ( ! $check['eligible'] ) {
				return 'ineligible:' . $check['blocks'][0];
			}
		}
		if ( ! $is_known && self::invites_to_vendor_since( $vendor_id, 7 * DAY_IN_SECONDS ) >= LNKup_Match_Settings::vendor_weekly_invite_cap() ) {
			return 'vendor_weekly_cap';
		}
		if ( self::invites_from_organizer_since( $organizer_id, DAY_IN_SECONDS ) + (int) ( $context['batch_extra'] ?? 0 ) >= LNKup_Match_Settings::organizer_daily_invite_cap() ) {
			return 'organizer_daily_cap';
		}
		return '';
	}

	/**
	 * Human label for a suppression code.
	 *
	 * @param string $code Code.
	 */
	public static function suppression_label( $code ) {
		$base = explode( ':', (string) $code )[0];
		$map  = array(
			'not_found'           => __( 'Profile no longer available', 'lnkup-marketplace' ),
			'inactive'            => __( 'Vendor account is inactive', 'lnkup-marketplace' ),
			'not_discoverable'    => __( 'Vendor opted out of discovery', 'lnkup-marketplace' ),
			'invites_disabled'    => __( 'Vendor is not accepting invites', 'lnkup-marketplace' ),
			'already_applied'     => __( 'Already applied to this event', 'lnkup-marketplace' ),
			'already_invited'     => __( 'Already has an open invite', 'lnkup-marketplace' ),
			'ineligible'          => __( 'Not eligible for this opportunity', 'lnkup-marketplace' ),
			'vendor_weekly_cap'   => __( 'Vendor received several invites this week', 'lnkup-marketplace' ),
			'organizer_daily_cap' => __( 'Daily invite limit reached', 'lnkup-marketplace' ),
			'batch_limit'         => __( 'Over the batch size limit', 'lnkup-marketplace' ),
		);
		return $map[ $base ] ?? __( 'Not sent', 'lnkup-marketplace' );
	}

	private static function has_open_invite( $organizer_id, $vendor_id, $event_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_organizer_user_id', 'value' => (int) $organizer_id ),
					array( 'key' => '_lnkup_vendor_user_id', 'value' => (int) $vendor_id ),
					array( 'key' => '_lnkup_event_id', 'value' => (int) $event_id ),
					array( 'key' => '_lnkup_status', 'value' => array( 'sent', 'opened' ), 'compare' => 'IN' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			$expires = get_post_meta( $id, '_lnkup_expires_at', true );
			if ( ! $expires || strtotime( $expires ) >= time() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Invites a vendor received from any organizer in a window.
	 */
	public static function invites_to_vendor_since( $vendor_id, $seconds ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id' AND v.meta_value = %s
				WHERE p.post_type = 'lnkup_vendor_invite' AND p.post_date_gmt >= %s",
				(string) (int) $vendor_id,
				gmdate( 'Y-m-d H:i:s', time() - (int) $seconds )
			)
		);
	}

	/**
	 * Invites an organizer sent in a window.
	 */
	public static function invites_from_organizer_since( $organizer_id, $seconds ) {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = %s
				WHERE p.post_type = 'lnkup_vendor_invite' AND p.post_date_gmt >= %s",
				(string) (int) $organizer_id,
				gmdate( 'Y-m-d H:i:s', time() - (int) $seconds )
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Batch invites
	 * ---------------------------------------------------------------- */

	/**
	 * Recipient preview with suppression reasons and the rendered template.
	 *
	 * @return array|WP_Error
	 */
	public static function preview( $organizer_id, $event_id, $opportunity_id, array $profile_ids, $message = '' ) {
		$profile_ids = array_values( array_unique( array_filter( array_map( 'absint', $profile_ids ) ) ) );
		if ( ! $profile_ids ) {
			return new WP_Error( 'lnkup_no_vendors', __( 'Choose at least one vendor.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( $opportunity_id && (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true ) !== (int) $event_id ) {
			return new WP_Error( 'lnkup_invalid_opportunity', __( 'That vendor slot belongs to a different event.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$max        = LNKup_Match_Settings::batch_max();
		$context    = array(
			'related_users' => LNKup_Match_Service::related_vendor_users( $organizer_id ),
			'applicants'    => LNKup_Match_Service::event_applicant_users( $event_id ),
		);
		$recipients = array();
		$suppressed = array();
		foreach ( $profile_ids as $index => $profile_id ) {
			$name = (string) ( get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id ) );
			if ( $index >= $max ) {
				$suppressed[] = array( 'profile_id' => $profile_id, 'business_name' => $name, 'reason' => 'batch_limit', 'reason_label' => self::suppression_label( 'batch_limit' ) );
				continue;
			}
			$context['batch_extra'] = count( $recipients );
			$reason                 = self::invite_block_reason( $organizer_id, $event_id, $opportunity_id, $profile_id, $context );
			if ( '' !== $reason ) {
				$suppressed[] = array( 'profile_id' => $profile_id, 'business_name' => $name, 'reason' => $reason, 'reason_label' => self::suppression_label( $reason ) );
				continue;
			}
			$recipients[] = array( 'profile_id' => $profile_id, 'business_name' => $name );
		}
		$event = get_post( $event_id );
		$org   = get_userdata( $organizer_id );
		return array(
			'event_id'       => (int) $event_id,
			'opportunity_id' => (int) $opportunity_id,
			'recipients'     => $recipients,
			'suppressed'     => $suppressed,
			'template'       => array(
				'subject' => sprintf( __( "You're invited to apply: %s", 'lnkup-marketplace' ), $event ? $event->post_title : '' ),
				'body'    => trim(
					sprintf(
						/* translators: 1: organizer name, 2: event name */
						__( '%1$s invited you to apply as a vendor at %2$s on LNKup.', 'lnkup-marketplace' ),
						$org ? $org->display_name : __( 'An organizer', 'lnkup-marketplace' ),
						$event ? $event->post_title : ''
					) . "\n\n" . sanitize_textarea_field( (string) $message )
				),
			),
			'limits'         => array(
				'batch_max'            => $max,
				'organizer_daily_cap'  => LNKup_Match_Settings::organizer_daily_invite_cap(),
				'sent_last_24h'        => self::invites_from_organizer_since( $organizer_id, DAY_IN_SECONDS ),
				'vendor_weekly_cap'    => LNKup_Match_Settings::vendor_weekly_invite_cap(),
			),
		);
	}

	/**
	 * Queue an organizer-approved batch invite job.
	 *
	 * @return array|WP_Error
	 */
	public static function create_job( $organizer_id, $event_id, $opportunity_id, array $profile_ids, $message = '' ) {
		$preview = self::preview( $organizer_id, $event_id, $opportunity_id, $profile_ids, $message );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		if ( ! $preview['recipients'] ) {
			return new WP_Error( 'lnkup_no_recipients', __( 'None of the selected vendors can be invited right now.', 'lnkup-marketplace' ), array( 'status' => 400, 'suppressed' => $preview['suppressed'] ) );
		}
		global $wpdb;
		$now     = current_time( 'mysql' );
		$reasons = array_count_values( array_map( static function ( $s ) {
			return explode( ':', $s['reason'] )[0];
		}, $preview['suppressed'] ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			LNKup_Match_Tables::jobs_table(),
			array(
				'organizer_user_id'   => (int) $organizer_id,
				'event_id'            => (int) $event_id,
				'opportunity_id'      => (int) $opportunity_id,
				'message'             => sanitize_textarea_field( (string) $message ),
				'recipients'          => wp_json_encode( array_map( 'intval', wp_list_pluck( $preview['recipients'], 'profile_id' ) ) ),
				'total'               => count( $preview['recipients'] ),
				'suppressed'          => count( $preview['suppressed'] ),
				'suppression_reasons' => wp_json_encode( $reasons ),
				'status'              => 'queued',
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		$job_id = (int) $wpdb->insert_id;
		wp_schedule_single_event( time() + 5, self::JOB_HOOK, array( $job_id ) );
		LNKup_Marketplace_Analytics::track( 'recruit_invite_batch', array( 'event_id' => (int) $event_id, 'count' => count( $preview['recipients'] ) ), $organizer_id );
		return self::job( $organizer_id, $job_id );
	}

	/**
	 * Send the next slice of a job; revalidates every recipient at send time.
	 *
	 * @param int $job_id Job ID.
	 */
	public static function process_job( $job_id ) {
		global $wpdb;
		$table = LNKup_Match_Tables::jobs_table();
		$job   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $job || in_array( $job['status'], array( 'complete', 'cancelled' ), true ) ) {
			return;
		}
		$recipients = json_decode( (string) $job['recipients'], true );
		$recipients = is_array( $recipients ) ? $recipients : array();
		$cursor     = (int) $job['sent'] + (int) $job['failed'];
		$slice      = array_slice( $recipients, $cursor, self::JOB_STEP );
		$reasons    = json_decode( (string) $job['suppression_reasons'], true );
		$reasons    = is_array( $reasons ) ? $reasons : array();
		$sent       = 0;
		$failed     = 0;
		$org        = (int) $job['organizer_user_id'];
		$event_id   = (int) $job['event_id'];
		$opp_id     = (int) $job['opportunity_id'];

		foreach ( $slice as $profile_id ) {
			$reason = self::invite_block_reason( $org, $event_id, $opp_id, (int) $profile_id );
			if ( '' !== $reason ) {
				$code             = explode( ':', $reason )[0];
				$reasons[ $code ] = (int) ( $reasons[ $code ] ?? 0 ) + 1;
				++$failed;
				continue;
			}
			$result = LNKup_Vendor_Invites::create(
				$org,
				(int) get_post_meta( (int) $profile_id, '_lnkup_owner_user_id', true ),
				array(
					'event_id'       => $event_id,
					'opportunity_id' => $opp_id,
					'message'        => (string) $job['message'],
					'type'           => 'apply',
					'source'         => 'match',
				)
			);
			if ( is_wp_error( $result ) ) {
				$code             = $result->get_error_code();
				$reasons[ $code ] = (int) ( $reasons[ $code ] ?? 0 ) + 1;
				++$failed;
				continue;
			}
			++$sent;
		}

		$total_sent   = (int) $job['sent'] + $sent;
		$total_failed = (int) $job['failed'] + $failed;
		$done         = ( $total_sent + $total_failed ) >= count( $recipients );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'sent'                => $total_sent,
				'failed'              => $total_failed,
				'suppression_reasons' => wp_json_encode( $reasons ),
				'status'              => $done ? 'complete' : 'sending',
				'updated_at'          => current_time( 'mysql' ),
			),
			array( 'id' => (int) $job_id )
		);
		if ( ! $done ) {
			wp_schedule_single_event( time() + 30, self::JOB_HOOK, array( (int) $job_id ) );
		}
	}

	/**
	 * Job status for its organizer.
	 *
	 * @return array|WP_Error
	 */
	public static function job( $organizer_id, $job_id ) {
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Match_Tables::jobs_table() . ' WHERE id = %d', (int) $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $job || ( (int) $job['organizer_user_id'] !== (int) $organizer_id && ! LNKup_Capabilities::user_has_manage_all( $organizer_id ) ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'Invite job not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$reasons = json_decode( (string) $job['suppression_reasons'], true );
		$labels  = array();
		foreach ( (array) $reasons as $code => $count ) {
			$labels[] = array( 'code' => $code, 'label' => self::suppression_label( $code ), 'count' => (int) $count );
		}
		return array(
			'id'          => (int) $job['id'],
			'event_id'    => (int) $job['event_id'],
			'status'      => $job['status'],
			'total'       => (int) $job['total'],
			'sent'        => (int) $job['sent'],
			'failed'      => (int) $job['failed'],
			'suppressed'  => (int) $job['suppressed'],
			'reasons'     => $labels,
			'created_at'  => $job['created_at'],
			'updated_at'  => $job['updated_at'],
		);
	}

	/* ------------------------------------------------------------------
	 * Hooks
	 * ---------------------------------------------------------------- */

	public static function on_invite_created( $invite_id, $organizer_id, $vendor_id, $event_id, $opportunity_id ) {
		$profile_id = (int) lnkup_get_vendor_profile_id( $vendor_id );
		if ( $profile_id && $event_id ) {
			delete_post_meta( $event_id, '_lnkup_recruit_prompt' );
			self::set_stage( $organizer_id, $event_id, $profile_id, 'invited', array( 'invite_id' => $invite_id, 'opportunity_id' => $opportunity_id, 'source' => sanitize_key( (string) get_post_meta( $invite_id, '_lnkup_invite_source', true ) ?: 'manual' ) ) );
			LNKup_Marketplace_Analytics::track( 'recruit_invite', array( 'event_id' => (int) $event_id ), (int) $organizer_id, (int) $opportunity_id );
		}
	}

	public static function on_invite_status( $invite_id, $status ) {
		$event_id   = (int) get_post_meta( $invite_id, '_lnkup_event_id', true );
		$org        = (int) get_post_meta( $invite_id, '_lnkup_organizer_user_id', true );
		$profile_id = (int) lnkup_get_vendor_profile_id( (int) get_post_meta( $invite_id, '_lnkup_vendor_user_id', true ) );
		$map        = array( 'opened' => 'opened', 'accepted' => 'opened', 'declined' => 'declined' );
		if ( $event_id && $org && $profile_id && isset( $map[ $status ] ) ) {
			self::set_stage( $org, $event_id, $profile_id, $map[ $status ], array( 'invite_id' => $invite_id, 'create' => false ) );
		}
	}

	public static function on_application_submitted( $app_id ) {
		self::sync_from_application( $app_id, 'applied' );
	}

	public static function on_application_status( $app_id, $prev, $status ) {
		unset( $prev );
		$map = array(
			'approved'         => 'approved',
			'payment_required' => 'approved',
			'paid'             => 'paid',
			'completed'        => 'paid',
		);
		if ( isset( $map[ $status ] ) ) {
			self::sync_from_application( $app_id, $map[ $status ] );
		}
	}

	/**
	 * Advance only vendors already in a recruitment pipeline (no attribution for organic applicants).
	 */
	private static function sync_from_application( $app_id, $stage ) {
		$org        = (int) get_post_meta( $app_id, '_lnkup_organizer_user_id', true );
		$event_id   = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( $org && $event_id && $profile_id ) {
			self::set_stage( $org, $event_id, $profile_id, $stage, array( 'application_id' => (int) $app_id, 'create' => false ) );
		}
	}

	/**
	 * Flag newly published events so the organizer sees the recruitment prompt.
	 */
	public static function on_event_status( $new_status, $old_status, $post ) {
		if ( 'lnkup_event' === $post->post_type && 'publish' === $new_status && 'publish' !== $old_status ) {
			update_post_meta( $post->ID, '_lnkup_recruit_prompt', current_time( 'mysql' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Assistant
	 * ---------------------------------------------------------------- */

	/**
	 * Readiness checklist for matching (only real, missing data).
	 *
	 * @param int $event_id Event ID.
	 */
	public static function readiness( $event_id ) {
		global $wpdb;
		$index = LNKup_Marketplace_Index::table_name();
		$opps  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT opportunity_id, categories, deadline, spots_remaining, spots_total FROM {$index} WHERE event_id = %d", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$has_categories = false;
		$has_deadline   = false;
		foreach ( $opps as $opp ) {
			$has_categories = $has_categories || array() !== ( json_decode( (string) $opp['categories'], true ) ?: array() );
			$has_deadline   = $has_deadline || ! empty( $opp['deadline'] );
		}
		$items = array(
			array( 'key' => 'published', 'label' => __( 'Event is published', 'lnkup-marketplace' ), 'done' => 'publish' === get_post_status( $event_id ) ),
			array( 'key' => 'open_slot', 'label' => __( 'At least one open vendor slot', 'lnkup-marketplace' ), 'done' => ! empty( $opps ) ),
			array( 'key' => 'categories', 'label' => __( 'Vendor categories selected', 'lnkup-marketplace' ), 'done' => $has_categories ),
			array( 'key' => 'location', 'label' => __( 'Event location set (city, state)', 'lnkup-marketplace' ), 'done' => '' !== (string) get_post_meta( $event_id, '_lnkup_city', true ) && '' !== (string) get_post_meta( $event_id, '_lnkup_state', true ) ),
			array( 'key' => 'map_location', 'label' => __( 'Event address found on the map', 'lnkup-marketplace' ), 'done' => (bool) get_post_meta( $event_id, '_lnkup_lat', true ) ),
			array( 'key' => 'deadline', 'label' => __( 'Application deadline set', 'lnkup-marketplace' ), 'done' => $has_deadline ),
			array( 'key' => 'date', 'label' => __( 'Event date set', 'lnkup-marketplace' ), 'done' => '' !== (string) get_post_meta( $event_id, '_lnkup_event_date', true ) ),
		);
		return $items;
	}

	/**
	 * Deterministic suggestions from real records. Suggests; never acts.
	 *
	 * @param int $organizer_id Organizer.
	 * @param int $event_id     Event.
	 * @return array
	 */
	public static function assistant( $organizer_id, $event_id ) {
		global $wpdb;
		$suggestions = array();
		$readiness   = self::readiness( $event_id );
		$missing     = array_filter( $readiness, static function ( $item ) {
			return ! $item['done'];
		} );
		if ( $missing ) {
			$suggestions[] = array(
				'type'    => 'complete_details',
				'title'   => __( 'Complete your event details', 'lnkup-marketplace' ),
				'message' => sprintf( __( 'Add: %s. Vendors use these details to decide whether to apply.', 'lnkup-marketplace' ), implode( ', ', wp_list_pluck( $missing, 'label' ) ) ),
				'action'  => 'edit_event',
			);
		}

		$index   = LNKup_Marketplace_Index::table_name();
		$results = LNKup_Match_Tables::results_table();
		$vtable  = LNKup_Match_Tables::vendor_index_table();
		$opps    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT opportunity_id, title, event_date, spots_remaining, spots_total, application_count FROM {$index} WHERE event_id = %d", $event_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$related = LNKup_Match_Service::related_vendor_users( $organizer_id );
		$visible = $related ? '(v.discoverable = 1 OR v.user_id IN (' . implode( ',', $related ) . '))' : 'v.discoverable = 1';
		$matches = $opps ? (int) $wpdb->get_var( "SELECT COUNT(DISTINCT r.profile_id) FROM {$results} r INNER JOIN {$vtable} v ON v.profile_id = r.profile_id WHERE r.eligible = 1 AND v.status = 'active' AND {$visible} AND r.opportunity_id IN (" . implode( ',', array_map( 'intval', wp_list_pluck( $opps, 'opportunity_id' ) ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB
		$pipe    = self::pipeline_for_event( $organizer_id, $event_id, 1 );
		$invited = $pipe['counts']['invited'] + $pipe['counts']['opened'] + $pipe['counts']['applied'] + $pipe['counts']['approved'] + $pipe['counts']['paid'];

		if ( $matches > 0 && 0 === $invited ) {
			$suggestions[] = array(
				'type'    => 'view_matches',
				'title'   => __( 'Recruit vendors for this event', 'lnkup-marketplace' ),
				'message' => sprintf( _n( '%d vendor matches your event and can be invited to apply.', '%d vendors match your event and can be invited to apply.', $matches, 'lnkup-marketplace' ), $matches ),
				'action'  => 'find_vendors',
			);
		}

		foreach ( $opps as $opp ) {
			$open  = (int) $opp['spots_remaining'];
			$total = (int) $opp['spots_total'];
			if ( $open <= 0 ) {
				continue;
			}
			$days = $opp['event_date'] ? (int) floor( ( strtotime( $opp['event_date'] ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS ) : null;
			if ( null !== $days && $days >= 0 && $days <= 21 ) {
				$suggestions[] = array(
					'type'    => 'open_spaces',
					'title'   => __( 'Your event has open vendor spaces', 'lnkup-marketplace' ),
					'message' => sprintf( __( '%1$d of %2$d spaces are open and the event is %3$d days away. Review outreach options.', 'lnkup-marketplace' ), $open, max( $total, $open ), $days ),
					'action'  => 'find_vendors',
					'opportunity_id' => (int) $opp['opportunity_id'],
				);
			}
			$cats = LNKup_Match_Categories::for_object( (int) $opp['opportunity_id'] );
			if ( $cats && (int) $opp['application_count'] < max( 1, (int) floor( $total / 2 ) ) ) {
				foreach ( array_slice( $cats, 0, 2 ) as $cat ) {
					$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
						$wpdb->prepare(
							"SELECT COUNT(DISTINCT r.profile_id) FROM {$results} r INNER JOIN {$vtable} v ON v.profile_id = r.profile_id INNER JOIN " . LNKup_Match_Tables::vendor_categories_table() . " c ON c.profile_id = r.profile_id AND c.term_id = %d WHERE r.eligible = 1 AND v.status = 'active' AND {$visible} AND r.opportunity_id = %d",
							$cat['term_id'],
							(int) $opp['opportunity_id']
						)
					);
					if ( $count > 0 ) {
						$suggestions[] = array(
							'type'        => 'category_gap',
							'title'       => sprintf( __( 'Need more %s vendors?', 'lnkup-marketplace' ), $cat['name'] ),
							'message'     => sprintf( _n( '%1$d eligible %2$s vendor matches this slot.', '%1$d eligible %2$s vendors match this slot.', $count, 'lnkup-marketplace' ), $count, $cat['name'] ),
							'action'      => 'find_vendors',
							'category_id' => (int) $cat['term_id'],
							'opportunity_id' => (int) $opp['opportunity_id'],
						);
					}
				}
			}
		}

		$pending = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} e INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id AND p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status' AND s.meta_value IN ('submitted','under_review')
				WHERE e.meta_key = '_lnkup_event_id' AND e.meta_value = %s",
				(string) (int) $event_id
			)
		);
		if ( $pending > 0 ) {
			$suggestions[] = array(
				'type'    => 'review_applications',
				'title'   => __( 'Vendor applications need attention', 'lnkup-marketplace' ),
				'message' => sprintf( _n( '%d application is waiting for your review.', '%d applications are waiting for your review.', $pending, 'lnkup-marketplace' ), $pending ),
				'action'  => 'review_applications',
			);
		}

		return array(
			'event_id'       => (int) $event_id,
			'readiness'      => $readiness,
			'suggestions'    => $suggestions,
			'matching_count' => $matches,
			'pipeline'       => $pipe['counts'],
			'just_published' => (bool) get_post_meta( $event_id, '_lnkup_recruit_prompt', true ),
		);
	}
}
