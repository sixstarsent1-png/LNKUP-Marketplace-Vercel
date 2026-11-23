<?php
/**
 * Tours and recurring series accepting vendors. Hidden when no published tour has an open stop.
 *
 * @package LNKup_Theme
 */

$tours = ( lnkup_theme_marketplace_open() && function_exists( 'lnkup_public_tour_summaries' ) ) ? lnkup_public_tour_summaries( 3 ) : array();
if ( ! $tours ) {
	return;
}
?>
<section id="tours" class="lnkup-section lnkup-tours-section lnkup-reveal" data-testid="lnkup-home-tours">
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<p class="lnkup-eyebrow"><?php esc_html_e( 'Tours & series', 'lnkup-theme' ); ?></p>
				<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Book one stop or the whole run', 'lnkup-theme' ); ?></h2>
				<p class="lnkup-section__lead"><?php esc_html_e( 'Multi-city tours and recurring markets. Each stop has its own date, fee and spots.', 'lnkup-theme' ); ?></p>
			</div>
		</div>
		<ul class="lnkup-tour-rail">
			<?php foreach ( $tours as $tour ) : ?>
				<li class="lnkup-tour-tile">
					<p class="lnkup-tour-tile__kind"><?php echo 'tour' === $tour['type'] ? esc_html__( 'Multi-city tour', 'lnkup-theme' ) : esc_html__( 'Recurring series', 'lnkup-theme' ); ?></p>
					<h3 class="lnkup-tour-tile__title"><a href="<?php echo esc_url( $tour['url'] ); ?>"><?php echo esc_html( $tour['title'] ); ?></a></h3>
					<p class="lnkup-tour-tile__meta">
						<?php
						$bits = array();
						if ( $tour['organizer'] ) {
							$bits[] = sprintf( /* translators: %s: organizer */ __( 'By %s', 'lnkup-theme' ), $tour['organizer'] );
						}
						$bits[] = sprintf( /* translators: 1: open stops, 2: all stops */ _n( '%1$d of %2$d date open', '%1$d of %2$d dates open', $tour['stop_count'], 'lnkup-theme' ), $tour['open_count'], $tour['stop_count'] );
						if ( null !== $tour['fee_min'] ) {
							$bits[] = $tour['fee_min'] > 0
								? sprintf( /* translators: %s: lowest booth fee */ __( 'From %s', 'lnkup-theme' ), function_exists( 'lnkup_public_money' ) ? lnkup_public_money( $tour['fee_min'] ) : '$' . number_format_i18n( $tour['fee_min'] ) )
								: __( 'Free spots', 'lnkup-theme' );
						}
						echo esc_html( implode( ' · ', $bits ) );
						?>
					</p>
					<ol class="lnkup-tour-tile__stops">
						<?php foreach ( array_slice( $tour['stops'], 0, 4 ) as $stop ) : ?>
							<li class="<?php echo $stop['accepting'] ? 'is-open' : 'is-closed'; ?>">
								<span class="lnkup-tour-tile__date"><?php echo esc_html( $stop['date'] ); ?></span>
								<span class="lnkup-tour-tile__city"><?php echo esc_html( $stop['label'] ); ?></span>
								<span class="lnkup-tour-tile__state"><?php echo $stop['accepting'] ? esc_html__( 'Open', 'lnkup-theme' ) : esc_html__( 'Closed', 'lnkup-theme' ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
					<?php if ( $tour['stop_count'] > 4 ) : ?>
						<p class="lnkup-tour-tile__more"><?php echo esc_html( sprintf( /* translators: %d: more stops */ _n( '+%d more date', '+%d more dates', $tour['stop_count'] - 4, 'lnkup-theme' ), $tour['stop_count'] - 4 ) ); ?></p>
					<?php endif; ?>
					<a class="lnkup-btn lnkup-btn--outline lnkup-btn--sm" href="<?php echo esc_url( $tour['url'] ); ?>"><?php esc_html_e( 'See dates', 'lnkup-theme' ); ?></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>
