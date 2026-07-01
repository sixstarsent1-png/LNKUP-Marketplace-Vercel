<?php
/**
 * Final CTA section.
 *
 * @package LNKup_Theme
 */

$signup_url       = lnkup_theme_registration_url();
$opportunities_url = lnkup_page_url( 'opportunities' );
$profile_url      = lnkup_page_url( 'vendor-profile' );
$marketplace_open = lnkup_theme_marketplace_open();
$launch_label     = lnkup_theme_launch_label();
?>
<section class="lnkup-section lnkup-section--cta lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-cta-banner">
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Ready to join the opportunity network?', 'lnkup-theme' ); ?></h2>
			<?php if ( $marketplace_open ) : ?>
				<p><?php esc_html_e( 'Create your vendor profile, browse opportunities, and apply for free. Pay only when an organizer accepts you.', 'lnkup-theme' ); ?></p>
			<?php else : ?>
				<p><?php echo esc_html( $launch_label ? sprintf( __( 'Join the prelaunch today. Build your profile now and apply when we launch %s.', 'lnkup-theme' ), $launch_label ) : __( 'Join the prelaunch today and be ready for launch day.', 'lnkup-theme' ) ); ?></p>
			<?php endif; ?>
			<div class="lnkup-hero__cta">
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg"><?php esc_html_e( 'Complete your profile', 'lnkup-theme' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg" data-testid="lnkup-final-cta-signup"><?php esc_html_e( 'Join the Prelaunch', 'lnkup-theme' ); ?></a>
				<?php endif; ?>
				<?php if ( $marketplace_open ) : ?>
					<a href="<?php echo esc_url( $opportunities_url ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg lnkup-btn--on-dark"><?php esc_html_e( 'Find Events', 'lnkup-theme' ); ?></a>
				<?php else : ?>
					<a href="#how-it-works" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg lnkup-btn--on-dark"><?php esc_html_e( 'Explore How It Works', 'lnkup-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
