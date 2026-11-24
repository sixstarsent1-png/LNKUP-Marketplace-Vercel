<?php
/**
 * Signed-in strip: role-specific next steps and (for vendors) recommended events.
 * Rendered only for logged-in users, whose pages are not served from the public page cache.
 *
 * @package LNKup_Theme
 */

$account = lnkup_public_account();
if ( '' === $account['url'] ) {
	return;
}
$user  = wp_get_current_user();
$name  = $user && $user->first_name ? $user->first_name : '';
$cards = array();
if ( 'vendor' === $account['role'] && lnkup_theme_marketplace_open() && class_exists( 'LNKup_Recommendations' ) && class_exists( 'LNKup_Public_Discovery' ) && function_exists( 'lnkup_group_opportunities_by_event' ) ) {
	try {
		$items = LNKup_Recommendations::get_for_user( get_current_user_id(), 12 );
		foreach ( lnkup_group_opportunities_by_event( (array) $items ) as $slots ) {
			$card = LNKup_Public_Discovery::event_card( $slots );
			if ( $card ) {
				$cards[] = $card;
			}
			if ( count( $cards ) >= 3 ) {
				break;
			}
		}
	} catch ( Throwable $e ) {
		$cards = array();
	}
}
$organizer_links = array(
	array( __( 'Create an event', 'lnkup-theme' ), function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create' ) : add_query_arg( 'view', 'create', $account['url'] ) ),
	array( __( 'Review applications', 'lnkup-theme' ), function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'applications' ) : $account['url'] ),
	array( __( 'Find vendors', 'lnkup-theme' ), lnkup_page_url( 'vendors' ) ),
);
$vendor_links = array(
	array( __( 'My applications', 'lnkup-theme' ), add_query_arg( 'view', 'applications', $account['url'] ) ),
	array( __( 'Find events', 'lnkup-theme' ), lnkup_theme_opportunities_url() ),
);
?>
<section class="lp-section lp-section--tight lp-section--mist" aria-labelledby="lp-welcome-title" data-testid="lp-welcome-back">
	<div class="lp-container">
		<div class="lp-section-head" style="margin-bottom:var(--lp-space-5)">
			<div class="lp-section-head__text">
				<h2 class="lp-h3" id="lp-welcome-title"><?php echo esc_html( $name ? sprintf( /* translators: %s: first name */ __( 'Welcome back, %s', 'lnkup-theme' ), $name ) : __( 'Welcome back', 'lnkup-theme' ) ); ?></h2>
			</div>
			<div class="lp-actions">
				<?php foreach ( 'organizer' === $account['role'] ? $organizer_links : $vendor_links as $link ) : ?>
					<a class="lp-btn lp-btn--outline lp-btn--sm" href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a>
				<?php endforeach; ?>
				<a class="lp-btn lp-btn--primary lp-btn--sm" href="<?php echo esc_url( $account['url'] ); ?>"><?php esc_html_e( 'Dashboard', 'lnkup-theme' ); ?></a>
			</div>
		</div>
		<?php if ( $cards ) : ?>
			<p class="lp-eyebrow"><?php esc_html_e( 'Recommended for your business', 'lnkup-theme' ); ?></p>
			<div class="lp-rail" style="--lp-rail-cols:3">
				<?php foreach ( $cards as $card ) : ?>
					<?php get_template_part( 'template-parts/public/event-card', null, array( 'card' => $card, 'testid' => 'lp-recommended-card' ) ); ?>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
