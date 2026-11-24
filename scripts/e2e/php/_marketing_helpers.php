<?php
/**
 * Fixtures for Marketing, SEO & Distribution OS tests. Everything created here is removed by
 * lnkup_t_finish(); no emails or pushes are sent (comms delivery is paused for the run).
 *
 * @package LNKup_Marketplace
 */

require_once __DIR__ . '/_helpers.php';

/**
 * Publish an event (+ one open opportunity unless $slot is false); removed at the end.
 *
 * @param array<string,mixed> $meta Event meta without the _lnkup_ prefix.
 * @return array{event:int,opportunity:int}
 */
function lnkup_m_event( array $meta = array(), bool $slot = true, string $title = '' ): array {
	$organizer = $meta['organizer_user_id'] ?? lnkup_t_user( 'lnkup_organizer' );
	unset( $meta['organizer_user_id'] );
	$event_id = wp_insert_post(
		array(
			'post_type'    => 'lnkup_event',
			'post_status'  => 'publish',
			'post_title'   => '' !== $title ? $title : 'MOS Test Event ' . wp_generate_password( 5, false ),
			'post_content' => 'A test festival with food, retail and art vendors. Load-in starts at 9am; power available for food trucks.',
		)
	);
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $event_id );
	$defaults = array(
		'organizer_user_id' => $organizer,
		'event_date'        => gmdate( 'Y-m-d', strtotime( '+20 days' ) ),
		'start_time'        => '10:00',
		'end_time'          => '18:00',
		'city'              => 'Charlotte',
		'state'             => 'NC',
		'venue_name'        => 'Test Park',
		'venue_address'     => '100 Test St',
	);
	foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
		update_post_meta( (int) $event_id, '_lnkup_' . $key, $value );
	}
	$opp_id = 0;
	if ( $slot ) {
		$opp_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_opportunity',
				'post_status' => 'publish',
				'post_title'  => 'Food vendor slot',
			)
		);
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $opp_id );
		update_post_meta( (int) $opp_id, '_lnkup_event_id', (int) $event_id );
		update_post_meta( (int) $opp_id, '_lnkup_opportunity_status', 'open' );
		update_post_meta( (int) $opp_id, '_lnkup_vendor_fee', 150 );
		update_post_meta( (int) $opp_id, '_lnkup_spots_total', 10 );
		update_post_meta( (int) $opp_id, '_lnkup_spots_remaining', 10 );
		update_post_meta( (int) $opp_id, '_lnkup_application_deadline', gmdate( 'Y-m-d', strtotime( '+10 days' ) ) );
		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
	}
	if ( class_exists( 'LNKup_Index_Policy' ) ) {
		LNKup_Index_Policy::flush();
	}
	return array( 'event' => (int) $event_id, 'opportunity' => (int) $opp_id );
}

/**
 * Turn flags on for the run and restore afterwards.
 *
 * @param string[] $flags Flags.
 */
function lnkup_m_flags( array $flags ): void {
	$before = get_option( 'lnkup_settings', array() );
	$GLOBALS['lnkup_m_settings_before'] = $before;
	foreach ( $flags as $flag ) {
		LNKup_Marketing_Flags::override( $flag, true );
	}
	register_shutdown_function(
		static function () use ( $flags ) {
			foreach ( $flags as $flag ) {
				LNKup_Marketing_Flags::override( $flag, null );
			}
		}
	);
}

/**
 * Remove index rows for fixture opportunities before deleting posts.
 */
function lnkup_m_finish(): void {
	global $wpdb;
	if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
		foreach ( $GLOBALS['lnkup_test_cleanup'] as $item ) {
			if ( 'post' === $item[0] && 'lnkup_opportunity' === get_post_type( (int) $item[1] ) ) {
				$wpdb->delete( LNKup_Marketplace_Index::table_name(), array( 'opportunity_id' => (int) $item[1] ) );
			}
		}
	}
	foreach ( (array) ( $GLOBALS['lnkup_m_extra_cleanup'] ?? array() ) as $callback ) {
		call_user_func( $callback );
	}
	if ( class_exists( 'LNKup_Public_Discovery' ) ) {
		LNKup_Public_Discovery::flush();
	}
	lnkup_t_finish();
}
