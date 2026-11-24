<?php
/**
 * Idempotent local E2E seed for wp-env. Never run against production.
 *
 * Usage: powershell -File scripts/e2e/wpe.ps1 wp eval-file /var/www/html/wp-content/lnkup-e2e/seed.php
 * Writes scripts/e2e/.env.e2e (KEY=VALUE) for Playwright.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to seed: WP_ENVIRONMENT_TYPE is not local.' );
}

const LNKUP_E2E_PASSWORD = 'lnkup-e2e-Passw0rd!';

/**
 * Find a seeded post by key, or create it.
 */
function lnkup_e2e_post( $key, $args, $meta = array() ) {
	$found = get_posts(
		array(
			'post_type'      => $args['post_type'],
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_lnkup_e2e_key',
			'meta_value'     => $key,
		)
	);
	if ( $found ) {
		$id = (int) $found[0];
	} else {
		$id = (int) wp_insert_post( array_merge( array( 'post_status' => 'publish' ), $args ) );
		update_post_meta( $id, '_lnkup_e2e_key', $key );
	}
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

/**
 * Find or create a user with a role.
 */
function lnkup_e2e_user( $login, $role, $display ) {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		$id   = wp_create_user( $login, LNKUP_E2E_PASSWORD, $login . '@example.test' );
		$user = get_user_by( 'id', $id );
	}
	wp_set_password( LNKUP_E2E_PASSWORD, $user->ID );
	$user->set_role( $role );
	wp_update_user( array( 'ID' => $user->ID, 'display_name' => $display ) );
	return (int) $user->ID;
}

/**
 * Event + opportunities owned by an organizer.
 */
function lnkup_e2e_event( $key, $organizer_id, $title, $slots, $days_ahead = 45 ) {
	$date     = gmdate( 'Y-m-d', strtotime( "+{$days_ahead} days" ) );
	$event_id = lnkup_e2e_post(
		"event:{$key}",
		array( 'post_type' => 'lnkup_event', 'post_title' => $title, 'post_content' => "E2E event {$title}." ),
		array(
			'_lnkup_organizer_user_id'  => $organizer_id,
			'_lnkup_event_date'         => $date,
			'_lnkup_city'               => 'Charlotte',
			'_lnkup_state'              => 'NC',
			'_lnkup_venue_name'         => 'E2E Park',
			'_lnkup_expected_attendance'=> 2500,
			'_lnkup_status'             => 'published',
		)
	);
	$opp_ids = array();
	foreach ( $slots as $slot_key => $slot ) {
		$opp_id = lnkup_e2e_post(
			"opp:{$key}:{$slot_key}",
			array( 'post_type' => 'lnkup_opportunity', 'post_title' => $slot['title'] ),
			array(
				'_lnkup_event_id'             => $event_id,
				'_lnkup_organizer_user_id'    => $organizer_id,
				'_lnkup_spots_total'          => $slot['spots'],
				'_lnkup_vendor_fee'           => $slot['fee'],
				'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( $date . ' -7 days' ) ),
				'_lnkup_opportunity_status'   => 'open',
			)
		);
		if ( '' === (string) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) ) {
			update_post_meta( $opp_id, '_lnkup_spots_remaining', $slot['spots'] );
		}
		wp_set_object_terms( $opp_id, array( 'Food Vendor', 'Retail' ), 'lnkup_vendor_category' );
		LNKup_Marketplace_Index::sync_opportunity( $opp_id );
		$opp_ids[ $slot_key ] = $opp_id;
	}
	return array( $event_id, $opp_ids );
}

/**
 * Vendor profile owned by a user.
 */
function lnkup_e2e_profile( $user_id, $business ) {
	$profile_id = lnkup_e2e_post(
		"profile:{$user_id}",
		array( 'post_type' => 'lnkup_vendor_profile', 'post_title' => $business, 'post_content' => "{$business} sells good things." ),
		array(
			'_lnkup_owner_user_id'       => $user_id,
			'_lnkup_business_name'       => $business,
			'_lnkup_city'                => 'Charlotte',
			'_lnkup_state'               => 'NC',
			'_lnkup_contact_email'       => 'vendor' . $user_id . '@example.test',
			'_lnkup_verification_status' => 'pending',
		)
	);
	wp_set_object_terms( $profile_id, array( 'Food Vendor' ), 'lnkup_vendor_category' );
	return $profile_id;
}

/**
 * Submit (as vendor) and optionally approve (as organizer) using real services.
 */
function lnkup_e2e_application( $vendor_id, $opp_id, $organizer_id, $approve ) {
	$existing = get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
				array( 'key' => '_lnkup_opportunity_id', 'value' => $opp_id ),
			),
		)
	);
	if ( $existing ) {
		$app_id = (int) $existing[0];
	} else {
		wp_set_current_user( $vendor_id );
		$app_id = LNKup_Applications::submit_with_profile(
			$vendor_id,
			$opp_id,
			array( 'selected_category' => 'Food Vendor', 'accepted_terms' => true, 'application_message' => 'E2E seed' )
		);
		if ( is_wp_error( $app_id ) ) {
			WP_CLI::warning( "apply failed for opp {$opp_id}: " . $app_id->get_error_message() );
			return 0;
		}
	}
	if ( $approve && 'submitted' === get_post_meta( $app_id, '_lnkup_application_status', true ) ) {
		wp_set_current_user( $organizer_id );
		$result = LNKup_Applications::handle_organizer_action( $app_id, 'approve', $organizer_id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( "approve failed for app {$app_id}: " . $result->get_error_message() );
		}
	}
	wp_set_current_user( 0 );
	return (int) $app_id;
}

/**
 * Private compliance document for a vendor (bypasses $_FILES).
 */
function lnkup_e2e_document( $vendor_id ) {
	$existing = get_posts(
		array(
			'post_type'      => 'lnkup_document',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_lnkup_vendor_user_id',
			'meta_value'     => $vendor_id,
		)
	);
	if ( $existing ) {
		return (int) $existing[0];
	}
	$dirs = LNKup_Documents::private_upload_dir( wp_upload_dir() );
	wp_mkdir_p( $dirs['path'] );
	$file = trailingslashit( $dirs['path'] ) . 'e2e-coi-' . $vendor_id . '.pdf';
	file_put_contents( $file, "%PDF-1.4\n% e2e\n" );
	$attachment_id = wp_insert_attachment(
		array( 'post_mime_type' => 'application/pdf', 'post_title' => 'E2E COI', 'post_status' => 'inherit', 'post_author' => $vendor_id ),
		$file
	);
	update_post_meta( $attachment_id, LNKup_Documents::PRIVATE_META, 1 );
	$doc_id = (int) wp_insert_post( array( 'post_type' => 'lnkup_document', 'post_status' => 'publish', 'post_title' => 'COI — E2E' ) );
	update_post_meta( $doc_id, '_lnkup_vendor_user_id', $vendor_id );
	update_post_meta( $doc_id, '_lnkup_vendor_profile_id', lnkup_get_vendor_profile_id( $vendor_id ) );
	update_post_meta( $doc_id, '_lnkup_document_type', 'certificate_of_insurance' );
	update_post_meta( $doc_id, '_lnkup_attachment_id', $attachment_id );
	update_post_meta( $doc_id, '_lnkup_verification_status', 'pending' );
	return $doc_id;
}

// ---------------------------------------------------------------------------

LNKup_Activator::activate();
switch_theme( 'lnkup-theme' );
WP_CLI::runcommand( "rewrite structure '/%postname%/' --hard", array( 'exit_error' => false ) );

$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$settings = array_merge(
	$settings,
	array(
		'platform_prelaunch_mode' => 0,
		'payment_provider'        => 'manual',
		'test_mode'               => 1,
		'stripe_secret_key'       => '',
		'stripe_public_key'       => '',
		'stripe_webhook_secret'   => 'whsec_lnkup_e2e_local_only',
		'marketplace_phase'       => '1c',
	)
);
update_option( 'lnkup_settings', $settings );

$org_a    = lnkup_e2e_user( 'e2e_org_a', 'lnkup_organizer', 'E2E Organizer A' );
$org_b    = lnkup_e2e_user( 'e2e_org_b', 'lnkup_organizer', 'E2E Organizer B' );
$vendor_a = lnkup_e2e_user( 'e2e_vendor_a', 'lnkup_vendor', 'E2E Vendor A' );
$vendor_b = lnkup_e2e_user( 'e2e_vendor_b', 'lnkup_vendor', 'E2E Vendor B' );
update_user_meta( $org_a, '_lnkup_organizer_public_name', 'E2E Events Co' );
lnkup_e2e_user( 'e2e_no_role', 'subscriber', 'E2E No Role' );

$staff = array();
foreach ( array( 'operations', 'coordinator', 'security', 'finance' ) as $role ) {
	$staff[ $role ] = lnkup_e2e_user( 'e2e_staff_' . $role, 'lnkup_organizer', 'E2E Staff ' . $role );
}

lnkup_e2e_profile( $vendor_a, 'E2E Vendor A Eats' );
lnkup_e2e_profile( $vendor_b, 'E2E Vendor B Goods' );

list( $event_main, $main_opps ) = lnkup_e2e_event(
	'main',
	$org_a,
	'E2E Summer Market',
	array(
		'food'   => array( 'title' => 'Food Vendor Space', 'fee' => 120, 'spots' => 2 ),
		'retail' => array( 'title' => 'Retail Booth', 'fee' => 80, 'spots' => 3 ),
		'fresh'  => array( 'title' => 'Premium Corner', 'fee' => 200, 'spots' => 1 ),
	)
);
list( $event_free, $free_opps ) = lnkup_e2e_event(
	'free',
	$org_a,
	'E2E Free Community Day',
	array( 'free' => array( 'title' => 'Free Community Table', 'fee' => 0, 'spots' => 5 ) )
);
list( $event_b, $b_opps ) = lnkup_e2e_event(
	'org-b',
	$org_b,
	'E2E Organizer B Fair',
	array( 'b' => array( 'title' => 'Organizer B Booth', 'fee' => 50, 'spots' => 4 ) )
);

$stale_fresh = get_posts(
	array(
		'post_type'      => 'lnkup_application',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array( array( 'key' => '_lnkup_opportunity_id', 'value' => $main_opps['fresh'] ) ),
	)
);
foreach ( $stale_fresh as $stale_id ) {
	wp_delete_post( $stale_id, true );
}
delete_transient( 'lnkup_apply_' . $vendor_a . '_' . $main_opps['fresh'] );

$app_pending = lnkup_e2e_application( $vendor_a, $main_opps['food'], $org_a, true );
$app_free    = lnkup_e2e_application( $vendor_a, $free_opps['free'], $org_a, true );
$app_b       = lnkup_e2e_application( $vendor_b, $main_opps['retail'], $org_a, true );
$app_review  = lnkup_e2e_application( $vendor_b, $main_opps['food'], $org_a, false );

$pending_payment = $app_pending ? LNKup_Payments::get_for_application( $app_pending ) : null;
$free_payment    = $app_free ? LNKup_Payments::get_for_application( $app_free ) : null;
$b_payment       = $app_b ? LNKup_Payments::get_for_application( $app_b ) : null;

$doc_b = lnkup_e2e_document( $vendor_b );
lnkup_e2e_document( $vendor_a );

foreach ( $staff as $role => $user_id ) {
	if ( isset( LNKup_Event_Staff::roles()[ $role ] ) && ! LNKup_Event_Staff::is_staff( $event_main, $user_id ) ) {
		LNKup_Event_Staff::assign( $event_main, $user_id, $role );
	}
}
// Floor staff on the free event: may check in, may not override gates.
if ( ! LNKup_Event_Staff::is_staff( $event_free, $staff['security'] ) ) {
	LNKup_Event_Staff::assign( $event_free, $staff['security'], 'security' );
}

// Event-day state for the free booking starts clean on every seed so check-in specs are repeatable.
global $wpdb;
// Organizer B starts each seed with $25 available in the test-mode treasury wallet so the cash-out
// spec can request and then cancel a payout. Manual provider only: no money moves.
if ( class_exists( 'LNKup_Organizer_Wallet' ) ) {
	$org_b_balances  = LNKup_Organizer_Wallet::get_balances( $org_b );
	$org_b_available = is_array( $org_b_balances ) ? (float) ( $org_b_balances['available'] ?? 0 ) : 0;
	if ( $org_b_available < 25 ) {
		LNKup_Organizer_Wallet::add_entry(
			$org_b,
			round( 25 - $org_b_available, 2 ),
			'available',
			array(
				'source_type' => 'e2e_seed',
				'note'        => 'E2E test-mode balance',
			)
		);
	}
}

// Saved searches left by earlier spec runs count toward the 10-search limit.
if ( class_exists( 'LNKup_Saved_Searches' ) ) {
	foreach ( (array) LNKup_Saved_Searches::get_for_user( $vendor_b ) as $saved_search ) {
		if ( 0 === strpos( (string) ( $saved_search['name'] ?? '' ), 'E2E search' ) ) {
			LNKup_Saved_Searches::delete( $vendor_b, $saved_search['id'] );
		}
	}
}

// Booth assignments on the main event start empty so the booth spec always has an open booth.
if ( class_exists( 'LNKup_Operations_Tables' ) && method_exists( 'LNKup_Operations_Tables', 'booths_table' ) ) {
	$wpdb->update( LNKup_Operations_Tables::booths_table(), array( 'application_id' => 0 ), array( 'event_id' => $event_main ), array( '%d' ), array( '%d' ) );
	$main_apps = get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_lnkup_event_id', 'value' => $event_main ) ),
		)
	);
	foreach ( $main_apps as $main_app_id ) {
		delete_post_meta( $main_app_id, '_lnkup_booth_placeholder' );
	}
}

$free_pass_code = '';
if ( $app_free ) {
	foreach ( array( '_lnkup_checked_in_at', '_lnkup_ops_status', '_lnkup_late_arrival_at', '_lnkup_no_show_at', '_lnkup_event_completed_at', '_lnkup_booth_placeholder' ) as $event_day_key ) {
		delete_post_meta( $app_free, $event_day_key );
	}
	if ( class_exists( 'LNKup_Operations_Tables' ) ) {
		$wpdb->delete( LNKup_Operations_Tables::checkins_table(), array( 'application_id' => $app_free ), array( '%d' ) );
	}
	if ( class_exists( 'LNKup_Operations_Tables' ) && method_exists( 'LNKup_Operations_Tables', 'booths_table' ) ) {
		$wpdb->update( LNKup_Operations_Tables::booths_table(), array( 'application_id' => 0 ), array( 'application_id' => $app_free ), array( '%d' ), array( '%d' ) );
	}
	$free_packets = get_posts(
		array(
			'post_type'      => 'lnkup_packet',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => '_lnkup_application_id',
			'meta_value'     => $app_free,
		)
	);
	if ( $free_packets ) {
		$free_pass_code = (string) get_post_meta( $free_packets[0]->ID, '_lnkup_qr_pass', true );
		if ( '' === $free_pass_code ) {
			$free_pass_code = 'e2e-free-' . wp_generate_password( 12, false );
			update_post_meta( $free_packets[0]->ID, '_lnkup_qr_pass', $free_pass_code );
		}
	}
}

// Booking & Application OS: wizard + multi-stop flags (local only), a fresh slot for draft specs,
// and a three-stop tour. Vendor B's applications and drafts on these slots reset every run.
$apply_settings = get_option( 'lnkup_settings', array() );
$apply_settings = is_array( $apply_settings ) ? $apply_settings : array();
$apply_settings['apply_wizard_enabled']     = true;
$apply_settings['tour_multi_apply_enabled'] = true;
$apply_settings['matching_enabled']         = true;
$apply_settings['matching_tours_enabled']   = true;
update_option( 'lnkup_settings', $apply_settings );

list( $event_draft, $draft_opps ) = lnkup_e2e_event( 'apply-os-draft', $org_a, 'E2E Draft Resume Fair', array( 'booth' => array( 'title' => 'Draft Booth', 'spots' => 20, 'fee' => 120 ) ), 50 );
$tour_id = lnkup_e2e_post( 'tour:apply-os', array( 'post_type' => 'lnkup_tour', 'post_title' => 'E2E Southern Tour' ), array( '_lnkup_organizer_user_id' => $org_a, '_lnkup_tour_type' => 'tour' ) );
$tour_opps = array();
$tour_states = array( 'houston' => 'TX', 'atlanta' => 'GA', 'nashville' => 'TN' );
foreach ( array( 'houston' => 55, 'atlanta' => 62, 'nashville' => 69 ) as $stop_key => $days ) {
	list( $stop_event, $stop_opps ) = lnkup_e2e_event( "tour-{$stop_key}", $org_a, 'E2E Tour ' . ucfirst( $stop_key ), array( 'booth' => array( 'title' => 'Tour Booth', 'spots' => 20, 'fee' => 200 ) ), $days );
	update_post_meta( $stop_event, '_lnkup_tour_id', $tour_id );
	update_post_meta( $stop_event, '_lnkup_city', ucfirst( $stop_key ) );
	update_post_meta( $stop_event, '_lnkup_state', $tour_states[ $stop_key ] );
	LNKup_Marketplace_Index::sync_opportunity( $stop_opps['booth'] );
	$tour_opps[] = (int) $stop_opps['booth'];
}
foreach ( array_merge( array( (int) $draft_opps['booth'] ), $tour_opps ) as $reset_opp ) {
	$reset = get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'post_status'    => array( 'publish', 'lnkup_app_draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_lnkup_opportunity_id', 'value' => $reset_opp ),
				array( 'key' => '_lnkup_draft_opportunity_id', 'value' => $reset_opp ),
			),
		)
	);
	foreach ( $reset as $reset_id ) {
		$reset_pay = LNKup_Payments::get_for_application( $reset_id );
		if ( $reset_pay ) {
			wp_delete_post( $reset_pay->ID, true );
		}
		wp_delete_post( $reset_id, true );
	}
	LNKup_Opportunity_Inventory::recompute( $reset_opp );
}
foreach ( get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'lnkup_app_draft', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_draft_tour_id', 'meta_value' => $tour_id ) ) as $tour_draft ) {
	wp_delete_post( $tour_draft, true );
}

$env = array(
	'PLAYWRIGHT_DRAFT_OPPORTUNITY_ID'       => $draft_opps['booth'],
	'PLAYWRIGHT_TOUR_ID'                    => $tour_id,
	'PLAYWRIGHT_BASE_URL'                   => home_url(),
	'PLAYWRIGHT_PASSWORD'                   => LNKUP_E2E_PASSWORD,
	'PLAYWRIGHT_VENDOR_USER'                => 'e2e_vendor_a',
	'PLAYWRIGHT_VENDOR_PASS'                => LNKUP_E2E_PASSWORD,
	'PLAYWRIGHT_VENDOR_B_USER'              => 'e2e_vendor_b',
	'PLAYWRIGHT_ORGANIZER_USER'             => 'e2e_org_a',
	'PLAYWRIGHT_ORGANIZER_PASS'             => LNKUP_E2E_PASSWORD,
	'PLAYWRIGHT_ORGANIZER_B_USER'           => 'e2e_org_b',
	'PLAYWRIGHT_STAFF_OPERATIONS_USER'      => 'e2e_staff_operations',
	'PLAYWRIGHT_STAFF_COORDINATOR_USER'     => 'e2e_staff_coordinator',
	'PLAYWRIGHT_STAFF_SECURITY_USER'        => 'e2e_staff_security',
	'PLAYWRIGHT_STAFF_FINANCE_USER'         => 'e2e_staff_finance',
	'PLAYWRIGHT_NO_ROLE_USER'               => 'e2e_no_role',
	'PLAYWRIGHT_ORGANIZER_EVENT_ID'         => $event_main,
	'PLAYWRIGHT_ORGANIZER_B_EVENT_ID'       => $event_b,
	'PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID'  => $pending_payment ? $pending_payment->ID : '',
	'PLAYWRIGHT_FREE_BOOTH_PAYMENT_ID'      => $free_payment ? $free_payment->ID : '',
	'PLAYWRIGHT_FOREIGN_PAYMENT_ID'         => $b_payment ? $b_payment->ID : '',
	'PLAYWRIGHT_FOREIGN_DOCUMENT_ID'        => $doc_b,
	'PLAYWRIGHT_FRESH_OPPORTUNITY_ID'       => $main_opps['fresh'],
	'PLAYWRIGHT_MULTI_SLOT_OPPORTUNITY_ID'  => $main_opps['retail'],
	'PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID'    => $main_opps['food'],
	'PLAYWRIGHT_REVIEW_APPLICATION_ID'      => $app_review,
	'PLAYWRIGHT_FREE_EVENT_ID'              => $event_free,
	'PLAYWRIGHT_FREE_APPLICATION_ID'        => $app_free,
	'PLAYWRIGHT_FREE_PASS_CODE'             => $free_pass_code,
	'PLAYWRIGHT_PAYMENT_SIMULATION'         => '1',
);

$lines = array();
foreach ( $env as $k => $v ) {
	$lines[] = $k . '=' . $v;
	WP_CLI::log( $k . '=' . $v );
}
file_put_contents( __DIR__ . '/.env.e2e', implode( "\n", $lines ) . "\n" );

delete_option( LNKup_Opportunity_Inventory::BACKFILL_OPTION );
LNKup_Opportunity_Inventory::maybe_backfill();
WP_CLI::log( 'Inventory backfill: ' . wp_json_encode( get_option( LNKup_Opportunity_Inventory::BACKFILL_OPTION ) ) );
WP_CLI::success( 'E2E seed complete.' );
