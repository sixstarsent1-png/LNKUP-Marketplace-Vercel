<?php
/**
 * Public event profile. Vendor slots lead; imported ticket copy does not.
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
			if ( class_exists( 'LNKup_Shortcodes' ) ) {
				echo LNKup_Shortcodes::render_public_event( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		endwhile;
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
