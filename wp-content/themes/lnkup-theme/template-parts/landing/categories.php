<?php
/**
 * Vendor categories section.
 *
 * @package LNKup_Theme
 */

$eyebrow    = (string) lnkup_landing_get( 'cat_eyebrow', '' );
$title      = (string) lnkup_landing_get( 'cat_title', '' );
$aside      = (string) lnkup_landing_get( 'cat_aside', '' );
$categories = lnkup_landing_lines( (string) lnkup_landing_get( 'cat_list', '' ) );
$banner     = lnkup_landing_image_url( 'cat_image_id', 'images/events/sunset-miami.svg' );
$feed_url   = function_exists( 'lnkup_theme_opportunities_url' ) ? lnkup_theme_opportunities_url() : home_url( '/opportunities/' );
$cities     = ( lnkup_theme_marketplace_open() && function_exists( 'lnkup_homepage_city_counts' ) ) ? lnkup_homepage_city_counts() : array();
?>
<section id="categories" class="lnkup-section lnkup-categories-section lnkup-reveal">
	<?php if ( $banner ) : ?>
		<div class="lnkup-categories-section__banner" aria-hidden="true">
			<img src="<?php echo esc_url( $banner ); ?>" alt="" width="1600" height="480" loading="lazy" />
			<span class="lnkup-categories-section__scrim"></span>
		</div>
	<?php endif; ?>
	<div class="lnkup-container">
		<div class="lnkup-section__header-row">
			<div class="lnkup-section__intro">
				<?php lnkup_theme_section_kicker( '03 / CATEGORIES', $eyebrow ); ?>
				<h2 class="lnkup-heading lnkup-heading--section"><?php echo esc_html( $title ); ?></h2>
			</div>
			<?php if ( $aside ) : ?>
				<p class="lnkup-section__aside"><?php echo esc_html( $aside ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $cities ) : ?>
			<h3 class="lnkup-browse__label"><?php esc_html_e( 'By city', 'lnkup-theme' ); ?></h3>
			<ul class="lnkup-city-tiles" data-testid="lnkup-home-cities">
				<?php foreach ( array_slice( $cities, 0, 8 ) as $city_row ) : ?>
					<li>
						<a class="lnkup-city-tile" href="<?php echo esc_url( $city_row['url'] ); ?>">
							<span class="lnkup-city-tile__name"><?php echo esc_html( $city_row['label'] ); ?></span>
							<span class="lnkup-city-tile__count"><?php echo esc_html( sprintf( _n( '%d open listing', '%d open listings', (int) $city_row['count'], 'lnkup-theme' ), (int) $city_row['count'] ) ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<h3 class="lnkup-browse__label"><?php esc_html_e( 'By what you sell', 'lnkup-theme' ); ?></h3>
		<?php endif; ?>
		<div class="lnkup-categories">
			<?php foreach ( $categories as $cat ) : ?>
				<?php
				$cat_label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( $cat ) : $cat;
				if ( '' === $cat_label ) {
					continue;
				}
				$cat_href = add_query_arg( 'categories', $cat_label, $feed_url );
				?>
				<a class="lnkup-category" href="<?php echo esc_url( $cat_href ); ?>">
					<span class="lnkup-category__icon" aria-hidden="true"><?php echo lnkup_theme_category_icon( $cat_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<span><?php echo esc_html( $cat_label ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
