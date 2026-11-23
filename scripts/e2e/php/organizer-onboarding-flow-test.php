<?php
/**
 * Organizer onboarding v2: progressive signup, resumable server-side state, format routing,
 * drafts, skip/resume, payout honesty, self-only access and the legacy drip hand-off.
 *
 * Local wp-env only; settings and fixtures are restored on exit.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/organizer-onboarding-flow-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Organizer onboarding v2\n";

$original_settings = get_option( 'lnkup_settings', array() );
$original_register = get_option( 'users_can_register' );
register_shutdown_function(
	static function () use ( $original_settings, $original_register ) {
		update_option( 'lnkup_settings', $original_settings, false );
		update_option( 'users_can_register', $original_register );
	}
);
update_option( 'users_can_register', 1 );

/**
 * @param array $flags Settings overrides.
 */
function lnkup_ob_flags( array $flags ): void {
	$settings = get_option( 'lnkup_settings', array() );
	update_option( 'lnkup_settings', array_merge( is_array( $settings ) ? $settings : array(), $flags ), false );
}

/**
 * @param int    $user   Acting user.
 * @param string $method Method.
 * @param string $route  Route.
 * @param array  $body   Body / query.
 */
function lnkup_ob_rest( int $user, string $method, string $route, array $body = array() ) {
	wp_set_current_user( $user );
	$req = new WP_REST_Request( $method, $route );
	if ( 'GET' === $method ) {
		$req->set_query_params( $body );
	} elseif ( $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	wp_set_current_user( 0 );
	return $res;
}

/**
 * Register through the same path as the web sign-up form.
 *
 * @param array $extra Extra input.
 * @return int|WP_Error
 */
function lnkup_ob_register( array $extra ) {
	$email  = 'ob_' . wp_generate_password( 8, false, false ) . '@example.test';
	$result = LNKup_Registration::register_user(
		array_merge(
			array(
				'email'            => $email,
				'password'         => 'Passw0rd!long',
				'password_confirm' => 'Passw0rd!long',
				'role'             => 'lnkup_organizer',
				'terms'            => true,
			),
			$extra
		)
	);
	if ( ! is_wp_error( $result ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'user', (int) $result );
	}
	return $result;
}

/**
 * @param array $flow Flow payload.
 * @return array<string, array> key => step.
 */
function lnkup_ob_steps( array $flow ): array {
	$out = array();
	foreach ( $flow['steps'] as $step ) {
		$out[ $step['key'] ] = $step;
	}
	return $out;
}

$category = array_key_first( LNKup_Organizer_Onboarding_Flow::category_options() );

// ---------------------------------------------------------------- flag off
lnkup_ob_flags( array( 'organizer_onboarding_v2_enabled' => false, 'portfolio_os_enabled' => false, 'portfolio_os_user_ids' => '' ) );
$off_user = lnkup_ob_register( array( 'require_organization' => true ) );
lnkup_t_assert( is_int( $off_user ), 'flag off: web signup without organization fields still works' );
lnkup_t_same( 404, lnkup_ob_rest( (int) $off_user, 'GET', '/lnkup/v1/organizer/onboarding' )->get_status(), 'flag off: guided setup REST returns 404' );
lnkup_t_assert( ! LNKup_Organizer_Onboarding_Flow::should_resume( (int) $off_user ), 'flag off: login does not redirect to Get started' );
$off_app = lnkup_ob_register( array( 'organization_name' => 'Older App Markets', 'city' => 'Austin', 'state' => 'TX' ) );
lnkup_t_same( 'Older App Markets', (string) get_user_meta( (int) $off_app, '_lnkup_organizer_public_name', true ), 'organizer basics sent by the app are saved even with the flag off' );

// ---------------------------------------------------------------- signup v2
lnkup_ob_flags( array( 'organizer_onboarding_v2_enabled' => true ) );
$missing = lnkup_ob_register( array( 'require_organization' => true, 'city' => 'Houston', 'state' => 'TX' ) );
lnkup_t_same( 'organization_required', is_wp_error( $missing ) ? $missing->get_error_code() : '', 'web signup requires an organization name' );
$no_city = lnkup_ob_register( array( 'require_organization' => true, 'organization_name' => 'Six Test Ent' ) );
lnkup_t_same( 'location_required', is_wp_error( $no_city ) ? $no_city->get_error_code() : '', 'web signup requires city and state' );
$app_user = lnkup_ob_register( array() );
lnkup_t_assert( is_int( $app_user ), 'app signup (not required) works without organization fields' );

$org = lnkup_ob_register(
	array(
		'require_organization' => true,
		'organization_name'    => 'Onboarding Test Productions',
		'city'                 => 'Houston',
		'state'                => 'TX',
		'category'             => (string) $category,
	)
);
lnkup_t_assert( is_int( $org ), 'organizer created with organization basics' );
$org = (int) $org;
lnkup_t_same( 'Onboarding Test Productions', (string) get_user_meta( $org, '_lnkup_organizer_public_name', true ), 'organization name saved to the public profile' );
lnkup_t_same( 'Houston', (string) get_user_meta( $org, '_lnkup_organizer_city', true ), 'primary city saved' );
lnkup_t_same( (string) $category, (string) get_user_meta( $org, '_lnkup_organizer_category', true ), 'category saved' );
lnkup_t_assert( LNKup_Organizer_Onboarding_Flow::should_resume( $org ), 'new organizer with setup unfinished resumes on Get started' );

$res  = lnkup_ob_rest( $org, 'GET', '/lnkup/v1/organizer/onboarding' );
lnkup_t_same( 200, $res->get_status(), 'organizer reads their guided setup' );
$flow  = $res->get_data()['data'];
$steps = lnkup_ob_steps( $flow );
lnkup_t_same( array( 'account', 'organization', 'format', 'first_event', 'payouts', 'share' ), array_keys( $steps ), 'six canonical steps in order' );
lnkup_t_same( 'complete', $steps['account']['status'], 'account step is complete' );
lnkup_t_same( 'complete', $steps['organization']['status'], 'signup fields complete the organization step' );
lnkup_t_same( 'format', $flow['next_step']['key'] ?? '', 'next step is choosing what you organize' );
lnkup_t_same( 'organization', $flow['state']['current_step'], 'signup records where setup started' );

// ---------------------------------------------------------------- formats + routing
lnkup_t_same( 400, lnkup_ob_rest( $org, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'formats' => array( 'rocket' ) ) )->get_status(), 'unknown format is rejected' );
$res   = lnkup_ob_rest( $org, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'formats' => array( 'tour', 'single' ), 'current_step' => 'first_event' ) );
$flow  = $res->get_data()['data'];
$steps = lnkup_ob_steps( $flow );
lnkup_t_same( 'complete', $steps['format']['status'], 'format step complete after choosing' );
lnkup_t_same( array( 'tour', 'single' ), $flow['state']['formats'], 'formats stored in order' );
lnkup_t_assert( false !== strpos( $steps['first_event']['cta_url'], 'view=create' ), 'tour without Portfolio OS routes to Create Event' );
lnkup_t_assert( '' !== $flow['formats_routing']['tour']['note'], 'routing explains tours are coming' );
lnkup_t_same( 'Set up your tour', $steps['first_event']['title'], 'first-event step follows the chosen format' );

lnkup_ob_flags( array( 'portfolio_os_user_ids' => (string) $org ) );
$flow  = LNKup_Organizer_Onboarding_Flow::for_user( $org );
$steps = lnkup_ob_steps( $flow );
lnkup_t_assert( false !== strpos( $steps['first_event']['cta_url'], 'view=tours' ) && false !== strpos( $steps['first_event']['cta_url'], 'new=tour' ), 'tour with Portfolio OS opens the tour wizard' );
lnkup_t_same( '', $flow['formats_routing']['tour']['note'], 'no routing note when tours are available' );

// ---------------------------------------------------------------- skip + resume
lnkup_t_same( 400, lnkup_ob_rest( $org, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'skip' => 'organization' ) )->get_status(), 'required steps cannot be skipped' );
lnkup_ob_flags( array( 'stripe_connect_enabled' => true ) );
$flow  = lnkup_ob_rest( $org, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'skip' => 'payouts' ) )->get_data()['data'];
$steps = lnkup_ob_steps( $flow );
lnkup_t_assert( $steps['payouts']['skipped'] && 'complete' !== $steps['payouts']['status'], 'skipped payouts is remembered and never marked complete' );
lnkup_t_same( 'first_event', $flow['next_step']['key'] ?? '', 'next step moves past the skipped payout step' );
lnkup_t_same( 'first_event', LNKup_Organizer_Onboarding_Flow::get_state( $org )['current_step'], 'current step is stored server-side for other devices' );
lnkup_t_assert( false !== strpos( $flow['resume_url'], 'view=tours' ), 'resume URL points at the step the organizer was on' );

// Payout verifying is never complete.
$ca_table = LNKup_Financial_Tables::connected_accounts_table();
$wpdb->insert( $ca_table, array( 'user_id' => $org, 'role_type' => 'organizer', 'stripe_account_id' => 'acct_onbverify0001', 'details_submitted' => 1, 'disabled_reason' => 'requirements.pending_verification', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
$steps = lnkup_ob_steps( LNKup_Organizer_Onboarding_Flow::for_user( $org ) );
lnkup_t_same( 'verifying', $steps['payouts']['payout_state'], 'payout step shows Stripe is verifying' );
lnkup_t_assert( 'complete' !== $steps['payouts']['status'], 'payout step is not complete while verifying' );
$wpdb->delete( $ca_table, array( 'user_id' => $org, 'role_type' => 'organizer' ) );
lnkup_ob_flags( array( 'stripe_connect_enabled' => false ) );

// ---------------------------------------------------------------- drafts across devices
$draft = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'Onboarding Draft Market', 'meta_input' => array( '_lnkup_organizer_user_id' => $org, '_lnkup_event_status' => 'draft' ) ) );
$stop  = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Onboarding Tour Stop', 'meta_input' => array( '_lnkup_organizer_user_id' => $org, '_lnkup_event_status' => 'draft', '_lnkup_tour_id' => 999999 ) ) );
$tour  = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'draft', 'post_title' => 'Onboarding Draft Tour', 'meta_input' => array( '_lnkup_organizer_user_id' => $org, '_lnkup_tour_type' => 'tour' ) ) );
foreach ( array( $draft, $stop, $tour ) as $p ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $p );
}
$titles = wp_list_pluck( LNKup_Organizer_Onboarding_Flow::resume_targets( $org ), 'title' );
lnkup_t_assert( in_array( 'Onboarding Draft Market', $titles, true ), 'draft event offered to continue' );
lnkup_t_assert( in_array( 'Onboarding Draft Tour', $titles, true ), 'draft tour offered to continue (Portfolio OS on)' );
lnkup_t_assert( ! in_array( 'Onboarding Tour Stop', $titles, true ), 'tour stops are not listed separately' );

// ---------------------------------------------------------------- access
$other  = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$admin  = lnkup_t_user( 'administrator' );
lnkup_t_same( 403, lnkup_ob_rest( $other, 'GET', '/lnkup/v1/organizer/onboarding', array( 'organizer_id' => $org ) )->get_status(), 'another organizer cannot read this setup' );
lnkup_t_same( 403, lnkup_ob_rest( $other, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'organizer_id' => $org, 'formats' => array( 'single' ) ) )->get_status(), 'another organizer cannot change this setup' );
lnkup_t_same( 403, lnkup_ob_rest( $vendor, 'GET', '/lnkup/v1/organizer/onboarding' )->get_status(), 'vendors have no guided organizer setup' );
lnkup_t_same( 200, lnkup_ob_rest( $admin, 'GET', '/lnkup/v1/organizer/onboarding', array( 'organizer_id' => $org ) )->get_status(), 'admins can read an organizer setup for support' );
lnkup_t_same( array( 'tour', 'single' ), LNKup_Organizer_Onboarding_Flow::get_state( $org )['formats'], 'failed writes changed nothing' );

// ---------------------------------------------------------------- dismiss
lnkup_ob_rest( $org, 'PATCH', '/lnkup/v1/organizer/onboarding', array( 'dismiss' => true ) );
lnkup_t_assert( ! LNKup_Organizer_Onboarding_Flow::should_resume( $org ), 'dismissed guide no longer redirects login' );
if ( class_exists( 'LNKup_Comms_Context' ) ) {
	lnkup_t_assert( ! LNKup_Comms_Context::guided_setup_open( $org ), 'Comms onboarding nudges stop once the guide is dismissed' );
}

// ---------------------------------------------------------------- legacy drip hand-off
if ( class_exists( 'LNKup_Comms_Settings' ) ) {
	$comms_key   = class_exists( 'LNKup_Comms_Settings' ) && defined( 'LNKup_Comms_Settings::OPTION' ) ? LNKup_Comms_Settings::OPTION : 'lnkup_comms_settings';
	$comms_saved = get_option( $comms_key );
	$comms       = is_array( $comms_saved ) ? $comms_saved : array();
	update_option( $comms_key, array_merge( $comms, array( 'flow_onboarding' => true ) ) );
	lnkup_t_assert( LNKup_Activation_Emails::comms_handles( 'flow_onboarding' ), 'Communications OS owns the organizer onboarding sequence' );
	$welcome_user = lnkup_t_user( 'lnkup_organizer' );
	delete_user_meta( $welcome_user, 'lnkup_activation_welcome_sent' );
	lnkup_t_assert( ! LNKup_Activation_Emails::send_welcome_organizer( $welcome_user ), 'legacy organizer welcome is not sent twice' );
	update_option( $comms_key, $comms_saved );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN (%d, %d, %d)", LNKup_Organizer_Onboarding_Flow::META, $org, (int) $off_user, (int) $app_user ) );
lnkup_t_finish();
