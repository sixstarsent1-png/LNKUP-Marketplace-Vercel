<?php
/**
 * Sign-up page template.
 *
 * @package LNKup_Theme
 */

get_header();
$launch_label     = lnkup_theme_launch_label();
$marketplace_open = lnkup_theme_marketplace_open();
$login_url        = function_exists( 'lnkup_login_page_url' ) ? lnkup_login_page_url() : wp_login_url();
?>
<div class="lnkup-site lnkup-site--signup">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<section class="lnkup-signup-page">
			<div class="lnkup-container lnkup-signup-page__grid">
				<div class="lnkup-signup-page__intro">
					<span class="lnkup-pill<?php echo $marketplace_open ? '' : ' lnkup-pill--launch'; ?>">
						<span class="lnkup-pill__dot"></span>
						<?php
						if ( $marketplace_open ) {
							esc_html_e( 'Join LNKup', 'lnkup-theme' );
						} elseif ( $launch_label ) {
							echo esc_html( sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) );
						} else {
							esc_html_e( 'Early access', 'lnkup-theme' );
						}
						?>
					</span>
					<h1 class="lnkup-heading lnkup-heading--page"><?php esc_html_e( 'Create your LNKup account', 'lnkup-theme' ); ?></h1>
					<p class="lnkup-signup-page__lead">
						<?php
						echo $marketplace_open
							? esc_html__( 'Join the opportunity network for live events. Vendors browse and apply today. Organizers can list opportunities now.', 'lnkup-theme' )
							: esc_html__( 'Join the opportunity network for live events. Vendors build profiles now and apply at launch. Organizers can prep events today.', 'lnkup-theme' );
						?>
					</p>
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
