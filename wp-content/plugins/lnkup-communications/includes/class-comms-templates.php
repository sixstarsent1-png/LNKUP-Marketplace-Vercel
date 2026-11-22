<?php
/**
 * Template catalog, admin overrides and safe rendering.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Templates {

	const FIELDS = array( 'subject', 'heading', 'body', 'cta', 'push_title', 'push_body' );

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
	public static function save_override( string $key, array $fields, int $user_id ) {
		global $wpdb;
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
		return true;
	}

	public static function reset_override( string $key ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( LNKup_Comms_Schema::table( 'templates' ), array( 'template_key' => $key ) );
		wp_cache_delete( 'tpl_' . $key, 'lnkup_comms' );
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
		foreach ( array( 'subject', 'heading', 'cta', 'push_title', 'push_body' ) as $field ) {
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
				'title' => sanitize_text_field( (string) $item['title'] ),
				'meta'  => sanitize_text_field( (string) ( $item['meta'] ?? '' ) ),
				'url'   => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
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
