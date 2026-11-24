<?php
/**
 * Unit tests for event media resolution (pure logic, minimal WordPress stubs).
 *
 * Run: php tests/php/event-media-test.php
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

function __( $s ) {
	return $s;
}
function apply_filters( $tag, $value ) {
	return $value;
}
function wp_json_encode( $v, $flags = 0 ) {
	return json_encode( $v, $flags );
}
function get_term( $id, $tax ) {
	return null;
}
function is_wp_error( $v ) {
	return false;
}

$plugin = getenv( 'LNKUP_PLUGIN_DIR' ) ?: dirname( __DIR__, 2 ) . '/wp-content/plugins/lnkup-marketplace';
require_once $plugin . '/includes/media/class-event-media.php';
require_once $plugin . '/includes/media/class-event-media-library.php';
require_once $plugin . '/includes/media/class-event-cover-svg.php';

$failures = 0;
$checks   = 0;

/**
 * Record one expectation.
 *
 * @param bool   $condition Expected truthy.
 * @param string $label     Description.
 */
function lnkup_media_check( $condition, $label ) {
	global $failures, $checks;
	++$checks;
	echo ( $condition ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $condition ) {
		++$failures;
	}
}

$t = static function ( string $name, int $parent = 0 ): array {
	return array( 'slug' => strtolower( str_replace( ' ', '-', $name ) ), 'name' => $name, 'parent' => $parent );
};

echo "Priority order\n";
$auto = LNKup_Event_Media::order_for_mode( 'auto' );
lnkup_media_check( 'override' === $auto[0], 'admin override always first' );
lnkup_media_check( array_search( 'event_cover', $auto, true ) < array_search( 'event_thumbnail', $auto, true ), 'LNKup cover before wp-admin featured image' );
lnkup_media_check( array_search( 'event_thumbnail', $auto, true ) < array_search( 'tour_cover', $auto, true ), 'uploads before tour artwork' );
lnkup_media_check( array_search( 'tour_cover', $auto, true ) < array_search( 'organizer_logo', $auto, true ), 'tour artwork before organizer logo' );
lnkup_media_check( array_search( 'organizer_logo', $auto, true ) < array_search( 'category_art', $auto, true ), 'organizer branding before LNKup art' );
lnkup_media_check( 'generated' === end( $auto ), 'generated cover is the last resort' );
$cat = LNKup_Event_Media::order_for_mode( 'category' );
lnkup_media_check( 'override' === $cat[0] && 'category_art' === $cat[1], 'category mode prefers LNKup art after override' );
lnkup_media_check( in_array( 'event_cover', $cat, true ), 'category mode still falls back through the full chain' );
$tour = LNKup_Event_Media::order_for_mode( 'tour' );
lnkup_media_check( 'tour_cover' === $tour[1] && 'tour_brand' === $tour[2], 'tour mode prefers tour artwork' );
lnkup_media_check( count( $auto ) === count( array_unique( $auto ) ), 'no duplicate candidates' );
lnkup_media_check( LNKup_Event_Media::order_for_mode( 'bogus' ) === $auto, 'unknown mode behaves like auto' );

echo "Media shape\n";
$shaped = LNKup_Event_Media::shape( array( 'source' => 'event_thumbnail', 'url' => 'x' ) );
lnkup_media_check( true === $shaped['uploaded'], 'organizer uploads flagged uploaded' );
lnkup_media_check( false === LNKup_Event_Media::shape( array( 'source' => 'category_art' ) )['uploaded'], 'LNKup art not flagged uploaded' );
lnkup_media_check( isset( $shaped['fit'], $shaped['background'], $shaped['alt'], $shaped['version'] ), 'shape fills every key' );
$rest = LNKup_Event_Media::to_rest( $shaped );
lnkup_media_check( ! isset( $rest['attachment_id'] ) && ! isset( $rest['og_url'] ) && isset( $rest['thumbnail_url'] ), 'REST shape omits internal fields' );

echo "Event-type artwork mapping\n";
lnkup_media_check( 'rodeo' === LNKup_Event_Media_Library::group_for( array( $t( 'Rodeo' ) ) ), 'Rodeo term maps to rodeo art' );
lnkup_media_check( 'gala' === LNKup_Event_Media_Library::group_for( array( $t( 'Fundraiser' ) ) ), 'Fundraiser maps to gala art' );
lnkup_media_check( 'music' === LNKup_Event_Media_Library::group_for( array( $t( 'Music Event' ) ), 'Food Truck Rally' ), 'event type wins over title words' );
lnkup_media_check( 'gala' === LNKup_Event_Media_Library::group_for( array(), 'She Rose Anyway Gala' ), 'title hint used only without event type' );
lnkup_media_check( 'rodeo' === LNKup_Event_Media_Library::group_for( array(), 'Dirty South Rodeo Festival: Charlotte NC' ), 'rodeo title hint beats generic "festival"' );
lnkup_media_check( '' === LNKup_Event_Media_Library::group_for( array( $t( 'Other' ) ), 'Quarterly Meetup' ), 'no relevant art when nothing matches (generated cover instead)' );
lnkup_media_check( 'market' === LNKup_Event_Media_Library::group_for( array( $t( 'Pop-Up' ) ) ), 'Pop-Up maps to market art' );
$v = LNKup_Event_Media_Library::variation( 1234 );
lnkup_media_check( $v === LNKup_Event_Media_Library::variation( 1234 ) && $v >= 1 && $v <= LNKup_Event_Media_Library::VARIATIONS, 'variation is stable and in range' );
$seen = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$seen[ LNKup_Event_Media_Library::variation( $i ) ] = true;
}
lnkup_media_check( count( $seen ) === LNKup_Event_Media_Library::VARIATIONS, 'variations are spread across events' );
foreach ( array_keys( LNKup_Event_Media_Library::GROUPS ) as $group ) {
	for ( $n = 1; $n <= LNKup_Event_Media_Library::VARIATIONS; $n++ ) {
		$ok = file_exists( $plugin . '/public/images/event-types/' . $group . '-' . $n . '.webp' ) && file_exists( $plugin . '/public/images/event-types/' . $group . '-' . $n . '.jpg' );
		if ( ! $ok ) {
			lnkup_media_check( false, "artwork file {$group}-{$n} exists (webp + jpg)" );
		}
	}
}
lnkup_media_check( true, 'all ' . ( count( LNKup_Event_Media_Library::GROUPS ) * LNKup_Event_Media_Library::VARIATIONS ) . ' artwork files checked' );
lnkup_media_check( ! in_array( '', array_values( LNKup_Event_Media_Library::TERM_GROUPS ), true ) && ! array_diff( array_values( LNKup_Event_Media_Library::TERM_GROUPS ), array_keys( LNKup_Event_Media_Library::GROUPS ) ), 'every term maps to an existing group' );

echo "Generated SVG cover\n";
$lines = LNKup_Event_Cover_SVG::wrap( 'Dirty South Rodeo Festival: Charlotte NC', 20, 3 );
lnkup_media_check( count( $lines ) <= 3 && max( array_map( 'mb_strlen', $lines ) ) <= 20, 'title wrapped within limits' );
$long = LNKup_Event_Cover_SVG::wrap( str_repeat( 'Extraordinary Celebration ', 12 ), 26, 3 );
lnkup_media_check( 3 === count( $long ) && '…' === mb_substr( end( $long ), -1 ), 'very long title clamped to 3 lines with ellipsis' );
lnkup_media_check( array( 'Gala' ) === LNKup_Event_Cover_SVG::wrap( 'Gala', 20, 3 ), 'short title stays on one line' );
$svg = LNKup_Event_Cover_SVG::svg(
	array(
		'title'     => 'Rock & Roll <script>alert(1)</script> "Night"',
		'organizer' => 'O\'Neil & Co',
		'place'     => 'Charlotte, NC',
		'label'     => 'Music',
		'bg'        => '#160f24',
		'accent'    => 'red;}</style><script>',
		'template'  => 2,
	)
);
lnkup_media_check( false === strpos( $svg, '<script' ) && false !== strpos( $svg, '&lt;script&gt;' ), 'title text escaped' );
lnkup_media_check( false !== strpos( $svg, 'O&apos;Neil &amp; Co' ), 'organizer escaped' );
lnkup_media_check( false === strpos( $svg, 'red;}' ) && false !== strpos( $svg, '#c6f24e' ), 'invalid colors replaced with safe defaults' );
lnkup_media_check( false === stripos( $svg, 'href=' ) && false === stripos( $svg, 'http://example' ), 'no external references in SVG' );
$doc = new DOMDocument();
lnkup_media_check( @$doc->loadXML( $svg ), 'SVG is well-formed XML' );
foreach ( array( 1, 2, 3 ) as $tpl ) {
	$x = LNKup_Event_Cover_SVG::svg( array( 'title' => 'T', 'organizer' => '', 'place' => '', 'label' => 'Festival', 'bg' => '#111111', 'accent' => '#c6f24e', 'template' => $tpl ) );
	lnkup_media_check( @( new DOMDocument() )->loadXML( $x ), "template {$tpl} well-formed" );
}

printf( "\n%d checks, %d failures\n", $checks, $failures );
exit( $failures > 0 ? 1 : 0 );
