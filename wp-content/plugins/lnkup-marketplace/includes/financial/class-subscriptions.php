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
		$sql .= ' ORDER BY id ASC';
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
				"SELECT s.*, p.name AS plan_name, p.slug AS plan_slug FROM {$table} s LEFT JOIN " . LNKup_Treasury_Tables::subscription_plans_table() . " p ON p.id = s.plan_id WHERE s.user_id = %d AND s.status IN ('active','trialing','incomplete') ORDER BY s.id DESC LIMIT 1",
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
	 * @return int|WP_Error|array Local subscription id, or array with client_secret when Stripe incomplete.
	 */
	public static function subscribe( $user_id, $plan_id ) {
		global $wpdb;
		$plans_table = LNKup_Treasury_Tables::subscription_plans_table();
		$sub_table   = LNKup_Treasury_Tables::subscriptions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $plans_table ) || ! LNKup_Financial_Tables::table_exists( $sub_table ) ) {
			return new WP_Error( 'subscriptions_unavailable', __( 'Subscriptions are not available.', 'lnkup-marketplace' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$plan = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$plans_table} WHERE id = %d", absint( $plan_id ) ), ARRAY_A );
		if ( ! $plan ) {
			return new WP_Error( 'invalid_plan', __( 'Plan not found.', 'lnkup-marketplace' ) );
		}

		$settings = lnkup_get_settings();
		$use_stripe = ! empty( $settings['stripe_billing_enabled'] )
			&& ! empty( $plan['stripe_price_id'] )
			&& class_exists( 'LNKup_Stripe_Client' );

		if ( $use_stripe ) {
			return self::subscribe_via_stripe( $user_id, $plan );
		}

		$provider = ! empty( $settings['stripe_billing_enabled'] ) ? 'stripe' : 'simulated';
		$now      = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'subscription_created', 'subscription', $id, array(
			'plan'     => $plan['slug'],
			'provider' => $provider,
			'mode'     => 'simulated',
		) );
		return $id;
	}

	/**
	 * Create Stripe Billing subscription and local mirror row.
	 *
	 * @param int   $user_id User ID.
	 * @param array $plan    Plan row.
	 * @return array|WP_Error
	 */
	private static function subscribe_via_stripe( $user_id, $plan ) {
		global $wpdb;
		$sub_table = LNKup_Treasury_Tables::subscriptions_table();
		$provider  = new LNKup_Stripe_Provider();
		$customer  = $provider->get_or_create_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$client = new LNKup_Stripe_Client();
		$params = array(
			'customer'               => $customer['id'],
			'items'                  => array(
				array( 'price' => sanitize_text_field( $plan['stripe_price_id'] ) ),
			),
			'payment_behavior'       => 'default_incomplete',
			'expand'                 => array( 'latest_invoice.payment_intent' ),
			'metadata'               => array(
				'wp_user_id' => (string) absint( $user_id ),
				'plan_id'    => (string) absint( $plan['id'] ),
				'plan_slug'  => (string) ( $plan['slug'] ?? '' ),
				'platform'   => 'lnkup',
			),
		);

		$idempotency = 'lnkup_sub_' . absint( $user_id ) . '_' . absint( $plan['id'] ) . '_' . gmdate( 'YmdH' );
		$stripe_sub  = $client->create_subscription( $params, $idempotency );
		if ( is_wp_error( $stripe_sub ) ) {
			return $stripe_sub;
		}

		$status = sanitize_key( $stripe_sub['status'] ?? 'incomplete' );
		$period_end = ! empty( $stripe_sub['current_period_end'] )
			? gmdate( 'Y-m-d H:i:s', (int) $stripe_sub['current_period_end'] )
			: null;
		$period_start = ! empty( $stripe_sub['current_period_start'] )
			? gmdate( 'Y-m-d H:i:s', (int) $stripe_sub['current_period_start'] )
			: null;
		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$sub_table,
			array(
				'user_id'              => absint( $user_id ),
				'plan_id'              => absint( $plan['id'] ),
				'status'               => $status,
				'provider'             => 'stripe',
				'provider_ref'         => sanitize_text_field( $stripe_sub['id'] ?? '' ),
				'current_period_start' => $period_start,
				'current_period_end'   => $period_end,
				'metadata'             => wp_json_encode( array( 'stripe_price_id' => $plan['stripe_price_id'] ) ),
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		$id = (int) $wpdb->insert_id;

		$client_secret = '';
		$invoice       = $stripe_sub['latest_invoice'] ?? array();
		if ( is_array( $invoice ) ) {
			$pi = $invoice['payment_intent'] ?? array();
			if ( is_array( $pi ) ) {
				$client_secret = (string) ( $pi['client_secret'] ?? '' );
			}
		}

		LNKup_Financial_Audit::log( 'subscription_created', 'subscription', $id, array(
			'plan'         => $plan['slug'],
			'provider'     => 'stripe',
			'provider_ref' => $stripe_sub['id'] ?? '',
			'status'       => $status,
		) );

		return array(
			'id'              => $id,
			'status'          => $status,
			'provider_ref'    => $stripe_sub['id'] ?? '',
			'client_secret'   => $client_secret,
			'publishable_key' => $client->get_publishable_key(),
		);
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
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
				absint( $subscription_id ),
				absint( $user_id )
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'not_found', __( 'Subscription not found.', 'lnkup-marketplace' ) );
		}

		$settings = lnkup_get_settings();
		$ref      = (string) ( $row['provider_ref'] ?? '' );
		if ( ! empty( $settings['stripe_billing_enabled'] ) && 0 === strpos( $ref, 'sub_' ) && class_exists( 'LNKup_Stripe_Client' ) ) {
			$client = new LNKup_Stripe_Client();
			$result = $client->cancel_subscription( $ref );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'       => 'cancelled',
				'cancelled_at' => current_time( 'mysql' ),
				'updated_at'   => current_time( 'mysql' ),
			),
			array(
				'id'      => absint( $subscription_id ),
				'user_id' => absint( $user_id ),
			)
		);
		LNKup_Financial_Audit::log( 'subscription_cancelled', 'subscription', absint( $subscription_id ), array(
			'provider_ref' => $ref,
		) );
		return true;
	}

	/**
	 * Apply Stripe Billing webhook to local row.
	 *
	 * @param array  $subscription Stripe subscription object.
	 * @param string $type         Event type.
	 */
	public static function sync_from_stripe_event( $subscription, $type ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::subscriptions_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return;
		}

		$ref = sanitize_text_field( $subscription['id'] ?? '' );
		if ( ! $ref ) {
			return;
		}

		$status_map = array(
			'active'             => 'active',
			'trialing'           => 'trialing',
			'incomplete'         => 'incomplete',
			'incomplete_expired' => 'cancelled',
			'past_due'           => 'past_due',
			'canceled'           => 'cancelled',
			'unpaid'             => 'cancelled',
		);
		$raw    = sanitize_key( $subscription['status'] ?? '' );
		$status = $status_map[ $raw ] ?? $raw;
		if ( false !== strpos( (string) $type, 'deleted' ) ) {
			$status = 'cancelled';
		}

		$period_end = ! empty( $subscription['current_period_end'] )
			? gmdate( 'Y-m-d H:i:s', (int) $subscription['current_period_end'] )
			: null;
		$period_start = ! empty( $subscription['current_period_start'] )
			? gmdate( 'Y-m-d H:i:s', (int) $subscription['current_period_start'] )
			: null;

		$data = array(
			'status'               => $status,
			'current_period_start' => $period_start,
			'current_period_end'   => $period_end,
			'updated_at'           => current_time( 'mysql' ),
		);
		if ( 'cancelled' === $status ) {
			$data['cancelled_at'] = current_time( 'mysql' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update( $table, $data, array( 'provider_ref' => $ref ) );
		if ( ! $updated ) {
			$user_id = absint( $subscription['metadata']['wp_user_id'] ?? 0 );
			$plan_id = absint( $subscription['metadata']['plan_id'] ?? 0 );
			if ( $user_id && $plan_id ) {
				$now = current_time( 'mysql' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->insert(
					$table,
					array_merge(
						$data,
						array(
							'user_id'      => $user_id,
							'plan_id'      => $plan_id,
							'provider'     => 'stripe',
							'provider_ref' => $ref,
							'created_at'   => $now,
						)
					)
				);
			}
		}
	}
}
