<?php
/**
 * Vendor CTA for market.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market = $ctx['market'];
?>
<section class="lnkup-section lnkup-section--muted lnkup-market-cta lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-market-cta__card">
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php echo esc_html( sprintf( __( 'Want more places to sell in %s?', 'lnkup-theme' ), $market['display_name'] ) ); ?>
			</h2>
			<p class="lnkup-muted"><?php esc_html_e( 'Create one vendor profile and use it to apply for festivals, markets, rodeos, pop-ups, and live events.', 'lnkup-theme' ); ?></p>
			<a href="<?php echo esc_url( $ctx['vendor_signup_url'] ); ?>" class="lnkup-btn lnkup-btn--primary" data-lnkup-track="market_vendor_signup_click"><?php esc_html_e( 'Join as a vendor', 'lnkup-theme' ); ?></a>
		</div>
	</div>
</section>
