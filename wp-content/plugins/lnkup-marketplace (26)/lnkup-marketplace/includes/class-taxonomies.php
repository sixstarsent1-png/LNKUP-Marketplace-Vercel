<?php
/**
 * Taxonomies.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Taxonomies {

	const EVENT_TYPE_HIERARCHY_VERSION = 1;

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
	}

	/**
	 * Register taxonomies.
	 */
	public static function register() {
		register_taxonomy(
			'lnkup_vendor_category',
			array( 'lnkup_vendor_profile', 'lnkup_opportunity' ),
			array(
				'label'        => __( 'Vendor Categories', 'lnkup-marketplace' ),
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => false,
			)
		);

		register_taxonomy(
			'lnkup_event_type',
			array( 'lnkup_event' ),
			array(
				'label'        => __( 'Event Types', 'lnkup-marketplace' ),
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => true,
			)
		);

		register_taxonomy(
			'lnkup_market',
			array( 'lnkup_event', 'lnkup_opportunity' ),
			array(
				'label'        => __( 'Markets', 'lnkup-marketplace' ),
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => true,
			)
		);
	}

	/**
	 * Parent => child event type labels.
	 */
	public static function get_event_type_tree_definition() {
		return array(
			'Festivals'           => array(
				'Festival',
				'Food Festival',
				'Music Event',
				'Cultural Event',
				'Street Fair',
				'Rodeo',
				'Holiday Event',
			),
			'Conferences'         => array(
				'Conference',
				'Speaking Event',
				'Networking Event',
				'Trade Show',
				'Expo',
				'Workshop',
			),
			'Markets & Pop-Ups'   => array(
				'Market',
				'Vendor Market',
				'Pop-Up',
			),
			'Community Events'    => array(
				'Block Party',
				'Community Event',
				'Family Event',
				'Fundraiser',
				'Launch Event',
			),
			'Campus Events'       => array(
				'College Event',
				'HBCU Event',
				'Homecoming',
				'Greek Event',
				'Campus Event',
			),
			'Nightlife & Parties' => array(
				'Nightlife Event',
				'Day Party',
			),
			'Corporate & Brand'   => array(
				'Brand Activation',
				'Corporate Event',
				'Business Event',
				'Private Event',
			),
			'Sports & Wellness'   => array(
				'Sports Event',
				'Wellness Event',
			),
			'Arts & Culture'      => array(
				'Fashion Event',
				'Beauty Event',
				'Art Event',
			),
			'Other'               => array(
				'Other',
			),
		);
	}

	/**
	 * Seed default terms (idempotent).
	 */
	public static function seed_terms() {
		$vendor_categories = array(
			'Food Vendor',
			'Food Truck',
			'Desserts',
			'Dessert Vendor',
			'Drinks',
			'Beverage Vendor',
			'Retail',
			'Retail Vendor',
			'Clothing',
			'Clothing Brand',
			'Beauty',
			'Beauty Vendor',
			'Jewelry',
			'Jewelry Vendor',
			'Art',
			'Art Vendor',
			'Photo Booth',
			'Photo Booth Vendor',
			'Services',
			'Service Vendor',
			'Wellness',
			'Wellness Vendor',
			'Merchandise',
			'Merchandise Vendor',
			'Mobile Business',
		);
		foreach ( $vendor_categories as $term ) {
			if ( ! term_exists( $term, 'lnkup_vendor_category' ) ) {
				wp_insert_term( $term, 'lnkup_vendor_category' );
			}
		}

		self::seed_event_type_hierarchy();

		$markets = array(
			'Southeast',
			'Mid-Atlantic',
			'Northeast',
			'Midwest',
			'Southwest',
			'West Coast',
			'Florida',
			'Texas',
			'Carolinas',
		);
		foreach ( $markets as $term ) {
			if ( ! term_exists( $term, 'lnkup_market' ) ) {
				wp_insert_term( $term, 'lnkup_market' );
			}
		}
	}

	/**
	 * Ensure parent/child event type terms exist.
	 */
	public static function seed_event_type_hierarchy() {
		$tree = self::get_event_type_tree_definition();

		foreach ( $tree as $parent_name => $children ) {
			$parent_id = self::ensure_term( $parent_name, 'lnkup_event_type', 0 );
			if ( ! $parent_id ) {
				continue;
			}

			foreach ( $children as $child_name ) {
				self::ensure_term( $child_name, 'lnkup_event_type', $parent_id );
			}
		}

		self::cleanup_orphan_event_type_roots( array_keys( $tree ), self::all_child_names( $tree ) );
	}

	/**
	 * Reassign events that still reference root-level leaf terms or parent-only terms.
	 */
	public static function migrate_event_type_assignments() {
		$tree          = self::get_event_type_tree_definition();
		$parent_names  = array_keys( $tree );
		$child_names   = self::all_child_names( $tree );
		$child_parents = array();
		foreach ( $tree as $parent_name => $children ) {
			foreach ( $children as $child_name ) {
				$child_parents[ $child_name ] = $parent_name;
			}
		}

		$events = get_posts(
			array(
				'post_type'      => 'lnkup_event',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $events as $event_id ) {
			$terms = wp_get_post_terms( $event_id, 'lnkup_event_type' );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term = $terms[0];
			if ( (int) $term->parent > 0 ) {
				continue;
			}

			if ( in_array( $term->name, $parent_names, true ) && ! in_array( $term->name, $child_names, true ) ) {
				$fallback = $tree[ $term->name ][0] ?? 'Other';
				wp_set_object_terms( $event_id, $fallback, 'lnkup_event_type' );
				continue;
			}

			if ( in_array( $term->name, $child_names, true ) ) {
				$parent_name = $child_parents[ $term->name ] ?? '';
				$parent_id   = $parent_name ? self::term_id_by_name( $parent_name ) : 0;
				if ( $parent_id ) {
					wp_update_term( $term->term_id, 'lnkup_event_type', array( 'parent' => $parent_id ) );
				}
				continue;
			}

			wp_set_object_terms( $event_id, 'Other', 'lnkup_event_type' );
		}
	}

	/**
	 * One-time hierarchy migration + index rebuild flag.
	 */
	public static function maybe_upgrade_event_type_hierarchy() {
		$stored = (int) get_option( 'lnkup_event_type_hierarchy_version', 0 );
		if ( $stored >= self::EVENT_TYPE_HIERARCHY_VERSION ) {
			return;
		}

		self::seed_event_type_hierarchy();
		self::migrate_event_type_assignments();
		update_option( 'lnkup_event_type_hierarchy_version', self::EVENT_TYPE_HIERARCHY_VERSION );
		update_option( 'lnkup_needs_deferred_upgrade', LNKUP_VERSION );
	}

	/**
	 * Insert or reparent a taxonomy term.
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $parent   Parent term ID.
	 */
	private static function ensure_term( $name, $taxonomy, $parent = 0 ) {
		$existing = term_exists( $name, $taxonomy );
		if ( $existing ) {
			$term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
			wp_update_term(
				$term_id,
				$taxonomy,
				array(
					'parent' => (int) $parent,
				)
			);
			return $term_id;
		}

		$result = wp_insert_term(
			$name,
			$taxonomy,
			array(
				'parent' => (int) $parent,
			)
		);
		if ( is_wp_error( $result ) ) {
			return 0;
		}

		return (int) $result['term_id'];
	}

	/**
	 * @param array $tree Event type tree definition.
	 */
	private static function all_child_names( $tree ) {
		$names = array();
		foreach ( $tree as $children ) {
			$names = array_merge( $names, $children );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * @param string $name Term name.
	 */
	private static function term_id_by_name( $name ) {
		$term = get_term_by( 'name', $name, 'lnkup_event_type' );
		return ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
	}

	/**
	 * Move legacy flat root terms under Other when they are not parents.
	 *
	 * @param array $parent_names Parent term names.
	 * @param array $child_names  Child term names.
	 */
	private static function cleanup_orphan_event_type_roots( $parent_names, $child_names ) {
		$roots = get_terms(
			array(
				'taxonomy'   => 'lnkup_event_type',
				'parent'     => 0,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $roots ) || empty( $roots ) ) {
			return;
		}

		$other_parent_id = self::term_id_by_name( 'Other' );
		foreach ( $roots as $term ) {
			if ( in_array( $term->name, $parent_names, true ) ) {
				continue;
			}
			if ( in_array( $term->name, $child_names, true ) ) {
				continue;
			}
			if ( $other_parent_id ) {
				wp_update_term( $term->term_id, 'lnkup_event_type', array( 'parent' => $other_parent_id ) );
			}
		}
	}
}
