<?php
/**
 * Organizer tools overview with a product preview.
 *
 * @package LNKup_Theme
 */

?>
<section class="lp-section lp-section--mist" aria-labelledby="lp-org-tools-title" data-testid="lp-organizer-tools">
	<div class="lp-container">
		<div class="lp-split" style="margin-bottom:var(--lp-space-7)">
			<div>
				<p class="lp-eyebrow"><?php esc_html_e( 'For organizers', 'lnkup-theme' ); ?></p>
				<h2 class="lp-h2" id="lp-org-tools-title"><?php esc_html_e( 'Stop running vendor signups from spreadsheets and DMs', 'lnkup-theme' ); ?></h2>
				<p class="lp-lead"><?php esc_html_e( 'Applications, documents, payments and event-day check-in live in one dashboard. Vendors find your event in LNKup search, too.', 'lnkup-theme' ); ?></p>
				<div class="lp-actions" style="margin-top:var(--lp-space-5)">
					<a class="lp-btn lp-btn--primary lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'List your event free', 'lnkup-theme' ); ?></a>
					<a class="lp-link" href="<?php echo esc_url( home_url( '/for-organizers/' ) ); ?>"><?php esc_html_e( 'See organizer pricing', 'lnkup-theme' ); ?> <?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				</div>
			</div>
			<?php lnkup_public_preview( 'organizer' ); ?>
		</div>
		<?php lnkup_public_feature_grid( lnkup_public_organizer_features() ); ?>
	</div>
</section>
