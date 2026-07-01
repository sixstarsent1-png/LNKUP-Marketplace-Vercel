<?php
/**
 * Full width page template.
 *
 * Template Name: Full Width
 *
 * @package LNKup_Theme
 */

get_header();
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
get_footer();
