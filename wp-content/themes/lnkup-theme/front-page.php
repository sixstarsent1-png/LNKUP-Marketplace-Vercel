<?php
/**
 * Homepage 5.0. Every live section hides itself when it has nothing real to show.
 *
 * @package LNKup_Theme
 */

get_header();
$open = lnkup_theme_marketplace_open();
?>
<div class="lnkup-site lp-site">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main id="lp-main" class="lp-main">
		<?php
		get_template_part( 'template-parts/home/hero' );
		get_template_part( 'template-parts/home/welcome-back' );
		if ( $open ) {
			get_template_part( 'template-parts/home/live-events' );
			get_template_part( 'template-parts/home/categories' );
			get_template_part( 'template-parts/home/cities' );
		} else {
			get_template_part( 'template-parts/landing/launch-countdown' );
			get_template_part( 'template-parts/landing/featured' );
		}
		get_template_part( 'template-parts/home/how-it-works' );
		get_template_part( 'template-parts/home/audiences' );
		get_template_part( 'template-parts/home/organizer-tools' );
		if ( $open ) {
			get_template_part( 'template-parts/home/tours' );
			get_template_part( 'template-parts/home/vendors' );
		}
		get_template_part( 'template-parts/public/social-proof' );
		get_template_part( 'template-parts/public/app' );
		get_template_part( 'template-parts/home/faq' );
		get_template_part( 'template-parts/public/final-cta' );
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
