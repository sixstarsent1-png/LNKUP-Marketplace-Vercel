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
 * Published Find Events page, or homepage #opportunities when the page is missing.
 */
function lnkup_theme_opportunities_url() {
	$page = get_page_by_path( 'opportunities' );
	if ( $page && 'publish' === $page->post_status ) {
		return get_permalink( $page );
	}
	return lnkup_theme_nav_href( 'opportunities' );
}

/**
 * Branded registration URL (plugin helper fallback).
 */
function lnkup_theme_registration_url() {
	if ( function_exists( 'lnkup_registration_url' ) ) {
		return lnkup_registration_url();
	}
	return home_url( '/sign-up/' );
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
 * Whether the mobile app public release moment has passed.
 */
function lnkup_theme_app_released() {
	return function_exists( 'lnkup_is_app_released' ) && lnkup_is_app_released();
}

/**
 * Human-readable app release label.
 *
 * @return string
 */
function lnkup_theme_app_release_label() {
	return function_exists( 'lnkup_get_app_release_label' ) ? lnkup_get_app_release_label() : '';
}

/**
 * Launch community goals from plugin settings (synced from Landing Page).
 *
 * @return array{vendors:int,organizers:int}
 */
function lnkup_theme_launch_goals() {
	if ( class_exists( 'LNKup_Theme_Landing_Settings' ) ) {
		$landing = LNKup_Theme_Landing_Settings::get_all();
		return array(
			'vendors'    => max( 0, (int) ( $landing['launch_goal_vendors'] ?? 4000 ) ),
			'organizers' => max( 0, (int) ( $landing['launch_goal_organizers'] ?? 500 ) ),
		);
	}
	return function_exists( 'lnkup_get_launch_goals' ) ? lnkup_get_launch_goals() : array( 'vendors' => 4000, 'organizers' => 500 );
}

/**
 * Get a landing page setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default.
 * @return mixed
 */
function lnkup_landing_get( $key, $default = '' ) {
	if ( class_exists( 'LNKup_Theme_Landing_Settings' ) ) {
		return LNKup_Theme_Landing_Settings::get( $key, $default );
	}
	return $default;
}

/**
 * Attachment URL with theme asset fallback.
 *
 * @param int    $attachment_id  Media library ID.
 * @param string $fallback_asset Relative path under assets/.
 * @return string
 */
function lnkup_landing_attachment_url( $attachment_id, $fallback_asset = '' ) {
	$id = absint( $attachment_id );
	if ( $id > 0 ) {
		$url = wp_get_attachment_image_url( $id, 'large' );
		if ( $url ) {
			return $url;
		}
	}
	return $fallback_asset ? lnkup_theme_asset( $fallback_asset ) : '';
}

/**
 * Landing image URL from setting key or theme asset fallback.
 *
 * @param string $setting_key    Landing setting key for attachment ID.
 * @param string $fallback_asset Relative path under assets/ (e.g. images/events/block-party.svg).
 * @return string
 */
function lnkup_landing_image_url( $setting_key, $fallback_asset = '' ) {
	return lnkup_landing_attachment_url( lnkup_landing_get( $setting_key, 0 ), $fallback_asset );
}

/**
 * Split multiline landing bullets into a clean array.
 *
 * @param string $text Raw bullets.
 * @return string[]
 */
function lnkup_landing_lines( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	$out   = array();
	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' !== $line ) {
			$out[] = $line;
		}
	}
	return $out;
}

/**
 * Resolve homepage trust stats (custom or default/live).
 *
 * @return array<int,array{value:string,label:string,sub:string,badge?:string}>
 */
function lnkup_landing_trust_stats() {
	$marketplace_open = lnkup_theme_marketplace_open();
	$launch_label     = lnkup_theme_launch_label();
	$goals            = lnkup_theme_launch_goals();
	$use_custom       = (bool) lnkup_landing_get( 'use_custom_stats', 0 );
	$custom           = lnkup_landing_get( 'stats', array() );

	if ( $use_custom && is_array( $custom ) ) {
		$out = array();
		foreach ( array_slice( $custom, 0, 4 ) as $i => $row ) {
			$value      = trim( (string) ( $row['value'] ?? '' ) );
			$sub        = trim( (string) ( $row['sub'] ?? '' ) );
			$from_goal  = false;
			if ( '' === $value ) {
				if ( 0 === $i ) {
					$value     = lnkup_theme_format_goal_number( $goals['vendors'] );
					$from_goal = true;
				} elseif ( 1 === $i ) {
					$value     = lnkup_theme_format_goal_number( $goals['organizers'] );
					$from_goal = true;
				}
			}
			if ( $from_goal && '' === $sub && $launch_label ) {
				$sub = sprintf( __( 'By %s', 'lnkup-theme' ), $launch_label );
			}
			$out[] = array(
				'value' => $value,
				'label' => (string) ( $row['label'] ?? '' ),
				'sub'   => $sub,
				'badge' => $from_goal ? __( 'Goal', 'lnkup-theme' ) : '',
			);
		}
		return $out;
	}

	if ( ! $marketplace_open ) {
		return array(
			array(
				'value' => lnkup_theme_format_goal_number( $goals['vendors'] ),
				'label' => __( 'Vendor community goal', 'lnkup-theme' ),
				'sub'   => $launch_label ? sprintf( __( 'By %s', 'lnkup-theme' ), $launch_label ) : '',
				'badge' => __( 'Goal', 'lnkup-theme' ),
			),
			array(
				'value' => lnkup_theme_format_goal_number( $goals['organizers'] ),
				'label' => __( 'Organizer community goal', 'lnkup-theme' ),
				'sub'   => $launch_label ? sprintf( __( 'By %s', 'lnkup-theme' ), $launch_label ) : '',
				'badge' => __( 'Goal', 'lnkup-theme' ),
			),
			array(
				'value' => __( 'Free', 'lnkup-theme' ),
				'label' => __( 'To join & apply', 'lnkup-theme' ),
				'sub'   => __( 'No upfront fees', 'lnkup-theme' ),
				'badge' => '',
			),
			array(
				'value' => '$0',
				'label' => __( 'Due until accepted', 'lnkup-theme' ),
				'sub'   => __( 'Pay only when approved', 'lnkup-theme' ),
				'badge' => '',
			),
		);
	}

	$stats = lnkup_theme_public_stats();
	return array(
		array(
			'value' => number_format_i18n( $stats['vendors'] ) . ( $stats['vendors'] > 0 ? '+' : '' ),
			'label' => __( 'Active vendors', 'lnkup-theme' ),
			'sub'   => '',
			'badge' => '',
		),
		array(
			'value' => number_format_i18n( $stats['organizers'] ) . ( $stats['organizers'] > 0 ? '+' : '' ),
			'label' => __( 'Organizers', 'lnkup-theme' ),
			'sub'   => '',
			'badge' => '',
		),
		array(
			'value' => number_format_i18n( $stats['cities'] ) . ( $stats['cities'] > 0 ? '+' : '' ),
			'label' => __( 'Cities', 'lnkup-theme' ),
			'sub'   => '',
			'badge' => '',
		),
		array(
			'value' => lnkup_theme_format_public_gmv( $stats['gmv'] ),
			'label' => __( 'Vendor bookings', 'lnkup-theme' ),
			'sub'   => '',
			'badge' => '',
		),
	);
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
		array( 'vendor-dashboard', 'organizer-dashboard', 'connector-dashboard', 'opportunities', 'vendor-profile', 'favorites' ),
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

/**
 * Marketing-page hash href (local on home/markets, otherwise home).
 *
 * @param string $hash Section id without #.
 */
function lnkup_theme_nav_href( $hash ) {
	$hash = ltrim( (string) $hash, '#' );
	if ( is_front_page() || get_query_var( 'lnkup_market_slug' ) ) {
		return '#' . $hash;
	}
	return home_url( '/#' . $hash );
}

/**
 * Editorial section kicker: lime rule, eyebrow, mono index.
 *
 * @param string $index Index label such as "01 / VENDORS".
 * @param string $label Eyebrow text.
 */
function lnkup_theme_section_kicker( $index, $label ) {
	?>
	<div class="lnkup-section__kicker">
		<span class="lnkup-section__kicker-bar" aria-hidden="true"></span>
		<p class="lnkup-eyebrow"><?php echo esc_html( $label ); ?></p>
		<span class="lnkup-section__index"><?php echo esc_html( $index ); ?></span>
	</div>
	<?php
}

/**
 * Market record from connector interest ?market= slug.
 *
 * @return array<string, mixed>|null
 */
function lnkup_theme_connector_market() {
	$slug = '';
	if ( ! empty( $_GET['market'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = sanitize_title( wp_unslash( (string) $_GET['market'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( $slug && class_exists( 'LNKup_Market_Pages' ) ) {
		$market = LNKup_Market_Pages::get_market_by_slug( $slug, false );
		return is_array( $market ) ? $market : null;
	}
	return null;
}
