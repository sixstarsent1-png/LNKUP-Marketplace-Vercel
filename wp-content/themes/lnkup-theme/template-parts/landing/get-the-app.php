<?php
/**
 * Homepage "Get the app" section for vendors and organizers.
 *
 * @package LNKup_Theme
 */

if ( ! lnkup_theme_show_app_download() ) {
	return;
}

$vendor_points = array(
	__( 'Get alerts the moment an organizer approves you.', 'lnkup-theme' ),
	__( 'Pay booth fees and keep your event pass and QR check-in handy.', 'lnkup-theme' ),
	__( 'Find and apply to events from anywhere.', 'lnkup-theme' ),
);
$organizer_points = array(
	__( 'Review vendor requests on the go.', 'lnkup-theme' ),
	__( 'Check in vendors at load-in from your phone.', 'lnkup-theme' ),
	__( 'Get alerts when vendors apply or pay.', 'lnkup-theme' ),
);
?>
<section class="lnkup-section lnkup-section--app lnkup-reveal" id="get-the-app" data-testid="lnkup-get-the-app">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<?php lnkup_theme_section_kicker( '05 / APP', __( 'LNKup for iPhone', 'lnkup-theme' ) ); ?>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Your event business, in your pocket', 'lnkup-theme' ); ?></h2>
			<p class="lnkup-section__lead"><?php esc_html_e( 'Everything works on the web. The iPhone app adds instant alerts and event-day tools.', 'lnkup-theme' ); ?></p>
		</div>
		<div class="lnkup-get-app">
			<div class="lnkup-get-app__grid">
				<article class="lnkup-card lnkup-get-app__card">
					<h3><?php esc_html_e( 'For vendors', 'lnkup-theme' ); ?></h3>
					<ul>
						<?php foreach ( $vendor_points as $point ) : ?>
							<li><?php echo esc_html( $point ); ?></li>
						<?php endforeach; ?>
					</ul>
				</article>
				<article class="lnkup-card lnkup-get-app__card">
					<h3><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></h3>
					<ul>
						<?php foreach ( $organizer_points as $point ) : ?>
							<li><?php echo esc_html( $point ); ?></li>
						<?php endforeach; ?>
					</ul>
				</article>
			</div>
			<div class="lnkup-get-app__download">
				<?php lnkup_theme_app_store_badge( 'black', 'homepage-section' ); ?>
				<figure class="lnkup-get-app__qr">
					<img src="<?php echo esc_url( lnkup_theme_asset( 'images/app-store-qr.svg' ) ); ?>" width="140" height="140" alt="<?php esc_attr_e( 'QR code to download LNKup on the App Store', 'lnkup-theme' ); ?>" loading="lazy">
					<figcaption><?php esc_html_e( 'Scan with your iPhone camera', 'lnkup-theme' ); ?></figcaption>
				</figure>
				<p class="lnkup-get-app__android"><?php esc_html_e( 'On Android? Keep using LNKup on the web. Everything works here too.', 'lnkup-theme' ); ?></p>
			</div>
		</div>
	</div>
</section>
