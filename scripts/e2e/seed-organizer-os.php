<?php
/**
 * Opt-in E2E fixtures for the Organizer Management OS specs (local wp-env only).
 *
 * Turns on application_review_v2_enabled, waitlist_offers_enabled and trust_org_teams, adds an
 * application reviewer to Organizer A's main event, and builds a one-spot waitlist fair.
 * Run after seed.php. "off" restores the flags so the classic Requests specs see the classic page.
 *
 * Usage:
 *   npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed-organizer-os.php on
 *   npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/seed-organizer-os.php off
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to seed: WP_ENVIRONMENT_TYPE is not local.' );
}

global $wpdb;
$mode     = isset( $args[0] ) && 'off' === $args[0] ? 'off' : 'on';
$password = 'lnkup-e2e-Passw0rd!';
$org_a    = get_user_by( 'login', 'e2e_org_a' );
$vendor_a = get_user_by( 'login', 'e2e_vendor_a' );
$vendor_b = get_user_by( 'login', 'e2e_vendor_b' );
if ( ! $org_a || ! $vendor_a || ! $vendor_b ) {
	WP_CLI::error( 'Run scripts/e2e/seed.php first.' );
}

$settings = get_option( 'lnkup_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$flags    = array( 'application_review_v2_enabled', 'waitlist_offers_enabled', 'trust_org_teams', 'organizer_ops_v2_enabled', 'organizer_document_review_enabled' );
foreach ( $flags as $flag ) {
	$settings[ $flag ] = 'on' === $mode;
}
// Document review reads files through Passport sharing; restore whatever was set before on "off".
$passport_flags = array( 'passport_enabled', 'document_vault_sharing' );
if ( 'on' === $mode ) {
	if ( false === get_option( 'lnkup_e2e_oos_passport_prev', false ) ) {
		update_option( 'lnkup_e2e_oos_passport_prev', array_intersect_key( $settings, array_flip( $passport_flags ) ), false );
	}
	foreach ( $passport_flags as $flag ) {
		$settings[ $flag ] = true;
	}
} else {
	$prev = get_option( 'lnkup_e2e_oos_passport_prev', array() );
	foreach ( $passport_flags as $flag ) {
		if ( array_key_exists( $flag, (array) $prev ) ) {
			$settings[ $flag ] = $prev[ $flag ];
		} else {
			unset( $settings[ $flag ] );
		}
	}
	delete_option( 'lnkup_e2e_oos_passport_prev' );
}
update_option( 'lnkup_settings', $settings );
LNKup_Organizer_OS_Tables::maybe_upgrade();
LNKup_Trust_Tables::maybe_upgrade();

$reviewer = get_user_by( 'login', 'e2e_reviewer' );
if ( ! $reviewer ) {
	$reviewer_id = wp_create_user( 'e2e_reviewer', $password, 'e2e_reviewer@example.test' );
	$reviewer    = get_user_by( 'id', $reviewer_id );
}
wp_set_password( $password, $reviewer->ID );
$reviewer->set_role( 'subscriber' );
wp_update_user( array( 'ID' => $reviewer->ID, 'display_name' => 'E2E Reviewer' ) );

$wpdb->delete( LNKup_Trust_Tables::org_members_table(), array( 'org_owner_id' => $org_a->ID, 'user_id' => $reviewer->ID ) );
if ( 'off' === $mode ) {
	WP_CLI::success( 'Organizer OS flags off; reviewer membership removed.' );
	return;
}

$event_main = (int) ( get_posts(
	array(
		'post_type'      => 'lnkup_event',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_lnkup_e2e_key',
		'meta_value'     => 'event:main',
	)
)[0] ?? 0 );
$now = current_time( 'mysql', true );
$wpdb->insert(
	LNKup_Trust_Tables::org_members_table(),
	array(
		'org_owner_id' => $org_a->ID,
		'user_id'      => $reviewer->ID,
		'role'         => 'reviewer',
		'scope_type'   => 'event',
		'scope_id'     => $event_main,
		'status'       => 'active',
		'invited_by'   => $org_a->ID,
		'created_at'   => $now,
		'updated_at'   => $now,
	)
);

/**
 * Find-or-create a post by E2E key.
 *
 * @param string $key  Key.
 * @param array  $args Post args.
 * @param array  $meta Meta.
 */
function lnkup_oos_post( $key, $args, $meta ) {
	$found = get_posts( array( 'post_type' => $args['post_type'], 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_e2e_key', 'meta_value' => $key ) );
	$id    = $found ? (int) $found[0] : (int) wp_insert_post( array_merge( array( 'post_status' => 'publish' ), $args ) );
	update_post_meta( $id, '_lnkup_e2e_key', $key );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

$date  = gmdate( 'Y-m-d', strtotime( '+40 days' ) );
$event = lnkup_oos_post( 'event:waitlist', array( 'post_type' => 'lnkup_event', 'post_title' => 'E2E Waitlist Fair' ), array( '_lnkup_organizer_user_id' => $org_a->ID, '_lnkup_event_date' => $date, '_lnkup_city' => 'Charlotte', '_lnkup_state' => 'NC', '_lnkup_status' => 'published' ) );
$opp   = lnkup_oos_post(
	'opp:waitlist:booth',
	array( 'post_type' => 'lnkup_opportunity', 'post_title' => 'Waitlist Booth' ),
	array(
		'_lnkup_event_id'             => $event,
		'_lnkup_organizer_user_id'    => $org_a->ID,
		'_lnkup_spots_total'          => 1,
		'_lnkup_spots_remaining'      => 1,
		'_lnkup_vendor_fee'           => 90,
		'_lnkup_required_documents'   => 'none',
		'_lnkup_application_deadline' => gmdate( 'Y-m-d', strtotime( $date . ' -7 days' ) ),
		'_lnkup_opportunity_status'   => 'open',
	)
);
wp_set_object_terms( $opp, array( 'Food Vendor', 'Retail' ), 'lnkup_vendor_category' );

foreach ( get_posts( array( 'post_type' => 'lnkup_application', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_lnkup_opportunity_id', 'meta_value' => $opp ) ) as $old ) {
	$pay = LNKup_Payments::get_for_application( $old );
	if ( $pay ) {
		wp_delete_post( $pay->ID, true );
	}
	wp_delete_post( $old, true );
}
$wpdb->delete( LNKup_Organizer_OS_Tables::waitlist_offers_table(), array( 'event_id' => $event ) );
LNKup_Opportunity_Inventory::recompute( $opp );
LNKup_Marketplace_Index::sync_opportunity( $opp );

$apply = static function ( $vendor_id ) use ( $opp ) {
	wp_set_current_user( $vendor_id );
	$id = LNKup_Applications::submit_with_profile( $vendor_id, $opp, array( 'selected_category' => 'Food Vendor', 'accepted_terms' => true, 'application_message' => 'E2E waitlist' ) );
	wp_set_current_user( 0 );
	return is_wp_error( $id ) ? 0 : (int) $id;
};
$booked     = $apply( $vendor_a->ID );
$waitlisted = $apply( $vendor_b->ID );
if ( ! $booked || ! $waitlisted ) {
	WP_CLI::error( 'Could not create the waitlist fixtures.' );
}
wp_set_current_user( $org_a->ID );
LNKup_Applications::handle_organizer_action( $waitlisted, 'waitlist', $org_a->ID );
LNKup_Applications::handle_organizer_action( $booked, 'approve', $org_a->ID );
wp_set_current_user( 0 );

// Wave 2: Organizer A's main event requires a COI; Vendor B (approved on it) shares one through Passport.
if ( class_exists( 'LNKup_Passport_Tables' ) && method_exists( 'LNKup_Passport_Tables', 'create_tables' ) ) {
	LNKup_Passport_Tables::create_tables();
}
$docs_app = (int) ( get_posts(
	array(
		'post_type'      => 'lnkup_application',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => '_lnkup_vendor_user_id', 'value' => (string) $vendor_b->ID ),
			array( 'key' => '_lnkup_event_id', 'value' => (string) $event_main ),
			array( 'key' => '_lnkup_application_status', 'value' => array( 'approved', 'payment_required', 'paid' ), 'compare' => 'IN' ),
		),
	)
)[0] ?? 0 );
if ( $docs_app ) {
	$docs_opp = (int) get_post_meta( $docs_app, '_lnkup_opportunity_id', true );
	update_post_meta( $docs_opp, '_lnkup_document_mode', 'custom' );
	update_post_meta( $docs_opp, '_lnkup_required_documents', 'certificate_of_insurance' );
	$wpdb->delete( LNKup_Organizer_OS_Tables::document_reviews_table(), array( 'application_id' => $docs_app ) );
	delete_transient( 'lnkup_docreq_' . $docs_app . '_certificate_of_insurance' );
	$vendor_doc = (int) ( get_posts( array( 'post_type' => 'lnkup_document', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_key' => '_lnkup_vendor_user_id', 'meta_value' => $vendor_b->ID ) )[0] ?? 0 );
	if ( $vendor_doc ) {
		update_post_meta( $vendor_doc, '_lnkup_document_type', 'certificate_of_insurance' );
		update_post_meta( $vendor_doc, '_lnkup_expiration_date', gmdate( 'Y-m-d', strtotime( '+300 days' ) ) );
		$profile_b = (int) lnkup_get_vendor_profile_id( $vendor_b->ID );
		$shared    = LNKup_Document_Vault::share( $profile_b, $vendor_doc, $docs_app, $vendor_b->ID );
		if ( is_wp_error( $shared ) ) {
			WP_CLI::warning( 'Document share fixture: ' . $shared->get_error_message() );
		}
	}
}
foreach ( array( $event_main, $event ) as $ops_event ) {
	$tasks_table = LNKup_Organizer_OS_Tables::event_tasks_table();
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$tasks_table} WHERE event_id = %d AND title LIKE %s", $ops_event, 'E2E %' ) );
	delete_post_meta( $ops_event, '_lnkup_readiness_marks' );
	LNKup_Event_Readiness::flush( $ops_event );
}

$env = array(
	'PLAYWRIGHT_OPS_EVENT_ID'            => $event_main,
	'PLAYWRIGHT_DOCS_APP_ID'             => $docs_app,
	'PLAYWRIGHT_REVIEWER_USER'           => 'e2e_reviewer',
	'PLAYWRIGHT_WAITLIST_EVENT_ID'       => $event,
	'PLAYWRIGHT_WAITLIST_OPPORTUNITY_ID' => $opp,
	'PLAYWRIGHT_WAITLIST_BOOKED_APP_ID'  => $booked,
	'PLAYWRIGHT_WAITLIST_APP_ID'         => $waitlisted,
);
$file  = __DIR__ . '/.env.e2e';
$lines = file_exists( $file ) ? (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
$lines = array_values(
	array_filter(
		$lines,
		static function ( $line ) use ( $env ) {
			return ! isset( $env[ strtok( $line, '=' ) ] );
		}
	)
);
foreach ( $env as $k => $v ) {
	$lines[] = $k . '=' . $v;
	WP_CLI::log( $k . '=' . $v );
}
file_put_contents( $file, implode( "\n", $lines ) . "\n" );
WP_CLI::success( 'Organizer OS fixtures ready (flags on).' );
