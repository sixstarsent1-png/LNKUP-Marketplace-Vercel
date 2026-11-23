<?php
/**
 * Event brands (lnkup_brand): a recognizable event identity owned by an organizer, e.g.
 * "Dirty South Rodeo Festival". Tours and series can belong to a brand; single-event organizers
 * never need one.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Brands {

	const MAX_PER_ORGANIZER = 50;

	/**
	 * @param int $brand_id Brand.
	 * @param int $user_id  User.
	 */
	public static function owns( $brand_id, $user_id ) {
		if ( 'lnkup_brand' !== get_post_type( $brand_id ) ) {
			return false;
		}
		return LNKup_Capabilities::user_has_manage_all( $user_id ) || (int) get_post_meta( $brand_id, '_lnkup_organizer_user_id', true ) === (int) $user_id;
	}

	/**
	 * @param int $organizer_id Owner.
	 */
	public static function list_for_organizer( $organizer_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_brand',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PER_ORGANIZER,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) absint( $organizer_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( array( __CLASS__, 'format' ), array_map( 'intval', $ids ) );
	}

	/**
	 * @param int $brand_id Brand.
	 */
	public static function format( $brand_id ) {
		return array(
			'id'          => (int) $brand_id,
			'name'        => lnkup_plain_title( $brand_id ),
			'description' => (string) get_post_field( 'post_content', $brand_id ),
			'website'     => (string) get_post_meta( $brand_id, '_lnkup_brand_website', true ),
			'logo_url'    => (string) get_the_post_thumbnail_url( $brand_id, 'thumbnail' ),
		);
	}

	/**
	 * Create or update a brand.
	 *
	 * @param int   $organizer_id Owner.
	 * @param array $data         name, description, website.
	 * @param int   $brand_id     Existing brand (0 = create).
	 * @return array|WP_Error
	 */
	public static function save( $organizer_id, array $data, $brand_id = 0 ) {
		$brand_id = absint( $brand_id );
		if ( $brand_id && ! self::owns( $brand_id, $organizer_id ) ) {
			return new WP_Error( 'lnkup_forbidden', __( 'You can only edit your own brands.', 'lnkup-marketplace' ), array( 'status' => 403 ) );
		}
		$name = mb_substr( trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) ), 0, 160 );
		if ( ! $brand_id && '' === $name ) {
			return new WP_Error( 'lnkup_missing_name', __( 'Give the brand a name.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( ! $brand_id && count( self::list_for_organizer( $organizer_id ) ) >= self::MAX_PER_ORGANIZER ) {
			return new WP_Error( 'lnkup_too_many_brands', sprintf( __( 'You can create up to %d brands.', 'lnkup-marketplace' ), self::MAX_PER_ORGANIZER ), array( 'status' => 400 ) );
		}
		$post = array(
			'post_type'   => 'lnkup_brand',
			'post_status' => 'publish',
		);
		if ( '' !== $name ) {
			$post['post_title'] = $name;
		}
		if ( array_key_exists( 'description', $data ) ) {
			$post['post_content'] = mb_substr( sanitize_textarea_field( (string) $data['description'] ), 0, 4000 );
		}
		if ( $brand_id ) {
			$post['ID'] = $brand_id;
			$result     = wp_update_post( $post, true );
		} else {
			$post['post_author'] = (int) $organizer_id;
			$result              = wp_insert_post( $post, true );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'lnkup_brand_save_failed', __( 'We could not save this brand.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		$brand_id = (int) $result;
		update_post_meta( $brand_id, '_lnkup_organizer_user_id', (int) get_post_meta( $brand_id, '_lnkup_organizer_user_id', true ) ?: (int) $organizer_id );
		if ( array_key_exists( 'website', $data ) ) {
			update_post_meta( $brand_id, '_lnkup_brand_website', esc_url_raw( (string) $data['website'] ) );
		}
		return self::format( $brand_id );
	}
}
