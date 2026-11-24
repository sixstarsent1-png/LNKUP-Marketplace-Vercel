<?php
/**
 * Public vendor card (from LNKup_Public_Discovery::featured_vendors()). Public Passport fields only.
 *
 * @package LNKup_Theme
 */

$vendor = isset( $args['vendor'] ) && is_array( $args['vendor'] ) ? $args['vendor'] : null;
if ( ! $vendor || empty( $vendor['url'] ) ) {
	return;
}
$meta = array_filter( array( (string) $vendor['category'], (string) $vendor['place'] ) );
?>
<a class="lp-vendor" href="<?php echo esc_url( (string) $vendor['url'] ); ?>" data-testid="lp-vendor-card">
	<span class="lp-vendor__cover">
		<?php if ( ! empty( $vendor['cover_id'] ) ) : ?>
			<?php echo lnkup_public_image( (int) $vendor['cover_id'], array( 'alt' => '', 'size' => 'medium_large', 'sizes' => '(max-width: 640px) 80vw, 300px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php elseif ( ! empty( $vendor['logo'] ) ) : ?>
			<img src="<?php echo esc_url( (string) $vendor['logo'] ); ?>" alt="" width="300" height="169" loading="lazy" decoding="async" style="object-fit:contain;padding:16%;background:#fff">
		<?php endif; ?>
	</span>
	<span class="lp-vendor__body">
		<?php if ( ! empty( $vendor['logo'] ) && ! empty( $vendor['cover_id'] ) ) : ?>
			<span class="lp-vendor__logo"><img src="<?php echo esc_url( (string) $vendor['logo'] ); ?>" alt="" width="48" height="48" loading="lazy" decoding="async"></span>
		<?php endif; ?>
		<span>
			<span class="lp-vendor__name" role="heading" aria-level="3"><?php echo esc_html( (string) $vendor['name'] ); ?></span>
			<?php if ( $meta ) : ?>
				<span class="lp-vendor__meta" style="display:block"><?php echo esc_html( implode( ' · ', $meta ) ); ?></span>
			<?php endif; ?>
		</span>
	</span>
</a>
