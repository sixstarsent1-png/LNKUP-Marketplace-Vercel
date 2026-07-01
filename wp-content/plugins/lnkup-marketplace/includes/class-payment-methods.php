<?php
/**
 * Saved payment methods (tokenized refs only).
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Payment_Methods {

	public static function list_for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, provider, method_type, brand, last4, exp_month, exp_year, is_default, is_expired, created_at FROM ' . LNKup_Financial_Tables::payment_methods_table() . ' WHERE user_id = %d ORDER BY is_default DESC, created_at DESC',
				$user_id
			),
			ARRAY_A
		);
	}

	public static function add_placeholder( $user_id, $args = array() ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Financial_Tables::payment_methods_table(),
			array(
				'user_id'              => $user_id,
				'provider'             => sanitize_key( $args['provider'] ?? 'stripe' ),
				'provider_customer_id' => sanitize_text_field( $args['customer_id'] ?? '' ),
				'provider_method_id'   => sanitize_text_field( $args['method_id'] ?? '' ),
				'method_type'          => sanitize_key( $args['method_type'] ?? 'card' ),
				'brand'                => sanitize_text_field( $args['brand'] ?? '' ),
				'last4'                => sanitize_text_field( $args['last4'] ?? '' ),
				'exp_month'            => absint( $args['exp_month'] ?? 0 ),
				'exp_year'             => absint( $args['exp_year'] ?? 0 ),
				'is_default'           => ! empty( $args['is_default'] ) ? 1 : 0,
				'created_at'           => current_time( 'mysql' ),
			)
		);
		LNKup_Financial_Audit::log( 'payment_method_added', 'payment_method', (int) $wpdb->insert_id );
		return (int) $wpdb->insert_id;
	}

	public static function set_default( $method_id, $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::payment_methods_table();
		$wpdb->update( $table, array( 'is_default' => 0 ), array( 'user_id' => $user_id ), array( '%d' ), array( '%d' ) );
		$wpdb->update( $table, array( 'is_default' => 1 ), array( 'id' => $method_id, 'user_id' => $user_id ), array( '%d' ), array( '%d', '%d' ) );
	}

	/**
	 * Upsert payment method from provider sync.
	 *
	 * @param int   $user_id User ID.
	 * @param array $args    Provider method data.
	 */
	public static function upsert_from_provider( $user_id, $args ) {
		global $wpdb;
		$table      = LNKup_Financial_Tables::payment_methods_table();
		$method_id  = sanitize_text_field( $args['provider_method_id'] ?? '' );
		if ( ! $method_id ) {
			return 0;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND provider_method_id = %s LIMIT 1",
				$user_id,
				$method_id
			)
		);
		$row = array(
			'user_id'              => $user_id,
			'provider'             => sanitize_key( $args['provider'] ?? 'stripe' ),
			'provider_customer_id' => sanitize_text_field( $args['customer_id'] ?? '' ),
			'provider_method_id'   => $method_id,
			'method_type'          => sanitize_key( $args['method_type'] ?? 'card' ),
			'brand'                => sanitize_text_field( $args['brand'] ?? '' ),
			'last4'                => sanitize_text_field( $args['last4'] ?? '' ),
			'exp_month'            => absint( $args['exp_month'] ?? 0 ),
			'exp_year'             => absint( $args['exp_year'] ?? 0 ),
			'label'                => sanitize_text_field( $args['label'] ?? '' ),
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( $table, $row );
		return (int) $wpdb->insert_id;
	}

	public static function remove( $method_id, $user_id ) {
		global $wpdb;
		$wpdb->delete(
			LNKup_Financial_Tables::payment_methods_table(),
			array( 'id' => absint( $method_id ), 'user_id' => absint( $user_id ) ),
			array( '%d', '%d' )
		);
	}

	public static function rename( $method_id, $user_id, $label ) {
		global $wpdb;
		$wpdb->update(
			LNKup_Financial_Tables::payment_methods_table(),
			array( 'label' => sanitize_text_field( $label ) ),
			array( 'id' => absint( $method_id ), 'user_id' => absint( $user_id ) ),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	public static function mark_expired_cards() {
		global $wpdb;
		$table = LNKup_Financial_Tables::payment_methods_table();
		$y = (int) gmdate( 'Y' );
		$m = (int) gmdate( 'n' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_expired = 1 WHERE method_type = 'card' AND (exp_year < %d OR (exp_year = %d AND exp_month < %d))",
				$y,
				$y,
				$m
			)
		);
	}
}
