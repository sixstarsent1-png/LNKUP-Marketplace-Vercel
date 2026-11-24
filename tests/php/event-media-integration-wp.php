<?php
/**
 * Event media integration checks in a real WordPress install (disposable test site only).
 *
 * Run: wp eval-file tests/php/event-media-integration-wp.php
 *
 * @package LNKup_Marketplace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $failures, $checks;
$failures = 0;
$checks   = 0;

/**
 * @param bool   $ok    Condition.
 * @param string $label Label.
 */
function lnkup_em( $ok, $label ) {
	global $failures, $checks;
	++$checks;
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		++$failures;
	}
}

/**
 * Create a real JPEG attachment of the given size.
 */
function lnkup_em_image( int $w, int $h, string $name ): int {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$up   = wp_upload_dir();
	$path = trailingslashit( $up['path'] ) . $name . '-' . wp_generate_password( 6, false ) . '.jpg';
	$img  = imagecreatetruecolor( $w, $h );
	imagefill( $img, 0, 0, imagecolorallocate( $img, 40, 120, 200 ) );
	imagejpeg( $img, $path, 80 );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	return (int) $id;
}

function lnkup_em_event( string $title, array $meta = array(), string $type = '' ): int {
	$id = wp_insert_post( array( 'post_type' => 'lnkup_event', 'post_status' => 'publish', 'post_title' => $title ) );
	foreach ( array_merge( array( '_lnkup_city' => 'Charlotte', '_lnkup_state' => 'NC' ), $meta ) as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	if ( $type ) {
		wp_set_object_terms( $id, $type, 'lnkup_event_type' );
	}
	return (int) $id;
}

$organizer = wp_insert_user( array( 'user_login' => 'org_' . wp_generate_password( 5, false ), 'user_pass' => 'x' . wp_generate_password( 12 ), 'user_email' => 'org' . wp_generate_password( 5, false ) . '@example.com', 'role' => 'lnkup_organizer' ) );
update_user_meta( $organizer, '_lnkup_organizer_public_name', 'Six Stars Entertainment' );

echo "Root cause: wp-admin featured image\n";
$landscape = lnkup_em_image( 1600, 900, 'landscape' );
$e_thumb   = lnkup_em_event( 'She Rose Anyway Gala', array( '_thumbnail_id' => $landscape, '_lnkup_organizer_user_id' => $organizer ), 'Fundraiser' );
$m         = LNKup_Event_Media::resolve( $e_thumb );
lnkup_em( 'event_thumbnail' === $m['source'] && $landscape === $m['attachment_id'], 'event with only a wp-admin featured image now resolves to it' );
lnkup_em( 'photo' === $m['presentation'] && 'cover' === $m['fit'], 'landscape image is a cover photo' );

echo "Gallery and flyer\n";
$g1      = lnkup_em_image( 1200, 800, 'gallery' );
$e_gal   = lnkup_em_event( 'Gallery Only Market', array( '_lnkup_gallery_ids' => wp_json_encode( array( $g1 ) ) ), 'Market' );
lnkup_em( 'gallery' === LNKup_Event_Media::resolve( $e_gal )['source'], 'gallery-only event uses its first gallery image' );
$portrait = lnkup_em_image( 1080, 1350, 'flyer' );
$e_flyer  = lnkup_em_event( 'Portrait Flyer Fest', array( '_lnkup_featured_image_id' => $portrait ), 'Festival' );
$fm       = LNKup_Event_Media::resolve( $e_flyer );
lnkup_em( 'flyer' === $fm['presentation'] && 'contain' === $fm['fit'], 'portrait upload shown as a contained flyer' );

echo "Tour inheritance\n";
$tour_cover = lnkup_em_image( 1600, 1000, 'tour' );
$tour       = wp_insert_post( array( 'post_type' => 'lnkup_tour', 'post_status' => 'publish', 'post_title' => 'Dirty South Rodeo Festival Tour' ) );
set_post_thumbnail( $tour, $tour_cover );
$stop_a = lnkup_em_event( 'Dirty South Rodeo Festival: Charlotte NC', array( '_lnkup_tour_id' => $tour, '_lnkup_organizer_user_id' => $organizer ), 'Rodeo' );
$city   = lnkup_em_image( 1600, 900, 'city' );
$stop_b = lnkup_em_event( 'Dirty South Rodeo Festival: VA', array( '_lnkup_tour_id' => $tour, '_lnkup_featured_image_id' => $city, '_lnkup_organizer_user_id' => $organizer ), 'Rodeo' );
lnkup_em( 'tour_cover' === LNKup_Event_Media::resolve( $stop_a )['source'], 'tour stop without its own image inherits tour artwork' );
lnkup_em( 'event_cover' === LNKup_Event_Media::resolve( $stop_b )['source'], 'city-specific image overrides tour artwork' );
$other = lnkup_em_event( 'Unrelated Same-Organizer Event', array( '_lnkup_organizer_user_id' => $organizer ), 'Conference' );
lnkup_em( 'tour_cover' !== LNKup_Event_Media::resolve( $other )['source'], 'unrelated event of the same organizer does not inherit tour art' );
$new_cover = lnkup_em_image( 1600, 1000, 'tour2' );
set_post_thumbnail( $tour, $new_cover );
lnkup_em( $new_cover === LNKup_Event_Media::resolve( $stop_a )['attachment_id'], 'changing the tour cover updates inheriting events' );
lnkup_em( $city === LNKup_Event_Media::resolve( $stop_b )['attachment_id'], 'city override untouched by tour cover change' );

echo "Organizer branding\n";
$logo = lnkup_em_image( 600, 600, 'logo' );
update_user_meta( $organizer, '_lnkup_organizer_logo_id', $logo );
$lm = LNKup_Event_Media::resolve( $other );
lnkup_em( 'organizer_logo' === $lm['source'] && 'logo' === $lm['presentation'] && 'contain' === $lm['fit'], 'logo-only event shows the logo contained, not stretched' );
lnkup_em( 'Six Stars Entertainment logo' === $lm['alt'], 'logo alt text names the organizer' );
$logo2 = lnkup_em_image( 600, 600, 'logo2' );
update_user_meta( $organizer, '_lnkup_organizer_logo_id', $logo2 );
lnkup_em( $logo2 === LNKup_Event_Media::resolve( $other )['attachment_id'], 'organizer logo change propagates to inheriting events' );
lnkup_em( $landscape === LNKup_Event_Media::resolve( $e_thumb )['attachment_id'], 'events with their own artwork keep it after a logo change' );

echo "No media at all\n";
$e_none = lnkup_em_event( 'Charlotte Night Market', array(), 'Vendor Market' );
$nm     = LNKup_Event_Media::resolve( $e_none );
lnkup_em( 'category_art' === $nm['source'] && false !== strpos( $nm['url'], '/event-types/market-' ), 'event with no media gets market event-type art' );
lnkup_em( '' !== $nm['og_url'] && false !== strpos( $nm['og_url'], '.jpg' ), 'og image for art is a JPG' );
$e_bare = lnkup_em_event( 'Quarterly Members Meetup', array(), 'Other' );
$bm     = LNKup_Event_Media::resolve( $e_bare );
lnkup_em( 'generated' === $bm['source'] && file_exists( str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $bm['url'] ) ), 'unmatched event gets a generated SVG cover file' );
lnkup_em( false === strpos( (string) file_get_contents( str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $bm['url'] ) ), '<script' ), 'generated cover contains no script' );

echo "Deleted / broken attachment\n";
$doomed = lnkup_em_image( 1600, 900, 'doomed' );
$e_del  = lnkup_em_event( 'Deleted Image Expo', array( '_lnkup_featured_image_id' => $doomed ), 'Expo' );
lnkup_em( 'event_cover' === LNKup_Event_Media::resolve( $e_del )['source'], 'valid before deletion' );
wp_delete_attachment( $doomed, true );
$dm = LNKup_Event_Media::resolve( $e_del );
lnkup_em( 'event_cover' !== $dm['source'] && 'category_art' === $dm['source'], 'deleted attachment falls through to the next valid source' );
lnkup_em( 'broken' === LNKup_Event_Media::health( $e_del, $dm ), 'health reports the broken upload' );
$missing = lnkup_em_image( 1600, 900, 'missingfile' );
wp_delete_file( get_attached_file( $missing ) );
LNKup_Event_Media::bump_generation();
$e_miss = lnkup_em_event( 'Missing File Fair', array( '_lnkup_featured_image_id' => $missing ), 'Festival' );
lnkup_em( 'category_art' === LNKup_Event_Media::resolve( $e_miss )['source'], 'attachment whose file is gone is rejected' );

echo "Admin override and modes\n";
$ov = lnkup_em_image( 1600, 900, 'override' );
lnkup_em( true === LNKup_Event_Media::set_display( $e_thumb, '', $ov, 'test' ), 'override accepted' );
lnkup_em( 'override' === LNKup_Event_Media::resolve( $e_thumb )['source'], 'override wins' );
lnkup_em( $landscape === (int) get_post_meta( $e_thumb, '_thumbnail_id', true ), 'original upload untouched by override' );
LNKup_Event_Media::set_display( $e_thumb, 'auto', 0, 'test' );
lnkup_em( 'event_thumbnail' === LNKup_Event_Media::resolve( $e_thumb )['source'], 'restore automatic returns to the organizer upload' );
lnkup_em( is_wp_error( LNKup_Event_Media::set_display( $e_thumb, 'bogus' ) ), 'invalid mode rejected' );
$svg_att = wp_insert_attachment( array( 'post_mime_type' => 'image/svg+xml', 'post_title' => 'svg', 'post_status' => 'inherit' ), wp_upload_dir()['path'] . '/x.svg' );
lnkup_em( is_wp_error( LNKup_Event_Media::set_display( $e_thumb, '', (int) $svg_att ) ), 'uploaded SVG rejected as override' );
LNKup_Event_Media::set_display( $e_none, 'generated', null, 'test' );
lnkup_em( 'generated' === LNKup_Event_Media::resolve( $e_none )['source'], 'generated mode honored' );

echo "REST / card output\n";
$opp = wp_insert_post( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'publish', 'post_title' => 'Food booth' ) );
update_post_meta( $opp, '_lnkup_event_id', $stop_a );
update_post_meta( $opp, '_lnkup_opportunity_status', 'open' );
update_post_meta( $opp, '_lnkup_spots_total', 10 );
update_post_meta( $opp, '_lnkup_spots_remaining', 10 );
update_post_meta( $opp, '_lnkup_vendor_fee', 150 );
LNKup_Marketplace_Index::sync_opportunity( $opp );
$row = LNKup_Marketplace_Index::get_row( $opp );
lnkup_em( (int) $row['featured_image_id'] === $new_cover, 'index stores the resolved attachment' );
$item = lnkup_format_index_row( $row );
lnkup_em( '' !== $item['featured_image'] && is_array( $item['media'] ) && 'tour_cover' === $item['media']['source'], 'REST row: featured_image never empty and media present' );
lnkup_em( ! isset( $item['media']['attachment_id'] ), 'REST media hides internal fields' );
$html = lnkup_render_homepage_event_card( array( $item ), 'home' );
lnkup_em( false === strpos( $html, 'lnkup-event-card__placeholder' ) && false !== strpos( $html, 'data-media-source="tour_cover"' ), 'homepage card renders real media, no placeholder' );
lnkup_em( false !== strpos( $html, 'View vendor opportunity' ) || false !== strpos( $html, 'Explore event' ), 'marketplace CTA copy' );
$empty_html = lnkup_event_card_img_html( '', 'Some Event' );
lnkup_em( false === strpos( $empty_html, 'LNKup</span>' ) && false !== strpos( $empty_html, '<img' ), 'legacy empty-image call renders a cover instead of the LNKup box' );

echo "Backfill dry run\n";
$before = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s", LNKup_Event_Media::META_RESOLVED ) );
LNKup_Event_Media::forget( array( $e_bare ) );
$before--;
$summary = LNKup_Event_Media_CLI::summarize( 'publish', false );
$after   = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = %s", LNKup_Event_Media::META_RESOLVED ) );
lnkup_em( $after === $before, 'audit (dry run) writes no cache meta' );
lnkup_em( $summary['total'] >= 10 && ! empty( $summary['by_source'] ), 'audit reports counts per source' );
$titles = wp_list_pluck( $summary['spotlight'], 'title' );
lnkup_em( in_array( 'She Rose Anyway Gala', $titles, true ) && in_array( 'Dirty South Rodeo Festival: VA', $titles, true ), 'gala and rodeo events reported individually' );
$counts = LNKup_Event_Media_CLI::backfill_batch( array( $e_bare ) );
lnkup_em( 1 === array_sum( $counts ) && '' !== (string) get_post_meta( $e_bare, LNKup_Event_Media::META_RESOLVED, true ), 'backfill batch writes the cache' );
lnkup_em( $landscape === (int) get_post_meta( $e_thumb, '_thumbnail_id', true ), 'backfill never touches uploaded images' );

echo "Permissions\n";
wp_set_current_user( $organizer );
lnkup_em( ! current_user_can( LNKup_Media_Health_Admin::CAP ), 'organizers cannot open Media Health' );
wp_set_current_user( 1 );
$_GET = array();
ob_start();
LNKup_Media_Health_Admin::render();
$admin_html = (string) ob_get_clean();
lnkup_em( false !== strpos( $admin_html, 'Media Health' ) && false !== strpos( $admin_html, 'She Rose Anyway Gala' ), 'Media Health renders event list for admins' );

printf( "\n%d checks, %d failures\n", $checks, $failures );
