<?php
/**
 * App Store download bar. Shown once the marketplace is open.
 *
 * @package LNKup_Theme
 */

if ( ! lnkup_theme_show_app_download() ) {
	return;
}

?>
<div class="lnkup-launch-bar lnkup-launch-bar--app lnkup-app-download" id="lnkup-app-download-bar" data-testid="lnkup-app-download-bar">
	<div class="lnkup-container lnkup-launch-bar__inner">
		<p class="lnkup-launch-bar__text">
			<strong><?php esc_html_e( 'LNKup is on the App Store', 'lnkup-theme' ); ?></strong>
			<span><?php esc_html_e( 'Browse events, apply, and manage your business in the app.', 'lnkup-theme' ); ?></span>
		</p>
		<div class="lnkup-launch-bar__actions">
			<a href="<?php echo esc_url( lnkup_theme_app_store_url( 'homepage-bar' ) ); ?>" class="lnkup-btn lnkup-btn--accent lnkup-btn--sm" data-testid="lnkup-app-download-bar-link"><?php esc_html_e( 'Get the app', 'lnkup-theme' ); ?></a>
			<button type="button" class="lnkup-launch-bar__dismiss" id="lnkup-app-download-bar-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'lnkup-theme' ); ?>">&times;</button>
		</div>
	</div>
</div>
