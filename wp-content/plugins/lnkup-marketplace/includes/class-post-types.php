<?php
/**
 * Custom post types.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Post_Types {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'rest_lnkup_event_query', array( __CLASS__, 'hide_draft_events_from_rest_query' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'hide_draft_event_from_rest_item' ), 10, 3 );
	}

	/**
	 * Post types exposed through core wp/v2 REST routes.
	 *
	 * Everything else (applications, payments, documents, packets, CRM records,
	 * profiles, reviews) is served only through permission-checked lnkup/v1 routes.
	 */
	const REST_PUBLIC_TYPES = array( 'lnkup_event', 'lnkup_opportunity' );

	/**
	 * Whether a post type may appear in core wp/v2 REST routes.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function is_rest_public( $post_type ) {
		return in_array( $post_type, self::REST_PUBLIC_TYPES, true );
	}

	/**
	 * Organizer drafts are stored as published posts with `_lnkup_event_status = draft`; keep them
	 * out of public wp/v2 event lists until the organizer publishes vendor spots.
	 *
	 * @param array<string,mixed> $args WP_Query args.
	 * @return array<string,mixed>
	 */
	public static function hide_draft_events_from_rest_query( $args ) {
		if ( current_user_can( 'edit_others_posts' ) ) {
			return $args;
		}
		$args               = is_array( $args ) ? $args : array();
		$meta_query         = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta_query[]       = array(
			'relation' => 'OR',
			array( 'key' => '_lnkup_event_status', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_lnkup_event_status', 'value' => 'draft', 'compare' => '!=' ),
		);
		$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	/**
	 * A single wp/v2 GET of a draft event answers like a missing post unless the viewer owns it.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Short-circuit response.
	 * @param array                                            $handler  Route handler.
	 * @param WP_REST_Request                                  $request  Request.
	 * @return mixed
	 */
	public static function hide_draft_event_from_rest_item( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method() ) {
			return $response;
		}
		if ( ! preg_match( '#^/wp/v2/lnkup_event/(\d+)$#', (string) $request->get_route(), $match ) ) {
			return $response;
		}
		$event_id = (int) $match[1];
		if ( 'draft' !== (string) get_post_meta( $event_id, '_lnkup_event_status', true ) ) {
			return $response;
		}
		$viewer = get_current_user_id();
		if ( current_user_can( 'edit_others_posts' ) || ( $viewer && (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) === $viewer ) ) {
			return $response;
		}
		return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.' ), array( 'status' => 404 ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
	}

	/**
	 * Register all CPTs.
	 */
	public static function register() {
		$types = array(
			'lnkup_vendor_profile' => array(
				'label'  => __( 'Vendor Profiles', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-store',
				'supports' => array( 'title', 'editor', 'thumbnail' ),
			),
			'lnkup_event' => array(
				'label'  => __( 'Events', 'lnkup-marketplace' ),
				'public' => true,
				'show_ui' => true,
				'menu_icon' => 'dashicons-calendar-alt',
				'supports' => array( 'title', 'editor', 'thumbnail' ),
				'has_archive' => false,
			),
			'lnkup_opportunity' => array(
				'label'  => __( 'Opportunities', 'lnkup-marketplace' ),
				'public' => true,
				'show_ui' => true,
				'menu_icon' => 'dashicons-tickets-alt',
				'supports' => array( 'title', 'editor' ),
			),
			'lnkup_application' => array(
				'label'  => __( 'Applications', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-clipboard',
				'supports' => array( 'title', 'editor' ),
			),
			'lnkup_document' => array(
				'label'  => __( 'Documents', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-media-document',
				'supports' => array( 'title' ),
			),
			'lnkup_payment' => array(
				'label'  => __( 'Payments', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-money-alt',
				'supports' => array( 'title' ),
			),
			'lnkup_packet' => array(
				'label'  => __( 'Vendor Packets', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-portfolio',
				'supports' => array( 'title', 'editor' ),
			),
			'lnkup_vendor_note' => array(
				'label'  => __( 'Vendor Notes', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-edit',
				'supports' => array( 'title', 'editor' ),
			),
			'lnkup_vendor_list' => array(
				'label'  => __( 'Vendor Lists', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-list-view',
				'supports' => array( 'title' ),
			),
			'lnkup_vendor_invite' => array(
				'label'  => __( 'Vendor Invites', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-email',
				'supports' => array( 'title' ),
			),
			'lnkup_tour' => array(
				'label'  => __( 'Tours & Series', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-location-alt',
				'supports' => array( 'title', 'editor', 'thumbnail' ),
			),
			'lnkup_brand' => array(
				'label'  => __( 'Event Brands', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-awards',
				'supports' => array( 'title', 'editor', 'thumbnail' ),
			),
			'lnkup_event_template' => array(
				'label'  => __( 'Event Templates', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-admin-page',
				'supports' => array( 'title' ),
			),
			'lnkup_vendor_flag' => array(
				'label'  => __( 'Vendor Flags', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-flag',
				'supports' => array( 'title' ),
			),
			'lnkup_review' => array(
				'label'  => __( 'Vendor Reviews', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-star-filled',
				'supports' => array( 'title', 'editor' ),
			),
			'lnkup_dispute' => array(
				'label'  => __( 'Review Disputes', 'lnkup-marketplace' ),
				'public' => false,
				'show_ui' => true,
				'menu_icon' => 'dashicons-warning',
				'supports' => array( 'title', 'editor' ),
			),
		);

		foreach ( $types as $slug => $args ) {
			register_post_type(
				$slug,
				array_merge(
					array(
						'labels'       => array(
							'name'          => $args['label'],
							'singular_name' => $args['label'],
						),
						'public'       => $args['public'],
						'show_ui'      => $args['show_ui'],
						'show_in_rest' => self::is_rest_public( $slug ),
						'menu_icon'    => $args['menu_icon'],
						'supports'     => $args['supports'],
						'capability_type' => 'post',
						'map_meta_cap' => true,
					),
					isset( $args['has_archive'] ) ? array( 'has_archive' => $args['has_archive'] ) : array()
				)
			);
		}
	}
}
