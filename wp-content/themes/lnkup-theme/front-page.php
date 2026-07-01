<?php
/**
 * Front page template — landing page.
 *
 * @package LNKup_Theme
 */

get_header();
?>
<div class="lnkup-site">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<?php
		get_template_part( 'template-parts/landing/hero' );
		get_template_part( 'template-parts/landing/launch-countdown' );
		get_template_part( 'template-parts/landing/how-it-works' );
		get_template_part( 'template-parts/landing/for-audiences' );
		get_template_part( 'template-parts/landing/categories' );
		get_template_part( 'template-parts/landing/featured' );
		get_template_part( 'template-parts/landing/trust' );
		get_template_part( 'template-parts/landing/final-cta' );
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
