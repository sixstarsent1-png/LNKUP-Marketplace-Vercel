<?php
/**
 * Installment payment plans.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Installments {

	public static function create_plan( $application_id, $plan_type, $total_amount, $schedule = array() ) {
		$opp_id = (int) get_post_meta( $application_id, '_lnkup_opportunity_id', true );
		global $wpdb;
		$table = LNKup_Financial_Tables::installment_plans_table();
		$wpdb->insert(
			$table,
			array(
				'application_id' => $application_id,
				'opportunity_id' => $opp_id,
				'plan_type'      => sanitize_key( $plan_type ),
				'total_amount'   => (float) $total_amount,
				'schedule_json'  => wp_json_encode( $schedule ),
				'status'         => 'active',
				'created_at'     => current_time( 'mysql' ),
			)
		);
		$plan_id = (int) $wpdb->insert_id;
		$items   = self::build_schedule( $plan_type, $total_amount, $schedule );
		foreach ( $items as $i => $item ) {
			$wpdb->insert(
				LNKup_Financial_Tables::installments_table(),
				array(
					'plan_id'      => $plan_id,
					'sequence_num' => $i + 1,
					'amount'       => $item['amount'],
					'due_date'     => $item['due_date'],
					'status'       => 'pending',
				)
			);
		}
		LNKup_Financial_Audit::log( 'installment_plan_created', 'installment_plan', $plan_id, array( 'amount' => $total_amount ) );
		return $plan_id;
	}

	public static function build_schedule( $plan_type, $total, $custom = array() ) {
		$items = array();
		switch ( $plan_type ) {
			case '50_50':
				$items[] = array( 'amount' => round( $total / 2, 2 ), 'due_date' => gmdate( 'Y-m-d' ) );
				$items[] = array( 'amount' => round( $total / 2, 2 ), 'due_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
				break;
			case 'weekly':
				$weeks = max( 2, absint( $custom['weeks'] ?? 4 ) );
				$each  = round( $total / $weeks, 2 );
				for ( $i = 0; $i < $weeks; $i++ ) {
					$items[] = array( 'amount' => $each, 'due_date' => gmdate( 'Y-m-d', strtotime( '+' . ( $i * 7 ) . ' days' ) ) );
				}
				break;
			case 'monthly':
				$months = max( 2, absint( $custom['months'] ?? 3 ) );
				$each   = round( $total / $months, 2 );
				for ( $i = 0; $i < $months; $i++ ) {
					$items[] = array( 'amount' => $each, 'due_date' => gmdate( 'Y-m-d', strtotime( '+' . $i . ' months' ) ) );
				}
				break;
			case 'custom':
				foreach ( $custom as $row ) {
					if ( empty( $row['amount'] ) ) {
						continue;
					}
					$items[] = array(
						'amount'   => (float) $row['amount'],
						'due_date' => ! empty( $row['due_date'] ) ? sanitize_text_field( $row['due_date'] ) : gmdate( 'Y-m-d' ),
					);
				}
				break;
			default:
				$items[] = array( 'amount' => $total, 'due_date' => gmdate( 'Y-m-d' ) );
		}
		return $items;
	}

	public static function next_for_application( $application_id ) {
		global $wpdb;
		$plan = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id FROM ' . LNKup_Financial_Tables::installment_plans_table() . ' WHERE application_id = %d AND status = %s ORDER BY id DESC LIMIT 1', $application_id, 'active' ),
			ARRAY_A
		);
		if ( ! $plan ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::installments_table() . " WHERE plan_id = %d AND status = 'pending' ORDER BY sequence_num ASC LIMIT 1",
				(int) $plan['id']
			),
			ARRAY_A
		);
	}

	public static function mark_paid( $installment_id, $payment_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::installments_table();
		$wpdb->update(
			$table,
			array(
				'status'          => 'paid',
				'paid_at'         => current_time( 'mysql' ),
				'payment_post_id' => $payment_id,
			),
			array( 'id' => $installment_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'installment_paid', 'installment', $installment_id );
	}

	public static function upcoming_for_vendor( $vendor_id ) {
		global $wpdb;
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_vendor_user_id',
				'meta_value'     => $vendor_id,
			)
		);
		$upcoming = array();
		foreach ( $apps as $app_id ) {
			$next = self::next_for_application( $app_id );
			if ( $next ) {
				$upcoming[] = array(
					'application_id' => $app_id,
					'amount'         => (float) $next['amount'],
					'due_date'       => $next['due_date'],
					'sequence'       => (int) $next['sequence_num'],
				);
			}
		}
		return $upcoming;
	}

	public static function timeline_for_application( $application_id ) {
		global $wpdb;
		$plan = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::installment_plans_table() . ' WHERE application_id = %d ORDER BY id DESC LIMIT 1', $application_id ),
			ARRAY_A
		);
		if ( ! $plan ) {
			return array();
		}
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . LNKup_Financial_Tables::installments_table() . ' WHERE plan_id = %d ORDER BY sequence_num ASC', (int) $plan['id'] ),
			ARRAY_A
		);
	}

	public static function apply_to_payment( $payment_id, $application_id ) {
		$next = self::next_for_application( $application_id );
		if ( $next ) {
			update_post_meta( $payment_id, '_lnkup_installment_amount', (float) $next['amount'] );
			update_post_meta( $payment_id, '_lnkup_installment_id', (int) $next['id'] );
			return (float) $next['amount'];
		}
		return 0;
	}
}
