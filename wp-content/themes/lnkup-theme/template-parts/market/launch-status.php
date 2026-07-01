<?php
/**
 * Market launch status card.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx ) {
	return;
}
$market = $ctx['market'];
$stats  = $ctx['stats'];
?>
<section class="lnkup-section lnkup-section--muted lnkup-market-status lnkup-reveal" data-testid="lnkup-market-status">
	<div class="lnkup-container">
		<div class="lnkup-market-status__card">
			<div class="lnkup-market-status__badge"><?php echo esc_html( $ctx['status_label'] ); ?></div>
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: city name */
						__( '%s marketplace status', 'lnkup-theme' ),
						$market['display_name']
					)
				);
				?>
			</h2>
			<?php if ( ! $ctx['marketplace_open'] ) : ?>
				<p class="lnkup-muted" data-testid="lnkup-market-prelaunch-copy">
					<?php
					echo esc_html(
						$ctx['launch_label']
							? sprintf(
								/* translators: 1: city, 2: launch date */
								__( '%1$s is in prelaunch. We are onboarding vendors and organizers before public marketplace launch in %2$s.', 'lnkup-theme' ),
								$market['display_name'],
								$ctx['launch_label']
							)
							: sprintf(
								/* translators: %s: city */
								__( '%s is in prelaunch. We are onboarding vendors and organizers before public marketplace launch.', 'lnkup-theme' ),
								$market['display_name']
							)
					);
					?>
				</p>
			<?php else : ?>
				<p class="lnkup-muted">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: city */
							__( 'Browse open vendor opportunities and grow your presence across %s.', 'lnkup-theme' ),
							$market['display_name']
						)
					);
					?>
				</p>
			<?php endif; ?>
			<div class="lnkup-market-status__stats">
				<?php if ( null !== $stats['public_opportunities'] && $stats['public_opportunities'] > 0 ) : ?>
					<div class="lnkup-market-stat">
						<span class="lnkup-market-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['public_opportunities'] ) ); ?></span>
						<span class="lnkup-market-stat__label"><?php esc_html_e( 'Public opportunities', 'lnkup-theme' ); ?></span>
					</div>
				<?php endif; ?>
				<div class="lnkup-market-stat">
					<span class="lnkup-market-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['vendor_goal'] ) ); ?>+</span>
					<span class="lnkup-market-stat__label"><?php esc_html_e( 'Vendor goal', 'lnkup-theme' ); ?></span>
				</div>
				<div class="lnkup-market-stat">
					<span class="lnkup-market-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['organizer_goal'] ) ); ?>+</span>
					<span class="lnkup-market-stat__label"><?php esc_html_e( 'Organizer goal', 'lnkup-theme' ); ?></span>
				</div>
				<?php if ( ! empty( $stats['vendor_signups'] ) ) : ?>
					<div class="lnkup-market-stat">
						<span class="lnkup-market-stat__value"><?php echo esc_html( number_format_i18n( (int) $stats['vendor_signups'] ) ); ?></span>
						<span class="lnkup-market-stat__label"><?php esc_html_e( 'Vendors joined from this market', 'lnkup-theme' ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<p class="lnkup-muted lnkup-market-status__disclaimer"><?php esc_html_e( 'Goals are targets — not live achievement stats.', 'lnkup-theme' ); ?></p>
		</div>
	</div>
</section>
