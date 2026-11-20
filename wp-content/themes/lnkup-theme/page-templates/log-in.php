<?php
/**
 * Branded login page template.
 *
 * @package LNKup_Theme
 */

get_header();
$lost = isset( $_GET['action'] ) && 'lostpassword' === sanitize_key( wp_unslash( (string) $_GET['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="lnkup-site lnkup-site--signup">
	<?php get_template_part( 'template-parts/landing/site-header' ); ?>
	<main class="lnkup-site__main">
		<section class="lnkup-signup-page">
			<div class="lnkup-container lnkup-signup-page__grid">
				<div class="lnkup-signup-page__intro">
					<span class="lnkup-pill">
						<span class="lnkup-pill__dot"></span>
						<?php esc_html_e( 'LNKup', 'lnkup-theme' ); ?>
					</span>
					<h1 class="lnkup-heading lnkup-heading--page">
						<?php echo $lost ? esc_html__( 'Reset your password', 'lnkup-theme' ) : esc_html__( 'Log in to LNKup', 'lnkup-theme' ); ?>
					</h1>
					<p class="lnkup-signup-page__lead">
						<?php
						echo $lost
							? esc_html__( 'We will email a reset link. After you choose a new password, you return to the page you were on.', 'lnkup-theme' )
							: esc_html__( 'Pick up an application, a saved event, or your dashboard.', 'lnkup-theme' );
						?>
					</p>
				</div>
				<div class="lnkup-signup-page__card lnkup-card">
					<?php
					if ( shortcode_exists( 'lnkup_login_form' ) ) {
						echo do_shortcode( '[lnkup_login_form]' );
					} else {
						echo '<p>' . esc_html__( 'Log in is unavailable. Please contact support.', 'lnkup-theme' ) . '</p>';
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
