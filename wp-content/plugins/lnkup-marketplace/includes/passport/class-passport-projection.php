<?php
/**
 * Vendor Passport projections. Each view is assembled from an explicit allow-list:
 * private fields are never loaded into a less-privileged view and hidden later.
 *
 * - public_view():    anonymous visitors (cached).
 * - organizer_view(): signed-in organizers (contact gated by existing relationship rules).
 * - owner_view():     the vendor (everything they own, plus guidance).
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Passport_Projection {

	const CACHE_PREFIX   = 'lnkup_passport_pub_';
	const CACHE_TTL      = 6 * HOUR_IN_SECONDS;
	const CACHE_GEN_OPT  = 'lnkup_passport_cache_gen';
	const PORTFOLIO_META = '_lnkup_portfolio_items';
	const PORTFOLIO_MAX  = 30;

	const PROFILE_META_TRIGGERS = array(
		'_lnkup_business_name', '_lnkup_logo_attachment_id', '_lnkup_cover_image_id', '_lnkup_gallery_ids', '_lnkup_portfolio_items',
		'_lnkup_city', '_lnkup_state', '_lnkup_cities_served', '_lnkup_states_served', '_lnkup_website', '_lnkup_instagram',
		'_lnkup_tiktok', '_lnkup_facebook', '_lnkup_products_services', '_lnkup_capabilities', '_lnkup_secondary_categories',
		'_lnkup_preferred_booth_types', '_lnkup_preferred_event_types', '_lnkup_passport_visibility', '_lnkup_discoverable',
		'_lnkup_verification_status', '_lnkup_public_slug', '_lnkup_reputation_updated_at',
	);

	public static function init(): void {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_profile_meta' ), 20, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_profile_meta' ), 20, 3 );
		add_action( 'save_post_lnkup_vendor_profile', array( __CLASS__, 'flush_public_cache' ), 20, 1 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 20, 1 );
		add_action( 'lnkup_vendor_review_submitted', array( __CLASS__, 'on_review' ), 20, 2 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'strip_internal_score' ), 20, 3 );
	}

	/** Keys that expose the internal-only reputation score. */
	const INTERNAL_SCORE_KEYS = array( 'vendor_score', 'reputation_score', 'reputation_label', 'breakdown', 'score_breakdown', 'dispute_count' );

	/** Organizer-facing route prefixes where the internal score must not appear. */
	const ORGANIZER_ROUTE_PREFIXES = array( '/lnkup/v1/crm', '/lnkup/v1/intelligence', '/lnkup/v1/applications', '/lnkup/v1/match', '/lnkup/v1/operations', '/lnkup/v1/vendor-lists', '/lnkup/v1/tours' );

	/**
	 * The 0–100 score is staff-only. Remove it from organizer-facing REST payloads.
	 *
	 * @param mixed           $response Callback result (response, array or WP_Error).
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function strip_internal_score( $response, $handler, $request ) {
		unset( $handler );
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request || LNKup_Capabilities::user_has_manage_all() ) {
			return $response;
		}
		$route   = (string) $request->get_route();
		$matches = false;
		foreach ( self::ORGANIZER_ROUTE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				$matches = true;
				break;
			}
		}
		if ( ! $matches ) {
			return $response;
		}
		if ( is_array( $response ) ) {
			return self::strip_keys( $response, 0 );
		}
		if ( $response instanceof WP_HTTP_Response ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				$response->set_data( self::strip_keys( $data, 0 ) );
			}
		}
		return $response;
	}

	/**
	 * @param array $data  Data.
	 * @param int   $depth Recursion depth guard.
	 * @return array
	 */
	private static function strip_keys( array $data, int $depth ): array {
		if ( $depth > 8 ) {
			return $data;
		}
		// Averages below the public review minimum are not shown to organizers either.
		if ( isset( $data['indicators']['organizer_feedback'] ) && array_key_exists( 'avg_rating', $data ) ) {
			$feedback = $data['indicators']['organizer_feedback'];
			if ( empty( $feedback['display_average'] ) ) {
				unset( $data['avg_rating'] );
			} else {
				$data['avg_rating'] = (float) $feedback['average'];
			}
		}
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::INTERNAL_SCORE_KEYS, true ) ) {
				unset( $data[ $key ] );
				continue;
			}
			if ( 'reputation' === $key && is_array( $value ) ) {
				unset( $value['score'], $value['label'] );
			}
			if ( is_array( $value ) ) {
				$data[ $key ] = self::strip_keys( $value, $depth + 1 );
			}
		}
		return $data;
	}

	/**
	 * @param int    $meta_id  Unused.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Key.
	 */
	public static function on_profile_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, self::PROFILE_META_TRIGGERS, true ) && 'lnkup_vendor_profile' === get_post_type( (int) $post_id ) ) {
			self::flush_public_cache( (int) $post_id );
		}
	}

	public static function on_terms( $object_id ): void {
		if ( 'lnkup_vendor_profile' === get_post_type( (int) $object_id ) ) {
			self::flush_public_cache( (int) $object_id );
		}
	}

	public static function on_review( $review_id, $profile_id ): void {
		unset( $review_id );
		self::flush_public_cache( (int) $profile_id );
	}

	public static function flush_public_cache( $profile_id ): void {
		delete_transient( self::cache_key( (int) $profile_id ) );
	}

	/** Invalidate every public projection (settings change). */
	public static function flush_all_public_cache(): void {
		update_option( self::CACHE_GEN_OPT, (int) get_option( self::CACHE_GEN_OPT, 0 ) + 1, false );
	}

	private static function cache_key( int $profile_id ): string {
		return self::CACHE_PREFIX . (int) get_option( self::CACHE_GEN_OPT, 0 ) . '_' . $profile_id;
	}

	/**
	 * Fields every view may show (public business identity).
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,mixed>
	 */
	private static function identity( int $profile_id, array $privacy ): array {
		$post     = get_post( $profile_id );
		$logo_id  = (int) get_post_meta( $profile_id, '_lnkup_logo_attachment_id', true );
		$cover_id = (int) get_post_meta( $profile_id, '_lnkup_cover_image_id', true );
		$terms    = wp_get_post_terms( $profile_id, 'lnkup_vendor_category', array( 'fields' => 'all' ) );
		$terms    = is_wp_error( $terms ) ? array() : $terms;
		$primary  = (int) get_post_meta( $profile_id, '_lnkup_primary_category', true );
		$secondary = self::json_list( get_post_meta( $profile_id, '_lnkup_secondary_categories', true ), true );
		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'id'         => (int) $term->term_id,
				'name'       => $term->name,
				'slug'       => $term->slug,
				'is_primary' => $primary ? $primary === (int) $term->term_id : false,
			);
		}
		$show_cities = ! empty( $privacy['show_cities'] );
		$show_social = ! empty( $privacy['show_socials'] );
		$instagram   = (string) get_post_meta( $profile_id, '_lnkup_instagram', true );
		$tiktok      = (string) get_post_meta( $profile_id, '_lnkup_tiktok', true );
		$facebook    = (string) get_post_meta( $profile_id, '_lnkup_facebook', true );
		return array(
			'profile_id'          => $profile_id,
			'public_slug'         => (string) get_post_meta( $profile_id, '_lnkup_public_slug', true ),
			'business_name'       => (string) ( get_post_meta( $profile_id, '_lnkup_business_name', true ) ?: ( $post ? $post->post_title : '' ) ),
			'description'         => $post ? wp_strip_all_tags( (string) $post->post_content ) : '',
			'logo'                => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'medium' ) : '',
			'cover'               => $cover_id ? (string) wp_get_attachment_image_url( $cover_id, 'large' ) : '',
			'categories'          => $categories,
			'secondary_category_ids' => $secondary,
			'city'                => function_exists( 'lnkup_display_label' ) ? lnkup_display_label( (string) get_post_meta( $profile_id, '_lnkup_city', true ) ) : (string) get_post_meta( $profile_id, '_lnkup_city', true ),
			'state'               => function_exists( 'lnkup_display_state' ) ? lnkup_display_state( (string) get_post_meta( $profile_id, '_lnkup_state', true ) ) : (string) get_post_meta( $profile_id, '_lnkup_state', true ),
			'cities_served'       => $show_cities ? self::json_list( get_post_meta( $profile_id, '_lnkup_cities_served', true ) ) : array(),
			'states_served'       => $show_cities ? self::json_list( get_post_meta( $profile_id, '_lnkup_states_served', true ) ) : array(),
			'products_services'   => (string) get_post_meta( $profile_id, '_lnkup_products_services', true ),
			'years_in_business'   => (int) get_post_meta( $profile_id, '_lnkup_years_in_business', true ),
			'website'             => $show_social ? (string) get_post_meta( $profile_id, '_lnkup_website', true ) : '',
			'socials'             => $show_social ? array(
				'instagram'     => $instagram,
				'instagram_url' => self::social_url( $instagram, 'instagram' ),
				'tiktok'        => $tiktok,
				'tiktok_url'    => self::social_url( $tiktok, 'tiktok' ),
				'facebook'      => $facebook,
				'facebook_url'  => self::social_url( $facebook, 'facebook' ),
			) : array(),
			'capabilities'        => self::capabilities( $profile_id ),
			'preferred_event_types' => self::json_list( get_post_meta( $profile_id, '_lnkup_preferred_event_types', true ) ),
			'preferred_booth_types' => self::json_list( get_post_meta( $profile_id, '_lnkup_preferred_booth_types', true ) ),
			'setup'               => array(
				'setup_type'   => (string) get_post_meta( $profile_id, '_lnkup_setup_type', true ),
				'tent_size'    => (string) get_post_meta( $profile_id, '_lnkup_tent_size', true ),
				'trailer_size' => (string) get_post_meta( $profile_id, '_lnkup_trailer_size', true ),
				'food_truck_size' => (string) get_post_meta( $profile_id, '_lnkup_food_truck_size', true ),
				'power_needs'  => (string) get_post_meta( $profile_id, '_lnkup_power_needed', true ),
				'water_needs'  => (string) get_post_meta( $profile_id, '_lnkup_water_needed', true ),
				'indoor_outdoor' => (string) get_post_meta( $profile_id, '_lnkup_indoor_outdoor_preference', true ),
			),
			'portfolio'           => ! empty( $privacy['show_portfolio'] ) ? self::portfolio( $profile_id ) : array(),
			'public_url'          => class_exists( 'LNKup_Passport_Public' ) ? LNKup_Passport_Public::url_for_slug( (string) get_post_meta( $profile_id, '_lnkup_public_slug', true ) ) : '',
		);
	}

	/**
	 * Public projection (anonymous). Null when the Passport is not public.
	 *
	 * @param int  $profile_id Profile ID.
	 * @param bool $preview    Owner/staff preview of a private Passport (never cached). Callers must authorize.
	 * @return array<string,mixed>|null
	 */
	public static function public_view( int $profile_id, bool $preview = false ): ?array {
		if ( ! $preview && ! LNKup_Passport_Privacy::is_public( $profile_id ) ) {
			return null;
		}
		if ( $preview && 'lnkup_vendor_profile' !== get_post_type( $profile_id ) ) {
			return null;
		}
		$cached = $preview ? false : get_transient( self::cache_key( $profile_id ) );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$privacy = LNKup_Passport_Privacy::get( $profile_id );
		$data    = self::identity( $profile_id, $privacy );
		$data['reputation'] = LNKup_Reputation_Summary::for_profile( $profile_id, 'public' );
		$data['history']    = LNKup_Participation_History::for_profile( $profile_id, 'public', 0, 1, 12 );
		$data['reviews']    = ! empty( $privacy['show_reviews'] ) ? LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 10, false, 0 ) : array();
		$data['view']       = 'public';
		$data['is_preview'] = $preview;
		$data = (array) apply_filters( 'lnkup_passport_public_view', $data, $profile_id );
		if ( ! $preview ) {
			set_transient( self::cache_key( $profile_id ), $data, self::CACHE_TTL );
		}
		return $data;
	}

	/**
	 * Organizer projection. Contact details only after a qualifying relationship (existing CRM rule).
	 *
	 * @param int $profile_id   Profile ID.
	 * @param int $organizer_id Organizer.
	 * @param int $opportunity_id Optional opportunity for document readiness.
	 * @return array<string,mixed>|null
	 */
	public static function organizer_view( int $profile_id, int $organizer_id, int $opportunity_id = 0 ): ?array {
		if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) || 'publish' !== get_post_status( $profile_id ) ) {
			return null;
		}
		$vendor_id   = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$is_staff    = LNKup_Capabilities::user_has_manage_all( $organizer_id );
		$connected   = $is_staff || LNKup_Capabilities::organizer_can_view_vendor_contact( $organizer_id, $profile_id );
		$discoverable = '0' !== (string) get_post_meta( $profile_id, '_lnkup_discoverable', true );
		if ( ! $discoverable && ! $connected && ! LNKup_Capabilities::organizer_can_manage_vendor( $organizer_id, $vendor_id ) ) {
			return null;
		}
		if ( $opportunity_id && ! LNKup_Capabilities::organizer_owns_event( (int) get_post_meta( $opportunity_id, '_lnkup_event_id', true ), $organizer_id ) ) {
			$opportunity_id = 0;
		}
		$privacy = LNKup_Passport_Privacy::get( $profile_id );
		$privacy['show_portfolio'] = true;
		$privacy['show_cities']    = true;
		$privacy['show_socials']   = true;
		$data    = self::identity( $profile_id, $privacy );
		$rel     = class_exists( 'LNKup_Organizer_CRM' ) ? LNKup_Organizer_CRM::get_relationship( $organizer_id, $vendor_id ) : null;

		$data['view']        = 'organizer';
		$data['vendor_user_id'] = $vendor_id;
		$data['reputation']  = LNKup_Reputation_Summary::for_profile( $profile_id, 'organizer', $opportunity_id );
		$data['history']     = LNKup_Participation_History::for_profile( $profile_id, 'organizer', $organizer_id, 1, 20 );
		$data['reviews']     = LNKup_Vendor_Reviews::get_for_vendor( $profile_id, 10, false, $organizer_id );
		$data['contact']     = $connected ? array(
			'owner_name' => (string) get_post_meta( $profile_id, '_lnkup_owner_name', true ),
			'email'      => (string) get_post_meta( $profile_id, '_lnkup_contact_email', true ),
			'phone'      => (string) get_post_meta( $profile_id, '_lnkup_contact_phone', true ),
		) : null;
		$data['can_view_contact'] = $connected;
		$data['relationship'] = array(
			'is_favorite'  => ! empty( $rel['is_favorite'] ),
			'is_preferred' => ! empty( $rel['is_preferred'] ),
		);
		$data['invite'] = array(
			'accepts_invites' => '0' !== (string) get_post_meta( $profile_id, '_lnkup_accept_invites', true ) && $discoverable,
		);
		return (array) apply_filters( 'lnkup_passport_organizer_view', $data, $profile_id, $organizer_id );
	}

	/**
	 * Owner projection: the vendor's full Passport with guidance.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<string,mixed>
	 */
	public static function owner_view( int $profile_id ): array {
		$privacy = LNKup_Passport_Privacy::get( $profile_id );
		$all     = array_merge( $privacy, array( 'show_portfolio' => true, 'show_cities' => true, 'show_socials' => true ) );
		$data    = self::identity( $profile_id, $all );
		$vendor  = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		$strength = LNKup_Growth_Profile_Strength::calculate( $profile_id );

		$data['view']          = 'owner';
		$data['privacy']       = $privacy;
		$data['is_public']     = LNKup_Passport_Privacy::is_public( $profile_id );
		$data['contact']       = array(
			'owner_name' => (string) get_post_meta( $profile_id, '_lnkup_owner_name', true ),
			'email'      => (string) get_post_meta( $profile_id, '_lnkup_contact_email', true ),
			'phone'      => (string) get_post_meta( $profile_id, '_lnkup_contact_phone', true ),
		);
		$data['strength']      = array(
			'score'               => (int) $strength['score'],
			'tier'                => (string) $strength['tier'],
			'sections'            => $strength['sections'],
			'recommended_actions' => $strength['recommended_actions'],
			'note'                => __( 'Profile completeness is not business verification. Optional fields you skip are tips, never penalties.', 'lnkup-marketplace' ),
		);
		$data['verification']  = array(
			'records' => LNKup_Verification_Records::current_all( $profile_id ),
			'summary' => LNKup_Reputation_Summary::verification( $profile_id ),
		);
		$data['reputation']    = LNKup_Reputation_Summary::for_profile( $profile_id, 'owner' );
		$data['history']       = LNKup_Participation_History::for_profile( $profile_id, 'owner', 0, 1, 20 );
		$data['documents']     = array(
			'compliance' => LNKup_Documents::compliance_summary( $vendor ),
			'expiring'   => self::expiring_documents( $vendor ),
		);
		$data['required_actions'] = self::required_actions( $profile_id, $data );
		$data['invitations']   = self::pending_invites( $vendor );
		$data['capability_groups'] = self::capability_groups();
		return (array) apply_filters( 'lnkup_passport_owner_view', $data, $profile_id );
	}

	/**
	 * Actionable next steps, most important first.
	 *
	 * @param int   $profile_id Profile ID.
	 * @param array $data       Owner view so far.
	 * @return array<int,array<string,string>>
	 */
	private static function required_actions( int $profile_id, array $data ): array {
		$actions = array();
		$verif   = $data['verification']['records']['business_identity'] ?? array();
		if ( in_array( $verif['status'] ?? '', array( 'info_required', 'rejected' ), true ) ) {
			$actions[] = array( 'key' => 'verification', 'label' => __( 'Complete an outstanding verification step', 'lnkup-marketplace' ), 'detail' => (string) ( $verif['reason'] ?? '' ), 'target' => 'verification' );
		} elseif ( 'expired' === ( $verif['status'] ?? '' ) ) {
			$actions[] = array( 'key' => 'verification_renew', 'label' => __( 'Renew your business verification', 'lnkup-marketplace' ), 'detail' => '', 'target' => 'verification' );
		}
		foreach ( (array) $data['documents']['expiring'] as $doc ) {
			$actions[] = array(
				'key'    => 'document_' . $doc['id'],
				/* translators: %s: document label */
				'label'  => sprintf( __( 'Update your %s', 'lnkup-marketplace' ), $doc['label'] ),
				/* translators: %s: date */
				'detail' => 'expired' === $doc['status'] ? __( 'Expired', 'lnkup-marketplace' ) : sprintf( __( 'Expires %s', 'lnkup-marketplace' ), $doc['expires_at'] ),
				'target' => 'documents',
			);
		}
		foreach ( array_slice( (array) $data['strength']['recommended_actions'], 0, 3 ) as $action ) {
			$actions[] = array( 'key' => 'profile_' . ( $action['key'] ?? '' ), 'label' => (string) ( $action['label'] ?? '' ), 'detail' => (string) ( $action['description'] ?? '' ), 'target' => 'overview' );
		}
		return array_slice( $actions, 0, 8 );
	}

	/**
	 * @param int $vendor_id Vendor user ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function expiring_documents( int $vendor_id ): array {
		$out = array();
		foreach ( LNKup_Documents::current_by_type( $vendor_id ) as $doc ) {
			$status = LNKup_Documents::effective_status( (int) $doc->ID );
			if ( in_array( $status, array( 'expiring', 'expired' ), true ) ) {
				$out[] = LNKup_Document_Vault::describe( (int) $doc->ID );
			}
		}
		return $out;
	}

	/**
	 * @param int $vendor_id Vendor user ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function pending_invites( int $vendor_id ): array {
		$invites = get_posts(
			array(
				'post_type'      => 'lnkup_vendor_invite',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_status', 'value' => array( 'sent', 'opened' ), 'compare' => 'IN' ),
				),
			)
		);
		$out = array();
		foreach ( $invites as $invite ) {
			$event_id  = (int) get_post_meta( $invite->ID, '_lnkup_event_id', true );
			$organizer = get_userdata( (int) get_post_meta( $invite->ID, '_lnkup_organizer_user_id', true ) );
			$out[]     = array(
				'id'             => $invite->ID,
				'event_id'       => $event_id,
				'event_title'    => $event_id ? lnkup_plain_title( $event_id ) : '',
				'event_date'     => $event_id ? (string) get_post_meta( $event_id, '_lnkup_event_date', true ) : '',
				'organizer_name' => $organizer ? $organizer->display_name : '',
				'tour_id'        => (int) get_post_meta( $invite->ID, '_lnkup_tour_id', true ),
				'expires_at'     => (string) get_post_meta( $invite->ID, '_lnkup_expires_at', true ),
				'message'        => (string) get_post_meta( $invite->ID, '_lnkup_invite_message', true ),
			);
		}
		return $out;
	}

	/**
	 * Portfolio items with image URLs. Falls back to legacy gallery IDs so existing photos keep working.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function portfolio( int $profile_id ): array {
		$items = self::portfolio_raw( $profile_id );
		$out   = array();
		foreach ( $items as $item ) {
			$aid = (int) $item['attachment_id'];
			$url = wp_get_attachment_image_url( $aid, 'large' );
			if ( ! $url ) {
				continue;
			}
			$event_id = (int) ( $item['event_id'] ?? 0 );
			$out[]    = array(
				'attachment_id' => $aid,
				'url'           => (string) $url,
				'thumb'         => (string) wp_get_attachment_image_url( $aid, 'medium' ),
				'caption'       => (string) ( $item['caption'] ?? '' ),
				'alt'           => (string) ( $item['alt'] ?? '' ) ?: (string) ( $item['caption'] ?? '' ),
				'tag'           => (string) ( $item['tag'] ?? '' ),
				'is_cover'      => ! empty( $item['is_cover'] ),
				'event_id'      => $event_id,
				'event_title'   => $event_id ? lnkup_plain_title( $event_id ) : '',
				'source'        => 'vendor_uploaded',
			);
		}
		return $out;
	}

	/**
	 * @param int $profile_id Profile ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function portfolio_raw( int $profile_id ): array {
		$raw   = (string) get_post_meta( $profile_id, self::PORTFOLIO_META, true );
		$items = '' !== $raw ? json_decode( $raw, true ) : null;
		if ( is_array( $items ) ) {
			return array_values( array_filter( $items, 'is_array' ) );
		}
		$legacy = array();
		foreach ( LNKup_Growth_Profile_Strength::gallery_ids( $profile_id ) as $i => $aid ) {
			$legacy[] = array( 'attachment_id' => (int) $aid, 'caption' => '', 'alt' => '', 'tag' => '', 'is_cover' => 0 === $i, 'event_id' => 0 );
		}
		return $legacy;
	}

	/**
	 * Validate and save portfolio items (order = array order). Keeps _lnkup_gallery_ids in sync.
	 *
	 * @param int   $profile_id Profile ID.
	 * @param int   $user_id    Acting user.
	 * @param array $items      Items.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function save_portfolio( int $profile_id, int $user_id, array $items ) {
		$existing_ids = array_map( static fn( $i ) => (int) $i['attachment_id'], self::portfolio_raw( $profile_id ) );
		$tags         = array_keys( self::portfolio_tags() );
		$clean        = array();
		$seen         = array();
		$vendor_id    = (int) get_post_meta( $profile_id, '_lnkup_owner_user_id', true );
		foreach ( array_slice( $items, 0, self::PORTFOLIO_MAX ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$aid = absint( $item['attachment_id'] ?? 0 );
			if ( ! $aid || isset( $seen[ $aid ] ) || ! LNKup_Security::filter_owned_images( array( $aid ), $user_id, $existing_ids ) ) {
				continue;
			}
			$seen[ $aid ] = true;
			$event_id     = absint( $item['event_id'] ?? 0 );
			if ( $event_id && ! self::vendor_participated( $vendor_id, $event_id ) ) {
				$event_id = 0;
			}
			$tag     = sanitize_key( (string) ( $item['tag'] ?? '' ) );
			$clean[] = array(
				'attachment_id' => $aid,
				'caption'       => mb_substr( sanitize_text_field( (string) ( $item['caption'] ?? '' ) ), 0, 200 ),
				'alt'           => mb_substr( sanitize_text_field( (string) ( $item['alt'] ?? '' ) ), 0, 200 ),
				'tag'           => in_array( $tag, $tags, true ) ? $tag : '',
				'is_cover'      => ! empty( $item['is_cover'] ),
				'event_id'      => $event_id,
			);
		}
		$covers = array_keys( array_filter( array_column( $clean, 'is_cover' ) ) );
		foreach ( $clean as $i => $row ) {
			$clean[ $i ]['is_cover'] = $covers ? $i === $covers[0] : 0 === $i;
		}
		update_post_meta( $profile_id, self::PORTFOLIO_META, wp_json_encode( $clean ) );
		update_post_meta( $profile_id, '_lnkup_gallery_ids', wp_json_encode( array_column( $clean, 'attachment_id' ) ) );
		self::flush_public_cache( $profile_id );
		return self::portfolio( $profile_id );
	}

	/**
	 * Keep portfolio metadata aligned when the legacy gallery field changes (order + additions + removals).
	 *
	 * @param int   $profile_id Profile ID.
	 * @param int[] $gallery    New gallery IDs (already ownership-checked).
	 */
	public static function sync_portfolio_from_gallery( int $profile_id, array $gallery ): void {
		$raw = (string) get_post_meta( $profile_id, self::PORTFOLIO_META, true );
		if ( '' === $raw ) {
			return;
		}
		$by_id = array();
		foreach ( self::portfolio_raw( $profile_id ) as $item ) {
			$by_id[ (int) $item['attachment_id'] ] = $item;
		}
		$items = array();
		foreach ( array_map( 'absint', $gallery ) as $aid ) {
			$items[] = $by_id[ $aid ] ?? array( 'attachment_id' => $aid, 'caption' => '', 'alt' => '', 'tag' => '', 'is_cover' => false, 'event_id' => 0 );
		}
		update_post_meta( $profile_id, self::PORTFOLIO_META, wp_json_encode( $items ) );
		self::flush_public_cache( $profile_id );
	}

	public static function portfolio_tags(): array {
		return array(
			'products'   => __( 'Products', 'lnkup-marketplace' ),
			'booth'      => __( 'Booth setup', 'lnkup-marketplace' ),
			'food'       => __( 'Food & drink', 'lnkup-marketplace' ),
			'activation' => __( 'Brand activation', 'lnkup-marketplace' ),
			'service'    => __( 'Service example', 'lnkup-marketplace' ),
			'event'      => __( 'Event highlight', 'lnkup-marketplace' ),
			'team'       => __( 'Team', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Configurable capability options grouped by marketplace category family.
	 *
	 * @return array<string,array{label:string,options:array<string,string>}>
	 */
	public static function capability_groups(): array {
		$groups = array(
			'food'    => array(
				'label'   => __( 'Food & beverage', 'lnkup-marketplace' ),
				'options' => array(
					'food_truck'      => __( 'Food truck', 'lnkup-marketplace' ),
					'food_tent'       => __( 'Food tent', 'lnkup-marketplace' ),
					'mobile_catering' => __( 'Mobile catering', 'lnkup-marketplace' ),
					'needs_power'     => __( 'Requires power', 'lnkup-marketplace' ),
					'needs_water'     => __( 'Requires water', 'lnkup-marketplace' ),
					'own_generator'   => __( 'Brings own generator', 'lnkup-marketplace' ),
					'counter_service' => __( 'Counter service', 'lnkup-marketplace' ),
					'full_service'    => __( 'Full service', 'lnkup-marketplace' ),
				),
			),
			'retail'  => array(
				'label'   => __( 'Retail', 'lnkup-marketplace' ),
				'options' => array(
					'apparel'     => __( 'Apparel', 'lnkup-marketplace' ),
					'beauty'      => __( 'Beauty', 'lnkup-marketplace' ),
					'merchandise' => __( 'Merchandise', 'lnkup-marketplace' ),
					'handmade'    => __( 'Handmade products', 'lnkup-marketplace' ),
					'accessories' => __( 'Accessories', 'lnkup-marketplace' ),
					'card_payments' => __( 'Accepts card payments', 'lnkup-marketplace' ),
				),
			),
			'service' => array(
				'label'   => __( 'Services', 'lnkup-marketplace' ),
				'options' => array(
					'photography'        => __( 'Photography', 'lnkup-marketplace' ),
					'entertainment'      => __( 'Entertainment services', 'lnkup-marketplace' ),
					'mobile_experience'  => __( 'Mobile experience', 'lnkup-marketplace' ),
					'business_services'  => __( 'Business services', 'lnkup-marketplace' ),
				),
			),
			'setup'   => array(
				'label'   => __( 'Setup', 'lnkup-marketplace' ),
				'options' => array(
					'indoor'       => __( 'Indoor ready', 'lnkup-marketplace' ),
					'outdoor'      => __( 'Outdoor ready', 'lnkup-marketplace' ),
					'own_tent'     => __( 'Brings own tent', 'lnkup-marketplace' ),
					'own_tables'   => __( 'Brings own tables', 'lnkup-marketplace' ),
					'multi_day'    => __( 'Multi-day events', 'lnkup-marketplace' ),
					'travels'      => __( 'Travels for tours', 'lnkup-marketplace' ),
				),
			),
		);
		return (array) apply_filters( 'lnkup_vendor_capability_groups', $groups );
	}

	/**
	 * Selected capabilities, validated against the current groups.
	 *
	 * @param int $profile_id Profile ID.
	 * @return array<int,array{key:string,label:string,group:string}>
	 */
	public static function capabilities( int $profile_id ): array {
		$selected = self::json_list( get_post_meta( $profile_id, '_lnkup_capabilities', true ) );
		$out      = array();
		foreach ( self::capability_groups() as $group_key => $group ) {
			foreach ( (array) $group['options'] as $key => $label ) {
				if ( in_array( $key, $selected, true ) ) {
					$out[] = array( 'key' => $key, 'label' => $label, 'group' => $group_key );
				}
			}
		}
		return $out;
	}

	/**
	 * Allowed capability keys.
	 *
	 * @return string[]
	 */
	public static function capability_keys(): array {
		$keys = array();
		foreach ( self::capability_groups() as $group ) {
			$keys = array_merge( $keys, array_keys( (array) $group['options'] ) );
		}
		return $keys;
	}

	private static function vendor_participated( int $vendor_id, int $event_id ): bool {
		$apps = get_posts(
			array(
				'post_type'      => 'lnkup_application',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_lnkup_vendor_user_id', 'value' => $vendor_id ),
					array( 'key' => '_lnkup_event_id', 'value' => $event_id ),
					array( 'key' => '_lnkup_application_status', 'value' => array( 'paid', 'completed' ), 'compare' => 'IN' ),
				),
			)
		);
		return ! empty( $apps );
	}

	/**
	 * @param mixed $raw    JSON list / array / CSV.
	 * @param bool  $as_int Cast to int.
	 * @return array<int,mixed>
	 */
	public static function json_list( $raw, bool $as_int = false ): array {
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$list    = is_array( $decoded ) ? $decoded : array_map( 'trim', explode( ',', $raw ) );
		} else {
			$list = array();
		}
		return $as_int ? array_values( array_filter( array_map( 'absint', $list ) ) ) : array_values( array_filter( array_map( 'strval', $list ), static fn( $v ) => '' !== trim( $v ) ) );
	}

	/**
	 * @param string $value   Handle or URL.
	 * @param string $network instagram|tiktok|facebook.
	 */
	public static function social_url( string $value, string $network ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		$handle = rawurlencode( ltrim( $value, '@' ) );
		switch ( $network ) {
			case 'tiktok':
				return 'https://www.tiktok.com/@' . $handle;
			case 'facebook':
				return 'https://www.facebook.com/' . $handle;
			default:
				return 'https://instagram.com/' . $handle;
		}
	}
}
