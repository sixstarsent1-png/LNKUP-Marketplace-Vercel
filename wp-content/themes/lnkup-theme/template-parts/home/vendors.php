<?php
/**
 * Vendors with public Passports (only shown when at least three exist).
 *
 * @package LNKup_Theme
 */

$vendors = class_exists( 'LNKup_Public_Discovery' ) ? LNKup_Public_Discovery::featured_vendors( 8 ) : array();
if ( count( $vendors ) < 3 ) {
	return;
}
?>
<section class="lp-section lp-section--mist" aria-labelledby="lp-vendors-title" data-testid="lp-featured-vendors">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow'      => __( 'For organizers', 'lnkup-theme' ),
				'title'        => __( 'Vendors on LNKup', 'lnkup-theme' ),
				'id'           => 'lp-vendors-title',
				'lead'         => __( 'Vendors who chose to share a public profile. Contact details are never shown publicly.', 'lnkup-theme' ),
				'action_label' => __( 'Vendor directory', 'lnkup-theme' ),
				'action_url'   => lnkup_page_url( 'vendors' ),
			)
		);
		?>
		<div class="lp-rail" style="--lp-rail-cols:4">
			<?php foreach ( $vendors as $vendor ) : ?>
				<?php get_template_part( 'template-parts/public/vendor-card', null, array( 'vendor' => $vendor ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
