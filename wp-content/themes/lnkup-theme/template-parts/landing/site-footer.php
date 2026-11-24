<?php
/**
 * Site footer 5.0. Only links to pages that exist; city links come from live listings.
 *
 * @package LNKup_Theme
 */

$open      = lnkup_theme_marketplace_open();
$feed      = lnkup_theme_opportunities_url();
$discovery = lnkup_public_discovery();
$cities    = array_slice( (array) ( $discovery['cities'] ?? array() ), 0, 5 );
$tagline   = (string) lnkup_public_setting( 'footer_blurb', __( 'The vendor opportunity marketplace for live events. Organizers fill booths; vendors find their next event.', 'lnkup-theme' ) );
$legal     = array(
	array( 'label' => __( 'Privacy', 'lnkup-theme' ), 'url' => home_url( '/privacy/' ) ),
	array( 'label' => __( 'Terms', 'lnkup-theme' ), 'url' => home_url( '/terms/' ) ),
	array( 'label' => __( 'Contact', 'lnkup-theme' ), 'url' => home_url( '/contact/' ) ),
);
$columns   = array(
	array(
		'title' => __( 'Vendors', 'lnkup-theme' ),
		'links' => array_filter(
			array(
				$open ? array( __( 'Find events', 'lnkup-theme' ), $feed ) : null,
				array( __( 'Why LNKup for vendors', 'lnkup-theme' ), home_url( '/for-vendors/' ) ),
				array( __( 'Vendor pricing', 'lnkup-theme' ), home_url( '/for-vendors/#pricing' ) ),
				array( __( 'Create a vendor profile', 'lnkup-theme' ), lnkup_public_signup_url( 'vendor' ) ),
			)
		),
	),
	array(
		'title' => __( 'Organizers', 'lnkup-theme' ),
		'links' => array(
			array( __( 'Why LNKup for organizers', 'lnkup-theme' ), home_url( '/for-organizers/' ) ),
			array( __( 'Organizer pricing', 'lnkup-theme' ), home_url( '/for-organizers/#pricing' ) ),
			array( __( 'Vendor directory', 'lnkup-theme' ), lnkup_page_url( 'vendors' ) ),
			array( __( 'List your event', 'lnkup-theme' ), lnkup_public_signup_url( 'organizer' ) ),
		),
	),
);
if ( $open && $cities ) {
	$city_links = array();
	foreach ( $cities as $city ) {
		$city_links[] = array( (string) $city['label'], (string) ( $city['market_url'] ? $city['market_url'] : $city['url'] ) );
	}
	$columns[] = array( 'title' => __( 'Cities', 'lnkup-theme' ), 'links' => $city_links );
}
$company = array(
	array( __( 'Organizer directory', 'lnkup-theme' ), lnkup_page_url( 'organizers' ) ),
	array( __( 'Log in', 'lnkup-theme' ), lnkup_theme_login_url() ),
);
if ( get_page_by_path( 'partners' ) ) {
	$company[] = array( __( 'Partner program', 'lnkup-theme' ), lnkup_page_url( 'partners' ) );
}
$company[] = array( __( 'Contact', 'lnkup-theme' ), home_url( '/contact/' ) );
$columns[] = array( 'title' => __( 'LNKup', 'lnkup-theme' ), 'links' => $company );
?>
<footer class="lp-footer" data-testid="lp-footer">
	<div class="lp-container">
		<div class="lp-footer__top">
			<div class="lp-footer__brand">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'LNKup home', 'lnkup-theme' ); ?>"><?php lnkup_logo(); ?></a>
				<p class="lp-footer__tagline"><?php echo esc_html( $tagline ); ?></p>
				<div class="lp-actions">
					<?php lnkup_theme_app_store_badge( 'white', 'footer' ); ?>
				</div>
			</div>
			<nav class="lp-footer__cols" aria-label="<?php esc_attr_e( 'Footer', 'lnkup-theme' ); ?>">
				<?php foreach ( $columns as $column ) : ?>
					<div class="lp-footer__col">
						<h2><?php echo esc_html( $column['title'] ); ?></h2>
						<ul>
							<?php foreach ( $column['links'] as $link ) : ?>
								<li><a href="<?php echo esc_url( $link[1] ); ?>"><?php echo esc_html( $link[0] ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</nav>
		</div>
		<div class="lp-footer__bottom">
			<p style="margin:0">&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> LNKup. <?php esc_html_e( 'LNKup is the vendor marketplace for events — not a ticketing platform.', 'lnkup-theme' ); ?></p>
			<ul>
				<?php foreach ( $legal as $link ) : ?>
					<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</footer>
