<?php
/**
 * Launch countdown section.
 *
 * @package LNKup_Theme
 */

if ( lnkup_theme_marketplace_open() ) {
	return;
}

$launch_label = lnkup_theme_launch_label();
$launch_iso   = lnkup_theme_launch_iso();
?>
<section class="lnkup-section lnkup-section--launch-countdown lnkup-reveal" id="launch-countdown" data-testid="lnkup-launch-countdown"<?php echo $launch_iso ? ' data-launch="' . esc_attr( $launch_iso ) . '"' : ''; ?>>
	<div class="lnkup-container">
		<div class="lnkup-launch-countdown">
			<div class="lnkup-launch-countdown__intro">
				<p class="lnkup-eyebrow"><?php esc_html_e( 'Early access', 'lnkup-theme' ); ?></p>
				<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $launch_label ? sprintf( __( 'Marketplace opens %s', 'lnkup-theme' ), $launch_label ) : __( 'Marketplace opening soon', 'lnkup-theme' ) ); ?></h2>
				<p class="lnkup-launch-countdown__lead"><?php esc_html_e( 'Sign up today to build your profile. Vendors apply when we launch; organizers can prep events now.', 'lnkup-theme' ); ?></p>
			</div>
			<div class="lnkup-launch-countdown__timer" aria-live="polite">
				<div class="lnkup-launch-countdown__unit"><span class="lnkup-launch-countdown__value" data-unit="days">--</span><span class="lnkup-launch-countdown__label"><?php esc_html_e( 'Days', 'lnkup-theme' ); ?></span></div>
				<div class="lnkup-launch-countdown__unit"><span class="lnkup-launch-countdown__value" data-unit="hours">--</span><span class="lnkup-launch-countdown__label"><?php esc_html_e( 'Hours', 'lnkup-theme' ); ?></span></div>
				<div class="lnkup-launch-countdown__unit"><span class="lnkup-launch-countdown__value" data-unit="minutes">--</span><span class="lnkup-launch-countdown__label"><?php esc_html_e( 'Minutes', 'lnkup-theme' ); ?></span></div>
				<div class="lnkup-launch-countdown__unit"><span class="lnkup-launch-countdown__value" data-unit="seconds">--</span><span class="lnkup-launch-countdown__label"><?php esc_html_e( 'Seconds', 'lnkup-theme' ); ?></span></div>
			</div>
		</div>
	</div>
</section>
