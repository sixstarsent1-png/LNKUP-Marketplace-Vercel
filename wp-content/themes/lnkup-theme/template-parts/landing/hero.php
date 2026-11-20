<?php
/**
 * Hero section partial.
 *
 * @package LNKup_Theme
 */

$signup_url        = lnkup_theme_registration_url();
$opportunities_url = lnkup_theme_opportunities_url();
$hero_image        = lnkup_landing_image_url( 'hero_image_id', 'images/marketing/hero-home.jpg' );
$launch_label      = lnkup_theme_launch_label();
$marketplace_open  = lnkup_theme_marketplace_open();
$eyebrow           = (string) lnkup_landing_get( 'hero_eyebrow', '' );
$list_event_url    = is_user_logged_in()
	? ( function_exists( 'lnkup_organizer_view_url' ) ? lnkup_organizer_view_url( 'create' ) : lnkup_page_url( 'organizer-dashboard' ) . '?view=create' )
	: add_query_arg( 'role', 'organizer', $signup_url );
$city_counts       = function_exists( 'lnkup_homepage_city_counts' ) ? lnkup_homepage_city_counts() : array();
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
			<?php else : ?>
				<span class="lnkup-pill">
					<span class="lnkup-pill__dot"></span>
					<?php echo esc_html( $eyebrow ); ?>
				</span>
			<?php endif; ?>
			<h1 class="lnkup-heading lnkup-heading--hero"><?php esc_html_e( 'Find your next vendor opportunity.', 'lnkup-theme' ); ?></h1>
			<p class="lnkup-hero__lead"><?php esc_html_e( 'Discover events, apply for free, get approved, and secure your vendor spot. All in one place.', 'lnkup-theme' ); ?></p>
			<form class="lnkup-hero-search" method="get" action="<?php echo esc_url( $opportunities_url ); ?>" data-testid="lnkup-hero-search">
				<label>
					<?php esc_html_e( 'City', 'lnkup-theme' ); ?>
					<input type="search" name="city" placeholder="<?php esc_attr_e( 'Houston', 'lnkup-theme' ); ?>" />
				</label>
				<label>
					<?php esc_html_e( 'Vendor category', 'lnkup-theme' ); ?>
					<select name="categories">
						<option value=""><?php esc_html_e( 'Any category', 'lnkup-theme' ); ?></option>
						<?php
						$hero_categories = get_terms(
							array(
								'taxonomy'   => 'lnkup_vendor_category',
								'hide_empty' => true,
								'number'     => 40,
								'orderby'    => 'name',
							)
						);
						if ( ! is_wp_error( $hero_categories ) ) {
							foreach ( $hero_categories as $hero_category ) {
								$hero_label = function_exists( 'lnkup_display_category_badge' ) ? lnkup_display_category_badge( $hero_category->name ) : $hero_category->name;
								echo '<option value="' . esc_attr( $hero_category->name ) . '">' . esc_html( $hero_label ) . '</option>';
							}
						}
						?>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Date', 'lnkup-theme' ); ?>
					<input type="date" name="date_from" />
				</label>
				<button type="submit" class="lnkup-btn lnkup-btn--primary"><?php esc_html_e( 'Search Events', 'lnkup-theme' ); ?></button>
			</form>
			<div class="lnkup-hero-paths">
				<a class="lnkup-btn lnkup-btn--primary" href="<?php echo esc_url( $opportunities_url ); ?>"><?php esc_html_e( 'Find Vendor Opportunities', 'lnkup-theme' ); ?></a>
				<a class="lnkup-btn lnkup-btn--outline" href="<?php echo esc_url( $list_event_url ); ?>"><?php esc_html_e( 'List Your Event', 'lnkup-theme' ); ?></a>
			</div>
			<?php if ( lnkup_theme_show_app_download() ) : ?>
				<p class="lnkup-hero__app-link">
					<a class="lnkup-app-download" href="<?php echo esc_url( lnkup_theme_app_store_url( 'homepage-hero' ) ); ?>" data-testid="lnkup-hero-app-link"><?php esc_html_e( 'Also on iPhone: get the LNKup app', 'lnkup-theme' ); ?></a>
				</p>
			<?php endif; ?>
			<?php if ( ! $marketplace_open && $launch_label ) : ?>
				<p class="lnkup-hero__sublead"><?php echo esc_html( sprintf( __( 'Create your free account today. Browse and apply when we launch %s.', 'lnkup-theme' ), $launch_label ) ); ?></p>
			<?php endif; ?>
			<?php if ( $city_counts ) : ?>
				<ul class="lnkup-hero-cities">
					<?php foreach ( $city_counts as $city_row ) : ?>
						<li>
							<a href="<?php echo esc_url( $city_row['url'] ); ?>">
								<?php echo esc_html( $city_row['label'] ); ?>
								<span><?php echo esc_html( sprintf( _n( '%d open', '%d open', (int) $city_row['count'], 'lnkup-theme' ), (int) $city_row['count'] ) ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</section>
