<?php
/**
 * Marketing analytics taxonomy: the specification's event names mapped onto what LNKup already
 * records, instead of a second event stream.
 *
 * - Conversions (registrations, applications, bookings, payments) come from the conversion
 *   registry, which already carries event_id, schema_version, dedupe_key, consent_state,
 *   attribution_json and is_test, and mirrors each row into lnkup_analytics_events as conv_*.
 * - Other events map to existing lnkup_analytics_events types.
 * - New marketing events are written through emit(), which adds a versioned contract
 *   (_v, event_id, env) to the same analytics table.
 *
 * Also: the Meta "Lead" conversion (vendor interest in an external event, market alert) and the
 * analytics retention job (daily rollup always; raw purge only when explicitly enabled).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Analytics_Taxonomy {

	const CONTRACT_VERSION = 1;
	const RETENTION_MONTHS = 13;
	const RETENTION_HOOK   = 'lnkup_analytics_retention';
	const ROLLUP_DAYS_PER_RUN = 31;

	/**
	 * Spec event => sources. `types` are lnkup_analytics_events.event_type values; `status`
	 * is mapped (existing data) or not_instrumented (no reliable signal exists yet).
	 *
	 * @return array<string,array{meaning:string,types:string[],status:string}>
	 */
	public static function map(): array {
		return array(
			'opportunity_viewed'         => array( 'meaning' => 'Public opportunity viewed', 'types' => array( 'view' ), 'status' => 'mapped' ),
			'opportunity_shared'         => array( 'meaning' => 'Share action completed', 'types' => array( 'share_link_copied', 'application_link_copied' ), 'status' => 'mapped' ),
			'opportunity_saved'          => array( 'meaning' => 'Opportunity or event saved', 'types' => array( 'conv_addtowishlist' ), 'status' => 'mapped' ),
			'vendor_signup_completed'    => array( 'meaning' => 'Vendor registration completed', 'types' => array( 'conv_vendorregistered' ), 'status' => 'mapped' ),
			'vendor_profile_completed'   => array( 'meaning' => 'Vendor reached profile completion', 'types' => array( 'conv_vendorprofilecompleted' ), 'status' => 'mapped' ),
			'application_started'        => array( 'meaning' => 'Application initiated', 'types' => array( 'apply_clicked' ), 'status' => 'partial' ),
			'application_submitted'      => array( 'meaning' => 'Application submitted', 'types' => array( 'conv_submitapplication' ), 'status' => 'mapped' ),
			'application_approved'       => array( 'meaning' => 'Organizer approved application', 'types' => array( 'conv_applicationapproved' ), 'status' => 'mapped' ),
			'booking_confirmed'          => array( 'meaning' => 'Vendor booking confirmed (paid)', 'types' => array( 'conv_firstvendorbooking', 'conv_repeatvendorbooking' ), 'status' => 'mapped' ),
			'vendor_payment_succeeded'   => array( 'meaning' => 'Authoritative payment success', 'types' => array( 'conv_purchase' ), 'status' => 'mapped' ),
			'organizer_signup_completed' => array( 'meaning' => 'Organizer registration completed', 'types' => array( 'conv_organizerregistered' ), 'status' => 'mapped' ),
			'organizer_event_created'    => array( 'meaning' => 'Organizer created event', 'types' => array( 'conv_eventcreated' ), 'status' => 'mapped' ),
			'organizer_event_published'  => array( 'meaning' => 'Organizer published event', 'types' => array( 'conv_eventpublished' ), 'status' => 'mapped' ),
			'organizer_first_booking'    => array( 'meaning' => 'Organizer first paid booking', 'types' => array( 'conv_organizerfirstpaidbooking' ), 'status' => 'mapped' ),
			'external_event_interest'    => array( 'meaning' => 'Vendor interest in an external event', 'types' => array( 'external_event_interest_submitted' ), 'status' => 'mapped' ),
			'external_event_claimed'     => array( 'meaning' => 'Verified organizer claimed event', 'types' => array( 'external_event_claim_completed' ), 'status' => 'mapped' ),
			'referral_conversion'        => array( 'meaning' => 'Referral qualification satisfied', 'types' => array(), 'status' => 'ledger' ),
			// Marketing OS additions (emitted with the v1 contract).
			'market_alert_created'       => array( 'meaning' => 'Market alert (saved search) created', 'types' => array( 'market_alert_created' ), 'status' => 'mapped' ),
			'market_demand_signal'       => array( 'meaning' => 'Anonymous category demand tap on a market page', 'types' => array( 'market_demand_signal' ), 'status' => 'mapped' ),
			'market_page_viewed'         => array( 'meaning' => 'City market page viewed', 'types' => array( 'market_page_view', 'market_page_viewed' ), 'status' => 'mapped' ),
			// Event Creation OS 3.0 (data carries method, provider and counts — never page content).
			'event_creation_method_selected' => array( 'meaning' => 'Organizer started a builder draft (method in data)', 'types' => array( 'creation_method_selected' ), 'status' => 'mapped' ),
			'event_builder_published'    => array( 'meaning' => 'Event, recurring series, tour or series published from the builder', 'types' => array( 'event_published', 'portfolio_published' ), 'status' => 'mapped' ),
			'event_import_previewed'     => array( 'meaning' => 'Listing link read for import (provider in data)', 'types' => array( 'import_previewed' ), 'status' => 'mapped' ),
			'event_imported'             => array( 'meaning' => 'Draft created from an imported listing', 'types' => array( 'event_imported' ), 'status' => 'mapped' ),
			'event_import_linked'        => array( 'meaning' => 'Listing linked to an existing LNKup event', 'types' => array( 'import_associated' ), 'status' => 'mapped' ),
			'event_bulk_import_applied'  => array( 'meaning' => 'Drafts created from a bulk import', 'types' => array( 'bulk_import_applied' ), 'status' => 'mapped' ),
			'event_provider_connected'   => array( 'meaning' => 'Organizer connected a ticketing account', 'types' => array( 'provider_connected' ), 'status' => 'mapped' ),
			'event_template_saved'       => array( 'meaning' => 'Organizer saved a builder template', 'types' => array( 'template_saved' ), 'status' => 'mapped' ),
			'official_listing_click'     => array( 'meaning' => 'Visitor opened an event\'s official ticketing listing', 'types' => array( 'official_listing_click' ), 'status' => 'mapped' ),
		);
	}

	public static function init(): void {
		add_filter( 'lnkup_conversion_registry', array( __CLASS__, 'register_lead' ) );
		add_action( 'lnkup_analytics_tracked', array( __CLASS__, 'on_tracked' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::RETENTION_HOOK, array( __CLASS__, 'run_retention' ) );
	}

	/**
	 * Analytics row with the v1 contract fields.
	 *
	 * @param array<string,mixed> $data Event data (no personal data).
	 */
	public static function emit( string $type, array $data = array(), int $user_id = 0, int $opp_id = 0, bool $anonymous = false ): string {
		global $wpdb;
		$event_id = class_exists( 'LNKup_Conversion_Registry' ) ? LNKup_Conversion_Registry::uuid() : wp_generate_uuid4();
		$data     = array_merge(
			$data,
			array(
				'_v'       => self::CONTRACT_VERSION,
				'event_id' => $event_id,
				'env'      => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			)
		);
		if ( $anonymous ) {
			// track() falls back to the signed-in user; anonymous signals must never carry one.
			$wpdb->insert( LNKup_Marketplace_Analytics::table_name(), array( 'event_type' => sanitize_key( $type ), 'event_data' => wp_json_encode( $data ), 'user_id' => 0, 'opportunity_id' => absint( $opp_id ), 'created_at' => current_time( 'mysql' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			do_action( 'lnkup_analytics_tracked', sanitize_key( $type ), $data, 0, absint( $opp_id ) );
		} elseif ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( $type, $data, $user_id, $opp_id );
		}
		return $event_id;
	}

	/**
	 * Counts per spec event over a window: raw events plus daily rollups for purged days.
	 *
	 * @return array<string,array{meaning:string,status:string,count:int}>
	 */
	public static function counts( string $from, string $to ): array {
		global $wpdb;
		$types = array();
		foreach ( self::map() as $spec ) {
			$types = array_merge( $types, $spec['types'] );
		}
		$types = array_values( array_unique( $types ) );
		$raw   = array();
		// Days up to the rollup watermark are read from the rollup, later days from raw rows.
		$until = (string) get_option( 'lnkup_analytics_rollup_until', '0000-00-00' );
		if ( $types ) {
			$in       = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$raw_from = max( $from, gmdate( 'Y-m-d', strtotime( $until . ' +1 day' ) ?: 0 ) );
			$sql      = 'SELECT event_type, COUNT(*) n FROM ' . LNKup_Marketplace_Analytics::table_name() . " WHERE event_type IN ({$in}) AND created_at BETWEEN %s AND %s GROUP BY event_type";
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( $raw_from . ' 00:00:00', $to . ' 23:59:59' ) ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$raw[ $r['event_type'] ] = (int) $r['n'];
			}
			if ( $from <= $until ) {
				$sql = 'SELECT event_type, SUM(events) n FROM ' . LNKup_Marketing_Tables::analytics_daily() . " WHERE event_type IN ({$in}) AND day BETWEEN %s AND %s GROUP BY event_type";
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( $from, min( $to, $until ) ) ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					$raw[ $r['event_type'] ] = ( $raw[ $r['event_type'] ] ?? 0 ) + (int) $r['n'];
				}
			}
		}
		$out = array();
		foreach ( self::map() as $name => $spec ) {
			$count = 0;
			foreach ( $spec['types'] as $t ) {
				$count += (int) ( $raw[ $t ] ?? 0 );
			}
			if ( 'referral_conversion' === $name ) {
				$count = self::referral_conversions( $from, $to );
			}
			$out[ $name ] = array( 'meaning' => $spec['meaning'], 'status' => $spec['status'], 'count' => $count );
		}
		return $out;
	}

	private static function referral_conversions( string $from, string $to ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'lnkup_referral_rewards';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s AND status NOT IN ('reversed','void','cancelled')", $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------ Lead conversion */

	/**
	 * Meta standard "Lead": a vendor asks for a spot at an external event or turns on a market alert.
	 * Once per user and entity. The Pixel allowlist already includes Lead.
	 *
	 * @param array<string,array<string,mixed>> $defs Definitions.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_lead( $defs ): array {
		$defs         = is_array( $defs ) ? $defs : array();
		$defs['Lead'] = array( 'standard' => true, 'scope' => 'item', 'browser' => true, 'server' => true, 'mirror' => true, 'optimize' => true, 'role' => 'vendor' );
		return $defs;
	}

	/**
	 * @param string              $type    Analytics type.
	 * @param array<string,mixed> $data    Data.
	 * @param int                 $user_id User.
	 */
	public static function on_tracked( $type, $data, $user_id, $opp_id = 0 ): void {
		if ( ! class_exists( 'LNKup_Conversion_Registry' ) || (int) $user_id < 1 ) {
			return;
		}
		if ( 'external_event_interest_submitted' === $type && ! empty( $data['external_event_id'] ) ) {
			LNKup_Conversion_Registry::record(
				'Lead',
				array(
					'user_id'     => (int) $user_id,
					'entity_type' => 'external_event',
					'entity_id'   => absint( $data['external_event_id'] ),
					'custom_data' => array( 'content_category' => 'external_event_interest', 'marketplace_role' => 'vendor' ),
				)
			);
		} elseif ( 'market_alert_created' === $type && ! empty( $data['market'] ) ) {
			LNKup_Conversion_Registry::record(
				'Lead',
				array(
					'user_id'     => (int) $user_id,
					'entity_type' => 'market',
					'entity_id'   => (int) ( crc32( (string) $data['market'] ) & 0x7fffffff ),
					'custom_data' => array( 'content_category' => 'market_alert', 'campaign_market' => sanitize_title( (string) $data['market'] ), 'marketplace_role' => 'vendor' ),
				)
			);
		}
	}

	/* ------------------------------------------------------------ Retention */

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::RETENTION_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:20' ), 'daily', self::RETENTION_HOOK );
		}
	}

	public static function purge_enabled(): bool {
		$settings = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		return ! empty( $settings['analytics_retention_purge'] );
	}

	/**
	 * Roll up whole days older than RETENTION_MONTHS into lnkup_analytics_daily (replace semantics,
	 * so re-running a day is idempotent), then delete those raw rows only when purge is enabled.
	 *
	 * @return array{days:int,purged:int}
	 */
	public static function run_retention( ?string $cutoff = null ): array {
		global $wpdb;
		$raw    = LNKup_Marketplace_Analytics::table_name();
		$daily  = LNKup_Marketing_Tables::analytics_daily();
		$cutoff = $cutoff ?? gmdate( 'Y-m-d', strtotime( '-' . self::RETENTION_MONTHS . ' months' ) );
		$purge  = self::purge_enabled();
		$after  = $purge ? '0000-00-00' : (string) get_option( 'lnkup_analytics_rollup_until', '0000-00-00' );
		$days   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT DATE(created_at) d FROM {$raw} WHERE created_at > %s AND created_at < %s ORDER BY d ASC LIMIT %d", $after . ' 23:59:59', $cutoff . ' 00:00:00', self::ROLLUP_DAYS_PER_RUN ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$purged = 0;
		foreach ( $days as $day ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (day, event_type, events, users) SELECT DATE(created_at), event_type, COUNT(*), COUNT(DISTINCT NULLIF(user_id,0)) FROM {$raw} WHERE created_at BETWEEN %s AND %s GROUP BY DATE(created_at), event_type ON DUPLICATE KEY UPDATE events = VALUES(events), users = VALUES(users)", $day . ' 00:00:00', $day . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $purge ) {
				$purged += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$raw} WHERE created_at BETWEEN %s AND %s", $day . ' 00:00:00', $day . ' 23:59:59' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( $days ) {
			update_option( 'lnkup_analytics_rollup_until', (string) end( $days ), false );
		}
		return array( 'days' => count( $days ), 'purged' => $purged );
	}
}
