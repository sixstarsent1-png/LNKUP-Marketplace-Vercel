<?php
/**
 * Vendor vs organizer audience cards.
 *
 * @package LNKup_Theme
 */

$signup_url      = lnkup_theme_registration_url();
$organizer_url   = lnkup_page_url( 'organizer-dashboard' );
$profile_url     = lnkup_page_url( 'vendor-profile' );
$launch_label    = lnkup_theme_launch_label();
$marketplace_open = lnkup_theme_marketplace_open();
?>
<section class="lnkup-section lnkup-section--muted lnkup-section--bordered lnkup-reveal" id="for-audiences">
	<div class="lnkup-container">
		<div class="lnkup-section__intro">
			<p class="lnkup-eyebrow"><?php esc_html_e( 'Who it\'s for', 'lnkup-theme' ); ?></p>
			<h2 class="lnkup-heading lnkup-heading--section"><?php esc_html_e( 'Built for vendors and organizers', 'lnkup-theme' ); ?></h2>
		</div>
		<div class="lnkup-audience-grid">
			<article class="lnkup-card lnkup-audience-card">
				<div class="lnkup-audience-card__icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9h18v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M3 9l2.5-4h13L21 9"/><path d="M12 13v4"/></svg>
				</div>
				<h3 class="lnkup-audience-card__title"><?php esc_html_e( 'Vendors', 'lnkup-theme' ); ?></h3>
				<p class="lnkup-audience-card__desc"><?php esc_html_e( 'Food trucks, artisans, retailers, and service vendors looking for booth opportunities at festivals, markets, and live events.', 'lnkup-theme' ); ?></p>
				<ul class="lnkup-audience-card__list">
					<li><?php esc_html_e( 'Create your free profile today', 'lnkup-theme' ); ?></li>
					<li><?php echo esc_html( $marketplace_open ? __( 'Browse and apply to events', 'lnkup-theme' ) : ( $launch_label ? sprintf( __( 'Apply when we launch %s', 'lnkup-theme' ), $launch_label ) : __( 'Apply when we launch', 'lnkup-theme' ) ) ); ?></li>
					<li><?php esc_html_e( 'Pay only when accepted', 'lnkup-theme' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( is_user_logged_in() ? $profile_url : $signup_url ); ?>" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Sign up as vendor', 'lnkup-theme' ); ?></a>
			</article>
			<article class="lnkup-card lnkup-audience-card">
				<div class="lnkup-audience-card__icon lnkup-audience-card__icon--organizer" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
				</div>
				<h3 class="lnkup-audience-card__title"><?php esc_html_e( 'Organizers', 'lnkup-theme' ); ?></h3>
				<p class="lnkup-audience-card__desc"><?php esc_html_e( 'Event producers who need a vendor layer — not ticketing. Keep selling tickets on Posh, Eventbrite, and Universe.', 'lnkup-theme' ); ?></p>
				<ul class="lnkup-audience-card__list">
					<li><?php esc_html_e( 'Sign up and launch vendor opportunities', 'lnkup-theme' ); ?></li>
					<li><?php esc_html_e( 'Review applications and collect payments', 'lnkup-theme' ); ?></li>
					<li><?php esc_html_e( 'Run day-of operations from one hub', 'lnkup-theme' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( is_user_logged_in() ? $organizer_url : $signup_url ); ?>" class="lnkup-btn lnkup-btn--outline"><?php esc_html_e( 'Sign up as organizer', 'lnkup-theme' ); ?></a>
			</article>
		</div>
	</div>
</section>
