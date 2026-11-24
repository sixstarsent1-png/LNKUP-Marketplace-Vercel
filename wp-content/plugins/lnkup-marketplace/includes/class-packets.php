<?php
/**
 * Vendor packet lock/unlock logic.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Packets {

	/**
	 * Unlock packet for paid application.
	 *
	 * @param int $app_id Application ID.
	 */
	/**
	 * Payment states that void an event pass even when the application still reads `paid`.
	 */
	const PASS_BLOCKING_PAYMENT_STATUSES = array( 'refunded', 'chargeback_lost', 'disputed' );

	/**
	 * Re-lock the packet after a full refund, lost chargeback or cancellation (B-8).
	 *
	 * @param int $app_id Application ID.
	 * @return bool Whether a packet was locked.
	 */
	public static function lock_for_application( $app_id ) {
		$packet = self::get_packet_for_application( $app_id );
		if ( ! $packet ) {
			return false;
		}
		update_post_meta( $packet->ID, '_lnkup_packet_locked', true );
		do_action( 'lnkup_packet_locked', (int) $app_id, (int) $packet->ID );
		return true;
	}

	public static function unlock_for_application( $app_id ) {
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
			)
		);

		if ( $packets ) {
			update_post_meta( $packets[0]->ID, '_lnkup_packet_locked', false );
			LNKup_Notifications::send_packet_unlocked( $app_id );
			$payment = LNKup_Payments::get_for_application( $app_id );
			do_action( 'lnkup_packet_unlocked', $app_id, $payment ? (int) $payment->ID : 0 );
			return $packets[0]->ID;
		}

		$event_id = (int) get_post_meta( $app_id, '_lnkup_event_id', true );
		$opp_id   = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		$vendor   = (int) get_post_meta( $app_id, '_lnkup_vendor_user_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		$settings = lnkup_get_settings();

		$packet_id = wp_insert_post(
			array(
				'post_title'  => sprintf( __( 'Packet: Application #%d', 'lnkup-marketplace' ), $app_id ),
				'post_type'   => 'lnkup_packet',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $packet_id, '_lnkup_application_id', $app_id );
		update_post_meta( $packet_id, '_lnkup_event_id', $event_id );
		update_post_meta( $packet_id, '_lnkup_opportunity_id', $opp_id );
		update_post_meta( $packet_id, '_lnkup_vendor_user_id', $vendor );
		update_post_meta( $packet_id, '_lnkup_packet_locked', false );
		update_post_meta( $packet_id, '_lnkup_qr_pass', 'LNKUP-' . strtoupper( wp_generate_password( 8, false ) ) );

		if ( $event ) {
			$venue = get_post_meta( $event_id, '_lnkup_venue_address', true );
			update_post_meta( $packet_id, '_lnkup_venue_address', $venue );
			update_post_meta( $packet_id, '_lnkup_load_in_time', get_post_meta( $event_id, '_lnkup_start_time', true ) );
			update_post_meta( $packet_id, '_lnkup_vendor_rules', __( 'Follow all venue and organizer guidelines. Check in at vendor registration on arrival.', 'lnkup-marketplace' ) );
		}

		LNKup_Notifications::send_packet_unlocked( $app_id );
		$payment = LNKup_Payments::get_for_application( $app_id );
		do_action( 'lnkup_packet_unlocked', $app_id, $payment ? (int) $payment->ID : 0 );

		return $packet_id;
	}

	/**
	 * Check if packet is accessible.
	 *
	 * @param int $packet_id Packet ID.
	 */
	public static function is_unlocked( $packet_id ) {
		$locked = get_post_meta( $packet_id, '_lnkup_packet_locked', true );
		if ( false === $locked || '0' === $locked || 0 === $locked ) {
			return true;
		}
		$app_id = (int) get_post_meta( $packet_id, '_lnkup_application_id', true );
		$payment = $app_id ? LNKup_Payments::get_for_application( $app_id ) : null;
		if ( $payment && 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Get venue address visibility for event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $app_id   Application ID.
	 */
	public static function get_venue_address( $event_id, $app_id = 0 ) {
		$hidden = (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
		if ( ! $hidden ) {
			return get_post_meta( $event_id, '_lnkup_venue_address', true );
		}
		if ( $app_id ) {
			$payment = LNKup_Payments::get_for_application( $app_id );
			if ( $payment && 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
				return get_post_meta( $event_id, '_lnkup_venue_address', true );
			}
		}
		return __( 'Hidden until payment is complete.', 'lnkup-marketplace' );
	}

	/**
	 * Render packet for vendor.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function render_for_vendor( $app_id ) {
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => $app_id,
			)
		);
		if ( ! $packets ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Packet not yet available.', 'lnkup-marketplace' ) . '</p>';
		}

		$packet = $packets[0];
		if ( ! self::is_unlocked( $packet->ID ) ) {
			return '<div class="lnkup-alert lnkup-alert--warning">' . esc_html__( 'Complete payment to unlock your vendor packet.', 'lnkup-marketplace' ) . '</div>';
		}

		ob_start();
		?>
		<div class="lnkup-panel">
			<h3 class="lnkup-panel__title"><?php esc_html_e( 'Vendor Packet', 'lnkup-marketplace' ); ?></h3>
			<p><strong><?php esc_html_e( 'QR Pass:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_qr_pass', true ) ); ?></p>
			<p><strong><?php esc_html_e( 'Load-in:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_load_in_time', true ) ?: '—' ); ?></p>
			<p><strong><?php esc_html_e( 'Booth:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_booth_assignment', true ) ?: __( 'TBD', 'lnkup-marketplace' ) ); ?></p>
			<p><strong><?php esc_html_e( 'Venue:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_venue_address', true ) ); ?></p>
			<p><strong><?php esc_html_e( 'Parking:', 'lnkup-marketplace' ); ?></strong> <?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_parking_info', true ) ?: '—' ); ?></p>
			<p><?php echo esc_html( get_post_meta( $packet->ID, '_lnkup_vendor_rules', true ) ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Packet section definitions.
	 */
	public static function sections() {
		return array(
			'welcome'     => __( 'Welcome', 'lnkup-marketplace' ),
			'overview'    => __( 'Event Overview', 'lnkup-marketplace' ),
			'venue'       => __( 'Venue & GPS', 'lnkup-marketplace' ),
			'schedule'    => __( 'Schedule', 'lnkup-marketplace' ),
			'loadin'      => __( 'Load-In Instructions', 'lnkup-marketplace' ),
			'booth'       => __( 'Booth Assignment', 'lnkup-marketplace' ),
			'parking'     => __( 'Parking Pass', 'lnkup-marketplace' ),
			'map'         => __( 'Site Map', 'lnkup-marketplace' ),
			'rules'       => __( 'Rules', 'lnkup-marketplace' ),
			'power'       => __( 'Power', 'lnkup-marketplace' ),
			'wifi'        => __( 'Wi-Fi', 'lnkup-marketplace' ),
			'emergency'   => __( 'Emergency Contacts', 'lnkup-marketplace' ),
			'weather'     => __( 'Weather Plan', 'lnkup-marketplace' ),
			'faq'         => __( 'FAQ', 'lnkup-marketplace' ),
			'support'     => __( 'Support', 'lnkup-marketplace' ),
			'qr'          => __( 'QR Pass', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Get packet post for an application.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_packet_for_application( $app_id ) {
		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_application_id',
				'meta_value'     => absint( $app_id ),
			)
		);
		return $packets ? $packets[0] : null;
	}

	/**
	 * Whether user may view an application's event pass.
	 *
	 * @param int $application_id Application ID.
	 * @param int $user_id        User ID.
	 */
	public static function can_view_pass( $application_id, $user_id = 0 ) {
		$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return true;
		}
		$vendor_id = (int) get_post_meta( $application_id, '_lnkup_vendor_user_id', true );
		if ( $vendor_id === $user_id && LNKup_Roles::is_vendor( $user_id ) ) {
			return true;
		}
		$organizer_id = (int) get_post_meta( $application_id, '_lnkup_organizer_user_id', true );
		if ( $organizer_id === $user_id && ( LNKup_Roles::is_organizer( $user_id ) || LNKup_Capabilities::user_has_manage_all( $user_id ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether application pass is unlocked (paid + packet accessible).
	 *
	 * @param int $app_id Application ID.
	 */
	public static function is_pass_unlocked_for_application( $app_id ) {
		$status  = get_post_meta( $app_id, '_lnkup_application_status', true );
		$pay_row = LNKup_Payments::get_for_application( $app_id );
		if ( $pay_row && in_array( (string) get_post_meta( $pay_row->ID, '_lnkup_payment_status', true ), self::PASS_BLOCKING_PAYMENT_STATUSES, true ) ) {
			return false;
		}
		if ( in_array( $status, array( 'paid', 'completed' ), true ) ) {
			$packet = self::get_packet_for_application( $app_id );
			if ( ! $packet ) {
				return true;
			}
			return self::is_unlocked( $packet->ID );
		}
		$payment = LNKup_Payments::get_for_application( $app_id );
		if ( $payment && 'paid' === get_post_meta( $payment->ID, '_lnkup_payment_status', true ) ) {
			$packet = self::get_packet_for_application( $app_id );
			return $packet ? self::is_unlocked( $packet->ID ) : true;
		}
		return false;
	}

	/**
	 * Booth block for the vendor event pass.
	 *
	 * @param array|null $booth     Booth row from LNKup_Booths.
	 * @param int        $packet_id Packet post ID.
	 * @param int        $event_id  Event ID.
	 * @return array<string, mixed>
	 */
	private static function format_pass_booth( $booth, $packet_id, $event_id ) {
		$placeholder = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_booth_placeholder', true ) : '';
		$assignment  = '';
		$zone        = '';
		$dimensions  = '';
		if ( is_array( $booth ) ) {
			$assignment = (string) ( $booth['booth_number'] ?? '' );
			$zone       = (string) ( $booth['booth_type_label'] ?? ( $booth['booth_type'] ?? '' ) );
			$dimensions = (string) ( $booth['booth_size'] ?? '' );
		}
		if ( ! $assignment && $packet_id ) {
			$assignment = (string) get_post_meta( $packet_id, '_lnkup_booth_assignment', true );
		}
		$assigned    = '' !== $assignment;
		$event_date  = $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '';
		$expected_by = '';
		if ( $event_date && strtotime( $event_date ) ) {
			$expected_by = sprintf(
				/* translators: %s: event date */
				__( 'before %s', 'lnkup-marketplace' ),
				date_i18n( get_option( 'date_format' ), strtotime( $event_date ) )
			);
		}
		return array(
			'assignment'  => $assigned ? $assignment : $placeholder,
			'zone'        => $zone,
			'dimensions'  => $dimensions,
			'has_power'   => is_array( $booth ) && ! empty( $booth['has_power'] ),
			'has_water'   => is_array( $booth ) && ! empty( $booth['has_water'] ),
			'status'      => $assigned ? 'assigned' : 'pending',
			'placeholder' => $placeholder,
			'expected_by' => $expected_by,
		);
	}

	/**
	 * Build vendor event pass DTO.
	 *
	 * @param int  $application_id Application ID.
	 * @param int  $user_id        Viewer user ID.
	 * @param bool $organizer_preview Organizer preview bypasses lock.
	 * @return array|WP_Error
	 */
	public static function get_vendor_event_pass( $application_id, $user_id = 0, $organizer_preview = false ) {
		$application_id = absint( $application_id );
		$user_id        = $user_id ? absint( $user_id ) : get_current_user_id();
		$app            = get_post( $application_id );

		if ( ! $app || 'lnkup_application' !== $app->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid application.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_view_pass( $application_id, $user_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		$opp_id         = (int) get_post_meta( $application_id, '_lnkup_opportunity_id', true );
		$event_id       = (int) get_post_meta( $application_id, '_lnkup_event_id', true );
		$vendor_user_id = (int) get_post_meta( $application_id, '_lnkup_vendor_user_id', true );
		$app_status     = (string) get_post_meta( $application_id, '_lnkup_application_status', true );
		$packet         = self::get_packet_for_application( $application_id );
		$packet_id      = $packet ? (int) $packet->ID : 0;
		$is_unlocked    = $organizer_preview ? true : self::is_pass_unlocked_for_application( $application_id );
		$event          = $event_id ? get_post( $event_id ) : null;
		$payment        = LNKup_Payments::get_for_application( $application_id );
		$payment_status = '';
		$amount_due     = 0.0;
		if ( $payment instanceof WP_Post ) {
			$payment_status = (string) get_post_meta( $payment->ID, '_lnkup_payment_status', true );
			$amount_due     = (float) get_post_meta( $payment->ID, '_lnkup_total_amount', true );
		}

		$event_state      = 'unknown';
		$credential_state = $is_unlocked ? 'active' : 'locked';
		if ( class_exists( 'LNKup_Vendor_Booking_Contract' ) ) {
			$resolved         = LNKup_Vendor_Booking_Contract::resolve_event( $application_id );
			$event_state      = LNKup_Vendor_Booking_Contract::event_state( (int) $resolved['event_id'], $resolved['snapshot'] );
			$credential_state = LNKup_Vendor_Booking_Contract::credential_state( (bool) $is_unlocked, $app_status, $payment_status, $event_state );
		}

		$base = array(
			'application_id' => $application_id,
			'opportunity_id' => $opp_id,
			'event_id'       => $event_id,
			'packet_id'      => $packet_id,
			'is_unlocked'    => $is_unlocked,
			'status'         => $is_unlocked ? 'unlocked' : 'locked',
			'event_state'    => $event_state,
			'credential_state' => $credential_state,
			'application'    => array(
				'status' => $app_status,
				'label'  => LNKup_Vendor_Dashboard_Data::application_status_label( $app_status ),
			),
			'payment'        => array(
				'payment_id' => $payment instanceof WP_Post ? (int) $payment->ID : 0,
				'status'     => $payment_status,
				'amount_due' => $amount_due,
				'formatted'  => $amount_due > 0 ? lnkup_currency( $amount_due ) : '',
			),
		);

		if ( ! $is_unlocked ) {
			$dashboard = lnkup_page_url_helper( 'vendor-dashboard' );
			return array_merge(
				$base,
				array(
					'locked_reason' => in_array( $app_status, array( 'payment_required', 'approved' ), true ) ? 'payment_required' : 'not_paid',
					'headline'      => lnkup_copy( 'pass_locked_title' ),
					'message'       => lnkup_copy( 'pass_locked_message' ),
					'event'         => array(
						'title' => $event ? $event->post_title : '',
						'date'  => $event_id ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
						'city'  => $event_id ? get_post_meta( $event_id, '_lnkup_city', true ) : '',
						'state' => $event_id ? get_post_meta( $event_id, '_lnkup_state', true ) : '',
					),
					'cta'           => array(
						'label'   => lnkup_copy( 'pass_locked_pay_cta' ),
						'pay_url' => add_query_arg( 'view', 'payments', $dashboard ),
					),
					'unlocks_after_payment' => array(
						__( 'Load-in details', 'lnkup-marketplace' ),
						__( 'Booth assignment', 'lnkup-marketplace' ),
						__( 'Venue map', 'lnkup-marketplace' ),
						__( 'Parking instructions', 'lnkup-marketplace' ),
						__( 'Check-in pass', 'lnkup-marketplace' ),
						__( 'Rules and contacts', 'lnkup-marketplace' ),
					),
				)
			);
		}

		$profile_id = (int) get_post_meta( $application_id, '_lnkup_vendor_profile_id', true );
		$profile    = $profile_id && class_exists( 'LNKup_Vendor_CRM_Profile' )
			? LNKup_Vendor_CRM_Profile::get( $profile_id, 0 )
			: null;
		$booth      = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_application( $application_id ) : null;
		$parking    = class_exists( 'LNKup_Parking' ) ? LNKup_Parking::get_for_application( $application_id ) : null;
		$loadin     = class_exists( 'LNKup_Loadin_Schedule' ) ? LNKup_Loadin_Schedule::get_for_application( $application_id ) : null;
		$checklist  = class_exists( 'LNKup_Vendor_Readiness' ) ? LNKup_Vendor_Readiness::checklist( $application_id ) : array();
		$organizer  = (int) get_post_meta( $application_id, '_lnkup_organizer_user_id', true );
		$org_user   = $organizer ? get_userdata( $organizer ) : null;
		$day_of_raw = $packet_id ? get_post_meta( $packet_id, '_lnkup_day_of_contact', true ) : '';
		$day_of     = $day_of_raw ? json_decode( $day_of_raw, true ) : array();
		if ( ! is_array( $day_of ) ) {
			$day_of = array();
		}
		$emergency  = class_exists( 'LNKup_Event_Operations' ) ? LNKup_Event_Operations::emergency_contacts( $event_id ) : array();
		$weather    = class_exists( 'LNKup_Weather_Service' ) ? LNKup_Weather_Service::vendor_note( $event_id ) : null;
		$map_id     = $packet_id ? (int) get_post_meta( $packet_id, '_lnkup_map_attachment_id', true ) : 0;
		if ( ! $map_id ) {
			$map_id = (int) get_post_meta( $event_id, '_lnkup_venue_map_attachment_id', true );
		}
		$qr_token   = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_qr_pass', true ) : '';

		$required_docs = function_exists( 'lnkup_get_opportunity_required_documents' )
			? lnkup_get_opportunity_required_documents( $opp_id )
			: array();
		$doc_labels    = LNKup_Documents::document_types();
		$documents     = class_exists( 'LNKup_Applications' )
			? LNKup_Applications::document_statuses_for_user( $vendor_user_id, $required_docs, $doc_labels )
			: array();

		$load_out = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_load_out_time', true ) : '';
		if ( ! $load_out && $event_id ) {
			$load_out = (string) get_post_meta( $event_id, '_lnkup_end_time', true );
		}

		$missing_items = array();
		if ( ! empty( $checklist['pending_top'] ) ) {
			foreach ( $checklist['pending_top'] as $item ) {
				if ( ! empty( $item['label'] ) ) {
					$missing_items[] = $item['label'];
				}
			}
		}

		$has_details = $booth || $parking || $loadin || ( $packet_id && get_post_meta( $packet_id, '_lnkup_venue_address', true ) );
		$dashboard   = lnkup_page_url_helper( 'vendor-dashboard' );
		$ops_url     = add_query_arg( array( 'view' => 'operations', 'app' => $application_id ), $dashboard );

		return array_merge(
			$base,
			array(
				'headline'     => lnkup_copy( 'pass_unlocked_headline' ),
				'has_details'  => (bool) $has_details,
				'empty_message'=> $has_details ? '' : lnkup_copy( 'pass_unlocked_empty' ),
				'event'        => array(
					'title'      => $event ? $event->post_title : '',
					'date'       => $event_id ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
					'start_time' => $event_id ? get_post_meta( $event_id, '_lnkup_start_time', true ) : '',
					'end_time'   => $event_id ? get_post_meta( $event_id, '_lnkup_end_time', true ) : '',
					'city'       => $event_id ? get_post_meta( $event_id, '_lnkup_city', true ) : '',
					'state'      => $event_id ? get_post_meta( $event_id, '_lnkup_state', true ) : '',
					'venue_name' => $event_id ? get_post_meta( $event_id, '_lnkup_venue_name', true ) : '',
					'address'    => self::get_venue_address( $event_id, $application_id ),
				),
				'vendor'       => array(
					'business_name' => $profile['business_name'] ?? '',
					'logo'          => $profile['logo'] ?? '',
					'category'      => $profile['category'] ?? array(),
					'setup_type'    => $profile['setup_type'] ?? '',
					'power_needs'   => $profile['power_needs'] ?? '',
					'water_needs'   => $profile['water_needs'] ?? '',
				),
				'booth'        => self::format_pass_booth( $booth, $packet_id, $event_id ),
				'load_in'      => array(
					'window'  => $loadin ? ( $loadin['label'] ?? '' ) : ( $packet_id ? get_post_meta( $packet_id, '_lnkup_load_in_time', true ) : '' ),
					'gate'    => $loadin ? ( $loadin['gate'] ?? '' ) : ( $event_id ? get_post_meta( $event_id, '_lnkup_vendor_gate', true ) : '' ),
					'notes'   => $event_id ? get_post_meta( $event_id, '_lnkup_loadin_entrance', true ) : '',
				),
				'load_out'     => array(
					'time' => $load_out,
				),
				'parking'      => $parking ? array(
					'lot'          => $parking['lot'] ?? '',
					'instructions' => $parking['instructions'] ?? ( $parking['label'] ?? '' ),
					'gate'         => $parking['gate'] ?? '',
				) : ( $packet_id && get_post_meta( $packet_id, '_lnkup_parking_info', true ) ? array(
					'instructions' => get_post_meta( $packet_id, '_lnkup_parking_info', true ),
				) : null ),
				'map'          => array(
					'attachment_url' => $map_id ? wp_get_attachment_url( $map_id ) : '',
					'map_url'        => add_query_arg( array( 'view' => 'operations', 'app' => $application_id, 'tab' => 'map' ), $dashboard ),
					'has_interactive'=> class_exists( 'LNKup_Maps' ),
				),
				'contacts'     => array(
					'organizer' => array(
						'name'  => $org_user ? $org_user->display_name : '',
						'email' => $org_user ? $org_user->user_email : '',
					),
					'day_of'    => $day_of,
					'emergency' => $emergency,
				),
				'rules'        => array(
					'vendor_rules'       => $packet_id ? get_post_meta( $packet_id, '_lnkup_vendor_rules', true ) : '',
					'setup_restrictions' => $packet_id ? get_post_meta( $packet_id, '_lnkup_setup_restrictions', true ) : '',
					'prohibited_items'   => $packet_id ? get_post_meta( $packet_id, '_lnkup_prohibited_items', true ) : '',
				),
				'documents'    => $documents,
				'weather'      => ( function () use ( $weather, $event_id, $packet_id ) {
					$rain = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_rain_plan', true ) : '';
					$heat = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_heat_plan', true ) : '';
					if ( is_array( $weather ) ) {
						$note = $weather;
						if ( ! isset( $note['rain_plan'] ) || '' === (string) $note['rain_plan'] ) {
							$note['rain_plan'] = $rain;
						}
						if ( ! isset( $note['heat_plan'] ) || '' === (string) $note['heat_plan'] ) {
							$note['heat_plan'] = $heat;
						}
						return $note;
					}
					return array(
						'summary'   => $event_id ? get_post_meta( $event_id, '_lnkup_weather_placeholder', true ) : '',
						'rain_plan' => $rain,
						'heat_plan' => $heat,
					);
				} )(),
				'refund_policy'=> $packet_id ? get_post_meta( $packet_id, '_lnkup_refund_policy', true ) : '',
				'qr_pass'      => array(
					'enabled' => (bool) $qr_token,
					'code'    => $qr_token,
					'label'   => __( 'Vendor Check-In Pass', 'lnkup-marketplace' ),
				),
				'readiness'    => array(
					'percent'       => (int) ( $checklist['percent'] ?? 0 ),
					'tier'          => (string) ( $checklist['tier'] ?? '' ),
					'label'         => (string) ( $checklist['label'] ?? '' ),
					'missing_items' => $missing_items,
				),
				'next_actions' => array_filter(
					array(
						$checklist['next_action'] ?? null,
					)
				),
				'support_url'  => add_query_arg( array( 'view' => 'operations', 'app' => $application_id, 'tab' => 'support' ), $dashboard ),
				'ops_url'      => $ops_url,
			)
		);
	}

	/**
	 * Organizer pass completeness for an event.
	 *
	 * @param int $event_id Event ID.
	 */
	public static function get_pass_completeness( $event_id ) {
		$event_id = absint( $event_id );
		$items    = array(
			'arrival'  => array(
				'key'     => 'arrival',
				'label'   => __( 'Arrival details', 'lnkup-marketplace' ),
				'complete'=> false,
			),
			'booths'   => array(
				'key'     => 'booths',
				'label'   => __( 'Booth details', 'lnkup-marketplace' ),
				'complete'=> false,
			),
			'rules'    => array(
				'key'     => 'rules',
				'label'   => __( 'Rules added', 'lnkup-marketplace' ),
				'complete'=> false,
			),
			'contacts' => array(
				'key'     => 'contacts',
				'label'   => __( 'Contact added', 'lnkup-marketplace' ),
				'complete'=> false,
			),
			'map'      => array(
				'key'     => 'map',
				'label'   => __( 'Map added', 'lnkup-marketplace' ),
				'complete'=> false,
			),
			'weather'  => array(
				'key'     => 'weather',
				'label'   => __( 'Weather plan', 'lnkup-marketplace' ),
				'complete'=> false,
			),
		);

		$venue  = (string) get_post_meta( $event_id, '_lnkup_venue_address', true );
		$gate   = (string) get_post_meta( $event_id, '_lnkup_vendor_gate', true );
		$park   = (string) get_post_meta( $event_id, '_lnkup_parking_notes', true );
		$loadin = class_exists( 'LNKup_Loadin_Schedule' ) ? LNKup_Loadin_Schedule::get_windows( $event_id ) : array();
		$items['arrival']['complete'] = ( $venue !== '' || $gate !== '' || $park !== '' || ! empty( $loadin ) );

		$booths = class_exists( 'LNKup_Booths' ) ? LNKup_Booths::get_for_event( $event_id ) : array();
		$items['booths']['complete'] = ! empty( $booths );

		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$packet  = $packets ? $packets[0] : null;
		if ( $packet ) {
			$rules = (string) get_post_meta( $packet->ID, '_lnkup_vendor_rules', true );
			$items['rules']['complete'] = $rules !== '';
			$day_of = (string) get_post_meta( $packet->ID, '_lnkup_day_of_contact', true );
			$items['contacts']['complete'] = $day_of !== '' || ! empty( LNKup_Event_Operations::emergency_contacts( $event_id ) );
			$map_att = (int) get_post_meta( $packet->ID, '_lnkup_map_attachment_id', true );
			$items['map']['complete'] = $map_att > 0 || (int) get_post_meta( $event_id, '_lnkup_venue_map_attachment_id', true ) > 0;
			$rain = (string) get_post_meta( $packet->ID, '_lnkup_rain_plan', true );
			$heat = (string) get_post_meta( $packet->ID, '_lnkup_heat_plan', true );
			$items['weather']['complete'] = $rain !== '' || $heat !== '' || (string) get_post_meta( $event_id, '_lnkup_weather_placeholder', true ) !== '';
		} else {
			$items['map']['complete'] = (int) get_post_meta( $event_id, '_lnkup_venue_map_attachment_id', true ) > 0;
			$items['contacts']['complete'] = ! empty( LNKup_Event_Operations::emergency_contacts( $event_id ) );
			$items['weather']['complete'] = (string) get_post_meta( $event_id, '_lnkup_weather_placeholder', true ) !== '';
		}

		$complete = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['complete'] ) ) {
				$complete++;
			}
		}

		return array(
			'event_id' => $event_id,
			'items'    => array_values( $items ),
			'complete' => $complete,
			'total'    => count( $items ),
			'percent'  => count( $items ) ? (int) round( ( $complete / count( $items ) ) * 100 ) : 0,
		);
	}

	/**
	 * Organizer-facing packet stage, derived from existing data (no new state):
	 * draft = arrival or booth details missing; ready = complete, no paid vendor unlocked yet;
	 * published = at least one paid vendor can open their pass. Unlock stays tied to payment.
	 *
	 * @param int $event_id Event ID.
	 * @return array{stage:string,label:string,hint:string,unlocked:int}
	 */
	public static function lifecycle( $event_id ) {
		$completeness = self::get_pass_completeness( $event_id );
		$required     = array();
		foreach ( $completeness['items'] as $item ) {
			$required[ $item['key'] ] = ! empty( $item['complete'] );
		}
		$unlocked = 0;
		foreach ( get_posts( array( 'post_type' => 'lnkup_packet', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_event_id', 'meta_value' => absint( $event_id ) ) ) as $packet_id ) { // phpcs:ignore WordPress.DB.SlowDBQuery
			if ( self::is_unlocked( $packet_id ) ) {
				++$unlocked;
			}
		}
		if ( $unlocked > 0 ) {
			return array(
				'stage'    => 'published',
				'label'    => __( 'Published', 'lnkup-marketplace' ),
				/* translators: %d: number of vendors */
				'hint'     => sprintf( _n( '%d paid vendor can open their pass.', '%d paid vendors can open their pass.', $unlocked, 'lnkup-marketplace' ), $unlocked ),
				'unlocked' => $unlocked,
			);
		}
		if ( empty( $required['arrival'] ) || empty( $required['booths'] ) ) {
			return array(
				'stage'    => 'draft',
				'label'    => __( 'Draft', 'lnkup-marketplace' ),
				'hint'     => __( 'Add arrival and booth details before vendors pay.', 'lnkup-marketplace' ),
				'unlocked' => 0,
			);
		}
		return array(
			'stage'    => 'ready',
			'label'    => __( 'Ready', 'lnkup-marketplace' ),
			'hint'     => __( 'Vendors see this pass as soon as they pay.', 'lnkup-marketplace' ),
			'unlocked' => 0,
		);
	}

	/**
	 * Save organizer pass details.
	 *
	 * @param int   $packet_id     Packet ID.
	 * @param array $input         Input fields.
	 * @param int   $organizer_id  Organizer user ID.
	 */
	public static function save_pass_details( $packet_id, $input, $organizer_id = 0 ) {
		$packet_id = absint( $packet_id );
		$packet    = get_post( $packet_id );
		if ( ! $packet || 'lnkup_packet' !== $packet->post_type ) {
			return new WP_Error( 'invalid', __( 'Invalid packet.', 'lnkup-marketplace' ) );
		}
		$event_id = (int) get_post_meta( $packet_id, '_lnkup_event_id', true );
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_packets', $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}

		if ( isset( $input['venue_address'] ) ) {
			$addr = sanitize_textarea_field( (string) $input['venue_address'] );
			update_post_meta( $packet_id, '_lnkup_venue_address', $addr );
			update_post_meta( $event_id, '_lnkup_venue_address', $addr );
		}
		if ( isset( $input['load_in_time'] ) ) {
			update_post_meta( $packet_id, '_lnkup_load_in_time', sanitize_text_field( (string) $input['load_in_time'] ) );
		}
		if ( isset( $input['load_out_time'] ) ) {
			update_post_meta( $packet_id, '_lnkup_load_out_time', sanitize_text_field( (string) $input['load_out_time'] ) );
			update_post_meta( $event_id, '_lnkup_end_time', sanitize_text_field( (string) $input['load_out_time'] ) );
		}
		if ( isset( $input['vendor_gate'] ) ) {
			update_post_meta( $event_id, '_lnkup_vendor_gate', sanitize_text_field( (string) $input['vendor_gate'] ) );
		}
		if ( isset( $input['loadin_entrance'] ) ) {
			update_post_meta( $event_id, '_lnkup_loadin_entrance', sanitize_text_field( (string) $input['loadin_entrance'] ) );
		}
		if ( isset( $input['parking_info'] ) ) {
			$park = sanitize_textarea_field( (string) $input['parking_info'] );
			update_post_meta( $packet_id, '_lnkup_parking_info', $park );
			update_post_meta( $event_id, '_lnkup_parking_notes', $park );
		}
		if ( isset( $input['vendor_rules'] ) ) {
			update_post_meta( $packet_id, '_lnkup_vendor_rules', sanitize_textarea_field( (string) $input['vendor_rules'] ) );
		}
		if ( isset( $input['setup_restrictions'] ) ) {
			update_post_meta( $packet_id, '_lnkup_setup_restrictions', sanitize_textarea_field( (string) $input['setup_restrictions'] ) );
		}
		if ( isset( $input['prohibited_items'] ) ) {
			update_post_meta( $packet_id, '_lnkup_prohibited_items', sanitize_textarea_field( (string) $input['prohibited_items'] ) );
		}
		if ( isset( $input['refund_policy'] ) ) {
			update_post_meta( $packet_id, '_lnkup_refund_policy', sanitize_textarea_field( (string) $input['refund_policy'] ) );
		}
		if ( isset( $input['rain_plan'] ) ) {
			update_post_meta( $packet_id, '_lnkup_rain_plan', sanitize_textarea_field( (string) $input['rain_plan'] ) );
		}
		if ( isset( $input['heat_plan'] ) ) {
			update_post_meta( $packet_id, '_lnkup_heat_plan', sanitize_textarea_field( (string) $input['heat_plan'] ) );
		}
		if ( isset( $input['weather_placeholder'] ) ) {
			update_post_meta( $event_id, '_lnkup_weather_placeholder', sanitize_textarea_field( (string) $input['weather_placeholder'] ) );
		}
		if ( isset( $input['map_attachment_id'] ) ) {
			update_post_meta( $packet_id, '_lnkup_map_attachment_id', absint( $input['map_attachment_id'] ) );
		}
		if ( isset( $input['day_of_contact'] ) && is_array( $input['day_of_contact'] ) ) {
			$contact = array(
				'name'  => sanitize_text_field( (string) ( $input['day_of_contact']['name'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $input['day_of_contact']['phone'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $input['day_of_contact']['email'] ?? '' ) ),
			);
			update_post_meta( $packet_id, '_lnkup_day_of_contact', wp_json_encode( $contact ) );
		}

		$app_id = (int) get_post_meta( $packet_id, '_lnkup_application_id', true );
		do_action( 'lnkup_pass_details_updated', $packet_id, $event_id, $app_id );
		if ( $app_id && class_exists( 'LNKup_Notifications' ) ) {
			LNKup_Notifications::send_pass_details_updated( $app_id );
		}

		return $packet_id;
	}

	/**
	 * Current organizer packet fields for an event.
	 *
	 * @param int $event_id Event ID.
	 * @return array<string, string>|WP_Error
	 */
	public static function get_event_pass_details( $event_id ) {
		$event_id = absint( $event_id );
		$event    = get_post( $event_id );
		if ( ! $event || 'lnkup_event' !== $event->post_type ) {
			return new WP_Error( 'not_found', __( 'Event not found.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}

		$packets = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		$packet_id = $packets ? (int) $packets[0]->ID : 0;

		$load_in = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_load_in_time', true ) : '';
		if ( '' === $load_in ) {
			$load_in = (string) get_post_meta( $event_id, '_lnkup_start_time', true );
		}
		$load_out = $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_load_out_time', true ) : '';
		if ( '' === $load_out ) {
			$load_out = (string) get_post_meta( $event_id, '_lnkup_end_time', true );
		}

		return array(
			'event_id'        => (string) $event_id,
			'event_title'     => $event->post_title,
			'venue_address'   => (string) get_post_meta( $event_id, '_lnkup_venue_address', true ),
			'load_in_time'    => $load_in,
			'load_out_time'   => $load_out,
			'vendor_gate'     => (string) get_post_meta( $event_id, '_lnkup_vendor_gate', true ),
			'loadin_entrance' => (string) get_post_meta( $event_id, '_lnkup_loadin_entrance', true ),
			'parking_info'    => (string) ( $packet_id ? get_post_meta( $packet_id, '_lnkup_parking_info', true ) : '' ) ?: (string) get_post_meta( $event_id, '_lnkup_parking_notes', true ),
			'vendor_rules'    => $packet_id ? (string) get_post_meta( $packet_id, '_lnkup_vendor_rules', true ) : '',
		);
	}

	/**
	 * Save pass details for an event (uses first packet when available).
	 *
	 * @param int   $event_id     Event ID.
	 * @param array $input        Input fields.
	 * @param int   $organizer_id Organizer user ID.
	 */
	public static function save_event_pass_details( $event_id, $input, $organizer_id = 0 ) {
		$event_id = absint( $event_id );
		$packets  = get_posts(
			array(
				'post_type'      => 'lnkup_packet',
				'posts_per_page' => 1,
				'meta_key'       => '_lnkup_event_id',
				'meta_value'     => $event_id,
			)
		);
		if ( $packets ) {
			return self::save_pass_details( (int) $packets[0]->ID, $input, $organizer_id );
		}
		$organizer_id = $organizer_id ?: get_current_user_id();
		if ( ! LNKup_Capabilities::staff_can( $event_id, 'edit_packets', $organizer_id ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		if ( isset( $input['venue_address'] ) ) {
			update_post_meta( $event_id, '_lnkup_venue_address', sanitize_textarea_field( (string) $input['venue_address'] ) );
		}
		if ( isset( $input['vendor_gate'] ) ) {
			update_post_meta( $event_id, '_lnkup_vendor_gate', sanitize_text_field( (string) $input['vendor_gate'] ) );
		}
		if ( isset( $input['loadin_entrance'] ) ) {
			update_post_meta( $event_id, '_lnkup_loadin_entrance', sanitize_text_field( (string) $input['loadin_entrance'] ) );
		}
		if ( isset( $input['parking_info'] ) ) {
			update_post_meta( $event_id, '_lnkup_parking_notes', sanitize_textarea_field( (string) $input['parking_info'] ) );
		}
		if ( isset( $input['weather_placeholder'] ) ) {
			update_post_meta( $event_id, '_lnkup_weather_placeholder', sanitize_textarea_field( (string) $input['weather_placeholder'] ) );
		}
		if ( isset( $input['load_out_time'] ) ) {
			update_post_meta( $event_id, '_lnkup_end_time', sanitize_text_field( (string) $input['load_out_time'] ) );
		}
		do_action( 'lnkup_pass_details_updated', 0, $event_id, 0 );
		return $event_id;
	}

	/**
	 * Build legacy packet DTO sections from event pass.
	 *
	 * @param array $pass Event pass DTO.
	 */
	private static function legacy_sections_from_pass( $pass ) {
		if ( empty( $pass['is_unlocked'] ) ) {
			return array( 'locked' => true );
		}
		$sections = self::sections();
		$event    = $pass['event'] ?? array();
		$booth    = $pass['booth'] ?? array();
		$loadin   = $pass['load_in'] ?? array();
		$parking  = $pass['parking'] ?? array();
		$map      = $pass['map'] ?? array();
		$rules    = $pass['rules'] ?? array();
		$weather  = $pass['weather'] ?? array();
		$qr       = $pass['qr_pass'] ?? array();
		return array(
			'packet_id'  => $pass['packet_id'] ?? 0,
			'locked'     => false,
			'qr_pass'    => $qr['code'] ?? '',
			'sections'   => array(
				'welcome'   => array( 'title' => $sections['welcome'], 'body' => sprintf( __( 'Welcome to %s!', 'lnkup-marketplace' ), $event['title'] ?? '' ) ),
				'overview'  => array( 'title' => $sections['overview'], 'body' => '' ),
				'venue'     => array( 'title' => $sections['venue'], 'body' => $event['address'] ?? '' ),
				'schedule'  => array( 'title' => $sections['schedule'], 'body' => trim( ( $event['date'] ?? '' ) . ' ' . ( $event['start_time'] ?? '' ) ) ),
				'loadin'    => array( 'title' => $sections['loadin'], 'body' => trim( ( $loadin['window'] ?? '' ) . ( ! empty( $loadin['gate'] ) ? ' — Gate ' . $loadin['gate'] : '' ) ) ),
				'booth'     => array( 'title' => $sections['booth'], 'body' => $booth['assignment'] ?? '' ),
				'parking'   => array( 'title' => $sections['parking'], 'body' => $parking['instructions'] ?? '' ),
				'map'       => array( 'title' => $sections['map'], 'body' => $map['attachment_url'] ?? '' ),
				'rules'     => array( 'title' => $sections['rules'], 'body' => $rules['vendor_rules'] ?? '' ),
				'emergency' => array( 'title' => $sections['emergency'], 'body' => $pass['contacts']['emergency'] ?? array() ),
				'weather'   => array(
					'title' => $sections['weather'],
					'body'  => is_array( $weather ) && class_exists( 'LNKup_Weather_Render' )
						? LNKup_Weather_Render::vendor_packet_note( $weather )
						: ( is_string( $weather ) ? $weather : ( $weather['summary'] ?? '' ) ),
				),
				'qr'        => array( 'title' => $sections['qr'], 'body' => $qr['code'] ?? '' ),
			),
			'engagement' => self::get_engagement( (int) ( $pass['packet_id'] ?? 0 ) ),
		);
	}


	/**
	 * Build interactive packet DTO (legacy operations.js shape).
	 *
	 * @param int $app_id Application ID.
	 */
	public static function get_dto( $app_id ) {
		$pass = self::get_vendor_event_pass( absint( $app_id ), get_current_user_id() );
		if ( is_wp_error( $pass ) ) {
			return array( 'locked' => true );
		}
		if ( empty( $pass['packet_id'] ) && empty( $pass['is_unlocked'] ) ) {
			return array( 'locked' => true );
		}
		return self::legacy_sections_from_pass( $pass );
	}

	/**
	 * Record packet section engagement.
	 *
	 * @param int    $packet_id   Packet ID.
	 * @param string $section_key Section key.
	 * @param string $action      viewed|downloaded|acknowledged|completed.
	 */
	public static function record_engagement( $packet_id, $section_key, $action = 'viewed' ) {
		global $wpdb;
		$wpdb->insert(
			LNKup_Operations_Tables::packet_engagement_table(),
			array(
				'packet_id'    => absint( $packet_id ),
				'section_key'  => sanitize_key( $section_key ),
				'action'       => sanitize_key( $action ),
				'created_at'   => current_time( 'mysql' ),
			)
		);
		$app_id = (int) get_post_meta( $packet_id, '_lnkup_application_id', true );
		if ( $app_id ) {
			LNKup_Vendor_Readiness::refresh( $app_id );
		}
		return true;
	}

	/**
	 * Check engagement exists.
	 *
	 * @param int    $packet_id   Packet ID.
	 * @param string $section_key Section key.
	 * @param string $action      Action slug.
	 */
	public static function has_engagement( $packet_id, $section_key, $action ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::packet_engagement_table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE packet_id = %d AND section_key = %s AND action = %s LIMIT 1",
				absint( $packet_id ),
				sanitize_key( $section_key ),
				sanitize_key( $action )
			)
		);
	}

	/**
	 * Get engagement summary for packet.
	 *
	 * @param int $packet_id Packet ID.
	 */
	public static function get_engagement( $packet_id ) {
		global $wpdb;
		$table = LNKup_Operations_Tables::packet_engagement_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT section_key, action FROM {$table} WHERE packet_id = %d", absint( $packet_id ) ),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['section_key'] ][] = $row['action'];
		}
		return $out;
	}

	/**
	 * Render tabbed packet for vendor dashboard.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function render_interactive( $app_id ) {
		$dto = self::get_dto( $app_id );
		if ( ! $dto ) {
			return '<p class="lnkup-empty">' . esc_html__( 'Packet not yet available.', 'lnkup-marketplace' ) . '</p>';
		}
		if ( ! empty( $dto['locked'] ) ) {
			return '<div class="lnkup-alert lnkup-alert--warning">' . esc_html__( 'Complete payment to unlock your vendor packet.', 'lnkup-marketplace' ) . '</div>';
		}
		$active_section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $dto['sections'][ $active_section ] ) ) {
			$active_section = 'welcome';
		}
		ob_start();
		?>
		<div class="lnkup-ops-packet lnkup-ops-packet--sidebar" id="lnkup-ops-packet" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>" data-packet-id="<?php echo esc_attr( (string) $dto['packet_id'] ); ?>">
			<div class="lnkup-ops-packet__layout">
				<nav class="lnkup-ops-packet__sidebar" aria-label="<?php esc_attr_e( 'Packet sections', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $dto['sections'] as $key => $section ) : ?>
						<?php
						$viewed = self::has_engagement( $dto['packet_id'], $key, 'viewed' );
						$acked  = self::has_engagement( $dto['packet_id'], $key, 'acknowledged' );
						$done   = $acked || ( 'rules' !== $key && $viewed );
						?>
						<button type="button" class="lnkup-ops-packet-tab lnkup-ops-packet-tab--sidebar<?php echo $active_section === $key ? ' is-active' : ''; ?><?php echo $done ? ' is-complete' : ''; ?>" data-section="<?php echo esc_attr( $key ); ?>">
							<span class="lnkup-ops-packet-tab__dot" aria-hidden="true"></span>
							<span class="lnkup-ops-packet-tab__label"><?php echo esc_html( $section['title'] ); ?></span>
						</button>
					<?php endforeach; ?>
				</nav>
				<select class="lnkup-ops-packet__mobile-select lnkup-input" aria-label="<?php esc_attr_e( 'Packet section', 'lnkup-marketplace' ); ?>">
					<?php foreach ( $dto['sections'] as $key => $section ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $active_section, $key ); ?>><?php echo esc_html( $section['title'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<div class="lnkup-ops-packet__panels">
				<?php foreach ( $dto['sections'] as $key => $section ) : ?>
					<div class="lnkup-ops-packet__panel<?php echo $active_section === $key ? ' is-active' : ''; ?>" data-section-panel="<?php echo esc_attr( $key ); ?>">
						<h3><?php echo esc_html( $section['title'] ); ?></h3>
						<?php if ( 'qr' === $key ) : ?>
							<div class="lnkup-ops-qr" data-qr="<?php echo esc_attr( $section['body'] ); ?>"></div>
							<p><code><?php echo esc_html( $section['body'] ); ?></code></p>
						<?php elseif ( 'map' === $key ) : ?>
							<div class="lnkup-map-packet-wrap" data-app-id="<?php echo esc_attr( (string) $app_id ); ?>"></div>
							<?php if ( $section['body'] ) : ?>
								<img src="<?php echo esc_url( $section['body'] ); ?>" alt="" class="lnkup-ops-map-img lnkup-muted" style="margin-top:0.5rem;opacity:0.6" />
							<?php endif; ?>
						<?php elseif ( 'emergency' === $key && is_array( $section['body'] ) ) : ?>
							<ul><?php foreach ( $section['body'] as $c ) : ?><li><?php echo esc_html( ( $c['name'] ?? '' ) . ' — ' . ( $c['phone'] ?? '' ) ); ?></li><?php endforeach; ?></ul>
						<?php else : ?>
							<p><?php echo esc_html( is_string( $section['body'] ) ? $section['body'] : '' ); ?></p>
						<?php endif; ?>
						<?php if ( 'rules' === $key ) : ?>
							<button type="button" class="lnkup-btn lnkup-btn--primary lnkup-ops-packet-ack" data-section="rules"><?php esc_html_e( 'I accept the rules', 'lnkup-marketplace' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
