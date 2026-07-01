<?php
/**
 * Financial health score computation.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Health_Score {

	public static function init() {
		add_action( 'lnkup_financial_cron', array( __CLASS__, 'compute_all' ) );
	}

	/**
	 * Compute health score for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    vendor|organizer.
	 */
	public static function compute( $user_id, $role = 'vendor' ) {
		$score   = 100;
		$reasons = array();

		$refund_rate = self::refund_rate( $user_id, $role );
		if ( $refund_rate > 10 ) {
			$score -= 20;
			$reasons[] = __( 'High refund rate', 'lnkup-marketplace' );
		} elseif ( $refund_rate > 5 ) {
			$score -= 10;
			$reasons[] = __( 'Elevated refund rate', 'lnkup-marketplace' );
		}

		$disputes = self::open_disputes( $user_id );
		if ( $disputes > 0 ) {
			$score -= min( 30, $disputes * 10 );
			$reasons[] = __( 'Open disputes', 'lnkup-marketplace' );
		}

		$collection = self::collection_speed( $user_id, $role );
		if ( $collection < 50 ) {
			$score -= 15;
			$reasons[] = __( 'Slow collection rate', 'lnkup-marketplace' );
		}

		$score = max( 0, min( 100, $score ) );
		self::store( $user_id, $role, $score, $reasons );
		return array( 'score' => $score, 'reasons' => $reasons );
	}

	/**
	 * Store computed score.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 * @param int    $score   Score 0-100.
	 * @param array  $reasons Reasons.
	 */
	private static function store( $user_id, $role, $score, $reasons ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_health_scores_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'user_id'    => absint( $user_id ),
				'role_type'  => sanitize_key( $role ),
				'score'      => absint( $score ),
				'reasons'    => wp_json_encode( $reasons ),
				'computed_at' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get latest score for user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Role.
	 */
	public static function get_latest( $user_id, $role = 'vendor' ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financial_health_scores_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array( 'score' => 75, 'reasons' => array() );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND role_type = %s ORDER BY computed_at DESC LIMIT 1",
				absint( $user_id ),
				sanitize_key( $role )
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return self::compute( $user_id, $role );
		}
		return array(
			'score'   => (int) $row['score'],
			'reasons' => json_decode( $row['reasons'] ?? '[]', true ) ?: array(),
			'computed_at' => $row['computed_at'],
		);
	}

	/**
	 * Compute scores for all active users.
	 */
	public static function compute_all() {
		$organizers = get_users( array( 'role' => 'lnkup_organizer', 'fields' => 'ID' ) );
		foreach ( $organizers as $uid ) {
			self::compute( (int) $uid, 'organizer' );
		}
		$vendors = get_users( array( 'role' => 'lnkup_vendor', 'fields' => 'ID' ) );
		foreach ( $vendors as $uid ) {
			self::compute( (int) $uid, 'vendor' );
		}
	}

	private static function refund_rate( $user_id, $role ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		try {
			if ( 'organizer' === $role ) {
				$total = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid'",
						absint( $user_id )
					)
				);
				$refunds = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'refund'",
						absint( $user_id )
					)
				);
			} else {
				$total = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE vendor_user_id = %d AND type = 'payment' AND status = 'paid'",
						absint( $user_id )
					)
				);
				$refunds = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE vendor_user_id = %d AND type = 'refund'",
						absint( $user_id )
					)
				);
			}
			return $total > 0 ? round( ( $refunds / $total ) * 100, 2 ) : 0;
		} catch ( Throwable $e ) {
			return 0;
		}
	}

	private static function open_disputes( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::disputes_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE (vendor_user_id = %d OR organizer_user_id = %d) AND status IN ('open','under_review')",
				absint( $user_id ),
				absint( $user_id )
			)
		);
	}

	private static function collection_speed( $user_id, $role ) {
		if ( 'organizer' === $role ) {
			$data = LNKup_Financial::organizer_dashboard( $user_id );
			return (float) ( $data['completion_rate'] ?? 75 );
		}
		return 80;
	}
}
