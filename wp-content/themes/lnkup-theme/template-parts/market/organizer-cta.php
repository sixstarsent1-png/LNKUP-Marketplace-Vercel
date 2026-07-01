<?php
/**
 * Organizer CTA for market.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market = $ctx['market'];
$cta_url = $ctx['marketplace_open'] ? $ctx['organizer_create_url'] : $ctx['organizer_signup_url'];
?>
<section class="lnkup-section lnkup-market-cta lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-market-cta__card">
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php echo esc_html( sprintf( __( 'Need vendors for an event in %s?', 'lnkup-theme' ), $market['display_name'] ) ); ?>
			</h2>
			<p class="lnkup-muted"><?php esc_html_e( 'Create an event, open vendor applications, collect payments, and release vendor packets through LNKup.', 'lnkup-theme' ); ?></p>
			<a href="<?php echo esc_url( $cta_url ); ?>" class="lnkup-btn lnkup-btn--primary" data-lnkup-track="market_organizer_signup_click"><?php esc_html_e( 'Post an opportunity', 'lnkup-theme' ); ?></a>
		</div>
	</div>
</section>
