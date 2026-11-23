<?php
/**
 * Takes over lnkup-marketplace messages one flow at a time.
 *
 * lnkup-marketplace asks `lnkup_pre_send_notification` before every send. A key is
 * claimed only while its flow flag is ON. Claimed keys are either:
 * - covered by a lifecycle listener (adapter = null), so core simply skips, or
 * - translated by an adapter that dispatches through the engine. An adapter may
 *   return false to decline the claim, in which case core sends as before.
 *
 * Turning a flag off (or deactivating this plugin) returns that flow to core.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Delegation {

	public static function init(): void {
		add_filter( 'lnkup_pre_send_notification', array( __CLASS__, 'filter' ), 10, 3 );
	}

	/**
	 * core key => [ flag(s) (all must be on), adapter callable|null ].
	 */
	public static function map(): array {
		return array(
			'application_submitted'                  => array( array( 'flow_applications' ), null ),
			'application_approved'                   => array( array( 'flow_applications' ), null ),
			'payment_required'                       => array( array( 'flow_applications' ), null ),
			'info_requested'                         => array( array( 'flow_applications' ), null ),
			'info_response'                          => array( array( 'flow_applications' ), null ),
			'application_rejected'                   => array( array( 'flow_applications' ), null ),
			'application_waitlisted'                 => array( array( 'flow_applications' ), null ),
			'payment_confirmed'                      => array( array( 'flow_payments' ), null ),
			'packet_unlocked'                        => array( array( 'flow_payments' ), null ),
			'organizer_vendor_paid'                  => array( array( 'flow_payments' ), null ),
			'invoice_email'                          => array( array( 'flow_payments' ), null ),
			'invoice_reminder'                       => array( array( 'flow_payments' ), array( __CLASS__, 'adapt_invoice_reminder' ) ),
			'financial_reminder'                     => array( array( 'flow_payments' ), array( __CLASS__, 'adapt_financial_reminder' ) ),
			'documents_needed'                       => array( array( 'flow_documents' ), array( __CLASS__, 'adapt_documents_needed' ) ),
			'arrival_reminder'                       => array( array( 'flow_operations', 'flow_countdown' ), null ),
			'pass_details_updated'                   => array( array( 'flow_operations' ), null ),
			'event_logistics_changed'                => array( array( 'flow_operations' ), null ),
			'ops_broadcast'                          => array( array( 'flow_operations' ), array( __CLASS__, 'adapt_ops_broadcast' ) ),
			'vendor_welcome'                         => array( array( 'flow_onboarding' ), null ),
			'vendor_complete_profile'                => array( array( 'flow_onboarding' ), null ),
			'vendor_find_events'                     => array( array( 'flow_onboarding' ), null ),
			'vendor_apply_nudge'                     => array( array( 'flow_onboarding' ), null ),
			'organizer_welcome'                      => array( array( 'flow_onboarding' ), null ),
			'organizer_create_event'                 => array( array( 'flow_onboarding' ), null ),
			'organizer_review_vendors'               => array( array( 'flow_onboarding' ), null ),
			'profile_match'                          => array( array( 'flow_discovery' ), null ),
			'favorite_organizer_new_event'           => array( array( 'flow_discovery' ), null ),
			'deadline_approaching'                   => array( array( 'flow_discovery' ), null ),
			'post_event_review_prompt'               => array( array( 'flow_post_event' ), null ),
			'saved_search_match'                     => array( array( 'flow_discovery' ), array( __CLASS__, 'adapt_saved_search_match' ) ),
			'favorite_deadline_reminder'             => array( array( 'flow_discovery' ), array( __CLASS__, 'adapt_favorite_deadline' ) ),
			'vendor_invite'                          => array( array( 'flow_discovery' ), array( __CLASS__, 'adapt_vendor_invite' ) ),
		);
	}

	/**
	 * Core saved-search alert (core picked the matches; we deliver with consent + digests).
	 * Args: user_id, name, count, items, filters, search_id.
	 */
	public static function adapt_saved_search_match( array $args ): bool {
		$user   = absint( $args[0] ?? 0 );
		$name   = sanitize_text_field( (string) ( $args[1] ?? '' ) );
		$count  = absint( $args[2] ?? 0 );
		$search = sanitize_key( (string) ( $args[5] ?? '' ) );
		// Outside the staged audience, core keeps sending so the vendor still hears about it.
		if ( ! $user || ! $count || '' === $name || ! LNKup_Comms_Settings::enabled_for( 'flow_discovery', $user ) ) {
			return false;
		}
		$items = array();
		foreach ( array_slice( (array) ( $args[3] ?? array() ), 0, 5 ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['opportunity_id'] ) ) {
				continue;
			}
			$items[] = array(
				'title' => (string) ( $item['event_name'] ?? $item['name'] ?? '' ),
				'meta'  => trim( (string) ( $item['location_label'] ?? '' ) . ( ! empty( $item['date'] ) ? ' · ' . wp_date( 'M j', (int) strtotime( (string) $item['date'] ) ) : '' ), ' ·' ),
				'url'   => LNKup_Comms_Context::web_url( 'opportunity', LNKup_Comms_Context::build( array( 'opportunity_id' => (int) $item['opportunity_id'] ) ), 'vendor' ),
			);
		}
		LNKup_Comms_Engine::dispatch(
			'v_saved_search_match',
			$user,
			array( 'vendor_user_id' => $user, 'search_name' => $name, 'count' => (string) $count ),
			array( 'window' => 'ss_' . $search . '_' . gmdate( 'Ymd' ), 'items' => $items, 'cancel_key' => 'user:' . $user . '|search', 'source' => 'core_saved_search' )
		);
		return true;
	}

	/**
	 * Saved-event deadline reminder. Args: user_id, title, deadline, window, opportunity_id.
	 * Without an opportunity ID (older core) the send stays in core.
	 */
	public static function adapt_favorite_deadline( array $args ): bool {
		$user   = absint( $args[0] ?? 0 );
		$window = sanitize_key( (string) ( $args[3] ?? '48h' ) );
		$opp    = absint( $args[4] ?? 0 );
		if ( ! $user || ! $opp || ! LNKup_Comms_Settings::enabled_for( 'flow_discovery', $user ) ) {
			return false;
		}
		$labels = array(
			'24h' => __( 'within 24 hours', 'lnkup-communications' ),
			'48h' => __( 'within 48 hours', 'lnkup-communications' ),
		);
		LNKup_Comms_Engine::dispatch(
			'v_saved_deadline',
			$user,
			array( 'opportunity_id' => $opp, 'vendor_user_id' => $user, 'window_label' => $labels[ $window ] ?? __( 'soon', 'lnkup-communications' ) ),
			array( 'window' => 'fav_' . $window, 'source' => 'core_favorite_deadline' )
		);
		return true;
	}

	/**
	 * Organizer invite to apply. Args: invite_id, link (tokenized invite URL).
	 */
	public static function adapt_vendor_invite( array $args ): bool {
		$invite = absint( $args[0] ?? 0 );
		if ( 'lnkup_vendor_invite' !== get_post_type( $invite ) ) {
			return false;
		}
		$vendor = (int) get_post_meta( $invite, '_lnkup_vendor_user_id', true );
		if ( ! $vendor || ! LNKup_Comms_Settings::enabled_for( 'flow_discovery', $vendor ) ) {
			return false;
		}
		LNKup_Comms_Engine::dispatch(
			'v_invited_to_apply',
			$vendor,
			array(
				'vendor_user_id'    => $vendor,
				'organizer_user_id' => (int) get_post_meta( $invite, '_lnkup_organizer_user_id', true ),
				'event_id'          => (int) get_post_meta( $invite, '_lnkup_event_id', true ),
				'opportunity_id'    => (int) get_post_meta( $invite, '_lnkup_opportunity_id', true ),
				'invite_id'         => $invite,
				'invite_url'        => esc_url_raw( (string) ( $args[1] ?? '' ) ),
				'invite_message'    => sanitize_textarea_field( (string) get_post_meta( $invite, '_lnkup_invite_message', true ) ),
			),
			array( 'window' => 'invite_' . $invite, 'link' => 'invite', 'cancel_key' => 'invite:' . $invite, 'source' => 'core_vendor_invite' )
		);
		return true;
	}

	/**
	 * Keys currently claimed (for the admin overview).
	 *
	 * @return string[]
	 */
	public static function claimed_keys(): array {
		$out = array();
		foreach ( self::map() as $key => $entry ) {
			if ( self::flags_on( $entry[0] ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	private static function flags_on( array $flags ): bool {
		foreach ( $flags as $flag ) {
			if ( ! LNKup_Comms_Settings::enabled( $flag ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed  $pre       Previous filter value.
	 * @param string $event_key Core key.
	 * @param array  $args      Original arguments.
	 * @return mixed Non-null to skip the core send.
	 */
	public static function filter( $pre, $event_key, $args ) {
		if ( null !== $pre ) {
			return $pre;
		}
		$map = self::map();
		if ( ! isset( $map[ $event_key ] ) || ! self::flags_on( $map[ $event_key ][0] ) ) {
			return null;
		}
		$adapter = $map[ $event_key ][1];
		if ( null === $adapter ) {
			return true;
		}
		try {
			return call_user_func( $adapter, is_array( $args ) ? array_values( $args ) : array() ) ? true : null;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup Communications delegation ' . $event_key . ' failed, core will send: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Core invoice reminders (7d/3d/24h/past due). Payment reminders for approved
	 * applications are scheduled by the payments listener; this covers invoice rows.
	 */
	public static function adapt_invoice_reminder( array $args ): bool {
		$row    = is_array( $args[0] ?? null ) ? $args[0] : array();
		$window = sanitize_key( (string) ( $args[1] ?? '' ) );
		$app_id = absint( $row['application_id'] ?? 0 );
		$user   = absint( $row['vendor_user_id'] ?? 0 );
		if ( ! $app_id || ! $user ) {
			return false;
		}
		$labels = array(
			'7_days'   => __( 'in 7 days', 'lnkup-communications' ),
			'3_days'   => __( 'in 3 days', 'lnkup-communications' ),
			'24_hours' => __( 'tomorrow', 'lnkup-communications' ),
			'past_due' => __( 'now (past due)', 'lnkup-communications' ),
		);
		LNKup_Comms_Engine::dispatch(
			'v_payment_reminder',
			$user,
			array( 'application_id' => $app_id, 'window_label' => $labels[ $window ] ?? __( 'soon', 'lnkup-communications' ) ),
			array( 'window' => 'inv_' . $window, 'cancel_key' => 'app:' . $app_id . '|payment', 'source' => 'core_invoice_reminder' )
		);
		return true;
	}

	/**
	 * Installment / deposit reminders: only claimed when tied to an application.
	 */
	public static function adapt_financial_reminder( array $args ): bool {
		$type   = sanitize_key( (string) ( $args[0] ?? '' ) );
		$row    = is_array( $args[1] ?? null ) ? $args[1] : array();
		$app_id = absint( $row['application_id'] ?? 0 );
		$user   = absint( $row['vendor_user_id'] ?? 0 );
		if ( ! $app_id || ! $user || ! in_array( $type, array( 'installment_due', 'deposit_due' ), true ) ) {
			return false;
		}
		LNKup_Comms_Engine::dispatch(
			'v_payment_reminder',
			$user,
			array( 'application_id' => $app_id, 'window_label' => __( 'soon', 'lnkup-communications' ) ),
			array( 'window' => $type . '_' . absint( $row['id'] ?? 0 ), 'cancel_key' => 'app:' . $app_id . '|payment', 'source' => 'core_' . $type, 'force' => true )
		);
		return true;
	}

	public static function adapt_documents_needed( array $args ): bool {
		$app_id = absint( $args[0] ?? 0 );
		$window = sanitize_key( (string) ( $args[1] ?? 'once' ) );
		$user   = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		if ( ! $app_id || ! $user ) {
			return false;
		}
		LNKup_Comms_Engine::dispatch( 'v_documents_needed', $user, array( 'application_id' => $app_id ), array( 'window' => $window, 'source' => 'core_documents_needed' ) );
		return true;
	}

	/**
	 * Core ops broadcast (organizer → event vendors) becomes queued, per-vendor delivery.
	 */
	public static function adapt_ops_broadcast( array $args ): bool {
		$event_id   = absint( $args[0] ?? 0 );
		$vendor_ids = array_map( 'absint', (array) ( $args[1] ?? array() ) );
		$text       = sanitize_textarea_field( (string) ( $args[2] ?? '' ) );
		if ( ! $event_id || '' === $text ) {
			return false;
		}
		$hash = substr( md5( $text ), 0, 10 );
		foreach ( array_filter( $vendor_ids ) as $vendor_id ) {
			$app_ids = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_event_id', 'value' => (string) $event_id ),
						array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $vendor_id ),
					),
				)
			);
			LNKup_Comms_Engine::dispatch(
				'v_announcement',
				$vendor_id,
				array(
					'event_id'           => $event_id,
					'application_id'     => (int) ( $app_ids[0] ?? 0 ),
					'announcement_title' => __( 'Event update', 'lnkup-communications' ),
					'announcement_body'  => $text,
				),
				array( 'window' => 'ops_' . $hash, 'force' => true, 'priority' => 'high', 'source' => 'core_ops_broadcast' )
			);
		}
		return true;
	}
}
