<?php
/**
 * Homepage FAQ (admin-editable; defaults use live pricing).
 *
 * @package LNKup_Theme
 */

$faqs = lnkup_public_faqs( 'home' );
if ( ! $faqs ) {
	return;
}
?>
<section class="lp-section" aria-labelledby="lp-faq-title">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'Questions', 'lnkup-theme' ),
				'title'   => __( 'Good to know', 'lnkup-theme' ),
				'id'      => 'lp-faq-title',
				'align'   => 'center',
			)
		);
		lnkup_public_faq( $faqs, 'lp-home-faq' );
		?>
	</div>
</section>
