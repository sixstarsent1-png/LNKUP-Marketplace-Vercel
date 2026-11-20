<?php
/**
 * First-visit iPhone and iPad download sheet.
 *
 * @package LNKup_Theme
 */

$store_url = lnkup_theme_app_store_url( 'ios-sheet' );
?>
<div class="lnkup-app-sheet lnkup-app-download" id="lnkup-app-download-sheet" hidden data-testid="lnkup-app-download-sheet">
	<div class="lnkup-app-sheet__backdrop" data-app-sheet-dismiss></div>
	<div class="lnkup-app-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="lnkup-app-sheet-title">
		<h2 id="lnkup-app-sheet-title"><?php esc_html_e( 'LNKup is on the App Store', 'lnkup-theme' ); ?></h2>
		<p><?php esc_html_e( 'Find events, apply, and keep your vendor business in the iPhone app.', 'lnkup-theme' ); ?></p>
		<div class="lnkup-app-sheet__actions">
			<a class="lnkup-app-badge" href="<?php echo esc_url( $store_url ); ?>" data-app-sheet-download data-testid="lnkup-app-sheet-download">
				<img src="<?php echo esc_url( 'https://tools.applemediaservices.com/api/badges/download-on-the-app-store/black/en-us?size=250x83' ); ?>" alt="<?php esc_attr_e( 'Download on the App Store', 'lnkup-theme' ); ?>" width="135" height="45">
			</a>
			<button type="button" class="lnkup-btn lnkup-btn--outline" data-app-sheet-dismiss data-testid="lnkup-app-sheet-continue"><?php esc_html_e( 'Continue on the website', 'lnkup-theme' ); ?></button>
		</div>
	</div>
</div>
