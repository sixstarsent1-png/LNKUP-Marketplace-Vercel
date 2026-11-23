<?php
/**
 * Marketplace funnel from authoritative records: opportunity view → apply start → submitted →
 * approved → paid → checked in, plus time to first paid booking, repeat vendors and open vs
 * filled slots, split by house (Six Stars) and independent organizers.
 *
 * Each step's source and counting rule is returned with the numbers. Test and demo accounts in
 * LNKup_Analytics_Exclusions never count, as vendors or as organizers.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Opportunity_Funnel {

	const NS               = 'lnkup/v1';
	const PAID_STATUSES    = array( 'paid', 'partially_refunded', 'refunded', 'disputed' );
	const APPROVED_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'lnkup_application_draft_started', array( __CLASS__, 'on_draft_started' ), 10, 3 );
	}

	/**
	 * Analytics event for the first save of an application (the draft row is the record of truth).
	 */
	public static function on_draft_started( $draft_id, $opportunity_id, $user_id ): void {
		if ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( 'apply_started', array( 'draft_id' => (int) $draft_id ), (int) $user_id, (int) $opportunity_id );
		}
	}

	public static function register_routes(): void {
		register_rest_route(
			self::NS,
			'/admin/analytics/funnel',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_report' ),
				'permission_callback' => static function () {
					return LNKup_Capabilities::user_has_manage_all();
				},
				'args'                => array(
					'from'         => array( 'type' => 'string' ),
					'to'           => array( 'type' => 'string' ),
					'segment'      => array( 'type' => 'string', 'enum' => array( 'all', 'house', 'independent' ) ),
					'organizer_id' => array( 'type' => 'integer' ),
				),
			)
		);
	}

	public static function rest_report( WP_REST_Request $request ) {
		return rest_ensure_response(
			self::report(
				array(
					'from'         => (string) $request->get_param( 'from' ),
					'to'           => (string) $request->get_param( 'to' ),
					'segment'      => (string) ( $request->get_param( 'segment' ) ?: 'all' ),
					'organizer_id' => absint( $request->get_param( 'organizer_id' ) ),
				)
			)
		);
	}

	/**
	 * Full report: the requested scope plus a house / independent comparison.
	 *
	 * @param array $args from, to (Y-m-d, default last 90 days), segment, organizer_id.
	 * @return array<string, mixed>
	 */
	public static function report( array $args ): array {
		$to   = self::date_or( (string) ( $args['to'] ?? '' ), current_time( 'Y-m-d' ) );
		$from = self::date_or( (string) ( $args['from'] ?? '' ), gmdate( 'Y-m-d', strtotime( $to . ' -89 days' ) ) );
		$range = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		$segment   = (string) ( $args['segment'] ?? 'all' );
		$segment   = in_array( $segment, array( 'all', 'house', 'independent' ), true ) ? $segment : 'all';
		$organizer = absint( $args['organizer_id'] ?? 0 );

		$out = array(
			'range'       => array( 'from' => $from, 'to' => $to ),
			'segment'     => $segment,
			'excluded_accounts' => count( LNKup_Analytics_Exclusions::user_ids() ),
			'definitions' => self::definitions(),
			'scope'       => self::scope_report( self::organizers( $segment, $organizer ), $range ),
		);
		if ( ! $organizer && 'all' === $segment ) {
			$out['house']       = self::scope_report( self::organizers( 'house', 0 ), $range );
			$out['independent'] = self::scope_report( self::organizers( 'independent', 0 ), $range );
			$out['house_configured'] = (bool) self::house_ids();
		}
		return $out;
	}

	/**
	 * What each step counts and where it comes from.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		return array(
			'views'         => 'Opportunity page views recorded by GET /marketplace/opportunities/{id} in the period (analytics events). Anonymous views count; excluded accounts do not.',
			'apply_started' => 'Vendor and opportunity pairs whose first saved application draft was created in the period, plus applications submitted in the period without a draft.',
			'submitted'     => 'Applications submitted in the period (the cohort for every later step).',
			'approved'      => 'Cohort applications the organizer approved at any time (approval in the status history, or a current approved, payment-required, paid or completed status).',
			'paid'          => 'Cohort applications with a confirmed payment record (paid, partially refunded, refunded or disputed). A later refund does not undo the paid step.',
			'checked_in'    => 'Cohort applications staff checked in.',
		);
	}

	/**
	 * Funnel and supporting metrics for a set of organizers.
	 *
	 * @param int[]    $organizers Organizer user IDs ([] = none).
	 * @param string[] $range      [from, to] datetimes.
	 * @return array<string, mixed>
	 */
	private static function scope_report( array $organizers, array $range ): array {
		$opps = self::opportunity_ids( $organizers );
		$cohort = self::submitted_cohort( $opps, $range );
		$steps = array(
			'views'         => self::views( $opps, $range ),
			'apply_started' => self::apply_starts( $opps, $range, $cohort ),
			'submitted'     => count( $cohort ),
			'approved'      => self::approved_count( $cohort ),
			'paid'          => self::paid_count( $cohort ),
			'checked_in'    => self::checked_in_count( $cohort ),
		);
		$order = array_keys( $steps );
		$drop  = array();
		foreach ( $order as $i => $key ) {
			if ( 0 === $i ) {
				continue;
			}
			$prev         = $steps[ $order[ $i - 1 ] ];
			$drop[ $key ] = $prev > 0 ? round( 1 - min( $steps[ $key ], $prev ) / $prev, 3 ) : null;
		}
		return array(
			'organizers'               => count( $organizers ),
			'opportunities'            => count( $opps ),
			'steps'                    => $steps,
			'drop_off'                 => $drop,
			'days_to_first_paid'       => self::days_to_first_paid( $organizers ),
			'repeat_vendors'           => self::repeat_vendors( $opps ),
			'slots'                    => self::slots( $opps ),
		);
	}

	/**
	 * @return int[]
	 */
	private static function house_ids(): array {
		return class_exists( 'LNKup_Org_Growth' ) ? array_map( 'intval', LNKup_Org_Growth::house_organizer_ids() ) : array();
	}

	/**
	 * Organizers in scope, excluding test and demo accounts.
	 *
	 * @return int[]
	 */
	private static function organizers( string $segment, int $organizer ): array {
		global $wpdb;
		$excluded = LNKup_Analytics_Exclusions::user_ids();
		if ( $organizer ) {
			return in_array( $organizer, $excluded, true ) ? array() : array( $organizer );
		}
		$all = array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'lnkup_event' AND p.post_status NOT IN ('trash','auto-draft') AND pm.meta_key = '_lnkup_organizer_user_id' AND pm.meta_value > 0"
			)
		);
		$house = self::house_ids();
		if ( 'house' === $segment ) {
			$all = array_intersect( $all, $house );
		} elseif ( 'independent' === $segment ) {
			$all = array_diff( $all, $house );
		}
		return array_values( array_diff( $all, $excluded ) );
	}

	/**
	 * @param int[] $organizers Organizers.
	 * @return int[]
	 */
	private static function opportunity_ids( array $organizers ): array {
		global $wpdb;
		if ( ! $organizers ) {
			return array();
		}
		$in = implode( ',', array_map( 'absint', $organizers ) );
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id'
				WHERE p.post_type = 'lnkup_opportunity' AND p.post_status NOT IN ('trash','auto-draft') AND o.meta_value IN ({$in})"
			)
		);
	}

	private static function views( array $opps, array $range ): int {
		global $wpdb;
		if ( ! $opps || ! class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			return 0;
		}
		$table = LNKup_Marketplace_Analytics::table_name();
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE event_type = 'view' AND opportunity_id IN (" . implode( ',', array_map( 'absint', $opps ) ) . ') AND created_at BETWEEN %s AND %s' . self::not_excluded( 'user_id' );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $range[0], $range[1] ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Applications submitted in the period on the scoped opportunities, by vendors who count.
	 *
	 * @return int[]
	 */
	private static function submitted_cohort( array $opps, array $range ): array {
		global $wpdb;
		if ( ! $opps ) {
			return array();
		}
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id'
			INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
			WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')
			AND o.meta_value IN (" . implode( ',', array_map( 'absint', $opps ) ) . ')
			AND p.post_date BETWEEN %s AND %s' . self::not_excluded( 'v.meta_value' );
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $range[0], $range[1] ) ) ); // phpcs:ignore WordPress.DB
	}

	private static function apply_starts( array $opps, array $range, array $cohort ): int {
		global $wpdb;
		if ( ! $opps ) {
			return 0;
		}
		$sql = "SELECT COUNT(DISTINCT CONCAT(v.meta_value, ':', o.meta_value)) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_draft_opportunity_id'
			INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_draft_vendor_user_id'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_draft_started_at'
			WHERE p.post_type = 'lnkup_application' AND o.meta_value IN (" . implode( ',', array_map( 'absint', $opps ) ) . ')
			AND s.meta_value BETWEEN %s AND %s' . self::not_excluded( 'v.meta_value' );
		$drafts = (int) $wpdb->get_var( $wpdb->prepare( $sql, $range[0], $range[1] ) ); // phpcs:ignore WordPress.DB
		$direct = 0;
		foreach ( $cohort as $app_id ) {
			if ( ! get_post_meta( $app_id, '_lnkup_draft_id', true ) ) {
				++$direct;
			}
		}
		return $drafts + $direct;
	}

	private static function approved_count( array $cohort ): int {
		$n = 0;
		foreach ( $cohort as $app_id ) {
			$status  = (string) get_post_meta( $app_id, '_lnkup_application_status', true );
			$history = (array) get_post_meta( $app_id, LNKup_Applications::HISTORY_META, true );
			$ever    = in_array( 'approved', array_column( $history, 'to' ), true ) || in_array( 'payment_required', array_column( $history, 'to' ), true );
			if ( $ever || in_array( $status, self::APPROVED_STATUSES, true ) ) {
				++$n;
			}
		}
		return $n;
	}

	private static function paid_count( array $cohort ): int {
		global $wpdb;
		if ( ! $cohort ) {
			return 0;
		}
		$statuses = "'" . implode( "','", self::PAID_STATUSES ) . "'";
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(DISTINCT a.meta_value) FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_lnkup_application_id'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status'
			WHERE p.post_type = 'lnkup_payment' AND s.meta_value IN ({$statuses}) AND a.meta_value IN (" . implode( ',', array_map( 'absint', $cohort ) ) . ')'
		);
	}

	private static function checked_in_count( array $cohort ): int {
		$n = 0;
		foreach ( $cohort as $app_id ) {
			if ( '' !== (string) get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Days from an organizer's first event to their first confirmed payment.
	 *
	 * @param int[] $organizers Organizers.
	 * @return array{organizers_with_paid:int, organizers_without_paid:int, median_days:?float}
	 */
	private static function days_to_first_paid( array $organizers ): array {
		global $wpdb;
		$days    = array();
		$without = 0;
		$paid_in = "'" . implode( "','", self::PAID_STATUSES ) . "'";
		foreach ( $organizers as $organizer ) {
			$first_event = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(p.post_date) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' WHERE p.post_type = 'lnkup_event' AND p.post_status NOT IN ('trash','auto-draft') AND o.meta_value = %s", (string) $organizer ) ); // phpcs:ignore WordPress.DB
			$first_paid  = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(t.meta_value) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_organizer_user_id' INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status' INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_lnkup_paid_at' WHERE p.post_type = 'lnkup_payment' AND s.meta_value IN ({$paid_in}) AND t.meta_value <> '' AND o.meta_value = %s", (string) $organizer ) ); // phpcs:ignore WordPress.DB
			if ( ! $first_event || ! $first_paid ) {
				++$without;
				continue;
			}
			$days[] = max( 0.0, ( strtotime( (string) $first_paid ) - strtotime( (string) $first_event ) ) / DAY_IN_SECONDS );
		}
		sort( $days );
		$count  = count( $days );
		$median = $count ? ( $count % 2 ? $days[ intdiv( $count, 2 ) ] : ( $days[ $count / 2 - 1 ] + $days[ $count / 2 ] ) / 2 ) : null;
		return array(
			'organizers_with_paid'    => $count,
			'organizers_without_paid' => $without,
			'median_days'             => null === $median ? null : round( $median, 1 ),
		);
	}

	/**
	 * Vendors with confirmed payments at two or more different events in scope (all time).
	 *
	 * @return array{vendors_paid:int, repeat_vendors:int}
	 */
	private static function repeat_vendors( array $opps ): array {
		global $wpdb;
		if ( ! $opps ) {
			return array( 'vendors_paid' => 0, 'repeat_vendors' => 0 );
		}
		$paid_in = "'" . implode( "','", self::PAID_STATUSES ) . "'";
		$rows    = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT v.meta_value AS vendor, COUNT(DISTINCT e.meta_value) AS events FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = '_lnkup_vendor_user_id'
			INNER JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = '_lnkup_event_id'
			INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_payment_status'
			INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id'
			WHERE p.post_type = 'lnkup_payment' AND s.meta_value IN ({$paid_in}) AND o.meta_value IN (" . implode( ',', array_map( 'absint', $opps ) ) . ')
			GROUP BY v.meta_value',
			ARRAY_A
		);
		$excluded = LNKup_Analytics_Exclusions::user_ids();
		$paid     = 0;
		$repeat   = 0;
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row['vendor'], $excluded, true ) ) {
				continue;
			}
			++$paid;
			$repeat += (int) $row['events'] >= 2 ? 1 : 0;
		}
		return array( 'vendors_paid' => $paid, 'repeat_vendors' => $repeat );
	}

	/**
	 * Capacity on open opportunities for upcoming events: filled = held by approved or paid bookings.
	 *
	 * @return array{opportunities:int, capacity:int, filled:int, open:int, unlimited:int}
	 */
	private static function slots( array $opps ): array {
		$out   = array( 'opportunities' => 0, 'capacity' => 0, 'filled' => 0, 'open' => 0, 'unlimited' => 0 );
		$today = current_time( 'Y-m-d' );
		foreach ( $opps as $opp_id ) {
			if ( 'open' !== (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true ) ) {
				continue;
			}
			$event = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
			$date  = (string) get_post_meta( $event, '_lnkup_event_date', true );
			if ( '' === $date || $date < $today ) {
				continue;
			}
			++$out['opportunities'];
			$capacity = LNKup_Opportunity_Inventory::capacity( $opp_id );
			$held     = LNKup_Opportunity_Inventory::holds( $opp_id );
			if ( $capacity <= 0 ) {
				++$out['unlimited'];
				$out['filled'] += $held;
				continue;
			}
			$out['capacity'] += $capacity;
			$out['filled']   += min( $held, $capacity );
			$out['open']     += max( 0, $capacity - $held );
		}
		return $out;
	}

	/**
	 * SQL fragment excluding test accounts from a user ID column.
	 */
	private static function not_excluded( string $column ): string {
		$ids = LNKup_Analytics_Exclusions::user_ids();
		return $ids ? " AND {$column} NOT IN (" . implode( ',', array_map( 'absint', $ids ) ) . ')' : '';
	}

	private static function date_or( string $value, string $fallback ): string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : $fallback;
	}
}
