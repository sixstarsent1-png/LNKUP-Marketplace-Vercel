<?php
/**
 * Final CTA section.
 *
 * @package LNKup_Theme
 */

$signup_url        = lnkup_theme_registration_url();
$opportunities_url = lnkup_theme_opportunities_url();
$profile_url       = lnkup_page_url( 'vendor-profile' );
$marketplace_open  = lnkup_theme_marketplace_open();
$launch_label      = lnkup_theme_launch_label();
$title             = (string) lnkup_landing_get( 'cta_title', '' );
$body              = trim( (string) lnkup_landing_get( 'cta_body', '' ) );
$button            = (string) lnkup_landing_get( 'cta_button', __( 'Create your account', 'lnkup-theme' ) );

$open_body = __( 'Create your vendor profile, browse opportunities, and apply for free. Pay only when an organizer accepts you.', 'lnkup-theme' );
$stale     = array(
	'Join the prelaunch today and be ready for launch day.',
	'Join the prelaunch today. Build your profile now and apply when we launch.',
);
if ( $launch_label ) {
	$stale[] = sprintf( 'Join the prelaunch today. Build your profile now and apply when we launch %s.', $launch_label );
}
if ( '' === $body || ( $marketplace_open && in_array( $body, $stale, true ) ) ) {
	$body = $marketplace_open
		? $open_body
		: ( $launch_label
			? sprintf( __( 'Join the prelaunch today. Build your profile now and apply when we launch %s.', 'lnkup-theme' ), $launch_label )
			: __( 'Join the prelaunch today and be ready for launch day.', 'lnkup-theme' ) );
}
?>
<section class="lnkup-section lnkup-section--cta lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-cta-banner">
			<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
			<p><?php echo esc_html( $body ); ?></p>
			<div class="lnkup-hero__cta">
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( $profile_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg"><?php esc_html_e( 'Complete your profile', 'lnkup-theme' ); ?></a>
				<?php else : ?>
					<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg" data-testid="lnkup-final-cta-signup"><?php echo esc_html( $button ); ?></a>
				<?php endif; ?>
				<?php if ( $marketplace_open ) : ?>
					<?php if ( is_user_logged_in() || 0 !== strcasecmp( trim( $button ), 'Find Events' ) ) : ?>
						<a href="<?php echo esc_url( $opportunities_url ); ?>" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg lnkup-btn--on-dark"><?php esc_html_e( 'Browse open spots', 'lnkup-theme' ); ?></a>
					<?php endif; ?>
				<?php else : ?>
					<a href="#how-it-works" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg lnkup-btn--on-dark"><?php esc_html_e( 'Explore How It Works', 'lnkup-theme' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
