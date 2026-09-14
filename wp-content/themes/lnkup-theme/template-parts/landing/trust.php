<?php
/**
 * Trust section.
 *
 * @package LNKup_Theme
 */

$marketplace_open = lnkup_theme_marketplace_open();
$launch_label     = lnkup_theme_launch_label();
$eyebrow          = $marketplace_open
	? (string) lnkup_landing_get( 'trust_eyebrow_open', __( 'Trusted by vendors', 'lnkup-theme' ) )
	: (string) lnkup_landing_get( 'trust_eyebrow', __( 'Launch goals', 'lnkup-theme' ) );
$title            = (string) lnkup_landing_get( 'trust_title', '' );
$lead             = (string) lnkup_landing_get( 'trust_lead', '' );
$side_image       = lnkup_landing_image_url( 'trust_image_id', 'images/events/ghoe-market.svg' );
$stat_rows        = lnkup_landing_trust_stats();
$use_custom       = (bool) lnkup_landing_get( 'use_custom_stats', 0 );
?>
<section class="lnkup-section lnkup-trust-section lnkup-reveal" data-testid="lnkup-trust-section">
	<div class="lnkup-container">
		<div class="lnkup-trust lnkup-trust--visual">
			<?php if ( $side_image ) : ?>
				<div class="lnkup-trust__media">
					<img src="<?php echo esc_url( $side_image ); ?>" alt="" width="720" height="540" loading="lazy" />
				</div>
			<?php endif; ?>
			<div class="lnkup-trust__content">
				<div class="lnkup-trust__intro" data-testid="lnkup-trust-eyebrow">
					<?php lnkup_theme_section_kicker( '04 / MARKETS', $eyebrow ); ?>
					<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
					<p class="lnkup-trust__lead"><?php echo esc_html( $lead ); ?></p>
				</div>
				<div class="lnkup-trust__stats">
					<?php foreach ( $stat_rows as $row ) : ?>
						<div class="lnkup-stat-card<?php echo ! empty( $row['badge'] ) ? ' lnkup-stat-card--goal' : ''; ?>">
							<?php if ( ! empty( $row['badge'] ) ) : ?>
								<span class="lnkup-stat-card__badge"><?php echo esc_html( (string) $row['badge'] ); ?></span>
							<?php endif; ?>
							<p class="lnkup-stat-card__value"><?php echo esc_html( (string) ( $row['value'] ?? '' ) ); ?></p>
							<p class="lnkup-stat-card__label"><?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?></p>
							<?php if ( ! empty( $row['sub'] ) ) : ?>
								<p class="lnkup-stat-card__sub"><?php echo esc_html( (string) $row['sub'] ); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( ! $marketplace_open && ! $use_custom ) : ?>
					<p class="lnkup-trust__disclaimer" data-testid="lnkup-trust-disclaimer">
						<?php
						echo esc_html(
							$launch_label
								? sprintf(
									/* translators: %s: launch date */
									__( 'Community goals for our %s launch — not current platform totals.', 'lnkup-theme' ),
									$launch_label
								)
								: __( 'Community goals — not current platform totals.', 'lnkup-theme' )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
