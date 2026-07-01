<?php
/**
 * Tax document center — wraps LNKup_Tax_Profiles.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Tax_Center {

	/**
	 * Get tax profile summary for user.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_summary( $user_id ) {
		$profile = LNKup_Tax_Profiles::get_or_create( $user_id );
		$docs    = self::list_documents( $user_id );
		$yearly  = self::yearly_summary( $user_id, (int) gmdate( 'Y' ) );
		return array(
			'profile'           => $profile,
			'w9_on_file'        => ! empty( $profile['w9_on_file'] ),
			'form_1099_eligible' => self::is_1099_eligible( $user_id ),
			'documents'         => $docs,
			'yearly'            => $yearly,
		);
	}

	/**
	 * List uploaded tax documents (attachment IDs only).
	 *
	 * @param int $user_id User ID.
	 */
	public static function list_documents( $user_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::tax_documents_table();
		if ( ! LNKup_Financial_Tables::table_exists( $table ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", absint( $user_id ) ),
			ARRAY_A
		);
	}

	/**
	 * Store tax document reference.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $document_type Document type.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function store_document( $user_id, $document_type, $attachment_id ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::tax_documents_table();
		$wpdb->insert(
			$table,
			array(
				'user_id'        => absint( $user_id ),
				'document_type'  => sanitize_key( $document_type ),
				'attachment_id'  => absint( $attachment_id ),
				'status'         => 'submitted',
				'created_at'     => current_time( 'mysql' ),
			)
		);
		if ( 'w9' === $document_type ) {
			LNKup_Tax_Profiles::update( $user_id, array( 'w9_on_file' => 1 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Yearly tax summary.
	 *
	 * @param int $user_id User ID.
	 * @param int $year    Tax year.
	 */
	public static function yearly_summary( $user_id, $year ) {
		$start = $year . '-01-01';
		$end   = $year . '-12-31 23:59:59';
		$role  = LNKup_Roles::is_organizer( $user_id ) ? 'organizer' : 'vendor';
		if ( 'organizer' === $role && class_exists( 'LNKup_Financial_Reports' ) ) {
			$report = LNKup_Financial_Reports::organizer_report( $user_id );
			return array(
				'year'     => $year,
				'gross'    => (float) ( $report['gross_revenue'] ?? 0 ),
				'fees'     => (float) ( $report['platform_fees'] ?? 0 ),
				'net'      => (float) ( $report['revenue'] ?? 0 ),
				'refunds'  => (float) ( $report['refunds'] ?? 0 ),
			);
		}
		$report = LNKup_Financial_Reports::vendor_report( $user_id );
		return array(
			'year'    => $year,
			'spent'   => (float) ( $report['lifetime_spend'] ?? 0 ),
			'refunds' => (float) ( $report['refunds'] ?? 0 ),
		);
	}

	/**
	 * Check 1099 eligibility (>$600 threshold).
	 *
	 * @param int $user_id User ID.
	 */
	public static function is_1099_eligible( $user_id ) {
		$summary = self::yearly_summary( $user_id, (int) gmdate( 'Y' ) );
		$amount  = (float) ( $summary['gross'] ?? $summary['spent'] ?? 0 );
		return $amount >= 600;
	}

	/**
	 * Export tax data CSV.
	 *
	 * @param int $user_id User ID.
	 * @param int $year    Year.
	 */
	public static function export_csv( $user_id, $year ) {
		global $wpdb;
		$table = LNKup_Treasury_Tables::tax_exports_table();
		$summary = self::yearly_summary( $user_id, $year );
		$csv = "year,gross,fees,net,refunds\n";
		$csv .= sprintf(
			"%d,%.2f,%.2f,%.2f,%.2f\n",
			$year,
			(float) ( $summary['gross'] ?? $summary['spent'] ?? 0 ),
			(float) ( $summary['fees'] ?? 0 ),
			(float) ( $summary['net'] ?? 0 ),
			(float) ( $summary['refunds'] ?? 0 )
		);
		$wpdb->insert(
			$table,
			array(
				'user_id'    => absint( $user_id ),
				'export_year' => $year,
				'format'     => 'csv',
				'status'     => 'completed',
				'created_at' => current_time( 'mysql' ),
			)
		);
		return $csv;
	}
}
