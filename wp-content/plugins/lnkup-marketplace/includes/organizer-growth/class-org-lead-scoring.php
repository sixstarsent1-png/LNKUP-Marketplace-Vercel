<?php
/**
 * Explainable organizer lead scoring.
 *
 * Every point comes from a recorded fact on the lead (or a real count of vendor profiles in
 * the lead's city). Nothing is inferred about revenue or attendance. Admins tune weights and
 * tier thresholds; each lead stores the breakdown that produced its score.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Lead_Scoring {

	const OPTION = 'lnkup_org_lead_scoring_weights';

	const FACTORS = array(
		'recurring'           => array( 'label' => 'Produces recurring events', 'weight' => 20 ),
		'multiple_events'     => array( 'label' => 'Operates multiple events or brands', 'weight' => 10 ),
		'multi_city'          => array( 'label' => 'Operates in multiple cities', 'weight' => 10 ),
		'category_fit'        => array( 'label' => 'Event categories match LNKup vendor supply', 'weight' => 15 ),
		'active_applications' => array( 'label' => 'Has an active vendor application process', 'weight' => 10 ),
		'upcoming_event'      => array( 'label' => 'Has a publicly confirmed upcoming event', 'weight' => 10 ),
		'vendor_capacity'     => array( 'label' => 'Sourced vendor booth capacity', 'weight' => 10 ),
		'manual_workflow'     => array( 'label' => 'Uses a manual / fragmented application workflow', 'weight' => 5 ),
		'verified_contact'    => array( 'label' => 'Has a verified, current business contact', 'weight' => 5 ),
		'vendor_market'       => array( 'label' => 'Operates in an existing LNKup vendor market', 'weight' => 5 ),
	);

	const DEFAULT_TIERS = array(
		'tier_a'                => 60,
		'tier_b'                => 35,
		'vendor_market_minimum' => 10,
	);

	const RECURRING_FREQUENCIES = array( 'weekly', 'monthly', 'quarterly', 'recurring' );
	const MANUAL_WORKFLOW_HINTS = array( 'google form', 'jotform', 'typeform', 'email', 'paper', 'pdf', 'manual', 'dm', 'instagram', 'facebook', 'spreadsheet', 'cognito', 'wufoo' );

	/**
	 * @return array{weights:array<string,int>,tier_a:int,tier_b:int,vendor_market_minimum:int}
	 */
	public static function config(): array {
		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$weights = array();
		foreach ( self::FACTORS as $key => $factor ) {
			$weights[ $key ] = isset( $saved['weights'][ $key ] ) ? max( 0, min( 100, (int) $saved['weights'][ $key ] ) ) : (int) $factor['weight'];
		}
		return array(
			'weights'               => $weights,
			'tier_a'                => (int) ( $saved['tier_a'] ?? self::DEFAULT_TIERS['tier_a'] ),
			'tier_b'                => (int) ( $saved['tier_b'] ?? self::DEFAULT_TIERS['tier_b'] ),
			'vendor_market_minimum' => (int) ( $saved['vendor_market_minimum'] ?? self::DEFAULT_TIERS['vendor_market_minimum'] ),
		);
	}

	/**
	 * @param array<string, mixed> $input weights{}, tier_a, tier_b, vendor_market_minimum.
	 * @return array<string, mixed>
	 */
	public static function save_config( array $input ): array {
		$current = self::config();
		foreach ( self::FACTORS as $key => $factor ) {
			if ( isset( $input['weights'][ $key ] ) ) {
				$current['weights'][ $key ] = max( 0, min( 100, (int) $input['weights'][ $key ] ) );
			}
		}
		foreach ( array_keys( self::DEFAULT_TIERS ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$current[ $key ] = max( 0, (int) $input[ $key ] );
			}
		}
		if ( $current['tier_b'] > $current['tier_a'] ) {
			$current['tier_b'] = $current['tier_a'];
		}
		update_option( self::OPTION, $current, false );
		self::schedule_rescore_all();
		return self::describe();
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function describe(): array {
		$config  = self::config();
		$factors = array();
		foreach ( self::FACTORS as $key => $factor ) {
			$factors[] = array(
				'key'    => $key,
				'label'  => $factor['label'],
				'weight' => $config['weights'][ $key ],
			);
		}
		return array(
			'factors'               => $factors,
			'tier_a'                => $config['tier_a'],
			'tier_b'                => $config['tier_b'],
			'vendor_market_minimum' => $config['vendor_market_minimum'],
			'max_score'             => array_sum( $config['weights'] ),
		);
	}

	/**
	 * Score a lead row without saving.
	 *
	 * @param array<string, mixed> $lead Lead row.
	 * @return array{score:int,tier:string,explanation:array<int,array<string,mixed>>}
	 */
	public static function evaluate( array $lead, array $context = array() ): array {
		$config  = self::config();
		$w       = $config['weights'];
		$items   = array();
		$add     = static function ( string $key, float $share, string $detail ) use ( &$items, $w ): void {
			$items[] = array(
				'factor' => $key,
				'label'  => self::FACTORS[ $key ]['label'],
				'points' => (int) round( $w[ $key ] * max( 0.0, min( 1.0, $share ) ) ),
				'max'    => $w[ $key ],
				'detail' => $detail,
			);
		};

		$frequency  = (string) ( $lead['event_frequency'] ?? '' );
		$org_type   = (string) ( $lead['org_type'] ?? '' );
		$event_count = (int) ( $context['event_count'] ?? 0 );
		$brand_count = (int) ( $context['brand_count'] ?? 0 );

		if ( in_array( $frequency, self::RECURRING_FREQUENCIES, true ) || 'event_series' === $org_type ) {
			$add( 'recurring', 1, '' !== $frequency ? sprintf( 'Frequency: %s', $frequency ) : 'Event series' );
		} elseif ( in_array( $frequency, array( 'seasonal', 'annual' ), true ) ) {
			$add( 'recurring', 0.5, sprintf( 'Frequency: %s', $frequency ) );
		} else {
			$add( 'recurring', 0, 'Frequency not recorded' );
		}

		if ( $event_count >= 2 || $brand_count >= 1 || 'multi_brand' === $org_type ) {
			$add( 'multiple_events', 1, sprintf( '%d known events, %d brands', $event_count, $brand_count ) );
		} else {
			$add( 'multiple_events', 0, sprintf( '%d known event(s)', $event_count ) );
		}

		$cities = self::split_list( (string) ( $lead['cities_served'] ?? '' ) );
		$add( 'multi_city', count( $cities ) >= 2 ? 1 : 0, $cities ? sprintf( 'Cities: %s', implode( ', ', array_slice( $cities, 0, 5 ) ) ) : 'Cities served not recorded' );

		$fit = self::category_matches( (string) ( $lead['event_categories'] ?? '' ) . ',' . (string) ( $lead['category'] ?? '' ) );
		$add( 'category_fit', $fit ? 1 : 0, $fit ? sprintf( 'Matches: %s', implode( ', ', array_slice( $fit, 0, 4 ) ) ) : 'No matching vendor categories recorded' );

		$process = trim( (string) ( $lead['application_process'] ?? '' ) );
		$app_url = trim( (string) ( $lead['application_url'] ?? '' ) );
		$add( 'active_applications', ( '' !== $app_url || '' !== $process ) ? 1 : 0, '' !== $app_url ? 'Vendor application URL on file' : ( '' !== $process ? 'Application process recorded' : 'No application process recorded' ) );

		$next     = (string) ( $lead['next_event_date'] ?? '' );
		$upcoming = ( '' !== $next && $next >= gmdate( 'Y-m-d' ) ) || ! empty( $context['has_future_event'] );
		$add( 'upcoming_event', $upcoming ? 1 : 0, $upcoming ? ( '' !== $next ? sprintf( 'Next event %s', $next ) : 'Upcoming event on file' ) : 'No confirmed upcoming date' );

		$booths = (int) ( $lead['estimated_booths'] ?? 0 );
		$add( 'vendor_capacity', $booths >= 50 ? 1 : ( $booths >= 20 ? 0.5 : 0 ), $booths > 0 ? sprintf( '~%d booths (sourced)', $booths ) : 'Booth count not sourced' );

		$lower  = strtolower( $process );
		$manual = '' !== $lower && (bool) array_filter( self::MANUAL_WORKFLOW_HINTS, static fn( $h ) => false !== strpos( $lower, $h ) );
		$add( 'manual_workflow', $manual ? 1 : 0, $manual ? sprintf( 'Recorded process: %s', substr( $process, 0, 60 ) ) : 'Not verified' );

		$verified   = (string) ( $lead['source_verified_at'] ?? '' );
		$stale_days = (int) LNKup_Org_Growth::setting( 'org_growth_contact_stale_days' );
		$fresh      = '' !== $verified && ( $stale_days <= 0 || strtotime( $verified ) >= time() - $stale_days * DAY_IN_SECONDS );
		$contact    = '' !== (string) ( $lead['email'] ?? '' ) || '' !== (string) ( $lead['phone'] ?? '' );
		$add( 'verified_contact', $contact && $fresh ? 1 : 0, $contact ? ( $fresh ? sprintf( 'Verified %s', substr( $verified, 0, 10 ) ) : 'Contact needs re-verification' ) : 'No business email or phone' );

		$vendors = self::vendor_count_for_city( (string) ( $lead['city'] ?? '' ), (string) ( $lead['state'] ?? '' ) );
		$minimum = max( 1, $config['vendor_market_minimum'] );
		$add( 'vendor_market', $vendors >= $minimum ? 1 : ( $vendors > 0 ? $vendors / $minimum : 0 ), sprintf( '%d LNKup vendor profiles in this city', $vendors ) );

		$score = array_sum( array_column( $items, 'points' ) );
		$tier  = $score >= $config['tier_a'] ? 'A' : ( $score >= $config['tier_b'] ? 'B' : 'C' );
		return array(
			'score'       => (int) $score,
			'tier'        => $tier,
			'explanation' => $items,
		);
	}

	public static function score_lead( int $lead_id ): void {
		global $wpdb;
		$lead = LNKup_Org_Leads::get_row( $lead_id );
		if ( ! $lead ) {
			return;
		}
		$t = static fn( string $s ): string => LNKup_Org_Growth_Tables::table( $s );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$context = array(
			'event_count'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::LEAD_EVENTS ) . ' WHERE lead_id = %d', $lead_id ) ),
			'has_future_event' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::LEAD_EVENTS ) . ' WHERE lead_id = %d AND event_date >= %s', $lead_id, gmdate( 'Y-m-d' ) ) ) > 0,
			'brand_count'      => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( LNKup_Org_Growth_Tables::LEADS ) . ' WHERE parent_lead_id = %d AND merged_into_id = 0', $lead_id ) ),
		);
		// phpcs:enable
		$result = self::evaluate( $lead, $context );
		$update = array(
			'score'             => $result['score'],
			'tier'              => $result['tier'],
			'score_explanation' => wp_json_encode( $result['explanation'] ),
			'scored_at'         => LNKup_Org_Growth::now(),
		);
		if ( '' === (string) $lead['priority'] ) {
			$update['priority'] = $result['tier'];
		}
		$wpdb->update( $t( LNKup_Org_Growth_Tables::LEADS ), $update, array( 'id' => $lead_id ) );
	}

	/**
	 * Daily refresh: leads not scored in the last day (bounded batch).
	 */
	public static function rescore_stale(): void {
		global $wpdb;
		if ( ! LNKup_Org_Growth_Tables::exists( LNKup_Org_Growth_Tables::LEADS ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . LNKup_Org_Leads::table() . ' WHERE merged_into_id = 0 AND (scored_at IS NULL OR scored_at < %s) ORDER BY scored_at ASC LIMIT 500', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		foreach ( $ids as $id ) {
			self::score_lead( (int) $id );
		}
	}

	public static function schedule_rescore_all(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'UPDATE ' . LNKup_Org_Leads::table() . ' SET scored_at = NULL' );
		if ( ! wp_next_scheduled( 'lnkup_org_growth_rescore' ) ) {
			wp_schedule_single_event( time() + 30, 'lnkup_org_growth_rescore' );
		}
	}

	/**
	 * Background rescore after weight changes; reschedules itself until done.
	 */
	public static function run_rescore(): void {
		self::rescore_stale();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . LNKup_Org_Leads::table() . ' WHERE merged_into_id = 0 AND scored_at IS NULL' );
		if ( $left > 0 ) {
			wp_schedule_single_event( time() + 60, 'lnkup_org_growth_rescore' );
		}
	}

	/**
	 * @return string[]
	 */
	public static function split_list( string $value ): array {
		$parts = preg_split( '/[,;|\/]+/', $value ) ?: array();
		return array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );
	}

	/**
	 * Vendor category names (lnkup_vendor_category) found in the lead's categories.
	 *
	 * @return string[]
	 */
	public static function category_matches( string $categories ): array {
		$wanted = array_map( 'strtolower', self::split_list( $categories ) );
		if ( ! $wanted || ! taxonomy_exists( 'lnkup_vendor_category' ) ) {
			return array();
		}
		$terms = get_transient( 'lnkup_org_growth_vendor_cats' );
		if ( ! is_array( $terms ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'lnkup_vendor_category',
					'hide_empty' => false,
					'fields'     => 'names',
				)
			);
			$terms = is_wp_error( $terms ) ? array() : array_map( 'strval', $terms );
			set_transient( 'lnkup_org_growth_vendor_cats', $terms, 6 * HOUR_IN_SECONDS );
		}
		$out = array();
		foreach ( $terms as $term ) {
			$lower = strtolower( $term );
			foreach ( $wanted as $w ) {
				if ( strlen( $w ) >= 3 && ( false !== strpos( $lower, $w ) || false !== strpos( $w, $lower ) ) ) {
					$out[] = $term;
					break;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Real count of published vendor profiles whose city matches (cached 12h).
	 */
	public static function vendor_count_for_city( string $city, string $state ): int {
		global $wpdb;
		$city = trim( $city );
		if ( '' === $city ) {
			return 0;
		}
		$key    = 'lnkup_org_vc_' . md5( strtolower( $city . '|' . $state ) );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		$sql  = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_lnkup_city' AND LOWER(c.meta_value) = %s";
		$args = array( strtolower( $city ) );
		if ( '' !== trim( $state ) ) {
			$sql   .= " LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_state'";
			$sql   .= " WHERE p.post_type = 'lnkup_vendor_profile' AND p.post_status = 'publish' AND (s.meta_value IS NULL OR s.meta_value = '' OR UPPER(s.meta_value) = %s)";
			$args[] = strtoupper( trim( $state ) );
		} else {
			$sql .= " WHERE p.post_type = 'lnkup_vendor_profile' AND p.post_status = 'publish'";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
		set_transient( $key, $count, 12 * HOUR_IN_SECONDS );
		return $count;
	}
}
