<?php
/**
 * Fixtures and stubs for lnkup-communications integration tests (wp-env only).
 *
 * - Settings are snapshotted and restored.
 * - wp_mail is captured (never delivered); Expo HTTP is stubbed.
 * - Every comms row created for test users is purged on finish.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_helpers.php';

$GLOBALS['lnkup_ct_settings'] = get_option( 'lnkup_comms_settings', null );
$GLOBALS['lnkup_ct_counters'] = get_option( LNKup_Comms_Analytics::OPTION, null );
$GLOBALS['lnkup_ct_users']    = array();
$GLOBALS['lnkup_ct_mail']     = array();
$GLOBALS['lnkup_ct_expo']     = array( 'send' => array(), 'receipts' => array(), 'ticket' => 'ok', 'receipt' => 'ok' );

/**
 * Turn communications settings on for the test (merged over current values).
 *
 * @param array $settings Settings to force.
 */
function lnkup_ct_settings( array $settings ): void {
	LNKup_Comms_Settings::update( $settings );
}

/**
 * Enable every flow flag.
 */
function lnkup_ct_all_flows(): void {
	$all = array_fill_keys( array_keys( LNKup_Comms_Settings::flags() ), true );
	lnkup_ct_settings( array_merge( $all, array( 'delivery_paused' => false, 'email_channel' => true, 'push_channel' => true, 'paused_automations' => array() ) ) );
}

/**
 * Create a user and remember it for comms cleanup.
 */
function lnkup_ct_user( string $role ): int {
	$id                          = lnkup_t_user( $role );
	$GLOBALS['lnkup_ct_users'][] = $id;
	return $id;
}

/**
 * Generic post fixture with meta (without the _lnkup_ prefix in keys).
 */
function lnkup_ct_post( string $type, string $title, array $meta, string $status = 'publish' ): int {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title ) );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, '_lnkup_' . $key, $value );
	}
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $id );
	return (int) $id;
}

/**
 * Vendor profile owned by a vendor.
 */
function lnkup_ct_profile( int $vendor, string $city = 'Atlanta', string $state = 'GA' ): int {
	$id = lnkup_ct_post( 'lnkup_vendor_profile', 'Comms Test Vendor ' . $vendor, array( 'owner_user_id' => $vendor, 'city' => $city, 'state' => $state ) );
	wp_update_post( array( 'ID' => $id, 'post_content' => 'Test vendor description.' ) );
	return $id;
}

/**
 * Event + open opportunity for an organizer.
 *
 * @return array{event:int,opp:int}
 */
function lnkup_ct_event( int $organizer, int $days_out = 20, string $city = 'Atlanta' ): array {
	$event = lnkup_ct_post(
		'lnkup_event',
		'Comms Test Festival ' . wp_generate_password( 4, false ),
		array(
			'organizer_user_id' => $organizer,
			'event_date'        => wp_date( 'Y-m-d', time() + $days_out * DAY_IN_SECONDS ),
			'start_time'        => '10:00',
			'city'              => $city,
			'state'             => 'GA',
			'event_status'      => 'live',
		)
	);
	$opp = lnkup_ct_post(
		'lnkup_opportunity',
		'Comms Test Booth',
		array(
			'event_id'             => $event,
			'organizer_user_id'    => $organizer,
			'opportunity_status'   => 'open',
			'application_deadline' => wp_date( 'Y-m-d', time() + max( 1, $days_out - 5 ) * DAY_IN_SECONDS ),
			'spots_total'          => 10,
			'spots_remaining'      => 10,
			'vendor_fee'           => 150,
		)
	);
	return array( 'event' => $event, 'opp' => $opp );
}

/**
 * Application fixture in a given status.
 */
function lnkup_ct_application( int $vendor, int $organizer, array $event, string $status = 'submitted', int $profile = 0 ): int {
	return lnkup_ct_post(
		'lnkup_application',
		'Comms Test Application',
		array(
			'vendor_user_id'     => $vendor,
			'organizer_user_id'  => $organizer,
			'event_id'           => $event['event'],
			'opportunity_id'     => $event['opp'],
			'vendor_profile_id'  => $profile,
			'application_status' => $status,
			'submitted_at'       => current_time( 'mysql' ),
		)
	);
}

/**
 * Payment linked to an application.
 */
function lnkup_ct_payment( int $app, int $vendor, int $organizer, string $status = 'pending' ): int {
	return lnkup_ct_post(
		'lnkup_payment',
		'Comms Test Payment',
		array(
			'application_id'    => $app,
			'vendor_user_id'    => $vendor,
			'organizer_user_id' => $organizer,
			'payment_status'    => $status,
		)
	);
}

/**
 * Register an Expo token for a user.
 */
function lnkup_ct_device( int $user_id, string $suffix = '' ): string {
	$token = 'ExponentPushToken[comms-test-' . $user_id . $suffix . ']';
	LNKup_Push_Service::register_device( $user_id, $token, 'ios', 'test' );
	return $token;
}

/**
 * Message row by ID.
 */
function lnkup_ct_message( int $id ): array {
	return (array) LNKup_Comms_Engine::message( $id );
}

/**
 * Delivery rows for a message.
 */
function lnkup_ct_deliveries( int $message_id, string $channel = '' ): array {
	global $wpdb;
	$sql = 'SELECT * FROM ' . LNKup_Comms_Schema::table( 'deliveries' ) . ' WHERE message_id = %d' . ( '' !== $channel ? ' AND channel = %s' : '' ) . ' ORDER BY id ASC';
	$args = '' !== $channel ? array( $message_id, $channel ) : array( $message_id );
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

/**
 * Messages for a recipient (optionally one trigger).
 */
function lnkup_ct_messages_for( int $user_id, string $trigger = '' ): array {
	global $wpdb;
	$sql  = 'SELECT * FROM ' . LNKup_Comms_Schema::table( 'messages' ) . ' WHERE recipient_user_id = %d' . ( '' !== $trigger ? ' AND trigger_key = %s' : '' ) . ' ORDER BY id ASC';
	$args = '' !== $trigger ? array( $user_id, $trigger ) : array( $user_id );
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
}

// Capture mail instead of sending.
add_filter(
	'pre_wp_mail',
	static function ( $null, $atts ) {
		unset( $null );
		$GLOBALS['lnkup_ct_mail'][] = $atts;
		return true;
	},
	10,
	2
);

// Stub Expo push + receipts.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'exp.host' ) ) {
			return $pre;
		}
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		if ( false !== strpos( (string) $url, 'getReceipts' ) ) {
			$GLOBALS['lnkup_ct_expo']['receipts'][] = $body;
			$data = array();
			foreach ( (array) ( $body['ids'] ?? array() ) as $id ) {
				$data[ $id ] = 'ok' === $GLOBALS['lnkup_ct_expo']['receipt'] ? array( 'status' => 'ok' ) : array( 'status' => 'error', 'details' => array( 'error' => $GLOBALS['lnkup_ct_expo']['receipt'] ) );
			}
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => $data ) ), 'headers' => array() );
		}
		$GLOBALS['lnkup_ct_expo']['send'][] = $body;
		if ( 'unavailable' === $GLOBALS['lnkup_ct_expo']['ticket'] ) {
			return array( 'response' => array( 'code' => 503 ), 'body' => '', 'headers' => array() );
		}
		$data = array();
		foreach ( (array) $body as $i => $msg ) {
			$data[] = 'ok' === $GLOBALS['lnkup_ct_expo']['ticket'] ? array( 'status' => 'ok', 'id' => 'ticket-' . wp_generate_password( 8, false ) ) : array( 'status' => 'error', 'message' => 'test', 'details' => array( 'error' => $GLOBALS['lnkup_ct_expo']['ticket'] ) );
		}
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'data' => $data ) ), 'headers' => array() );
	},
	10,
	3
);

/**
 * Purge comms data for test users, restore settings, then run the shared cleanup.
 */
function lnkup_ct_finish(): void {
	global $wpdb;
	foreach ( $GLOBALS['lnkup_ct_users'] as $user_id ) {
		LNKup_Comms_Engine::purge_user( (int) $user_id );
		$wpdb->delete( LNKup_Push_Tables::table_name( LNKup_Push_Tables::DEVICES ), array( 'user_id' => (int) $user_id ) );
	}
	if ( null === $GLOBALS['lnkup_ct_settings'] ) {
		delete_option( 'lnkup_comms_settings' );
	} else {
		update_option( 'lnkup_comms_settings', $GLOBALS['lnkup_ct_settings'], false );
	}
	if ( null === $GLOBALS['lnkup_ct_counters'] ) {
		delete_option( LNKup_Comms_Analytics::OPTION );
	} else {
		update_option( LNKup_Comms_Analytics::OPTION, $GLOBALS['lnkup_ct_counters'], false );
	}
	lnkup_t_finish();
}
