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
			'profile'            => $profile,
			'w9_on_file'         => ! empty( $profile['w9_on_file'] ),
			'form_1099_eligible' => self::is_1099_eligible( $user_id ),
			'documents'          => $docs,
			'yearly'             => $yearly,
			'w9_pdf_url'         => rest_url( 'lnkup/v1/financial/tax-center/w9/pdf' ),
			'form_1099_pdf_url'  => rest_url( 'lnkup/v1/financial/tax-center/1099/pdf' ),
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
				'user_id'       => absint( $user_id ),
				'doc_type'      => sanitize_key( $document_type ),
				'attachment_id' => absint( $attachment_id ),
				'status'        => 'submitted',
				'created_at'    => current_time( 'mysql' ),
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

	/**
	 * Generate a LNKup W-9 summary PDF (not an official IRS form).
	 *
	 * @param int $user_id User ID.
	 * @return array|WP_Error { binary, filename }
	 */
	public static function generate_w9_pdf( $user_id ) {
		$summary = self::get_summary( $user_id );
		$user    = get_userdata( $user_id );
		$name    = $user ? $user->display_name : '';
		$email   = $user ? $user->user_email : '';
		$profile = is_array( $summary['profile'] ?? null ) ? $summary['profile'] : array();
		$last4   = isset( $profile['tax_id_last4'] ) ? (string) $profile['tax_id_last4'] : '';
		$html    = self::pdf_shell(
			__( 'LNKup W-9 summary', 'lnkup-marketplace' ),
			array(
				array( __( 'Legal / display name', 'lnkup-marketplace' ), $name ),
				array( __( 'Email', 'lnkup-marketplace' ), $email ),
				array( __( 'W-9 on file', 'lnkup-marketplace' ), ! empty( $summary['w9_on_file'] ) ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No', 'lnkup-marketplace' ) ),
				array( __( 'Submitted', 'lnkup-marketplace' ), (string) ( $profile['w9_submitted_at'] ?? '—' ) ),
				array( __( 'TIN last 4', 'lnkup-marketplace' ), $last4 ? ( '••••' . $last4 ) : __( 'Not on file', 'lnkup-marketplace' ) ),
			),
			__( 'This is an LNKup record summary, not an official IRS Form W-9. Do not file this PDF with the IRS.', 'lnkup-marketplace' )
		);
		return self::render_pdf( $html, 'lnkup-w9-summary-' . absint( $user_id ) . '.pdf' );
	}

	/**
	 * Generate a LNKup 1099 year summary PDF (not an official 1099-NEC).
	 *
	 * @param int $user_id User ID.
	 * @param int $year    Tax year.
	 * @return array|WP_Error { binary, filename }
	 */
	public static function generate_1099_pdf( $user_id, $year = 0 ) {
		$year    = $year ? absint( $year ) : (int) gmdate( 'Y' );
		$summary = self::get_summary( $user_id );
		$yearly  = self::yearly_summary( $user_id, $year );
		$user    = get_userdata( $user_id );
		$name    = $user ? $user->display_name : '';
		$eligible = self::is_1099_eligible( $user_id );
		$html     = self::pdf_shell(
			sprintf(
				/* translators: %d: tax year */
				__( 'LNKup 1099 year summary (%d)', 'lnkup-marketplace' ),
				$year
			),
			array(
				array( __( 'Payee / payer name', 'lnkup-marketplace' ), $name ),
				array( __( '1099 eligible ($600+)', 'lnkup-marketplace' ), $eligible ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No', 'lnkup-marketplace' ) ),
				array( __( 'Gross / spent', 'lnkup-marketplace' ), '$' . number_format( (float) ( $yearly['gross'] ?? $yearly['spent'] ?? 0 ), 2 ) ),
				array( __( 'Fees', 'lnkup-marketplace' ), '$' . number_format( (float) ( $yearly['fees'] ?? 0 ), 2 ) ),
				array( __( 'Net', 'lnkup-marketplace' ), '$' . number_format( (float) ( $yearly['net'] ?? 0 ), 2 ) ),
				array( __( 'Refunds', 'lnkup-marketplace' ), '$' . number_format( (float) ( $yearly['refunds'] ?? 0 ), 2 ) ),
				array( __( 'W-9 on file', 'lnkup-marketplace' ), ! empty( $summary['w9_on_file'] ) ? __( 'Yes', 'lnkup-marketplace' ) : __( 'No', 'lnkup-marketplace' ) ),
			),
			__( 'This is an LNKup eligibility and year summary, not an official IRS Form 1099-NEC. Official e-file remains a separate step.', 'lnkup-marketplace' )
		);
		return self::render_pdf( $html, 'lnkup-1099-summary-' . $year . '-' . absint( $user_id ) . '.pdf' );
	}

	/**
	 * Shared HTML wrapper for tax PDFs.
	 *
	 * @param string $title      Title.
	 * @param array  $rows       Label/value pairs.
	 * @param string $disclaimer Footer.
	 * @return string
	 */
	private static function pdf_shell( $title, $rows, $disclaimer ) {
		$site = esc_html( get_bloginfo( 'name' ) );
		$generated = esc_html( current_time( 'mysql' ) );
		$html = '<html><head><meta charset="utf-8"><style>
			body{font-family:DejaVu Sans,sans-serif;color:#111;font-size:13px;margin:36px}
			h1{font-size:20px;margin:0 0 8px}
			.meta{color:#555;margin-bottom:20px}
			table{width:100%;border-collapse:collapse}
			td{padding:8px 6px;border-bottom:1px solid #ddd;vertical-align:top}
			td.label{width:40%;color:#444}
			.foot{margin-top:28px;font-size:11px;color:#555}
		</style></head><body>';
		$html .= '<h1>' . esc_html( $title ) . '</h1>';
		$html .= '<p class="meta">' . $site . ' · ' . $generated . '</p><table>';
		foreach ( $rows as $row ) {
			$html .= '<tr><td class="label">' . esc_html( $row[0] ) . '</td><td>' . esc_html( $row[1] ) . '</td></tr>';
		}
		$html .= '</table><p class="foot">' . esc_html( $disclaimer ) . '</p></body></html>';
		return $html;
	}

	/**
	 * Render HTML to PDF binary via Dompdf.
	 *
	 * @param string $html     HTML.
	 * @param string $filename Download name.
	 * @return array|WP_Error
	 */
	private static function render_pdf( $html, $filename ) {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			return new WP_Error(
				'lnkup_pdf_unavailable',
				__( 'PDF library is not installed. Run composer install in the plugin directory.', 'lnkup-marketplace' ),
				array( 'status' => 503 )
			);
		}
		try {
			$dompdf = new \Dompdf\Dompdf(
				array(
					'isRemoteEnabled' => false,
					'isHtml5ParserEnabled' => true,
				)
			);
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'letter', 'portrait' );
			$dompdf->render();
			return array(
				'binary'   => $dompdf->output(),
				'filename' => sanitize_file_name( $filename ),
			);
		} catch ( Throwable $e ) {
			return new WP_Error(
				'lnkup_pdf_failed',
				__( 'Could not generate the tax PDF.', 'lnkup-marketplace' ),
				array( 'status' => 500 )
			);
		}
	}
}
