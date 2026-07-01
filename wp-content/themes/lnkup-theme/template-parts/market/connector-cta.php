<?php
/**
 * City connector CTA.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market = $ctx['market'];
?>
<section class="lnkup-section lnkup-market-cta lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-market-cta__card lnkup-market-cta__card--connector">
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php echo esc_html( sprintf( __( 'Help build LNKup in %s', 'lnkup-theme' ), $market['display_name'] ) ); ?>
			</h2>
			<p class="lnkup-muted"><?php esc_html_e( 'We are looking for local connectors who know vendors, organizers, markets, and events.', 'lnkup-theme' ); ?></p>
			<a href="<?php echo esc_url( $ctx['connector_url'] ); ?>" class="lnkup-btn lnkup-btn--outline" data-lnkup-track="market_connector_click"><?php esc_html_e( 'Become a City Connector', 'lnkup-theme' ); ?></a>
		</div>
	</div>
</section>
