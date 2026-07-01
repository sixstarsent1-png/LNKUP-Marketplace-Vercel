<?php
/**
 * Event operations lifecycle stages.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Lifecycle {

	/**
	 * Lifecycle stage definitions.
	 */
	public static function stages() {
		return array(
			'planning'               => __( 'Planning', 'lnkup-marketplace' ),
			'applications_open'      => __( 'Applications Open', 'lnkup-marketplace' ),
			'applications_closed'    => __( 'Applications Closed', 'lnkup-marketplace' ),
			'approvals'              => __( 'Approvals', 'lnkup-marketplace' ),
			'payments'               => __( 'Payments', 'lnkup-marketplace' ),
			'vendor_packets_released'=> __( 'Vendor Packets Released', 'lnkup-marketplace' ),
			'load_in'                => __( 'Load-In', 'lnkup-marketplace' ),
			'check_in'               => __( 'Check-In', 'lnkup-marketplace' ),
			'setup'                  => __( 'Setup', 'lnkup-marketplace' ),
			'live_event'             => __( 'Live Event', 'lnkup-marketplace' ),
			'breakdown'              => __( 'Breakdown', 'lnkup-marketplace' ),
			'load_out'               => __( 'Load-Out', 'lnkup-marketplace' ),
			'reviews'                => __( 'Reviews', 'lnkup-marketplace' ),
			'archived'               => __( 'Archived', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Get current stage for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_stage( $event_id ) {
		$stage = get_post_meta( absint( $event_id ), '_lnkup_ops_stage', true );
		if ( $stage && isset( self::stages()[ $stage ] ) ) {
			return $stage;
		}
		return 'planning';
	}

	/**
	 * Set lifecycle stage.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $stage    Stage slug.
	 */
	public static function set_stage( $event_id, $stage ) {
		$stage = sanitize_key( $stage );
		if ( ! isset( self::stages()[ $stage ] ) ) {
			return false;
		}
		update_post_meta( absint( $event_id ), '_lnkup_ops_stage', $stage );
		update_post_meta( absint( $event_id ), '_lnkup_ops_stage_updated_at', current_time( 'mysql' ) );
		LNKup_Operations_Log::log( $event_id, 'status_changed', array( 'payload' => array( 'stage' => $stage ) ) );
		do_action( 'lnkup_event_lifecycle_stage_changed', $event_id, $stage );
		return true;
	}

	/**
	 * Progress percentage through lifecycle.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function progress( $event_id ) {
		$keys  = array_keys( self::stages() );
		$stage = self::get_stage( $event_id );
		$idx   = array_search( $stage, $keys, true );
		if ( false === $idx ) {
			return 0;
		}
		return (int) round( ( ( $idx + 1 ) / count( $keys ) ) * 100 );
	}

	/**
	 * Lifecycle DTO for API.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get( $event_id ) {
		$stage = self::get_stage( $event_id );
		$stages = self::stages();
		$timeline = array();
		$current_found = false;
		foreach ( $stages as $slug => $label ) {
			$timeline[] = array(
				'slug'      => $slug,
				'label'     => $label,
				'completed' => ! $current_found && $slug !== $stage,
				'current'   => $slug === $stage,
			);
			if ( $slug === $stage ) {
				$current_found = true;
			}
		}
		return array(
			'stage'       => $stage,
			'stage_label' => $stages[ $stage ],
			'progress'    => self::progress( $event_id ),
			'updated_at'  => get_post_meta( absint( $event_id ), '_lnkup_ops_stage_updated_at', true ),
			'timeline'    => $timeline,
			'tasks'       => self::required_tasks( $event_id, $stage ),
		);
	}

	/**
	 * Required tasks for current stage.
	 *
	 * @param int    $event_id Event ID.
	 * @param string $stage    Stage slug.
	 */
	public static function required_tasks( $event_id, $stage ) {
		$tasks = array();
		switch ( $stage ) {
			case 'approvals':
				$tasks[] = __( 'Review pending applications', 'lnkup-marketplace' );
				break;
			case 'payments':
				$tasks[] = __( 'Confirm vendor payments', 'lnkup-marketplace' );
				break;
			case 'vendor_packets_released':
				$tasks[] = __( 'Verify packets unlocked for paid vendors', 'lnkup-marketplace' );
				break;
			case 'load_in':
				$tasks[] = __( 'Assign load-in windows', 'lnkup-marketplace' );
				$tasks[] = __( 'Assign booth and parking', 'lnkup-marketplace' );
				break;
			case 'check_in':
				$tasks[] = __( 'Monitor vendor check-ins', 'lnkup-marketplace' );
				break;
			case 'live_event':
				$tasks[] = __( 'Monitor support queue and incidents', 'lnkup-marketplace' );
				break;
			case 'reviews':
				$tasks[] = __( 'Prompt organizers to submit vendor reviews', 'lnkup-marketplace' );
				break;
		}
		return $tasks;
	}

	/**
	 * Init hooks for auto-advance hints.
	 */
	public static function init() {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_application_status' ), 15, 3 );
	}

	/**
	 * Suggest stage transitions on payment.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $prev   Previous status.
	 * @param string $status New status.
	 */
	public static function on_application_status( $app_id, $prev, $status ) {
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		if ( ! $event_id ) {
			return;
		}
		if ( 'paid' === $status && in_array( self::get_stage( $event_id ), array( 'planning', 'applications_open', 'approvals', 'payments' ), true ) ) {
			self::set_stage( $event_id, 'vendor_packets_released' );
		}
	}
}
