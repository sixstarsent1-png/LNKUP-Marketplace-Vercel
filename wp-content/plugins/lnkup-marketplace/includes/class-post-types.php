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
