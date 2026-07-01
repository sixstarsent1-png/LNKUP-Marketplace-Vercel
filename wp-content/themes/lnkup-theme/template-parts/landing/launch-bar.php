<?php
/**
 * Launch announcement bar.
 *
 * @package LNKup_Theme
 */

if ( lnkup_theme_marketplace_open() ) {
	return;
}

$signup_url   = lnkup_theme_registration_url();
$launch_label = lnkup_theme_launch_label();
?>
<div class="lnkup-launch-bar" id="lnkup-launch-bar" data-testid="lnkup-launch-bar">
	<div class="lnkup-container lnkup-launch-bar__inner">
		<p class="lnkup-launch-bar__text">
			<strong><?php echo esc_html( $launch_label ? sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) : __( 'Launching soon', 'lnkup-theme' ) ); ?></strong>
			<span><?php esc_html_e( 'Create your free account now — browse & apply when we open.', 'lnkup-theme' ); ?></span>
		</p>
		<div class="lnkup-launch-bar__actions">
			<a href="<?php echo esc_url( $signup_url ); ?>" class="lnkup-btn lnkup-btn--accent lnkup-btn--sm"><?php esc_html_e( 'Sign up free', 'lnkup-theme' ); ?></a>
			<button type="button" class="lnkup-launch-bar__dismiss" id="lnkup-launch-bar-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'lnkup-theme' ); ?>">&times;</button>
		</div>
	</div>
</div>
