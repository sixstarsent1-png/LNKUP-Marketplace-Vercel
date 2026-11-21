<?php
/**
 * Financial reporting aggregates.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financial_Reports {

	public static function marketplace_summary() {
		$cached = get_transient( 'lnkup_fin_market_summary' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$rows  = $wpdb->get_results(
			"SELECT type, status, SUM(amount) as total, COUNT(*) as cnt FROM {$table} GROUP BY type, status",
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$gmv = 0;
		$platform = 0;
		$organizer = 0;
		$refunds = 0;
		$paid_count = 0;
		foreach ( $rows as $row ) {
			if ( 'payment' === $row['type'] && 'paid' === $row['status'] ) {
				$gmv += (float) $row['total'];
				$paid_count += (int) $row['cnt'];
			}
			if ( 'refund' === $row['type'] ) {
				$refunds += (float) $row['total'];
			}
		}
		if ( 0 === $paid_count ) {
			$legacy = LNKup_Revenue_Intelligence::marketplace_summary();
			$data = array(
				'gmv'            => $legacy['gmv'] ?? 0,
				'platform_revenue' => $legacy['lnkup_fees'] ?? 0,
				'organizer_revenue' => $legacy['organizer_fees'] ?? 0,
				'paid_bookings'  => $legacy['paid_bookings'] ?? 0,
				'refund_volume'  => $refunds,
				'dispute_count'  => LNKup_Financial_Disputes::open_count_all(),
				'avg_vendor_fee' => $legacy['avg_fee'] ?? 0,
			);
		} else {
			$platform = $wpdb->get_var( "SELECT COALESCE(SUM(JSON_EXTRACT(metadata, '$.lnkup_fee')), 0) FROM {$table} WHERE type = 'payment' AND status = 'paid'" );
			if ( null === $platform ) {
				$platform = 0;
			}
			$data = array(
				'gmv'              => round( $gmv, 2 ),
				'platform_revenue' => round( (float) $platform, 2 ),
				'organizer_revenue'=> round( max( 0, $gmv - (float) $platform ), 2 ),
				'paid_bookings'    => $paid_count,
				'refund_volume'    => round( $refunds, 2 ),
				'dispute_count'    => LNKup_Financial_Disputes::open_count_all(),
				'avg_vendor_fee'   => $paid_count ? round( $gmv / $paid_count, 2 ) : 0,
			);
		}
		set_transient( 'lnkup_fin_market_summary', $data, HOUR_IN_SECONDS );
		return $data;
	}

	public static function vendor_report( $user_id ) {
		$txns = LNKup_Transactions::list_for_user( array( 'user_id' => $user_id, 'role' => 'vendor', 'limit' => 100 ) );
		$txns = is_array( $txns ) ? $txns : array();
		$spent = 0;
		$refunds = 0;
		$pending = 0;
		$open_invoices = 0;
		$due_soon = 0;
		$soon_date = gmdate( 'Y-m-d', strtotime( '+7 days' ) );
		foreach ( $txns as $t ) {
			if ( 'payment' === $t['type'] && 'paid' === $t['status'] ) {
				$spent += (float) $t['amount'];
			}
			if ( 'payment' === $t['type'] && 'pending' === $t['status'] ) {
				$pending += (float) $t['amount'];
			}
			if ( 'refund' === $t['type'] ) {
				$refunds += (float) $t['amount'];
			}
		}
		$invoices = LNKup_Invoices::list_for_user( $user_id, 'vendor' );
		foreach ( (array) $invoices as $inv ) {
			$status = $inv['status'] ?? '';
			if ( in_array( $status, array( 'pending', 'sent', 'overdue', 'partially_paid' ), true ) ) {
				$open_invoices++;
				if ( ! empty( $inv['due_date'] ) && $inv['due_date'] <= $soon_date ) {
					$due_soon++;
				}
			}
		}
		$credits = class_exists( 'LNKup_Credits' ) ? LNKup_Credits::available_total( $user_id ) : 0;
		return array(
			'money_spent'        => round( $spent, 2 ),
			'lifetime_spend'     => round( $spent, 2 ),
			'pending_payments'   => round( $pending, 2 ),
			'refunds'            => round( $refunds, 2 ),
			'invoice_count'      => LNKup_Invoices::count_for_user( $user_id, 'vendor' ),
			'open_invoices'      => $open_invoices,
			'due_soon'           => $due_soon,
			'credits_available'  => round( (float) $credits, 2 ),
			'wallet_balance'     => LNKup_Wallet::get_balance( $user_id, 'vendor' ),
			'installments'      => LNKup_Installments::upcoming_for_vendor( $user_id ),
			'by_month'          => self::vendor_spending_by_month( $user_id ),
			'confirmed_bookings'=> (int) count( array_filter( $txns, function ( $t ) {
				return 'payment' === $t['type'] && 'paid' === $t['status'];
			} ) ),
		);
	}

	public static function vendor_spending_by_month( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$since = gmdate( 'Y-m-d', strtotime( '-12 months' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month_key, SUM(amount) as total FROM {$table} WHERE vendor_user_id = %d AND type = 'payment' AND status = 'paid' AND created_at >= %s GROUP BY month_key ORDER BY month_key ASC",
				$user_id,
				$since . ' 00:00:00'
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		return array_map( function ( $r ) {
			return array( 'month' => $r['month_key'], 'amount' => round( (float) $r['total'], 2 ) );
		}, $rows );
	}

	public static function organizer_report( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
		return array(
			'revenue'           => LNKup_Financial::organizer_collected_revenue( $user_id ),
			'gross_revenue'     => LNKup_Financial::organizer_collected_revenue( $user_id ),
			'platform_fees'     => 0,
			'collected'         => LNKup_Financial::organizer_collected_revenue( $user_id ),
			'refunds'           => 0,
			'outstanding'       => LNKup_Financial::organizer_pending_revenue( $user_id ),
			'pending_payouts'   => 0,
			'available_balance' => 0,
			'refund_requests'   => 0,
			'open_disputes'     => 0,
			'revenue_by_event'  => array(),
			'by_event'          => array(),
			'by_month'          => array(),
			'by_category'       => array(),
			'completion_rate'   => 0,
		);
		}
		$gross = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid'", $user_id )
		);
		$collected_cpt = LNKup_Financial::organizer_collected_revenue( $user_id );
		// CPT booth totals are source of truth for Collected / Gross Revenue KPIs.
		$gross = $collected_cpt;
		$platform_fees = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(platform_fee), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid'", $user_id )
		);
		$refunds = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'refund'", $user_id )
		);
		$pending = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'pending'", $user_id )
		);
		$pending_cpt = LNKup_Financial::organizer_pending_revenue( $user_id );
		if ( $pending_cpt > 0 && $pending <= 0 ) {
			$pending = $pending_cpt;
		}
		$by_event = self::revenue_by_event( $user_id );
		$by_month = self::revenue_by_month( $user_id );
		$total_bookings = array_sum( array_column( $by_event, 'bookings' ) );
		$paid_bookings  = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid'", $user_id )
		);
		$pending_payouts   = 0;
		$available_balance = 0;
		$refund_requests   = 0;
		$open_disputes     = 0;
		try {
			$pending_payouts   = LNKup_Payouts::pending_balance( $user_id );
			$available_balance = LNKup_Payouts::available_balance( $user_id );
		} catch ( Throwable $e ) {
			// Legacy payouts table may be unavailable.
		}
		try {
			$refund_requests = LNKup_Refunds::pending_count_for_organizer( $user_id );
			$open_disputes   = LNKup_Financial_Disputes::open_count_for_organizer( $user_id );
		} catch ( Throwable $e ) {
			// Non-critical report metrics.
		}
		return array(
			'revenue'              => $collected_cpt,
			'gross_revenue'        => round( $gross, 2 ),
			'platform_fees'        => round( $platform_fees, 2 ),
			'collected'            => round( $collected_cpt, 2 ),
			'refunds'              => round( $refunds, 2 ),
			'outstanding'          => round( $pending, 2 ),
			'pending_payouts'      => $pending_payouts,
			'available_balance'    => $available_balance,
			'refund_requests'      => $refund_requests,
			'open_disputes'        => $open_disputes,
			'revenue_by_event'     => $by_event,
			'by_event'             => $by_event,
			'by_month'             => $by_month,
			'by_category'          => self::revenue_by_category( $user_id ),
			'completion_rate'      => $total_bookings ? round( ( $paid_bookings / max( 1, $total_bookings ) ) * 100, 1 ) : 0,
		);
	}

	public static function revenue_by_month( $organizer_id, $months = 12 ) {
		$from_payments = self::revenue_by_month_from_payments( $organizer_id, $months );
		if ( $from_payments ) {
			return $from_payments;
		}
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$since = gmdate( 'Y-m-d', strtotime( '-' . absint( $months ) . ' months' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') as month_key, SUM(amount) as total FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid' AND created_at >= %s GROUP BY month_key ORDER BY month_key ASC",
				$organizer_id,
				$since . ' 00:00:00'
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$mapped = array_map( function ( $r ) {
			return array( 'month' => $r['month_key'], 'revenue' => round( (float) $r['total'], 2 ) );
		}, $rows );
		return $mapped;
	}

	/**
	 * Month totals from paid booth payments when the ledger query is empty.
	 *
	 * @param int $organizer_id Organizer user ID.
	 * @param int $months       Lookback in months.
	 * @return array<int, array{month: string, revenue: float}>
	 */
	private static function revenue_by_month_from_payments( $organizer_id, $months = 12 ) {
		if ( ! function_exists( 'lnkup_get_payments_for_user' ) || ! class_exists( 'LNKup_Financial' ) ) {
			return array();
		}
		$payments = lnkup_get_payments_for_user( (int) $organizer_id, 'organizer' );
		if ( ! is_array( $payments ) ) {
			return array();
		}
		$paid = array();
		foreach ( $payments as $payment ) {
			if ( $payment instanceof WP_Post && 'paid' === (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				$paid[] = $payment;
			}
		}
		// Every paid payment is money collected; no grouping, so two bookings never merge.
		$rows = array_map(
			static function ( $payment ) {
				return array(
					'payment' => $payment,
					'amount'  => LNKup_Financial::payment_booth_amount( $payment->ID ),
				);
			},
			$paid
		);
		$cutoff  = strtotime( '-' . absint( $months ) . ' months' );
		$buckets = array();
		foreach ( $rows as $row ) {
			$payment = $row['payment'];
			$paid_at = (string) get_post_meta( $payment->ID, '_lnkup_paid_at', true );
			$ts      = $paid_at ? strtotime( $paid_at ) : strtotime( (string) $payment->post_date );
			if ( ! $ts || ( $cutoff && $ts < $cutoff ) ) {
				continue;
			}
			$month = function_exists( 'wp_date' ) ? wp_date( 'Y-m', $ts ) : gmdate( 'Y-m', $ts );
			if ( ! isset( $buckets[ $month ] ) ) {
				$buckets[ $month ] = 0.0;
			}
			$buckets[ $month ] += (float) $row['amount'];
		}
		ksort( $buckets );
		$out = array();
		foreach ( $buckets as $month => $total ) {
			$out[] = array(
				'month'   => (string) $month,
				'revenue' => round( (float) $total, 2 ),
			);
		}
		return $out;
	}

	public static function revenue_by_category( $organizer_id ) {
		return array();
	}

	public static function to_csv( $data ) {
		$lines = array( 'metric,value' );
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$lines[] = $key . ',' . $value;
			}
		}
		return implode( "\n", $lines );
	}

	public static function to_pdf_html( $data ) {
		$html = '<html><body><h1>LNKup Financial Report</h1><table border="1" cellpadding="8">';
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$html .= '<tr><td>' . esc_html( $key ) . '</td><td>' . esc_html( (string) $value ) . '</td></tr>';
			}
		}
		$html .= '</table></body></html>';
		return $html;
	}

	public static function admin_extended_summary() {
		$base = self::marketplace_summary();
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$chargebacks = (int) LNKup_Financial_Disputes::open_count_all();
		$refund_pct  = $base['gmv'] > 0 ? round( ( $base['refund_volume'] / $base['gmv'] ) * 100, 2 ) : 0;
		$top_orgs = $wpdb->get_results(
			"SELECT organizer_user_id, SUM(amount) as total FROM {$table} WHERE type = 'payment' AND status = 'paid' AND organizer_user_id > 0 GROUP BY organizer_user_id ORDER BY total DESC LIMIT 5",
			ARRAY_A
		);
		$top_vendors = $wpdb->get_results(
			"SELECT vendor_user_id, SUM(amount) as total FROM {$table} WHERE type = 'payment' AND status = 'paid' AND vendor_user_id > 0 GROUP BY vendor_user_id ORDER BY total DESC LIMIT 5",
			ARRAY_A
		);
		return array_merge( $base, array(
			'refund_percent'       => $refund_pct,
			'chargebacks'          => $chargebacks,
			'treasury_platform_fees' => class_exists( 'LNKup_Platform_Fees' ) ? LNKup_Platform_Fees::sum_platform_fees() : 0,
			'outstanding_payouts'  => self::count_outstanding_organizer_payouts(),
			'subscription_mrr'     => self::estimate_subscription_mrr(),
			'top_organizers'   => array_map( function ( $r ) {
				$user = get_userdata( (int) $r['organizer_user_id'] );
				return array( 'name' => $user ? $user->display_name : '', 'revenue' => round( (float) $r['total'], 2 ) );
			}, $top_orgs ),
			'top_vendors'      => array_map( function ( $r ) {
				$user = get_userdata( (int) $r['vendor_user_id'] );
				return array( 'name' => $user ? $user->display_name : '', 'spent' => round( (float) $r['total'], 2 ) );
			}, $top_vendors ),
			'revenue_by_month' => self::chart_revenue_over_time( 365 ),
		) );
	}

	public static function revenue_by_event( $organizer_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, SUM(amount) as total, COUNT(*) as cnt FROM {$table} WHERE organizer_user_id = %d AND type = 'payment' AND status = 'paid' AND event_id > 0 GROUP BY event_id ORDER BY total DESC LIMIT 20",
				$organizer_id
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'event_id'    => (int) $row['event_id'],
				'event_title' => get_the_title( (int) $row['event_id'] ),
				'revenue'     => round( (float) $row['total'], 2 ),
				'bookings'    => (int) $row['cnt'],
			);
		}
		return $out;
	}

	public static function for_event( $event_id ) {
		global $wpdb;
		$event_id  = absint( $event_id );
		$table     = LNKup_Financial_Tables::transactions_table();
		$collected = LNKup_Financial::event_collected_revenue( $event_id );

		$pending = 0.0;
		if ( LNKup_Financial_Tables::table_exists( $table ) ) {
			$pending = (float) $wpdb->get_var(
				$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE event_id = %d AND type = 'payment' AND status = 'pending'", $event_id )
			);
		}
		if ( $pending <= 0 ) {
			$pending_posts = get_posts(
				array(
					'post_type'      => 'lnkup_payment',
					'posts_per_page' => -1,
					'meta_query'     => array(
						array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
						array(
							'key'     => '_lnkup_payment_status',
							'value'   => 'paid',
							'compare' => '!=',
						),
					),
					'fields'         => 'ids',
				)
			);
			$pending = 0.0;
			foreach ( $pending_posts as $pid ) {
				$pending += LNKup_Financial::payment_booth_amount( (int) $pid );
			}
		}

		$refunds = 0.0;
		if ( LNKup_Financial_Tables::table_exists( $table ) ) {
			$refunds = (float) $wpdb->get_var(
				$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE event_id = %d AND type = 'refund'", $event_id )
			);
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$total_apps              = count( $apps );
		$paid_apps               = 0;
		$payment_required_apps   = 0;
		foreach ( $apps as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$paid_apps++;
			} elseif ( in_array( $status, array( 'payment_required', 'approved' ), true ) ) {
				$payment_required_apps++;
			}
		}
		$completion = $total_apps ? round( ( $paid_apps / $total_apps ) * 100, 1 ) : 0;
		$timeline   = array();
		if ( LNKup_Financial_Tables::table_exists( $table ) ) {
			$timeline = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE event_id = %d ORDER BY created_at DESC LIMIT 25", $event_id ),
				ARRAY_A
			);
			if ( ! is_array( $timeline ) ) {
				$timeline = array();
			}
		}
		return array(
			'projected_revenue'       => round( $collected + $pending, 2 ),
			'collected_revenue'       => round( $collected, 2 ),
			'pending_revenue'         => round( $pending, 2 ),
			'refunds'                 => round( $refunds, 2 ),
			'payment_completion'      => $completion,
			'paid_vendor_count'       => $paid_apps,
			'payment_required_count'  => $payment_required_apps,
			'timeline'                => array_map( array( 'LNKup_Transactions', 'format' ), $timeline ),
		);
	}

	public static function chart_revenue_over_time( $days = 30 ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::transactions_table();
		$since = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as d, SUM(amount) as total FROM {$table} WHERE type = 'payment' AND status = 'paid' AND created_at >= %s GROUP BY DATE(created_at) ORDER BY d ASC",
				$since . ' 00:00:00'
			),
			ARRAY_A
		);
		return array_map(
			function ( $r ) {
				return array( 'date' => $r['d'], 'amount' => round( (float) $r['total'], 2 ) );
			},
			$rows
		);
	}

	/**
	 * Count organizer treasury payouts awaiting completion.
	 */
	public static function count_outstanding_organizer_payouts() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::organizer_payouts_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('requested','approved','processing')" );
	}

	/**
	 * Rough MRR from active subscriptions.
	 */
	public static function estimate_subscription_mrr() {
		global $wpdb;
		$sub_table  = LNKup_Treasury_Tables::subscriptions_table();
		$plan_table = LNKup_Treasury_Tables::subscription_plans_table();
		if ( ! LNKup_Financial_Tables::table_exists( $sub_table ) || ! LNKup_Financial_Tables::table_exists( $plan_table ) ) {
			return 0.0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sum = $wpdb->get_var(
			"SELECT COALESCE(SUM(p.price_monthly), 0) FROM {$sub_table} s INNER JOIN {$plan_table} p ON p.id = s.plan_id WHERE s.status IN ('active','trialing')"
		);
		return round( (float) $sum, 2 );
	}
}
