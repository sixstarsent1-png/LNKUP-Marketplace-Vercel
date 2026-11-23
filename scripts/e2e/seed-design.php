<?php
/**
 * Design review fixtures on top of seed.php: a Six Stars house organizer and an independent
 * organizer, events with a portrait flyer, a landscape photo, no art, a very long title, a sold-out
 * booth, a free booth, an unknown fee, and one unclaimed external listing. Flyers are synthetic
 * (drawn with GD) and live only in the local uploads folder.
 *
 * Run: npx -y @wordpress/env@10 run cli wp eval-file /var/www/html/wp-content/lnkup-e2e/seed-design.php
 *
 * @package LNKup_Marketplace
 */

require_once __DIR__ . '/seed.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/**
 * Attach a generated image to a post as its featured image.
 *
 * @param int    $post_id Post.
 * @param string $key     Fixture key (file name).
 * @param int    $w       Width.
 * @param int    $h       Height.
 * @param array  $lines   Text lines drawn on the image.
 * @param array  $bg      RGB background.
 * @param string $source  Optional source file to copy instead of drawing.
 */
function lnkup_design_image( $post_id, $key, $w, $h, $lines, $bg, $source = '' ) {
	$existing = (int) get_post_meta( $post_id, '_lnkup_design_image_id', true );
	if ( $existing && get_post( $existing ) ) {
		set_post_thumbnail( $post_id, $existing );
		return $existing;
	}
	$upload = wp_upload_dir();
	$file   = trailingslashit( $upload['path'] ) . 'design-' . $key . '.jpg';
	if ( $source && file_exists( $source ) ) {
		copy( $source, $file );
	} else {
		$img   = imagecreatetruecolor( $w, $h );
		$fill  = imagecolorallocate( $img, $bg[0], $bg[1], $bg[2] );
		$lime  = imagecolorallocate( $img, 200, 255, 0 );
		$white = imagecolorallocate( $img, 255, 255, 255 );
		imagefill( $img, 0, 0, $fill );
		imagefilledrectangle( $img, 0, (int) ( $h * 0.78 ), $w, $h, $lime );
		$y = (int) ( $h * 0.12 );
		foreach ( $lines as $i => $line ) {
			$scale = 0 === $i ? 5 : 3;
			$tmp   = imagecreatetruecolor( imagefontwidth( 5 ) * strlen( $line ), imagefontheight( 5 ) );
			imagefill( $tmp, 0, 0, $fill );
			imagestring( $tmp, 5, 0, 0, $line, $white );
			$tw = imagesx( $tmp ) * $scale;
			$th = imagesy( $tmp ) * $scale;
			imagecopyresized( $img, $tmp, (int) max( 20, ( $w - $tw ) / 2 ), $y, 0, 0, (int) min( $tw, $w - 40 ), $th, imagesx( $tmp ), imagesy( $tmp ) );
			imagedestroy( $tmp );
			$y += $th + 30;
		}
		imagejpeg( $img, $file, 85 );
		imagedestroy( $img );
	}
	$att = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'Design fixture ' . $key, 'post_status' => 'inherit' ), $file, $post_id );
	wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $file ) );
	set_post_thumbnail( $post_id, $att );
	update_post_meta( $post_id, '_lnkup_design_image_id', $att );
	return $att;
}

$six_stars = lnkup_e2e_user( 'design_sixstars', 'lnkup_organizer', 'Six Stars Entertainment' );
update_user_meta( $six_stars, '_lnkup_organizer_public_name', 'Six Stars Entertainment' );
$indie = lnkup_e2e_user( 'design_indie', 'lnkup_organizer', 'Southside Makers Collective' );
update_user_meta( $indie, '_lnkup_organizer_public_name', 'Southside Makers Collective' );

$settings = lnkup_get_settings();
$house    = array_filter( array_map( 'absint', explode( ',', (string) ( $settings['org_growth_house_organizer_ids'] ?? '' ) ) ) );
$house[]  = $six_stars;
$settings['org_growth_house_organizer_ids'] = implode( ',', array_unique( $house ) );
update_option( 'lnkup_settings', $settings );

$theme_img = get_theme_file_path( 'assets/images/marketing/hero-market.jpg' );

$fixtures = array(
	array(
		'key'    => 'design-greek-fest',
		'org'    => $six_stars,
		'title'  => 'Atlanta Greek Picnic Weekend Festival and Vendor Village Featuring Live DJs, Food Row and Pop-Up Shops',
		'city'   => array( 'Atlanta', 'GA' ),
		'days'   => 24,
		'slots'  => array(
			'food'   => array( 'title' => 'Food Row Booth', 'spots' => 12, 'fee' => 350, 'cats' => array( 'Food Vendor' ) ),
			'retail' => array( 'title' => 'Pop-Up Shop 10x10', 'spots' => 30, 'fee' => 150, 'cats' => array( 'Retail', 'Clothing' ) ),
			'beauty' => array( 'title' => 'Beauty Lane', 'spots' => 8, 'fee' => 175, 'cats' => array( 'Beauty' ) ),
		),
		'image'  => array( 1080, 1350, array( 'GREEK PICNIC', 'ATLANTA  JUNE 2027', 'VENDOR VILLAGE' ), array( 32, 16, 64 ), '' ),
	),
	array(
		'key'    => 'design-night-market',
		'org'    => $indie,
		'title'  => 'Southside Night Market',
		'city'   => array( 'Charlotte', 'NC' ),
		'days'   => 12,
		'slots'  => array(
			'makers' => array( 'title' => 'Maker Table', 'spots' => 20, 'fee' => 0, 'cats' => array( 'Art', 'Jewelry' ) ),
			'food'   => array( 'title' => 'Food Truck Spot', 'spots' => 4, 'fee' => 95, 'cats' => array( 'Food Truck' ) ),
		),
		'image'  => array( 0, 0, array(), array( 0, 0, 0 ), $theme_img ),
	),
	array(
		'key'    => 'design-no-art',
		'org'    => $indie,
		'title'  => 'Raleigh Sunday Flea',
		'city'   => array( 'Raleigh', 'NC' ),
		'days'   => 30,
		'slots'  => array(
			'flea' => array( 'title' => 'Flea Table', 'spots' => 40, 'fee' => '', 'cats' => array( 'Retail' ) ),
		),
		'image'  => null,
	),
	array(
		'key'    => 'design-sold-out',
		'org'    => $six_stars,
		'title'  => 'Juneteenth Block Party',
		'city'   => array( 'Durham', 'NC' ),
		'days'   => 18,
		'slots'  => array(
			'food' => array( 'title' => 'Food Vendor', 'spots' => 6, 'fee' => 200, 'cats' => array( 'Food Vendor' ), 'remaining' => 0 ),
		),
		'image'  => array( 1080, 1350, array( 'JUNETEENTH', 'BLOCK PARTY', 'DURHAM' ), array( 120, 20, 20 ), '' ),
	),
);

foreach ( $fixtures as $fx ) {
	list( $event_id, $opps ) = lnkup_e2e_event( $fx['key'], $fx['org'], $fx['title'], array_map( static fn( $s ) => array( 'title' => $s['title'], 'spots' => $s['spots'], 'fee' => $s['fee'] ), $fx['slots'] ), $fx['days'] );
	update_post_meta( $event_id, '_lnkup_city', $fx['city'][0] );
	update_post_meta( $event_id, '_lnkup_state', $fx['city'][1] );
	update_post_meta( $event_id, '_lnkup_venue_name', $fx['city'][0] . ' Fairgrounds' );
	if ( $fx['image'] ) {
		list( $w, $h, $lines, $bg, $src ) = $fx['image'];
		$att = lnkup_design_image( $event_id, $fx['key'], $w, $h, $lines, $bg, $src );
	} else {
		delete_post_thumbnail( $event_id );
		$att = 0;
	}
	foreach ( $opps as $slot_key => $opp_id ) {
		$slot = $fx['slots'][ $slot_key ];
		wp_set_object_terms( $opp_id, $slot['cats'], 'lnkup_vendor_category' );
		if ( '' === $slot['fee'] ) {
			delete_post_meta( $opp_id, '_lnkup_vendor_fee' );
		}
		if ( isset( $slot['remaining'] ) ) {
			update_post_meta( $opp_id, '_lnkup_spots_remaining', $slot['remaining'] );
		}
		if ( $att ) {
			set_post_thumbnail( $opp_id, $att );
		} else {
			delete_post_thumbnail( $opp_id );
		}
		LNKup_Marketplace_Index::sync_opportunity( $opp_id );
	}
	WP_CLI::log( sprintf( '%s: event %d, %d slots', $fx['key'], $event_id, count( $opps ) ) );
}

list( $ext_event, $ext_opps ) = lnkup_e2e_event( 'design-external', 0, 'Carolina Classic Car Show', array( 'show' => array( 'title' => 'Vendor booth', 'spots' => 10, 'fee' => 125 ) ), 40 );
update_post_meta( $ext_event, '_lnkup_external_event_id', 999001 );
update_post_meta( $ext_event, '_lnkup_organizer_user_id', 0 );
update_post_meta( $ext_event, '_lnkup_city', 'Greensboro' );
foreach ( $ext_opps as $opp_id ) {
	update_post_meta( $opp_id, '_lnkup_organizer_user_id', 0 );
	LNKup_Marketplace_Index::sync_opportunity( $opp_id );
}
WP_CLI::log( 'design-external: event ' . $ext_event );
WP_CLI::success( 'Design fixtures ready.' );
