<?php
/**
 * Insurance marketplace placeholder — offer catalog and referral tracking.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Insurance_Marketplace {

	/**
	 * List available insurance offers.
	 */
	public static function list_offers() {
		global $wpdb;
		$table = LNKup_Treasury_Tables::insurance_offers_table();
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
				'id'          => 0,
				'name'        => __( 'Event Liability Coverage', 'lnkup-marketplace' ),
				'description' => __( 'General liability protection for vendor events.', 'lnkup-marketplace' ),
				'provider'    => 'Partner Insurance Co.',
				'cta_url'     => '#insurance-inquiry',
				'status'      => 'active',
			),
			array(
				'id'          => 0,
				'name'        => __( 'Equipment Protection', 'lnkup-marketplace' ),
				'description' => __( 'Coverage for booth equipment and inventory.', 'lnkup-marketplace' ),
				'provider'    => 'Vendor Shield',
				'cta_url'     => '#insurance-inquiry',
				'status'      => 'active',
			),
		);
	}

	/**
	 * Record CTA click / inquiry.
	 *
	 * @param int $offer_id Offer ID.
	 * @param int $user_id  User ID.
	 */
	public static function record_inquiry( $offer_id, $user_id ) {
		LNKup_Financial_Audit::log( 'insurance_inquiry', 'insurance', $offer_id, array( 'user_id' => $user_id ) );
		return array( 'status' => 'recorded', 'message' => __( 'Inquiry recorded. A partner will contact you.', 'lnkup-marketplace' ) );
	}
}
