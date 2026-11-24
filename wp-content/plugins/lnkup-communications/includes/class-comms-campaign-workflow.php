<?php
/**
 * Marketing campaign workflow on top of admin campaigns (marketplace flag campaign_scheduling).
 *
 * Draft → Pending approval → Scheduled / Active (running) → Paused → Completed, plus Failed and
 * Archived. A campaign is approved by a different user with lnkup_manage_marketing; the approved
 * audience size is recorded and a scheduled send that grew more than 10% goes back for approval.
 *
 * Metadata: objective, market, landing tracking link, budget, exclusions, conversion goal and an
 * optional subject/copy experiment (variants assigned by a stable hash of campaign and user,
 * predefined metric and minimum sample; results say "insufficient data" until it is met).
 *
 * When the flag is off, admin campaigns behave exactly as before.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Campaign_Workflow {

	const SCHEDULE_HOOK = 'lnkup_comms_campaign_scheduled';
	const CAP           = 'lnkup_manage_marketing';
	const OBJECTIVES    = array( 'awareness', 'signups', 'applications', 'bookings', 'reactivation', 'organizer_activation' );
	const GOALS         = array( 'CompleteRegistration', 'VendorRegistered', 'OrganizerRegistered', 'SubmitApplication', 'Purchase', 'FirstEventPublished', 'Lead' );
	const MIN_SAMPLE    = 100;
	const REGROWTH      = 1.10;

	/** Display labels; `running` is shown as Active. */
	public static function statuses(): array {
		return array(
			'draft'            => __( 'Draft', 'lnkup-communications' ),
			'pending_approval' => __( 'Pending approval', 'lnkup-communications' ),
			'scheduled'        => __( 'Scheduled', 'lnkup-communications' ),
			'running'          => __( 'Active', 'lnkup-communications' ),
			'paused'           => __( 'Paused', 'lnkup-communications' ),
			'completed'        => __( 'Completed', 'lnkup-communications' ),
			'failed'           => __( 'Failed', 'lnkup-communications' ),
			'cancelled'        => __( 'Cancelled', 'lnkup-communications' ),
			'archived'         => __( 'Archived', 'lnkup-communications' ),
		);
	}

	public static function init(): void {
		add_action( self::SCHEDULE_HOOK, array( __CLASS__, 'run_scheduled' ), 10, 1 );
	}

	public static function active(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'campaign_scheduling' );
	}

	public static function can_manage( int $user_id ): bool {
		return $user_id > 0 && ( user_can( $user_id, self::CAP ) || user_can( $user_id, 'manage_options' ) );
	}

	/* ------------------------------------------------------------ Metadata */

	/**
	 * @param array<string,mixed> $raw Raw meta.
	 * @return array<string,mixed>
	 */
	public static function clean_meta( array $raw ): array {
		$exclusions = (array) ( $raw['exclusions'] ?? array() );
		$experiment = (array) ( $raw['experiment'] ?? array() );
		$variants   = array();
		foreach ( array_slice( (array) ( $experiment['variants'] ?? array() ), 0, 2 ) as $i => $variant ) {
			$subject = mb_substr( sanitize_text_field( (string) ( $variant['subject'] ?? '' ) ), 0, 120 );
			$body    = mb_substr( sanitize_textarea_field( (string) ( $variant['body'] ?? '' ) ), 0, 2000 );
			if ( '' !== $subject || '' !== $body ) {
				$variants[] = array( 'key' => chr( 98 + $i ), 'subject' => $subject, 'body' => $body );
			}
		}
		$link_id = absint( $raw['landing_link_id'] ?? 0 );
		if ( $link_id && ( ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::get( $link_id ) ) ) {
			$link_id = 0;
		}
		return array(
			'objective'       => in_array( (string) ( $raw['objective'] ?? '' ), self::OBJECTIVES, true ) ? (string) $raw['objective'] : 'awareness',
			'market'          => sanitize_title( (string) ( $raw['market'] ?? '' ) ),
			'landing_link_id' => $link_id,
			'budget'          => round( max( 0.0, (float) ( $raw['budget'] ?? 0 ) ), 2 ),
			'conversion_goal' => in_array( (string) ( $raw['conversion_goal'] ?? '' ), self::GOALS, true ) ? (string) $raw['conversion_goal'] : '',
			'exclusions'      => array(
				'recent_campaign_days' => min( 90, absint( $exclusions['recent_campaign_days'] ?? 0 ) ),
				'segment_id'           => absint( $exclusions['segment_id'] ?? 0 ),
			),
			'experiment'      => array(
				'metric'     => in_array( (string) ( $experiment['metric'] ?? '' ), array( 'click', 'conversion' ), true ) ? (string) $experiment['metric'] : 'click',
				'min_sample' => max( self::MIN_SAMPLE, absint( $experiment['min_sample'] ?? self::MIN_SAMPLE ) ),
				'variants'   => $variants,
			),
			'review_note'     => mb_substr( sanitize_textarea_field( (string) ( $raw['review_note'] ?? '' ) ), 0, 500 ),
		);
	}

	public static function meta( array $row ): array {
		$raw = is_array( $row['meta'] ?? null ) ? $row['meta'] : ( json_decode( (string) ( $row['meta'] ?? '' ), true ) ?: array() );
		return self::clean_meta( (array) $raw );
	}

	/**
	 * Audience filters for a campaign: its saved segment's rules when linked, else its own filters.
	 */
	public static function filters_for( array $row ): array {
		$segment = (int) ( $row['segment_id'] ?? 0 ) ? LNKup_Comms_Segments::get( (int) $row['segment_id'] ) : null;
		return LNKup_Comms_Audiences::clean_filters( $segment ? (array) $segment['rules'] : (array) $row['filters'] );
	}

	/* ------------------------------------------------------------ Lifecycle */

	/**
	 * Create a marketing campaign (draft).
	 *
	 * @return int|WP_Error
	 */
	public static function create( array $input, int $user_id ) {
		global $wpdb;
		$segment_id = absint( $input['segment_id'] ?? 0 );
		if ( $segment_id ) {
			$segment = LNKup_Comms_Segments::get( $segment_id );
			if ( ! $segment || 'active' !== $segment['status'] ) {
				return new WP_Error( 'lnkup_comms_campaign', __( 'Choose an active segment.', 'lnkup-communications' ), array( 'status' => 400 ) );
			}
			$input['filters'] = $segment['rules'];
		}
		$id = LNKup_Comms_Campaigns::create_admin( $input, $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( LNKup_Comms_Campaigns::table(), array( 'meta' => wp_json_encode( self::clean_meta( (array) ( $input['meta'] ?? array() ) ) ), 'segment_id' => $segment_id ), array( 'id' => (int) $id ) );
		return (int) $id;
	}

	/**
	 * Edit a draft (content, audience, metadata).
	 *
	 * @return true|WP_Error
	 */
	public static function update_draft( int $id, array $input ) {
		global $wpdb;
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || 'admin' !== $row['campaign_type'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Campaign not found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		if ( 'draft' !== $row['status'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Only drafts can be edited. Send it back to draft first.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		$data = array( 'updated_at' => LNKup_Comms_Schema::now() );
		if ( isset( $input['meta'] ) ) {
			$data['meta'] = wp_json_encode( self::clean_meta( (array) $input['meta'] ) );
		}
		if ( isset( $input['content'] ) ) {
			$content = array_merge( (array) $row['content'], array_intersect_key( (array) $input['content'], array_flip( array( 'subject', 'body', 'cta', 'push', 'link' ) ) ) );
			$data['content'] = wp_json_encode(
				array(
					'subject' => mb_substr( sanitize_text_field( (string) $content['subject'] ), 0, 120 ),
					'body'    => mb_substr( sanitize_textarea_field( (string) $content['body'] ), 0, 2000 ),
					'cta'     => mb_substr( sanitize_text_field( (string) ( $content['cta'] ?? '' ) ), 0, 40 ),
					'push'    => mb_substr( sanitize_text_field( (string) ( $content['push'] ?? '' ) ), 0, 178 ),
					'link'    => (string) ( $row['content']['link'] ?? 'home' ),
				)
			);
		}
		if ( isset( $input['filters'] ) ) {
			$filters                = LNKup_Comms_Audiences::clean_filters( (array) $input['filters'] );
			$data['filters']        = wp_json_encode( $filters );
			$data['audience_role']  = $filters['role'];
			$data['segment_id']     = 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( LNKup_Comms_Campaigns::table(), $data, array( 'id' => $id ) );
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function submit( int $id, int $user_id ) {
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || 'admin' !== $row['campaign_type'] || 'draft' !== $row['status'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Only drafts can be submitted for approval.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		$preview = LNKup_Comms_Campaigns::preview( $id );
		if ( empty( $preview['recipients'] ) ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'This audience has no recipients.', 'lnkup-communications' ), array( 'status' => 400 ) );
		}
		self::set( $id, array( 'status' => 'pending_approval', 'submitted_by' => $user_id ) );
		return true;
	}

	/**
	 * Approve and either start now or schedule. Approver must differ from creator and submitter.
	 *
	 * @return array{status:string,recipients:int}|WP_Error
	 */
	public static function approve( int $id, int $approver, int $scheduled_ts = 0 ) {
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || 'pending_approval' !== $row['status'] ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Only campaigns waiting for approval can be approved.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		if ( ! self::can_manage( $approver ) ) {
			return new WP_Error( 'lnkup_comms_forbidden', __( 'You cannot approve campaigns.', 'lnkup-communications' ), array( 'status' => 403 ) );
		}
		if ( in_array( $approver, array( (int) $row['created_by'], (int) $row['submitted_by'] ), true ) ) {
			return new WP_Error( 'lnkup_comms_two_person', __( 'A different team member must approve this campaign.', 'lnkup-communications' ), array( 'status' => 403 ) );
		}
		$preview = LNKup_Comms_Campaigns::preview( $id );
		$count   = (int) ( $preview['recipients'] ?? 0 );
		$base    = array( 'approved_by' => $approver, 'approved_at' => LNKup_Comms_Schema::now(), 'approved_count' => $count );
		if ( $scheduled_ts > time() + 60 ) {
			self::set( $id, array_merge( $base, array( 'status' => 'scheduled', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', $scheduled_ts ) ) ) );
			LNKup_Comms_Queue::job( self::SCHEDULE_HOOK, array( $id ), $scheduled_ts - time() );
			return array( 'status' => 'scheduled', 'recipients' => $count );
		}
		self::set( $id, array_merge( $base, array( 'status' => 'scheduled', 'scheduled_at' => LNKup_Comms_Schema::now() ) ) );
		$started = LNKup_Comms_Campaigns::start( $id, $count );
		if ( is_wp_error( $started ) ) {
			self::set( $id, array( 'status' => 'pending_approval' ) );
			return $started;
		}
		return array( 'status' => 'running', 'recipients' => $count );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function reject( int $id, int $approver, string $note ) {
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'pending_approval', 'scheduled' ), true ) ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'This campaign is not waiting for approval.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		$meta                = self::meta( $row );
		$meta['review_note'] = mb_substr( sanitize_textarea_field( $note ), 0, 500 );
		self::set( $id, array( 'status' => 'draft', 'approved_by' => 0, 'approved_at' => null, 'scheduled_at' => null, 'meta' => wp_json_encode( $meta ) ) );
		return true;
	}

	/**
	 * Scheduled start. Re-checks the audience; a >10% larger audience needs approval again.
	 *
	 * @param int $id Campaign.
	 */
	public static function run_scheduled( $id ): void {
		$id  = absint( $id );
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || 'scheduled' !== $row['status'] ) {
			return;
		}
		$preview = LNKup_Comms_Campaigns::preview( $id );
		$count   = (int) ( $preview['recipients'] ?? 0 );
		if ( $count > (int) ceil( (int) $row['approved_count'] * self::REGROWTH ) ) {
			$meta                = self::meta( $row );
			/* translators: 1: approved, 2: current */
			$meta['review_note'] = sprintf( __( 'Audience grew from %1$d to %2$d after approval. Please review again.', 'lnkup-communications' ), (int) $row['approved_count'], $count );
			self::set( $id, array( 'status' => 'pending_approval', 'meta' => wp_json_encode( $meta ) ) );
			return;
		}
		$started = LNKup_Comms_Campaigns::start( $id, $count );
		if ( is_wp_error( $started ) ) {
			self::set( $id, array( 'status' => 'failed', 'completed_at' => LNKup_Comms_Schema::now() ) );
		}
	}

	/**
	 * @return true|WP_Error
	 */
	public static function archive( int $id ) {
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'completed', 'cancelled', 'failed', 'draft' ), true ) ) {
			return new WP_Error( 'lnkup_comms_campaign', __( 'Only finished campaigns or drafts can be archived.', 'lnkup-communications' ), array( 'status' => 409 ) );
		}
		self::set( $id, array( 'status' => 'archived' ) );
		return true;
	}

	private static function set( int $id, array $data ): void {
		global $wpdb;
		$data['updated_at'] = LNKup_Comms_Schema::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( LNKup_Comms_Campaigns::table(), $data, array( 'id' => $id ) );
	}

	/* ------------------------------------------------------------ Sending helpers */

	/**
	 * Exclusions: recent campaign recipients and an exclusion segment.
	 */
	public static function allowed_recipient( array $row, int $user_id ): bool {
		global $wpdb;
		$meta = self::meta( $row );
		$days = (int) $meta['exclusions']['recent_campaign_days'];
		if ( $days > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$recent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Comms_Schema::table( 'messages' ) . " WHERE recipient_user_id = %d AND trigger_key = 'campaign_message' AND campaign_id <> %d AND created_at >= %s", $user_id, (int) $row['id'], LNKup_Comms_Schema::now( -$days * DAY_IN_SECONDS ) ) );
			if ( $recent > 0 ) {
				return false;
			}
		}
		$exclude = (int) $meta['exclusions']['segment_id'];
		if ( $exclude ) {
			$segment = LNKup_Comms_Segments::get( $exclude );
			if ( $segment && LNKup_Comms_Audiences::matches( $user_id, (array) $segment['rules'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Experiment variant for a user ('' = base copy 'a'). Stable across retries and batches.
	 */
	public static function variant_for( array $row, int $user_id ): string {
		$variants = (array) self::meta( $row )['experiment']['variants'];
		if ( ! $variants ) {
			return '';
		}
		$slot = (int) ( crc32( 'lnkup-exp|' . (int) $row['id'] . '|' . $user_id ) % ( count( $variants ) + 1 ) );
		return 0 === $slot ? '' : (string) $variants[ $slot - 1 ]['key'];
	}

	/**
	 * Subject/body overrides for a variant key.
	 *
	 * @return array<string,string>
	 */
	public static function variant_content( array $row, string $key ): array {
		foreach ( (array) self::meta( $row )['experiment']['variants'] as $variant ) {
			if ( $variant['key'] === $key ) {
				return array_filter( array( 'subject' => $variant['subject'], 'body' => $variant['body'] ) );
			}
		}
		return array();
	}

	/**
	 * Experiment results: recipients and metric per variant. No winner is named until every
	 * variant reaches the minimum sample and a two-proportion z-test passes at 95%.
	 *
	 * @return array<string,mixed>
	 */
	public static function results( int $id ): array {
		global $wpdb;
		$row = LNKup_Comms_Campaigns::get( $id );
		if ( ! $row ) {
			return array();
		}
		$meta     = self::meta( $row );
		$exp      = $meta['experiment'];
		$messages = LNKup_Comms_Schema::table( 'messages' );
		$clicks   = LNKup_Comms_Schema::table( 'clicks' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.id, m.recipient_user_id, m.context, ( SELECT COUNT(*) FROM {$clicks} c WHERE c.message_id = m.id ) AS clicks FROM {$messages} m WHERE m.campaign_id = %d AND m.trigger_key = 'campaign_message' AND m.status NOT IN ('cancelled','suppressed')", $id ), ARRAY_A );
		$groups = array( 'a' => array( 'recipients' => 0, 'successes' => 0 ) );
		foreach ( (array) $exp['variants'] as $variant ) {
			$groups[ $variant['key'] ] = array( 'recipients' => 0, 'successes' => 0 );
		}
		$goal    = (string) $meta['conversion_goal'];
		$started = (string) ( $row['started_at'] ?? '' );
		foreach ( $rows as $r ) {
			$ctx = json_decode( (string) $r['context'], true );
			$key = is_array( $ctx ) && ! empty( $ctx['_xv'] ) ? (string) $ctx['_xv'] : 'a';
			if ( ! isset( $groups[ $key ] ) ) {
				continue;
			}
			++$groups[ $key ]['recipients'];
			if ( 'click' === $exp['metric'] ) {
				$groups[ $key ]['successes'] += (int) $r['clicks'] > 0 ? 1 : 0;
			} elseif ( '' !== $goal && '' !== $started && class_exists( 'LNKup_Meta_Tables' ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hit = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . LNKup_Meta_Tables::conversions() . ' WHERE user_id = %d AND event_name = %s AND created_at BETWEEN %s AND %s', (int) $r['recipient_user_id'], $goal, $started, gmdate( 'Y-m-d H:i:s', strtotime( $started . ' UTC' ) + 14 * DAY_IN_SECONDS ) ) );
				$groups[ $key ]['successes'] += $hit > 0 ? 1 : 0;
			}
		}
		$enough = true;
		foreach ( $groups as $key => &$g ) {
			$g['rate'] = $g['recipients'] > 0 ? round( $g['successes'] / $g['recipients'], 4 ) : 0.0;
			if ( $g['recipients'] < (int) $exp['min_sample'] ) {
				$enough = false;
			}
		}
		unset( $g );
		$verdict = $enough ? 'no_clear_difference' : 'insufficient_data';
		$leader  = '';
		if ( $enough && count( $groups ) > 1 ) {
			uasort( $groups, static fn( $x, $y ) => $y['rate'] <=> $x['rate'] );
			$keys = array_keys( $groups );
			$a    = $groups[ $keys[0] ];
			$b    = $groups[ $keys[1] ];
			$p    = ( $a['successes'] + $b['successes'] ) / max( 1, $a['recipients'] + $b['recipients'] );
			$se   = sqrt( max( 1e-12, $p * ( 1 - $p ) * ( 1 / max( 1, $a['recipients'] ) + 1 / max( 1, $b['recipients'] ) ) ) );
			if ( ( $a['rate'] - $b['rate'] ) / $se >= 1.96 ) {
				$verdict = 'leader';
				$leader  = (string) $keys[0];
			}
		}
		return array( 'metric' => (string) $exp['metric'], 'goal' => $goal, 'min_sample' => (int) $exp['min_sample'], 'variants' => $groups, 'verdict' => $verdict, 'leader' => $leader );
	}
}
