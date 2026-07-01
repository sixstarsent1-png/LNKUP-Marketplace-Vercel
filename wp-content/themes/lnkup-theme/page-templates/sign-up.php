<?php
/**
 * Sign-up page template.
 *
 * @package LNKup_Theme
 */

get_header();
$launch_label = lnkup_theme_launch_label();
$login_url    = wp_login_url( lnkup_theme_registration_url() );
?>
<div class="lnkup-site lnkup-site--signup">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<section class="lnkup-signup-page">
			<div class="lnkup-container lnkup-signup-page__grid">
				<div class="lnkup-signup-page__intro">
					<span class="lnkup-pill lnkup-pill--launch">
						<span class="lnkup-pill__dot"></span>
						<?php echo esc_html( $launch_label ? sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) : __( 'Early access', 'lnkup-theme' ) ); ?>
					</span>
					<h1 class="lnkup-heading lnkup-heading--page"><?php esc_html_e( 'Create your LNKup account', 'lnkup-theme' ); ?></h1>
					<p class="lnkup-signup-page__lead"><?php esc_html_e( 'Join the opportunity network for live events. Vendors build profiles now and apply at launch. Organizers can prep events today.', 'lnkup-theme' ); ?></p>
					<ul class="lnkup-signup-page__benefits">
						<li><?php esc_html_e( 'Free to join — no credit card required', 'lnkup-theme' ); ?></li>
						<li><?php esc_html_e( 'Vendor or organizer — choose your path', 'lnkup-theme' ); ?></li>
						<li><?php esc_html_e( 'Pay only when accepted to an event', 'lnkup-theme' ); ?></li>
					</ul>
				</div>
				<div class="lnkup-signup-page__card lnkup-card">
					<?php
					if ( shortcode_exists( 'lnkup_register_form' ) ) {
						echo do_shortcode( '[lnkup_register_form]' );
					} else {
						echo '<p>' . esc_html__( 'Registration is unavailable. Please contact support.', 'lnkup-theme' ) . '</p>';
					}
					?>
				</div>
			</div>
		</section>
	</main>
	<?php get_template_part( 'template-parts/landing/site-footer' ); ?>
</div>
<?php
get_footer();
