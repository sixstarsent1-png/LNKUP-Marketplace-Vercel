<?php
/**
 * City card: live open-event count; links to the city market page when one is published.
 *
 * @package LNKup_Theme
 */

$city = isset( $args['city'] ) && is_array( $args['city'] ) ? $args['city'] : null;
if ( ! $city ) {
	return;
}
$url = ! empty( $city['market_url'] ) ? (string) $city['market_url'] : (string) $city['url'];
?>
<a class="lp-city" href="<?php echo esc_url( $url ); ?>" data-testid="lp-city-card">
	<span>
		<span class="lp-city__name"><?php echo esc_html( (string) $city['label'] ); ?></span>
		<span class="lp-city__count"><?php echo esc_html( sprintf( /* translators: %d: open events */ _n( '%d event booking vendors', '%d events booking vendors', (int) $city['count'], 'lnkup-theme' ), (int) $city['count'] ) ); ?></span>
	</span>
	<span class="lp-city__arrow"><?php echo lnkup_public_icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
</a>
