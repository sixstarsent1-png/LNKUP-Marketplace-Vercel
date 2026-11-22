<?php
/**
 * Partner program registry.
 *
 * Every referral, reward and rule row carries a program slug from this registry. Live programs
 * keep their existing on/off settings; placeholder programs stay disabled until a later stage
 * ships their onboarding.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Partner_Programs {

	const CITY_CONNECTOR    = 'city_connector';
	const ORGANIZER_RECRUIT = 'organizer_recruit';
	const VENDOR_REFERRAL   = 'vendor_referral';
	const AFFILIATE         = 'affiliate';
	const STRATEGIC_PARTNER = 'strategic_partner';

	/**
	 * Programs whose members hold a partner account (a row in lnkup_connectors) and are paid
	 * manually from the reward ledger.
	 */
	const ACCOUNT_PROGRAMS = array( self::CITY_CONNECTOR, self::AFFILIATE, self::STRATEGIC_PARTNER );

	/**
	 * Programs that accept public applications.
	 */
	const APPLICATION_PROGRAMS = array( self::AFFILIATE, self::STRATEGIC_PARTNER );

	public static function is_account_program( string $slug ): bool {
		return in_array( $slug, self::ACCOUNT_PROGRAMS, true );
	}

	/**
	 * SQL IN list for account programs (constants only, safe to interpolate).
	 */
	public static function account_programs_sql(): string {
		return "'" . implode( "','", self::ACCOUNT_PROGRAMS ) . "'";
	}

	/**
	 * Seed definitions. `enabled_setting` maps a live program to its existing lnkup_settings key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			self::CITY_CONNECTOR    => array(
				'name'            => __( 'City Connector', 'lnkup-marketplace' ),
				'status'          => 'active',
				'enabled_setting' => 'connector_rewards_enabled',
			),
			self::ORGANIZER_RECRUIT => array(
				'name'            => __( 'Organizer & Vendor Referral', 'lnkup-marketplace' ),
				'status'          => 'active',
				'enabled_setting' => 'organizer_recruit_enabled',
			),
			self::VENDOR_REFERRAL   => array(
				'name'            => __( 'Vendor Referral Credits', 'lnkup-marketplace' ),
				'status'          => 'active',
				'enabled_setting' => '',
			),
			self::AFFILIATE         => array(
				'name'            => __( 'Professional Affiliate', 'lnkup-marketplace' ),
				'status'          => 'active',
				'enabled_setting' => '',
			),
			self::STRATEGIC_PARTNER => array(
				'name'            => __( 'Strategic Partner', 'lnkup-marketplace' ),
				'status'          => 'active',
				'enabled_setting' => '',
			),
			'enterprise_partner'    => array(
				'name'            => __( 'Enterprise / Network Partner', 'lnkup-marketplace' ),
				'status'          => 'disabled',
				'enabled_setting' => '',
			),
		);
	}

	/**
	 * Insert missing program rows. Never overwrites an existing row.
	 */
	public static function seed(): void {
		global $wpdb;
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::PROGRAMS );
		$now   = current_time( 'mysql' );
		foreach ( self::definitions() as $slug => $def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) );
			if ( $exists ) {
				continue;
			}
			$wpdb->insert(
				$table,
				array(
					'slug'       => $slug,
					'name'       => $def['name'],
					'status'     => $def['status'],
					'settings'   => wp_json_encode( array( 'enabled_setting' => $def['enabled_setting'] ) ),
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		global $wpdb;
		$slug = sanitize_key( $slug );
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::PROGRAMS ) ) {
			$defs = self::definitions();
			return isset( $defs[ $slug ] ) ? array_merge( array( 'slug' => $slug ), $defs[ $slug ] ) : null;
		}
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::PROGRAMS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		global $wpdb;
		if ( ! LNKup_Partner_Tables::exists( LNKup_Partner_Tables::PROGRAMS ) ) {
			return array();
		}
		$table = LNKup_Partner_Tables::table( LNKup_Partner_Tables::PROGRAMS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * Program is active in the registry and, for live programs, its legacy setting is on.
	 */
	public static function is_enabled( string $slug ): bool {
		$row = self::get( $slug );
		if ( ! $row || 'active' !== ( $row['status'] ?? '' ) ) {
			return false;
		}
		switch ( $slug ) {
			case self::CITY_CONNECTOR:
				return class_exists( 'LNKup_Connector_Rewards' ) && LNKup_Connector_Rewards::terms()['enabled'];
			case self::ORGANIZER_RECRUIT:
				return class_exists( 'LNKup_Organizer_Recruit_Referrals' ) && LNKup_Organizer_Recruit_Referrals::is_enabled();
			case self::AFFILIATE:
			case self::STRATEGIC_PARTNER:
				return null !== LNKup_Commission_Rules::active_rule( $slug );
			default:
				return true;
		}
	}

	/**
	 * Whether an application program is open in the registry.
	 */
	public static function accepts_applications( string $slug ): bool {
		$row = self::get( $slug );
		return in_array( $slug, self::APPLICATION_PROGRAMS, true ) && $row && 'active' === ( $row['status'] ?? '' );
	}

	/**
	 * Human label for a program slug.
	 */
	public static function label( string $slug ): string {
		$defs = self::definitions();
		return isset( $defs[ $slug ] ) ? (string) $defs[ $slug ]['name'] : ucwords( str_replace( '_', ' ', $slug ) );
	}
}
