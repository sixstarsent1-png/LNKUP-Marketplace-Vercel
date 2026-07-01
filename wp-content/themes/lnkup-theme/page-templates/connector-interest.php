<?php
/**
 * City Connector interest form page.
 *
 * Template Name: Connector Interest
 *
 * @package LNKup_Theme
 */

get_header();
?>
<main class="lnkup-main lnkup-signup-page">
	<div class="lnkup-container">
		<div class="lnkup-signup-page__inner">
			<?php echo do_shortcode( '[lnkup_connector_interest_form]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
</main>
<?php
get_footer();
