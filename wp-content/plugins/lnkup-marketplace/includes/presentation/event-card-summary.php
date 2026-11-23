<?php
/**
 * Display summary for one public event built from its opportunity rows. Every public card
 * (homepage hero, featured grid, Explore feed, storefronts) reads the same summary so fees,
 * availability and actions are worded the same way everywhere.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

/*
 * Explore interaction layer (filter sheet, filter count, empty-state actions, return position).
 * Loaded only where the marketplace feed script is.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! wp_script_is( 'lnkup-marketplace-v2', 'enqueued' ) ) {
			return;
		}
		$file = LNKUP_PLUGIN_DIR . 'public/js/explore-ux.js';
		wp_enqueue_script( 'lnkup-explore-ux', LNKUP_PLUGIN_URL . 'public/js/explore-ux.js', array( 'lnkup-marketplace-v2' ), LNKUP_VERSION . '.' . ( file_exists( $file ) ? (string) filemtime( $file ) : '0' ), true );
	},
	100
);

/**
 * Drop a trailing " — Dec 19, 2026" style date from a title (series occurrences carry one) when
 * the date is shown next to it anyway.
 *
 * @param string $title Title.
 */
function lnkup_strip_title_date( $title ) {
	$clean = preg_replace( '/\s+[\x{2014}\x{2013}-]\s+(?:[A-Za-z]{3,9},?\s+)?[A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}\s*$/u', '', (string) $title );
	return '' !== trim( (string) $clean ) ? trim( (string) $clean ) : (string) $title;
}

/**
 * Summarize an event from its opportunity rows.
 *
 * @param array  $slots   Opportunity rows for a single event (marketplace index shape).
 * @param string $context home|feed|storefront|hero. Apply is only offered on the feed.
 * @return array<string, mixed> Empty when there are no rows.
 */
function lnkup_event_card_summary( $slots, $context = 'home' ) {
	$slots = array_values( (array) $slots );
	if ( ! $slots ) {
		return array();
	}
	$first    = $slots[0];
	$event_id = (int) ( $first['event_id'] ?? 0 );
	$event    = $event_id ? get_post( $event_id ) : null;
	$date     = (string) ( $first['date'] ?? '' );
	$title    = $event ? $event->post_title : (string) ( $first['event_name'] ?? $first['name'] ?? '' );
	$venue    = '';
	$org_name = '';
	if ( $event_id && ! get_post_meta( $event_id, '_lnkup_venue_hidden', true ) ) {
		$venue = (string) get_post_meta( $event_id, '_lnkup_venue_name', true );
	}
	if ( $event_id ) {
		$organizer_user = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		if ( $organizer_user ) {
			$org_name = (string) get_user_meta( $organizer_user, '_lnkup_organizer_public_name', true );
		}
	}
	$title      = lnkup_strip_title_date( lnkup_display_event_title( $title, $date, $venue, $org_name ) );
	$place      = ( ! empty( $first['city'] ) || ! empty( $first['state'] ) ) ? lnkup_display_place( $first['city'] ?? '', $first['state'] ?? '' ) : '';
	$bookable   = ! ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) && LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id ) );
	$view_url   = ( $event_id && class_exists( 'LNKup_Organizer_Onboarding' ) ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : '';
	$apply_base = function_exists( 'lnkup_page_url_helper' ) ? lnkup_page_url_helper( 'opportunities' ) : '';

	$fee         = null;
	$fee_max     = null;
	$fee_known   = false;
	$accepting   = false;
	$open_spots  = 0;
	$spots_left  = false;
	$closing     = false;
	$apply_id    = 0;
	$app_status  = '';
	$app_raw     = '';
	$cats        = array();
	$slot_rows   = array();
	foreach ( $slots as $slot ) {
		$open        = ! isset( $slot['is_open'] ) || ! empty( $slot['is_open'] );
		$left        = (int) ( $slot['spots_remaining'] ?? 0 );
		$slot_status = (string) ( $slot['application_status'] ?? '' );
		if ( $left > 0 ) {
			$spots_left = true;
		}
		if ( in_array( 'closing_soon', (array) ( $slot['badges'] ?? array() ), true ) ) {
			$closing = true;
		}
		if ( '' === $app_status && '' !== $slot_status ) {
			$app_raw    = $slot_status;
			$app_status = lnkup_application_status_label( $slot_status );
		}
		if ( $bookable && $open && $left > 0 ) {
			$accepting   = true;
			$open_spots += $left;
			$row_fee     = null;
			if ( array_key_exists( 'vendor_fee', $slot ) && '' !== $slot['vendor_fee'] && null !== $slot['vendor_fee'] ) {
				$row_fee = (float) $slot['vendor_fee'];
				if ( ! $fee_known || $row_fee < $fee ) {
					$fee       = $row_fee;
					$fee_known = true;
				}
				if ( null === $fee_max || $row_fee > $fee_max ) {
					$fee_max = $row_fee;
				}
			}
			$slot_id = (int) ( $slot['opportunity_id'] ?? $slot['id'] ?? 0 );
			if ( ! $apply_id && $slot_id && '' === $slot_status ) {
				$apply_id = $slot_id;
			}
			$row_label = '';
			$slot_cats = array_values( array_filter( (array) ( $slot['categories'] ?? array() ) ) );
			if ( $slot_cats ) {
				$row_label = lnkup_display_category_badge( (string) $slot_cats[0] );
			}
			if ( '' === $row_label ) {
				$row_label = lnkup_display_category_badge( (string) ( $slot['opportunity_title'] ?? $slot['name'] ?? '' ) );
			}
			if ( '' === $row_label ) {
				$row_label = __( 'Vendor slot', 'lnkup-marketplace' );
			}
			$slot_rows[] = array(
				'label' => $row_label,
				'fee'   => $row_fee,
				'left'  => $left,
			);
		}
		foreach ( (array) ( $slot['categories'] ?? array() ) as $cat ) {
			$label = lnkup_canonical_category_label( (string) $cat );
			if ( '' !== $label ) {
				$cats[ strtolower( $label ) ] = $label;
			}
		}
	}

	if ( ! $bookable ) {
		$status_key = 'external';
		$status     = __( 'External listing', 'lnkup-marketplace' );
	} elseif ( $accepting && $closing ) {
		$status_key = 'closing';
		$status     = __( 'Closing soon', 'lnkup-marketplace' );
	} elseif ( $accepting ) {
		$status_key = 'open';
		$status     = __( 'Accepting vendors', 'lnkup-marketplace' );
	} elseif ( ! $spots_left ) {
		$status_key = 'sold_out';
		$status     = __( 'Sold out', 'lnkup-marketplace' );
	} else {
		$status_key = 'closed';
		$status     = __( 'Applications closed', 'lnkup-marketplace' );
	}

	if ( $bookable && $fee_known ) {
		$fee_kind  = $fee > 0 ? 'from' : 'free';
		$fee_label = $fee > 0
			? ( null !== $fee_max && $fee_max > $fee
				? sprintf( /* translators: %s: lowest booth fee */ __( 'From %s', 'lnkup-marketplace' ), lnkup_public_money( $fee ) )
				: lnkup_public_money( $fee ) )
			: __( 'Free', 'lnkup-marketplace' );
	} elseif ( ! $bookable ) {
		$fee_kind  = 'external';
		$fee_label = __( 'Fee set by the organizer', 'lnkup-marketplace' );
	} elseif ( 'open' === $status_key || 'closing' === $status_key ) {
		$fee_kind  = 'unavailable';
		$fee_label = __( 'Fee unavailable', 'lnkup-marketplace' );
	} else {
		$fee_kind  = 'none';
		$fee_label = '';
	}

	$apply_url  = ( 'feed' === $context && $bookable && $accepting && $apply_id && $apply_base ) ? add_query_arg( 'apply', $apply_id, $apply_base ) : '';
	$status_url = ( '' !== $app_status && ! $apply_url && function_exists( 'lnkup_page_url_helper' ) ) ? add_query_arg( 'view', 'applications', lnkup_page_url_helper( 'vendor-dashboard' ) ) : '';
	$can_save   = ( 'feed' === $context && $event_id && is_user_logged_in() && class_exists( 'LNKup_Roles' ) && ( LNKup_Roles::is_vendor() || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all() ) ) );
	$labels     = array();
	foreach ( $slot_rows as $row ) {
		$labels[ strtolower( $row['label'] ) ] = true;
	}
	$img    = lnkup_opportunity_illustration_url( $first );
	$img_id = (int) ( $first['featured_image_id'] ?? 0 );
	if ( '' === $img && $event_id ) {
		// Some row sources (storefronts, legacy feeds) carry no image; use the event's own artwork.
		$img_id = (int) get_post_meta( $event_id, '_lnkup_featured_image_id', true );
		$img    = $img_id ? lnkup_sanitize_card_image_url( (string) wp_get_attachment_image_url( $img_id, 'medium_large' ) ) : '';
	}

	return array(
		'event_id'          => $event_id,
		'title'             => $title,
		'organizer'         => trim( (string) ( $first['organizer_name'] ?? $org_name ) ),
		'place'             => $place,
		'date'              => $date,
		'when'              => $date ? date_i18n( 'M j, Y', strtotime( $date ) ) : '',
		'when_short'        => $date ? date_i18n( 'M j', strtotime( $date ) ) : '',
		'weekday'           => $date ? date_i18n( 'D', strtotime( $date ) ) : '',
		'bookable'          => $bookable,
		'accepting'         => $accepting,
		'status_key'        => $status_key,
		'status'            => $status,
		'fee_kind'          => $fee_kind,
		'fee_label'         => $fee_label,
		'fee_min'           => $fee,
		'fee_max'           => $fee_max,
		'open_spots'        => $open_spots,
		'slot_rows'         => $slot_rows,
		'slot_types'        => count( $labels ),
		'categories'        => array_values( $cats ),
		'img'               => $img,
		'img_alt'           => function_exists( 'lnkup_opportunity_card_alt' ) ? lnkup_opportunity_card_alt( $first ) : $title,
		'featured_image_id' => $img_id,
		'view_url'          => $view_url,
		'apply_url'         => $apply_url,
		'status_url'        => $status_url,
		'app_status'        => $app_status,
		'app_status_raw'    => $app_raw,
		'can_save'          => $can_save,
		'saved'             => $can_save && class_exists( 'LNKup_Vendor_Personalization' ) && LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'events', $event_id ),
	);
}

/**
 * Artwork block shared by cards and the event page: the event's own image shown whole (contained
 * over a blurred copy, so flyer text is never cropped off), or an LNKup poster when there is none.
 *
 * @param array  $summary Card summary.
 * @param string $loading lazy|eager.
 */
function lnkup_event_art_html( array $summary, $loading = 'lazy' ) {
	$img = (string) ( $summary['img'] ?? '' );
	if ( '' === $img ) {
		$words    = preg_split( '/\s+/', trim( (string) ( $summary['title'] ?? '' ) ) );
		$initials = strtoupper( implode( '', array_map( static fn( $w ) => mb_substr( (string) $w, 0, 1 ), array_slice( array_filter( (array) $words ), 0, 2 ) ) ) );
		return sprintf(
			'<div class="lnkup-art lnkup-art--fallback" role="img" aria-label="%1$s"><span class="lnkup-art__mark" aria-hidden="true">%2$s</span><span class="lnkup-art__brand" aria-hidden="true">LNKup</span></div>',
			esc_attr( (string) ( $summary['title'] ?? '' ) ),
			esc_html( $initials )
		);
	}
	$srcset = '';
	$id     = (int) ( $summary['featured_image_id'] ?? 0 );
	if ( $id ) {
		$set = wp_get_attachment_image_srcset( $id, 'medium_large' );
		if ( $set ) {
			$srcset = sprintf( ' srcset="%1$s" sizes="(max-width: 640px) 100vw, (max-width: 1100px) 50vw, 400px"', esc_attr( $set ) );
		}
	}
	return sprintf(
		'<div class="lnkup-art"><img class="lnkup-art__blur" src="%1$s" alt="" aria-hidden="true" loading="%3$s" decoding="async" /><img class="lnkup-art__img" src="%1$s" alt="%2$s" loading="%3$s" decoding="async"%4$s /></div>',
		esc_url( $img ),
		esc_attr( (string) ( $summary['img_alt'] ?? '' ) ),
		esc_attr( 'eager' === $loading ? 'eager' : 'lazy' ),
		$srcset
	);
}

/**
 * Availability line for a card: spots left, sold out, or closed.
 *
 * @param array $summary Card summary.
 */
function lnkup_event_card_availability( array $summary ) {
	switch ( $summary['status_key'] ) {
		case 'sold_out':
		case 'closed':
			// The status badge on the artwork already says so.
			return '';
		case 'external':
			return __( 'Listed from the organizer’s site', 'lnkup-marketplace' );
	}
	$spots = (int) $summary['open_spots'];
	$types = (int) $summary['slot_types'];
	$bits  = array();
	if ( $spots > 0 ) {
		$bits[] = $spots <= 5
			? sprintf( /* translators: %d: spots */ _n( 'Only %d spot left', 'Only %d spots left', $spots, 'lnkup-marketplace' ), $spots )
			: sprintf( /* translators: %d: spots */ _n( '%d spot open', '%d spots open', $spots, 'lnkup-marketplace' ), $spots );
	}
	if ( $types > 1 ) {
		$bits[] = sprintf( /* translators: %d: booth types */ __( '%d booth types', 'lnkup-marketplace' ), $types );
	}
	return implode( ' · ', $bits );
}

/**
 * Public event card markup. Scan order: event, date and city, vendor fit, fee and availability,
 * next action.
 *
 * @param array  $s       Card summary from lnkup_event_card_summary().
 * @param string $context home|feed|storefront.
 */
function lnkup_render_event_card_from_summary( array $s, $context = 'home' ) {
	if ( ! $s ) {
		return '';
	}
	$action_url   = $s['view_url'];
	$action_label = __( 'View event', 'lnkup-marketplace' );
	$action_class = 'lnkup-btn lnkup-btn--outline lnkup-btn--sm';
	if ( 'storefront' === $context && $s['view_url'] ) {
		$action_url   = $s['view_url'] . '#lnkup-vendor-options';
		$action_label = __( 'View vendor spots', 'lnkup-marketplace' );
		$action_class = 'lnkup-btn lnkup-btn--primary lnkup-btn--sm';
	}
	if ( $s['apply_url'] ) {
		$action_label = __( 'Details', 'lnkup-marketplace' );
	}
	$fit   = array_slice( $s['categories'], 0, 2 );
	$extra = count( $s['categories'] ) - count( $fit );
	$avail = lnkup_event_card_availability( $s );
	$urgent = 'open' === $s['status_key'] && $s['open_spots'] > 0 && $s['open_spots'] <= 5;
	ob_start();
	?>
	<article class="lnkup-event-card lnkup-event-card--v2 lnkup-event-card--public lnkup-event-card--x" data-event-id="<?php echo esc_attr( (string) $s['event_id'] ); ?>" data-status="<?php echo esc_attr( $s['status_key'] ); ?>">
		<div class="lnkup-event-card__media">
			<?php echo lnkup_event_art_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php if ( $s['when_short'] ) : ?>
				<span class="lnkup-event-card__date" aria-hidden="true"><span><?php echo esc_html( $s['weekday'] ); ?></span><?php echo esc_html( $s['when_short'] ); ?></span>
			<?php endif; ?>
			<div class="lnkup-event-card__badges-top">
				<?php if ( 'open' !== $s['status_key'] ) : ?>
					<span class="lnkup-badge lnkup-badge--status lnkup-badge--<?php echo esc_attr( $s['status_key'] ); ?>"><?php echo esc_html( $s['status'] ); ?></span>
				<?php endif; ?>
				<?php if ( $s['app_status'] ) : ?>
					<span class="lnkup-badge lnkup-badge--mine"><?php echo esc_html( $s['app_status'] ); ?></span>
				<?php endif; ?>
			</div>
			<?php if ( $s['can_save'] ) : ?>
				<button type="button" class="lnkup-event-card__favorite<?php echo $s['saved'] ? ' is-active' : ''; ?>" data-favorite-type="events" data-favorite-id="<?php echo esc_attr( (string) $s['event_id'] ); ?>" aria-pressed="<?php echo $s['saved'] ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event */ __( 'Save %s', 'lnkup-marketplace' ), $s['title'] ) ); ?>"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-7.5-10.1A4.4 4.4 0 0 1 12 7.6a4.4 4.4 0 0 1 7.5 2.8c0 5.5-7.5 10.1-7.5 10.1Z" fill="currentColor"/></svg></button>
			<?php endif; ?>
		</div>
		<div class="lnkup-event-card__body">
			<h3 class="lnkup-event-card__title">
				<?php if ( $s['view_url'] ) : ?>
					<a href="<?php echo esc_url( $s['view_url'] ); ?>"><?php echo esc_html( $s['title'] ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $s['title'] ); ?>
				<?php endif; ?>
			</h3>
			<?php if ( $s['place'] || $s['when'] ) : ?>
				<p class="lnkup-event-card__meta"><?php echo esc_html( trim( $s['when'] . ( $s['place'] && $s['when'] ? ' · ' : '' ) . $s['place'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $fit || $s['organizer'] ) : ?>
				<p class="lnkup-event-card__fit">
					<?php if ( $fit ) : ?>
						<span class="lnkup-event-card__cats"><?php echo esc_html( implode( ', ', $fit ) . ( $extra > 0 ? ' +' . $extra : '' ) ); ?></span>
					<?php endif; ?>
					<?php if ( $s['organizer'] ) : ?>
						<span class="lnkup-event-card__organizer"><?php echo esc_html( sprintf( /* translators: %s: organizer */ __( 'by %s', 'lnkup-marketplace' ), $s['organizer'] ) ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<div class="lnkup-event-card__deal">
				<?php if ( '' !== $s['fee_label'] ) : ?>
					<p class="lnkup-event-card__fee lnkup-fee lnkup-fee--<?php echo esc_attr( $s['fee_kind'] ); ?>"><?php echo esc_html( $s['fee_label'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $avail ) : ?>
					<p class="lnkup-event-card__slot-count<?php echo $urgent ? ' is-urgent' : ''; ?>"><?php echo esc_html( $avail ); ?></p>
				<?php endif; ?>
			</div>
			<div class="lnkup-event-card__footer">
				<?php if ( $action_url ) : ?>
					<a class="<?php echo esc_attr( $action_class ); ?>" href="<?php echo esc_url( $action_url ); ?>" aria-label="<?php echo esc_attr( $action_label . ': ' . $s['title'] ); ?>"><?php echo esc_html( $action_label ); ?></a>
				<?php endif; ?>
				<?php if ( $s['apply_url'] ) : ?>
					<a class="lnkup-btn lnkup-btn--primary lnkup-btn--sm" href="<?php echo esc_url( $s['apply_url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: event */ __( 'Apply to %s', 'lnkup-marketplace' ), $s['title'] ) ); ?>"><?php esc_html_e( 'Apply', 'lnkup-marketplace' ); ?></a>
				<?php elseif ( $s['status_url'] ) : ?>
					<a class="lnkup-btn lnkup-btn--secondary lnkup-btn--sm lnkup-event-card__status" href="<?php echo esc_url( $s['status_url'] ); ?>"><?php echo esc_html( sprintf( /* translators: %s: application status */ __( 'Application: %s', 'lnkup-marketplace' ), $s['app_status'] ) ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</article>
	<?php
	return (string) ob_get_clean();
}

/**
 * Published tours and series with at least one upcoming stop accepting vendors, for the homepage.
 * Uses the same public tour data as the tour page, so stop dates, cities and fees match it.
 *
 * @param int $limit Maximum tours.
 * @return array<int, array{title:string,type:string,url:string,organizer:string,stops:array<int,array{label:string,date:string,accepting:bool,url:string}>,stop_count:int,open_count:int,fee_min:?float}>
 */
function lnkup_public_tour_summaries( $limit = 3 ) {
	if ( ! class_exists( 'LNKup_Portfolio_Public' ) || ! class_exists( 'LNKup_Feature_Flags' ) ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'      => 'lnkup_tour',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	$out = array();
	foreach ( $ids as $tour_id ) {
		$owner = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		if ( ! LNKup_Feature_Flags::portfolio( $owner ) ) {
			continue;
		}
		$data = LNKup_Portfolio_Public::data( (int) $tour_id, 0 );
		if ( is_wp_error( $data ) || empty( $data['accepting'] ) || empty( $data['url'] ) ) {
			continue;
		}
		$stops    = array();
		$open     = 0;
		$fee_min  = null;
		foreach ( (array) $data['stops'] as $stop ) {
			$open += ! empty( $stop['accepting'] ) ? 1 : 0;
			if ( ! empty( $stop['accepting'] ) && null !== $stop['fee_min'] && ( null === $fee_min || $stop['fee_min'] < $fee_min ) ) {
				$fee_min = (float) $stop['fee_min'];
			}
			$stops[] = array(
				'label'     => lnkup_display_place( (string) ( $stop['city'] ?? '' ), (string) ( $stop['state'] ?? '' ) ),
				'date'      => ! empty( $stop['date'] ) ? date_i18n( 'M j', strtotime( (string) $stop['date'] ) ) : '',
				'accepting' => ! empty( $stop['accepting'] ),
				'url'       => (string) ( $stop['event_url'] ?? '' ),
			);
		}
		$out[] = array(
			'title'      => (string) $data['title'],
			'type'       => (string) $data['type'],
			'url'        => (string) $data['url'],
			'organizer'  => (string) ( $data['organizer']['name'] ?? '' ),
			'stops'      => $stops,
			'stop_count' => count( $stops ),
			'open_count' => $open,
			'fee_min'    => $fee_min,
		);
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

/**
 * Event summaries for public discovery surfaces, grouped one per event.
 *
 * @param int  $limit         Maximum events.
 * @param bool $require_image Only events with their own artwork.
 * @return array<int, array<string, mixed>>
 */
function lnkup_public_event_summaries( $limit = 6, $require_image = false ) {
	if ( ! function_exists( 'lnkup_get_featured_opportunities' ) ) {
		return array();
	}
	$rows = lnkup_get_featured_opportunities( max( 12, $limit * 4 ), array( 'curate_homepage' => true ) );
	$out  = array();
	foreach ( lnkup_group_opportunities_by_event( $rows ) as $slots ) {
		$summary = lnkup_event_card_summary( $slots, 'home' );
		if ( ! $summary || ! $summary['accepting'] ) {
			continue;
		}
		if ( $require_image && '' === $summary['img'] ) {
			continue;
		}
		$out[] = $summary;
		if ( count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}
