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

$is_public_listing = 'opportunities' === $shell_slug && ( isset( $_GET['event'] ) || isset( $_GET['apply'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( $is_public_listing ) {
	$use_dashboard_shell = false;
	add_filter(
		'document_title_parts',
		static function ( $parts ) {
			$event_id = isset( $_GET['event'] ) ? absint( $_GET['event'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $event_id ) {
				$event = get_post( $event_id );
				if ( $event && 'lnkup_event' === $event->post_type ) {
					$parts['title'] = $event->post_title;
				}
			} elseif ( ! empty( $_GET['apply'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$slot = get_post( absint( $_GET['apply'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$event_id = ( $slot && 'lnkup_opportunity' === $slot->post_type ) ? (int) get_post_meta( $slot->ID, '_lnkup_event_id', true ) : 0;
				$event    = $event_id ? get_post( $event_id ) : null;
				$parts['title'] = ( $event && 'lnkup_event' === $event->post_type ) ? $event->post_title : __( 'Apply', 'lnkup-theme' );
			}
			return $parts;
		}
	);
} elseif ( ! $use_dashboard_shell && is_user_logged_in() ) {

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

