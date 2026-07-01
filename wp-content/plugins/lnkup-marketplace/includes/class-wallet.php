<?php
/**
 * Marketplace wallet.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Wallet {

	public static function get_or_create( $user_id, $role_type = 'vendor' ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::wallets_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND role_type = %s", $user_id, $role_type ),
			ARRAY_A
		);
		if ( $row ) {
			return $row;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'role_type'  => $role_type,
				'balance'    => 0,
				'currency'   => 'USD',
				'updated_at' => $now,
			)
		);
		return self::get_or_create( $user_id, $role_type );
	}

	public static function get_balance( $user_id, $role_type = 'vendor' ) {
		$wallet = self::get_or_create( $user_id, $role_type );
		return (float) ( $wallet['balance'] ?? 0 );
	}

	public static function credit( $user_id, $amount, $source, $source_id = 0, $role_type = 'vendor', $note = '' ) {
		global $wpdb;
		$wallet = self::get_or_create( $user_id, $role_type );
		$new_bal = (float) $wallet['balance'] + (float) $amount;
		$wpdb->update(
			LNKup_Financial_Tables::wallets_table(),
			array( 'balance' => $new_bal, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $wallet['id'] ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		$wpdb->insert(
			LNKup_Financial_Tables::wallet_entries_table(),
			array(
				'wallet_id'     => (int) $wallet['id'],
				'entry_type'    => 'credit',
				'amount'        => $amount,
				'balance_after' => $new_bal,
				'source'        => sanitize_key( $source ),
				'source_id'     => absint( $source_id ),
				'note'          => sanitize_text_field( $note ),
				'created_at'    => current_time( 'mysql' ),
			)
		);
		LNKup_Financial_Audit::log( 'wallet_credit', 'wallet', (int) $wallet['id'], array( 'amount' => $amount ) );
		return $new_bal;
	}

	public static function debit( $user_id, $amount, $source, $source_id = 0, $role_type = 'vendor', $note = '' ) {
		$balance = self::get_balance( $user_id, $role_type );
		if ( $balance < $amount ) {
			return new WP_Error( 'insufficient', __( 'Insufficient wallet balance.', 'lnkup-marketplace' ) );
		}
		global $wpdb;
		$wallet  = self::get_or_create( $user_id, $role_type );
		$new_bal = $balance - (float) $amount;
		$wpdb->update(
			LNKup_Financial_Tables::wallets_table(),
			array( 'balance' => $new_bal, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $wallet['id'] ),
			array( '%f', '%s' ),
			array( '%d' )
		);
		$wpdb->insert(
			LNKup_Financial_Tables::wallet_entries_table(),
			array(
				'wallet_id'     => (int) $wallet['id'],
				'entry_type'    => 'debit',
				'amount'        => $amount,
				'balance_after' => $new_bal,
				'source'        => sanitize_key( $source ),
				'source_id'     => absint( $source_id ),
				'note'          => sanitize_text_field( $note ),
				'created_at'    => current_time( 'mysql' ),
			)
		);
		LNKup_Financial_Audit::log( 'wallet_debit', 'wallet', (int) $wallet['id'], array( 'amount' => $amount ) );
		return $new_bal;
	}

	public static function entries( $user_id, $role_type = 'vendor', $limit = 25 ) {
		global $wpdb;
		$wallet = self::get_or_create( $user_id, $role_type );
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . LNKup_Financial_Tables::wallet_entries_table() . ' WHERE wallet_id = %d ORDER BY created_at DESC LIMIT %d',
				(int) $wallet['id'],
				min( 50, max( 1, absint( $limit ) ) )
			),
			ARRAY_A
		);
	}
}
