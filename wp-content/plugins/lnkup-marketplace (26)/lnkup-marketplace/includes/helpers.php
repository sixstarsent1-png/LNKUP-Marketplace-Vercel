<?php
/**
 * Helper functions.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the public marketplace (browse/apply) is open.
 */
function lnkup_is_marketplace_open() {
	$settings = lnkup_get_settings();
	if ( empty( $settings['platform_prelaunch_mode'] ) ) {
		return true;
	}
	$launch = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return true;
	}
	$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
	try {
		$launch_dt = new DateTimeImmutable( $launch . ' 00:00:00', new DateTimeZone( $tz_string ) );
	} catch ( Exception $e ) {
		return true;
	}
	return time() >= $launch_dt->getTimestamp();
}

/**
 * Whether LNKup admins may preview the marketplace before public launch.
 */
function lnkup_prelaunch_admin_preview_enabled() {
	$settings = lnkup_get_settings();
	return ! empty( $settings['platform_prelaunch_admin_preview'] );
}

/**
 * Whether the current user may browse/apply (includes admin pre-launch preview).
 *
 * @param int $user_id User ID; defaults to current user.
 */
function lnkup_can_browse_marketplace( $user_id = 0 ) {
	if ( lnkup_is_marketplace_open() ) {
		return true;
	}
	if ( ! lnkup_prelaunch_admin_preview_enabled() ) {
		return false;
	}
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	return LNKup_Capabilities::user_has_manage_all( $user_id );
}

/**
 * Whether the viewer is using admin pre-launch preview (not public launch).
 *
 * @param int $user_id User ID; defaults to current user.
 */
function lnkup_is_prelaunch_admin_preview( $user_id = 0 ) {
	return ! lnkup_is_marketplace_open() && lnkup_can_browse_marketplace( $user_id );
}

/**
 * Whether a vendor can discover an opportunity in search/alerts.
 *
 * @param int $user_id        Vendor user ID.
 * @param int $opportunity_id Opportunity post ID.
 */
function lnkup_vendor_can_discover_opportunity( $user_id, $opportunity_id ) {
	$user_id        = absint( $user_id );
	$opportunity_id = absint( $opportunity_id );
	if ( ! $user_id || ! $opportunity_id ) {
		return false;
	}

	if ( ! lnkup_can_browse_marketplace( $user_id ) ) {
		return false;
	}

	$opp = get_post( $opportunity_id );
	if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type || 'publish' !== $opp->post_status ) {
		return false;
	}

	if ( get_post_meta( $opportunity_id, '_lnkup_invite_only', true ) ) {
		if ( ! class_exists( 'LNKup_Vendor_Invites' ) || ! LNKup_Vendor_Invites::vendor_has_invite_for_opportunity( $user_id, $opportunity_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Branded sign-up page URL.
 */
function lnkup_registration_url() {
	return lnkup_page_url_helper( 'sign-up' );
}

/**
 * Human-readable platform launch date.
 */
function lnkup_get_launch_date_label() {
	$settings = lnkup_get_settings();
	$launch   = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return '';
	}
	$ts = strtotime( $launch . ' 12:00:00' );
	if ( ! $ts ) {
		return $launch;
	}
	return date_i18n( 'F j, Y', $ts );
}

/**
 * ISO launch datetime for countdown scripts.
 */
function lnkup_get_launch_datetime_iso() {
	$settings = lnkup_get_settings();
	$launch   = isset( $settings['platform_launch_date'] ) ? (string) $settings['platform_launch_date'] : '';
	if ( '' === $launch ) {
		return '';
	}
	$tz_string = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
	try {
		$launch_dt = new DateTimeImmutable( $launch . ' 00:00:00', new DateTimeZone( $tz_string ) );
	} catch ( Exception $e ) {
		return '';
	}
	return $launch_dt->format( DATE_ATOM );
}

/**
 * Whether an opportunity row passes homepage curation rules.
 *
 * @param array $opp Opportunity row.
 */
function lnkup_passes_homepage_curation( $opp ) {
	if ( ! is_array( $opp ) ) {
		return false;
	}
	$city = trim( (string) ( $opp['city'] ?? '' ) );
	$state = trim( (string) ( $opp['state'] ?? '' ) );
	if ( '' === $city || '' === $state ) {
		return false;
	}
	if ( 'Location TBA' === $city ) {
		return false;
	}
	$fee = (float) ( $opp['vendor_fee'] ?? 0 );
	if ( $fee > 0 && $fee < 20 ) {
		return false;
	}
	return true;
}

/**
 * Configured community goals for pre-launch marketing.
 *
 * @return array{vendors:int,organizers:int}
 */
function lnkup_get_launch_goals() {
	$settings = lnkup_get_settings();
	return array(
		'vendors'    => max( 0, (int) ( $settings['launch_goal_vendors'] ?? 4000 ) ),
		'organizers' => max( 0, (int) ( $settings['launch_goal_organizers'] ?? 500 ) ),
	);
}

/**
 * Live marketplace totals for post-launch public display.
 *
 * @return array{vendors:int,organizers:int,cities:int,gmv:float}
 */
function lnkup_get_marketplace_public_stats() {
	global $wpdb;

	$vendor_counts = count_users( array( 'role' => 'lnkup_vendor' ) );
	$org_counts    = count_users( array( 'role' => 'lnkup_organizer' ) );

	$vendors    = isset( $vendor_counts['avail_roles']['lnkup_vendor'] ) ? (int) $vendor_counts['avail_roles']['lnkup_vendor'] : 0;
	$organizers = isset( $org_counts['avail_roles']['lnkup_organizer'] ) ? (int) $org_counts['avail_roles']['lnkup_organizer'] : 0;

	$cities = 0;
	if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
		$table  = $wpdb->prefix . 'lnkup_marketplace_index';
		$cities = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT city) FROM {$table} WHERE city IS NOT NULL AND city != '' AND city != 'Location TBA'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	$gmv = 0.0;
	if ( class_exists( 'LNKup_Financial_Tables' ) ) {
		$tx_table = LNKup_Financial_Tables::transactions_table();
		$gmv      = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(gross_amount), 0) FROM {$tx_table} WHERE status IN ('completed','paid','succeeded')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
	if ( $gmv <= 0 ) {
		$paid_payments = get_posts(
			array(
				'post_type'      => 'lnkup_payment',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_lnkup_payment_status',
						'value' => 'paid',
					),
				),
			)
		);
		foreach ( $paid_payments as $payment_id ) {
			$gmv += (float) get_post_meta( $payment_id, '_lnkup_amount', true );
		}
	}

	return array(
		'vendors'    => $vendors,
		'organizers' => $organizers,
		'cities'     => $cities,
		'gmv'        => $gmv,
	);
}

/**
 * Format currency.
 *
 * @param float $amount Amount.
 */
function lnkup_currency( $amount ) {
	return '$' . number_format( (float) $amount, 2 );
}

/**
 * Get vendor profile for user.
 *
 * @param int $user_id User ID.
 */
function lnkup_get_vendor_profile_id( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	$posts = get_posts(
		array(
			'post_type'      => 'lnkup_vendor_profile',
			'posts_per_page' => 1,
			'meta_key'       => '_lnkup_owner_user_id',
			'meta_value'     => $user_id,
			'fields'         => 'ids',
		)
	);
	return $posts[0] ?? 0;
}

/**
 * Get featured opportunities.
 *
 * @param int   $limit Limit.
 * @param array $args  Optional: curate_homepage (bool).
 */
function lnkup_get_featured_opportunities( $limit = 6, $args = array() ) {
	$curate = ! empty( $args['curate_homepage'] );
	$fetch  = max( $limit, $curate ? $limit * 4 : $limit );

	if ( lnkup_marketplace_phase_at_least( '1b' ) && class_exists( 'LNKup_Trending' ) ) {
		$items = LNKup_Trending::get_section( 'trending', $fetch );
		if ( $items ) {
			if ( $curate ) {
				$items = array_values( array_filter( $items, 'lnkup_passes_homepage_curation' ) );
			}
			return array_slice( $items, 0, $limit );
		}
	}
	$posts = get_posts(
		array(
			'post_type'      => 'lnkup_opportunity',
			'posts_per_page' => $fetch,
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_lnkup_opportunity_status',
					'value' => 'open',
				),
			),
		)
	);
	$result = array();
	foreach ( $posts as $post ) {
		$row = lnkup_format_opportunity( $post );
		if ( $curate && ! lnkup_passes_homepage_curation( $row ) ) {
			continue;
		}
		$result[] = $row;
		if ( count( $result ) >= $limit ) {
			break;
		}
	}
	return $result;
}

/**
 * Filter trending section items for homepage display.
 *
 * @param array $items Items.
 * @param int   $limit Limit.
 */
function lnkup_curate_trending_section( $items, $limit = 4 ) {
	if ( ! is_array( $items ) ) {
		return array();
	}
	$filtered = array_values( array_filter( $items, 'lnkup_passes_homepage_curation' ) );
	return array_slice( $filtered, 0, $limit );
}

/**
 * Format opportunity for display.
 *
 * @param WP_Post $opp Opportunity post.
 */
function lnkup_format_opportunity( $opp ) {
	$event_id = (int) get_post_meta( $opp->ID, '_lnkup_event_id', true );
	$event    = $event_id ? get_post( $event_id ) : null;
	$terms    = wp_get_post_terms( $opp->ID, 'lnkup_vendor_category', array( 'fields' => 'names' ) );

	return array(
		'id'           => $opp->ID,
		'name'         => $event ? $event->post_title : $opp->post_title,
		'city'         => $event ? get_post_meta( $event_id, '_lnkup_city', true ) : '',
		'state'        => $event ? get_post_meta( $event_id, '_lnkup_state', true ) : '',
		'date'         => $event ? get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
		'attendance'   => $event ? (int) get_post_meta( $event_id, '_lnkup_expected_attendance', true ) : 0,
		'vendor_fee'   => (float) get_post_meta( $opp->ID, '_lnkup_vendor_fee', true ),
		'spots_remaining' => (int) get_post_meta( $opp->ID, '_lnkup_spots_remaining', true ),
		'total_spots'  => (int) get_post_meta( $opp->ID, '_lnkup_spots_total', true ),
		'categories'   => is_array( $terms ) ? $terms : array(),
		'deadline'     => get_post_meta( $opp->ID, '_lnkup_application_deadline', true ),
		'opportunity_id' => $opp->ID,
		'event_id'     => $event_id,
	);
}

/**
 * Pick theme illustration filename for an opportunity row.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_opportunity_illustration_filename( $opp ) {
	$haystack = strtolower(
		trim(
			( $opp['name'] ?? '' ) . ' ' .
			( $opp['event_name'] ?? '' ) . ' ' .
			( $opp['city'] ?? '' ) . ' ' .
			( $opp['opportunity_title'] ?? '' ) . ' ' .
			( $opp['event_type_label'] ?? '' ) . ' ' .
			( $opp['event_type'] ?? '' )
		)
	);
	if ( false !== strpos( $haystack, 'rodeo' ) ) {
		return 'rodeo.svg';
	}
	if ( false !== strpos( $haystack, 'miami' ) || false !== strpos( $haystack, 'sunset' ) ) {
		return 'sunset-miami.svg';
	}
	if ( false !== strpos( $haystack, 'greek' ) || false !== strpos( $haystack, 'picnic' ) || false !== strpos( $haystack, 'homecoming' ) ) {
		return 'greek-picnic.svg';
	}
	if ( false !== strpos( $haystack, 'ghoe' ) || false !== strpos( $haystack, 'market' ) ) {
		return 'ghoe-market.svg';
	}
	return 'block-party.svg';
}

/**
 * Card image URL: attachment or themed SVG illustration.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_opportunity_illustration_url( $opp ) {
	if ( ! empty( $opp['featured_image'] ) ) {
		return (string) $opp['featured_image'];
	}
	$file = lnkup_opportunity_illustration_filename( $opp );
	$ver  = defined( 'LNKUP_THEME_VERSION' ) ? LNKUP_THEME_VERSION : LNKUP_VERSION;
	$url  = get_theme_file_uri( 'assets/images/events/' . $file );
	return add_query_arg( 'ver', $ver, $url );
}

/**
 * Card image for external (unclaimed) events: remote URL or themed illustration.
 *
 * @param array<string, mixed> $row External event DTO.
 */
function lnkup_external_event_illustration_url( $row ) {
	if ( ! empty( $row['image_url'] ) ) {
		$url = (string) $row['image_url'];
		if ( class_exists( 'LNKup_External_Source_Eventbrite' ) ) {
			$sanitized = LNKup_External_Source_Eventbrite::sanitize_image_url( $url );
			if ( '' !== $sanitized ) {
				return $sanitized;
			}
		} elseif ( str_starts_with( strtolower( $url ), 'https://' ) ) {
			return $url;
		}
	}
	return lnkup_opportunity_illustration_url(
		array(
			'name'             => $row['title'] ?? '',
			'event_name'       => $row['title'] ?? '',
			'city'             => $row['city'] ?? '',
			'event_type_label' => $row['event_type_label'] ?? $row['event_type'] ?? '',
		)
	);
}

/**
 * Page URL helper (works without theme).
 *
 * @param string $slug Page slug.
 */
function lnkup_page_url_helper( $slug ) {
	$pages = get_option( 'lnkup_pages_created', array() );
	if ( ! empty( $pages[ $slug ] ) ) {
		return get_permalink( $pages[ $slug ] );
	}
	$page = get_page_by_path( $slug );
	return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
}

/**
 * Dashboard breadcrumb trail.
 *
 * @param array $items Each item: label (required), href (optional for current page).
 */
function lnkup_dash_breadcrumb( array $items ) {
	if ( ! $items ) {
		return '';
	}
	ob_start();
	?>
	<nav class="lnkup-dash-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'lnkup-marketplace' ); ?>">
		<ol class="lnkup-dash-breadcrumb__list">
			<?php foreach ( $items as $index => $item ) : ?>
				<?php
				$is_last = ( $index === count( $items ) - 1 );
				$label   = $item['label'] ?? '';
				$href    = $item['href'] ?? '';
				?>
				<li class="lnkup-dash-breadcrumb__item">
					<?php if ( ! $is_last && $href ) : ?>
						<a href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php else : ?>
						<span aria-current="page"><?php echo esc_html( $label ); ?></span>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Dashboard sub-navigation tabs.
 *
 * @param array  $tabs    Tab definitions: id, label, href (optional).
 * @param string $current Active tab id.
 */
function lnkup_dash_subnav( array $tabs, $current = '' ) {
	if ( ! $tabs ) {
		return '';
	}
	ob_start();
	?>
	<nav class="lnkup-dash-subnav" role="tablist" aria-label="<?php esc_attr_e( 'Sections', 'lnkup-marketplace' ); ?>">
		<?php foreach ( $tabs as $tab ) : ?>
			<?php
			$id    = $tab['id'] ?? '';
			$label = $tab['label'] ?? '';
			$href  = $tab['href'] ?? '#' . $id;
			$active = $id === $current;
			?>
			<a href="<?php echo esc_url( $href ); ?>" class="lnkup-dash-subnav__link<?php echo $active ? ' is-active' : ''; ?>" role="tab"<?php echo $active ? ' aria-current="page"' : ''; ?> data-subnav="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php
	return ob_get_clean();
}

/**
 * Reusable empty state block.
 *
 * @param string $title       Heading.
 * @param string $description Body copy.
 * @param string $cta_label   Primary button label.
 * @param string $cta_href    Primary button URL.
 */
function lnkup_empty_state( $title, $description = '', $cta_label = '', $cta_href = '', $secondary_label = '', $secondary_href = '' ) {
	$compact = false;
	if ( is_array( $title ) ) {
		$args            = $title;
		$title           = (string) ( $args['title'] ?? '' );
		$description     = (string) ( $args['desc'] ?? $args['description'] ?? '' );
		$cta_label       = (string) ( $args['cta_label'] ?? $args['ctaLabel'] ?? '' );
		$cta_href        = (string) ( $args['cta_href'] ?? $args['ctaHref'] ?? '' );
		$secondary_label = (string) ( $args['secondary_cta_label'] ?? $args['secondaryCtaLabel'] ?? '' );
		$secondary_href  = (string) ( $args['secondary_cta_href'] ?? $args['secondaryCtaHref'] ?? '' );
		$compact         = ! empty( $args['compact'] );
	}
	ob_start();
	?>
	<div class="lnkup-empty-state<?php echo $compact ? ' lnkup-empty-state--compact' : ''; ?>">
		<div class="lnkup-empty-state__icon" aria-hidden="true"></div>
		<h3 class="lnkup-empty-state__title"><?php echo esc_html( $title ); ?></h3>
		<?php if ( $description ) : ?>
			<p class="lnkup-empty-state__desc"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php if ( $cta_label && $cta_href ) : ?>
			<div class="lnkup-empty-state__actions">
				<a href="<?php echo esc_url( $cta_href ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $cta_label ); ?></a>
				<?php if ( $secondary_label && $secondary_href ) : ?>
					<a href="<?php echo esc_url( $secondary_href ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php echo esc_html( $secondary_label ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Query applications for user.
 *
 * @param int    $user_id User ID.
 * @param string $role    vendor|organizer.
 */
function lnkup_get_applications_for_user( $user_id, $role = 'vendor' ) {
	$key = 'vendor' === $role ? '_lnkup_vendor_user_id' : '_lnkup_organizer_user_id';
	return get_posts(
		array(
			'post_type'      => 'lnkup_application',
			'posts_per_page' => -1,
			'meta_key'       => $key,
			'meta_value'     => $user_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}

/**
 * Get payments for user.
 *
 * @param int    $user_id User ID.
 * @param string $role    vendor|organizer.
 */
function lnkup_get_payments_for_user( $user_id, $role = 'vendor' ) {
	$key = 'vendor' === $role ? '_lnkup_vendor_user_id' : '_lnkup_organizer_user_id';
	return get_posts(
		array(
			'post_type'      => 'lnkup_payment',
			'posts_per_page' => -1,
			'meta_key'       => $key,
			'meta_value'     => $user_id,
		)
	);
}

/**
 * Parse marketplace filter args from request array.
 *
 * @param array $source Request source ($_GET or POST).
 */
function lnkup_parse_marketplace_filters( $source = null ) {
	$source = is_array( $source ) ? $source : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$array_keys = array( 'categories', 'event_types', 'markets', 'doc_required' );
	$args       = array();
	$string_keys = array(
		'q', 'city', 'state', 'date_preset', 'date_from', 'date_to',
		'attendance_bucket', 'fee_bucket', 'environment', 'sort',
	);
	foreach ( $string_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = sanitize_text_field( wp_unslash( $source[ $key ] ) );
		}
	}
	if ( isset( $source['s'] ) && ! isset( $args['q'] ) ) {
		$args['q'] = sanitize_text_field( wp_unslash( $source['s'] ) );
	}
	foreach ( $array_keys as $key ) {
		if ( ! empty( $source[ $key ] ) ) {
			$args[ $key ] = array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $source[ $key ] ) ) ) );
		}
	}
	$num_keys = array( 'attendance_min', 'attendance_max', 'fee_min', 'fee_max', 'page', 'per_page', 'radius' );
	foreach ( $num_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = 'page' === $key || 'per_page' === $key || 'attendance_min' === $key || 'attendance_max' === $key || 'radius' === $key
				? absint( $source[ $key ] )
				: (float) $source[ $key ];
		}
	}
	$bool_keys = array(
		'fee_free', 'indoor', 'outdoor', 'power_available', 'water_available',
		'food_truck_allowed', 'verified_organizer', 'featured_organizer',
		'instant_approval', 'has_spots', 'show_closed', 'newly_added',
		'closing_soon', 'almost_full', 'applications_open', 'doc_none',
		'high_demand', 'include_external',
	);
	foreach ( $bool_keys as $key ) {
		if ( isset( $source[ $key ] ) && '' !== $source[ $key ] ) {
			$args[ $key ] = rest_sanitize_boolean( $source[ $key ] );
		}
	}
	if ( ! isset( $args['has_spots'] ) && ! isset( $source['show_closed'] ) ) {
		$args['has_spots'] = true;
	}
	if ( ! empty( $args['q'] ) && empty( $args['sort'] ) ) {
		$args['sort'] = 'relevance';
	}
	return $args;
}

/**
 * Build active filter chip labels.
 *
 * @param array $args Filter args.
 */
function lnkup_build_active_filters( $args ) {
	$labels = array();
	$map    = array(
		'q'                 => __( 'Search', 'lnkup-marketplace' ),
		'city'              => __( 'City', 'lnkup-marketplace' ),
		'state'             => __( 'State', 'lnkup-marketplace' ),
		'date_preset'       => __( 'Date', 'lnkup-marketplace' ),
		'attendance_bucket' => __( 'Attendance', 'lnkup-marketplace' ),
		'fee_bucket'        => __( 'Fee', 'lnkup-marketplace' ),
		'environment'       => __( 'Environment', 'lnkup-marketplace' ),
		'sort'              => __( 'Sort', 'lnkup-marketplace' ),
	);
	foreach ( $map as $key => $label ) {
		if ( ! empty( $args[ $key ] ) ) {
			$display = 'q' === $key
				? sprintf( __( 'Search: %s', 'lnkup-marketplace' ), $args[ $key ] )
				: $label . ': ' . $args[ $key ];
			$labels[] = array( 'key' => $key, 'value' => $args[ $key ], 'label' => $display );
		}
	}
	foreach ( array( 'categories', 'event_types', 'markets' ) as $tax_key ) {
		foreach ( array_filter( (array) ( $args[ $tax_key ] ?? array() ) ) as $term ) {
			$labels[] = array( 'key' => $tax_key, 'value' => $term, 'label' => $term );
		}
	}
	$bool_labels = array(
		'fee_free'           => __( 'Free', 'lnkup-marketplace' ),
		'has_spots'          => __( 'Has spots', 'lnkup-marketplace' ),
		'verified_organizer' => __( 'Verified organizer', 'lnkup-marketplace' ),
		'closing_soon'       => __( 'Closing soon', 'lnkup-marketplace' ),
		'newly_added'        => __( 'Newly added', 'lnkup-marketplace' ),
		'show_closed'        => __( 'Show closed', 'lnkup-marketplace' ),
		'high_demand'        => __( 'High demand external', 'lnkup-marketplace' ),
	);
	foreach ( $bool_labels as $key => $label ) {
		if ( ! empty( $args[ $key ] ) ) {
			$labels[] = array( 'key' => $key, 'label' => $label );
		}
	}
	return $labels;
}

/**
 * Render pagination links for marketplace results.
 *
 * @param array $result Search result.
 * @param array $args   Current filters.
 */
function lnkup_render_marketplace_pagination( $result, $args = array() ) {
	$page  = max( 1, (int) ( $result['page'] ?? 1 ) );
	$pages = max( 1, (int) ( $result['pages'] ?? 1 ) );
	if ( $pages <= 1 ) {
		return '';
	}
	$base_args = array_filter( $args );
	unset( $base_args['page'] );
	$base_url = add_query_arg( $base_args, lnkup_page_url_helper( 'opportunities' ) );
	$html     = '<nav class="lnkup-mp-v2__pagination" aria-label="' . esc_attr__( 'Opportunities pagination', 'lnkup-marketplace' ) . '">';
	if ( $page > 1 ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( add_query_arg( 'page', $page - 1, $base_url ) ) . '">' . esc_html__( 'Previous', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '<span class="lnkup-muted">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'lnkup-marketplace' ), $page, $pages ) ) . '</span>';
	if ( $page < $pages ) {
		$html .= '<a class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm" href="' . esc_url( add_query_arg( 'page', $page + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'lnkup-marketplace' ) . '</a>';
	}
	$html .= '</nav>';
	return $html;
}

/**
 * Default application deadline from event date.
 *
 * @param int $event_id Event post ID.
 */
function lnkup_default_application_deadline( $event_id ) {
	$event_date = get_post_meta( $event_id, '_lnkup_event_date', true );
	if ( $event_date && strtotime( $event_date ) ) {
		$event_ts  = strtotime( $event_date );
		$seven_out = strtotime( '+7 days' );
		if ( $event_ts <= $seven_out ) {
			return gmdate( 'Y-m-d', strtotime( '+48 hours' ) );
		}
		return gmdate( 'Y-m-d', strtotime( $event_date . ' -7 days' ) );
	}
	return gmdate( 'Y-m-d', strtotime( '+90 days' ) );
}

/**
 * Required document slugs for an opportunity.
 *
 * @param int $opportunity_id Opportunity ID.
 * @return array<int, string>
 */
function lnkup_get_opportunity_document_mode( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$mode           = (string) get_post_meta( $opportunity_id, '_lnkup_document_mode', true );
	$raw            = (string) get_post_meta( $opportunity_id, '_lnkup_required_documents', true );

	if ( in_array( $mode, array( 'none', 'platform_defaults', 'custom' ), true ) ) {
		return $mode;
	}
	if ( 'none' === $raw ) {
		return 'none';
	}
	if ( $raw ) {
		return 'custom';
	}
	return 'none';
}

/**
 * Required document slugs for an opportunity.
 *
 * @param int $opportunity_id Opportunity ID.
 * @return array<int, string>
 */
function lnkup_get_opportunity_required_documents( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$mode           = lnkup_get_opportunity_document_mode( $opportunity_id );
	$raw            = get_post_meta( $opportunity_id, '_lnkup_required_documents', true );
	$docs           = array();

	if ( 'none' === $mode || 'none' === $raw ) {
		return array();
	}

	if ( 'platform_defaults' === $mode ) {
		$settings = lnkup_get_settings();
		return array_values(
			array_unique(
				array_map( 'sanitize_text_field', (array) ( $settings['default_documents'] ?? array() ) )
			)
		);
	}

	if ( $raw ) {
		foreach ( explode( ',', (string) $raw ) as $slug ) {
			$slug = trim( $slug );
			if ( $slug && 'none' !== $slug ) {
				$docs[] = sanitize_key( $slug );
			}
		}
	}

	return array_values( array_unique( $docs ) );
}

/**
 * Render apply-to-opportunity form HTML for modal or REST.
 *
 * @param int $opportunity_id Opportunity ID.
 */
function lnkup_render_apply_form_html( $opportunity_id ) {
	$opportunity_id = absint( $opportunity_id );
	$opp            = get_post( $opportunity_id );
	if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type ) {
		return '';
	}

	if ( class_exists( 'LNKup_Apply_With_Profile_View' ) ) {
		return LNKup_Apply_With_Profile_View::render( $opportunity_id );
	}

	return '';
}

/**
 * Filtered opportunities query wrapper.
 *
 * @param array $args Filter args.
 */
function lnkup_get_filtered_opportunities( $args = array() ) {
	$result = LNKup_Discovery::search( $args );
	$result = lnkup_merge_marketplace_feed( $result, $args );
	$result['active_filters'] = lnkup_build_active_filters( $args );
	$result['empty_state']    = 0 === (int) $result['total'] && empty( $result['has_external'] );
	$result['pagination']     = lnkup_render_marketplace_pagination( $result, $args );
	return $result;
}

/**
 * Merge official opportunities with external unclaimed events (page 1 interleave).
 *
 * @param array<string, mixed> $result Discovery search result.
 * @param array<string, mixed> $args   Filter args.
 * @return array<string, mixed>
 */
function lnkup_merge_marketplace_feed( $result, $args = array() ) {
	$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
	$external_items   = array();
	$external_total   = 0;
	$include_external = class_exists( 'LNKup_External_Events' ) && LNKup_External_Events::should_include_in_feed( $args );

	if ( $include_external ) {
		$ext_args = array_merge( $args, array( 'per_page' => 50, 'page' => 1 ) );
		if ( ! empty( $args['sort'] ) && in_array( $args['sort'], array( 'high_demand', 'recently_requested' ), true ) ) {
			$ext_args['external_sort'] = $args['sort'];
		}
		$ext = LNKup_External_Events::search( $ext_args );
		$external_items = $ext['items'] ?? array();
		$external_total = (int) ( $ext['total'] ?? 0 );
	}

	$result['html']           = lnkup_render_mixed_feed_batch( $result['items'] ?? array(), $external_items, $page );
	$result['external_total'] = $external_total;
	$result['has_external']   = $include_external && $external_total > 0;
	if ( $result['has_external'] && 1 === $page ) {
		$result['total'] = (int) ( $result['total'] ?? 0 ) + min( 6, $external_total );
	}
	return $result;
}

/**
 * Render mixed opportunity + external event cards sorted by date on page 1.
 *
 * @param array<int, array<string, mixed>> $opps      Opportunity items.
 * @param array<int, array<string, mixed>> $externals External event items.
 * @param int                              $page      Current page.
 */
function lnkup_render_mixed_feed_batch( $opps, $externals, $page = 1 ) {
	if ( 1 !== (int) $page || empty( $externals ) ) {
		return lnkup_render_opportunity_cards_batch( $opps );
	}

	$merged = array();
	foreach ( (array) $opps as $item ) {
		$item['item_type'] = 'opportunity';
		$item['sort_date'] = strtotime( (string) ( $item['event_date'] ?? $item['deadline'] ?? '2999-12-31' ) );
		$merged[]          = $item;
	}
	foreach ( array_slice( (array) $externals, 0, 6 ) as $item ) {
		$merged[] = $item;
	}
	usort(
		$merged,
		static function ( $a, $b ) {
			return ( (int) ( $a['sort_date'] ?? 0 ) ) <=> ( (int) ( $b['sort_date'] ?? 0 ) );
		}
	);

	$html = '';
	foreach ( $merged as $item ) {
		if ( 'external_event' === ( $item['item_type'] ?? '' ) ) {
			$html .= lnkup_render_external_event_card( $item );
		} else {
			$html .= lnkup_render_opportunity_card_v2( $item );
		}
	}
	return $html;
}

/**
 * Render a single external (unclaimed) event card.
 *
 * @param array<string, mixed> $row Public external event DTO.
 */
function lnkup_render_external_event_card( $row ) {
	$id = (int) ( $row['id'] ?? 0 );
	if ( ! $id ) {
		return '';
	}

	$user_id    = get_current_user_id();
	$has_interest = false;
	if ( $user_id && class_exists( 'LNKup_External_Events' ) ) {
		$has_interest = (bool) LNKup_External_Events::get_vendor_interest_status( $id, $user_id );
	}

	$img       = lnkup_external_event_illustration_url( $row );
	$interest  = (int) ( $row['vendor_interest_count'] ?? 0 );
	$interest_label = $interest > 0
		? sprintf( lnkup_copy( 'external_event_interest_count' ), $interest )
		: '';

	$badge_labels = array(
		'high_demand'          => lnkup_copy( 'external_event_high_demand' ),
		'trending'             => lnkup_copy( 'external_event_trending' ),
		'recently_requested'   => lnkup_copy( 'external_event_recently_requested' ),
	);
	$badges = (array) ( $row['badges'] ?? array() );
	$breakdown = (array) ( $row['interest_breakdown'] ?? array() );
	$recs = (array) ( $row['recommended_categories'] ?? array() );
	$demand_score = (int) ( $row['demand_score'] ?? 0 );

	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2 lnkup-event-card--external<?php echo $demand_score >= 50 ? ' lnkup-event-card--external-hot' : ''; ?>" data-external-event-id="<?php echo esc_attr( (string) $id ); ?>" data-has-interest="<?php echo $has_interest ? '1' : '0'; ?>" data-demand-score="<?php echo esc_attr( (string) $demand_score ); ?>" data-testid="lnkup-external-event-card">
		<div class="lnkup-event-card__media">
			<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" />
			<div class="lnkup-event-card__badges-top">
				<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( (string) ( $row['event_type_label'] ?? __( 'Event Opportunity', 'lnkup-marketplace' ) ) ); ?></span>
				<span class="lnkup-badge lnkup-badge--external"><?php echo esc_html( lnkup_copy( 'external_event_badge' ) ); ?></span>
				<?php foreach ( $badges as $badge_key ) : ?>
					<?php if ( ! empty( $badge_labels[ $badge_key ] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--demand lnkup-badge--<?php echo esc_attr( $badge_key ); ?>"><?php echo esc_html( $badge_labels[ $badge_key ] ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="lnkup-event-card__body">
			<?php if ( ! empty( $row['organizer_name'] ) ) : ?>
				<div class="lnkup-event-card__organizer">
					<span class="lnkup-event-card__avatar"><?php echo esc_html( substr( (string) $row['organizer_name'], 0, 1 ) ); ?></span>
					<span><?php echo esc_html( (string) $row['organizer_name'] ); ?></span>
				</div>
			<?php endif; ?>
			<p class="lnkup-event-card__external-subline"><?php echo esc_html( lnkup_copy( 'external_event_unclaimed' ) ); ?></p>
			<h3 class="lnkup-event-card__title"><?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?></h3>
			<div class="lnkup-event-card__meta lnkup-event-card__meta-grid">
				<?php if ( ! empty( $row['date_label'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( (string) $row['date_label'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $row['location_label'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( (string) $row['location_label'] ); ?></span>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $row['venue_name'] ) ) : ?>
					<div class="lnkup-event-card__meta-item">
						<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span>
						<span><?php echo esc_html( (string) $row['venue_name'] ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $row['description_excerpt'] ) ) : ?>
				<p class="lnkup-event-card__excerpt"><?php echo esc_html( (string) $row['description_excerpt'] ); ?></p>
			<?php endif; ?>
			<p class="lnkup-event-card__disclaimer lnkup-muted"><?php echo esc_html( lnkup_copy( 'external_event_disclaimer' ) ); ?></p>
			<p class="lnkup-event-card__source lnkup-muted"><?php echo esc_html( lnkup_copy( 'external_event_source_label' ) ); ?></p>
			<?php if ( $interest_label ) : ?>
				<p class="lnkup-event-card__interest-count lnkup-muted"><?php echo esc_html( $interest_label ); ?></p>
			<?php endif; ?>
			<?php if ( $demand_score > 0 ) : ?>
				<p class="lnkup-event-card__demand-score" aria-label="<?php echo esc_attr( lnkup_copy( 'demand_score' ) ); ?>">
					<span class="lnkup-demand-ring" data-score="<?php echo esc_attr( (string) $demand_score ); ?>"><?php echo esc_html( (string) $demand_score ); ?></span>
					<span><?php echo esc_html( (string) ( $row['demand_label'] ?? lnkup_copy( 'demand_score' ) ) ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $breakdown ) ) : ?>
				<ul class="lnkup-event-card__demand-breakdown">
					<?php foreach ( $breakdown as $line ) : ?>
						<li><?php echo esc_html( sprintf( '%s (%d)', (string) ( $line['label'] ?? '' ), (int) ( $line['count'] ?? 0 ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( ! empty( $row['recent_activity_label'] ) ) : ?>
				<p class="lnkup-event-card__recent-activity lnkup-muted"><?php echo esc_html( (string) $row['recent_activity_label'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $recs ) ) : ?>
				<div class="lnkup-event-card__chips">
					<?php foreach ( array_slice( $recs, 0, 3 ) as $cat ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( (string) $cat ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="lnkup-event-card__actions">
				<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-btn--external-interest" data-external-event-id="<?php echo esc_attr( (string) $id ); ?>"<?php echo $has_interest ? ' disabled' : ''; ?>>
					<?php echo esc_html( $has_interest ? lnkup_copy( 'request_interest_existing' ) : lnkup_copy( 'request_vendor_opportunity' ) ); ?>
				</button>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Batch render external event cards.
 *
 * @param array<int, array<string, mixed>> $items External event DTOs.
 */
function lnkup_render_external_event_cards_batch( $items ) {
	$html = '';
	foreach ( (array) $items as $item ) {
		$html .= lnkup_render_external_event_card( $item );
	}
	return $html;
}

/**
 * Normalized opportunity card DTO with fallbacks.
 *
 * @param int        $opportunity_id Opportunity ID.
 * @param array|null $row            Optional index row.
 */
function lnkup_get_opportunity_card_data( $opportunity_id, $row = null ) {
	$opportunity_id = absint( $opportunity_id );
	if ( ! $row ) {
		$row = LNKup_Marketplace_Index::get_row( $opportunity_id );
		if ( ! $row ) {
			LNKup_Marketplace_Index::sync_opportunity( $opportunity_id );
			$row = LNKup_Marketplace_Index::get_row( $opportunity_id );
		}
	}
	if ( ! $row ) {
		return array();
	}

	$item = lnkup_format_index_row( $row, true );
	$opp_post = get_post( $opportunity_id );
	$item['opportunity_title'] = $opp_post ? $opp_post->post_title : '';
	$item['event_name']        = $row['title'] ?? $item['name'];
	$item['event_type']        = ! empty( $item['event_types'][0] ) ? $item['event_types'][0] : __( 'Event Opportunity', 'lnkup-marketplace' );
	$item['event_type_label']  = $item['event_type'];

	if ( empty( $item['city'] ) && empty( $item['state'] ) ) {
		$item['location_label'] = __( 'Location TBA', 'lnkup-marketplace' );
	} else {
		$item['location_label'] = trim( ( $item['city'] ?? '' ) . ( $item['state'] ? ', ' . $item['state'] : '' ) );
	}

	$item['attendance_label'] = ! empty( $item['attendance'] )
		? number_format_i18n( (int) $item['attendance'] )
		: __( 'Attendance TBA', 'lnkup-marketplace' );

	$item['fee_label'] = isset( $item['vendor_fee'] ) && $item['vendor_fee'] > 0
		? lnkup_currency( $item['vendor_fee'] )
		: __( 'Fee TBA', 'lnkup-marketplace' );

	$item['deadline_label'] = ! empty( $item['deadline'] )
		? date_i18n( 'M j, Y', strtotime( $item['deadline'] ) )
		: __( 'Rolling applications', 'lnkup-marketplace' );

	$item['venue_label'] = ! empty( $item['venue_name'] ) ? $item['venue_name'] : '';

	$env = array();
	if ( ! empty( $item['indoor'] ) ) {
		$env[] = __( 'Indoor', 'lnkup-marketplace' );
	}
	if ( ! empty( $item['outdoor'] ) ) {
		$env[] = __( 'Outdoor', 'lnkup-marketplace' );
	}
	if ( ! empty( $item['tent_allowed'] ) ) {
		$env[] = __( 'Tented', 'lnkup-marketplace' );
	}
	$item['environment_labels'] = $env;

	$doc_map = array(
		'certificate_of_insurance' => __( 'COI', 'lnkup-marketplace' ),
		'health_permit'            => __( 'Health Permit', 'lnkup-marketplace' ),
		'business_license'         => __( 'Business License', 'lnkup-marketplace' ),
		'w9'                       => __( 'W9', 'lnkup-marketplace' ),
	);
	$item['document_badges'] = array();
	if ( ! empty( $item['required_documents'] ) ) {
		foreach ( explode( ',', $item['required_documents'] ) as $slug ) {
			$slug = trim( $slug );
			if ( $slug ) {
				$item['document_badges'][] = $doc_map[ $slug ] ?? ucwords( str_replace( '_', ' ', $slug ) );
			}
		}
	}

	$deadline_passed = ! empty( $item['deadline'] ) && strtotime( $item['deadline'] ) < strtotime( 'today' );
	$no_spots        = (int) ( $item['spots_remaining'] ?? 0 ) <= 0;
	$item['is_open'] = ! $deadline_passed && ! $no_spots;

	$item['apply_url']    = add_query_arg( array( 'apply' => $opportunity_id ), lnkup_page_url_helper( 'opportunities' ) );
	$item['details_url']  = $item['apply_url'];
	$item['application_status'] = '';
	$user_id = get_current_user_id();
	if ( $user_id && LNKup_Roles::is_vendor( $user_id ) ) {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_opportunity_id', 'value' => $opportunity_id ),
				),
			)
		);
		if ( $apps ) {
			$item['application_status'] = get_post_meta( $apps[0]->ID, '_lnkup_application_status', true );
		}
	}

	return $item;
}

/**
 * Format index table row for API/cards.
 *
 * @param array $row     Index row.
 * @param bool  $compare Include comparison fields.
 */
function lnkup_format_index_row( $row, $compare = false ) {
	$categories  = json_decode( $row['categories'] ?? '[]', true ) ?: array();
	$event_types = json_decode( $row['event_types'] ?? '[]', true ) ?: array();
	$organizer   = ! empty( $row['organizer_user_id'] ) ? get_userdata( (int) $row['organizer_user_id'] ) : null;

	$item = array(
		'opportunity_id'    => (int) $row['opportunity_id'],
		'event_id'          => (int) $row['event_id'],
		'id'                => (int) $row['opportunity_id'],
		'name'              => $row['title'],
		'city'              => $row['city'],
		'state'             => $row['state'],
		'venue_name'        => $row['venue_name'],
		'date'              => $row['event_date'],
		'attendance'        => (int) $row['attendance'],
		'vendor_fee'        => (float) $row['vendor_fee'],
		'spots_remaining'   => (int) $row['spots_remaining'],
		'total_spots'       => (int) $row['spots_total'],
		'categories'        => $categories,
		'event_types'       => $event_types,
		'deadline'          => $row['deadline'],
		'lat'               => $row['lat'] ? (float) $row['lat'] : null,
		'lng'               => $row['lng'] ? (float) $row['lng'] : null,
		'featured_image_id' => (int) ( $row['featured_image_id'] ?? 0 ),
		'featured_image'    => ! empty( $row['featured_image_id'] ) ? wp_get_attachment_image_url( (int) $row['featured_image_id'], 'medium_large' ) : '',
		'organizer_id'      => (int) $row['organizer_user_id'],
		'organizer_name'    => $organizer ? $organizer->display_name : '',
		'organizer_verified'=> (bool) ( $row['organizer_verified'] ?? false ),
		'organizer_rating'  => $row['organizer_rating'] ? (float) $row['organizer_rating'] : null,
		'instant_approval'  => (bool) ( $row['instant_approval'] ?? false ),
		'application_count' => (int) ( $row['application_count'] ?? 0 ),
		'view_count'        => (int) ( $row['view_count'] ?? 0 ),
		'trending_score'    => (float) ( $row['trending_score'] ?? 0 ),
		'badges'            => lnkup_get_opportunity_badges( $row ),
	);

	if ( $compare ) {
		$item['power_available']     = (bool) ( $row['power_available'] ?? false );
		$item['water_available']     = (bool) ( $row['water_available'] ?? false );
		$item['food_truck_allowed']  = (bool) ( $row['food_truck_allowed'] ?? false );
		$item['tent_allowed']        = (bool) ( $row['tent_allowed'] ?? false );
		$item['generator_allowed']   = (bool) ( $row['generator_allowed'] ?? false );
		$item['indoor']              = (bool) ( $row['indoor'] ?? false );
		$item['outdoor']             = (bool) ( $row['outdoor'] ?? false );
		$item['free_parking']        = (bool) ( $row['free_parking'] ?? false );
		$item['load_in_time']        = $row['load_in_time'] ?? '';
		$item['required_documents']  = $row['required_documents'] ?? '';
	}

	return $item;
}

/**
 * Compute display badges for opportunity.
 *
 * @param array $row Index row or formatted item.
 */
function lnkup_get_opportunity_badges( $row ) {
	$badges = array();
	$created = isset( $row['created_at'] ) ? strtotime( $row['created_at'] ) : 0;
	if ( $created && $created > strtotime( '-7 days' ) ) {
		$badges[] = 'recently_added';
	}
	if ( ! empty( $row['deadline'] ) ) {
		$days = ( strtotime( $row['deadline'] ) - time() ) / DAY_IN_SECONDS;
		if ( $days >= 0 && $days <= 7 ) {
			$badges[] = 'closing_soon';
		}
	}
	$spots_r = (int) ( $row['spots_remaining'] ?? 0 );
	$spots_t = (int) ( $row['spots_total'] ?? 1 );
	if ( $spots_t > 0 && ( $spots_r / $spots_t ) <= 0.1 && $spots_r > 0 ) {
		$badges[] = 'almost_full';
	}
	if ( ! empty( $row['trending_score'] ) && (float) $row['trending_score'] > 30 ) {
		$badges[] = 'trending';
	}
	if ( ! empty( $row['organizer_verified'] ) ) {
		$badges[] = 'verified';
	}
	if ( ! empty( $row['application_count'] ) && (int) $row['application_count'] > 5 ) {
		$badges[] = 'popular';
	}
	return $badges;
}

/**
 * Render v2 opportunity card.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_render_opportunity_card_v2( $opp ) {
	if ( empty( $opp['event_type_label'] ) && ! empty( $opp['opportunity_id'] ) ) {
		$opp = lnkup_get_opportunity_card_data( (int) $opp['opportunity_id'], null );
	}
	if ( ! $opp ) {
		return '';
	}

	$user_id   = get_current_user_id();
	$favorited = $user_id && LNKup_Vendor_Personalization::is_favorite( $user_id, 'opportunities', $opp['opportunity_id'] );
	$low_spots = ( $opp['spots_remaining'] ?? 0 ) <= 5 && ( $opp['spots_remaining'] ?? 0 ) > 0;
	$apply_url = $opp['apply_url'] ?? add_query_arg( array( 'apply' => $opp['opportunity_id'] ), lnkup_page_url_helper( 'opportunities' ) );
	$match_chip = '';
	if ( $user_id && class_exists( 'LNKup_Growth_Match' ) && LNKup_Capabilities::can_view_growth( $user_id ) ) {
		$match = LNKup_Growth_Match::for_opportunity( $user_id, $opp['opportunity_id'] );
		if ( ! empty( $match['match_score'] ) ) {
			$match_chip = '<span class="lnkup-growth-match-chip">' . esc_html( $match['match_score'] ) . '% ' . esc_html( $match['match_label'] ) . '</span>';
		}
	}
	$event_name = $opp['event_name'] ?? $opp['name'];
	$name       = $opp['name_html'] ?? $event_name;
	$subtitle   = '';
	if ( ! empty( $opp['opportunity_title'] ) && $opp['opportunity_title'] !== $event_name ) {
		$subtitle = $opp['opportunity_title'];
	}
	$location = $opp['location_label'] ?? trim( ( $opp['city'] ?? '' ) . ( ! empty( $opp['state'] ) ? ', ' . $opp['state'] : '' ) );
	$img       = lnkup_opportunity_illustration_url( $opp );
	$badge_labels = array(
		'trending'       => __( 'Trending', 'lnkup-marketplace' ),
		'closing_soon'   => __( 'Closing Soon', 'lnkup-marketplace' ),
		'recently_added' => __( 'New', 'lnkup-marketplace' ),
		'verified'       => __( 'Verified', 'lnkup-marketplace' ),
		'popular'        => __( 'Popular', 'lnkup-marketplace' ),
		'almost_full'    => __( 'Almost Full', 'lnkup-marketplace' ),
	);
	$status = $opp['application_status'] ?? '';
	$status_labels = array(
		'submitted'        => __( 'Applied', 'lnkup-marketplace' ),
		'under_review'     => __( 'Under Review', 'lnkup-marketplace' ),
		'approved'         => __( 'Approved', 'lnkup-marketplace' ),
		'payment_required' => __( 'Payment Due', 'lnkup-marketplace' ),
		'paid'             => __( 'Paid', 'lnkup-marketplace' ),
		'completed'        => __( 'Completed', 'lnkup-marketplace' ),
		'waitlisted'       => __( 'Waitlisted', 'lnkup-marketplace' ),
	);
	$is_closed = empty( $opp['is_open'] );

	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2<?php echo $is_closed ? ' lnkup-event-card--closed' : ''; ?>" data-opportunity-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>" data-lat="<?php echo esc_attr( (string) ( $opp['lat'] ?? '' ) ); ?>" data-lng="<?php echo esc_attr( (string) ( $opp['lng'] ?? '' ) ); ?>">
		<div class="lnkup-event-card__media">
			<?php if ( $img ) : ?>
				<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy" />
			<?php else : ?>
				<div class="lnkup-event-card__placeholder" aria-hidden="true"><span>LNKup</span></div>
			<?php endif; ?>
			<div class="lnkup-event-card__badges-top">
				<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( $opp['event_type_label'] ?? __( 'Event Opportunity', 'lnkup-marketplace' ) ); ?></span>
				<span class="lnkup-badge"><?php echo esc_html( $opp['fee_label'] ?? lnkup_currency( $opp['vendor_fee'] ?? 0 ) ); ?></span>
				<?php if ( $low_spots ) : ?>
					<span class="lnkup-badge lnkup-badge--destructive"><?php echo esc_html( (string) $opp['spots_remaining'] ); ?> <?php esc_html_e( 'left', 'lnkup-marketplace' ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $opp['badges'] ) ) : ?>
				<div class="lnkup-event-card__badges-float">
					<?php foreach ( array_slice( $opp['badges'], 0, 2 ) as $b ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $badge_labels[ $b ] ?? $b ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<button type="button" class="lnkup-event-card__favorite<?php echo $favorited ? ' is-active' : ''; ?>" data-favorite-type="opportunities" data-favorite-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>" aria-label="<?php esc_attr_e( 'Favorite', 'lnkup-marketplace' ); ?>">♥</button>
		</div>
		<div class="lnkup-event-card__body">
			<?php if ( ! empty( $opp['organizer_name'] ) ) : ?>
				<div class="lnkup-event-card__organizer">
					<span class="lnkup-event-card__avatar"><?php echo esc_html( substr( $opp['organizer_name'], 0, 1 ) ); ?></span>
					<span><?php echo esc_html( $opp['organizer_name'] ); ?></span>
					<?php if ( ! empty( $opp['organizer_verified'] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--verified"><?php esc_html_e( 'Verified', 'lnkup-marketplace' ); ?></span>
					<?php endif; ?>
				</div>
				<?php
				if ( ! empty( $opp['organizer_id'] ) && class_exists( 'LNKup_Verification' ) ) {
					$org_badges = LNKup_Verification::get_organizer_badges( (int) $opp['organizer_id'], 'card' );
					if ( $org_badges ) {
						echo '<div class="lnkup-event-card__trust-badges" data-testid="lnkup-opp-organizer-trust">';
						foreach ( $org_badges as $ob ) {
							printf(
								'<span class="lnkup-trust-badge lnkup-trust-badge--accent">%s</span>',
								esc_html( $ob['label'] ?? '' )
							);
						}
						echo '</div>';
					}
				}
				?>
			<?php endif; ?>
			<h3><?php echo wp_kses_post( $name ); ?></h3>
			<?php if ( $subtitle ) : ?>
				<p class="lnkup-event-card__subtitle lnkup-muted"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
			<?php if ( $match_chip ) : ?>
				<div class="lnkup-event-card__match"><?php echo $match_chip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
			<div class="lnkup-event-card__meta lnkup-event-card__meta-grid">
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Location', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $location ?: __( 'TBA', 'lnkup-marketplace' ) ); ?></span>
				</div>
				<?php if ( ! empty( $opp['date'] ) ) : ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Date', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $opp['date'] ) ) ); ?></span>
				</div>
				<?php endif; ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Booth fee', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['fee_label'] ?? lnkup_currency( $opp['vendor_fee'] ?? 0 ) ); ?></span>
				</div>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Capacity', 'lnkup-marketplace' ); ?></span>
					<span><?php
					$filled = max( 0, (int) $opp['total_spots'] - (int) $opp['spots_remaining'] );
					printf(
						esc_html__( '%1$d / %2$d', 'lnkup-marketplace' ),
						$filled,
						(int) $opp['total_spots']
					);
					?></span>
				</div>
				<?php if ( ! empty( $opp['venue_label'] ) ) : ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Venue', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['venue_label'] ); ?></span>
				</div>
				<?php endif; ?>
				<div class="lnkup-event-card__meta-item">
					<span class="lnkup-event-card__meta-label"><?php esc_html_e( 'Deadline', 'lnkup-marketplace' ); ?></span>
					<span><?php echo esc_html( $opp['deadline_label'] ?? __( 'Rolling', 'lnkup-marketplace' ) ); ?></span>
				</div>
			</div>
			<?php if ( ! empty( $opp['categories'] ) ) : ?>
				<div class="lnkup-event-card__chips">
					<?php foreach ( array_slice( $opp['categories'], 0, 3 ) as $cat ) : ?>
						<span class="lnkup-badge lnkup-badge--secondary"><?php echo esc_html( $cat ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $opp['environment_labels'] ) || ! empty( $opp['document_badges'] ) ) : ?>
				<div class="lnkup-event-card__chips lnkup-event-card__chips--meta">
					<?php foreach ( (array) $opp['environment_labels'] as $env_label ) : ?>
						<span class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $env_label ); ?></span>
					<?php endforeach; ?>
					<?php foreach ( (array) $opp['document_badges'] as $doc_label ) : ?>
						<span class="lnkup-badge lnkup-badge--outline"><?php echo esc_html( $doc_label ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="lnkup-event-card__footer">
				<span class="lnkup-badge lnkup-badge--secondary"><?php
				$filled = max( 0, (int) $opp['total_spots'] - (int) $opp['spots_remaining'] );
				printf(
					/* translators: 1: filled spots, 2: total spots */
					esc_html__( '%1$d of %2$d spots filled', 'lnkup-marketplace' ),
					$filled,
					(int) $opp['total_spots']
				);
				?></span>
				<div class="lnkup-event-card__actions">
					<?php if ( lnkup_marketplace_phase_at_least( '1c' ) ) : ?>
					<button type="button" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm lnkup-compare-add" data-opportunity-id="<?php echo esc_attr( (string) $opp['opportunity_id'] ); ?>"><?php esc_html_e( 'Compare', 'lnkup-marketplace' ); ?></button>
					<?php endif; ?>
					<a href="<?php echo esc_url( $apply_url ); ?>" class="lnkup-btn lnkup-btn--ghost lnkup-btn--sm"><?php esc_html_e( 'Details', 'lnkup-marketplace' ); ?></a>
					<?php if ( $status && isset( $status_labels[ $status ] ) ) : ?>
						<span class="lnkup-badge lnkup-badge--accent"><?php echo esc_html( $status_labels[ $status ] ); ?></span>
					<?php elseif ( $is_closed ) : ?>
						<span class="lnkup-badge"><?php esc_html_e( 'Closed', 'lnkup-marketplace' ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( $apply_url ); ?>" class="lnkup-btn lnkup-btn--primary lnkup-btn--sm"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</article>
	<?php
	return ob_get_clean();
}

/**
 * Render skeleton card placeholder.
 */
function lnkup_render_opportunity_card_skeleton() {
	return '<article class="lnkup-event-card lnkup-event-card--skeleton"><div class="lnkup-skeleton lnkup-skeleton--media"></div><div class="lnkup-skeleton lnkup-skeleton--title"></div><div class="lnkup-skeleton lnkup-skeleton--line"></div></article>';
}

/**
 * Batch render cards HTML.
 *
 * @param array $items Items.
 */
function lnkup_render_opportunity_cards_batch( $items ) {
	$html = '';
	foreach ( (array) $items as $item ) {
		$html .= lnkup_render_opportunity_card_v2( $item );
	}
	return $html;
}

/**
 * Legacy card wrapper — delegates to v2 when index data available.
 *
 * @param array $opp Opportunity data.
 */
function lnkup_render_event_card( $opp ) {
	if ( ! empty( $opp['opportunity_id'] ) || ! empty( $opp['id'] ) ) {
		$id = $opp['opportunity_id'] ?? $opp['id'];
		$row = LNKup_Marketplace_Index::get_row( (int) $id );
		if ( $row ) {
			return lnkup_render_opportunity_card_v2( lnkup_format_index_row( $row ) );
		}
	}
	return lnkup_render_opportunity_card_v2( $opp );
}

/**
 * Query discoverable vendor profiles for the organizer directory.
 *
 * @param array $args Query arguments.
 */
function lnkup_get_discoverable_vendors( $args = array() ) {
	$args = wp_parse_args(
		$args,
		array(
			'page'        => 1,
			'per_page'    => 24,
			'q'           => '',
			'city'        => '',
			'state'       => '',
			'category'    => '',
			'verified'    => '',
			'post_status' => array( 'publish', 'private' ),
		)
	);

	$query_args = array(
		'post_type'              => 'lnkup_vendor_profile',
		'post_status'            => $args['post_status'],
		'posts_per_page'         => min( 48, max( 1, absint( $args['per_page'] ) ) ),
		'paged'                  => max( 1, absint( $args['page'] ) ),
		'orderby'                => 'date',
		'order'                  => 'DESC',
		'no_found_rows'          => false,
		'update_post_meta_cache' => true,
		'meta_query'             => array(
			array(
				'key'     => '_lnkup_owner_user_id',
				'compare' => 'EXISTS',
			),
		),
	);

	if ( '' !== $args['verified'] && $args['verified'] ) {
		$query_args['meta_query'][] = array(
			'key'   => '_lnkup_verification_status',
			'value' => 'approved',
		);
	}

	if ( ! empty( $args['city'] ) ) {
		$query_args['meta_query'][] = array(
			'key'     => '_lnkup_city',
			'value'   => sanitize_text_field( $args['city'] ),
			'compare' => 'LIKE',
		);
	}

	if ( ! empty( $args['state'] ) ) {
		$query_args['meta_query'][] = array(
			'key'   => '_lnkup_state',
			'value' => sanitize_text_field( $args['state'] ),
		);
	}

	if ( ! empty( $args['category'] ) ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => 'lnkup_vendor_category',
				'field'    => 'name',
				'terms'    => sanitize_text_field( $args['category'] ),
			),
		);
	}

	if ( ! empty( $args['q'] ) ) {
		$search = sanitize_text_field( $args['q'] );
		$query_args['s'] = $search;
		$query_args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => '_lnkup_business_name',
				'value'   => $search,
				'compare' => 'LIKE',
			),
			array(
				'key'     => '_lnkup_city',
				'value'   => $search,
				'compare' => 'LIKE',
			),
		);
	}

	if ( count( $query_args['meta_query'] ) > 1 ) {
		$query_args['meta_query']['relation'] = 'AND';
	}

	$query = new WP_Query( $query_args );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'lnkup_get_discoverable_vendors: found %d (page %d)',
				(int) $query->found_posts,
				(int) $args['page']
			)
		);
	}

	return array(
		'posts' => $query->posts,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
		'page'  => max( 1, absint( $args['page'] ) ),
	);
}

/**
 * Normalize vendor card data with safe fallbacks.
 *
 * @param array $profile Vendor profile DTO.
 */
function lnkup_normalize_vendor_card( $profile ) {
	if ( ! is_array( $profile ) ) {
		return array();
	}
	$profile['business_name'] = $profile['business_name'] ?? __( 'Vendor', 'lnkup-marketplace' );
	$profile['category']      = is_array( $profile['category'] ?? null ) ? $profile['category'] : array();
	$profile['city']          = $profile['city'] ?? '';
	$profile['state']         = $profile['state'] ?? '';
	$profile['vendor_score']  = isset( $profile['vendor_score'] ) ? (int) $profile['vendor_score'] : 0;
	$profile['avg_rating']    = isset( $profile['avg_rating'] ) ? (float) $profile['avg_rating'] : 0;
	$profile['review_count']  = isset( $profile['review_count'] ) ? (int) $profile['review_count'] : 0;
	$profile['completed_events'] = isset( $profile['completed_events'] ) ? (int) $profile['completed_events'] : 0;
	$profile['logo']          = $profile['logo'] ?? '';
	$profile['documents']     = is_array( $profile['documents'] ?? null ) ? $profile['documents'] : array();
	$profile['badges']        = is_array( $profile['badges'] ?? null ) ? $profile['badges'] : array();
	$profile['verified']      = ! empty( $profile['verified'] );
	return $profile;
}

/**
 * Canonical parent => child event type map.
 */
function lnkup_get_event_type_tree_definition() {
	if ( class_exists( 'LNKup_Taxonomies' ) ) {
		return LNKup_Taxonomies::get_event_type_tree_definition();
	}
	return array();
}

/**
 * Top-level event type parents.
 */
function lnkup_get_event_type_parents() {
	$terms = get_terms(
		array(
			'taxonomy'   => 'lnkup_event_type',
			'parent'     => 0,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Child event types for a parent term.
 *
 * @param int $parent_id Parent term ID.
 */
function lnkup_get_event_type_children( $parent_id ) {
	$terms = get_terms(
		array(
			'taxonomy'   => 'lnkup_event_type',
			'parent'     => absint( $parent_id ),
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
	return is_wp_error( $terms ) ? array() : $terms;
}

/**
 * Nested event type tree for PHP templates and JS localization.
 */
function lnkup_get_event_type_tree() {
	$tree = array();
	foreach ( lnkup_get_event_type_parents() as $parent ) {
		$children = array();
		foreach ( lnkup_get_event_type_children( $parent->term_id ) as $child ) {
			$children[] = array(
				'id'   => (int) $child->term_id,
				'name' => $child->name,
				'slug' => $child->slug,
			);
		}
		$tree[] = array(
			'id'       => (int) $parent->term_id,
			'name'     => $parent->name,
			'slug'     => $parent->slug,
			'children' => $children,
		);
	}
	return $tree;
}

/**
 * Expand assigned event type terms to leaf + parent names for indexing/filtering.
 *
 * @param array $terms WP_Term objects.
 */
function lnkup_expand_event_type_names_for_index( $terms ) {
	$names = array();
	if ( ! is_array( $terms ) ) {
		return $names;
	}
	foreach ( $terms as $term ) {
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$names[] = $term->name;
		if ( $term->parent ) {
			$parent = get_term( $term->parent, 'lnkup_event_type' );
			if ( $parent && ! is_wp_error( $parent ) ) {
				$names[] = $parent->name;
			}
		}
	}
	return array_values( array_unique( array_filter( $names ) ) );
}

/**
 * Organizer event intelligence summary for dashboard widgets.
 *
 * @param int|null $organizer_id Organizer user ID.
 */
function lnkup_get_event_intelligence_summary( $organizer_id = null ) {
	$organizer_id = $organizer_id ? absint( $organizer_id ) : get_current_user_id();
	if ( ! $organizer_id ) {
		return array(
			'kpis'      => array(),
			'insights'  => array(),
			'events'    => array(),
			'warnings'  => array(),
		);
	}

	if ( class_exists( 'LNKup_Organizer_Intelligence' ) ) {
		return LNKup_Organizer_Intelligence::event_intelligence_summary( $organizer_id );
	}

	return array(
		'kpis'     => array(),
		'insights' => array( __( 'More data needed.', 'lnkup-marketplace' ) ),
		'events'   => array(),
		'warnings' => array(),
	);
}

/**
 * Semantic status badge modifier map.
 *
 * @return array<string, string>
 */
function lnkup_status_badge_modifier( string $status ): string {
	$map = array(
		'paid'             => 'success',
		'completed'        => 'success',
		'approved'         => 'success',
		'ready'            => 'success',
		'verified'         => 'success',
		'submitted'        => 'warning',
		'under_review'     => 'warning',
		'payment_required' => 'warning',
		'pending'          => 'warning',
		'docs_pending'     => 'warning',
		'balance_due'      => 'warning',
		'rejected'         => 'danger',
		'cancelled'        => 'danger',
		'failed'           => 'danger',
		'overdue'          => 'danger',
		'missing_docs'     => 'danger',
		'invited'          => 'info',
		'recommended'      => 'info',
		'featured'         => 'info',
		'draft'            => 'muted',
		'open'             => 'muted',
		'archived'         => 'muted',
		'waitlisted'       => 'muted',
	);
	return $map[ $status ] ?? 'muted';
}

/**
 * Render a semantic status badge.
 *
 * @param string $status Workflow status slug.
 * @param string $label  Display label.
 */
function lnkup_status_badge( string $status, string $label = '' ): string {
	$modifier = lnkup_status_badge_modifier( $status );
	if ( ! $label ) {
		$label = $status;
	}
	return sprintf(
		'<span class="lnkup-badge lnkup-badge--%s">%s</span>',
		esc_attr( $modifier ),
		esc_html( $label )
	);
}

/**
 * Render standardized KPI card.
 *
 * @param array<string, mixed> $args Card arguments.
 */
function lnkup_kpi_card( array $args ): string {
	$label     = (string) ( $args['label'] ?? '' );
	$value     = (string) ( $args['value'] ?? '' );
	$trend     = (string) ( $args['trend'] ?? '' );
	$icon      = (string) ( $args['icon'] ?? 'apps' );
	$is_zero   = ! empty( $args['is_zero'] );
	$trend_dir = (string) ( $args['trend_dir'] ?? 'neutral' );
	$href      = (string) ( $args['href'] ?? '' );

	$icons = array(
		'apps'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'star'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>',
		'cash'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
		'docs'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/></svg>',
		'payment'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
		'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>',
		'inbox'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-6l-2 3H10l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
		'users'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
		'shield'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
		'folder'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"/></svg>',
	);
	$trend_icons = array(
		'up'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/></svg>',
		'down'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m22 17-8.5-8.5-5 5L2 7"/><path d="M16 17h6v-6"/></svg>',
		'neutral' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>',
	);
	$trend_dir = in_array( $trend_dir, array( 'up', 'down', 'neutral' ), true ) ? $trend_dir : 'neutral';
	$has_spark = $trend && in_array( $trend_dir, array( 'up', 'down' ), true );
	$icon_html = function_exists( 'lnkup_inline_icon' )
		? lnkup_inline_icon( $icons[ $icon ] ?? $icons['apps'], 18 )
		: ( $icons[ $icon ] ?? $icons['apps'] );
	$trend_icon = function_exists( 'lnkup_inline_icon' )
		? lnkup_inline_icon( $trend_icons[ $trend_dir ], 12 )
		: $trend_icons[ $trend_dir ];
	$value_class = 'lnkup-stat-card__value' . ( $is_zero ? ' lnkup-stat-card__value--muted' : '' );
	$card_class  = 'lnkup-stat-card' . ( $href ? ' lnkup-stat-card--link' : '' ) . ( $has_spark ? ' lnkup-kpi-card--spark' : '' );
	$accent      = (string) ( $args['accent'] ?? '' );
	if ( $accent ) {
		$card_class .= ' lnkup-stat-card--accent-' . sanitize_html_class( $accent );
	}
	$animate_raw = isset( $args['animate'] ) ? (string) $args['animate'] : '';
	$spark_pct   = 'up' === $trend_dir ? '72%' : '38%';
	ob_start();
	?>
	<<?php echo $href ? 'a' : 'div'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> class="<?php echo esc_attr( $card_class ); ?>"<?php echo $href ? ' href="' . esc_url( $href ) . '"' : ''; ?>>
		<div class="lnkup-stat-card__content">
			<p class="lnkup-stat-card__label"><?php echo esc_html( $label ); ?></p>
			<p class="<?php echo esc_attr( $value_class ); ?>"<?php echo $animate_raw !== '' ? ' data-animate-count="' . esc_attr( $animate_raw ) . '"' . ( ! empty( $args['count_currency'] ) ? ' data-count-currency="1" data-count-prefix="$"' : '' ) : ''; ?>><?php echo esc_html( $value ); ?></p>
			<?php if ( $trend ) : ?>
				<p class="lnkup-stat-card__trend lnkup-stat-card__trend--<?php echo esc_attr( $trend_dir ); ?>">
					<span class="lnkup-stat-card__trend-icon" aria-hidden="true"><?php echo $trend_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php echo esc_html( $trend ); ?>
				</p>
			<?php endif; ?>
			<?php if ( $has_spark ) : ?>
				<div class="lnkup-stat-card__spark" aria-hidden="true"><span style="width:<?php echo esc_attr( $spark_pct ); ?>"></span></div>
			<?php endif; ?>
		</div>
		<span class="lnkup-stat-card__icon" aria-hidden="true"><?php echo $icon_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	</<?php echo $href ? 'a' : 'div'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php
	return ob_get_clean();
}

/**
 * Render panel hero header.
 *
 * @param string $title    Page title.
 * @param string $subtitle Subtitle.
 * @param string $actions  Optional HTML for action buttons.
 */
function lnkup_panel_hero( string $title, string $subtitle = '', string $actions = '' ): string {
	ob_start();
	?>
	<header class="lnkup-panel-hero lnkup-dash-header">
		<div class="lnkup-panel-hero__copy">
			<h1 class="lnkup-dash-header__title"><?php echo esc_html( $title ); ?></h1>
			<?php if ( $subtitle ) : ?>
				<p class="lnkup-dash-header__subtitle"><?php echo esc_html( $subtitle ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $actions ) : ?>
			<div class="lnkup-panel-hero__actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>
	</header>
	<?php
	return ob_get_clean();
}

/**
 * Executive page hero with KPI, metrics, and CTAs.
 *
 * @param array $args title, subtitle, kpi, kpi_label, metrics[], status_html, primary_cta{label,href}, secondary_cta{label,href}, ring_pct.
 */
function lnkup_executive_hero( array $args ): string {
	$title    = (string) ( $args['title'] ?? '' );
	$subtitle = (string) ( $args['subtitle'] ?? '' );
	$kpi      = (string) ( $args['kpi'] ?? '' );
	$kpi_lbl  = (string) ( $args['kpi_label'] ?? '' );
	$metrics  = is_array( $args['metrics'] ?? null ) ? $args['metrics'] : array();
	$status   = (string) ( $args['status_html'] ?? '' );
	$primary  = is_array( $args['primary_cta'] ?? null ) ? $args['primary_cta'] : array();
	$secondary = is_array( $args['secondary_cta'] ?? null ) ? $args['secondary_cta'] : array();
	$ring_pct = isset( $args['ring_pct'] ) ? (int) $args['ring_pct'] : null;

	ob_start();
	?>
	<header class="lnkup-executive-hero lnkup-surface--hero">
		<div class="lnkup-executive-hero__top">
			<div>
				<h1 class="lnkup-dash-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( $subtitle ) : ?>
					<p class="lnkup-dash-header__subtitle lnkup-type-meta"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
				<?php if ( $kpi ) : ?>
					<p class="lnkup-executive-hero__kpi">
						<?php if ( $kpi_lbl ) : ?>
							<span class="lnkup-type-label"><?php echo esc_html( $kpi_lbl ); ?></span><br>
						<?php endif; ?>
						<span class="lnkup-type-display"><?php echo esc_html( $kpi ); ?></span>
					</p>
				<?php endif; ?>
				<?php if ( $status ) : ?>
					<div class="lnkup-executive-hero__status"><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				<?php endif; ?>
			</div>
			<?php if ( null !== $ring_pct ) : ?>
				<div class="lnkup-compliance-ring" style="--progress: <?php echo esc_attr( (string) min( 100, max( 0, $ring_pct ) ) ); ?>%" role="img" aria-label="<?php echo esc_attr( sprintf( __( '%d percent', 'lnkup-marketplace' ), $ring_pct ) ); ?>">
					<span class="lnkup-compliance-ring__value"><?php echo esc_html( (string) $ring_pct ); ?>%</span>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $metrics ) : ?>
			<div class="lnkup-executive-hero__metrics">
				<?php foreach ( $metrics as $metric ) : ?>
					<?php if ( empty( $metric['label'] ) ) { continue; } ?>
					<span class="lnkup-executive-hero__metric">
						<span class="lnkup-type-meta"><?php echo esc_html( (string) $metric['label'] ); ?></span>
						<strong><?php echo esc_html( (string) ( $metric['value'] ?? '' ) ); ?></strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $primary['label'] ) || ! empty( $secondary['label'] ) ) : ?>
			<div class="lnkup-executive-hero__ctas">
				<?php if ( ! empty( $primary['label'] ) && ! empty( $primary['href'] ) ) : ?>
					<a href="<?php echo esc_url( $primary['href'] ); ?>" class="lnkup-btn lnkup-btn--primary"><?php echo esc_html( $primary['label'] ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $secondary['label'] ) && ! empty( $secondary['href'] ) ) : ?>
					<a href="<?php echo esc_url( $secondary['href'] ); ?>" class="lnkup-btn lnkup-btn--ghost"><?php echo esc_html( $secondary['label'] ); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</header>
	<?php
	return ob_get_clean();
}
