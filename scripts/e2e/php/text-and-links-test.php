<?php
/**
 * Plain titles ("R&B", not "R&amp;B") and signed receipt / invoice links that open without a login.
 *
 * Run: npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/text-and-links-test.php
 *
 * @package LNKup_Marketplace
 */

require __DIR__ . '/_helpers.php';

global $wpdb;
echo "Plain titles and signed document links\n";

$org    = lnkup_t_user( 'lnkup_organizer' );
$vendor = lnkup_t_user( 'lnkup_vendor' );
$other  = lnkup_t_user( 'lnkup_vendor' );
$posts  = array();

// --------------------------------------------------------------- Stored titles.
echo "\n[stored titles]\n";
wp_set_current_user( $org );
lnkup_t_assert( ! current_user_can( 'unfiltered_html' ), 'organizers save through kses (no unfiltered_html)' );

remove_filter( 'wp_insert_post_data', array( 'LNKup_Plain_Titles', 'filter_post_data' ), 20 );
$raw_id  = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'R&B Raw', 'post_author' => $org ) );
$posts[] = $raw_id;
lnkup_t_same( 'R&amp;B Raw', get_post_field( 'post_title', $raw_id, 'raw' ), 'without the fix, core stores "&" as "&amp;"' );
add_filter( 'wp_insert_post_data', array( 'LNKup_Plain_Titles', 'filter_post_data' ), 20 );

$event_id = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => 'R&B Fest', 'post_author' => $org ) );
$posts[]  = $event_id;
lnkup_t_same( 'R&B Fest', get_post_field( 'post_title', $event_id, 'raw' ), 'organizer-created event title is stored as typed' );
wp_update_post( array( 'ID' => $event_id, 'post_title' => 'R&B Fest & Market' ) );
lnkup_t_same( 'R&B Fest & Market', get_post_field( 'post_title', $event_id, 'raw' ), 'renaming keeps the ampersands' );
wp_update_post( array( 'ID' => $event_id, 'post_title' => 'R&B Fest' ) );

$script_id = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'draft', 'post_title' => 'Fest <script>alert(1)</script>', 'post_author' => $org ) );
$posts[]   = $script_id;
lnkup_t_assert( false === strpos( get_post_field( 'post_title', $script_id, 'raw' ), '<script' ), 'tags are still stripped from titles' );

// --------------------------------------------------------------- JSON helpers.
echo "\n[plain text for JSON]\n";
lnkup_t_same( 'R&B Raw', lnkup_plain_title( $raw_id ), 'lnkup_plain_title decodes a stored &amp;' );
lnkup_t_same( 'Joe’s R&B', lnkup_plain_text( 'Joe&#8217;s R&#038;B' ), 'texturized entities are decoded' );
lnkup_t_same( '&lt;b&gt;Bold&lt;/b&gt;', lnkup_plain_text( '&lt;b&gt;Bold&lt;/b&gt;' ), 'angle brackets stay encoded' );
lnkup_t_same( '', lnkup_plain_title( 0 ), 'a missing post gives an empty title, not the current page' );

// --------------------------------------------------------------- Migration.
echo "\n[migration]\n";
$wpdb->update( $wpdb->posts, array( 'post_title' => 'Old R&amp;B &#038; Soul' ), array( 'ID' => $raw_id ) );
clean_post_cache( $raw_id );
$updated = LNKup_Plain_Titles::migrate( true );
lnkup_t_assert( $updated >= 1, 'migration updates encoded titles' );
lnkup_t_same( 'Old R&B & Soul', get_post_field( 'post_title', $raw_id, 'raw' ), 'existing encoded title is decoded' );
lnkup_t_assert( (bool) get_option( LNKup_Plain_Titles::MIGRATION_OPTION ), 'migration is recorded as done' );

// --------------------------------------------------------------- Receipt payload.
echo "\n[receipt]\n";
$table = LNKup_Financial_Tables::receipts_table();
$wpdb->insert(
	$table,
	array(
		'receipt_number' => 'RT-' . wp_generate_password( 8, false ),
		'vendor_user_id' => $vendor,
		'amount'         => 25,
		'payment_method' => 'card',
		'status'         => 'issued',
		'metadata'       => wp_json_encode( array( 'event_id' => $event_id ) ),
		'created_at'     => current_time( 'mysql' ),
	)
);
$receipt_id = (int) $wpdb->insert_id;
$row        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $receipt_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$formatted  = LNKup_Receipts::format( $row );
lnkup_t_same( 'R&B Fest', $formatted['event_title'], 'receipt event title is plain' );
lnkup_t_assert( false !== strpos( $formatted['view_url'], 'lnkup_token=' ), 'receipt view URL is signed' );

$view = static function ( array $query ) use ( $receipt_id ) {
	wp_set_current_user( 0 );
	$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/receipts/' . $receipt_id . '/view' );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$status = rest_do_request( $req )->get_status();
	wp_set_current_user( 0 );
	return $status;
};
wp_parse_str( (string) wp_parse_url( $formatted['view_url'], PHP_URL_QUERY ), $signed );

echo "\n[signed receipt link]\n";
lnkup_t_same( 401, $view( array() ), 'no login and no signature is refused' );
lnkup_t_same( 200, $view( $signed ), 'a valid signed link opens without a login' );
lnkup_t_same( 401, $view( array_merge( $signed, array( 'lnkup_token' => str_repeat( 'a', 64 ) ) ) ), 'a tampered token is refused' );
lnkup_t_same( 401, $view( array_merge( $signed, array( 'lnkup_expires' => (string) ( time() + 7200 ) ) ) ), 'changing the expiry breaks the signature' );
lnkup_t_same( 401, $view( array_merge( $signed, array( 'lnkup_expires' => (string) ( time() - 10 ) ) ) ), 'an expired link is refused' );
wp_parse_str( (string) wp_parse_url( LNKup_Signed_Links::sign_url( 'https://x.test/', 'receipt', $receipt_id, $other ), PHP_URL_QUERY ), $other_signed );
lnkup_t_same( 404, $view( $other_signed ), "another vendor's signed link cannot open this receipt" );
wp_parse_str( (string) wp_parse_url( LNKup_Signed_Links::sign_url( 'https://x.test/', 'invoice', $receipt_id, $vendor ), PHP_URL_QUERY ), $invoice_signed );
lnkup_t_same( 401, $view( $invoice_signed ), 'an invoice signature does not open a receipt' );

wp_set_current_user( $vendor );
$req = new WP_REST_Request( 'GET', '/lnkup/v1/financial/receipts/' . $receipt_id . '/view' );
lnkup_t_same( 200, rest_do_request( $req )->get_status(), 'the logged-in owner still opens it' );

// --------------------------------------------------------------- Cleanup.
wp_set_current_user( 0 );
$wpdb->delete( $table, array( 'id' => $receipt_id ) );
foreach ( $posts as $pid ) {
	wp_delete_post( $pid, true );
}
lnkup_t_finish();
