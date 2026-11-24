<?php
/**
 * Event Card 5.0. Expects $args['card'] from LNKup_Public_Discovery::event_card().
 * Whole card is one link (title link stretched); badges only state facts from the listing.
 *
 * @package LNKup_Theme
 */

$card = isset( $args['card'] ) && is_array( $args['card'] ) ? $args['card'] : null;
if ( ! $card || empty( $card['url'] ) ) {
	return;
}
$testid  = isset( $args['testid'] ) ? (string) $args['testid'] : 'lp-event-card';
$eager   = ! empty( $args['eager'] );
$heading = isset( $args['heading'] ) ? (string) $args['heading'] : 'h3';
$heading = in_array( $heading, array( 'h2', 'h3', 'h4' ), true ) ? $heading : 'h3';
$ts      = $card['date'] ? strtotime( (string) $card['date'] ) : false;
$media   = '';
if ( ! empty( $card['media'] ) && function_exists( 'lnkup_event_media_html' ) ) {
	$media = lnkup_event_media_html(
		(array) $card['media'],
		(string) $card['alt'],
		array(
			'fallback' => (array) $card['media_fallback'],
			'sizes'    => '(max-width: 640px) 90vw, (max-width: 1100px) 45vw, 380px',
			'eager'    => $eager,
		)
	);
}
?>
<article class="lp-event" data-testid="<?php echo esc_attr( $testid ); ?>" data-event-id="<?php echo esc_attr( (string) $card['event_id'] ); ?>">
	<div class="lp-event__media">
		<?php echo $media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by lnkup_event_media_html(). ?>
		<div class="lp-event__badges">
			<?php if ( ! empty( $card['closing_soon'] ) ) : ?>
				<span class="lp-chip lp-chip--lime"><?php esc_html_e( 'Closing soon', 'lnkup-theme' ); ?></span>
			<?php else : ?>
				<span class="lp-chip lp-chip--ink"><?php esc_html_e( 'Booking vendors', 'lnkup-theme' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( $ts ) : ?>
			<time class="lp-event__date" datetime="<?php echo esc_attr( (string) $card['date_iso'] ); ?>">
				<span class="lp-event__date-month"><?php echo esc_html( date_i18n( 'M', $ts ) ); ?></span>
				<span class="lp-event__date-day"><?php echo esc_html( date_i18n( 'j', $ts ) ); ?></span>
			</time>
		<?php endif; ?>
	</div>
	<div class="lp-event__body">
		<?php if ( '' !== (string) $card['organizer'] ) : ?>
			<p class="lp-event__organizer">
				<?php echo esc_html( (string) $card['organizer'] ); ?>
				<?php if ( ! empty( $card['verified'] ) ) : ?>
					<span class="lp-event__verified" title="<?php esc_attr_e( 'Verified organizer', 'lnkup-theme' ); ?>"><?php echo lnkup_public_icon( 'verified' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="lp-visually-hidden"><?php esc_html_e( 'Verified organizer', 'lnkup-theme' ); ?></span></span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<<?php echo esc_html( $heading ); ?> class="lp-event__title"><a href="<?php echo esc_url( (string) $card['url'] ); ?>"><?php echo esc_html( (string) $card['title'] ); ?></a></<?php echo esc_html( $heading ); ?>>
		<p class="lp-event__meta">
			<?php if ( '' !== (string) $card['place'] ) : ?>
				<span><?php echo lnkup_public_icon( 'pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( (string) $card['place'] ); ?></span>
			<?php endif; ?>
			<?php if ( '' !== (string) $card['date_label'] ) : ?>
				<span><?php echo lnkup_public_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( (string) $card['date_label'] ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $card['categories'] ) ) : ?>
			<ul class="lp-event__cats" aria-label="<?php esc_attr_e( 'Vendor categories wanted', 'lnkup-theme' ); ?>">
				<?php foreach ( (array) $card['categories'] as $cat ) : ?>
					<li class="lp-chip"><?php echo esc_html( (string) $cat ); ?></li>
				<?php endforeach; ?>
				<?php if ( (int) $card['more_categories'] > 0 ) : ?>
					<li class="lp-chip lp-chip--line"><?php echo esc_html( sprintf( /* translators: %d: more categories */ __( '+%d more', 'lnkup-theme' ), (int) $card['more_categories'] ) ); ?></li>
				<?php endif; ?>
			</ul>
		<?php endif; ?>
		<?php if ( ! empty( $card['tour'] ) ) : ?>
			<a class="lp-event__tour lp-link" href="<?php echo esc_url( (string) $card['tour']['url'] ); ?>"><?php echo esc_html( (string) $card['tour']['label'] ); ?></a>
		<?php endif; ?>
		<div class="lp-event__foot">
			<?php if ( ! empty( $card['access']['gated'] ) ) : ?>
				<span class="lp-event__status"><?php echo esc_html( (string) ( $card['status_label'] ?? __( 'Vendor applications open', 'lnkup-theme' ) ) ); ?></span>
				<a class="lp-event__gate" data-testid="lp-event-gate" data-lnkup-gate="card" href="<?php echo esc_url( (string) $card['access']['cta']['sign_in_url'] ); ?>">
					<?php echo lnkup_public_icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Sign in to view pricing', 'lnkup-theme' ); ?></span>
				</a>
			<?php else : ?>
				<span class="lp-event__price"><?php echo esc_html( (string) $card['fee_label'] ); ?></span>
				<span class="lp-event__spots"><?php echo esc_html( sprintf( /* translators: %d: open vendor spots */ _n( '%d spot open', '%d spots open', (int) $card['spots'], 'lnkup-theme' ), (int) $card['spots'] ) ); ?></span>
			<?php endif; ?>
		</div>
	</div>
</article>
