<?php
/**
 * Hero section partial.
 *
 * One vendor action in the first viewport: a city search.
 *
 * @package LNKup_Theme
 */

$opportunities_url = lnkup_theme_opportunities_url();
$hero_image        = lnkup_landing_image_url( 'hero_image_id', 'images/marketing/hero-home.jpg' );
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();
$eyebrow           = (string) lnkup_landing_get( 'hero_eyebrow', '' );
?>
<section class="lnkup-hero lnkup-hero--cinematic lnkup-reveal">
	<?php
	get_template_part(
		'template-parts/landing/hero-canvas',
		null,
		array(
			'photo' => $hero_image,
			'stamp' => 'LNK / MARKETPLACE',
		)
	);
	?>
	<div class="lnkup-container lnkup-hero__grid">
		<div class="lnkup-hero__content">
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<span class="lnkup-pill lnkup-pill--launch" data-testid="lnkup-hero-launch-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( sprintf( __( 'Launching %s', 'lnkup-theme' ), $launch_label ) ); ?>
				</span>
			<?php elseif ( $eyebrow ) : ?>
				<span class="lnkup-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
			<?php endif; ?>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php esc_html_e( 'Find your next vendor spot.', 'lnkup-theme' ); ?></h1>
			<p class="lnkup-hero__lead"><?php esc_html_e( 'Festivals, markets, and live events. Apply for free. Pay the booth fee only after an organizer accepts you.', 'lnkup-theme' ); ?></p>
			<form class="lnkup-hero-search lnkup-hero-search--simple" method="get" action="<?php echo esc_url( $opportunities_url ); ?>" data-testid="lnkup-hero-search">
				<label>
					<?php esc_html_e( 'City', 'lnkup-theme' ); ?>
					<input type="search" name="city" placeholder="<?php esc_attr_e( 'Houston', 'lnkup-theme' ); ?>" />
				</label>
				<button type="submit" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-hero-cta-primary"><?php esc_html_e( 'Search', 'lnkup-theme' ); ?></button>
			</form>
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<p class="lnkup-hero__sublead"><?php echo esc_html( sprintf( __( 'Create your free account today. Browse and apply when we launch %s.', 'lnkup-theme' ), $launch_label ) ); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
