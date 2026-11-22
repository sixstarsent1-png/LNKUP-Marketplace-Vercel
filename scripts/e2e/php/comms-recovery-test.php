<?php
/**
 * Recovery reminders: unfinished application drafts (24h / 72h after the last save) and
 * deadline-aware payment reminders, with every suppression path.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/comms-recovery-test.php
 *
 * @package LNKup_Communications
 */

require __DIR__ . '/_comms_helpers.php';

echo "Recovery reminders\n";
lnkup_ct_all_flows();

/**
 * Draft reminder rows for a vendor, by status.
 *
 * @param int    $vendor Vendor.
 * @param string $status Message status.
 */
function lnkup_cr_draft_msgs( int $vendor, string $status = 'scheduled' ): array {
	return array_values(
		array_filter(
			lnkup_ct_messages_for( $vendor, 'v_application_draft_reminder' ),
			static function ( $m ) use ( $status ) {
				return $m['status'] === $status;
			}
		)
	);
}

/**
 * Save a draft as the vendor.
 *
 * @param int    $vendor Vendor.
 * @param int    $opp    Opportunity.
 * @param string $note   Message text.
 * @return array Draft DTO.
 */
function lnkup_cr_save( int $vendor, int $opp, string $note = 'Hello' ): array {
	wp_set_current_user( $vendor );
	$draft = LNKup_Application_Drafts::upsert( $vendor, $opp, array( 'step' => 'business', 'fields' => array( 'selected_category' => 'General', 'application_message' => $note ) ) );
	if ( is_array( $draft ) ) {
		$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $draft['id'] );
	}
	return is_array( $draft ) ? $draft : array();
}

$organizer = lnkup_ct_user( 'lnkup_organizer' );
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
lnkup_ct_profile( $vendor );

// 1. Save schedules exactly two reminders, 24h and 72h after the save.
$e1    = lnkup_ct_event( $organizer, 30 );
$draft = lnkup_cr_save( $vendor, $e1['opp'] );
$msgs  = lnkup_cr_draft_msgs( $vendor );
lnkup_t_same( 2, count( $msgs ), 'a draft save schedules two reminders' );
$saved  = strtotime( get_post_meta( $draft['id'], '_lnkup_draft_updated_at_gmt', true ) . ' UTC' );
$offset = array_map(
	static function ( $m ) use ( $saved ) {
		return (int) round( ( strtotime( $m['scheduled_at'] . ' UTC' ) - $saved ) / HOUR_IN_SECONDS );
	},
	$msgs
);
sort( $offset );
lnkup_t_same( array( 24, 72 ), $offset, 'reminders land 24h and 72h after the last save' );
lnkup_t_same( array( 'v_application_draft_reminder:d24', 'v_application_draft_reminder:d72' ), wp_list_pluck( $msgs, 'template_key' ), 'each reminder uses its own template' );
lnkup_t_same( 0, (int) $msgs[0]['application_id'], 'draft reminders never carry an application id' );
lnkup_t_same( 'draft:' . $draft['id'], $msgs[0]['cancel_key'], 'cancel key targets the draft' );

// 2. Saving again reschedules (old pair cancelled, new pair scheduled).
lnkup_cr_save( $vendor, $e1['opp'], 'Updated pitch' );
lnkup_t_same( 2, count( lnkup_cr_draft_msgs( $vendor ) ), 'a later save keeps exactly two reminders scheduled' );
lnkup_t_same( 2, count( lnkup_cr_draft_msgs( $vendor, 'cancelled' ) ), 'the earlier pair is cancelled' );

// 3. Submitting cancels everything.
wp_set_current_user( $vendor );
$done = LNKup_Application_Drafts::finalize( $draft['id'], $vendor, array( 'accepted_terms' => true ) );
if ( is_array( $done ) && ! empty( $done['application_id'] ) ) {
	$GLOBALS['lnkup_test_cleanup'][] = array( 'post', (int) $done['application_id'] );
}
lnkup_t_assert( is_array( $done ) && $done['application_id'] > 0, 'draft submits' );
lnkup_t_same( 0, count( lnkup_cr_draft_msgs( $vendor ) ), 'submitting cancels the reminders' );

// 4. Deadline sooner than 72h: only the 24h reminder.
$e2 = lnkup_ct_event( $organizer, 6 );
update_post_meta( $e2['opp'], '_lnkup_application_deadline', wp_date( 'Y-m-d', time() + DAY_IN_SECONDS + 2 * HOUR_IN_SECONDS ) );
lnkup_cr_save( $vendor, $e2['opp'] );
$soon = lnkup_cr_draft_msgs( $vendor );
lnkup_t_same( array( 'v_application_draft_reminder:d24' ), wp_list_pluck( $soon, 'template_key' ), 'no reminder is scheduled after applications close' );

// 5. Discard cancels.
$e3 = lnkup_ct_event( $organizer, 30 );
$d3 = lnkup_cr_save( $vendor, $e3['opp'] );
LNKup_Application_Drafts::discard( $d3['id'], $vendor );
lnkup_t_same( 0, count( array_filter( lnkup_cr_draft_msgs( $vendor ), static function ( $m ) use ( $d3 ) { return 'draft:' . $d3['id'] === $m['cancel_key']; } ) ), 'discarding cancels the reminders' );

// 6. Expiry (slot closed) cancels.
$e4 = lnkup_ct_event( $organizer, 30 );
$d4 = lnkup_cr_save( $vendor, $e4['opp'] );
update_post_meta( $e4['opp'], '_lnkup_opportunity_status', 'closed' );
LNKup_Application_Drafts::expire_stale();
lnkup_t_same( 'expired', get_post_meta( $d4['id'], '_lnkup_draft_state', true ), 'closed slot expires the draft' );
lnkup_t_same( 0, count( array_filter( lnkup_cr_draft_msgs( $vendor ), static function ( $m ) use ( $d4 ) { return 'draft:' . $d4['id'] === $m['cancel_key']; } ) ), 'expiry cancels the reminders' );

// 7. Event cancellation cancels (messages carry the event).
$e5 = lnkup_ct_event( $organizer, 30 );
$d5 = lnkup_cr_save( $vendor, $e5['opp'] );
LNKup_Comms_Listen_Events::cancel_event_messages( $e5['event'], 'event_cancelled' );
lnkup_t_same( 0, count( array_filter( lnkup_cr_draft_msgs( $vendor ), static function ( $m ) use ( $d5 ) { return 'draft:' . $d5['id'] === $m['cancel_key']; } ) ), 'event cancellation cancels the reminders' );

// 8. Send-time validity: deadline passed, stale revision, already applied, wrong owner.
$e6  = lnkup_ct_event( $organizer, 30 );
$d6  = lnkup_cr_save( $vendor, $e6['opp'] );
$m6  = lnkup_cr_draft_msgs( $vendor );
$m6  = array_values( array_filter( $m6, static function ( $m ) use ( $d6 ) { return 'draft:' . $d6['id'] === $m['cancel_key']; } ) );
$ctx = LNKup_Comms_Context::build( (array) json_decode( $m6[0]['context'], true ) );
lnkup_t_same( '', LNKup_Comms_Context::check( 'draft_still_open', $ctx ), 'an open draft passes validity' );
update_post_meta( $e6['opp'], '_lnkup_application_deadline', wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) );
lnkup_t_assert( '' !== LNKup_Comms_Context::check( 'draft_still_open', $ctx ), 'validity blocks a reminder after the deadline' );
update_post_meta( $e6['opp'], '_lnkup_application_deadline', wp_date( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) );
lnkup_t_assert( '' !== LNKup_Comms_Context::check( 'draft_still_open', array_merge( $ctx, array( 'draft_rev' => (int) $ctx['draft_rev'] - 1 ) ) ), 'validity blocks a reminder from an older save' );
lnkup_t_assert( '' !== LNKup_Comms_Context::check( 'draft_still_open', array_merge( $ctx, array( 'vendor_user_id' => $organizer ) ) ), 'validity blocks a reminder for someone else\'s draft' );
$other_app = lnkup_ct_application( $vendor, $organizer, $e6, 'submitted' );
lnkup_t_assert( '' !== LNKup_Comms_Context::check( 'draft_still_open', $ctx ), 'validity blocks a reminder once the vendor has applied' );
lnkup_t_same( '/apply/' . $e6['opp'], LNKup_Comms_Context::deep_link( 'draft_resume', $ctx, 'vendor' ), 'push opens the saved application in the app' );
lnkup_t_assert( false !== strpos( LNKup_Comms_Context::web_url( 'draft_resume', $ctx, 'vendor' ), 'resume=1' ), 'email opens the saved application on the web' );

// 9. Flag off: saving schedules nothing.
lnkup_ct_settings( array( 'flow_application_drafts' => false ) );
$e7     = lnkup_ct_event( $organizer, 30 );
$before = count( lnkup_ct_messages_for( $vendor, 'v_application_draft_reminder' ) );
lnkup_cr_save( $vendor, $e7['opp'] );
lnkup_t_same( $before, count( lnkup_ct_messages_for( $vendor, 'v_application_draft_reminder' ) ), 'flag off schedules no draft reminders' );
lnkup_ct_settings( array( 'flow_application_drafts' => true ) );

// 10. Payment reminders: deadline-aware windows, stop on payment and withdrawal.
/**
 * Approved application with a pending payment due in $days days.
 */
function lnkup_cr_due_app( int $vendor, int $organizer, int $days ): int {
	$event = lnkup_ct_event( $organizer, $days + 10 );
	$app   = lnkup_ct_application( $vendor, $organizer, $event, 'payment_required' );
	lnkup_ct_payment( $app, $vendor, $organizer, 'pending' );
	update_post_meta( $app, '_lnkup_payment_due_at', gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) );
	return $app;
}
/**
 * Scheduled payment reminders for one application.
 */
function lnkup_cr_pay_msgs( int $vendor, int $app ): array {
	return array_values(
		array_filter(
			lnkup_ct_messages_for( $vendor, 'v_payment_reminder' ),
			static function ( $m ) use ( $app ) {
				return 'scheduled' === $m['status'] && (int) $m['application_id'] === $app;
			}
		)
	);
}
$pay_vendor = lnkup_ct_user( 'lnkup_vendor' );
$long       = lnkup_cr_due_app( $pay_vendor, $organizer, 10 );
LNKup_Comms_Listen_Applications::schedule_payment_reminders( $long, $pay_vendor );
$windows = array_map( static function ( $m ) { return substr( $m['dedupe_key'], strrpos( $m['dedupe_key'], '|' ) + 1 ); }, lnkup_cr_pay_msgs( $pay_vendor, $long ) );
sort( $windows );
lnkup_t_same( array( 'due_d3', 'due_d7', 'due_h24', 'due_h6' ), $windows, 'a 10-day deadline gets 7d, 3d, 24h and 6h reminders' );
$short = lnkup_cr_due_app( $pay_vendor, $organizer, 7 );
LNKup_Comms_Listen_Applications::schedule_payment_reminders( $short, $pay_vendor );
lnkup_t_same( 3, count( lnkup_cr_pay_msgs( $pay_vendor, $short ) ), 'the default 7-day deadline skips the 7-day reminder' );

do_action( 'lnkup_application_status_changed', $long, 'payment_required', 'paid' );
lnkup_t_same( 0, count( lnkup_cr_pay_msgs( $pay_vendor, $long ) ), 'payment cancels every payment reminder' );
do_action( 'lnkup_application_withdrawn', $short, 'payment_required' );
lnkup_t_same( 0, count( lnkup_cr_pay_msgs( $pay_vendor, $short ) ), 'withdrawal cancels every payment reminder' );

// 11. No double reminders from core invoice reminders on the same deadline.
$inv_app = lnkup_cr_due_app( $pay_vendor, $organizer, 5 );
$before  = count( lnkup_ct_messages_for( $pay_vendor, 'v_payment_reminder' ) );
lnkup_t_assert( LNKup_Comms_Delegation::adapt_invoice_reminder( array( array( 'application_id' => $inv_app, 'vendor_user_id' => $pay_vendor ), '3_days' ) ), 'invoice reminder is claimed so core stays quiet' );
lnkup_t_same( $before, count( lnkup_ct_messages_for( $pay_vendor, 'v_payment_reminder' ) ), 'no second reminder for the same deadline' );
LNKup_Comms_Delegation::adapt_invoice_reminder( array( array( 'application_id' => $inv_app, 'vendor_user_id' => $pay_vendor ), 'past_due' ) );
lnkup_t_same( $before + 1, count( lnkup_ct_messages_for( $pay_vendor, 'v_payment_reminder' ) ), 'the past-due notice still comes from the invoice' );

// 12. Processing bank payments are not "due".
$proc = lnkup_cr_due_app( $pay_vendor, $organizer, 5 );
$proc_pay = LNKup_Payments::get_for_application( $proc );
update_post_meta( $proc_pay->ID, '_lnkup_payment_status', 'processing' );
lnkup_t_assert( ! LNKup_Comms_Context::payment_due( $proc, (int) $proc_pay->ID ), 'a processing payment gets no payment reminder' );

wp_set_current_user( 0 );
lnkup_ct_finish();
