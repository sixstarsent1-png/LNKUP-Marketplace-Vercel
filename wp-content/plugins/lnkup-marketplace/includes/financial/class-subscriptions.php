<?php
/**
 * Subscription plans and user subscriptions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Subscriptions {

	/**
	 * List available plans.
	 *
	 * @param string $role vendor|organizer.
	 */
	public static function list_plans( $role = '' ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::subscription_plans_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		$sql = "SELECT * FROM {$table} WHERE status = 'active'";
		if ( $role ) {
			$sql .= $wpdb->prepare( ' AND role_type = %s', sanitize_key( $role ) );
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get active subscription for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_active( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::subscriptions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, p.name AS plan_name, p.slug AS plan_slug FROM {$table} s LEFT JOIN " . LNKup_Treasury_Tables::subscription_plans_table() . " p ON p.id = s.plan_id WHERE s.user_id = %d AND s.status IN ('active','trialing') ORDER BY s.id DESC LIMIT 1",
				absint( $user_id )
			),
			ARRAY_A
		);
	}

	/**
	 * Subscribe user to plan (simulated or Stripe Billing).
	 *
	 * @param int $user_id User ID.
	 * @param int $plan_id Plan ID.
	 */
	public static function subscribe( $user_id, $plan_id ) {
		global $wpdb;
		$plans_table = LNKup_Treasury_Tables::subscription_plans_table();
		$sub_table   = LNKup_Treasury_Tables::subscriptions_table();
		$plan        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$plans_table} WHERE id = %d", absint( $plan_id ) ), ARRAY_A );
		if ( ! $plan ) {
			return new WP_Error( 'invalid_plan', __( 'Plan not found.', 'lnkup-marketplace' ) );
		}
		$settings = lnkup_get_settings();
		$provider = ! empty( $settings['stripe_billing_enabled'] ) ? 'stripe' : 'simulated';
		$now      = current_time( 'mysql' );
		$wpdb->insert(
			$sub_table,
			array(
				'user_id'            => absint( $user_id ),
				'plan_id'            => absint( $plan_id ),
				'status'             => 'active',
				'provider'           => $provider,
				'current_period_end' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 month' ) ),
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		LNKup_Financial_Audit::log( 'subscription_created', 'subscription', (int) $wpdb->insert_id, array( 'plan' => $plan['slug'] ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Cancel subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $user_id         User ID.
	 */
	public static function cancel( $subscription_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::subscriptions_table();
		$wpdb->update(
			$table,
			array( 'status' => 'cancelled', 'cancelled_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => absint( $subscription_id ), 'user_id' => absint( $user_id ) )
		);
		return true;
	}
}
