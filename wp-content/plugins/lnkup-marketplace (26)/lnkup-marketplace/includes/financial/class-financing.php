<?php
/**
 * Financing marketplace placeholder — eligibility and offer CTAs.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Financing {

	/**
	 * List financing offers.
	 */
	public static function list_offers() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::financing_offers_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return self::default_offers();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'active' ORDER BY sort_order ASC", ARRAY_A );
		return $rows ?: self::default_offers();
	}

	/**
	 * Default placeholder offers.
	 */
	private static function default_offers() {
		return array(
			array(
				'id'           => 0,
				'name'         => __( 'Event Cash Advance', 'lnkup-marketplace' ),
				'description'  => __( 'Advance against expected vendor revenue.', 'lnkup-marketplace' ),
				'min_amount'   => 1000,
				'max_amount'   => 50000,
				'apr_range'    => '8-15%',
				'status'       => 'active',
			),
			array(
				'id'           => 0,
				'name'         => __( 'Vendor Equipment Loan', 'lnkup-marketplace' ),
				'description'  => __( 'Finance booth setup and equipment purchases.', 'lnkup-marketplace' ),
				'min_amount'   => 500,
				'max_amount'   => 25000,
				'apr_range'    => '6-12%',
				'status'       => 'active',
			),
		);
	}

	/**
	 * Check basic eligibility.
	 *
	 * @param int   $user_id User ID.
	 * @param float $amount  Requested amount.
	 */
	public static function check_eligibility( $user_id, $amount ) {
		$health = class_exists( 'LNKup_Financial_Health_Score' )
			? LNKup_Financial_Health_Score::get_latest( $user_id, LNKup_Roles::is_organizer( $user_id ) ? 'organizer' : 'vendor' )
			: array( 'score' => 75 );
		$eligible = (int) ( $health['score'] ?? 0 ) >= 60 && $amount <= 50000;
		return array(
			'eligible'    => $eligible,
			'health_score' => (int) ( $health['score'] ?? 0 ),
			'max_amount'  => $eligible ? min( 50000, $amount * 2 ) : 0,
		);
	}

	/**
	 * Record financing inquiry.
	 *
	 * @param int   $offer_id Offer ID.
	 * @param int   $user_id  User ID.
	 * @param float $amount   Requested amount.
	 */
	public static function record_inquiry( $offer_id, $user_id, $amount ) {
		LNKup_Financial_Audit::log( 'financing_inquiry', 'financing', $offer_id, array(
			'user_id' => $user_id,
			'amount'  => $amount,
		) );
		return array( 'status' => 'recorded', 'message' => __( 'Inquiry submitted. A financing partner will review your request.', 'lnkup-marketplace' ) );
	}
}
