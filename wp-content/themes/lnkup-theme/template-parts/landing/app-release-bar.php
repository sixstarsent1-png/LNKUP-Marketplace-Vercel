<?php
/**
 * App release announcement bar (post marketplace open, pre app launch).
 *
 * @package LNKup_Theme
 */

if ( ! lnkup_theme_marketplace_open() || lnkup_theme_app_released() ) {
	return;
}

$label             = lnkup_theme_app_release_label();
$opportunities_url = lnkup_page_url( 'opportunities' );
$signup_url        = lnkup_theme_registration_url();
$cta_url           = is_user_logged_in() ? $opportunities_url : $signup_url;
$cta_label         = is_user_logged_in() ? __( 'Find Events', 'lnkup-theme' ) : __( 'Use the web today', 'lnkup-theme' );
?>
<div class="lnkup-launch-bar lnkup-launch-bar--app" id="lnkup-app-release-bar" data-testid="lnkup-app-release-bar">
	<div class="lnkup-container lnkup-launch-bar__inner">
		<p class="lnkup-launch-bar__text">
			<strong>
				<?php
				echo esc_html(
					$label
						? sprintf( __( 'LNKup app launches %s', 'lnkup-theme' ), $label )
						: __( 'LNKup app launching soon', 'lnkup-theme' )
				);
				?>
			</strong>
			<span><?php esc_html_e( 'Browse and apply on the web now.', 'lnkup-theme' ); ?></span>
		</p>
		<div class="lnkup-launch-bar__actions">
			<a href="<?php echo esc_url( $cta_url ); ?>" class="lnkup-btn lnkup-btn--accent lnkup-btn--sm"><?php echo esc_html( $cta_label ); ?></a>
			<button type="button" class="lnkup-launch-bar__dismiss" id="lnkup-app-release-bar-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'lnkup-theme' ); ?>">&times;</button>
		</div>
	</div>
</div>
