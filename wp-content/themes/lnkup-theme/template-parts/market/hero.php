<?php
/**
 * Market hero section.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market     = $ctx['market'];
$hero_image = lnkup_theme_asset( 'images/marketing/hero-market.jpg' );
$city       = strtoupper( (string) ( $market['city'] ?? $market['display_name'] ?? '' ) );
$state      = strtoupper( (string) ( $market['state'] ?? '' ) );
$stamp      = trim( $city . ( $state ? ' / ' . $state : '' ) );
?>
<section class="lnkup-hero lnkup-hero--cinematic lnkup-market-hero lnkup-reveal" data-testid="lnkup-market-hero">
	<?php
	get_template_part(
		'template-parts/landing/hero-canvas',
		null,
		array(
			'photo' => $hero_image,
			'stamp' => $stamp,
		)
	);
	?>
	<div class="lnkup-container lnkup-hero__grid">
		<div class="lnkup-hero__content">
			<span class="lnkup-pill lnkup-pill--market">
				<span class="lnkup-pill__dot"></span>
				<?php echo esc_html( sprintf( __( 'LNKup %s', 'lnkup-theme' ), $market['display_name'] ) ); ?>
			</span>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php echo esc_html( $ctx['headline'] ); ?></h1>
			<p class="lnkup-hero__lead"><?php echo esc_html( $ctx['description'] ); ?></p>
			<div class="lnkup-hero__cta lnkup-hero__actions">
				<a href="<?php echo esc_url( $ctx['vendor_signup_url'] ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg" data-testid="lnkup-market-vendor-cta" data-lnkup-track="market_vendor_signup_click"><?php esc_html_e( 'Join as a vendor', 'lnkup-theme' ); ?></a>
				<?php if ( $ctx['marketplace_open'] ) : ?>
					<a href="<?php echo esc_url( $market['opportunities_url'] ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg"><?php esc_html_e( 'View opportunities', 'lnkup-theme' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $ctx['organizer_signup_url'] ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg" data-testid="lnkup-market-organizer-cta" data-lnkup-track="market_organizer_signup_click"><?php esc_html_e( 'Post vendor opportunities', 'lnkup-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
