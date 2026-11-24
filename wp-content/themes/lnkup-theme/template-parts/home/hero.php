<?php
/**
 * Homepage hero: headline, role-aware search, quick links, live counts.
 *
 * @package LNKup_Theme
 */

$open      = lnkup_theme_marketplace_open();
$discovery = lnkup_public_discovery();
$stats     = (array) ( $discovery['stats'] ?? array() );
$title     = (string) lnkup_public_setting( 'home_title', __( 'Where live events and vendors find each other.', 'lnkup-theme' ) );
$lead      = (string) lnkup_public_setting( 'home_lead', __( 'Vendors discover festivals, markets and pop-ups that are booking now. Organizers fill every booth, collect fees and run load-in from one place.', 'lnkup-theme' ) );
$eyebrow   = (string) lnkup_public_setting( 'home_eyebrow', __( 'The vendor marketplace for events', 'lnkup-theme' ) );
$image_id  = (int) lnkup_landing_get( 'hero_image_id', 0 );
$image     = $image_id ? $image_id : 'images/marketing/hero-home.jpg';
$chips     = array();
foreach ( array_slice( (array) ( $discovery['categories'] ?? array() ), 0, 3 ) as $cat ) {
	$chips[] = array( 'label' => (string) $cat['label'], 'count' => (int) $cat['count'], 'url' => (string) $cat['url'] );
}
foreach ( array_slice( (array) ( $discovery['cities'] ?? array() ), 0, 3 ) as $city ) {
	$chips[] = array( 'label' => (string) $city['label'], 'count' => (int) $city['count'], 'url' => (string) ( $city['market_url'] ? $city['market_url'] : $city['url'] ) );
}
$stat_rows = array_filter(
	array(
		(int) ( $stats['open_events'] ?? 0 ) > 0 ? array( (int) $stats['open_events'], _n( 'Event booking vendors', 'Events booking vendors', (int) $stats['open_events'], 'lnkup-theme' ) ) : null,
		(int) ( $stats['open_opportunities'] ?? 0 ) > 0 ? array( (int) $stats['open_opportunities'], _n( 'Open vendor opportunity', 'Open vendor opportunities', (int) $stats['open_opportunities'], 'lnkup-theme' ) ) : null,
		(int) ( $stats['cities'] ?? 0 ) > 0 ? array( (int) $stats['cities'], _n( 'City with open events', 'Cities with open events', (int) $stats['cities'], 'lnkup-theme' ) ) : null,
		(int) ( $stats['categories'] ?? 0 ) > 0 ? array( (int) $stats['categories'], _n( 'Vendor category wanted', 'Vendor categories wanted', (int) $stats['categories'], 'lnkup-theme' ) ) : null,
	)
);
?>
<section class="lp-hero" aria-labelledby="lp-hero-title" data-testid="lp-home-hero">
	<div class="lp-hero__media">
		<?php echo lnkup_public_image( $image, array( 'alt' => '', 'eager' => true, 'width' => 1920, 'height' => 1080, 'sizes' => '100vw' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<div class="lp-container lp-hero__inner">
		<div class="lp-hero__copy">
			<?php if ( ! $open && lnkup_theme_launch_label() ) : ?>
				<p class="lp-chip lp-chip--lime" data-testid="lnkup-hero-launch-pill"><?php echo esc_html( sprintf( /* translators: %s: launch date */ __( 'Launching %s', 'lnkup-theme' ), lnkup_theme_launch_label() ) ); ?></p>
			<?php endif; ?>
			<p class="lp-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<h1 class="lp-h1" id="lp-hero-title"><?php echo esc_html( $title ); ?></h1>
			<p class="lp-lead"><?php echo esc_html( $lead ); ?></p>
			<?php if ( ! $open ) : ?>
				<div class="lp-actions lp-hero__ctas">
					<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor' ) ); ?>" data-testid="lnkup-hero-cta-primary"><?php esc_html_e( 'Create a free vendor profile', 'lnkup-theme' ); ?></a>
					<a class="lp-btn lp-btn--on-dark lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'organizer' ) ); ?>"><?php esc_html_e( 'List your event', 'lnkup-theme' ); ?></a>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $open ) : ?>
			<?php get_template_part( 'template-parts/public/search', null, array( 'id' => 'lp-home-search', 'mode' => 'events' ) ); ?>
			<?php if ( $chips ) : ?>
				<nav class="lp-search__chips" aria-label="<?php esc_attr_e( 'Popular right now', 'lnkup-theme' ); ?>">
					<?php foreach ( $chips as $chip ) : ?>
						<a href="<?php echo esc_url( $chip['url'] ); ?>"><?php echo esc_html( $chip['label'] ); ?> <span><?php echo esc_html( number_format_i18n( $chip['count'] ) ); ?></span></a>
					<?php endforeach; ?>
				</nav>
			<?php endif; ?>
			<div class="lp-actions">
				<a class="lp-btn lp-btn--lime lp-btn--lg" href="<?php echo esc_url( lnkup_public_signup_url( 'vendor' ) ); ?>" data-testid="lnkup-hero-cta-primary"><?php esc_html_e( "I'm a vendor", 'lnkup-theme' ); ?></a>
				<a class="lp-btn lp-btn--on-dark lp-btn--lg" href="<?php echo esc_url( home_url( '/for-organizers/' ) ); ?>" data-testid="lp-hero-cta-organizer"><?php esc_html_e( "I'm an organizer", 'lnkup-theme' ); ?></a>
			</div>
			<p class="lp-hero__note"><?php esc_html_e( 'Free to join and apply. Vendors pay only after an organizer approves them.', 'lnkup-theme' ); ?></p>
			<?php if ( count( $stat_rows ) >= 2 ) : ?>
				<dl class="lp-stats" style="--lp-stat-cols:<?php echo (int) count( $stat_rows ); ?>" data-testid="lp-live-stats" aria-label="<?php esc_attr_e( 'Live marketplace activity', 'lnkup-theme' ); ?>">
					<?php foreach ( $stat_rows as $row ) : ?>
						<div class="lp-stats__item">
							<dt class="lp-stats__label"><?php echo esc_html( $row[1] ); ?></dt>
							<dd class="lp-stats__value"><?php echo esc_html( number_format_i18n( $row[0] ) ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
