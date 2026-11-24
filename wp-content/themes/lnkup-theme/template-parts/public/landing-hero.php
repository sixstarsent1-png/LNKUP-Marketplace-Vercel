<?php
/**
 * Acquisition landing hero.
 *
 * $args: eyebrow, title, lead, image (attachment ID or asset path), ctas (array of
 * [label, url, style, testid]), note, testid, after (callable rendering extra content).
 *
 * @package LNKup_Theme
 */

$args = wp_parse_args(
	$args,
	array(
		'eyebrow' => '',
		'title'   => '',
		'lead'    => '',
		'image'   => '',
		'ctas'    => array(),
		'note'    => '',
		'testid'  => 'lp-landing-hero',
		'after'   => null,
	)
);
?>
<section class="lp-hero" aria-labelledby="lp-landing-title" data-testid="<?php echo esc_attr( $args['testid'] ); ?>">
	<?php if ( $args['image'] ) : ?>
		<div class="lp-hero__media">
			<?php echo lnkup_public_image( $args['image'], array( 'alt' => '', 'eager' => true, 'width' => 1920, 'height' => 1080, 'sizes' => '100vw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
	<div class="lp-container lp-hero__inner">
		<div class="lp-hero__copy">
			<?php if ( $args['eyebrow'] ) : ?>
				<p class="lp-eyebrow"><?php echo esc_html( $args['eyebrow'] ); ?></p>
			<?php endif; ?>
			<h1 class="lp-h1" id="lp-landing-title"><?php echo esc_html( $args['title'] ); ?></h1>
			<?php if ( $args['lead'] ) : ?>
				<p class="lp-lead"><?php echo esc_html( $args['lead'] ); ?></p>
			<?php endif; ?>
			<?php if ( $args['ctas'] ) : ?>
				<div class="lp-actions lp-hero__ctas">
					<?php foreach ( $args['ctas'] as $cta ) : ?>
						<a class="lp-btn lp-btn--lg <?php echo esc_attr( $cta[2] ?? 'lp-btn--lime' ); ?>" href="<?php echo esc_url( $cta[1] ); ?>"<?php echo ! empty( $cta[3] ) ? ' data-testid="' . esc_attr( $cta[3] ) . '"' : ''; ?>><?php echo esc_html( $cta[0] ); ?></a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $args['note'] ) : ?>
				<p class="lp-hero__note"><?php echo esc_html( $args['note'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		if ( is_callable( $args['after'] ) ) {
			call_user_func( $args['after'] );
		}
		?>
	</div>
</section>
