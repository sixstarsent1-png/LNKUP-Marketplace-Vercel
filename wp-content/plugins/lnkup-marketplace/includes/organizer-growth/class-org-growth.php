<?php
/**
 * Organizer Acquisition OS — module entry point, settings and access.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth {

	const CAP            = 'lnkup_manage_organizer_growth';
	const SETTINGS_KEY   = 'lnkup_org_growth_settings';
	const SETTING_KEYS   = array(
		'organizer_growth_enabled'          => 'bool',
		'organizer_growth_outreach_enabled' => 'bool',
		'org_growth_house_organizer_ids'    => 'ids',
		'org_growth_outreach_dedupe_days'   => 'int',
		'org_growth_campaign_daily_cap'     => 'int',
		'org_growth_approval_threshold'     => 'int',
		'org_growth_contact_stale_days'     => 'int',
		'org_growth_sender_name'            => 'text',
		'org_growth_sender_postal_address'  => 'textarea',
		'org_growth_import_max_rows'        => 'int',
		'org_growth_activation_paid_bookings' => 'int',
		'org_growth_supply_min_active_vendors' => 'int',
		'org_growth_supply_surplus_ratio'      => 'int',
	);

	public static function init(): void {
		LNKup_Org_Growth_Tables::maybe_upgrade();
		LNKup_Org_Lead_Lifecycle::init();
		LNKup_Org_Outreach::init();
		LNKup_Org_Lead_Import::init();
		LNKup_Org_Lead_Tasks::init();
		LNKup_Org_Growth_REST::init();
		LNKup_Org_Supply_Intel::init();
		add_action( 'lnkup_marketplace_daily', array( 'LNKup_Org_Lead_Scoring', 'rescore_stale' ), 50 );
		add_action( 'lnkup_org_growth_rescore', array( 'LNKup_Org_Lead_Scoring', 'run_rescore' ) );
		add_action(
			'lnkup_marketplace_daily',
			static function (): void {
				update_option( 'lnkup_org_growth_daily_last_run', current_time( 'mysql' ), false );
			},
			999
		);
	}

	/**
	 * Go-live readiness checks for the Settings tab (read-only).
	 *
	 * @return array<int, array{key:string,label:string,ok:bool,detail:string}>
	 */
	public static function readiness(): array {
		$settings  = self::settings();
		$base      = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$house     = self::house_organizer_ids();
		$last_run  = (string) get_option( 'lnkup_org_growth_daily_last_run', '' );
		$next_run  = wp_next_scheduled( 'lnkup_marketplace_daily' );
		$backfill  = class_exists( 'LNKup_Platform_Fees' ) ? LNKup_Platform_Fees::backfill_candidates() : array();
		$pending   = array_sum( array_map( 'count', $backfill ) );
		$stale_run = '' === $last_run || strtotime( $last_run ) < (int) current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS;
		$checks    = array(
			array(
				'key'    => 'postal_address',
				'label'  => __( 'Sender postal address (required for outreach)', 'lnkup-marketplace' ),
				'ok'     => '' !== trim( (string) $settings['org_growth_sender_postal_address'] ),
				'detail' => '' !== trim( (string) $settings['org_growth_sender_postal_address'] ) ? __( 'Set', 'lnkup-marketplace' ) : __( 'Missing — campaigns cannot be queued', 'lnkup-marketplace' ),
			),
			array(
				'key'    => 'house_ids',
				'label'  => __( 'House-affiliated organizers (Six Stars) configured', 'lnkup-marketplace' ),
				'ok'     => (bool) $house,
				'detail' => $house ? implode( ', ', array_map( static fn( $id ) => '#' . $id, $house ) ) : __( 'Not set — every organizer counts as independent', 'lnkup-marketplace' ),
			),
			array(
				'key'    => 'comms',
				'label'  => __( 'Communications plugin running', 'lnkup-marketplace' ),
				'ok'     => did_action( 'lnkup_comms_loaded' ) > 0,
				'detail' => did_action( 'lnkup_comms_loaded' ) > 0 ? __( 'Loaded — suppression is mirrored', 'lnkup-marketplace' ) : __( 'Not loaded — growth suppression still works on its own', 'lnkup-marketplace' ),
			),
			array(
				'key'    => 'cron',
				'label'  => __( 'Daily jobs (scoring, follow-up digest)', 'lnkup-marketplace' ),
				'ok'     => (bool) $next_run && ! $stale_run,
				'detail' => ( '' !== $last_run ? sprintf( /* translators: %s: datetime */ __( 'Last ran %s', 'lnkup-marketplace' ), $last_run ) : __( 'Has not run yet', 'lnkup-marketplace' ) ) . ' · ' . ( $next_run ? sprintf( /* translators: %s: datetime */ __( 'next %s', 'lnkup-marketplace' ), wp_date( 'Y-m-d H:i', $next_run ) ) : __( 'NOT SCHEDULED', 'lnkup-marketplace' ) ),
			),
			array(
				'key'    => 'fee_backfill',
				'label'  => __( 'Refunded platform fees recorded (net revenue accurate)', 'lnkup-marketplace' ),
				'ok'     => 0 === $pending,
				'detail' => 0 === $pending ? __( 'Up to date', 'lnkup-marketplace' ) : sprintf( /* translators: 1: bookings, 2: organizers */ __( '%1$d refunded bookings across %2$d organizers need the backfill (LNKup → Reconciliation)', 'lnkup-marketplace' ), $pending, count( $backfill ) ),
			),
			array(
				'key'    => 'outreach_global',
				'label'  => __( 'Platform outreach emails enabled (LNKup → Settings)', 'lnkup-marketplace' ),
				'ok'     => ! empty( $base['outreach_emails_enabled'] ),
				'detail' => ! empty( $base['outreach_emails_enabled'] ) ? __( 'On', 'lnkup-marketplace' ) : __( 'Off — no outreach email will send', 'lnkup-marketplace' ),
			),
		);
		return $checks;
	}

	/**
	 * Growth settings: plugin defaults (lnkup_settings) overlaid by the module option.
	 *
	 * Kept in a separate option because the main settings form re-sanitizes lnkup_settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		$base = function_exists( 'lnkup_get_settings' ) ? lnkup_get_settings() : array();
		$own  = get_option( self::SETTINGS_KEY, array() );
		$out  = array();
		foreach ( array_keys( self::SETTING_KEYS ) as $key ) {
			$out[ $key ] = is_array( $own ) && array_key_exists( $key, $own ) ? $own[ $key ] : ( $base[ $key ] ?? null );
		}
		return $out;
	}

	public static function setting( string $key ) {
		$all = self::settings();
		return $all[ $key ] ?? null;
	}

	/**
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed> Saved settings.
	 */
	public static function update_settings( array $input ): array {
		$current = get_option( self::SETTINGS_KEY, array() );
		$current = is_array( $current ) ? $current : array();
		foreach ( self::SETTING_KEYS as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = $input[ $key ];
			switch ( $type ) {
				case 'bool':
					$current[ $key ] = rest_sanitize_boolean( $value );
					break;
				case 'int':
					$current[ $key ] = max( 0, (int) $value );
					break;
				case 'ids':
					$ids             = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $value ) ?: array() ) );
					$current[ $key ] = implode( ',', array_unique( $ids ) );
					break;
				case 'textarea':
					$current[ $key ] = sanitize_textarea_field( (string) $value );
					break;
				default:
					$current[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		update_option( self::SETTINGS_KEY, $current, false );
		if ( array_key_exists( 'org_growth_house_organizer_ids', $input ) ) {
			LNKup_Org_Leads::refresh_house_flags();
		}
		LNKup_Org_Growth_Metrics::flush_cache();
		return self::settings();
	}

	public static function is_enabled(): bool {
		return (bool) self::setting( 'organizer_growth_enabled' );
	}

	public static function outreach_enabled(): bool {
		return self::is_enabled() && (bool) self::setting( 'organizer_growth_outreach_enabled' );
	}

	public static function current_user_can_manage(): bool {
		return current_user_can( self::CAP ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() );
	}

	/**
	 * @return int[] House-affiliated (e.g. Six Stars) organizer user IDs.
	 */
	public static function house_organizer_ids(): array {
		$raw = (string) self::setting( 'org_growth_house_organizer_ids' );
		return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
	}

	/**
	 * Staff who can own leads (anyone holding the growth capability).
	 *
	 * @return array<int, array{id:int,name:string}>
	 */
	public static function team_members(): array {
		$users = get_users(
			array(
				'capability' => self::CAP,
				'fields'     => array( 'ID', 'display_name' ),
				'number'     => 200,
			)
		);
		$out = array();
		foreach ( $users as $user ) {
			$out[] = array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			);
		}
		return $out;
	}

	/**
	 * Active City Connectors (assignment targets; they cannot read the CRM in this phase).
	 *
	 * @return array<int, array{id:int,name:string,city:string}>
	 */
	public static function connectors(): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Connector_Tables' ) ) {
			return array();
		}
		$table = LNKup_Connector_Tables::connectors_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		if ( ! $exists ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, user_id, display_name, city, state FROM {$table} WHERE status = 'active' ORDER BY display_name ASC LIMIT 300", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$name  = (string) $row['display_name'];
			$out[] = array(
				'id'   => (int) $row['id'],
				'name' => '' !== $name ? $name : sprintf( 'Connector #%d', (int) $row['id'] ),
				'city' => trim( (string) $row['city'] . ( '' !== (string) $row['state'] ? ', ' . (string) $row['state'] : '' ) ),
			);
		}
		return $out;
	}

	public static function now(): string {
		return current_time( 'mysql' );
	}
}
