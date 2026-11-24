<?php
/**
 * Featured opportunities in market.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market            = $ctx['market'];
$opportunities     = $ctx['opportunities'];
$marketplace_open  = $ctx['marketplace_open'];
$has_opportunities = ! empty( $opportunities );
?>
<section class="lnkup-section lnkup-section--muted lnkup-section--bordered lnkup-reveal" id="opportunities" data-testid="lnkup-market-opportunities">
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<p class="lnkup-eyebrow"><?php esc_html_e( 'Featured opportunities', 'lnkup-theme' ); ?></p>
				<h2 class="lnkup-heading lnkup-heading--section">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: city */
							__( 'Vendor opportunities in %s', 'lnkup-theme' ),
							$market['display_name']
						)
					);
					?>
				</h2>
			</div>
			<?php if ( $marketplace_open && $has_opportunities ) : ?>
				<a href="<?php echo esc_url( $market['opportunities_url'] ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'View all in this city', 'lnkup-theme' ); ?></a>
			<?php endif; ?>
		</div>

		<?php if ( $has_opportunities ) : ?>
			<div class="lnkup-event-grid<?php echo $marketplace_open ? '' : ' lnkup-event-grid--preview'; ?>">
				<?php foreach ( $opportunities as $opp ) : ?>
					<div class="lnkup-featured-card-wrap<?php echo $marketplace_open ? '' : ' is-locked'; ?>" data-testid="lnkup-market-opp-card">
						<?php if ( $marketplace_open ) : ?>
							<?php echo function_exists( 'lnkup_render_opportunity_card_v2' ) ? lnkup_render_opportunity_card_v2( $opp ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<?php // Before launch only a title/date/place teaser is rendered; full card data never reaches the page. ?>
							<article class="lnkup-event-card lnkup-event-card--v2 lnkup-event-card--teaser">
								<div class="lnkup-event-card__body">
									<h3><?php echo esc_html( (string) ( $opp['event_name'] ?? $opp['name'] ?? '' ) ); ?></h3>
									<p class="lnkup-event-card__summary"><?php echo esc_html( trim( ( ! empty( $opp['date'] ) ? date_i18n( 'M j, Y', strtotime( (string) $opp['date'] ) ) : '' ) . ( ! empty( $opp['location_label'] ) ? ' · ' . $opp['location_label'] : '' ), ' ·' ) ); ?></p>
								</div>
							</article>
							<div class="lnkup-featured-card-wrap__lock" aria-hidden="true">
								<span><?php echo esc_html( $ctx['launch_label'] ? sprintf( __( 'Opens %s', 'lnkup-theme' ), $ctx['launch_label'] ) : __( 'Opens soon', 'lnkup-theme' ) ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else : ?>
			<div class="lnkup-empty lnkup-empty--market" data-testid="lnkup-market-empty-opps">
				<p><?php echo esc_html( sprintf( __( 'No public vendor opportunities are live in %s yet.', 'lnkup-theme' ), $market['display_name'] ) ); ?></p>
				<p class="lnkup-muted"><?php esc_html_e( 'Join the local waitlist and we will notify you when events open.', 'lnkup-theme' ); ?></p>
				<a href="<?php echo esc_url( $ctx['vendor_signup_url'] ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Join the waitlist', 'lnkup-theme' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</section>
