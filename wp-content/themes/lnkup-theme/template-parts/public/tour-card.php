<?php
/**
 * Tour / series card (only tours whose public page or tour filter is live).
 *
 * @package LNKup_Theme
 */

$tour = isset( $args['tour'] ) && is_array( $args['tour'] ) ? $args['tour'] : null;
if ( ! $tour || empty( $tour['url'] ) ) {
	return;
}
$type = 'tour' === $tour['type'] ? __( 'Multi-city tour', 'lnkup-theme' ) : __( 'Series', 'lnkup-theme' );
?>
<a class="lp-tour" href="<?php echo esc_url( (string) $tour['url'] ); ?>" data-testid="lp-tour-card">
	<?php if ( ! empty( $tour['image_id'] ) ) : ?>
		<?php echo lnkup_public_image( (int) $tour['image_id'], array( 'alt' => '', 'size' => 'medium_large', 'sizes' => '(max-width: 640px) 85vw, 400px' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php endif; ?>
	<span class="lp-tour__body">
		<span class="lp-chip lp-chip--lime"><?php echo esc_html( $type ); ?></span>
		<span class="lp-tour__title" role="heading" aria-level="3" style="display:block"><?php echo esc_html( (string) $tour['label'] ); ?></span>
		<span class="lp-tour__meta">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: stops, 2: stops booking vendors */
					__( '%1$s · %2$s booking vendors', 'lnkup-theme' ),
					sprintf( _n( '%d stop', '%d stops', (int) $tour['stop_count'], 'lnkup-theme' ), (int) $tour['stop_count'] ),
					number_format_i18n( (int) $tour['count'] )
				)
			);
			?>
		</span>
	</span>
</a>
