<?php
/**
 * Site-wide announcement bar (Appearance → Landing Page → Announcement).
 *
 * @package LNKup_Theme
 */

if ( ! lnkup_landing_get( 'announce_enabled', 0 ) ) {
	return;
}
$text    = trim( (string) lnkup_landing_get( 'announce_text', '' ) );
$expires = trim( (string) lnkup_landing_get( 'announce_expires', '' ) );
if ( '' === $text || ( '' !== $expires && $expires < current_time( 'Y-m-d' ) ) ) {
	return;
}
$link_url   = (string) lnkup_landing_get( 'announce_url', '' );
$link_label = (string) lnkup_landing_get( 'announce_link_label', '' );
$key        = 'lp_announce_' . substr( md5( $text . $link_url ), 0, 10 );
?>
<div class="lp-announce" data-lp-announce="<?php echo esc_attr( $key ); ?>" data-testid="lp-announcement">
	<div class="lp-container lp-announce__inner">
		<p style="margin:0">
			<?php echo esc_html( $text ); ?>
			<?php if ( $link_url && $link_label ) : ?>
				<a href="<?php echo esc_url( $link_url ); ?>"><?php echo esc_html( $link_label ); ?></a>
			<?php endif; ?>
		</p>
		<button type="button" class="lp-announce__close" aria-label="<?php esc_attr_e( 'Dismiss announcement', 'lnkup-theme' ); ?>" data-lp-announce-close>&times;</button>
	</div>
</div>
