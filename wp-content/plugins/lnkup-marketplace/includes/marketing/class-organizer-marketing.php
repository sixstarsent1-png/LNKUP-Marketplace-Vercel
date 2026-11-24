<?php
/**
 * Organizer Marketing toolkit (flag organizer_marketing): Organizer dashboard → Marketing.
 *
 * Share and promote with tracked links and local QR codes, graphics rendered in the browser from
 * real event data, editable copy templates (never auto-published), embeddable lists, and per-link
 * performance with fact-based suggestions. Every endpoint checks event ownership (or the team
 * `broadcast` capability); links are LNKup_Tracking_Links records owned by the organizer.
 *
 * Public list embeds (/public/embeds/...) reuse LNKup_Public_Embed card data, are rate limited
 * and expose only fields already public on LNKup.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Organizer_Marketing {

	const NS              = 'lnkup/v1';
	const EMBED_RATE      = 300;
	const EMBED_CACHE_TTL = 300;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
	}

	public static function enabled(): bool {
		return class_exists( 'LNKup_Marketing_Flags' ) && LNKup_Marketing_Flags::enabled( 'organizer_marketing' );
	}

	public static function can_use( int $user_id = 0 ): bool {
		$user_id = $user_id ?: get_current_user_id();
		if ( $user_id < 1 || ! self::enabled() ) {
			return false;
		}
		return ( class_exists( 'LNKup_Roles' ) && LNKup_Roles::is_organizer( $user_id ) ) || ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	/* ------------------------------------------------------------ Routes */

	public static function routes(): void {
		$auth = static fn(): bool => self::can_use();
		$id   = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		register_rest_route( self::NS, '/organizer/marketing/context', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_context' ), 'permission_callback' => $auth ) );
		register_rest_route(
			self::NS,
			'/organizer/marketing/links',
			array(
				array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_links' ), 'permission_callback' => $auth ),
				array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_create_link' ), 'permission_callback' => $auth ),
			)
		);
		register_rest_route( self::NS, '/organizer/marketing/links/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_link_status' ), 'permission_callback' => $auth, 'args' => $id ) );
		register_rest_route( self::NS, '/organizer/marketing/performance', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_performance' ), 'permission_callback' => $auth ) );
		register_rest_route( self::NS, '/public/embeds/events/(?P<id>\d+)/opportunities', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_embed_event' ), 'permission_callback' => '__return_true', 'args' => $id ) );
		register_rest_route( self::NS, '/public/embeds/tours/(?P<id>\d+)/stops', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_embed_tour' ), 'permission_callback' => '__return_true', 'args' => $id ) );
	}

	/**
	 * @return true|WP_Error
	 */
	private static function require_event( int $event_id ) {
		if ( $event_id < 1 || ! class_exists( 'LNKup_Tracking_Links' ) || ! LNKup_Tracking_Links::can_promote_event( $event_id, get_current_user_id() ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only promote events you manage.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_context( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$events  = array();
		foreach ( self::event_ids_for( $user_id ) as $event_id ) {
			$events[] = self::event_payload( $event_id );
		}
		$tours = array();
		if ( class_exists( 'LNKup_Tours' ) ) {
			foreach ( (array) LNKup_Tours::list_for_organizer( $user_id ) as $tour ) {
				$tid = (int) ( $tour['id'] ?? 0 );
				if ( $tid && 'publish' === get_post_status( $tid ) ) {
					$tours[] = array( 'id' => $tid, 'title' => html_entity_decode( get_the_title( $tid ), ENT_QUOTES, 'UTF-8' ), 'url' => (string) get_permalink( $tid ) );
				}
			}
		}
		$logo_id = (int) get_user_meta( $user_id, '_lnkup_organizer_logo_id', true );
		return rest_ensure_response(
			array(
				'events'    => $events,
				'tours'     => $tours,
				'organizer' => array(
					'id'          => $user_id,
					'name'        => self::organizer_name( $user_id ),
					'logo'        => $logo_id ? self::local_image( (string) wp_get_attachment_image_url( $logo_id, 'medium' ) ) : '',
					'profile_url' => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::organizer_public_url( $user_id ) : '',
				),
				'channels'  => array_values( array_diff( LNKup_Tracking_Links::CHANNELS, array( 'app' ) ) ),
				'copy'      => self::copy_templates(),
				'embed'     => array(
					'apply_script' => LNKUP_PLUGIN_URL . 'public/js/embed.js',
					'list_script'  => LNKUP_PLUGIN_URL . 'public/js/embed-list.js',
				),
			)
		);
	}

	public static function rest_links( WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$args     = array( 'limit' => 200 );
		if ( $event_id ) {
			$ok = self::require_event( $event_id );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$rows = LNKup_Tracking_Links::list_for_owner( get_current_user_id(), $args );
		if ( $event_id ) {
			$opps = self::opportunity_ids( $event_id );
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => ( 'event' === $r['target_type'] && (int) $r['target_id'] === $event_id ) || ( 'opportunity' === $r['target_type'] && in_array( (int) $r['target_id'], $opps, true ) )
				)
			);
		}
		return rest_ensure_response( array( 'links' => array_map( array( __CLASS__, 'public_link' ), $rows ) ) );
	}

	public static function rest_create_link( WP_REST_Request $request ) {
		$body = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$type = sanitize_key( (string) ( $body['target_type'] ?? 'event' ) );
		if ( ! in_array( $type, array( 'event', 'opportunity', 'tour', 'organizer', 'market', 'category', 'signup' ), true ) ) {
			return new WP_Error( 'lnkup_link_target', __( 'Choose what this link should open.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$result = LNKup_Tracking_Links::create(
			array(
				'owner_user_id'    => get_current_user_id(),
				'owner_type'       => 'organizer',
				'target_type'      => $type,
				'target_id'        => absint( $body['target_id'] ?? 0 ),
				'target_key'       => (string) ( $body['target_key'] ?? '' ),
				'label'            => (string) ( $body['label'] ?? '' ),
				'channel'          => (string) ( $body['channel'] ?? 'other' ),
				'campaign'         => (string) ( $body['campaign'] ?? '' ),
				'forward_referral' => 'signup' === $type,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'link' => self::public_link( array_merge( $result, array( 'clicks' => 0, 'unique_clicks' => 0, 'signups' => 0, 'applications' => 0, 'bookings' => 0 ) ) ) ) );
	}

	public static function rest_link_status( WP_REST_Request $request ) {
		$body   = (array) ( $request->get_json_params() ?: $request->get_body_params() );
		$result = LNKup_Tracking_Links::set_status( (int) $request['id'], sanitize_key( (string) ( $body['status'] ?? '' ) ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'link' => self::public_link( $result ) ) );
	}

	/**
	 * Link fields the organizer UI needs (no internal owner columns).
	 *
	 * @param array<string,mixed> $row Link.
	 * @return array<string,mixed>
	 */
	public static function public_link( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'code'         => (string) $row['code'],
			'url'          => LNKup_Tracking_Links::url( (string) $row['code'] ),
			'target_type'  => (string) $row['target_type'],
			'target_id'    => (int) $row['target_id'],
			'target_key'   => (string) $row['target_key'],
			'target_url'   => LNKup_Tracking_Links::target_url( $row ),
			'label'        => (string) $row['label'],
			'channel'      => (string) $row['channel'],
			'campaign'     => (string) $row['campaign'],
			'status'       => (string) $row['status'],
			'created_at'   => (string) $row['created_at'],
			'clicks'       => (int) ( $row['clicks'] ?? 0 ),
			'unique_clicks' => (int) ( $row['unique_clicks'] ?? 0 ),
			'signups'      => (int) ( $row['signups'] ?? 0 ),
			'applications' => (int) ( $row['applications'] ?? 0 ),
			'bookings'     => (int) ( $row['bookings'] ?? 0 ),
		);
	}

	public static function rest_performance( WP_REST_Request $request ) {
		$event_id = absint( $request->get_param( 'event_id' ) );
		$ok       = self::require_event( $event_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$days  = max( 7, min( 365, (int) ( $request->get_param( 'days' ) ?: 30 ) ) );
		$links = (array) self::rest_links( $request )->get_data()['links'];
		$from  = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days', strtotime( current_time( 'Y-m-d' ) ) ) );
		$daily = LNKup_Tracking_Links::daily( wp_list_pluck( $links, 'id' ), $from, current_time( 'Y-m-d' ) );
		$event = self::event_metrics( $event_id );
		return rest_ensure_response(
			array(
				'event'           => $event,
				'links'           => $links,
				'daily'           => $daily,
				'recommendations' => self::recommendations( $event_id, $event, $links ),
			)
		);
	}

	/* ------------------------------------------------------------ Data */

	/**
	 * Organizer's published events from 30 days ago onward (newest first), max 50.
	 *
	 * @return int[]
	 */
	public static function event_ids_for( int $user_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_event',
					'post_status'    => 'publish',
					'posts_per_page' => 50,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'orderby'        => 'meta_value',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_lnkup_organizer_user_id', 'value' => $user_id ),
						array( 'key' => '_lnkup_event_date', 'value' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ), 'compare' => '>=', 'type' => 'DATE' ),
					),
				)
			)
		);
	}

	/**
	 * @return int[]
	 */
	private static function opportunity_ids( int $event_id ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'lnkup_opportunity',
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => '_lnkup_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	private static function organizer_name( int $user_id ): string {
		$name = (string) get_user_meta( $user_id, '_lnkup_organizer_public_name', true );
		if ( '' === $name ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->display_name : '';
		}
		return $name;
	}

	/**
	 * Same-site image URL only (graphics are drawn on a canvas; remote images would taint it).
	 */
	private static function local_image( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$own  = wp_parse_url( home_url(), PHP_URL_HOST );
		return ( null === $host || strtolower( (string) $host ) === strtolower( (string) $own ) ) ? $url : '';
	}

	/**
	 * Public facts about one event for links, graphics and copy.
	 *
	 * @return array<string,mixed>
	 */
	public static function event_payload( int $event_id ): array {
		$date     = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
		$city     = (string) get_post_meta( $event_id, '_lnkup_city', true );
		$state    = (string) get_post_meta( $event_id, '_lnkup_state', true );
		$media    = class_exists( 'LNKup_Event_Media' ) ? LNKup_Event_Media::resolve( $event_id ) : array();
		$image    = self::local_image( (string) ( $media['large_url'] ?? $media['url'] ?? '' ) );
		$cats     = array();
		$open     = 0;
		$deadline = '';
		$opps     = array();
		foreach ( self::opportunity_ids( $event_id ) as $opp_id ) {
			$status = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
			$left   = (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
			$due    = (string) get_post_meta( $opp_id, '_lnkup_application_deadline', true );
			$is_open = ( '' === $status || 'open' === $status ) && $left > 0 && ( '' === $due || $due >= current_time( 'Y-m-d' ) );
			$names  = wp_get_object_terms( $opp_id, 'lnkup_vendor_category', array( 'fields' => 'names' ) );
			$labels = array();
			foreach ( is_wp_error( $names ) ? array() : $names as $name ) {
				$label = function_exists( 'lnkup_canonical_category_label' ) ? lnkup_canonical_category_label( (string) $name ) : (string) $name;
				if ( '' !== $label ) {
					$labels[] = $label;
				}
			}
			if ( $is_open ) {
				$open += $left;
				if ( '' !== $due && ( '' === $deadline || $due < $deadline ) ) {
					$deadline = $due;
				}
				foreach ( array_unique( $labels ) as $label ) {
					$cats[ $label ] = ( $cats[ $label ] ?? 0 ) + $left;
				}
			}
			$opps[] = array( 'id' => $opp_id, 'title' => html_entity_decode( get_the_title( $opp_id ), ENT_QUOTES, 'UTF-8' ), 'open' => $is_open, 'categories' => array_values( array_unique( $labels ) ) );
		}
		arsort( $cats );
		$tour_id = (int) get_post_meta( $event_id, '_lnkup_tour_id', true );
		$tour    = ( $tour_id && 'publish' === get_post_status( $tour_id ) ) ? array( 'id' => $tour_id, 'title' => html_entity_decode( get_the_title( $tour_id ), ENT_QUOTES, 'UTF-8' ) ) : null;
		$location = class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_location( $event_id ) : array();
		$title   = function_exists( 'lnkup_plain_title' ) ? lnkup_plain_title( get_post( $event_id ) ) : get_the_title( $event_id );
		return array(
			'id'            => $event_id,
			'title'         => html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ),
			'date'          => $date,
			'date_label'    => preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ? date_i18n( 'D, M j', strtotime( $date ) ) : '',
			'city'          => $city,
			'state'         => $state,
			'where'         => (string) ( $location['where'] ?? trim( $city . ( $state ? ', ' . $state : '' ) ) ),
			'url'           => class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : (string) get_permalink( $event_id ),
			'image'         => $image,
			'image_is_fallback' => empty( $media['uploaded'] ),
			'categories'    => array_map( static fn( $label, $spots ) => array( 'label' => $label, 'open_spots' => $spots ), array_keys( $cats ), array_values( $cats ) ),
			'open_spots'    => $open,
			'deadline'      => $deadline,
			'deadline_label' => '' !== $deadline ? date_i18n( 'M j', strtotime( $deadline ) ) : '',
			'opportunities' => $opps,
			'tour'          => $tour,
			'status'        => strtolower( (string) get_post_meta( $event_id, '_lnkup_event_status', true ) ),
		);
	}

	/**
	 * Real event metrics: index views, applications, approvals, paid bookings, open spots.
	 *
	 * @return array<string,int|string>
	 */
	public static function event_metrics( int $event_id ): array {
		global $wpdb;
		$views = 0;
		if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
			$views = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(view_count),0) FROM ' . LNKup_Marketplace_Index::table_name() . ' WHERE event_id = %d', $event_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		$count = static function ( string $type, array $meta ) use ( $event_id ): int {
			return count(
				get_posts(
					array(
						'post_type'      => $type,
						'post_status'    => array( 'publish', 'pending', 'private' ),
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => array_merge( array( array( 'key' => '_lnkup_event_id', 'value' => $event_id ) ), $meta ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					)
				)
			);
		};
		$payload = self::event_payload( $event_id );
		return array(
			'views'        => $views,
			'applications' => $count( 'lnkup_application', array( array( 'key' => '_lnkup_application_status', 'value' => array( 'draft', '' ), 'compare' => 'NOT IN' ) ) ),
			'approved'     => $count( 'lnkup_application', array( array( 'key' => '_lnkup_application_status', 'value' => array( 'approved', 'paid', 'confirmed', 'completed' ), 'compare' => 'IN' ) ) ),
			'bookings'     => $count( 'lnkup_payment', array( array( 'key' => '_lnkup_payment_status', 'value' => 'paid' ) ) ),
			'open_spots'   => (int) $payload['open_spots'],
			'deadline'     => (string) $payload['deadline'],
		);
	}

	/**
	 * Suggestions built only from facts on this event. Never promises results.
	 *
	 * @param array<string,mixed>            $metrics Event metrics.
	 * @param array<int,array<string,mixed>> $links   Links with totals.
	 * @return array<int,array<string,string>>
	 */
	public static function recommendations( int $event_id, array $metrics, array $links ): array {
		$out     = array();
		$payload = self::event_payload( $event_id );
		$today   = strtotime( current_time( 'Y-m-d' ) );
		if ( '' !== (string) $metrics['deadline'] && (int) $metrics['open_spots'] > 0 ) {
			$days = (int) floor( ( strtotime( (string) $metrics['deadline'] ) - $today ) / DAY_IN_SECONDS );
			if ( $days >= 0 && $days <= 7 ) {
				/* translators: 1: days, 2: open spots */
				$out[] = array( 'id' => 'deadline_soon', 'text' => sprintf( _n( 'Applications close in %1$d day and %2$d spots are still open. A "Closing soon" post is a common last reminder.', 'Applications close in %1$d days and %2$d spots are still open. A "Closing soon" post is a common last reminder.', max( 1, $days ), 'lnkup-marketplace' ), $days, (int) $metrics['open_spots'] ), 'tab' => 'graphics', 'template' => 'closing_soon' );
			}
		}
		foreach ( array_slice( (array) $payload['categories'], 0, 2 ) as $cat ) {
			/* translators: 1: open spots, 2: category */
			$out[] = array( 'id' => 'unfilled_' . sanitize_title( $cat['label'] ), 'text' => sprintf( _n( '%1$d %2$s spot is still open. A category-specific post reaches the right vendors.', '%1$d %2$s spots are still open. A category-specific post reaches the right vendors.', (int) $cat['open_spots'], 'lnkup-marketplace' ), (int) $cat['open_spots'], $cat['label'] ), 'tab' => 'graphics', 'template' => 'calling_category' );
		}
		if ( (int) $metrics['views'] >= 25 && 0 === (int) $metrics['applications'] ) {
			/* translators: %d: views */
			$out[] = array( 'id' => 'views_no_applications', 'text' => sprintf( __( 'This event has %d listing views but no applications yet. Check that booth prices, dates and requirements are clear.', 'lnkup-marketplace' ), (int) $metrics['views'] ), 'tab' => 'share', 'template' => '' );
		}
		if ( ! $links ) {
			$out[] = array( 'id' => 'no_links', 'text' => __( 'Create one tracked link per place you share (Instagram, Facebook groups, email) to see which one brings applications.', 'lnkup-marketplace' ), 'tab' => 'share', 'template' => '' );
		}
		foreach ( $links as $link ) {
			if ( (int) $link['clicks'] >= 20 && 0 === (int) $link['applications'] ) {
				/* translators: 1: link label or channel, 2: clicks */
				$out[] = array( 'id' => 'clicks_no_apps_' . (int) $link['id'], 'text' => sprintf( __( 'Your %1$s link has %2$d clicks but no applications yet.', 'lnkup-marketplace' ), '' !== $link['label'] ? $link['label'] : $link['channel'], (int) $link['clicks'] ), 'tab' => 'performance', 'template' => '' );
			}
		}
		if ( ! empty( $payload['image_is_fallback'] ) ) {
			$out[] = array( 'id' => 'no_photo', 'text' => __( 'Add a photo of your event. Graphics and listings use it instead of generic artwork.', 'lnkup-marketplace' ), 'tab' => 'share', 'template' => '' );
		}
		return $out;
	}

	/**
	 * Editable copy templates. Placeholders are filled in the browser from real event data;
	 * lines whose placeholders are empty are dropped. Nothing is posted automatically.
	 *
	 * @return array<string,array{label:string,text:string}>
	 */
	public static function copy_templates(): array {
		return array(
			'instagram'      => array( 'label' => __( 'Instagram caption', 'lnkup-marketplace' ), 'text' => __( "Vendors wanted! {title} is booking vendors for {date} in {city}.\nLooking for: {categories}\nApply free and pay only if you're approved: {link}", 'lnkup-marketplace' ) ),
			'facebook_group' => array( 'label' => __( 'Facebook group post', 'lnkup-marketplace' ), 'text' => __( "Hi everyone! We're booking vendors for {title} on {date} at {where}.\nWe're looking for: {categories}\nApplications are free; you only pay the booth fee if you're approved.\nDetails and application: {link}", 'lnkup-marketplace' ) ),
			'email'          => array( 'label' => __( 'Email to vendors', 'lnkup-marketplace' ), 'text' => __( "Subject: Vendor spots open for {title} ({date})\n\nHi,\n\nWe're booking vendors for {title} on {date} at {where}.\nWe're looking for: {categories}\nApplications close {deadline}.\n\nApply here: {link}\n\nThanks,\n{organizer}", 'lnkup-marketplace' ) ),
			'sms'            => array( 'label' => __( 'Text message', 'lnkup-marketplace' ), 'text' => __( '{organizer}: vendor spots are open for {title} on {date}. Apply: {link}', 'lnkup-marketplace' ) ),
			'deadline'       => array( 'label' => __( 'Deadline reminder', 'lnkup-marketplace' ), 'text' => __( "Last call! Vendor applications for {title} close {deadline}.\nApply: {link}", 'lnkup-marketplace' ) ),
			'food_truck'     => array( 'label' => __( 'Food truck call', 'lnkup-marketplace' ), 'text' => __( "Calling food trucks! {title} on {date} in {city} is booking food vendors.\nApply: {link}", 'lnkup-marketplace' ) ),
			'partner'        => array( 'label' => __( 'Ask a partner to share', 'lnkup-marketplace' ), 'text' => __( "Hi! Could you share this with vendors in your network? {title} ({date}, {city}) is booking vendors and applying is free.\n{link}\nThank you!\n{organizer}", 'lnkup-marketplace' ) ),
		);
	}

	/* ------------------------------------------------------------ Public list embeds */

	private static function embed_rate_limited(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key  = 'lnkup_embed_list_rl_' . substr( hash( 'sha256', $ip . wp_salt( 'nonce' ) ), 0, 20 );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::EMBED_RATE ) {
			return true;
		}
		set_transient( $key, $hits + 1, HOUR_IN_SECONDS );
		return false;
	}

	/**
	 * A link code is kept only when it is an active link owned by the event's organizer.
	 */
	private static function embed_link_params( string $code, int $organizer_id ): array {
		$code = (string) preg_replace( '/[^A-Za-z0-9]/', '', $code );
		$link = '' !== $code && class_exists( 'LNKup_Tracking_Links' ) && LNKup_Tracking_Links::active() ? LNKup_Tracking_Links::get_by_code( $code ) : null;
		$params = array( 'utm_source' => 'organizer_site', 'utm_medium' => 'embed' );
		if ( $link && 'active' === $link['status'] && (int) $link['owner_user_id'] === $organizer_id ) {
			$params['lnk'] = $link['code'];
		}
		return $params;
	}

	public static function rest_embed_event( WP_REST_Request $request ) {
		if ( self::embed_rate_limited() ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$event_id = (int) $request['id'];
		if ( 'lnkup_event' !== get_post_type( $event_id ) || 'publish' !== get_post_status( $event_id ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'This event is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true );
		$params    = self::embed_link_params( (string) $request->get_param( 'lnk' ), $organizer );
		$cache_key = 'lnkup_embed_list_e' . $event_id . '_' . md5( wp_json_encode( $params ) );
		$data      = get_transient( $cache_key );
		if ( ! is_array( $data ) ) {
			$items = array();
			foreach ( self::opportunity_ids( $event_id ) as $opp_id ) {
				$card = class_exists( 'LNKup_Public_Embed' ) ? LNKup_Public_Embed::payload( $opp_id ) : null;
				if ( is_array( $card ) ) {
					$card['apply_url'] = add_query_arg( $params, (string) $card['apply_url'] );
					$items[]           = $card;
				}
			}
			$data = array(
				'event' => array(
					'title' => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
					'date'  => (string) get_post_meta( $event_id, '_lnkup_event_date', true ),
					'city'  => (string) get_post_meta( $event_id, '_lnkup_city', true ),
					'state' => (string) get_post_meta( $event_id, '_lnkup_state', true ),
					'url'   => add_query_arg( $params, class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( $event_id ) : (string) get_permalink( $event_id ) ),
				),
				'items' => $items,
			);
			set_transient( $cache_key, $data, self::EMBED_CACHE_TTL );
		}
		$response = new WP_REST_Response( array( 'success' => true, 'data' => $data ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	public static function rest_embed_tour( WP_REST_Request $request ) {
		if ( self::embed_rate_limited() ) {
			return new WP_Error( 'lnkup_rate_limited', __( 'Too many requests. Try again later.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		$tour_id = (int) $request['id'];
		if ( 'lnkup_tour' !== get_post_type( $tour_id ) || 'publish' !== get_post_status( $tour_id ) || ! class_exists( 'LNKup_Tours' ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'This tour is not available.', 'lnkup-marketplace' ), array( 'status' => 404 ) );
		}
		$organizer = (int) get_post_meta( $tour_id, '_lnkup_organizer_user_id', true );
		$params    = self::embed_link_params( (string) $request->get_param( 'lnk' ), $organizer );
		$stops     = array();
		foreach ( LNKup_Tours::stop_event_ids( $tour_id ) as $event_id ) {
			$date = (string) get_post_meta( $event_id, '_lnkup_event_date', true );
			if ( 'publish' !== get_post_status( $event_id ) || ( '' !== $date && $date < current_time( 'Y-m-d' ) ) ) {
				continue;
			}
			$stops[] = array(
				'title' => html_entity_decode( get_the_title( $event_id ), ENT_QUOTES, 'UTF-8' ),
				'date'  => $date,
				'city'  => (string) get_post_meta( $event_id, '_lnkup_city', true ),
				'state' => (string) get_post_meta( $event_id, '_lnkup_state', true ),
				'url'   => add_query_arg( $params, class_exists( 'LNKup_Organizer_Onboarding' ) ? LNKup_Organizer_Onboarding::event_public_url( (int) $event_id ) : (string) get_permalink( $event_id ) ),
			);
		}
		usort( $stops, static fn( $a, $b ) => strcmp( (string) $a['date'], (string) $b['date'] ) );
		$response = new WP_REST_Response( array( 'success' => true, 'data' => array( 'tour' => array( 'title' => html_entity_decode( get_the_title( $tour_id ), ENT_QUOTES, 'UTF-8' ), 'url' => add_query_arg( $params, (string) get_permalink( $tour_id ) ) ), 'items' => $stops ) ), 200 );
		$response->header( 'Cache-Control', 'public, max-age=300' );
		return $response;
	}

	/* ------------------------------------------------------------ Dashboard view */

	public static function assets(): void {
		if ( ! self::can_use() || ! function_exists( 'lnkup_organizer_dashboard_view' ) || 'marketing' !== lnkup_organizer_dashboard_view() ) {
			return;
		}
		wp_enqueue_style( 'lnkup-organizer-marketing', LNKUP_PLUGIN_URL . 'public/css/organizer-marketing.css', array(), LNKUP_VERSION );
		wp_enqueue_script( 'lnkup-organizer-marketing', LNKUP_PLUGIN_URL . 'public/js/organizer-marketing.js', array( 'lnkup-qr' ), LNKUP_VERSION, true );
		wp_localize_script(
			'lnkup-organizer-marketing',
			'lnkupOrgMarketing',
			array(
				'rest'  => esc_url_raw( rest_url( 'lnkup/v1/organizer/marketing/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'site'  => home_url( '/' ),
			)
		);
	}

	/**
	 * Server shell; organizer-marketing.js fills it (loading, empty and error states included).
	 */
	public static function render(): string {
		if ( ! self::can_use() ) {
			return '<p class="lnkup-empty-state">' . esc_html__( 'Marketing tools are not available on your account yet.', 'lnkup-marketplace' ) . '</p>';
		}
		$tabs = array(
			'share'       => __( 'Share & promote', 'lnkup-marketplace' ),
			'graphics'    => __( 'Graphics', 'lnkup-marketplace' ),
			'copy'        => __( 'Copy', 'lnkup-marketplace' ),
			'embed'       => __( 'Website embeds', 'lnkup-marketplace' ),
			'performance' => __( 'Performance', 'lnkup-marketplace' ),
		);
		ob_start();
		?>
		<section class="lnkup-om" data-testid="lnkup-org-marketing" aria-labelledby="lnkup-om-title">
			<header class="lnkup-om__head">
				<div>
					<h2 class="lnkup-om__title screen-reader-text" id="lnkup-om-title"><?php esc_html_e( 'Marketing', 'lnkup-marketplace' ); ?></h2>
					<p class="lnkup-om__lead"><?php esc_html_e( 'Share your events, see which links bring vendors, and make graphics and posts from your real event details.', 'lnkup-marketplace' ); ?></p>
				</div>
				<label class="lnkup-om__picker">
					<span><?php esc_html_e( 'Event', 'lnkup-marketplace' ); ?></span>
					<select data-om-event disabled><option><?php esc_html_e( 'Loading events…', 'lnkup-marketplace' ); ?></option></select>
				</label>
			</header>
			<nav class="lnkup-om__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Marketing tools', 'lnkup-marketplace' ); ?>">
				<?php $first = true; ?>
				<?php foreach ( $tabs as $key => $label ) : ?>
					<button type="button" role="tab" class="lnkup-om__tab" id="lnkup-om-tab-<?php echo esc_attr( $key ); ?>" aria-controls="lnkup-om-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" data-om-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
					<?php $first = false; ?>
				<?php endforeach; ?>
			</nav>
			<div class="lnkup-om__status" data-om-status role="status" aria-live="polite">
				<div class="lnkup-om__skeleton" aria-hidden="true"></div>
			</div>
			<?php $first = true; ?>
			<?php foreach ( $tabs as $key => $label ) : ?>
				<div class="lnkup-om__panel" role="tabpanel" id="lnkup-om-panel-<?php echo esc_attr( $key ); ?>" aria-labelledby="lnkup-om-tab-<?php echo esc_attr( $key ); ?>" data-om-panel="<?php echo esc_attr( $key ); ?>" hidden></div>
			<?php endforeach; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
