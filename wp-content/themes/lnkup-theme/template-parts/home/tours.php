<?php
/**
 * Tours and series with open stops (only when their public pages are live).
 *
 * @package LNKup_Theme
 */

$tours = (array) ( lnkup_public_discovery()['tours'] ?? array() );
if ( ! $tours ) {
	return;
}
?>
<section class="lp-section lp-section--line" id="lp-tours" aria-labelledby="lp-tours-title" data-testid="lp-tours">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'Book once, sell in many places', 'lnkup-theme' ),
				'title'   => __( 'Tours and recurring series', 'lnkup-theme' ),
				'id'      => 'lp-tours-title',
				'lead'    => __( 'Multi-city tours and weekly markets with open vendor spots.', 'lnkup-theme' ),
			)
		);
		?>
		<div class="lp-rail" style="--lp-rail-cols:3">
			<?php foreach ( $tours as $tour ) : ?>
				<?php get_template_part( 'template-parts/public/tour-card', null, array( 'tour' => $tour ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
