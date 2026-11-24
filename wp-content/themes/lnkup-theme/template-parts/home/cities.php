<?php
/**
 * Cities with open events (live).
 *
 * @package LNKup_Theme
 */

$cities = (array) ( lnkup_public_discovery()['cities'] ?? array() );
if ( ! $cities ) {
	return;
}
?>
<section class="lp-section" aria-labelledby="lp-city-title" data-testid="lp-cities">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow'      => __( 'Near you', 'lnkup-theme' ),
				'title'        => __( 'Cities booking vendors', 'lnkup-theme' ),
				'id'           => 'lp-city-title',
				'action_label' => __( 'All events', 'lnkup-theme' ),
				'action_url'   => lnkup_theme_opportunities_url(),
			)
		);
		?>
		<div class="lp-grid lp-grid--4">
			<?php foreach ( $cities as $city ) : ?>
				<?php get_template_part( 'template-parts/public/city-card', null, array( 'city' => $city ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
