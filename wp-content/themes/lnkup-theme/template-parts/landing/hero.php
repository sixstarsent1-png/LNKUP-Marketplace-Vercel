<?php
/**
 * Hero section partial.
 *
 * One vendor action in the first viewport (a city search) next to real events booking vendors
 * right now. The event stack only shows events with their own artwork; without them the photo
 * carries the hero.
 *
 * @package LNKup_Theme
 */

$opportunities_url = lnkup_theme_opportunities_url();
$hero_image        = lnkup_landing_image_url( 'hero_image_id', 'images/marketing/hero-home.jpg' );
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();
$eyebrow           = (string) lnkup_landing_get( 'hero_eyebrow', '' );
$city_counts       = ( $marketplace_open && function_exists( 'lnkup_homepage_city_counts' ) ) ? lnkup_homepage_city_counts() : array();
$stack             = ( $marketplace_open && function_exists( 'lnkup_public_event_summaries' ) ) ? lnkup_public_event_summaries( 3, true ) : array();
$city_hint         = $city_counts ? (string) $city_counts[0]['label'] : __( 'Charlotte', 'lnkup-theme' );
?>
<section class="lnkup-hero lnkup-hero--cinematic lnkup-hero--stage lnkup-reveal<?php echo $stack ? ' has-stack' : ''; ?>">
	<?php
	get_template_part(
		'template-parts/landing/hero-canvas',
		null,
		array(
			'photo'     => $hero_image,
			'show_orbs' => false,
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
			<?php elseif ( $city_counts ) : ?>
				<span class="lnkup-pill lnkup-pill--live">
					<span class="lnkup-pill__dot" aria-hidden="true"></span>
					<?php
					$names = wp_list_pluck( array_slice( $city_counts, 0, 2 ), 'label' );
					$more  = count( $city_counts ) - count( $names );
					echo esc_html(
						$more > 0
							? sprintf( /* translators: 1: city names, 2: number of other cities */ __( 'Booking vendors now in %1$s +%2$d', 'lnkup-theme' ), implode( ', ', $names ), $more )
							: sprintf( /* translators: %s: city names */ __( 'Booking vendors now in %s', 'lnkup-theme' ), implode( ' & ', $names ) )
					);
					?>
				</span>
			<?php elseif ( $eyebrow ) : ?>
				<span class="lnkup-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
			<?php endif; ?>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php esc_html_e( 'Find your next vendor spot.', 'lnkup-theme' ); ?></h1>
			<p class="lnkup-hero__lead"><?php esc_html_e( 'Festivals, markets, and live events. Apply for free. Pay the booth fee only after an organizer accepts you.', 'lnkup-theme' ); ?></p>
			<form class="lnkup-hero-search lnkup-hero-search--simple" method="get" action="<?php echo esc_url( $opportunities_url ); ?>" role="search" data-testid="lnkup-hero-search">
				<label class="lnkup-hero-search__field">
					<span class="lnkup-hero-search__label"><?php esc_html_e( 'City', 'lnkup-theme' ); ?></span>
					<input type="search" name="city" list="lnkup-hero-cities" autocomplete="address-level2" placeholder="<?php echo esc_attr( sprintf( /* translators: %s: example city */ __( 'Try %s', 'lnkup-theme' ), $city_hint ) ); ?>" />
				</label>
				<?php if ( $city_counts ) : ?>
					<datalist id="lnkup-hero-cities">
						<?php foreach ( $city_counts as $city_row ) : ?>
							<option value="<?php echo esc_attr( $city_row['label'] ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				<?php endif; ?>
				<button type="submit" class="lnkup-btn lnkup-btn--primary" data-testid="lnkup-hero-cta-primary"><?php esc_html_e( 'Find spots', 'lnkup-theme' ); ?></button>
			</form>
			<?php if ( $city_counts ) : ?>
				<nav class="lnkup-hero__cities" aria-label="<?php esc_attr_e( 'Cities with open vendor spots', 'lnkup-theme' ); ?>">
					<?php foreach ( array_slice( $city_counts, 0, 4 ) as $city_row ) : ?>
						<a href="<?php echo esc_url( $city_row['url'] ); ?>"><?php echo esc_html( $city_row['label'] ); ?> <span><?php echo esc_html( number_format_i18n( (int) $city_row['count'] ) ); ?></span></a>
					<?php endforeach; ?>
					<a class="lnkup-hero__cities-all" href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'All open spots', 'lnkup-theme' ); ?> <span aria-hidden="true">→</span></a>
				</nav>
			<?php endif; ?>
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<p class="lnkup-hero__sublead"><?php echo esc_html( sprintf( __( 'Create your free account today. Browse and apply when we launch %s.', 'lnkup-theme' ), $launch_label ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $stack ) : ?>
			<ul class="lnkup-hero__stack" aria-label="<?php esc_attr_e( 'Events booking vendors now', 'lnkup-theme' ); ?>" data-testid="lnkup-hero-stack">
				<?php foreach ( $stack as $i => $event ) : ?>
					<li class="lnkup-poster lnkup-poster--<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
						<a class="lnkup-poster__link" href="<?php echo esc_url( $event['view_url'] ); ?>">
							<span class="lnkup-poster__art">
								<img class="lnkup-poster__blur" src="<?php echo esc_url( $event['img'] ); ?>" alt="" aria-hidden="true" loading="<?php echo 0 === $i ? 'eager' : 'lazy'; ?>" decoding="async" />
								<img class="lnkup-poster__img" src="<?php echo esc_url( $event['img'] ); ?>" alt="<?php echo esc_attr( $event['img_alt'] ); ?>" loading="<?php echo 0 === $i ? 'eager' : 'lazy'; ?>" decoding="async" />
							</span>
							<span class="lnkup-poster__body">
								<span class="lnkup-poster__title"><?php echo esc_html( $event['title'] ); ?></span>
								<span class="lnkup-poster__meta"><?php echo esc_html( trim( $event['weekday'] . ' ' . $event['when_short'] . ( $event['place'] ? ' · ' . $event['place'] : '' ) ) ); ?></span>
								<span class="lnkup-poster__fee lnkup-fee lnkup-fee--<?php echo esc_attr( $event['fee_kind'] ); ?>"><?php echo esc_html( $event['fee_label'] ); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</section>
