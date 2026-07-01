<?php
/**
 * Vendor categories section.
 *
 * @package LNKup_Theme
 */

$categories = array(
	'Food Vendor', 'Food Truck', 'Desserts', 'Drinks', 'Retail', 'Clothing',
	'Beauty', 'Jewelry', 'Art', 'Photo Booth', 'Services', 'Wellness', 'Merchandise',
);
?>
<section id="categories" class="lnkup-section">
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<p class="lnkup-eyebrow"><?php esc_html_e( 'Vendor categories', 'lnkup-theme' ); ?></p>
				<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Whatever you bring to the event', 'lnkup-theme' ); ?></h2>
			</div>
			<p class="lnkup-section__aside"><?php esc_html_e( '13 categories · more launching soon', 'lnkup-theme' ); ?></p>
		</div>
		<div class="lnkup-categories">
			<?php foreach ( $categories as $cat ) : ?>
				<div class="lnkup-category">
					<span class="lnkup-category__icon" aria-hidden="true"><?php echo lnkup_theme_category_icon( $cat ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $cat ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
