<?php
/**
 * Promo and referral codes.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Promo_Codes {

	public static function create( $data ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::promo_codes_table();
		$code  = strtoupper( sanitize_text_field( $data['code'] ?? wp_generate_password( 8, false, false ) ) );
		$wpdb->insert(
			$table,
			array(
				'code'               => $code,
				'discount_type'      => sanitize_key( $data['discount_type'] ?? 'percent' ),
				'discount_value'     => (float) ( $data['discount_value'] ?? 0 ),
				'scope'              => sanitize_key( $data['scope'] ?? 'marketplace' ),
				'organizer_user_id'  => absint( $data['organizer_user_id'] ?? 0 ),
				'category'           => sanitize_text_field( $data['category'] ?? '' ),
				'usage_limit'        => absint( $data['usage_limit'] ?? 0 ),
				'first_time_only'    => ! empty( $data['first_time_only'] ) ? 1 : 0,
				'expires_at'         => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
				'status'             => 'active',
				'created_at'         => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		LNKup_Financial_Audit::log( 'promo_created', 'promo_code', $id );
		return $id;
	}

	public static function validate( $code, $user_id, $context = array() ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::promo_codes_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s AND status = 'active'", strtoupper( sanitize_text_field( $code ) ) ),
			ARRAY_A
		);
		if ( ! $row ) {
			return new WP_Error( 'invalid', __( 'Invalid promo code.', 'lnkup-marketplace' ) );
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
			return new WP_Error( 'expired', __( 'Promo code has expired.', 'lnkup-marketplace' ) );
		}
		if ( (int) $row['usage_limit'] > 0 && (int) $row['usage_count'] >= (int) $row['usage_limit'] ) {
			return new WP_Error( 'limit', __( 'Promo code usage limit reached.', 'lnkup-marketplace' ) );
		}
		if ( ! empty( $row['first_time_only'] ) && self::user_has_paid_before( $user_id ) ) {
			return new WP_Error( 'first_time', __( 'Promo code is for first-time vendors only.', 'lnkup-marketplace' ) );
		}
		if ( 'organizer' === $row['scope'] && ! empty( $context['organizer_user_id'] ) && (int) $row['organizer_user_id'] !== (int) $context['organizer_user_id'] ) {
			return new WP_Error( 'scope', __( 'Promo code not valid for this organizer.', 'lnkup-marketplace' ) );
		}
		return $row;
	}

	public static function calculate_discount( $promo, $subtotal ) {
		if ( 'fixed' === $promo['discount_type'] ) {
			return min( (float) $promo['discount_value'], (float) $subtotal );
		}
		return round( (float) $subtotal * ( (float) $promo['discount_value'] / 100 ), 2 );
	}

	public static function redeem( $code, $user_id, $payment_id, $discount_amount ) {
		$promo = self::validate( $code, $user_id );
		if ( is_wp_error( $promo ) ) {
			return $promo;
		}
		global $wpdb;
		$already = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . LNKup_Financial_Tables::promo_redemptions_table() . ' WHERE payment_post_id = %d',
				(int) $payment_id
			)
		);
		if ( $already > 0 ) {
			return new WP_Error( 'promo_already_applied', __( 'A promo code is already applied to this booking.', 'lnkup-marketplace' ), array( 'status' => 409 ) );
		}
		$wpdb->insert(
			LNKup_Financial_Tables::promo_redemptions_table(),
			array(
				'promo_code_id'   => (int) $promo['id'],
				'user_id'         => $user_id,
				'payment_post_id' => $payment_id,
				'discount_amount' => $discount_amount,
				'redeemed_at'     => current_time( 'mysql' ),
			)
		);
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Financial_Tables::promo_codes_table() . ' SET usage_count = usage_count + 1 WHERE id = %d', (int) $promo['id'] ) );
		update_post_meta( $payment_id, '_lnkup_promo_code', $promo['code'] );
		update_post_meta( $payment_id, '_lnkup_promo_discount', $discount_amount );
		LNKup_Financial_Audit::log( 'promo_redeemed', 'promo_code', (int) $promo['id'], array( 'amount' => $discount_amount ) );
		return true;
	}

	public static function user_has_paid_before( $user_id ) {
		$payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_payment_status', 'value' => 'paid' ),
				),
			)
		);
		return ! empty( $payments );
	}

	public static function list_for_organizer( $organizer_id ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::promo_codes_table() . ' WHERE organizer_user_id = %d OR scope = %s ORDER BY created_at DESC',
				$organizer_id,
				'marketplace'
			),
			ARRAY_A
		);
	}
}
