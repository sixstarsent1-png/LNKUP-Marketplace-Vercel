<?php
/**
 * Admin UI for connector Content Studio templates.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Connector_Content_Admin {

	/**
	 * Init hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 28 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . LNKup_Connector_Studio::TEMPLATE_CPT, array( __CLASS__, 'save_template' ), 10, 2 );
	}

	/**
	 * Submenu under LNKup.
	 */
	public static function add_menu() {
		add_submenu_page(
			'lnkup-marketplace',
			__( 'Connector Content', 'lnkup-marketplace' ),
			__( 'Connector Content', 'lnkup-marketplace' ),
			'lnkup_manage_all',
			'edit.php?post_type=' . LNKup_Connector_Studio::TEMPLATE_CPT
		);
	}

	/**
	 * Template meta box.
	 */
	public static function meta_boxes() {
		add_meta_box(
			'lnkup_connector_template_meta',
			__( 'Video template', 'lnkup-marketplace' ),
			array( __CLASS__, 'render_template_box' ),
			LNKup_Connector_Studio::TEMPLATE_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Fields.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_template_box( $post ) {
		wp_nonce_field( 'lnkup_connector_template', 'lnkup_connector_template_nonce' );
		$row = LNKup_Connector_Studio::template_row( $post->ID );
		$fields = array(
			'hook'        => 'textarea',
			'objective'   => 'text',
			'audience'    => 'text',
			'duration'    => 'text',
			'format'      => 'text',
			'category'    => 'text',
			'cta'         => 'textarea',
			'caption'     => 'textarea',
			'onscreen'    => 'text',
			'cover'       => 'text',
			'broll'       => 'textarea',
			'disclosure'  => 'textarea',
			'city'        => 'text',
			'campaign_dates' => 'text',
		);
		echo '<p class="description">' . esc_html__( 'Script lives in the main editor. Dashboard JS does not hardcode future templates.', 'lnkup-marketplace' ) . '</p>';
		foreach ( $fields as $key => $type ) {
			$val = is_array( $row[ $key ] ?? '' ) ? wp_json_encode( $row[ $key ] ) : (string) ( $row[ $key ] ?? '' );
			echo '<p><label><strong>' . esc_html( $key ) . '</strong><br />';
			if ( 'textarea' === $type ) {
				echo '<textarea name="lnkup_tpl[' . esc_attr( $key ) . ']" class="large-text" rows="3">' . esc_textarea( $val ) . '</textarea>';
			} else {
				echo '<input type="text" class="regular-text" name="lnkup_tpl[' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" />';
			}
			echo '</label></p>';
		}
		$platforms = is_array( $row['platforms'] ?? null ) ? implode( ',', $row['platforms'] ) : (string) ( $row['platforms'] ?? '' );
		$shots     = is_array( $row['shots'] ?? null ) ? implode( "\n", $row['shots'] ) : (string) ( $row['shots'] ?? '' );
		$styles    = is_array( $row['growth_styles'] ?? null ) ? implode( ',', $row['growth_styles'] ) : (string) ( $row['growth_styles'] ?? '' );
		$frames    = is_array( $row['story_frames'] ?? null ) ? implode( "\n", $row['story_frames'] ) : (string) ( $row['story_frames'] ?? '' );
		echo '<p><label><strong>platforms</strong> (comma)<br /><input class="large-text" name="lnkup_tpl[platforms]" value="' . esc_attr( $platforms ) . '" /></label></p>';
		echo '<p><label><strong>growth_styles</strong> (comma: relationship,video,community,event)<br /><input class="large-text" name="lnkup_tpl[growth_styles]" value="' . esc_attr( $styles ) . '" /></label></p>';
		echo '<p><label><strong>shots</strong> (one per line)<br /><textarea class="large-text" rows="4" name="lnkup_tpl[shots]">' . esc_textarea( $shots ) . '</textarea></label></p>';
		echo '<p><label><strong>story_frames</strong> (one per line)<br /><textarea class="large-text" rows="3" name="lnkup_tpl[story_frames]">' . esc_textarea( $frames ) . '</textarea></label></p>';
		echo '<p><label><input type="checkbox" name="lnkup_tpl[featured]" value="1" ' . checked( ! empty( $row['featured'] ), true, false ) . ' /> ' . esc_html__( 'Featured mission', 'lnkup-marketplace' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="lnkup_tpl[active]" value="1" ' . checked( ! isset( $row['active'] ) || ! empty( $row['active'] ), true, false ) . ' /> ' . esc_html__( 'Active', 'lnkup-marketplace' ) . '</label></p>';
	}

	/**
	 * Save template meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save_template( $post_id, $post ) {
		if ( ! isset( $_POST['lnkup_connector_template_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lnkup_connector_template_nonce'] ) ), 'lnkup_connector_template' ) ) {
			return;
		}
		if ( ! LNKup_Capabilities::user_has_manage_all() ) {
			return;
		}
		$input = isset( $_POST['lnkup_tpl'] ) ? wp_unslash( $_POST['lnkup_tpl'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_array( $input ) ) {
			return;
		}
		$lists = array( 'platforms', 'growth_styles', 'shots', 'story_frames' );
		foreach ( $input as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( in_array( $key, $lists, true ) ) {
				$parts = preg_split( '/[\n,]+/', (string) $value );
				$parts = array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
				update_post_meta( $post_id, '_lnkup_' . $key, wp_json_encode( $parts ) );
				continue;
			}
			if ( in_array( $key, array( 'featured', 'active' ), true ) ) {
				update_post_meta( $post_id, '_lnkup_' . $key, empty( $value ) ? 0 : 1 );
				continue;
			}
			update_post_meta( $post_id, '_lnkup_' . $key, sanitize_textarea_field( (string) $value ) );
		}
		if ( empty( $input['featured'] ) ) {
			update_post_meta( $post_id, '_lnkup_featured', 0 );
		}
		if ( empty( $input['active'] ) ) {
			update_post_meta( $post_id, '_lnkup_active', 0 );
		}
	}
}
