<?php
/**
 * Event budget center with variance tracking.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Budget_Center {

	/**
	 * Get or create event budget.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  Organizer user ID.
	 */
	public static function get_or_create( $event_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::event_budgets_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d LIMIT 1", absint( $event_id ) ),
			ARRAY_A
		);
		if ( $row ) {
			return $row;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'event_id'          => absint( $event_id ),
				'organizer_user_id' => absint( $user_id ),
				'total_budget'      => 0,
				'currency'          => 'USD',
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		return self::get_or_create( $event_id, $user_id );
	}

	/**
	 * Add budget line item.
	 *
	 * @param int   $budget_id Budget ID.
	 * @param array $item      Item data.
	 */
	public static function add_item( $budget_id, $item ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::event_budget_items_table();
		$wpdb->insert(
			$table,
			array(
				'budget_id'   => absint( $budget_id ),
				'category'    => sanitize_text_field( $item['category'] ?? 'general' ),
				'label'       => sanitize_text_field( $item['label'] ?? '' ),
				'planned'     => round( (float) ( $item['planned'] ?? 0 ), 2 ),
				'actual'      => round( (float) ( $item['actual'] ?? 0 ), 2 ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get budget with items and variance.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_with_variance( $event_id ) {
		global $wpdb;
		$budget = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . LNKup_Treasury_Tables::event_budgets_table() . " WHERE event_id = %d", absint( $event_id ) ),
			ARRAY_A
		);
		if ( ! $budget ) {
			return null;
		}
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM " . LNKup_Treasury_Tables::event_budget_items_table() . " WHERE budget_id = %d", (int) $budget['id'] ),
			ARRAY_A
		);
		$planned_total = array_sum( array_column( $items, 'planned' ) );
		$actual_total  = array_sum( array_column( $items, 'actual' ) );
		$revenue       = LNKup_Financial::event_collected_revenue( $event_id );
		return array(
			'budget'        => $budget,
			'items'         => $items,
			'planned_total' => round( $planned_total, 2 ),
			'actual_total'  => round( $actual_total, 2 ),
			'variance'      => round( $planned_total - $actual_total, 2 ),
			'revenue'       => round( $revenue, 2 ),
			'profit'        => round( $revenue - $actual_total, 2 ),
		);
	}

	/**
	 * Generate cashflow forecast for organizer.
	 *
	 * @param int $user_id Organizer user ID.
	 * @param int $days    Forecast horizon.
	 */
	public static function forecast( $user_id, $days = 30 ) {
		$user_id = absint( $user_id );
		$days    = max( 7, min( 90, absint( $days ) ) );

		$zero = array(
			'next_7_days'              => 0,
			'next_30_days'             => 0,
			'expected_payouts'         => 0,
			'overdue_invoices'         => 0,
			'high_risk_invoices'       => array(),
			'projected_available_cash' => 0,
			'confidence'               => 'low',
			'message'                  => __( 'Forecasts will appear after vendor invoices and payments are created.', 'lnkup-marketplace' ),
			'horizon_days'             => $days,
			'expected_inflows'         => 0,
			'outstanding'              => 0,
			'overdue'                  => 0,
			'projected_payout'         => 0,
			'generated_at'             => current_time( 'mysql' ),
		);

		try {
			$outstanding = LNKup_Financial::organizer_pending_revenue( $user_id );
			$collected   = LNKup_Financial::organizer_collected_revenue( $user_id );
			$wallet      = array();
			if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
				$wallet = LNKup_Organizer_Wallet::get_balances( $user_id );
			}
			$available = (float) ( $wallet['available'] ?? 0 );

			$overdue = 0;
			$high_risk = array();
			global $wpdb;
			$inv_table = LNKup_Financial_Tables::invoices_table();
			if ( LNKup_Financial_Tables::table_exists( $inv_table ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$overdue = (float) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COALESCE(SUM(total_amount - paid_amount), 0) FROM {$inv_table} WHERE organizer_user_id = %d AND status IN ('sent','overdue') AND due_date < %s",
						$user_id,
						current_time( 'Y-m-d' )
					)
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, (total_amount - paid_amount) AS amount_due, due_date, status FROM {$inv_table} WHERE organizer_user_id = %d AND status IN ('sent','overdue') AND due_date < %s ORDER BY due_date ASC LIMIT 5",
						$user_id,
						current_time( 'Y-m-d' )
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$high_risk[] = array(
						'id'       => (int) $row['id'],
						'amount'   => round( (float) $row['amount_due'], 2 ),
						'due_date' => $row['due_date'],
						'status'   => $row['status'],
					);
				}
			}

			$has_data = $collected > 0 || $outstanding > 0 || $available > 0 || $overdue > 0;
			if ( ! $has_data ) {
				return $zero;
			}

			$ratio_7  = min( 1, 7 / max( 1, $days ) );
			$ratio_30 = min( 1, 30 / max( 1, $days ) );
			$next_7   = round( $outstanding * $ratio_7 * 0.35, 2 );
			$next_30  = round( $outstanding * $ratio_30 * 0.65, 2 );

			$expected_payouts = 0;
			if ( class_exists( 'LNKup_Payouts' ) ) {
				$expected_payouts = (float) LNKup_Payouts::pending_balance( $user_id );
			}

			$confidence = $outstanding > 0 ? 'medium' : 'low';
			if ( $collected > 0 && $outstanding > 0 ) {
				$confidence = 'high';
			}

			return array(
				'next_7_days'              => $next_7,
				'next_30_days'             => $next_30,
				'expected_payouts'         => round( $expected_payouts, 2 ),
				'overdue_invoices'         => round( $overdue, 2 ),
				'high_risk_invoices'       => $high_risk,
				'projected_available_cash' => round( $available + $next_30 - $expected_payouts, 2 ),
				'confidence'               => $confidence,
				'message'                  => $has_data
					? __( 'Forecast based on outstanding invoices and wallet balances.', 'lnkup-marketplace' )
					: __( 'Forecasts will appear after vendor invoices and payments are created.', 'lnkup-marketplace' ),
				'horizon_days'             => $days,
				'expected_inflows'         => round( (float) $collected, 2 ),
				'outstanding'              => round( (float) $outstanding, 2 ),
				'overdue'                  => round( (float) $overdue, 2 ),
				'projected_payout'         => round( $available, 2 ),
				'generated_at'             => current_time( 'mysql' ),
			);
		} catch ( Throwable $e ) {
			return $zero;
		}
	}
}
