<?php

/**

 * Vendor dashboard shell — shared by vendor pages; opportunities stay public.

 *

 * Template Name: Vendor Shell

 *

 * @package LNKup_Theme

 */



$shell_slug     = get_post_field( 'post_name', get_queried_object_id() );

$is_vendor_page = in_array( $shell_slug, array( 'vendor-dashboard', 'vendor-profile' ), true );



if ( $is_vendor_page ) {

	lnkup_require_roles( array( 'lnkup_vendor', 'lnkup_admin', 'administrator' ) );

}



$use_dashboard_shell = $is_vendor_page;

if ( ! $use_dashboard_shell && is_user_logged_in() ) {

	$user = wp_get_current_user();

	$use_dashboard_shell = (bool) array_intersect(

		array( 'lnkup_vendor', 'lnkup_admin', 'administrator' ),

		(array) $user->roles

	);

}



get_header();



if ( $use_dashboard_shell ) {

	lnkup_dashboard_shell_open( 'vendor', lnkup_dashboard_vendor_title() );

	while ( have_posts() ) {

		the_post();

		the_content();

	}

	lnkup_dashboard_shell_close();

} else {

	?>

	<div class="lnkup-site">

		<?php get_template_part( 'template-parts/landing/site-header' ); ?>

		<main class="lnkup-site__main lnkup-container lnkup-section">

			<?php

			while ( have_posts() ) :

				the_post();

				the_content();

			endwhile;

			?>

		</main>

		<?php get_template_part( 'template-parts/landing/site-footer' ); ?>

	</div>

	<?php

}



get_footer();

