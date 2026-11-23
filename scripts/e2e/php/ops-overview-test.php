<?php
/**
 * Operations queue: manage_all only, per-event grouping, test-account exclusion, dismiss until the
 * item changes, snooze expiry, audit rows, and no private document names or trust narratives.
 *
 * Run: npx -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/ops-overview-test.php
 */

require __DIR__ . '/_helpers.php';
require __DIR__ . '/_comms_helpers.php';

global $wpdb;

/**
 * Dispatch a REST request as a user and return [status, data].
 */
function lnkup_ops_rest( int $user_id, string $method, string $route, array $params = array() ): array {
	wp_set_current_user( $user_id );
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

/**
 * Find a queue item by key.
 */
function lnkup_ops_find( array $queue, string $key ): ?array {
	foreach ( $queue['items'] as $item ) {
		if ( $item['key'] === $key ) {
			return $item;
		}
	}
	return null;
}

/**
 * Move an application's post_date back by N days.
 */
function lnkup_ops_age( int $app, int $days ): void {
	$date = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	wp_update_post( array( 'ID' => $app, 'post_date' => get_date_from_gmt( $date ), 'post_date_gmt' => $date ) );
}

$admin     = lnkup_ct_user( 'administrator' );
$organizer = lnkup_ct_user( 'lnkup_organizer' );
$vendor    = lnkup_ct_user( 'lnkup_vendor' );
$vendor2   = lnkup_ct_user( 'lnkup_vendor' );
$tester    = lnkup_ct_user( 'lnkup_vendor' );
update_user_meta( $tester, LNKup_Analytics_Exclusions::USER_META, '1' );
LNKup_Analytics_Exclusions::flush();

$event = lnkup_ct_event( $organizer, 10 );
$other = lnkup_ct_event( $organizer, 12 );
$posts = array( $event['event'], $event['opp'], $other['event'], $other['opp'] );

list( $status ) = lnkup_ops_rest( $organizer, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_same( 403, $status, 'organizer cannot read the ops queue' );
list( $status ) = lnkup_ops_rest( $vendor, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_same( 403, $status, 'vendor cannot read the ops queue' );
list( $status ) = lnkup_ops_rest( $organizer, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => 'trust:cases', 'state' => 'dismissed' ) );
lnkup_t_same( 403, $status, 'organizer cannot dismiss items' );

$old_a = lnkup_ct_application( $vendor, $organizer, $event );
$old_b = lnkup_ct_application( $vendor2, $organizer, $event );
$fresh = lnkup_ct_application( $vendor2, $organizer, $other );
$test  = lnkup_ct_application( $tester, $organizer, $other );
lnkup_ops_age( $old_a, 8 );
lnkup_ops_age( $old_b, 6 );
lnkup_ops_age( $test, 9 );
$posts = array_merge( $posts, array( $old_a, $old_b, $fresh, $test ) );

$unpaid = lnkup_ct_application( $vendor, $organizer, $other, 'payment_required' );
update_post_meta( $unpaid, '_lnkup_payment_due_at', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) );
$posts[] = $unpaid;

$doc_title = 'OPS-PRIVACY passport scan ' . wp_generate_password( 6, false ) . '.pdf';
$posts[]   = lnkup_ct_post( 'lnkup_document', $doc_title, array( 'vendor_user_id' => $vendor, 'document_status' => 'pending' ) );
$narrative = 'OPS-PRIVACY narrative ' . wp_generate_password( 8, false );
$case_id   = LNKup_Trust_Cases::open(
	array(
		'case_type'    => 'manual',
		'severity'     => 'critical',
		'subject_type' => 'user',
		'subject_id'   => $vendor,
		'signal_key'   => 'ops_test_' . $vendor,
		'summary'      => $narrative,
		'next_step'    => $narrative,
		'evidence'     => array( array( 'note' => $narrative ) ),
	)
);
lnkup_t_assert( $case_id > 0, 'trust case fixture opened' );

list( $status, $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_same( 200, $status, 'admin reads the ops queue' );
foreach ( array( 'items', 'hidden', 'counts' ) as $field ) {
	lnkup_t_assert( array_key_exists( $field, $queue ), "queue has {$field}" );
}

$review_key = 'stuck:review_overdue:' . $event['event'];
$review     = lnkup_ops_find( $queue, $review_key );
lnkup_t_assert( null !== $review, 'overdue reviews at the event form one item' );
lnkup_t_same( 2, (int) ( $review['count'] ?? 0 ), 'both overdue applications counted once each' );
lnkup_t_same( 'applications', $review['source'] ?? '', 'review item source' );
lnkup_t_same( $organizer, (int) ( $review['owner']['id'] ?? 0 ), 'organizer is the owner' );
lnkup_t_same( $old_a, (int) ( $review['refs']['application_id'] ?? 0 ), 'action points at the oldest application' );
lnkup_t_assert( (float) $review['age_hours'] >= 8 * 24 - 1, 'age is the oldest application age' );
lnkup_t_assert( false !== strpos( (string) ( $review['action']['url'] ?? '' ), 'post=' . $old_a ), 'action opens the oldest application' );
lnkup_t_assert( null === lnkup_ops_find( $queue, 'stuck:review_overdue:' . $other['event'] ), 'fresh and test-account applications are not flagged' );

$unpaid_item = lnkup_ops_find( $queue, 'stuck:unpaid_past_due:' . $other['event'] );
lnkup_t_assert( null !== $unpaid_item, 'unpaid past due is flagged' );
lnkup_t_same( 'high', $unpaid_item['severity'] ?? '', 'unpaid past due is high severity' );

$trust = lnkup_ops_find( $queue, 'trust:cases' );
lnkup_t_assert( null !== $trust, 'trust cases appear as a count' );
lnkup_t_same( 'critical', $trust['severity'] ?? '', 'a critical case raises trust severity' );

$order = array_map( static fn( $i ) => LNKup_Ops_Overview::SEVERITY_ORDER[ $i['severity'] ], $queue['items'] );
$sorted = $order;
sort( $sorted );
lnkup_t_same( $sorted, $order, 'items are sorted by severity' );

$json = (string) wp_json_encode( $queue );
lnkup_t_assert( false === strpos( $json, $doc_title ), 'no private document names in the queue' );
lnkup_t_assert( false === strpos( $json, $narrative ), 'no trust case narratives in the queue' );
lnkup_t_assert( false === strpos( $json, 'fingerprint' ), 'fingerprints stay server-side' );

list( $status ) = lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => $review_key, 'state' => 'archived' ) );
lnkup_t_same( 400, $status, 'unknown state rejected' );
list( $status ) = lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => 'stuck:review_overdue:999999999', 'state' => 'dismissed' ) );
lnkup_t_same( 404, $status, 'dismissing an item that is not in the queue fails' );

list( $status ) = lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => $review_key, 'state' => 'dismissed', 'note' => 'Organizer is on it' ) );
lnkup_t_same( 200, $status, 'admin dismisses the review item' );
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_assert( null === lnkup_ops_find( $queue, $review_key ), 'dismissed item is hidden' );
lnkup_t_assert( $queue['hidden'] >= 1, 'hidden count includes the dismissed item' );
list( , $with_hidden ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue', array( 'include_hidden' => 1 ) );
lnkup_t_same( 'dismissed', lnkup_ops_find( $with_hidden, $review_key )['state'] ?? '', 'include_hidden shows it as dismissed' );

$audit = LNKup_Audit_Log::list_entries( array( 'entity_type' => 'ops_item', 'action' => 'ops_item_dismissed', 'actor_user_id' => $admin, 'limit' => 5 ) );
lnkup_t_assert( count( $audit ) >= 1, 'dismiss is audited' );
lnkup_t_assert( false !== strpos( (string) ( $audit[0]['new_state'] ?? '' ), $review_key ), 'audit row names the item key' );
lnkup_t_same( 'Organizer is on it', $audit[0]['reason'] ?? '', 'audit row keeps the note' );

$old_c   = lnkup_ct_application( $vendor2, $organizer, $event );
$posts[] = $old_c;
lnkup_ops_age( $old_c, 7 );
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
$back = lnkup_ops_find( $queue, $review_key );
lnkup_t_assert( null !== $back, 'dismissed item returns when it gets worse' );
lnkup_t_same( 3, (int) ( $back['count'] ?? 0 ), 'returned item shows the new count' );

$unpaid_key = 'stuck:unpaid_past_due:' . $other['event'];
list( $status ) = lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => $unpaid_key, 'state' => 'snoozed', 'hours' => 4 ) );
lnkup_t_same( 200, $status, 'admin snoozes the unpaid item' );
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_assert( null === lnkup_ops_find( $queue, $unpaid_key ), 'snoozed item is hidden' );
$until = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT until_at FROM ' . LNKup_Ops_Overview::table() . ' WHERE item_key = %s', $unpaid_key ) ); // phpcs:ignore WordPress.DB
$hours = ( strtotime( $until . ' UTC' ) - time() ) / HOUR_IN_SECONDS;
lnkup_t_assert( $hours > 3.9 && $hours <= 4.01, 'snooze lasts the requested hours' );
$wpdb->update( LNKup_Ops_Overview::table(), array( 'until_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'item_key' => $unpaid_key ) ); // phpcs:ignore WordPress.DB
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_assert( null !== lnkup_ops_find( $queue, $unpaid_key ), 'expired snooze returns the item' );

lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => 'trust:cases', 'state' => 'snoozed', 'hours' => 24 ) );
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_assert( null === lnkup_ops_find( $queue, 'trust:cases' ), 'trust item snoozed' );
list( $status ) = lnkup_ops_rest( $admin, 'POST', '/lnkup/v1/admin/ops/queue/state', array( 'key' => 'trust:cases', 'state' => 'clear' ) );
lnkup_t_same( 200, $status, 'admin restores the trust item' );
list( , $queue ) = lnkup_ops_rest( $admin, 'GET', '/lnkup/v1/admin/ops/queue' );
lnkup_t_assert( null !== lnkup_ops_find( $queue, 'trust:cases' ), 'cleared item is back' );
lnkup_t_assert( count( LNKup_Audit_Log::list_entries( array( 'entity_type' => 'ops_item', 'action' => 'ops_item_clear', 'actor_user_id' => $admin ) ) ) >= 1, 'restore is audited' );

$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . LNKup_Ops_Overview::table() . ' WHERE item_key IN (%s, %s, %s)', $review_key, $unpaid_key, 'trust:cases' ) ); // phpcs:ignore WordPress.DB
$wpdb->delete( LNKup_Trust_Tables::cases_table(), array( 'id' => $case_id ) ); // phpcs:ignore WordPress.DB
foreach ( $posts as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
lnkup_ct_finish();
lnkup_t_finish();
