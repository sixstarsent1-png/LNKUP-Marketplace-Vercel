<?php
/**
 * Event operations command center aggregates.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Operations {

	/**
	 * Command center snapshot for event.
	 *
	 * @param int $event_id     Event ID.
	 * @param int $organizer_id Organizer user ID.
	 */
	public static function snapshot( $event_id, $organizer_id = 0 ) {
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'view_roster', $organizer_id ) ) {
			return null;
		}
		$event = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return null;
		}
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
				'fields'         => 'ids',
			)
		);
		$kpis = self::compute_kpis( $apps );
		return array(
			'event_id'          => $event_id,
			'title'             => $event->post_title,
			'date'              => get_post_meta( $event_id, '_lnkup_event_date', true ),
			'city'              => get_post_meta( $event_id, '_lnkup_city', true ),
			'lifecycle'         => LNKup_Event_Lifecycle::get( $event_id ),
			'kpis'              => $kpis,
			'readiness_summary' => LNKup_Vendor_Readiness::event_readiness_summary( $event_id ),
			'compliance'        => self::compliance_alerts( $event_id, $apps ),
			'feed'              => array_slice( LNKup_Operations_Log::get_for_event( $event_id, 15 ), 0, 15 ),
			'emergency'         => self::emergency_contacts( $event_id ),
			'weather'           => class_exists( 'LNKup_Weather_Service' ) ? LNKup_Weather_Service::for_event( $event_id ) : array(),
		);
	}

	/**
	 * Compute KPI cards.
	 *
	 * @param array $app_ids Application IDs.
	 */
	private static function compute_kpis( $app_ids ) {
		$approved = 0;
		$paid = 0;
		$ready = 0;
		$checked_in = 0;
		$late = 0;
		$missing = 0;
		$support_open = 0;
		$setup_complete = 0;
		$readiness_percent_sum = 0;
		$readiness_percent_count = 0;
		foreach ( $app_ids as $app_id ) {
			$status = get_post_meta( $app_id, '_lnkup_application_status', true );
			if ( in_array( $status, array( 'approved', 'payment_required', 'paid', 'completed' ), true ) ) {
				$approved++;
			}
			if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
				$paid++;
				$checklist = LNKup_Vendor_Readiness::checklist( $app_id );
				$readiness_percent_sum += $checklist['percent'];
				$readiness_percent_count++;
			}
			$readiness = LNKup_Vendor_Readiness::refresh( $app_id );
			if ( LNKup_Vendor_Readiness::STATUS_READY === $readiness || LNKup_Vendor_Readiness::STATUS_COMPLETED === $readiness ) {
				$ready++;
			}
			if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
				$checked_in++;
			}
			if ( get_post_meta( $app_id, '_lnkup_late_arrival_at', true ) ) {
				$late++;
			}
			$ops = get_post_meta( $app_id, '_lnkup_ops_status', true );
			if ( 'missing' === $ops ) {
				$missing++;
			}
			if ( 'checked_out' === $ops || get_post_meta( $app_id, '_lnkup_event_completed_at', true ) ) {
				$setup_complete++;
			}
		}
		global $wpdb;
		$event_id = 0;
		if ( $app_ids ) {
			$event_id = (int) get_post_meta( $app_ids[0], '_lnkup_event_id', true );
		}
		if ( $event_id ) {
			$table = LNKup_Operations_Tables::support_table();
			$support_open = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'open'", $event_id )
			);
		}
		$setup_pct = $paid ? round( ( $setup_complete / $paid ) * 100 ) : 0;
		$readiness_pct = $readiness_percent_count ? (int) round( $readiness_percent_sum / $readiness_percent_count ) : 0;
		return array(
			'approved_vendors'       => $approved,
			'paid_vendors'           => $paid,
			'ready_vendors'          => $ready,
			'readiness_percent'      => $readiness_pct,
			'readiness_ready_label'  => $paid ? sprintf( '%d/%d ready', $ready, $paid ) : '—',
			'checked_in'             => $checked_in,
			'late_vendors'           => $late,
			'missing_vendors'        => $missing,
			'support_requests'       => $support_open,
			'avg_checkin_time'       => LNKup_Ops_Analytics::avg_checkin_time( $event_id ),
			'setup_completion_pct'   => $setup_pct,
		);
	}

	/**
	 * Compliance alerts for event vendors.
	 *
	 * @param int   $event_id Event ID.
	 * @param array $app_ids  Application IDs.
	 */
	public static function compliance_alerts( $event_id, $app_ids = null ) {
		if ( null === $app_ids ) {
			$app_ids = get_posts(
				array(
					'post_type'      => 'lnkup_application',
					'posts_per_page' => -1,
					'meta_key'       => '_lnkup_event_id',
					'meta_value'     => absint( $event_id ),
					'fields'         => 'ids',
				)
			);
		}
		$alerts = array();
		foreach ( $app_ids as $app_id ) {
			$checklist = LNKup_Vendor_Readiness::checklist( $app_id );
			$name      = self::vendor_name_for_app( $app_id );
			if ( $checklist['percent'] < 65 ) {
				$hint = ! empty( $checklist['next_action']['label'] ) ? $checklist['next_action']['label'] : '';
				$alerts[] = array(
					'type'           => 'readiness_low',
					'severity'       => $checklist['percent'] < 50 ? 'high' : 'medium',
					'message'        => sprintf(
						/* translators: 1: vendor name, 2: percent */
						__( '%1$s readiness at %2$d%% — needs review', 'lnkup-marketplace' ),
						$name,
						$checklist['percent']
					),
					'application_id' => $app_id,
					'vendor_name'    => $name,
					'next_action'    => $hint,
				);
			}
			$critical_keys = array( 'packet_viewed', 'booth_assigned', 'documents_complete' );
			foreach ( $checklist['items'] as $item ) {
				if ( 0 === strpos( $item['key'], 'doc_' ) ) {
					$critical_keys[] = $item['key'];
				}
			}
			foreach ( $checklist['items'] as $item ) {
				if ( ! $item['done'] && in_array( $item['key'], $critical_keys, true ) ) {
					$alerts[] = array(
						'type'           => $item['key'],
						'severity'       => 'medium',
						'message'        => sprintf(
							/* translators: 1: vendor name, 2: item label */
							__( '%1$s: %2$s incomplete', 'lnkup-marketplace' ),
							$name,
							$item['label']
						),
						'application_id' => $app_id,
						'vendor_name'    => $name,
						'next_action'    => $item['label'],
					);
				}
			}
		}
		return $alerts;
	}

	/**
	 * Vendor business name for application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function vendor_name_for_app( $app_id ) {
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		if ( $profile_id ) {
			return get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: get_the_title( $profile_id );
		}
		return sprintf( __( 'App #%d', 'lnkup-marketplace' ), $app_id );
	}

	/**
	 * Emergency contacts for event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function emergency_contacts( $event_id ) {
		$raw = get_post_meta( absint( $event_id ), '_lnkup_emergency_contacts', true );
		$list = json_decode( $raw ?: '[]', true );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * Vendor ops status columns for Kanban.
	 */
	public static function ops_statuses() {
		return array(
			'approved'    => __( 'Approved', 'lnkup-marketplace' ),
			'paid'        => __( 'Paid', 'lnkup-marketplace' ),
			'ready'       => __( 'Ready', 'lnkup-marketplace' ),
			'checked_in'  => __( 'Checked In', 'lnkup-marketplace' ),
			'setting_up'  => __( 'Setting Up', 'lnkup-marketplace' ),
			'live_ready'  => __( 'Ready', 'lnkup-marketplace' ),
			'needs_help'  => __( 'Needs Help', 'lnkup-marketplace' ),
			'late'        => __( 'Late', 'lnkup-marketplace' ),
			'missing'     => __( 'Missing', 'lnkup-marketplace' ),
			'no_show'     => __( 'No Show', 'lnkup-marketplace' ),
			'checked_out' => __( 'Checked Out', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Resolve ops status from application state.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function resolve_ops_status( $app_id ) {
		$stored = get_post_meta( $app_id, '_lnkup_ops_status', true );
		if ( $stored && isset( self::ops_statuses()[ $stored ] ) ) {
			return $stored;
		}
		if ( get_post_meta( $app_id, '_lnkup_no_show_at', true ) ) {
			return 'no_show';
		}
		if ( get_post_meta( $app_id, '_lnkup_late_arrival_at', true ) ) {
			return 'late';
		}
		$ops = get_post_meta( $app_id, '_lnkup_ops_status', true );
		if ( 'checked_out' === $ops ) {
			return 'checked_out';
		}
		if ( get_post_meta( $app_id, '_lnkup_checked_in_at', true ) ) {
			return 'checked_in';
		}
		$readiness = LNKup_Vendor_Readiness::calculate( $app_id );
		if ( LNKup_Vendor_Readiness::STATUS_READY === $readiness ) {
			return 'ready';
		}
		$status = get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( 'paid' === $status ) {
			return 'paid';
		}
		if ( in_array( $status, array( 'approved', 'payment_required' ), true ) ) {
			return 'approved';
		}
		return 'approved';
	}

	/**
	 * Set ops status on application.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $status Status slug.
	 */
	public static function set_ops_status( $app_id, $status ) {
		$status = sanitize_key( $status );
		if ( ! isset( self::ops_statuses()[ $status ] ) ) {
			return false;
		}
		update_post_meta( $app_id, '_lnkup_ops_status', $status );
		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		LNKup_Operations_Log::log(
			$event_id,
			'status_changed',
			array(
				'application_id' => $app_id,
				'payload'        => array(
					'ops_status'    => $status,
					'business_name' => self::vendor_name_for_app( $app_id ),
				),
			)
		);
		return true;
	}

	/**
	 * Vendors list for Kanban.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function vendors_for_kanban( $event_id ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => -1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => absint( $event_id ),
			)
		);
		$columns = array();
		foreach ( self::ops_statuses() as $slug => $label ) {
			$columns[ $slug ] = array();
		}
		foreach ( $apps as $app ) {
			$status = self::resolve_ops_status( $app->ID );
			if ( ! isset( $columns[ $status ] ) ) {
				$status = 'approved';
			}
			$profile_id = (int) get_post_meta( $app->ID, '_lnkup_vendor_profile_id', true );
			$checklist  = LNKup_Vendor_Readiness::checklist( $app->ID );
			$columns[ $status ][] = array(
				'application_id' => $app->ID,
				'business_name'  => self::vendor_name_for_app( $app->ID ),
				'profile_id'     => $profile_id,
				'readiness_pct'  => $checklist['percent'],
				'readiness_tier' => $checklist['tier'] ?? '',
				'booth'          => LNKup_Booths::get_for_application( $app->ID ),
				'ops_status'     => $status,
			);
		}
		return $columns;
	}

	/**
	 * Vendor home summary for today's event.
	 *
	 * @param int $user_id Vendor user ID.
	 */
	public static function vendor_home( $user_id ) {
		$user_id = absint( $user_id );
		$apps    = lnkup_get_applications_for_user( $user_id, 'vendor' );
		$today   = gmdate( 'Y-m-d' );
		$upcoming = null;
		foreach ( $apps as $app ) {
			$event_id = (int) get_post_meta( $app->ID, '_lnkup_event_id', true );
			$date     = get_post_meta( $event_id, '_lnkup_event_date', true );
			$status   = get_post_meta( $app->ID, '_lnkup_application_status', true );
			if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
				continue;
			}
			if ( $date && $date >= $today && ( ! $upcoming || $date < get_post_meta( (int) get_post_meta( $upcoming->ID, '_lnkup_event_id', true ), '_lnkup_event_date', true ) ) ) {
				$upcoming = $app;
			}
		}
		if ( ! $upcoming ) {
			return array( 'has_event' => false );
		}
		return self::vendor_home_for_application( $upcoming->ID, $user_id );
	}

	/**
	 * Vendor operations summary for a specific confirmed application.
	 *
	 * @param int $app_id  Application ID.
	 * @param int $user_id Vendor user ID.
	 */
	public static function vendor_home_for_application( $app_id, $user_id = 0 ) {
		$app_id = absint( $app_id );
		$app    = get_post( $app_id );
		if ( ! $app || 'lnkup_application' !== $app->post_type ) {
			return array( 'has_event' => false );
		}
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		$profile_id = (int) get_post_meta( $app_id, '_lnkup_vendor_profile_id', true );
		$is_owner   = LNKup_Capabilities::vendor_owns_profile( $profile_id, $user_id )
			|| (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true ) === $user_id;
		if ( ! $is_owner && ! user_can( $user_id, 'manage_options' ) ) {
			return array( 'has_event' => false );
		}
		$status = get_post_meta( $app_id, '_lnkup_application_status', true );
		if ( ! in_array( $status, array( 'paid', 'completed' ), true ) ) {
			return array( 'has_event' => false );
		}
		$event_id  = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$checklist = LNKup_Vendor_Readiness::checklist( $app_id );
		$booth     = LNKup_Booths::get_for_application( $app_id );
		$parking   = LNKup_Parking::get_for_application( $app_id );
		$loadin    = LNKup_Loadin_Schedule::get_for_application( $app_id );
		$broadcasts = LNKup_Broadcasts::unread_for_vendor( $user_id, $event_id );
		$qr_pass   = '';
		$packets   = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
			)
		);
		if ( $packets ) {
			$qr_pass = get_post_meta( $packets[0]->ID, '_lnkup_qr_pass', true );
		}
		$packet_status = '';
		if ( $packets ) {
			$pid = $packets[0]->ID;
			if ( get_post_meta( $pid, '_lnkup_packet_locked', true ) ) {
				$packet_status = __( 'Locked', 'lnkup-marketplace' );
			} elseif ( class_exists( 'LNKup_Packets' ) && LNKup_Packets::has_engagement( $pid, 'rules', 'acknowledged' ) ) {
				$packet_status = __( 'Complete', 'lnkup-marketplace' );
			} elseif ( class_exists( 'LNKup_Packets' ) && LNKup_Packets::has_engagement( $pid, 'welcome', 'viewed' ) ) {
				$packet_status = __( 'Rules pending', 'lnkup-marketplace' );
			} else {
				$packet_status = __( 'Not opened', 'lnkup-marketplace' );
			}
		}
		$countdown_label = '';
		if ( $loadin && ! empty( $loadin['start_at'] ) ) {
			$countdown_label = sprintf(
				/* translators: %s: load-in datetime */
				__( 'Load-in window: %s', 'lnkup-marketplace' ),
				$loadin['start_at']
			);
		} elseif ( get_post_meta( $event_id, '_lnkup_event_date', true ) ) {
			$countdown_label = sprintf(
				/* translators: %s: event date */
				__( 'Event date: %s', 'lnkup-marketplace' ),
				get_post_meta( $event_id, '_lnkup_event_date', true )
			);
		}
		return array(
			'has_event'          => true,
			'application_id'     => $app_id,
			'event_id'           => $event_id,
			'event_title'        => get_the_title( $event_id ),
			'event_date'         => get_post_meta( $event_id, '_lnkup_event_date', true ),
			'readiness'          => $checklist,
			'readiness_percent'  => $checklist['percent'] ?? 0,
			'readiness_tier'     => $checklist['tier'] ?? '',
			'packet_status'      => $packet_status,
			'booth'              => $booth,
			'parking'            => $parking,
			'loadin'             => $loadin,
			'ops_status'         => self::resolve_ops_status( $app_id ),
			'unread_messages'    => count( $broadcasts ),
			'unread_broadcasts'  => count( $broadcasts ),
			'qr_pass'            => $qr_pass,
			'countdown_label'    => $countdown_label,
			'emergency'          => self::emergency_contacts( $event_id ),
			'weather'            => class_exists( 'LNKup_Weather_Service' ) ? LNKup_Weather_Service::vendor_note( $event_id ) : null,
		);
	}

	/**
	 * Seed operations SQL from event / opportunity meta (idempotent).
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function bootstrap_from_event( $event_id ) {
		$event_id = absint( $event_id );
		if ( ! $event_id || 'lnkup_event' !== get_post_type( $event_id ) ) {
			return false;
		}
		$opp_id = 0;
		$opps   = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		if ( $opps ) {
			$opp_id = (int) $opps[0];
		}
		$spots = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ) : 0;
		$gate  = (string) get_post_meta( $event_id, '_lnkup_vendor_gate', true );
		if ( '' === $gate ) {
			$gate = (string) get_post_meta( $event_id, '_lnkup_loadin_entrance', true );
		}
		if ( '' === $gate ) {
			$gate = 'Gate A';
		}
		$has_power = ! empty( get_post_meta( $event_id, '_lnkup_power_available', true ) );

		if ( class_exists( 'LNKup_Booths' ) ) {
			$existing = LNKup_Booths::get_for_event( $event_id );
			if ( empty( $existing ) && $spots > 0 ) {
				$limit = min( $spots, 50 );
				for ( $i = 1; $i <= $limit; $i++ ) {
					LNKup_Booths::save(
						$event_id,
						array(
							'booth_number' => (string) $i,
							'booth_type'   => 'standard',
							'has_power'    => $has_power,
						)
					);
				}
			}
		}

		if ( class_exists( 'LNKup_Loadin_Schedule' ) ) {
			$windows = LNKup_Loadin_Schedule::get_windows( $event_id );
			if ( empty( $windows ) ) {
				$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
				$start_time = get_post_meta( $event_id, '_lnkup_start_time', true ) ?: '09:00';
				$start_ts   = $event_date ? strtotime( trim( $event_date . ' ' . $start_time ) ) : false;
				if ( $start_ts ) {
					LNKup_Loadin_Schedule::create_window(
						$event_id,
						array(
							'gate'      => $gate,
							'start_at'  => gmdate( 'Y-m-d H:i:s', $start_ts - ( 4 * HOUR_IN_SECONDS ) ),
							'end_at'    => gmdate( 'Y-m-d H:i:s', $start_ts - ( 2 * HOUR_IN_SECONDS ) ),
							'max_slots' => max( 1, $spots ),
						)
					);
				}
			}
		}

		if ( class_exists( 'LNKup_Parking' ) ) {
			$parking = LNKup_Parking::get_for_event( $event_id );
			if ( empty( $parking ) ) {
				$venue = get_post_meta( $event_id, '_lnkup_venue_name', true );
				$notes = get_post_meta( $event_id, '_lnkup_parking_notes', true );
				LNKup_Parking::save(
					$event_id,
					array(
						'lot'          => $venue ? $venue . ' Vendor Lot' : 'Vendor Lot',
						'row_label'    => '1',
						'space_number' => '1',
						'gate'         => $gate,
						'instructions' => $notes,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Default field values for add booth / parking / load-in forms.
	 *
	 * @param int $event_id Event post ID.
	 */
	public static function defaults_for_event( $event_id ) {
		$event_id = absint( $event_id );
		$opp_id   = 0;
		$opps     = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		if ( $opps ) {
			$opp_id = (int) $opps[0];
		}
		$spots    = $opp_id ? (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ) : 0;
		$gate     = (string) get_post_meta( $event_id, '_lnkup_vendor_gate', true );
		if ( '' === $gate ) {
			$gate = (string) get_post_meta( $event_id, '_lnkup_loadin_entrance', true );
		}
		if ( '' === $gate ) {
			$gate = 'Gate A';
		}
		$venue    = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
		$notes    = (string) get_post_meta( $event_id, '_lnkup_parking_notes', true );
		$existing = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_event( $event_id ) : array();
		$next_num = count( $existing ) + 1;
		$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
		$start_time = get_post_meta( $event_id, '_lnkup_start_time', true ) ?: '09:00';
		$start_ts   = $event_date ? strtotime( trim( $event_date . ' ' . $start_time ) ) : time();
		$win_start  = gmdate( 'Y-m-d\TH:i', $start_ts - ( 4 * HOUR_IN_SECONDS ) );
		$win_end    = gmdate( 'Y-m-d\TH:i', $start_ts - ( 2 * HOUR_IN_SECONDS ) );

		return array(
			'booth_number' => (string) $next_num,
			'booth_type'   => 'standard',
			'has_power'    => ! empty( get_post_meta( $event_id, '_lnkup_power_available', true ) ),
			'lot'          => $venue ? $venue . ' Vendor Lot' : 'Vendor Lot',
			'row_label'    => '1',
			'space_number' => '1',
			'gate'         => $gate,
			'instructions' => $notes,
			'start_at'     => $win_start,
			'end_at'       => $win_end,
			'max_slots'    => max( 1, $spots ),
		);
	}
}
