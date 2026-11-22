<?php
/**
 * Schema, wiring and catalog integrity for lnkup-communications.
 *
 * @package LNKup_Communications
 */

require_once __DIR__ . '/_comms_helpers.php';

global $wpdb;

echo "Schema\n";
foreach ( array( 'events', 'messages', 'deliveries', 'preferences', 'suppressions', 'templates', 'campaigns', 'clicks' ) as $table ) {
	$name = LNKup_Comms_Schema::table( $table );
	lnkup_t_same( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), "table {$table} exists" );
}
lnkup_t_same( LNKUP_COMMS_DB_VERSION, get_option( LNKup_Comms_Schema::DB_OPTION ), 'db version recorded' );

echo "Action Scheduler\n";
lnkup_t_assert( function_exists( 'as_enqueue_async_action' ) && did_action( 'action_scheduler_init' ) > 0, 'Action Scheduler loaded and initialised' );
delete_transient( 'lnkup_comms_recurring_checked' );
LNKup_Comms_Queue::ensure_recurring();
foreach ( array_keys( LNKup_Comms_Queue::RECURRING ) as $hook ) {
	lnkup_t_assert( as_has_scheduled_action( $hook, array(), LNKup_Comms_Queue::GROUP ), "recurring {$hook} scheduled" );
}

echo "REST routes\n";
$routes   = rest_get_server()->get_routes();
$expected = array( '/lnkup/v1/comms/preferences', '/lnkup/v1/comms/inbox', '/lnkup/v1/comms/inbox/count', '/lnkup/v1/comms/inbox/read-all', '/lnkup/v1/comms/opened', '/lnkup/v1/comms/next-steps', '/lnkup/v1/comms/admin/overview', '/lnkup/v1/comms/admin/logs', '/lnkup/v1/organizer/events/(?P<id>\d+)/status', '/lnkup/v1/applications/(?P<id>\d+)/withdraw' );
foreach ( $expected as $route ) {
	lnkup_t_assert( isset( $routes[ $route ] ), "route {$route} registered" );
}
$missing_perm = 0;
foreach ( $routes as $path => $handlers ) {
	if ( false === strpos( $path, '/lnkup/v1/comms' ) ) {
		continue;
	}
	foreach ( $handlers as $handler ) {
		if ( empty( $handler['permission_callback'] ) ) {
			++$missing_perm;
		}
	}
}
lnkup_t_same( 0, $missing_perm, 'every comms route has a permission_callback' );

echo "Registry & templates\n";
$samples  = LNKup_Comms_Admin_REST::sample_variables();
$failures = array();
foreach ( LNKup_Comms_Templates::defaults() as $key => $template ) {
	$vars = array();
	foreach ( LNKup_Comms_Templates::placeholders( $template ) as $name ) {
		$vars[ $name ] = $samples[ $name ] ?? 'x';
	}
	$out = LNKup_Comms_Templates::render( $key, $vars, 0 === strpos( $key, 'o_' ) ? 'organizer' : 'vendor' );
	if ( is_wp_error( $out ) || false !== strpos( wp_json_encode( $out ), '{{' ) ) {
		$failures[] = $key;
	}
}
lnkup_t_same( array(), $failures, 'every template renders with no unresolved placeholders' );

$missing_tpl = array();
foreach ( LNKup_Comms_Registry::triggers() as $key => $def ) {
	$has = null !== LNKup_Comms_Templates::get( $key );
	foreach ( array_keys( LNKup_Comms_Templates::defaults() ) as $tpl ) {
		$has = $has || 0 === strpos( $tpl, $key . ':' );
	}
	if ( ! $has ) {
		$missing_tpl[] = $key;
	}
	if ( ! LNKup_Comms_Registry::category( (string) $def['category'] ) ) {
		$missing_tpl[] = $key . '(category)';
	}
	if ( ! in_array( $def['priority'], LNKup_Comms_Registry::PRIORITIES, true ) ) {
		$missing_tpl[] = $key . '(priority)';
	}
}
lnkup_t_same( array(), $missing_tpl, 'every trigger has a template, a valid category and priority' );

$required_fail = LNKup_Comms_Templates::render( 'v_application_submitted', array( 'first_name' => 'A' ), 'vendor' );
lnkup_t_assert( is_wp_error( $required_fail ) && false !== strpos( $required_fail->get_error_message(), 'event_name' ), 'missing required variable blocks rendering' );
$dropped = LNKup_Comms_Templates::render( 'v_application_rejected', array( 'event_name' => 'Fest', 'first_name' => 'A', 'rejection_reason' => '' ), 'vendor' );
lnkup_t_assert( ! is_wp_error( $dropped ) && 2 === count( $dropped['paragraphs'] ), 'paragraph with empty optional variable is dropped' );
$escaped = LNKup_Comms_Templates::email_html( LNKup_Comms_Templates::render( 'v_application_submitted', array( 'event_name' => '<script>alert(1)</script>' ), 'vendor' ), 'https://example.test', array() );
lnkup_t_assert( false === strpos( $escaped, '<script>alert' ), 'template output is escaped' );

echo "Delegation map\n";
$methods = array();
foreach ( get_class_methods( 'LNKup_Notifications' ) as $method ) {
	if ( preg_match( '/^(?:send|resend)_(.+)$/', $method, $m ) ) {
		$methods[ $m[1] ] = true;
	}
}
$unknown = array();
foreach ( array_keys( LNKup_Comms_Delegation::map() ) as $key ) {
	if ( ! isset( $methods[ $key ] ) && ! in_array( $key, array( 'invoice_email', 'financial_reminder' ), true ) ) {
		$unknown[] = $key;
	}
}
lnkup_t_same( array(), $unknown, 'every delegated key maps to a real LNKup_Notifications sender' );

lnkup_ct_settings( array( 'flow_applications' => false ) );
lnkup_t_assert( ! LNKup_Notifications::delegated( 'application_submitted', array( 1 ) ), 'flag off: core keeps sending' );
lnkup_ct_settings( array( 'flow_applications' => true ) );
lnkup_t_assert( LNKup_Notifications::delegated( 'application_submitted', array( 1 ) ), 'flag on: core send is delegated' );
lnkup_t_assert( ! LNKup_Notifications::delegated( 'saved_search_match', array( 1 ) ), 'unclaimed keys stay in core' );

echo "WP-CLI rollout helpers\n";
lnkup_ct_settings( array( 'flow_applications' => false, 'delivery_paused' => false ) );
$status = LNKup_Comms_CLI::status_data();
lnkup_t_assert( isset( $status['flags']['flow_applications'], $status['queued'], $status['overdue'], $status['health'], $status['last_24h'] ), 'status reports flags, queue, health and recent sends' );
lnkup_t_same( false, $status['flags']['flow_applications'], 'status reflects a flag that is off' );
lnkup_t_assert( ! in_array( 'application_submitted', $status['claimed'], true ), 'flag off: nothing claimed' );

lnkup_t_assert( true === LNKup_Comms_CLI::set_flag( 'flow_applications', true, 'test' ), 'flag can be turned on' );
lnkup_t_assert( in_array( 'application_submitted', LNKup_Comms_CLI::status_data()['claimed'], true ), 'flag on: core application messages claimed' );
lnkup_t_assert( true === LNKup_Comms_CLI::set_flag( 'flow_applications', false, 'test' ), 'flag can be turned back off' );
lnkup_t_assert( ! LNKup_Comms_Settings::enabled( 'flow_applications' ), 'rollback restores core sending' );
lnkup_t_assert( is_wp_error( LNKup_Comms_CLI::set_flag( 'not_a_flag', true, 'test' ) ), 'unknown flags are refused' );
lnkup_t_assert( is_wp_error( LNKup_Comms_CLI::set_flag( 'cap_promo_push_daily', true, 'test' ) ), 'non-flag settings cannot be toggled from the CLI' );
LNKup_Comms_CLI::set_flag( 'delivery_paused', true, 'test' );
lnkup_t_assert( LNKup_Comms_CLI::status_data()['delivery_paused'], 'pause is reported' );
LNKup_Comms_CLI::set_flag( 'delivery_paused', false, 'test' );

$cli_vendor = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_settings( array( 'test_user_ids' => '' ) );
lnkup_t_assert( is_wp_error( LNKup_Comms_CLI::test_send( 'v_welcome', $cli_vendor ) ), 'test sends refuse accounts that are not configured test accounts' );
lnkup_ct_settings( array( 'test_user_ids' => (string) $cli_vendor ) );
lnkup_t_assert( is_wp_error( LNKup_Comms_CLI::test_send( 'nope', $cli_vendor ) ), 'unknown trigger refused' );
$cli_msg = LNKup_Comms_CLI::test_send( 'v_welcome', $cli_vendor );
lnkup_t_assert( is_int( $cli_msg ) && $cli_msg > 0, 'test send queues to a configured test account even with the flow flag off' );

echo "CLI cancel for a flag\n";
lnkup_ct_settings( array( 'flow_onboarding' => true, 'flow_applications' => true ) );
$cancel_org     = lnkup_ct_user( 'lnkup_organizer' );
$cancel_event   = lnkup_ct_event( $cancel_org );
$cancel_app     = lnkup_ct_application( $cli_vendor, $cancel_org, $cancel_event, 'rejected', lnkup_ct_profile( $cli_vendor ) );
$onboard_msg    = LNKup_Comms_Engine::dispatch( 'v_welcome', $cli_vendor, array( 'vendor_user_id' => $cli_vendor ), array( 'window' => 'cancel_test', 'at' => time() + HOUR_IN_SECONDS ) );
$other_flow_msg = LNKup_Comms_Engine::dispatch( 'v_application_rejected', $cli_vendor, array( 'application_id' => $cancel_app ), array( 'window' => 'cancel_test', 'at' => time() + HOUR_IN_SECONDS ) );
lnkup_t_assert( $onboard_msg > 0 && $other_flow_msg > 0, 'messages queued for two flows' );
lnkup_t_assert( is_wp_error( LNKup_Comms_CLI::cancel_for_flag( 'nope', 'test' ) ), 'cancel refuses unknown flags' );
lnkup_t_assert( LNKup_Comms_CLI::cancel_for_flag( 'flow_onboarding', 'test' ) >= 1, 'cancel reports messages cancelled' );
lnkup_t_same( array( 'cancelled', 'cancelled:flag_off' ), array( lnkup_ct_message( $onboard_msg )['status'], lnkup_ct_message( $onboard_msg )['suppression_reason'] ), 'that flow\'s queued message is cancelled' );
lnkup_t_same( 'scheduled', lnkup_ct_message( $other_flow_msg )['status'], 'other flows are untouched' );

lnkup_ct_finish();
