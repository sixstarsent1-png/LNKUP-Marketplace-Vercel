<?php
/**
 * Vendor category groups for matching ("Food Vendor" ~ "Food Truck").
 *
 * Groups live in term meta `_lnkup_category_group` on lnkup_vendor_category
 * terms. Opportunity categories are mirrored into a normalized join table so
 * candidate lookups are indexed instead of LIKE scans over JSON.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Match_Categories {

	const META     = '_lnkup_category_group';
	const TAXONOMY = 'lnkup_vendor_category';

	/**
	 * Group key => label.
	 *
	 * @return array<string,string>
	 */
	public static function groups() {
		return array(
			'food'        => __( 'Food', 'lnkup-marketplace' ),
			'desserts'    => __( 'Desserts', 'lnkup-marketplace' ),
			'beverage'    => __( 'Beverages', 'lnkup-marketplace' ),
			'apparel'     => __( 'Apparel', 'lnkup-marketplace' ),
			'beauty'      => __( 'Beauty', 'lnkup-marketplace' ),
			'jewelry'     => __( 'Jewelry & Accessories', 'lnkup-marketplace' ),
			'art'         => __( 'Art & Crafts', 'lnkup-marketplace' ),
			'photo'       => __( 'Photo & Entertainment', 'lnkup-marketplace' ),
			'services'    => __( 'Services', 'lnkup-marketplace' ),
			'wellness'    => __( 'Wellness', 'lnkup-marketplace' ),
			'merchandise' => __( 'Merchandise & Retail', 'lnkup-marketplace' ),
			'mobile'      => __( 'Mobile Business', 'lnkup-marketplace' ),
		);
	}

	/**
	 * Keyword => group inference for seeding and new terms.
	 *
	 * @return array<string,string>
	 */
	private static function keyword_map() {
		return array(
			'dessert'   => 'desserts',
			'sweet'     => 'desserts',
			'bake'      => 'desserts',
			'ice cream' => 'desserts',
			'drink'     => 'beverage',
			'beverage'  => 'beverage',
			'coffee'    => 'beverage',
			'lemonade'  => 'beverage',
			'food'      => 'food',
			'truck'     => 'food',
			'bbq'       => 'food',
			'catering'  => 'food',
			'clothing'  => 'apparel',
			'apparel'   => 'apparel',
			'streetwear' => 'apparel',
			'beauty'    => 'beauty',
			'hair'      => 'beauty',
			'skin'      => 'beauty',
			'jewel'     => 'jewelry',
			'accessor'  => 'jewelry',
			'art'       => 'art',
			'craft'     => 'art',
			'photo'     => 'photo',
			'entertain' => 'photo',
			'service'   => 'services',
			'wellness'  => 'wellness',
			'fitness'   => 'wellness',
			'merch'     => 'merchandise',
			'retail'    => 'merchandise',
			'mobile'    => 'mobile',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 99 );
		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'on_term_created' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_object_terms_set' ), 20, 4 );
		add_action( 'lnkup_marketplace_index_changed', array( __CLASS__, 'on_index_changed' ), 5, 2 );
	}

	/**
	 * Infer group for a label.
	 *
	 * @param string $name Term name.
	 */
	public static function infer_group( $name ) {
		$name = strtolower( (string) $name );
		foreach ( self::keyword_map() as $needle => $group ) {
			if ( false !== strpos( $name, $needle ) ) {
				return $group;
			}
		}
		return '';
	}

	/**
	 * Run the one-time group seed queued by the DB migration.
	 */
	public static function maybe_seed() {
		if ( ! get_option( 'lnkup_match_seed_pending' ) || ! taxonomy_exists( self::TAXONOMY ) ) {
			return;
		}
		try {
			self::seed_groups();
			delete_option( 'lnkup_match_seed_pending' );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup category group seed failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Assign inferred groups to terms that have none (idempotent).
	 */
	public static function seed_groups() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $terms as $term ) {
			if ( '' !== (string) get_term_meta( $term->term_id, self::META, true ) ) {
				continue;
			}
			$group = self::infer_group( $term->name );
			if ( $group ) {
				update_term_meta( $term->term_id, self::META, $group );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * New category term gets an inferred group.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function on_term_created( $term_id ) {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( $term && ! is_wp_error( $term ) ) {
			$group = self::infer_group( $term->name );
			if ( $group ) {
				update_term_meta( $term_id, self::META, $group );
			}
		}
	}

	/**
	 * Group for a term.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function group_for_term( $term_id ) {
		return sanitize_key( (string) get_term_meta( (int) $term_id, self::META, true ) );
	}

	/**
	 * Admin: set or clear a term's group and refresh join tables.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $group   Group key or ''.
	 */
	public static function set_group( $term_id, $group ) {
		$term_id = absint( $term_id );
		$group   = sanitize_key( $group );
		if ( ! $term_id || ( '' !== $group && ! isset( self::groups()[ $group ] ) ) ) {
			return new WP_Error( 'lnkup_invalid_group', __( 'Choose a valid category group.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( '' === $group ) {
			delete_term_meta( $term_id, self::META );
		} else {
			update_term_meta( $term_id, self::META, $group );
		}
		global $wpdb;
		foreach ( array( LNKup_Match_Tables::vendor_categories_table(), LNKup_Match_Tables::opportunity_categories_table() ) as $table ) {
			$wpdb->update( $table, array( 'group_key' => $group ), array( 'term_id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		LNKup_Match_Cron::queue_full_rebuild();
		return true;
	}

	/**
	 * Category descriptors for an object (vendor profile or opportunity).
	 *
	 * @param int $object_id Post ID.
	 * @return array<int,array{term_id:int,name:string,group:string}>
	 */
	public static function for_object( $object_id ) {
		$terms = wp_get_post_terms( (int) $object_id, self::TAXONOMY );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $term ) {
			$out[] = array(
				'term_id' => (int) $term->term_id,
				'name'    => $term->name,
				'group'   => self::group_for_term( $term->term_id ),
			);
		}
		return $out;
	}

	/**
	 * Mirror an opportunity's categories into the join table.
	 *
	 * @param int $opportunity_id Opportunity ID.
	 */
	public static function sync_opportunity( $opportunity_id ) {
		global $wpdb;
		$table = LNKup_Match_Tables::opportunity_categories_table();
		$wpdb->delete( $table, array( 'opportunity_id' => (int) $opportunity_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Upsert: a concurrent sync may already have re-inserted the same pair after our delete.
		foreach ( self::for_object( $opportunity_id ) as $cat ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"INSERT INTO {$table} (opportunity_id, term_id, group_key) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE group_key = VALUES(group_key)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $opportunity_id,
					$cat['term_id'],
					$cat['group']
				)
			);
		}
	}

	/**
	 * Keep opportunity join rows aligned with the marketplace index.
	 *
	 * @param int    $opportunity_id Opportunity ID.
	 * @param string $change         synced|removed.
	 */
	public static function on_index_changed( $opportunity_id, $change ) {
		if ( 'removed' === $change ) {
			global $wpdb;
			$wpdb->delete( LNKup_Match_Tables::opportunity_categories_table(), array( 'opportunity_id' => (int) $opportunity_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		self::sync_opportunity( $opportunity_id );
	}

	/**
	 * Category changes on profiles/opportunities after save_post (e.g. wp_set_object_terms in AJAX).
	 */
	public static function on_object_terms_set( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$type = get_post_type( $object_id );
		if ( 'lnkup_opportunity' === $type ) {
			self::sync_opportunity( $object_id );
			LNKup_Match_Cron::mark_dirty( 'opportunity', $object_id );
		} elseif ( 'lnkup_vendor_profile' === $type ) {
			LNKup_Match_Vendor_Index::sync( $object_id );
		}
	}
}
