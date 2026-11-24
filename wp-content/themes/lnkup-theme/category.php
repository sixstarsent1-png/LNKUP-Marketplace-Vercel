<?php
/**
 * Category archives, including /resources/ (vendor + organizer guides).
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

$is_hub = class_exists( 'LNKup_Resources_Hub' ) && LNKup_Resources_Hub::is_resources_index();
get_header();
get_template_part(
	'template-parts/resources/list',
	null,
	array(
		'eyebrow' => $is_hub ? __( 'Resources', 'lnkup-theme' ) : __( 'Guides', 'lnkup-theme' ),
		'title'   => $is_hub ? __( 'Guides for vendors and organizers', 'lnkup-theme' ) : single_cat_title( '', false ),
		'lead'    => $is_hub ? __( 'Practical guides for booking booths, running vendor markets and growing on LNKup.', 'lnkup-theme' ) : wp_strip_all_tags( category_description() ),
	)
);
get_footer();
