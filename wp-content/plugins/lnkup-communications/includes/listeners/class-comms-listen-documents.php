<?php
/**
 * Document review outcomes. Required-document reminders come from the core
 * financial cron (delegated) and from the "paid" transition; expiry reminders
 * from the daily automation. Only documents the opportunity requires are requested
 * (LNKup_Verification decides that).
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Listen_Documents {

	public static function init(): void {
		add_action( 'lnkup_document_status_changed', array( __CLASS__, 'on_status' ), 20, 3 );
	}

	public static function label( int $doc_id ): string {
		$type  = (string) get_post_meta( $doc_id, '_lnkup_document_type', true );
		$types = class_exists( 'LNKup_Documents' ) && method_exists( 'LNKup_Documents', 'document_types' ) ? (array) LNKup_Documents::document_types() : array();
		return (string) ( $types[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) ) );
	}

	/**
	 * @param int    $doc_id Document.
	 * @param string $old    Previous verification status.
	 * @param string $new    New verification status.
	 */
	public static function on_status( $doc_id, $old, $new ): void {
		unset( $old );
		$doc_id = absint( $doc_id );
		$vendor = (int) get_post_meta( $doc_id, '_lnkup_vendor_user_id', true );
		if ( ! $vendor ) {
			return;
		}
		$ctx = array(
			'document_id'    => $doc_id,
			'document_label' => self::label( $doc_id ),
			'review_note'    => sanitize_text_field( (string) get_post_meta( $doc_id, '_lnkup_admin_notes', true ) ),
		);
		if ( 'approved' === $new ) {
			LNKup_Comms_Engine::dispatch( 'v_document_approved', $vendor, $ctx, array( 'source' => 'document_approved' ) );
		} elseif ( 'rejected' === $new ) {
			LNKup_Comms_Engine::dispatch( 'v_document_rejected', $vendor, $ctx, array( 'window' => gmdate( 'YmdHi' ), 'source' => 'document_rejected' ) );
		}
	}
}
