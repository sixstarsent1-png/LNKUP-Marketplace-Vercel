<?php
/**
 * City Connector program hero.
 *
 * @package LNKup_Theme
 */

$market     = function_exists( 'lnkup_theme_connector_market' ) ? lnkup_theme_connector_market() : null;
$city_name  = is_array( $market ) ? (string) ( $market['display_name'] ?? $market['city'] ?? '' ) : '';
$hero_image = lnkup_theme_asset( 'images/marketing/hero-connector.jpg' );
$stamp      = $city_name ? strtoupper( $city_name ) . ' / CONNECTOR' : '01 / CONNECTOR';
$title      = $city_name
	? sprintf( __( '%s. Their events. Our network.', 'lnkup-theme' ), $city_name )
	: __( 'Your city. Their events. Our network.', 'lnkup-theme' );
$lead       = $city_name
	? sprintf( __( 'Become a City Connector in %s. Introduce the vendors and organizers you already know to LNKup.', 'lnkup-theme' ), $city_name )
	: __( 'Become a City Connector. Introduce the vendors and organizers you already know to LNKup.', 'lnkup-theme' );
?>
<section class="lnkup-hero lnkup-hero--cinematic lnkup-reveal">
	<?php
	get_template_part(
		'template-parts/landing/hero-canvas',
		null,
		array(
			'photo' => $hero_image,
			'stamp' => $stamp,
		)
	);
	?>
	<div class="lnkup-container lnkup-hero__grid">
		<div class="lnkup-hero__content">
			<span class="lnkup-pill">
				<span class="lnkup-pill__dot"></span>
				<?php esc_html_e( 'City Connector', 'lnkup-theme' ); ?>
			</span>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php echo esc_html( $title ); ?></h1>
			<p class="lnkup-hero__lead"><?php echo esc_html( $lead ); ?></p>
			<p class="lnkup-hero__sublead"><?php esc_html_e( 'You receive 10% commission on the booking for 12 months.', 'lnkup-theme' ); ?></p>
			<div class="lnkup-hero__cta">
				<a href="#apply" class="lnkup-btn lnkup-btn--primary lnkup-btn--lg"><?php esc_html_e( 'Apply to connect', 'lnkup-theme' ); ?></a>
				<a href="#how-it-works" class="lnkup-btn lnkup-btn--outline lnkup-btn--lg"><?php esc_html_e( 'See how it works', 'lnkup-theme' ); ?></a>
			</div>
		</div>
	</div>
</section>
