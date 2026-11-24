<?php
/**
 * Central guest / member / participant visibility policy for marketplace data.
 *
 * Every public surface (REST, wp/v2, server-rendered cards, event pages, SEO copy) asks this
 * class what a viewer may see. Field groups are fixed in code on purpose: marketing settings
 * must never be able to expose pricing internals, operational logistics or participant data.
 *
 * Viewer contexts (resource-specific, never a global ladder):
 * - guest:       not signed in. Public preview only.
 * - member:      signed in. Marketplace detail (pricing, spots, requirements).
 * - participant: vendor with an approved/paid booking on this event. Adds operational logistics.
 * - owner:       event owner, organization team, or event staff. Everything for that event.
 * - admin:       manage-all capability.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Access_Policy {

	const GUEST       = 'guest';
	const MEMBER      = 'member';
	const PARTICIPANT = 'participant';
	const OWNER       = 'owner';
	const ADMIN       = 'admin';

	/** Application statuses that make a vendor a confirmed participant of the event. */
	const PARTICIPANT_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed' );

	/** Opportunity card keys hidden from guests (pricing, inventory, requirements). */
	const MARKETPLACE_DETAIL_KEYS = array(
		'vendor_fee',
		'fee_label',
		'spots_remaining',
		'total_spots',
		'spots_unlimited',
		'required_documents',
		'document_badges',
		'load_in_time',
		'price_label',
		'from_price',
		'min_fee',
		'max_fee',
		'fee_range',
		'open_spots',
		'spots_left',
		'spots_open',
	);

	/** Keys never sent to anyone outside the owner context. */
	const INTERNAL_KEYS = array(
		'application_count',
		'view_count',
		'trending_score',
		'lnkup_fee',
		'organizer_notes',
	);

	/** wp/v2 event meta that anyone may read. */
	const EVENT_PUBLIC_META = array(
		'_lnkup_tour_id',
		'_lnkup_tour_stop_order',
		'_lnkup_timezone',
		'_lnkup_event_date',
		'_lnkup_start_time',
		'_lnkup_end_time',
		'_lnkup_city',
		'_lnkup_state',
		'_lnkup_country',
		'_lnkup_expected_attendance',
		'_lnkup_ticketing_platform',
		'_lnkup_ticketing_url',
		'_lnkup_status',
		'_lnkup_indoor',
		'_lnkup_outdoor',
		'_lnkup_indoor_outdoor',
		'_lnkup_featured_image_id',
		'_lnkup_gallery_ids',
		'_lnkup_tags',
		'_lnkup_venue_type',
		'_lnkup_market',
		'_lnkup_region',
	);

	/** Event meta visible to signed-in members when the venue is not hidden. */
	const EVENT_MEMBER_META = array(
		'_lnkup_venue_name',
		'_lnkup_venue_address',
		'_lnkup_address_line1',
		'_lnkup_address_line2',
		'_lnkup_zip',
		'_lnkup_neighborhood',
		'_lnkup_lat',
		'_lnkup_lng',
		'_lnkup_power_available',
		'_lnkup_water_available',
		'_lnkup_food_truck_allowed',
		'_lnkup_tent_allowed',
		'_lnkup_generator_allowed',
		'_lnkup_free_vendor_parking',
	);

	/** Event meta for confirmed participants (operational logistics). */
	const EVENT_PARTICIPANT_META = array(
		'_lnkup_parking_notes',
		'_lnkup_loadin_entrance',
		'_lnkup_vendor_gate',
		'_lnkup_venue_map_attachment_id',
	);

	/** wp/v2 opportunity meta that anyone may read. */
	const OPPORTUNITY_PUBLIC_META = array(
		'_lnkup_event_id',
		'_lnkup_booth_type_key',
		'_lnkup_application_deadline',
		'_lnkup_opportunity_status',
		'_lnkup_instant_approval',
		'_lnkup_gallery_ids',
		'_lnkup_featured_image_id',
	);

	/** Opportunity meta for signed-in members. */
	const OPPORTUNITY_MEMBER_META = array(
		'_lnkup_spots_total',
		'_lnkup_spots_remaining',
		'_lnkup_vendor_fee',
		'_lnkup_required_documents',
		'_lnkup_document_mode',
		'_lnkup_load_in_time',
	);

	/** Marketplace read routes closed before public launch (admins with preview still pass). */
	const PRELAUNCH_ROUTES = '#^/lnkup/v1/(marketplace/(search|suggestions|sections/|collections|compare|map-pins)|maps/discovery/pins|tours/\d+|public/discovery/cards)#';

	public static function init(): void {
		add_filter( 'rest_prepare_lnkup_event', array( __CLASS__, 'filter_wp_rest_event' ), 20, 2 );
		add_filter( 'rest_prepare_lnkup_opportunity', array( __CLASS__, 'filter_wp_rest_opportunity' ), 20, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'prelaunch_gate' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_intent_script' ), 20 );
		add_action( 'lnkup_user_registered', array( __CLASS__, 'record_registration_conversion' ), 20, 3 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'record_application_conversion' ), 30, 1 );
	}

	/**
	 * Sign-ups that started at a guest gate (lnkup_intent on the return URL, or an app-supplied
	 * attribution intent) count as gate conversions. Plain sign-ups are not attributed.
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    lnkup_vendor|lnkup_organizer.
	 * @param array  $context Registration context.
	 */
	public static function record_registration_conversion( $user_id, $role, $context = array() ): void {
		$raw = '';
		if ( ! empty( $_POST['lnkup_redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the registration handler.
			$raw = (string) wp_unslash( $_POST['lnkup_redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( ! empty( $_GET['redirect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = (string) wp_unslash( $_GET['redirect'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$intent = '';
		$query  = (string) wp_parse_url( $raw, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $args );
			$intent = isset( $args['lnkup_intent'] ) && is_string( $args['lnkup_intent'] ) ? sanitize_key( $args['lnkup_intent'] ) : '';
		}
		$attribution = is_array( $context['attribution'] ?? null ) ? $context['attribution'] : array();
		if ( '' === $intent && isset( $attribution['intent'] ) && is_string( $attribution['intent'] ) ) {
			$intent = sanitize_key( $attribution['intent'] );
		}
		if ( ! in_array( $intent, array( 'view_pricing', 'apply', 'save', 'connect' ), true ) || ! class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			return;
		}
		$path = (string) wp_parse_url( $raw, PHP_URL_PATH );
		$data = array(
			'intent'  => $intent,
			'channel' => (string) ( $context['channel'] ?? 'web' ),
			'landing' => substr( sanitize_text_field( $path ), 0, 120 ),
			'market'  => (string) ( $context['market'] ?? '' ),
		);
		update_user_meta( (int) $user_id, '_lnkup_auth_intent', $data + array( 'at' => gmdate( 'c' ) ) );
		LNKup_Marketplace_Analytics::track( 'guest_registration_completed', $data, (int) $user_id );
		LNKup_Marketplace_Analytics::track( 'lnkup_organizer' === $role ? 'guest_to_organizer_conversion' : 'guest_to_vendor_conversion', $data, (int) $user_id );
	}

	/**
	 * First application from a gate-converted account (counted once).
	 *
	 * @param int $app_id Application ID.
	 */
	public static function record_application_conversion( $app_id ): void {
		$user_id = (int) get_post_meta( (int) $app_id, '_lnkup_vendor_user_id', true );
		$intent  = $user_id ? get_user_meta( $user_id, '_lnkup_auth_intent', true ) : '';
		if ( ! is_array( $intent ) || ! empty( $intent['applied'] ) || ! class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			return;
		}
		$intent['applied'] = gmdate( 'c' );
		update_user_meta( $user_id, '_lnkup_auth_intent', $intent );
		LNKup_Marketplace_Analytics::track(
			'guest_to_application_conversion',
			array(
				'intent'  => (string) ( $intent['intent'] ?? '' ),
				'landing' => (string) ( $intent['landing'] ?? '' ),
			),
			$user_id,
			(int) get_post_meta( (int) $app_id, '_lnkup_opportunity_id', true )
		);
	}

	/** Gate funnel + post-sign-in restore script on public pages (tiny, deferred, no deps). */
	public static function enqueue_intent_script(): void {
		if ( is_admin() ) {
			return;
		}
		$path = LNKUP_PLUGIN_DIR . 'public/js/access-intent.js';
		wp_enqueue_script(
			'lnkup-access-intent',
			LNKUP_PLUGIN_URL . 'public/js/access-intent.js',
			array(),
			file_exists( $path ) ? LNKUP_VERSION . '.' . filemtime( $path ) : LNKUP_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
		wp_localize_script(
			'lnkup-access-intent',
			'lnkupAccess',
			array(
				'trackUrl' => esc_url_raw( rest_url( 'lnkup/v1/marketplace/track/event' ) ),
				'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'isGuest'  => ! is_user_logged_in(),
			)
		);
	}

	/**
	 * Before launch, marketplace read routes return an empty, well-formed payload.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 */
	public static function prelaunch_gate( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $result;
		}
		if ( ! preg_match( self::PRELAUNCH_ROUTES, (string) $request->get_route() ) ) {
			return $result;
		}
		if ( ! function_exists( 'lnkup_can_browse_marketplace' ) || lnkup_can_browse_marketplace() ) {
			return $result;
		}
		return new WP_REST_Response(
			array(
				'prelaunch' => true,
				'message'   => __( 'The LNKup marketplace opens soon.', 'lnkup-marketplace' ),
				'results'   => array(),
				'items'     => array(),
				'cards'     => array(),
				'pins'      => array(),
				'total'     => 0,
				'html'      => '',
			),
			200
		);
	}

	/**
	 * Viewer context for one event (0 = no specific event).
	 *
	 * @param int $event_id Event post ID.
	 * @param int $user_id  User ID; -1 = current user.
	 */
	public static function viewer_context( int $event_id = 0, int $user_id = -1 ): string {
		$user_id = $user_id < 0 ? get_current_user_id() : $user_id;
		if ( $user_id < 1 ) {
			return self::GUEST;
		}
		if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) ) {
			return self::ADMIN;
		}
		if ( $event_id > 0 ) {
			if ( class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::staff_can( $event_id, 'view_roster', $user_id ) ) {
				return self::OWNER;
			}
			if ( self::vendor_is_participant( $event_id, $user_id ) ) {
				return self::PARTICIPANT;
			}
		}
		return self::MEMBER;
	}

	/** Whether the current request is from a signed-out visitor (crawlers included). */
	public static function is_guest(): bool {
		return ! is_user_logged_in();
	}

	/** Signed-in viewers see pricing, inventory and requirements. */
	public static function can_view_marketplace_detail( int $user_id = -1 ): bool {
		$user_id = $user_id < 0 ? get_current_user_id() : $user_id;
		return $user_id > 0;
	}

	/**
	 * Whether a context may see a hidden venue's exact name, address and coordinates.
	 *
	 * @param string $context Viewer context.
	 */
	public static function context_sees_hidden_venue( string $context ): bool {
		return in_array( $context, array( self::PARTICIPANT, self::OWNER, self::ADMIN ), true );
	}

	/**
	 * Vendor holds an approved or paid booking on any slot of this event.
	 *
	 * @param int $event_id Event ID.
	 * @param int $user_id  Vendor user ID.
	 */
	public static function vendor_is_participant( int $event_id, int $user_id ): bool {
		static $cache = array();
		$key = $event_id . ':' . $user_id;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $user_id ),
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => self::PARTICIPANT_STATUSES, 'compare' => 'IN' ),
				),
			)
		);
		$cache[ $key ] = ! empty( $ids );
		return $cache[ $key ];
	}

	/**
	 * Slot may be shown on public marketplace surfaces: published, open, not invite-only, event
	 * published and live.
	 *
	 * @param int $opp_id Opportunity ID.
	 */
	public static function opportunity_publicly_visible( int $opp_id ): bool {
		$opp = get_post( $opp_id );
		if ( ! $opp || 'lnkup_opportunity' !== $opp->post_type || 'publish' !== $opp->post_status ) {
			return false;
		}
		$slot = (string) get_post_meta( $opp_id, '_lnkup_opportunity_status', true );
		if ( ( '' !== $slot && 'open' !== $slot ) || get_post_meta( $opp_id, '_lnkup_invite_only', true ) ) {
			return false;
		}
		$event_id = (int) get_post_meta( $opp_id, '_lnkup_event_id', true );
		$event    = $event_id ? get_post( $event_id ) : null;
		return $event && 'publish' === $event->post_status && 'live' === (string) get_post_meta( $event_id, '_lnkup_event_status', true );
	}

	/**
	 * Slot may appear on its event page for this viewer. Owners see every slot they manage;
	 * everyone else sees published slots that are not invite-only.
	 *
	 * @param WP_Post $opp     Opportunity post.
	 * @param string  $context Viewer context for the event.
	 */
	public static function slot_listable( WP_Post $opp, string $context ): bool {
		if ( in_array( $context, array( self::OWNER, self::ADMIN ), true ) ) {
			return true;
		}
		if ( 'publish' !== $opp->post_status ) {
			return false;
		}
		return ! get_post_meta( $opp->ID, '_lnkup_invite_only', true );
	}

	/**
	 * SQL fragment that drops invite-only slots from index queries.
	 *
	 * @param string $table Index table name (already escaped).
	 */
	public static function invite_only_exclusion_sql( string $table ): string {
		global $wpdb;
		return "{$table}.opportunity_id NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lnkup_invite_only' AND meta_value NOT IN ('', '0'))";
	}

	/** Whether index searches should exclude invite-only slots for the current viewer. */
	public static function exclude_invite_only(): bool {
		$user_id = get_current_user_id();
		return ! ( $user_id && class_exists( 'LNKup_Capabilities' ) && LNKup_Capabilities::user_has_manage_all( $user_id ) );
	}

	/** Event hides its venue from the public. */
	public static function venue_hidden( int $event_id ): bool {
		return $event_id > 0 && (bool) get_post_meta( $event_id, '_lnkup_venue_hidden', true );
	}

	/**
	 * Guest-safe opportunity status with no numbers.
	 *
	 * @param array<string, mixed> $item Formatted card or index row.
	 * @return array{key: string, label: string}
	 */
	public static function guest_opportunity_status( array $item ): array {
		$deadline = (string) ( $item['deadline'] ?? '' );
		$today    = current_time( 'Y-m-d' );
		if ( '' !== $deadline && substr( $deadline, 0, 10 ) < $today ) {
			return array( 'key' => 'closed', 'label' => __( 'Applications closed', 'lnkup-marketplace' ) );
		}
		$total     = (int) ( $item['total_spots'] ?? $item['spots_total'] ?? 0 );
		$remaining = (int) ( $item['spots_remaining'] ?? 0 );
		if ( $total > 0 && $remaining <= 0 ) {
			return array( 'key' => 'sold_out', 'label' => __( 'Vendor spaces filled', 'lnkup-marketplace' ) );
		}
		if ( '' !== $deadline && strtotime( substr( $deadline, 0, 10 ) ) - strtotime( $today ) <= 7 * DAY_IN_SECONDS ) {
			return array( 'key' => 'closing_soon', 'label' => __( 'Applications closing soon', 'lnkup-marketplace' ) );
		}
		return array( 'key' => 'open', 'label' => __( 'Vendor applications open', 'lnkup-marketplace' ) );
	}

	/**
	 * Contextual sign-in CTA. Messages are fixed copy; URLs carry a validated return target.
	 *
	 * @param string $reason     pricing|apply|connect|organizer|save.
	 * @param string $return_url Same-site URL to return to after sign-in.
	 * @return array{reason: string, message: string, sign_in_label: string, sign_in_url: string, sign_up_label: string, sign_up_url: string}
	 */
	public static function guest_cta( string $reason = 'pricing', string $return_url = '' ): array {
		$messages = array(
			'pricing'   => __( 'Sign in to view pricing, available spaces, and application requirements.', 'lnkup-marketplace' ),
			'apply'     => __( 'Sign in to view vendor pricing and apply for this event.', 'lnkup-marketplace' ),
			'connect'   => __( 'Sign in to connect with this organizer.', 'lnkup-marketplace' ),
			'organizer' => __( 'Join LNKup to find and manage vendors for your next event.', 'lnkup-marketplace' ),
			'save'      => __( 'Sign in to save events and get alerts for new opportunities.', 'lnkup-marketplace' ),
		);
		$reason     = isset( $messages[ $reason ] ) ? $reason : 'pricing';
		$return_url = self::safe_return_url( $return_url );
		$intent     = 'apply' === $reason ? 'apply' : 'view_pricing';
		if ( '' !== $return_url ) {
			$return_url = add_query_arg( 'lnkup_intent', $intent, $return_url );
		}
		$sign_in = function_exists( 'lnkup_login_url_for' ) ? lnkup_login_url_for( $return_url ) : wp_login_url( $return_url );
		$sign_up = function_exists( 'lnkup_registration_url' ) ? lnkup_registration_url() : wp_registration_url();
		$sign_up = add_query_arg( 'role', 'organizer' === $reason ? 'organizer' : 'vendor', $sign_up );
		if ( '' !== $return_url ) {
			$sign_up = add_query_arg( 'redirect', rawurlencode( $return_url ), $sign_up );
		}
		return array(
			'reason'        => $reason,
			'message'       => $messages[ $reason ],
			'sign_in_label' => 'apply' === $reason ? __( 'Sign in to apply', 'lnkup-marketplace' ) : __( 'Sign in to view opportunities', 'lnkup-marketplace' ),
			'sign_in_url'   => $sign_in,
			'sign_up_label' => 'organizer' === $reason ? __( 'Create a free organizer account', 'lnkup-marketplace' ) : __( 'Create a free account', 'lnkup-marketplace' ),
			'sign_up_url'   => $sign_up,
		);
	}

	/**
	 * Same-host URL or ''. Prevents open redirects through CTA links.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function safe_return_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
			$url = home_url( $url );
		}
		$validated = wp_validate_redirect( $url, '' );
		return is_string( $validated ) ? $validated : '';
	}

	/**
	 * Apply the policy to a formatted opportunity card / index row for the current viewer.
	 *
	 * @param array<string, mixed> $item Formatted item (lnkup_format_index_row shape).
	 * @return array<string, mixed>
	 */
	public static function scrub_opportunity_item( array $item ): array {
		$event_id = (int) ( $item['event_id'] ?? 0 );
		$context  = self::viewer_context( $event_id );
		$owner    = in_array( $context, array( self::OWNER, self::ADMIN ), true );

		if ( ! $owner ) {
			foreach ( self::INTERNAL_KEYS as $key ) {
				unset( $item[ $key ] );
			}
		}

		if ( self::venue_hidden( $event_id ) && ! self::context_sees_hidden_venue( $context ) ) {
			$item['venue_name']  = '';
			$item['venue_label'] = '';
			$item['venue_hidden'] = true;
			foreach ( array( 'lat', 'lng' ) as $coord ) {
				if ( isset( $item[ $coord ] ) && null !== $item[ $coord ] ) {
					$item[ $coord ] = round( (float) $item[ $coord ], 2 );
				}
			}
		}

		if ( self::GUEST !== $context ) {
			$item['access'] = array( 'gated' => false, 'context' => $context );
			return $item;
		}

		$status = self::guest_opportunity_status( $item );
		foreach ( self::MARKETPLACE_DETAIL_KEYS as $key ) {
			if ( array_key_exists( $key, $item ) ) {
				$item[ $key ] = null;
			}
		}
		$item['document_badges'] = array();
		$item['badges']          = array_values( array_diff( (array) ( $item['badges'] ?? array() ), array( 'almost_full', 'popular', 'trending' ) ) );
		$item['opportunity_status'] = $status;
		$return                  = (string) ( $item['details_url'] ?? $item['apply_url'] ?? '' );
		if ( '' === $return && ! empty( $item['opportunity_id'] ) && function_exists( 'lnkup_page_url_helper' ) ) {
			$return = add_query_arg( 'apply', (int) $item['opportunity_id'], lnkup_page_url_helper( 'opportunities' ) );
		}
		$item['access']          = array(
			'gated'   => true,
			'context' => self::GUEST,
			'cta'     => self::guest_cta( 'pricing', $return ),
		);
		return $item;
	}

	/**
	 * Strip pricing and inventory from a public event / tour payload for guests. Works on nested
	 * arrays (stops, slots) so one call covers detail payloads.
	 *
	 * @param array<mixed> $data Payload.
	 * @return array<mixed>
	 */
	public static function scrub_guest_payload( array $data ): array {
		if ( ! self::is_guest() ) {
			return $data;
		}
		$hidden = array_merge( self::MARKETPLACE_DETAIL_KEYS, self::INTERNAL_KEYS, array( 'fee', 'fee_min', 'fee_max', 'price', 'spots', 'capacity', 'documents', 'questions' ) );
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, $hidden, true ) ) {
				unset( $data[ $key ] );
				continue;
			}
			if ( is_array( $value ) ) {
				$data[ $key ] = self::scrub_guest_payload( $value );
			}
		}
		return $data;
	}

	/**
	 * Meta keys a viewer may read for one post through wp/v2.
	 *
	 * @param string $post_type lnkup_event|lnkup_opportunity.
	 * @param int    $event_id  Event the post belongs to.
	 * @return array<int, string>|null Null = no restriction (owner/admin).
	 */
	public static function allowed_rest_meta( string $post_type, int $event_id ): ?array {
		$context = self::viewer_context( $event_id );
		if ( in_array( $context, array( self::OWNER, self::ADMIN ), true ) ) {
			return null;
		}
		if ( 'lnkup_opportunity' === $post_type ) {
			return self::GUEST === $context
				? self::OPPORTUNITY_PUBLIC_META
				: array_merge( self::OPPORTUNITY_PUBLIC_META, self::OPPORTUNITY_MEMBER_META );
		}
		$keys = self::EVENT_PUBLIC_META;
		if ( self::GUEST === $context ) {
			return $keys;
		}
		$hidden = self::venue_hidden( $event_id );
		if ( ! $hidden || self::context_sees_hidden_venue( $context ) ) {
			$keys = array_merge( $keys, self::EVENT_MEMBER_META );
		}
		if ( self::PARTICIPANT === $context ) {
			$keys = array_merge( $keys, self::EVENT_PARTICIPANT_META );
		}
		return $keys;
	}

	/**
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Event post.
	 */
	public static function filter_wp_rest_event( $response, $post ) {
		return self::filter_wp_rest_meta( $response, $post, 'lnkup_event', (int) $post->ID );
	}

	/**
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Opportunity post.
	 */
	public static function filter_wp_rest_opportunity( $response, $post ) {
		return self::filter_wp_rest_meta( $response, $post, 'lnkup_opportunity', (int) get_post_meta( $post->ID, '_lnkup_event_id', true ) );
	}

	/**
	 * @param WP_REST_Response $response  Response.
	 * @param WP_Post          $post      Post.
	 * @param string           $post_type Post type.
	 * @param int              $event_id  Owning event.
	 */
	private static function filter_wp_rest_meta( $response, $post, string $post_type, int $event_id ) {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$allowed = self::allowed_rest_meta( $post_type, $event_id );
		if ( null === $allowed ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			$data['meta'] = array_intersect_key( $data['meta'], array_flip( $allowed ) );
		}
		if ( 'lnkup_opportunity' === $post_type && self::is_guest() && isset( $data['content'] ) ) {
			$data['content'] = array( 'rendered' => '', 'protected' => false );
		}
		$response->set_data( $data );
		return $response;
	}
}
