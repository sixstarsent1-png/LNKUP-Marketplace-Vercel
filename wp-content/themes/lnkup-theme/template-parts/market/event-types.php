<?php
/**
 * Local event types for market.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx || empty( $ctx['event_types'] ) ) {
	return;
}
$market = $ctx['market'];
?>
<section class="lnkup-section lnkup-section--bordered lnkup-reveal">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'Event types', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: city */
						__( 'Live events across %s', 'lnkup-theme' ),
						$market['display_name']
					)
				);
				?>
			</h2>
		</div>
		<div class="lnkup-chip-grid">
			<?php foreach ( $ctx['event_types'] as $term ) : ?>
				<span class="lnkup-chip lnkup-chip--outline"><?php echo esc_html( $term->name ); ?></span>
			<?php endforeach; ?>
		</div>
	</div>
</section>
