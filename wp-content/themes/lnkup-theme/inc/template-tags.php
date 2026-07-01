<?php
/**
 * Template tags and helpers.
 *
 * @package LNKup_Theme
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the LNKup logo.
 *
 * @param bool $show_wordmark Whether to show wordmark.
 */
function lnkup_logo( $show_wordmark = true ) {
	?>
	<span class="lnkup-logo">
		<span class="lnkup-logo__mark" aria-hidden="true">
			<svg viewBox="0 0 24 24" fill="none" class="lnkup-logo__icon">
				<path d="M7 13.5 12 8l5 5.5M5 17l3.5-4M19 17l-3.5-4" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</span>
		<?php if ( $show_wordmark ) : ?>
			<span class="lnkup-logo__text">LNKup</span>
		<?php endif; ?>
	</span>
	<?php
}

/**
 * Get a theme asset URL.
 *
 * @param string $path Relative path from assets/.
 */
function lnkup_theme_asset( $path ) {
	$url = LNKUP_THEME_URI . '/assets/' . ltrim( $path, '/' );
	return add_query_arg( 'ver', LNKUP_THEME_VERSION, $url );
}

/**
 * Get page URL by slug with fallback.
 *
 * @param string $slug Page slug.
 */
function lnkup_page_url( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

/**
 * Branded registration URL (plugin helper fallback).
 */
function lnkup_theme_registration_url() {
	if ( function_exists( 'lnkup_registration_url' ) ) {
		return lnkup_registration_url();
	}
	return wp_registration_url();
}

/**
 * Whether marketplace browse/apply is open.
 */
function lnkup_theme_marketplace_open() {
	return function_exists( 'lnkup_is_marketplace_open' ) && lnkup_is_marketplace_open();
}

/**
 * Launch date label for marketing copy.
 */
function lnkup_theme_launch_label() {
	return function_exists( 'lnkup_get_launch_date_label' ) ? lnkup_get_launch_date_label() : '';
}

/**
 * ISO launch datetime for countdown JS.
 */
function lnkup_theme_launch_iso() {
	return function_exists( 'lnkup_get_launch_datetime_iso' ) ? lnkup_get_launch_datetime_iso() : '';
}

/**
 * Launch community goals from plugin settings.
 *
 * @return array{vendors:int,organizers:int}
 */
function lnkup_theme_launch_goals() {
	return function_exists( 'lnkup_get_launch_goals' ) ? lnkup_get_launch_goals() : array( 'vendors' => 4000, 'organizers' => 500 );
}

/**
 * Live public marketplace stats (post-launch).
 *
 * @return array{vendors:int,organizers:int,cities:int,gmv:float}
 */
function lnkup_theme_public_stats() {
	return function_exists( 'lnkup_get_marketplace_public_stats' ) ? lnkup_get_marketplace_public_stats() : array( 'vendors' => 0, 'organizers' => 0, 'cities' => 0, 'gmv' => 0.0 );
}

/**
 * Format launch goal number for display.
 *
 * @param int $number Number.
 */
function lnkup_theme_format_goal_number( $number ) {
	$number = max( 0, (int) $number );
	return number_format_i18n( $number ) . '+';
}

/**
 * Format GMV for public stat card.
 *
 * @param float $gmv Amount.
 */
function lnkup_theme_format_public_gmv( $gmv ) {
	$gmv = (float) $gmv;
	if ( $gmv >= 1000000 ) {
		return '$' . number_format_i18n( $gmv / 1000000, 1 ) . 'M';
	}
	if ( $gmv >= 1000 ) {
		return '$' . number_format_i18n( $gmv / 1000, 1 ) . 'K';
	}
	return function_exists( 'lnkup_currency' ) ? lnkup_currency( $gmv ) : '$' . number_format_i18n( $gmv, 2 );
}

/**
 * Check if current page uses a dashboard template.
 */
function lnkup_theme_is_dashboard_page() {
	if ( ! is_page() ) {
		return false;
	}
	$slug = get_post_field( 'post_name', get_queried_object_id() );
	if ( in_array(
		$slug,
		array( 'vendor-dashboard', 'organizer-dashboard', 'connector-dashboard', 'opportunities', 'vendor-profile' ),
		true
	) ) {
		return true;
	}
	$template = get_page_template_slug();
	return in_array(
		$template,
		array(
			'page-templates/vendor-dashboard.php',
			'page-templates/vendor-shell.php',
			'page-templates/organizer-dashboard.php',
			'page-templates/connector-dashboard.php',
		),
		true
	);
}

/**
 * Output a button link.
 *
 * @param string $url    URL.
 * @param string $label  Label.
 * @param string $class  CSS classes.
 */
function lnkup_button_link( $url, $label, $class = 'lnkup-btn lnkup-btn--primary' ) {
	printf(
		'<a href="%s" class="%s">%s</a>',
		esc_url( $url ),
		esc_attr( $class ),
		esc_html( $label )
	);
}

/**
 * Ensure inline SVG icons have explicit dimensions.
 *
 * @param string $svg  SVG markup.
 * @param int    $size Width and height in pixels.
 */
function lnkup_inline_icon( $svg, $size = 18 ) {
	$size = max( 12, min( 48, (int) $size ) );
	$style = sprintf(
		'width:%1$dpx;height:%1$dpx;max-width:%1$dpx;max-height:%1$dpx;flex-shrink:0;display:block;',
		$size
	);

	if ( ! preg_match( '/<svg([^>]*)>/i', $svg, $matches ) ) {
		return $svg;
	}

	$attrs = $matches[1];
	$attrs = preg_replace( '/\swidth="[^"]*"/i', '', $attrs );
	$attrs = preg_replace( '/\sheight="[^"]*"/i', '', $attrs );
	$attrs = preg_replace( '/\sstyle="[^"]*"/i', '', $attrs );
	$attrs = trim( $attrs );

	$replacement = sprintf(
		'<svg %s width="%2$d" height="%2$d" style="%3$s" class="lnkup-icon" role="img" aria-hidden="true">',
		$attrs,
		$size,
		esc_attr( $style )
	);

	return preg_replace( '/<svg[^>]*>/i', $replacement, $svg, 1 );
}

/**
 * City market page context from plugin registry.
 *
 * @return array<string, mixed>|null
 */
function lnkup_theme_market_context() {
	if ( class_exists( 'LNKup_Market_Pages' ) ) {
		$ctx = LNKup_Market_Pages::get_context();
		if ( $ctx ) {
			return $ctx;
		}
		$slug = LNKup_Market_Pages::current_slug();
		if ( $slug ) {
			return LNKup_Market_Pages::get_market_page_data( $slug );
		}
	}
	return null;
}

/**
 * Market-aware sign-up URL for vendor or organizer.
 *
 * @param string $role vendor|organizer.
 */
function lnkup_theme_market_signup_url( $role = 'vendor' ) {
	$ctx = lnkup_theme_market_context();
	if ( $ctx && class_exists( 'LNKup_Market_Pages' ) ) {
		return LNKup_Market_Pages::get_market_signup_url( $ctx['market']['slug'], $role );
	}
	$url = lnkup_theme_registration_url();
	return add_query_arg( 'role', 'organizer' === $role ? 'organizer' : 'vendor', $url );
}
