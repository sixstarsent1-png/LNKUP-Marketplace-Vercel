<?php
/**
 * Organizer Growth overview metrics.
 *
 * Every number is a count over lead, milestone, activity, payment or platform-fee rows.
 * Revenue is LNKup's earned platform fee net of refunds (LNKup_Platform_Fees), reported
 * separately from gross booking value. Acquisition cost is reported as unavailable because no
 * marketing-spend records exist.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Org_Growth_Metrics {

	const CACHE_VERSION_OPTION = 'lnkup_org_growth_metrics_v';
	const ACTIVE_WINDOW_DAYS   = 90;

	public static function flush_cache(): void {
		update_option( self::CACHE_VERSION_OPTION, (int) get_option( self::CACHE_VERSION_OPTION, 0 ) + 1, false );
	}

	/**
	 * @param array<string, mixed> $args affiliation (''|house|independent).
	 * @return array<string, mixed>
	 */
	public static function overview( array $args = array() ): array {
		$affiliation = in_array( (string) ( $args['affiliation'] ?? '' ), array( 'house', 'independent' ), true ) ? (string) $args['affiliation'] : '';
		$key         = 'lnkup_org_growth_ov_' . (int) get_option( self::CACHE_VERSION_OPTION, 0 ) . '_' . $affiliation;
		$cached      = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = self::compute( $affiliation );
		set_transient( $key, $data, 10 * MINUTE_IN_SECONDS );
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function compute( string $affiliation ): array {
		global $wpdb;
		$leads      = LNKup_Org_Leads::table();
		$milestones = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		$activity   = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY );
		$tasks      = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::TASKS );
		$aff_sql    = 'house' === $affiliation ? ' AND l.is_house_affiliated = 1' : ( 'independent' === $affiliation ? ' AND l.is_house_affiliated = 0' : '' );
		$base       = "FROM {$leads} l WHERE l.merged_into_id = 0{$aff_sql}";
		$rank       = static function ( string $stage ): array {
			$keys = array_keys( LNKup_Org_Lead_Pipeline::STAGES );
			return array_slice( $keys, max( 0, LNKup_Org_Lead_Pipeline::rank( $stage ) ) );
		};
		$in         = static fn( array $list ): string => "'" . implode( "','", array_map( 'esc_sql', $list ) ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) {$base}" );
		$qualified = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND l.stage IN (" . $in( $rank( 'qualified' ) ) . ") AND l.outcome NOT IN ('unqualified','duplicate')" );
		$contacted = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND ( l.stage IN (" . $in( $rank( 'contacted' ) ) . ") OR EXISTS (SELECT 1 FROM {$activity} a WHERE a.lead_id = l.id AND a.type = 'contact') )" );
		$replied   = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND ( l.stage IN (" . $in( $rank( 'responded' ) ) . ") OR EXISTS (SELECT 1 FROM {$activity} a WHERE a.lead_id = l.id AND a.type = 'contact' AND a.outcome IN ('replied','meeting_booked','demo_done')) )" );
		$meetings  = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND ( l.stage IN (" . $in( $rank( 'meeting_scheduled' ) ) . ") OR EXISTS (SELECT 1 FROM {$tasks} t WHERE t.lead_id = l.id AND t.type IN ('meeting','demo')) )" );
		$ms_count  = static function ( string $milestone ) use ( $wpdb, $base, $milestones ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base} AND EXISTS (SELECT 1 FROM {$milestones} m WHERE m.lead_id = l.id AND m.milestone = %s AND m.status = 'active')", $milestone ) );
		};
		$registered = $ms_count( 'registered' );
		$published  = $ms_count( 'first_event_published' );
		$applied    = $ms_count( 'first_application' );
		$paid       = $ms_count( 'first_paid_booking' );
		$activated  = $ms_count( 'activated' );
		$repeat     = $ms_count( 'repeat_organizer' );
		$onboarding = (int) $wpdb->get_var( "SELECT COUNT(*) {$base} AND EXISTS (SELECT 1 FROM {$milestones} m WHERE m.lead_id = l.id AND m.milestone = 'registered' AND m.status = 'active') AND NOT EXISTS (SELECT 1 FROM {$milestones} m2 WHERE m2.lead_id = l.id AND m2.milestone = 'first_event_published' AND m2.status = 'active')" );
		$referral   = (int) $wpdb->get_var(
			"SELECT COUNT(*) {$base} AND ( l.referrer_user_id > 0 OR l.partner_id > 0 OR l.source IN ('connector','city_connector','referral','organizer_referral','partner')
			OR EXISTS (SELECT 1 FROM " . LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS ) . " a WHERE a.lead_id = l.id AND a.link_method = 'referral_code') )"
		);
		$sources    = $wpdb->get_results( "SELECT l.source AS source, COUNT(*) AS leads, SUM(EXISTS (SELECT 1 FROM {$milestones} m WHERE m.lead_id = l.id AND m.milestone = 'registered' AND m.status = 'active')) AS registered, SUM(EXISTS (SELECT 1 FROM {$milestones} m WHERE m.lead_id = l.id AND m.milestone = 'first_paid_booking' AND m.status = 'active')) AS paid {$base} GROUP BY l.source ORDER BY leads DESC LIMIT 25", ARRAY_A );
		// phpcs:enable

		$revenue = self::revenue_by_source( $aff_sql );
		$active  = self::active_organizers( $aff_sql );

		$funnel = array(
			array( 'key' => 'leads', 'label' => __( 'Leads', 'lnkup-marketplace' ), 'count' => $total ),
			array( 'key' => 'contacted', 'label' => __( 'Contacted', 'lnkup-marketplace' ), 'count' => $contacted ),
			array( 'key' => 'responded', 'label' => __( 'Responded', 'lnkup-marketplace' ), 'count' => $replied ),
			array( 'key' => 'meeting', 'label' => __( 'Meeting', 'lnkup-marketplace' ), 'count' => $meetings ),
			array( 'key' => 'registered', 'label' => __( 'Registered', 'lnkup-marketplace' ), 'count' => $registered ),
			array( 'key' => 'published', 'label' => __( 'Event published', 'lnkup-marketplace' ), 'count' => $published ),
			array( 'key' => 'application', 'label' => __( 'Application received', 'lnkup-marketplace' ), 'count' => $applied ),
			array( 'key' => 'paid', 'label' => __( 'Paid booking', 'lnkup-marketplace' ), 'count' => $paid ),
			array( 'key' => 'repeat', 'label' => __( 'Repeat organizer', 'lnkup-marketplace' ), 'count' => $repeat ),
		);
		$prev = null;
		foreach ( $funnel as &$step ) {
			$step['rate_from_previous'] = null === $prev ? null : ( $prev > 0 ? round( $step['count'] / $prev * 100, 1 ) : null );
			$prev                       = $step['count'];
		}
		unset( $step );

		$source_rows = array();
		foreach ( (array) $sources as $row ) {
			$src           = (string) $row['source'];
			$source_rows[] = array(
				'source'            => '' === $src ? 'unknown' : $src,
				'leads'             => (int) $row['leads'],
				'registered'        => (int) $row['registered'],
				'paid'              => (int) $row['paid'],
				'net_platform_fees' => $revenue['by_source'][ '' === $src ? 'unknown' : $src ]['net_platform_fees'] ?? 0.0,
				'gross_bookings'    => $revenue['by_source'][ '' === $src ? 'unknown' : $src ]['gross_bookings'] ?? 0.0,
			);
		}

		return array(
			'affiliation'     => '' === $affiliation ? 'all' : $affiliation,
			'generated_at'    => LNKup_Org_Growth::now(),
			'kpis'            => array(
				'total_prospects'       => $total,
				'qualified'             => $qualified,
				'contacted'             => $contacted,
				'replies'               => $replied,
				'meetings'              => $meetings,
				'registrations'         => $registered,
				'onboarding'            => $onboarding,
				'published'             => $published,
				'first_paid_booking'    => $paid,
				'activated'             => $activated,
				'active_organizers'     => $active,
				'repeat_organizers'     => $repeat,
				'referral_attributed'   => $referral,
				'net_platform_fees'     => $revenue['total_net'],
				'gross_bookings'        => $revenue['total_gross'],
			),
			'definitions'     => array(
				'active_organizers' => sprintf( /* translators: %d: days */ __( 'Linked organizations with at least one paid booking in the last %d days.', 'lnkup-marketplace' ), self::ACTIVE_WINDOW_DAYS ),
				'activated'         => sprintf( /* translators: %d: bookings */ __( 'Organizations with %d or more paid bookings.', 'lnkup-marketplace' ), max( 1, (int) LNKup_Org_Growth::setting( 'org_growth_activation_paid_bookings' ) ) ),
				'net_platform_fees' => __( 'LNKup platform fees earned on these organizers\' bookings, net of refunds and lost chargebacks.', 'lnkup-marketplace' ),
				'gross_bookings'    => __( 'Total vendor booking value paid (not LNKup revenue).', 'lnkup-marketplace' ),
			),
			'onboarding_by_stage' => self::onboarding_by_stage( $aff_sql ),
			'funnel'          => $funnel,
			'sources'         => $source_rows,
			'trends'          => self::trends( $aff_sql ),
			'unavailable'     => array(
				'cost_per_acquired_organizer'  => __( 'Unavailable — no marketing spend records have been entered.', 'lnkup-marketplace' ),
				'cost_per_activated_organizer' => __( 'Unavailable — no marketing spend records have been entered.', 'lnkup-marketplace' ),
				'lifetime_value'               => __( 'Unavailable — not enough verified history to calculate.', 'lnkup-marketplace' ),
			),
			'revenue_available' => $revenue['available'],
		);
	}

	/**
	 * Net platform fees and gross booking value per lead source, from paid payments of linked organizers.
	 *
	 * @return array{available:bool,total_net:float,total_gross:float,by_source:array<string,array{net_platform_fees:float,gross_bookings:float}>}
	 */
	private static function revenue_by_source( string $aff_sql ): array {
		global $wpdb;
		$out = array(
			'available'   => class_exists( 'LNKup_Platform_Fees' ),
			'total_net'   => 0.0,
			'total_gross' => 0.0,
			'by_source'   => array(),
		);
		if ( ! $out['available'] ) {
			return $out;
		}
		$leads    = LNKup_Org_Leads::table();
		$accounts = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT l.source AS source, p.ID AS payment_id, CAST(t.meta_value AS DECIMAL(14,2)) AS total
			FROM {$leads} l
			INNER JOIN {$accounts} a ON a.lead_id = l.id AND a.status = 'linked'
			INNER JOIN {$wpdb->postmeta} o ON o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = a.user_id
			INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id AND p.post_type = 'lnkup_payment'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' AND s.meta_value IN ('paid','partially_refunded')
			LEFT JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_lnkup_total_amount'
			WHERE l.merged_into_id = 0{$aff_sql} LIMIT 20000",
			ARRAY_A
		);
		$by = array();
		foreach ( (array) $rows as $row ) {
			$src                     = '' === (string) $row['source'] ? 'unknown' : (string) $row['source'];
			$by[ $src ]['ids'][]     = (int) $row['payment_id'];
			$by[ $src ]['gross']     = ( $by[ $src ]['gross'] ?? 0.0 ) + (float) $row['total'];
		}
		foreach ( $by as $src => $set ) {
			$net                      = LNKup_Platform_Fees::sum_net_platform_fees( '', $set['ids'] );
			$out['by_source'][ $src ] = array(
				'net_platform_fees' => $net,
				'gross_bookings'    => round( (float) $set['gross'], 2 ),
			);
			$out['total_net']        += $net;
			$out['total_gross']      += (float) $set['gross'];
		}
		$out['total_net']   = round( $out['total_net'], 2 );
		$out['total_gross'] = round( $out['total_gross'], 2 );
		return $out;
	}

	/**
	 * Where registered-but-not-activated organizations are stuck (organizer activation model).
	 * Counts each organization once, by its most advanced linked account. Capped at 500 orgs.
	 *
	 * @return array<int, array{stage:string,label:string,count:int}>
	 */
	private static function onboarding_by_stage( string $aff_sql ): array {
		global $wpdb;
		if ( ! class_exists( 'LNKup_Organizer_Activation' ) ) {
			return array();
		}
		$leads      = LNKup_Org_Leads::table();
		$accounts   = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		$milestones = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT l.id AS lead_id, a.user_id FROM {$leads} l
			INNER JOIN {$accounts} a ON a.lead_id = l.id AND a.status = 'linked'
			WHERE l.merged_into_id = 0{$aff_sql}
			AND NOT EXISTS (SELECT 1 FROM {$milestones} m WHERE m.lead_id = l.id AND m.milestone = 'activated' AND m.status = 'active')
			ORDER BY l.id LIMIT 1500",
			ARRAY_A
		);
		$order = array_flip( LNKup_Organizer_Activation::STAGES );
		$best  = array();
		foreach ( (array) $rows as $row ) {
			$stage = (string) LNKup_Organizer_Activation::for_user( (int) $row['user_id'] )['current_stage'];
			$lead  = (int) $row['lead_id'];
			if ( ! isset( $best[ $lead ] ) || ( $order[ $stage ] ?? 99 ) > ( $order[ $best[ $lead ] ] ?? 99 ) ) {
				$best[ $lead ] = $stage;
			}
			if ( count( $best ) >= 500 ) {
				break;
			}
		}
		$counts = array_count_values( $best );
		$labels = array(
			'organization'       => __( 'Organization setup', 'lnkup-marketplace' ),
			'payouts'            => __( 'Payout setup', 'lnkup-marketplace' ),
			'first_event'        => __( 'Creating first event', 'lnkup-marketplace' ),
			'publication'        => __( 'Publishing', 'lnkup-marketplace' ),
			'first_applications' => __( 'Waiting for applications', 'lnkup-marketplace' ),
			'first_paid_booking' => __( 'Waiting for first paid booking', 'lnkup-marketplace' ),
			'activated'          => __( 'Setup complete (below activation threshold)', 'lnkup-marketplace' ),
		);
		$out = array();
		foreach ( $labels as $stage => $label ) {
			if ( ! empty( $counts[ $stage ] ) ) {
				$out[] = array(
					'stage' => $stage,
					'label' => $label,
					'count' => (int) $counts[ $stage ],
				);
			}
		}
		return $out;
	}

	private static function active_organizers( string $aff_sql ): int {
		global $wpdb;
		$leads    = LNKup_Org_Leads::table();
		$accounts = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACCOUNTS );
		$since    = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - self::ACTIVE_WINDOW_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT l.id) FROM {$leads} l
				INNER JOIN {$accounts} a ON a.lead_id = l.id AND a.status = 'linked'
				INNER JOIN {$wpdb->postmeta} o ON o.meta_key = '_lnkup_organizer_user_id' AND o.meta_value = a.user_id
				INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id AND p.post_type = 'lnkup_payment' AND p.post_date >= %s
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' AND s.meta_value IN ('paid','partially_refunded')
				WHERE l.merged_into_id = 0{$aff_sql}",
				$since
			)
		);
	}

	/**
	 * Weekly counts for the last 12 weeks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function trends( string $aff_sql ): array {
		global $wpdb;
		$leads      = LNKup_Org_Leads::table();
		$milestones = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::MILESTONES );
		$activity   = LNKup_Org_Growth_Tables::table( LNKup_Org_Growth_Tables::ACTIVITY );
		$start      = gmdate( 'Y-m-d', strtotime( 'monday this week', (int) current_time( 'timestamp' ) ) - 11 * WEEK_IN_SECONDS );
		$weeks      = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$weeks[ gmdate( 'Y-m-d', strtotime( $start ) + $i * WEEK_IN_SECONDS ) ] = array(
				'leads'      => 0,
				'contacted'  => 0,
				'registered' => 0,
				'published'  => 0,
				'paid'       => 0,
			);
		}
		$bucket = static fn( string $col ): string => "DATE(DATE_SUB(DATE({$col}), INTERVAL WEEKDAY({$col}) DAY))";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$series = array(
			'leads'     => $wpdb->prepare( 'SELECT ' . $bucket( 'l.created_at' ) . " AS wk, COUNT(*) AS n FROM {$leads} l WHERE l.merged_into_id = 0{$aff_sql} AND l.created_at >= %s GROUP BY wk", $start ),
			'contacted' => $wpdb->prepare( 'SELECT ' . $bucket( 'a.occurred_at' ) . " AS wk, COUNT(DISTINCT a.lead_id) AS n FROM {$activity} a INNER JOIN {$leads} l ON l.id = a.lead_id WHERE a.type = 'contact'{$aff_sql} AND a.occurred_at >= %s GROUP BY wk", $start ),
		);
		foreach ( array( 'registered' => 'registered', 'published' => 'first_event_published', 'paid' => 'first_paid_booking' ) as $key => $milestone ) {
			$series[ $key ] = $wpdb->prepare( 'SELECT ' . $bucket( 'm.achieved_at' ) . " AS wk, COUNT(*) AS n FROM {$milestones} m INNER JOIN {$leads} l ON l.id = m.lead_id WHERE m.milestone = %s AND m.status = 'active'{$aff_sql} AND m.achieved_at >= %s GROUP BY wk", $milestone, $start );
		}
		foreach ( $series as $key => $sql ) {
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) {
				if ( isset( $weeks[ (string) $row['wk'] ] ) ) {
					$weeks[ (string) $row['wk'] ][ $key ] = (int) $row['n'];
				}
			}
		}
		// phpcs:enable
		$out = array();
		foreach ( $weeks as $week => $counts ) {
			$out[] = array( 'week' => $week ) + $counts;
		}
		return $out;
	}
}
