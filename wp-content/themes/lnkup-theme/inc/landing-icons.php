<?php
/**
 * Landing page icon helpers.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return inline SVG markup for a named landing icon.
 *
 * @param string $name Icon key.
 */
function lnkup_theme_landing_icon( $name ) {
	$icons = array(
		'check'   => '<path d="M9 12 11 14 15 10"/><circle cx="12" cy="12" r="9"/>',
		'star'    => '<path d="m12 2 3 7h7l-5.5 4 2 7-6.5-4.5L5.5 20l2-7L2 9h7z"/>',
		'pin'     => '<path d="M12 21s7-4.5 7-11a7 7 0 1 0-14 0c0 6.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
		'tent'    => '<path d="M4 20 12 6l8 14"/><path d="M8 20h8"/><path d="M12 6v14"/>',
		'music'   => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
		'ticket'  => '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v14"/>',
		'utensils' => '<path d="M3 2v7c0 1.1.9 2 2 2h0a2 2 0 0 0 2-2V2"/><path d="M7 2v20"/><path d="M21 15V2a5 5 0 0 0-5 5v8a5 5 0 0 0 5 5Z"/>',
		'truck'   => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h1"/><path d="M15 18h2"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
		'cake'    => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-4 8-4 8 4 8 4"/><path d="M12 4v4"/><path d="M8 4v2"/><path d="M16 4v2"/>',
		'cup'     => '<path d="M17 8h1a4 4 0 0 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/>',
		'bag'     => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
		'shirt'   => '<path d="M20.38 3.46 16 2 12 5.5 8 2 3.62 3.46a2 2 0 0 0-1.34 2.23l.58 3.47a1 1 0 0 0 .99.84H6v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V10h2.15a1 1 0 0 0 .99-.84l.58-3.47a2 2 0 0 0-1.34-2.23Z"/>',
		'sparkle' => '<path d="m12 3-1.9 5.8H4l4.8 3.5-1.9 5.8L12 14.6l5.1 3.5-1.9-5.8L20 8.8h-6.1Z"/>',
		'gem'     => '<path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/>',
		'palette' => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2a10 10 0 1 0 0 20 2 2 0 0 0 2-2 1 1 0 0 1 1-1h1.5a2.5 2.5 0 0 0 0-5H17a2 2 0 0 1-2-2 2 2 0 0 0-2-2Z"/>',
		'camera'  => '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3"/>',
		'wrench'  => '<path d="M14.7 6.3a1 1 0 0 0 0 .6l1.2 2.1a1 1 0 0 0 1.2.4l2.1-.6a1 1 0 0 1 1.3 1.3l-.6 2.1a1 1 0 0 0 .4 1.2l2.1 1.2a1 1 0 0 1 0 1.7l-2.1 1.2a1 1 0 0 0-.4 1.2l.6 2.1a1 1 0 0 1-1.3 1.3l-2.1-.6a1 1 0 0 0-1.2.4l-1.2 2.1a1 1 0 0 1-1.7 0l-1.2-2.1a1 1 0 0 0-1.2-.4l-2.1.6a1 1 0 0 1-1.3-1.3l.6-2.1a1 1 0 0 0-.4-1.2L2.7 14.7a1 1 0 0 1 0-1.7l2.1-1.2a1 1 0 0 0 .4-1.2l-.6-2.1a1 1 0 0 1 1.3-1.3l2.1.6a1 1 0 0 0 1.2-.4l1.2-2.1a1 1 0 0 1 1.7 0Z"/><circle cx="12" cy="12" r="3"/>',
		'heart'   => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
		'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
		'food'    => '<path d="M12 2v8"/><path d="M4.93 10.93 8 14"/><path d="M2 18h2"/><path d="M20 18h2"/><path d="M19.07 10.93 16 14"/><path d="M12 10a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/>',
	);

	$key = sanitize_key( $name );
	if ( ! isset( $icons[ $key ] ) ) {
		return '';
	}

	return '<svg class="lnkup-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icons[ $key ] . '</svg>';
}

/**
 * Return category icon SVG for a vendor category label.
 *
 * @param string $label Category label.
 */
function lnkup_theme_category_icon( $label ) {
	$map = array(
		'Food Vendor'  => 'utensils',
		'Food Truck'   => 'truck',
		'Desserts'     => 'cake',
		'Drinks'       => 'cup',
		'Retail'       => 'bag',
		'Clothing'     => 'shirt',
		'Beauty'       => 'sparkle',
		'Jewelry'      => 'gem',
		'Art'          => 'palette',
		'Photo Booth'  => 'camera',
		'Services'     => 'wrench',
		'Wellness'     => 'heart',
		'Merchandise'  => 'package',
	);

	$key = $map[ $label ] ?? 'food';
	return lnkup_theme_landing_icon( $key );
}
