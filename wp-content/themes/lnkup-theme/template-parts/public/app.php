<?php
/**
 * iPhone app section. Hidden when app promotion is off. No Android claims.
 *
 * @package LNKup_Theme
 */

if ( ! lnkup_theme_show_app_download() ) {
	return;
}
$audience = isset( $args['audience'] ) ? (string) $args['audience'] : 'all';
$points   = array(
	'vendor'    => __( 'Approval alerts, booth payments and your event pass in your pocket.', 'lnkup-theme' ),
	'organizer' => __( 'Review vendor requests and check vendors in at load-in from your phone.', 'lnkup-theme' ),
);
?>
<section class="lp-section lp-section--tight" id="get-the-app" aria-labelledby="lp-app-title" data-testid="lnkup-get-the-app">
	<div class="lp-container">
		<div class="lp-app">
			<div>
				<p class="lp-eyebrow"><?php esc_html_e( 'LNKup for iPhone', 'lnkup-theme' ); ?></p>
				<h2 class="lp-h2" id="lp-app-title"><?php esc_html_e( 'Your event business, on your phone', 'lnkup-theme' ); ?></h2>
				<ul class="lp-checks">
					<?php foreach ( $points as $key => $point ) : ?>
						<?php if ( 'all' === $audience || $key === $audience ) : ?>
							<li><?php echo esc_html( $point ); ?></li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
				<p class="lp-app__note"><?php esc_html_e( 'On Android? Everything works on the web at thelnkup.com.', 'lnkup-theme' ); ?></p>
			</div>
			<div class="lp-app__download">
				<?php lnkup_theme_app_store_badge( 'black', 'homepage-section' ); ?>
				<figure class="lp-app__qr">
					<img src="<?php echo esc_url( lnkup_theme_asset( 'images/app-store-qr.svg' ) ); ?>" width="120" height="120" alt="<?php esc_attr_e( 'QR code to download LNKup on the App Store', 'lnkup-theme' ); ?>" loading="lazy" decoding="async">
					<figcaption><?php esc_html_e( 'Scan with your iPhone camera', 'lnkup-theme' ); ?></figcaption>
				</figure>
			</div>
		</div>
	</div>
</section>
