<?php
/**
 * Vendor categories that open events are asking for (live counts).
 *
 * @package LNKup_Theme
 */

$categories = array_slice( (array) ( lnkup_public_discovery()['categories'] ?? array() ), 0, 12 );
if ( count( $categories ) < 2 ) {
	return;
}
?>
<section class="lp-section lp-section--mist" aria-labelledby="lp-cat-title" data-testid="lp-categories">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'Browse by what you sell', 'lnkup-theme' ),
				'title'   => __( 'Vendor categories in demand', 'lnkup-theme' ),
				'id'      => 'lp-cat-title',
				'lead'    => __( 'Counts show events with open spots for each category today.', 'lnkup-theme' ),
			)
		);
		?>
		<div class="lp-grid lp-grid--tiles">
			<?php foreach ( $categories as $cat ) : ?>
				<?php get_template_part( 'template-parts/public/category-tile', null, array( 'category' => $cat ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
