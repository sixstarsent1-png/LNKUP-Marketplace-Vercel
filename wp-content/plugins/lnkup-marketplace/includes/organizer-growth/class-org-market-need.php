<?php
/**
 * Market need for Organizer Acquisition (Matching Phase 7).
 *
 * Combines Organizer Growth's vendor supply signal with matching's market liquidity signal
 * (LNKup_Match_Market) per city, and feeds it into lead work:
 * - the `market_need` lead-scoring factor,
 * - the `market_signal` lead filter and a shared saved view,
 * - optional research tasks when a city starts needing organizers (setting, off by default).
 *
 * Every figure is a count from the latest snapshots; nothing is inferred.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Market_Need {

	const NEED_ORGANIZERS = 'need_organizers';
	const NEED_VENDORS    = 'need_vendors';
	const FILTERS         = array( self::NEED_ORGANIZERS, self::NEED_VENDORS );
	const STATE_OPTION    = 'lnkup_org_market_need_cities';
	const VIEW_OPTION     = 'lnkup_org_market_need_view_seeded';
	const TASK_DAILY_MAX  = 50;
	const TASK_SETTING    = 'org_growth_market_tasks';

	/** @var array<string, array<string, mixed>>|null */
	private static $cache = null;

	public static function init(): void {
		// Today's snapshots (44, 45) are built before rescoring (50) reads them.
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'flush' ), 49 );
		add_action( 'lnkup_marketplace_daily', array( __CLASS__, 'daily' ), 52 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_saved_view' ) );
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Per city key: Organizer Growth signal, match signal and the counts behind them.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function cities(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		global $wpdb;
		$out = array();
		if ( LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::SUPPLY ) ) {
			$date = LNKup_Org_Supply_Intel::latest_date();
			if ( '' !== $date ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Org_Supply_Intel::table() . " WHERE snapshot_date = %s AND term_id = 0 AND city_key <> '_national'", $date ), ARRAY_A ) as $row ) {
					$k                         = (string) $row['city_key'];
					$out[ $k ]['label']        = trim( $row['city'] . ', ' . $row['state'], ', ' );
					$out[ $k ]['org_signal']   = LNKup_Org_Supply_Intel::signal( $row );
					$out[ $k ]['vendors_active'] = (int) $row['vendors_active'];
					$out[ $k ]['open_slots']   = (int) $row['spots_remaining'];
				}
			}
		}
		if ( class_exists( 'LNKup_Match_Market' ) && LNKup_Match_Tables::exists( LNKup_Match_Market::table() ) ) {
			$date = LNKup_Match_Market::latest_date();
			if ( '' !== $date ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Match_Market::table() . ' WHERE snapshot_date = %s AND term_id = 0', $date ), ARRAY_A ) as $row ) {
					$k     = (string) $row['city_key'];
					$count = array_map( 'intval', array_intersect_key( $row, array_flip( LNKup_Match_Market::COUNT_COLUMNS ) ) );
					$out[ $k ]['label']        = $out[ $k ]['label'] ?? trim( $row['city'] . ', ' . $row['state'], ', ' );
					$out[ $k ]['match_signal'] = LNKup_Match_Market::signal( $count );
					$out[ $k ]['match']        = $count;
				}
			}
		}
		foreach ( $out as $k => $row ) {
			$out[ $k ]['need'] = self::need( $row );
		}
		self::$cache = $out;
		return $out;
	}

	/**
	 * need_organizers, need_vendors or ''.
	 *
	 * @param array<string, mixed> $row City row.
	 */
	private static function need( array $row ): string {
		$org   = (string) ( $row['org_signal'] ?? '' );
		$match = (string) ( $row['match_signal'] ?? '' );
		if ( 'recruit_organizers' === $org || in_array( $match, array( 'idle_supply', 'unmet_search' ), true ) ) {
			return self::NEED_ORGANIZERS;
		}
		if ( 'recruit_vendors' === $org || 'thin_supply' === $match ) {
			return self::NEED_VENDORS;
		}
		return '';
	}

	/**
	 * City keys with a given need (for the lead filter).
	 *
	 * @return string[]
	 */
	public static function city_keys( string $need ): array {
		return array_keys( array_filter( self::cities(), static fn( $row ) => $row['need'] === $need ) );
	}

	/**
	 * Lead scoring share (0, 0.5 or 1) and plain-language detail for a lead's city.
	 *
	 * Full points: the city needs organizers. Half: some unmet vendor demand (idle matching
	 * vendors or searches that found nothing) without a full signal yet.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array{0:float,1:string}
	 */
	public static function factor( array $lead ): array {
		$city = trim( (string) ( $lead['city'] ?? '' ) );
		if ( '' === $city ) {
			return array( 0.0, 'City not recorded' );
		}
		$row = self::cities()[ LNKup_Org_Supply_Intel::key( $city, (string) ( $lead['state'] ?? '' ) ) ] ?? null;
		if ( ! $row ) {
			return array( 0.0, 'No supply data for this city yet' );
		}
		$idle   = (int) ( $row['match']['idle_vendors'] ?? 0 );
		$zero   = (int) ( $row['match']['zero_result_searches_30d'] ?? 0 );
		$active = (int) ( $row['vendors_active'] ?? 0 );
		$open   = (int) ( $row['open_slots'] ?? ( $row['match']['open_opportunities'] ?? 0 ) );
		$facts  = sprintf( '%d active vendors, %d open booths, %d matching vendors with nothing to apply to, %d searches found nothing (30 days)', $active, $open, $idle, $zero );
		if ( self::NEED_ORGANIZERS === $row['need'] ) {
			return array( 1.0, 'Market needs organizers: ' . $facts );
		}
		if ( $idle > 0 || $zero > 0 ) {
			return array( 0.5, 'Some unmet vendor demand: ' . $facts );
		}
		return array( 0.0, 'No unmet vendor demand: ' . $facts );
	}

	/**
	 * Shared saved view for the leads list (created once).
	 */
	public static function ensure_saved_view(): void {
		global $wpdb;
		if ( get_option( self::VIEW_OPTION ) || ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::VIEWS ) ) {
			return;
		}
		$wpdb->insert(
			LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::VIEWS ),
			array(
				'user_id'    => 0,
				'name'       => __( 'Leads in markets that need organizers', 'lnkup-marketplace' ),
				'filters'    => wp_json_encode( array( 'market_signal' => self::NEED_ORGANIZERS ) ),
				'is_shared'  => 1,
				'created_at' => LNKup_Org_Growth::now(),
			)
		);
		update_option( self::VIEW_OPTION, 1, false );
	}

	/**
	 * Daily: remember which cities need organizers and, when the setting is on, open research
	 * tasks for leads in cities that just started needing them.
	 *
	 * @return int Tasks created.
	 */
	public static function daily(): int {
		self::flush();
		$now      = self::city_keys( self::NEED_ORGANIZERS );
		$previous = get_option( self::STATE_OPTION, null );
		update_option( self::STATE_OPTION, $now, false );
		// First run only records the baseline, so turning this on never floods existing cities.
		if ( ! is_array( $previous ) || ! LNKup_Org_Growth::is_enabled() || ! LNKup_Org_Growth::setting( self::TASK_SETTING ) ) {
			return 0;
		}
		return self::create_tasks( array_values( array_diff( $now, $previous ) ) );
	}

	/**
	 * One research task per open lead in these cities (max 50 per run), never twice in a month.
	 *
	 * @param string[] $city_keys Cities that started needing organizers.
	 * @return int Tasks created.
	 */
	public static function create_tasks( array $city_keys ): int {
		global $wpdb;
		if ( ! $city_keys ) {
			return 0;
		}
		$tag     = 'market-need-' . gmdate( 'Y-m' );
		$tags    = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TAGS );
		$created = 0;
		$cities  = self::cities();
		foreach ( $city_keys as $key ) {
			if ( $created >= self::TASK_DAILY_MAX ) {
				break;
			}
			list( $city, $state ) = array_pad( explode( '|', $key ), 2, '' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$lead_ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT l.id FROM ' . LNKup_Org_Leads::table() . " l WHERE l.merged_into_id = 0 AND l.outcome = '' AND LOWER(l.city) = %s AND UPPER(l.state) = %s AND NOT EXISTS (SELECT 1 FROM {$tags} t WHERE t.lead_id = l.id AND t.tag = %s) ORDER BY l.score DESC LIMIT %d", $city, $state, $tag, self::TASK_DAILY_MAX - $created ) );
			$label    = (string) ( $cities[ $key ]['label'] ?? $city );
			foreach ( $lead_ids as $lead_id ) {
				$task = LNKup_Org_Lead_Tasks::create(
					(int) $lead_id,
					array(
						'type'   => 'research',
						'title'  => sprintf( __( 'Market needs organizers: %s', 'lnkup-marketplace' ), $label ),
						'notes'  => self::factor( (array) LNKup_Org_Leads::get_row( (int) $lead_id ) )[1],
						'due_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					),
					0
				);
				if ( ! is_wp_error( $task ) ) {
					LNKup_Org_Leads::add_tags( (int) $lead_id, array( $tag ) );
					++$created;
				}
			}
		}
		return $created;
	}

	/**
	 * Matching figures for the lead drawer / connector panel (null when no match snapshot).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function match_summary( string $city, string $state ): ?array {
		if ( ! class_exists( 'LNKup_Match_Market' ) ) {
			return null;
		}
		$row = LNKup_Match_Market::for_city( $city, $state );
		if ( ! $row ) {
			return null;
		}
		return array(
			'signal'               => $row['signal'],
			'signal_label'         => $row['signal_label'],
			'matched_vendors'      => $row['matched_vendors'],
			'idle_vendors'         => $row['idle_vendors'],
			'thin_booths'          => $row['opps_thin'],
			'zero_result_searches' => $row['zero_result_searches_30d'],
		);
	}
}
