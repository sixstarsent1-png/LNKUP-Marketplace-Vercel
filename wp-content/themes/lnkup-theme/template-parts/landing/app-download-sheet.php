<?php
/**
 * First-visit iPhone and iPad download sheet.
 *
 * @package LNKup_Theme
 */

$store_url = lnkup_theme_app_store_url( 'ios-sheet' );
?>
<aside class="lnkup-app-sheet lnkup-app-sheet--bar lnkup-app-download" id="lnkup-app-download-sheet" hidden data-testid="lnkup-app-download-sheet" aria-labelledby="lnkup-app-sheet-title">
	<div class="lnkup-app-sheet__panel">
		<span class="lnkup-app-sheet__icon" aria-hidden="true"><?php lnkup_logo( false ); ?></span>
		<p class="lnkup-app-sheet__text">
			<strong id="lnkup-app-sheet-title"><?php esc_html_e( 'LNKup for iPhone', 'lnkup-theme' ); ?></strong>
			<span><?php esc_html_e( 'Approval alerts and your event pass.', 'lnkup-theme' ); ?></span>
		</p>
		<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $store_url ); ?>" data-app-sheet-download data-testid="lnkup-app-sheet-download"><?php esc_html_e( 'Get the app', 'lnkup-theme' ); ?></a>
		<button type="button" class="lnkup-app-sheet__close" data-app-sheet-dismiss data-testid="lnkup-app-sheet-continue" aria-label="<?php esc_attr_e( 'Not now', 'lnkup-theme' ); ?>">&times;</button>
	</div>
</aside>
