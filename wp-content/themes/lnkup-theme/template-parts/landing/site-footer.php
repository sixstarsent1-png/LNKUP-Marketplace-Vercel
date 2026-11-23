<?php
/**
 * Site footer partial.
 *
 * @package LNKup_Theme
 */

$signup_url       = function_exists( 'lnkup_theme_signup_url' ) ? lnkup_theme_signup_url() : lnkup_theme_registration_url();
$marketplace_open = lnkup_theme_marketplace_open();
$launch_label     = lnkup_theme_launch_label();
$footer_title     = (string) lnkup_landing_get( 'footer_title', '' );
$footer_blurb     = (string) lnkup_landing_get( 'footer_blurb', '' );
$footer_cta       = (string) lnkup_landing_get( 'footer_cta', __( 'Get started free', 'lnkup-theme' ) );
$cta_label        = is_user_logged_in() ? __( 'Complete profile', 'lnkup-theme' ) : $footer_cta;
if ( '' === trim( $footer_blurb ) || false !== stripos( $footer_blurb, 'join thousands of vendors' ) ) {
	$footer_blurb = __( 'Vendor spots at festivals, markets and live events. Apply for free and pay only when an organizer accepts you.', 'lnkup-theme' );
}
?>
<footer class="lnkup-footer">
	<div class="lnkup-container">
		<div class="lnkup-footer__grid">
			<div>
				<h2 class="lnkup-heading lnkup-heading--footer"><?php echo esc_html( $footer_title ); ?></h2>
				<p><?php echo esc_html( $footer_blurb ); ?></p>
				<div class="lnkup-footer__actions">
					<a href="<?php echo esc_url( is_user_logged_in() ? lnkup_page_url( 'vendor-profile' ) : $signup_url ); ?>" class="lnkup-btn lnkup-btn--accent lnkup-btn--lg"><?php echo esc_html( $cta_label ); ?></a>
					<?php
					if ( ! is_front_page() ) {
						lnkup_theme_app_store_badge( 'white', 'footer' );
					}
					?>
				</div>
			</div>
			<div class="lnkup-footer__cols">
				<div>
					<h3><?php esc_html_e( 'For vendors', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( $marketplace_open ? lnkup_page_url( 'opportunities' ) : $signup_url ); ?>"><?php echo $marketplace_open ? esc_html__( 'Find Events', 'lnkup-theme' ) : esc_html__( 'Sign up', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( $signup_url ); ?>"><?php esc_html_e( 'Create Account', 'lnkup-theme' ); ?></a></li>
						<?php if ( ! is_user_logged_in() ) : ?>
							<li><a href="<?php echo esc_url( function_exists( 'lnkup_theme_login_url' ) ? lnkup_theme_login_url() : wp_login_url() ); ?>"><?php esc_html_e( 'Log in', 'lnkup-theme' ); ?></a></li>
						<?php endif; ?>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'vendor-dashboard' ) ); ?>"><?php esc_html_e( 'Applications', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
				<div>
					<h3><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( lnkup_page_url( 'organizer-dashboard' ) ); ?>"><?php esc_html_e( 'Organizer Home', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create' ) : lnkup_page_url( 'organizer-dashboard' ) . '?view=create' ); ?>"><?php esc_html_e( 'Create Event', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'events' ) : lnkup_page_url( 'organizer-dashboard' ) . '?view=events' ); ?>"><?php esc_html_e( 'Your events', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
				<div>
					<h3><?php esc_html_e( 'Company', 'lnkup-theme' ); ?></h3>
					<ul>
						<li><a href="<?php echo esc_url( home_url( '/privacy/' ) ); ?>"><?php esc_html_e( 'Privacy', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/terms/' ) ); ?>"><?php esc_html_e( 'Terms', 'lnkup-theme' ); ?></a></li>
						<li><a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact', 'lnkup-theme' ); ?></a></li>
					</ul>
				</div>
			</div>
		</div>
		<div class="lnkup-footer__bottom">
			<span class="lnkup-footer__brand">LNK<span>up</span></span>
			<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> LNKup. <?php esc_html_e( 'The opportunity network for live events.', 'lnkup-theme' ); ?><?php echo $launch_label && ! $marketplace_open ? ' · ' . esc_html( sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) ) : ''; ?></p>
		</div>
	</div>
</footer>
