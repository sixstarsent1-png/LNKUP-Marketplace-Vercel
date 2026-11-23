<?php
/**
 * Organizer event templates with immutable, versioned snapshots.
 *
 * A template (lnkup_event_template) holds the same configuration as tour defaults: booth types,
 * prices, capacities, document requirements, application questions, deadline offset and vendor
 * description. Each config change writes a new row to lnkup_template_versions; tours and stops
 * record the version they were built from, so editing a template never changes past events.
 *
 * Templates contain configuration only. They never carry payments, booking status, approvals,
 * check-ins or financial data, because none of those are part of the config schema.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Portfolio_Templates {

	const MAX_PER_ORGANIZER = 50;

	/**
	 * @param int $template_id Template.
	 * @param int $user_id     User.
	 */
	public static function owns( $template_id, $user_id ) {
		if ( 'lnkup_event_template' !== get_post_type( $template_id ) || 'trash' === get_post_status( $template_id ) ) {
			return false;
		}
		return LNKup_Capabilities::user_has_manage_all( $user_id ) || (int) get_post_meta( $template_id, '_lnkup_organizer_user_id', true ) === (int) $user_id;
	}

	/**
	 * @param int $organizer_id Owner.
	 */
	public static function list_for_organizer( $organizer_id ) {
		$ids = get_posts(
			array(
				'post_type'      => 'lnkup_event_template',
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_PER_ORGANIZER,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_key'       => '_lnkup_organizer_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) absint( $organizer_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return array_map( array( __CLASS__, 'format' ), array_map( 'intval', $ids ) );
	}

	/**
	 * @param int $template_id Template.
	 */
	public static function format( $template_id ) {
		$version = self::current_version( $template_id );
		$config  = $version ? $version['config'] : LNKup_Portfolio_Config::sanitize_defaults( array() );
		return array(
			'id'         => (int) $template_id,
			'name'       => lnkup_plain_title( $template_id ),
			'version'    => $version ? (int) $version['version'] : 0,
			'version_id' => $version ? (int) $version['id'] : 0,
			'config'     => $config,
			'summary'    => array(
				'booth_types' => count( $config['booth_types'] ),
				'questions'   => count( $config['questions'] ),
				'documents'   => count( $config['required_documents'] ),
			),
			'updated_at' => (string) get_post_field( 'post_modified', $template_id ),
		);
	}

	/**
	 * Latest version row.
	 *
	 * @param int $template_id Template.
	 * @return array|null { id, version, config }
	 */
	public static function current_version( $template_id ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::template_versions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE template_id = %d ORDER BY version DESC LIMIT 1", absint( $template_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::format_version( $row ) : null;
	}

	/**
	 * A specific immutable version.
	 *
	 * @param int $version_id Version row ID.
	 * @return array|null
	 */
	public static function get_version( $version_id ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::template_versions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $version_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::format_version( $row ) : null;
	}

	/**
	 * @param array $row DB row.
	 */
	private static function format_version( array $row ) {
		$config = json_decode( (string) $row['config_json'], true );
		$clean  = LNKup_Portfolio_Config::sanitize_defaults( is_array( $config ) ? $config : array() );
		return array(
			'id'          => (int) $row['id'],
			'template_id' => (int) $row['template_id'],
			'version'     => (int) $row['version'],
			'config'      => is_wp_error( $clean ) ? LNKup_Portfolio_Config::sanitize_defaults( array() ) : $clean,
			'created_at'  => $row['created_at'],
		);
	}

	/**
	 * Create a template (version 1).
	 *
	 * @param int    $organizer_id Owner.
	 * @param string $name         Template name.
	 * @param array  $config       Raw config.
	 * @return array|WP_Error
	 */
	public static function create( $organizer_id, $name, array $config ) {
		$name = mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 160 );
		if ( '' === $name ) {
			return new WP_Error( 'lnkup_missing_name', __( 'Name the template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		if ( count( self::list_for_organizer( $organizer_id ) ) >= self::MAX_PER_ORGANIZER ) {
			return new WP_Error( 'lnkup_too_many_templates', sprintf( __( 'You can keep up to %d templates.', 'lnkup-marketplace' ), self::MAX_PER_ORGANIZER ), array( 'status' => 400 ) );
		}
		$clean = LNKup_Portfolio_Config::sanitize_defaults( $config );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$template_id = wp_insert_post(
			array(
				'post_type'   => 'lnkup_event_template',
				'post_status' => 'publish',
				'post_title'  => $name,
				'post_author' => (int) $organizer_id,
			),
			true
		);
		if ( is_wp_error( $template_id ) ) {
			return new WP_Error( 'lnkup_template_save_failed', __( 'We could not save this template.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		update_post_meta( $template_id, '_lnkup_organizer_user_id', (int) $organizer_id );
		$version = self::write_version( (int) $template_id, $clean, (int) $organizer_id );
		if ( is_wp_error( $version ) ) {
			return $version;
		}
		LNKup_Portfolio_Audit::log( 'template', $template_id, 'template_created', array( 'version' => 1 ), $organizer_id );
		return self::format( (int) $template_id );
	}

	/**
	 * Save a tour's current defaults as a new template.
	 *
	 * @param int    $organizer_id Owner.
	 * @param int    $tour_id      Tour.
	 * @param string $name         Template name.
	 */
	public static function create_from_tour( $organizer_id, $tour_id, $name ) {
		return self::create( $organizer_id, $name, LNKup_Portfolio_Config::get_defaults( $tour_id ) );
	}

	/**
	 * Rename and/or write a new config version.
	 *
	 * @param int   $template_id  Template.
	 * @param int   $organizer_id Owner.
	 * @param array $data         name?, config?
	 * @return array|WP_Error
	 */
	public static function update( $template_id, $organizer_id, array $data ) {
		if ( array_key_exists( 'name', $data ) ) {
			$name = mb_substr( trim( sanitize_text_field( (string) $data['name'] ) ), 0, 160 );
			if ( '' === $name ) {
				return new WP_Error( 'lnkup_missing_name', __( 'Name the template.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
			}
			wp_update_post( array( 'ID' => $template_id, 'post_title' => $name ) );
		}
		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$clean = LNKup_Portfolio_Config::sanitize_defaults( $data['config'] );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
			$current = self::current_version( $template_id );
			if ( ! $current || wp_json_encode( $current['config'] ) !== wp_json_encode( $clean ) ) {
				$written = self::write_version( (int) $template_id, $clean, (int) $organizer_id );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
			}
		}
		LNKup_Portfolio_Audit::log( 'template', $template_id, 'template_updated', array( 'fields' => array_keys( $data ) ), $organizer_id );
		return self::format( (int) $template_id );
	}

	/**
	 * @param int    $template_id  Template.
	 * @param int    $organizer_id Owner.
	 * @param string $name         Name for the copy.
	 */
	public static function duplicate( $template_id, $organizer_id, $name = '' ) {
		$current = self::current_version( $template_id );
		$name    = '' !== trim( (string) $name ) ? $name : sprintf( __( '%s (copy)', 'lnkup-marketplace' ), lnkup_plain_title( $template_id ) );
		return self::create( $organizer_id, $name, $current ? $current['config'] : array() );
	}

	/**
	 * Retire a template. Past versions stay in the versions table for tours that used them.
	 *
	 * @param int $template_id  Template.
	 * @param int $organizer_id Owner.
	 */
	public static function delete( $template_id, $organizer_id ) {
		wp_trash_post( $template_id );
		LNKup_Portfolio_Audit::log( 'template', $template_id, 'template_deleted', array(), $organizer_id );
		return array( 'id' => (int) $template_id, 'deleted' => true );
	}

	/**
	 * Copy the template's current version into a tour's defaults. Published stops are unaffected
	 * (their booth listings already exist) and draft stop overrides still win.
	 *
	 * @param int $template_id  Template.
	 * @param int $tour_id      Tour.
	 * @param int $organizer_id Owner.
	 * @return array|WP_Error Saved defaults with version_id.
	 */
	public static function apply_to_tour( $template_id, $tour_id, $organizer_id ) {
		$current = self::current_version( $template_id );
		if ( ! $current ) {
			return new WP_Error( 'lnkup_template_empty', __( 'This template has no saved setup yet.', 'lnkup-marketplace' ), array( 'status' => 400 ) );
		}
		$saved = LNKup_Portfolio_Config::save_defaults( $tour_id, $current['config'] );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		update_post_meta( $tour_id, '_lnkup_template_version_id', (int) $current['id'] );
		LNKup_Portfolio_Audit::log( 'tour', $tour_id, 'template_applied', array( 'template_id' => (int) $template_id, 'version_id' => (int) $current['id'] ), $organizer_id );
		return array(
			'defaults'   => $saved,
			'version_id' => (int) $current['id'],
		);
	}

	/**
	 * @param int   $template_id  Template.
	 * @param array $config       Clean config.
	 * @param int   $organizer_id Author.
	 * @return int|WP_Error Version row ID.
	 */
	private static function write_version( $template_id, array $config, $organizer_id ) {
		global $wpdb;
		$table = LNKup_Portfolio_Tables::template_versions_table();
		$next  = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM {$table} WHERE template_id = %d", $template_id ) ); // phpcs:ignore WordPress.DB
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'template_id' => $template_id,
				'version'     => $next,
				'config_json' => wp_json_encode( $config ),
				'created_by'  => $organizer_id,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'lnkup_template_version_failed', __( 'We could not save this template version. Try again.', 'lnkup-marketplace' ), array( 'status' => 500 ) );
		}
		update_post_meta( $template_id, '_lnkup_current_version', $next );
		return (int) $wpdb->insert_id;
	}
}
