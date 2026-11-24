<?php
/**
 * Public event page honesty notices and follow/alert actions (flag seo_index_policy).
 *
 * Notices: cancelled / postponed / rescheduled / ended banners, authenticity notices
 * (LNKup_Event_Authenticity::public_label) and the external-listing disclosure (source, last checked
 * date, "not managed on LNKup", no apply or pay buttons; the page already hides them).
 * Actions: Follow tour (favorite type `tours`) and "Get alerts for similar events" when nothing is
 * open. The alert routes into the market alert flow, which reuses saved searches.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Distribution {

	const FAVORITE_TYPES = array( 'events', 'organizers', 'opportunities', 'tours' );

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
	}

	public static function assets(): void {
		if ( ! self::enabled() || ! is_singular( 'lnkup_event' ) ) {
			return;
		}
		if ( is_user_logged_in() ) {
			wp_enqueue_script( 'lnkup-event-follow', LNKUP_PLUGIN_URL . 'public/js/event-follow.js', array(), LNKUP_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		}
		wp_add_inline_style( 'lnkup-components', '.lnkup-event-notice{display:grid;gap:4px;margin:0 0 12px;padding:12px 14px;border-radius:14px;background:#f4f6ef;border:1px solid #e3e7da;font-size:14px;line-height:1.45}.lnkup-event-notice--cancelled,.lnkup-event-notice--authenticity{background:#fff4f2;border-color:#f3c9c0}.lnkup-event-notice--postponed,.lnkup-event-notice--rescheduled{background:#fff8e6;border-color:#f1dfae}.lnkup-event-notice small{color:#5b6150}' );
	}

	public static function enabled(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'seo_index_policy' );
	}

	/**
	 * Validate a favorite toggle. Tours must be published.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_favorite( string $type, int $id ) {
		if ( ! in_array( $type, self::FAVORITE_TYPES, true ) || $id < 1 ) {
			return new WP_Error( 'lnkup_invalid_favorite', __( 'That item cannot be saved.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( 'tours' === $type && ( 'lnkup_tour' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) ) {
			return new WP_Error( 'lnkup_invalid_favorite', __( 'That tour is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/**
	 * Users following a tour (for new-stop notifications).
	 *
	 * @return int[]
	 */
	public static function tour_followers( int $tour_id, int $limit = 500 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'i:' . $tour_id . ';' ) . '%';
		$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'lnkup_favorites' AND meta_value LIKE %s LIMIT %d", $like, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$out  = array();
		foreach ( array_map( 'intval', (array) $ids ) as $uid ) {
			if ( class_exists( 'LNKup_Vendor_Personalization' ) && LNKup_Vendor_Personalization::is_favorite( $uid, 'tours', $tour_id ) ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/**
	 * Status, authenticity and external-source notices for the event hero.
	 */
	public static function hero_notices( int $event_id ): string {
		if ( ! self::enabled() ) {
			return '';
		}
		$notices = array();
		$status  = strtolower( (string) get_post_meta( $event_id, '_lnkup_event_status', true ) );
		$date    = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$end     = (string) get_post_meta( $event_id, '_lnkup_event_end_date', true );
		$last    = '' !== $end ? $end : $date;
		if ( in_array( $status, array( 'cancelled', 'canceled' ), true ) ) {
			$notices[] = array( 'cancelled', __( 'This event has been cancelled.', 'lnkup-marketplace' ), __( 'Vendor applications are closed. Browse other events in this area below.', 'lnkup-marketplace' ) );
		} elseif ( 'postponed' === $status ) {
			$notices[] = array( 'postponed', __( 'This event has been postponed.', 'lnkup-marketplace' ), __( 'A new date has not been announced yet. Save the event to hear about changes.', 'lnkup-marketplace' ) );
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $last ) && $last < current_time( 'Y-m-d' ) ) {
			$notices[] = array( 'ended', __( 'This event has ended.', 'lnkup-marketplace' ), __( 'Look for upcoming events nearby below.', 'lnkup-marketplace' ) );
		} else {
			$previous = (string) get_post_meta( $event_id, '_lnkup_seo_previous_start', true );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $previous ) && $previous !== $date ) {
				/* translators: %s: previous date */
				$notices[] = array( 'rescheduled', __( 'This event has a new date.', 'lnkup-marketplace' ), sprintf( __( 'It was previously scheduled for %s.', 'lnkup-marketplace' ), date_i18n( 'M j, Y', strtotime( $previous ) ) ) );
			}
		}

		if ( class_exists( 'LNKup_Event_Authenticity' ) ) {
			$auth = LNKup_Event_Authenticity::public_label( $event_id );
			if ( in_array( $auth['status'], array( 'claim_pending', 'disputed', 'restricted' ), true ) ) {
				$notices[] = array( 'authenticity', (string) $auth['label'], (string) $auth['description'] );
			}
		}

		$html = '';
		foreach ( $notices as $n ) {
			$html .= sprintf(
				'<div class="lnkup-event-notice lnkup-event-notice--%1$s" role="status" data-testid="lnkup-event-notice-%1$s"><strong>%2$s</strong> <span>%3$s</span></div>',
				esc_attr( $n[0] ),
				esc_html( $n[1] ),
				esc_html( $n[2] )
			);
		}
		return $html . self::external_disclosure( $event_id );
	}

	/**
	 * Disclosure for listings gathered from public sources and not yet managed on LNKup.
	 */
	public static function external_disclosure( int $event_id ): string {
		if ( ! class_exists( 'LNKup_Organizer_Onboarding' ) || ! LNKup_Organizer_Onboarding::event_is_unclaimed_external( $event_id ) ) {
			return '';
		}
		$row      = class_exists( 'LNKup_External_Events' ) ? LNKup_External_Events::get_row( (int) get_post_meta( $event_id, '_lnkup_external_event_id', true ) ) : null;
		$source   = (string) ( $row['source_url'] ?? get_post_meta( $event_id, '_lnkup_external_source_url', true ) );
		$host     = $source ? (string) wp_parse_url( $source, PHP_URL_HOST ) : '';
		$platform = $row ? ucwords( str_replace( array( '_', '-' ), ' ', (string) $row['source_platform'] ) ) : '';
		$checked  = (string) ( $row['last_synced_at'] ?? '' );
		$checked  = '' !== $checked ? $checked : (string) ( $row['updated_at'] ?? '' );
		$parts    = array();
		if ( '' !== $platform || '' !== $host ) {
			/* translators: %s: source name */
			$parts[] = sprintf( __( 'Source: %s', 'lnkup-marketplace' ), trim( $platform . ( $host ? ' (' . preg_replace( '/^www\./', '', $host ) . ')' : '' ) ) );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $checked ) ) {
			/* translators: %s: date */
			$parts[] = sprintf( __( 'Last checked %s', 'lnkup-marketplace' ), date_i18n( 'M j, Y', strtotime( $checked ) ) );
		}
		ob_start();
		?>
		<div class="lnkup-event-notice lnkup-event-notice--external" data-testid="lnkup-event-external-disclosure">
			<strong><?php esc_html_e( 'Listed from public information', 'lnkup-marketplace' ); ?></strong>
			<span><?php esc_html_e( 'This event is not managed by its organizer on LNKup. You cannot apply or pay through LNKup for it; contact the organizer through the original listing.', 'lnkup-marketplace' ); ?></span>
			<?php if ( $parts ) : ?><small><?php echo esc_html( implode( ' · ', $parts ) ); ?></small><?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Extra hero actions: follow tour, alerts for similar events when nothing is open.
	 */
	public static function hero_actions( int $event_id, int $open_spots, bool $guest ): string {
		if ( ! self::enabled() ) {
			return '';
		}
		$html    = '';
		$tour_id = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		$tour    = ( $tour_id && class_exists( 'LNKup_Tours' ) ) ? LNKup_Tours::card_summary( $tour_id ) : null;
		if ( $tour ) {
			$is_vendor = is_user_logged_in() && class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_vendor();
			if ( $is_vendor ) {
				$on    = class_exists( 'LNKup_Vendor_Personalization' ) && LNKup_Vendor_Personalization::is_favorite( get_current_user_id(), 'tours', $tour_id );
				$html .= sprintf(
					'<button type="button" class="lnkup-btn lnkup-btn--outline lnkup-follow-tour%1$s" data-testid="lnkup-follow-tour" data-tour-id="%2$d" aria-pressed="%3$s" data-label-on="%4$s" data-label-off="%5$s">%6$s</button>',
					$on ? ' is-active' : '',
					$tour_id,
					$on ? 'true' : 'false',
					esc_attr__( 'Following tour', 'lnkup-marketplace' ),
					esc_attr__( 'Follow tour', 'lnkup-marketplace' ),
					esc_html( $on ? __( 'Following tour', 'lnkup-marketplace' ) : __( 'Follow tour', 'lnkup-marketplace' ) )
				);
			} elseif ( ! is_user_logged_in() ) {
				$base   = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : wp_registration_url();
				$signup = add_query_arg( array( 'role' => 'vendor', 'redirect' => rawurlencode( (string) get_permalink( $event_id ) ) ), $base );
				$html  .= sprintf( '<a class="lnkup-btn lnkup-btn--outline" data-testid="lnkup-follow-tour" href="%s">%s</a>', esc_url( $signup ), esc_html__( 'Follow tour', 'lnkup-marketplace' ) );
			}
		}
		if ( $open_spots < 1 && ! $guest ) {
			$alert = self::similar_alert_url( $event_id );
			if ( '' !== $alert ) {
				$html .= sprintf( '<a class="lnkup-btn lnkup-btn--ghost" data-testid="lnkup-event-similar-alert" href="%s">%s</a>', esc_url( $alert ), esc_html__( 'Get alerts for similar events', 'lnkup-marketplace' ) );
			}
		}
		return $html;
	}

	/**
	 * Market alert anchor when the city has a market; otherwise the city-filtered feed.
	 */
	public static function similar_alert_url( int $event_id ): string {
		$city  = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state = (string) get_post_meta( $event_id, '_lnkup_state', true );
		if ( '' === $city ) {
			return '';
		}
		$market = class_exists( 'LNKup_SEO_Schema' ) ? LNKup_SEO_Schema::market_for_city( $city, $state ) : null;
		if ( $market && ! empty( $market['slug'] ) ) {
			return LNKup_Market_Pages::market_url( (string) $market['slug'] ) . '#lnkup-market-alerts';
		}
		return add_query_arg( 'city', $city, lnkup_page_url_helper( 'opportunities' ) );
	}
}
