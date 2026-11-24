<?php
/**
 * Posts index (only used when a static "Posts page" is configured).
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
get_template_part(
	'template-parts/resources/list',
	null,
	array(
		'eyebrow' => __( 'Blog', 'lnkup-theme' ),
		'title'   => single_post_title( '', false ) ? single_post_title( '', false ) : __( 'Latest from LNKup', 'lnkup-theme' ),
	)
);
get_footer();
