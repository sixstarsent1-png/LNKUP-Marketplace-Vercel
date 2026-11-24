<?php
/**
 * Template catalog, admin overrides and safe rendering.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Templates {

	/** inbox_body is optional; when empty the inbox reuses push_body. Push text never carries amounts, inbox text may. */
	const FIELDS = array( 'subject', 'heading', 'body', 'cta', 'push_title', 'push_body', 'inbox_body' );

	/**
	 * @var array<string, array>|null
	 */
	private static $defaults = null;

	public static function defaults(): array {
		if ( null === self::$defaults ) {
			$catalog        = include LNKUP_COMMS_DIR . 'includes/templates/catalog.php';
			self::$defaults = is_array( $catalog ) ? $catalog : array();
		}
		return self::$defaults;
	}

	/**
	 * Template merged with any admin override.
	 */
	public static function get( string $key ): ?array {
		$defaults = self::defaults();
		if ( ! isset( $defaults[ $key ] ) ) {
			return null;
		}
		$template = $defaults[ $key ];
		$override = self::override( $key );
		foreach ( self::FIELDS as $field ) {
			if ( isset( $override[ $field ] ) && '' !== $override[ $field ] && array() !== $override[ $field ] ) {
				$template[ $field ] = $override[ $field ];
			}
		}
		return $template;
	}

	public static function override( string $key ): array {
		global $wpdb;
		$cache = wp_cache_get( 'tpl_' . $key, 'lnkup_comms' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$table = LNKup_Comms_Schema::table( 'templates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw     = $wpdb->get_var( $wpdb->prepare( "SELECT fields FROM {$table} WHERE template_key = %s", $key ) );
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
		$fields  = is_array( $decoded ) ? $decoded : array();
		wp_cache_set( 'tpl_' . $key, $fields, 'lnkup_comms', HOUR_IN_SECONDS );
		return $fields;
	}

	/**
	 * Placeholders referenced by a template.
	 *
	 * @param array $template Template.
	 * @return string[]
	 */
	public static function placeholders( array $template ): array {
		$text = '';
		foreach ( self::FIELDS as $field ) {
			$value = $template[ $field ] ?? '';
			$text .= ' ' . ( is_array( $value ) ? implode( ' ', $value ) : (string) $value );
		}
		preg_match_all( '/\{\{\s*([a-z_]+)\s*\}\}/', $text, $matches );
		return array_values( array_unique( $matches[1] ?? array() ) );
	}

	/**
	 * Save an admin override after validating placeholders against the default.
	 *
	 * @param string $key    Template key.
	 * @param array  $fields Raw fields.
	 * @return true|WP_Error
	 */
	public static function save_override( string $key, array $fields, int $user_id, string $note = '' ) {
		global $wpdb;
		$clean = self::validate_fields( $key, $fields );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$table = LNKup_Comms_Schema::table( 'templates' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->replace(
			$table,
			array(
				'template_key' => $key,
				'fields'       => wp_json_encode( $clean ),
				'updated_by'   => $user_id,
				'updated_at'   => LNKup_Comms_Schema::now(),
			)
		);
		wp_cache_delete( 'tpl_' . $key, 'lnkup_comms' );
		self::record_version( $key, $clean, 'published', $user_id, $note );
		LNKup_Comms_Admin_Ops::audit( 'comms_template_published', $key, array( 'new' => $clean, 'reason' => $note ) );
		return true;
	}

	/**
	 * Save (or replace) the single working draft for a template. Drafts are validated
	 * exactly like published templates but never used for sending.
	 *
	 * @return int|WP_Error Draft version ID.
	 */
	public static function save_draft( string $key, array $fields, int $user_id ) {
		global $wpdb;
		$clean = self::validate_fields( $key, $fields );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$table = LNKup_Comms_Schema::table( 'template_versions' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, array( 'template_key' => $key, 'status' => 'draft' ) );
		return self::record_version( $key, $clean, 'draft', $user_id, '' );
	}

	/**
	 * @return array|null Draft row with decoded fields.
	 */
	public static function draft( string $key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Comms_Schema::table( 'template_versions' ) . " WHERE template_key = %s AND status = 'draft' ORDER BY id DESC LIMIT 1", $key ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['fields'] = json_decode( (string) $row['fields'], true ) ?: array();
		return $row;
	}

	/**
	 * Publish the working draft (re-validated at publish time).
	 *
	 * @return true|WP_Error
	 */
	public static function publish_draft( string $key, int $user_id ) {
		global $wpdb;
		$draft = self::draft( $key );
		if ( ! $draft ) {
			return new WP_Error( 'lnkup_comms_no_draft', __( 'There is no draft to publish.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$result = self::save_override( $key, (array) $draft['fields'], $user_id, __( 'Published from draft', 'lnkup-communications' ) );
		if ( true === $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( LNKup_Comms_Schema::table( 'template_versions' ), array( 'id' => (int) $draft['id'] ) );
		}
		return $result;
	}

	/**
	 * Republish an earlier version as a new version (history is never rewritten).
	 *
	 * @return true|WP_Error
	 */
	public static function restore_version( int $version_id, int $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Comms_Schema::table( 'template_versions' ) . ' WHERE id = %d', $version_id ), ARRAY_A );
		if ( ! is_array( $row ) || 'draft' === $row['status'] ) {
			return new WP_Error( 'lnkup_comms_version', __( 'That version could not be found.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$fields = json_decode( (string) $row['fields'], true ) ?: array();
		if ( array() === $fields ) {
			self::reset_override( (string) $row['template_key'], $user_id );
			return true;
		}
		/* translators: %d: version number */
		return self::save_override( (string) $row['template_key'], $fields, $user_id, sprintf( __( 'Restored version #%d', 'lnkup-communications' ), $version_id ) );
	}

	/**
	 * Newest first.
	 */
	public static function versions( string $key, int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, template_key, status, note, created_by, created_at, published_at FROM ' . LNKup_Comms_Schema::table( 'template_versions' ) . ' WHERE template_key = %s ORDER BY id DESC LIMIT %d', $key, $limit ), ARRAY_A );
	}

	private static function record_version( string $key, array $fields, string $status, int $user_id, string $note ): int {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'template_versions' );
		$now   = LNKup_Comms_Schema::now();
		if ( 'published' === $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, array( 'status' => 'archived' ), array( 'template_key' => $key, 'status' => 'published' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'template_key' => $key,
				'fields'       => wp_json_encode( $fields ),
				'status'       => $status,
				'note'         => substr( sanitize_text_field( $note ), 0, 255 ),
				'created_by'   => $user_id,
				'created_at'   => $now,
				'published_at' => 'published' === $status ? $now : null,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Sanitize fields and reject placeholders the message cannot resolve.
	 *
	 * @return array|WP_Error
	 */
	public static function validate_fields( string $key, array $fields ) {
		$defaults = self::defaults();
		if ( ! isset( $defaults[ $key ] ) ) {
			return new WP_Error( 'lnkup_comms_template', __( 'Unknown template.', 'lnkup-communications' ), array( 'status' => 404 ) );
		}
		$clean = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! isset( $fields[ $field ] ) ) {
				continue;
			}
			if ( 'body' === $field ) {
				$lines         = is_array( $fields[ $field ] ) ? $fields[ $field ] : preg_split( '/\r\n|\r|\n/', (string) $fields[ $field ] );
				$clean['body'] = array_values( array_filter( array_map( 'sanitize_text_field', (array) $lines ) ) );
			} else {
				$clean[ $field ] = sanitize_text_field( (string) $fields[ $field ] );
			}
		}
		$allowed = array_merge( self::placeholders( $defaults[ $key ] ), array( 'first_name', 'business_name', 'organizer_name', 'event_name', 'event_date', 'event_time', 'event_city', 'opportunity_name', 'booking_reference', 'application_status' ) );
		$unknown = array_diff( self::placeholders( $clean ), $allowed );
		if ( $unknown ) {
			return new WP_Error(
				'lnkup_comms_template_vars',
				/* translators: %s: variable list */
				sprintf( __( 'These variables are not available for this message: %s', 'lnkup-communications' ), implode( ', ', $unknown ) ),
				array( 'status' => 400 )
			);
		}
		return $clean;
	}

	public static function reset_override( string $key, int $user_id = 0 ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( LNKup_Comms_Schema::table( 'templates' ), array( 'template_key' => $key ) );
		wp_cache_delete( 'tpl_' . $key, 'lnkup_comms' );
		if ( isset( self::defaults()[ $key ] ) ) {
			self::record_version( $key, array(), 'published', $user_id ?: get_current_user_id(), __( 'Reset to default', 'lnkup-communications' ) );
			LNKup_Comms_Admin_Ops::audit( 'comms_template_reset', $key, array() );
		}
	}

	/**
	 * Render a template.
	 *
	 * @param string $key   Template key (trigger or trigger:variant).
	 * @param array  $vars  Resolved variables.
	 * @param string $role  Recipient role (for fallbacks).
	 * @param array  $items Optional list items: [ ['title' => '', 'meta' => '', 'url' => ''] ].
	 * @return array|WP_Error
	 */
	public static function render( string $key, array $vars, string $role, array $items = array() ) {
		$template = self::get( $key );
		if ( ! $template ) {
			return new WP_Error( 'lnkup_comms_render', 'missing_template:' . $key );
		}
		foreach ( (array) ( $template['required'] ?? array() ) as $required ) {
			if ( '' === trim( (string) ( $vars[ $required ] ?? '' ) ) ) {
				return new WP_Error( 'lnkup_comms_render', 'missing_variable:' . $required );
			}
		}
		$fallbacks = array(
			'first_name'    => __( 'there', 'lnkup-communications' ),
			'business_name' => 'organizer' === $role ? __( 'A vendor', 'lnkup-communications' ) : __( 'your business', 'lnkup-communications' ),
			'organizer_name' => __( 'The organizer', 'lnkup-communications' ),
		);

		$line = static function ( string $text, bool $droppable ) use ( $vars, $fallbacks ): ?string {
			$failed = false;
			$out    = preg_replace_callback(
				'/\{\{\s*([a-z_]+)\s*\}\}/',
				static function ( array $m ) use ( $vars, $fallbacks, &$failed ): string {
					$value = trim( (string) ( $vars[ $m[1] ] ?? '' ) );
					if ( '' === $value ) {
						$value = (string) ( $fallbacks[ $m[1] ] ?? '' );
					}
					if ( '' === $value ) {
						$failed = true;
					}
					return $value;
				},
				$text
			);
			if ( $failed ) {
				return $droppable ? null : '';
			}
			$out = trim( (string) $out );
			return false === strpos( $out, '{{' ) ? $out : ( $droppable ? null : '' );
		};

		$rendered = array();
		foreach ( array( 'subject', 'heading', 'cta', 'push_title', 'push_body', 'inbox_body' ) as $field ) {
			$value = $line( (string) ( $template[ $field ] ?? '' ), false );
			if ( '' === $value && in_array( $field, array( 'subject', 'heading', 'push_title' ), true ) ) {
				return new WP_Error( 'lnkup_comms_render', 'unresolved:' . $field );
			}
			$rendered[ $field ] = (string) $value;
		}

		$paragraphs = array();
		$exclusive  = array_map( 'intval', (array) ( $template['exclusive'] ?? array() ) );
		$used_excl  = false;
		foreach ( array_values( (array) ( $template['body'] ?? array() ) ) as $index => $text ) {
			if ( in_array( $index, $exclusive, true ) && $used_excl ) {
				continue;
			}
			$value = $line( (string) $text, true );
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( in_array( $index, $exclusive, true ) ) {
				$used_excl = true;
			}
			foreach ( preg_split( '/\r\n|\r|\n/', $value ) ?: array() as $part ) {
				if ( '' !== trim( $part ) ) {
					$paragraphs[] = trim( $part );
				}
			}
		}
		if ( array() === $paragraphs ) {
			return new WP_Error( 'lnkup_comms_render', 'empty_body' );
		}
		if ( '' === $rendered['push_body'] ) {
			$rendered['push_body'] = $paragraphs[0];
		}
		$rendered['push_title'] = self::truncate( $rendered['push_title'], 65 );
		$rendered['push_body']  = self::truncate( $rendered['push_body'], 178 );
		$rendered['inbox_body'] = self::truncate( $rendered['inbox_body'], 240 );
		$rendered['subject']    = self::truncate( $rendered['subject'], 120 );
		$rendered['paragraphs'] = $paragraphs;
		$rendered['items']      = self::clean_items( $items );
		return $rendered;
	}

	private static function truncate( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		if ( strlen( $text ) > $max ) {
			return rtrim( substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	private static function clean_items( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, 10 ) as $item ) {
			if ( ! is_array( $item ) || '' === trim( (string) ( $item['title'] ?? '' ) ) ) {
				continue;
			}
			$out[] = array(
				'title'        => sanitize_text_field( (string) $item['title'] ),
				'meta'         => sanitize_text_field( (string) ( $item['meta'] ?? '' ) ),
				'url'          => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
				'image'        => esc_url_raw( (string) ( $item['image'] ?? '' ) ),
				'badge'        => sanitize_text_field( (string) ( $item['badge'] ?? '' ) ),
				'action_label' => sanitize_text_field( (string) ( $item['action_label'] ?? '' ) ),
				'action_url'   => esc_url_raw( (string) ( $item['action_url'] ?? '' ) ),
			);
		}
		return $out;
	}

	/**
	 * Full HTML email.
	 *
	 * @param array  $rendered     render() output.
	 * @param string $action_url   Primary CTA URL (already tracked).
	 * @param array  $footer       prefs_url, unsubscribe_url, reason.
	 */
	public static function email_html( array $rendered, string $action_url, array $footer ): string {
		$args = array(
			'subject'         => (string) $rendered['subject'],
			'heading'         => (string) $rendered['heading'],
			'paragraphs'      => (array) $rendered['paragraphs'],
			'items'           => (array) $rendered['items'],
			'cta'             => (string) $rendered['cta'],
			'action_url'      => $action_url,
			'prefs_url'       => (string) ( $footer['prefs_url'] ?? '' ),
			'unsubscribe_url' => (string) ( $footer['unsubscribe_url'] ?? '' ),
			'reason'          => (string) ( $footer['reason'] ?? '' ),
			'address'         => (string) ( $footer['address'] ?? '' ),
		);
		ob_start();
		include LNKUP_COMMS_DIR . 'templates/email/layout.php';
		return (string) ob_get_clean();
	}

	public static function email_text( array $rendered, string $action_url, array $footer ): string {
		$lines   = array( (string) $rendered['heading'], '' );
		foreach ( (array) $rendered['paragraphs'] as $paragraph ) {
			$lines[] = (string) $paragraph;
			$lines[] = '';
		}
		foreach ( (array) $rendered['items'] as $item ) {
			$lines[] = '- ' . $item['title'] . ( '' !== $item['meta'] ? ' (' . $item['meta'] . ')' : '' ) . ( '' !== $item['url'] ? ': ' . $item['url'] : '' );
		}
		if ( '' !== $action_url && '' !== (string) $rendered['cta'] ) {
			$lines[] = '';
			$lines[] = $rendered['cta'] . ': ' . $action_url;
		}
		$lines[] = '';
		$lines[] = '--';
		if ( ! empty( $footer['reason'] ) ) {
			$lines[] = (string) $footer['reason'];
		}
		if ( ! empty( $footer['prefs_url'] ) ) {
			$lines[] = __( 'Notification settings:', 'lnkup-communications' ) . ' ' . $footer['prefs_url'];
		}
		if ( ! empty( $footer['unsubscribe_url'] ) ) {
			$lines[] = __( 'Unsubscribe:', 'lnkup-communications' ) . ' ' . $footer['unsubscribe_url'];
		}
		if ( ! empty( $footer['address'] ) ) {
			$lines[] = (string) $footer['address'];
		}
		return implode( "\n", $lines );
	}
}
