<?php
/**
 * Vendor category tile with live event count.
 *
 * @package LNKup_Theme
 */

$cat = isset( $args['category'] ) && is_array( $args['category'] ) ? $args['category'] : null;
if ( ! $cat ) {
	return;
}
$url = isset( $args['url'] ) ? (string) $args['url'] : (string) $cat['url'];
?>
<a class="lp-tile" href="<?php echo esc_url( $url ); ?>" data-testid="lp-category-tile">
	<?php echo lnkup_public_image( lnkup_public_category_image( (string) $cat['label'] ), array( 'alt' => '', 'width' => 600, 'height' => 400, 'sizes' => '(max-width: 640px) 45vw, 220px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<span>
		<span class="lp-tile__label"><?php echo esc_html( (string) $cat['label'] ); ?></span>
		<?php if ( isset( $cat['count'] ) && (int) $cat['count'] > 0 ) : ?>
			<span class="lp-tile__count"><?php echo esc_html( sprintf( /* translators: %d: events */ _n( '%d event', '%d events', (int) $cat['count'], 'lnkup-theme' ), (int) $cat['count'] ) ); ?></span>
		<?php endif; ?>
	</span>
</a>
