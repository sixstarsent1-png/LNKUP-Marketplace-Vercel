<?php
/**
 * Tax profile placeholders.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Tax_Profiles {

	public static function get_or_create( $user_id ) {
		global $wpdb;
		$table = LNKup_Financial_Tables::tax_profiles_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		if ( $row ) {
			return $row;
		}
		$wpdb->insert(
			$table,
			array(
				'user_id'    => $user_id,
				'updated_at' => current_time( 'mysql' ),
			)
		);
		return self::get_or_create( $user_id );
	}

	public static function update_w9( $user_id, $on_file = true ) {
		global $wpdb;
		self::get_or_create( $user_id );
		$wpdb->update(
			LNKup_Financial_Tables::tax_profiles_table(),
			array(
				'w9_on_file'       => $on_file ? 1 : 0,
				'w9_submitted_at'  => $on_file ? current_time( 'mysql' ) : null,
				'updated_at'       => current_time( 'mysql' ),
			),
			array( 'user_id' => $user_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		LNKup_Financial_Audit::log( 'w9_updated', 'tax_profile', $user_id );
	}

	/**
	 * Partial update for tax profile columns.
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Column => value.
	 */
	public static function update( $user_id, $fields ) {
		global $wpdb;
		self::get_or_create( $user_id );
		$allowed = array( 'w9_on_file', 'w9_submitted_at', 'form_1099_eligible', 'tax_id_last4', 'sales_tax_rate' );
		$data    = array( 'updated_at' => current_time( 'mysql' ) );
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = $fields[ $key ];
			}
		}
		if ( isset( $data['w9_on_file'] ) && ! empty( $data['w9_on_file'] ) && empty( $data['w9_submitted_at'] ) ) {
			$data['w9_submitted_at'] = current_time( 'mysql' );
		}
		$wpdb->update(
			LNKup_Financial_Tables::tax_profiles_table(),
			$data,
			array( 'user_id' => absint( $user_id ) )
		);
		LNKup_Financial_Audit::log( 'tax_profile_updated', 'tax_profile', $user_id );
	}

	public static function format( $row ) {
		if ( ! $row ) {
			return null;
		}
		$eligible = class_exists( 'LNKup_Tax_Center' )
			? LNKup_Tax_Center::is_1099_eligible( (int) $row['user_id'] )
			: (bool) $row['form_1099_eligible'];
		return array(
			'user_id'            => (int) $row['user_id'],
			'w9_on_file'         => (bool) $row['w9_on_file'],
			'w9_submitted_at'    => $row['w9_submitted_at'],
			'form_1099_eligible' => $eligible,
			'tax_id_last4'       => isset( $row['tax_id_last4'] ) ? (string) $row['tax_id_last4'] : '',
			'sales_tax_rate'     => (float) $row['sales_tax_rate'],
			'w9_pdf_url'         => rest_url( 'lnkup/v1/financial/tax-center/w9/pdf' ),
			'form_1099_pdf_url'  => rest_url( 'lnkup/v1/financial/tax-center/1099/pdf' ),
		);
	}
}
