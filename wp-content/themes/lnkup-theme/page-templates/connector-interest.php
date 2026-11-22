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
<div class="lnkup-site lnkup-site--connector">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<?php get_template_part( 'template-parts/connector/hero' ); ?>
		<?php get_template_part( 'template-parts/connector/how-it-works' ); ?>
		<section id="apply" class="lnkup-section lnkup-band lnkup-band--dark lnkup-reveal">
			<div class="lnkup-container">
				<?php lnkup_theme_section_kicker( '02 / APPLY', __( 'Interest form', 'lnkup-theme' ) ); ?>
				<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Tell us about your city', 'lnkup-theme' ); ?></h2>
				<p class="lnkup-muted"><?php esc_html_e( 'We review every application. If you know vendors, organizers, or both, we want to hear from you.', 'lnkup-theme' ); ?></p>
				<?php echo do_shortcode( '[lnkup_partner_program_terms program="city_connector"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="lnkup-connector-panel">
					<?php echo do_shortcode( '[lnkup_connector_interest_form show_heading="0"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</section>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
