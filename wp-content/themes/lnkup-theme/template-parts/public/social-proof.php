<?php
/**
 * Partner logos, testimonials and case studies — rendered only from admin-entered content that is
 * marked as verified (Appearance → Landing Page). Nothing here is generated or seeded.
 *
 * $args: audience (all|organizer|vendor).
 *
 * @package LNKup_Theme
 */

$audience     = isset( $args['audience'] ) ? (string) $args['audience'] : 'all';
$match        = static function ( $row ) use ( $audience ) {
	$for = (string) ( $row['audience'] ?? 'all' );
	return 'all' === $audience || 'all' === $for || $for === $audience;
};
$logos        = array_filter( (array) lnkup_landing_get( 'logos', array() ), static function ( $row ) {
	return is_array( $row ) && ! empty( $row['image_id'] ) && ! empty( $row['name'] );
} );
$testimonials = array_filter( (array) lnkup_landing_get( 'testimonials', array() ), static function ( $row ) use ( $match ) {
	return is_array( $row ) && ! empty( $row['verified'] ) && '' !== trim( (string) ( $row['quote'] ?? '' ) ) && '' !== trim( (string) ( $row['name'] ?? '' ) ) && $match( $row );
} );
$cases        = array_filter( (array) lnkup_landing_get( 'case_studies', array() ), static function ( $row ) use ( $match ) {
	return is_array( $row ) && ! empty( $row['verified'] ) && '' !== trim( (string) ( $row['title'] ?? '' ) ) && $match( $row );
} );
if ( ! $logos && ! $testimonials && ! $cases ) {
	return;
}
?>
<section class="lp-section lp-section--line" aria-labelledby="lp-proof-title" data-testid="lp-social-proof">
	<div class="lp-container">
		<?php
		lnkup_public_section_head(
			array(
				'eyebrow' => __( 'From the community', 'lnkup-theme' ),
				'title'   => __( 'Organizers and vendors on LNKup', 'lnkup-theme' ),
				'id'      => 'lp-proof-title',
				'align'   => 'center',
			)
		);
		?>
		<?php if ( $logos ) : ?>
			<ul class="lp-logos" aria-label="<?php esc_attr_e( 'Events and organizers using LNKup', 'lnkup-theme' ); ?>" style="margin-bottom:var(--lp-space-7)">
				<?php foreach ( $logos as $logo ) : ?>
					<li>
						<?php $img = lnkup_public_image( (int) $logo['image_id'], array( 'alt' => (string) $logo['name'], 'size' => 'medium', 'sizes' => '160px' ) ); ?>
						<?php if ( ! empty( $logo['url'] ) ) : ?>
							<a href="<?php echo esc_url( (string) $logo['url'] ); ?>" rel="noopener" target="_blank"><?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
						<?php else : ?>
							<?php echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $testimonials ) : ?>
			<div class="lp-grid lp-grid--3" style="margin-bottom:var(--lp-space-6)">
				<?php foreach ( $testimonials as $row ) : ?>
					<figure class="lp-quote">
						<blockquote><p style="margin:0"><?php echo esc_html( (string) $row['quote'] ); ?></p></blockquote>
						<figcaption>
							<?php if ( ! empty( $row['image_id'] ) ) : ?>
								<?php echo lnkup_public_image( (int) $row['image_id'], array( 'alt' => '', 'size' => 'thumbnail', 'sizes' => '44px', 'width' => 44, 'height' => 44 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
							<span><strong><?php echo esc_html( (string) $row['name'] ); ?></strong><span class="lp-muted"><?php echo esc_html( (string) ( $row['role'] ?? '' ) ); ?></span></span>
						</figcaption>
					</figure>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( $cases ) : ?>
			<div class="lp-grid lp-grid--3">
				<?php foreach ( $cases as $row ) : ?>
					<article class="lp-case">
						<?php if ( ! empty( $row['image_id'] ) ) : ?>
							<div class="lp-case__media"><?php echo lnkup_public_image( (int) $row['image_id'], array( 'alt' => '', 'size' => 'medium_large', 'sizes' => '(max-width: 760px) 92vw, 380px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
						<div class="lp-case__body">
							<h3 class="lp-h3" style="font-size:var(--lp-fs-lg)"><?php echo esc_html( (string) $row['title'] ); ?></h3>
							<p class="lp-muted" style="margin:0"><?php echo esc_html( (string) ( $row['summary'] ?? '' ) ); ?></p>
							<?php if ( ! empty( $row['url'] ) ) : ?>
								<a class="lp-link" href="<?php echo esc_url( (string) $row['url'] ); ?>"><?php esc_html_e( 'Read the story', 'lnkup-theme' ); ?></a>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
