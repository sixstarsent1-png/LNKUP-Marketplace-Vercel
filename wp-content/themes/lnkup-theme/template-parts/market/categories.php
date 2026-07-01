<?php
/**
 * Vendor categories needed in market.
 *
 * @package LNKup_Theme
 */

$ctx = lnkup_theme_market_context();
if ( ! $ctx || empty( $ctx['categories'] ) ) {
	return;
}
$market = $ctx['market'];
?>
<section class="lnkup-section lnkup-reveal" id="categories">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'Vendor categories', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: city */
						__( 'Vendors we are building in %s', 'lnkup-theme' ),
						$market['display_name']
					)
				);
				?>
			</h2>
		</div>
		<div class="lnkup-chip-grid">
			<?php foreach ( $ctx['categories'] as $term ) : ?>
				<span class="lnkup-chip"><?php echo esc_html( $term->name ); ?></span>
			<?php endforeach; ?>
		</div>
	</div>
</section>
