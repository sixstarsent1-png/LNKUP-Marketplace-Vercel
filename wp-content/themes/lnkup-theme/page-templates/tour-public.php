<?php
/**
 * Public tour / series page (/tours/{slug}/, /series/{slug}/).
 *
 * Presentation only: the plugin resolves the tour, filters to published stops and renders the body.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="lnkup-site lnkup-site--tour">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main" id="main">
		<?php
		if ( class_exists( 'LNKup_Portfolio_Public' ) ) {
			echo LNKup_Portfolio_Public::render_current(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
