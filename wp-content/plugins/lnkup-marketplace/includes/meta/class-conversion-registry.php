<?php
/**
 * Canonical marketplace conversion registry and outbox.
 *
 * One place decides that a registration, application, booking or payment happened.
 * `record()` writes one row per logical conversion (UNIQUE dedupe_key), so retries,
 * refreshes, duplicate webhooks and concurrent requests collapse into a single event.
 * Every consumer (Meta Pixel, Conversions API, internal analytics, communications via
 * `lnkup_conversion_recorded`) reads that same row and event_id.
 *
 * record() never calls the network; dispatch happens in the background.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Conversion_Registry {

	const SCHEMA_VERSION      = 1;
	const PENDING_BROWSER_META = '_lnkup_meta_pending_browser';
	const MAX_PENDING_BROWSER = 10;

	/** Custom-data keys allowed per parameter group; anything else is dropped. */
	const CUSTOM_KEYS = array(
		'content_ids', 'content_name', 'content_type', 'content_category', 'contents', 'num_items',
		'value', 'currency', 'order_id', 'search_string', 'status', 'marketplace_role', 'event_category',
		'vendor_category', 'campaign_market', 'event_series_id', 'opportunity_id', 'event_id',
	);

	/**
	 * Event definitions.
	 *
	 * scope: user (once per user), entity (once per entity), item (once per user+entity), none (every call).
	 * browser: also fire through the Pixel with the same event_id.
	 * server: send through the Conversions API.
	 * mirror: also write to lnkup_analytics_events.
	 * optimize: suitable as an ad optimization / custom conversion event.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$std = static function ( string $scope, bool $browser, bool $server, bool $optimize, string $role = '' ): array {
			return array( 'standard' => true, 'scope' => $scope, 'browser' => $browser, 'server' => $server, 'mirror' => true, 'optimize' => $optimize, 'role' => $role );
		};
		$custom = static function ( string $scope, bool $browser, bool $server, bool $optimize, string $role ): array {
			return array( 'standard' => false, 'scope' => $scope, 'browser' => $browser, 'server' => $server, 'mirror' => true, 'optimize' => $optimize, 'role' => $role );
		};

		$defs = array(
			// Standard events.
			'PageView'             => array_merge( $std( 'none', true, false, false ), array( 'mirror' => false ) ),
			'ViewContent'          => array_merge( $std( 'none', true, true, false ), array( 'mirror' => false ) ),
			'Search'               => array_merge( $std( 'none', true, true, false ), array( 'mirror' => false ) ),
			'AddToWishlist'        => $std( 'item', true, true, false, 'vendor' ),
			'CompleteRegistration' => $std( 'user', true, true, true ),
			'SubmitApplication'    => $std( 'entity', true, true, true, 'vendor' ),
			'InitiateCheckout'     => $std( 'entity', true, true, true, 'vendor' ),
			'Purchase'             => $std( 'entity', true, true, true, 'vendor' ),

			// Organizer.
			'OrganizerRegistrationStarted'     => array_merge( $custom( 'none', true, true, false, 'organizer' ), array( 'mirror' => false ) ),
			'OrganizerRegistered'              => $custom( 'user', true, true, true, 'organizer' ),
			'OrganizerProfileCompleted'        => $custom( 'user', true, true, false, 'organizer' ),
			'EventCreated'                     => $custom( 'entity', true, true, false, 'organizer' ),
			'FirstEventCreated'                => $custom( 'user', true, true, false, 'organizer' ),
			'EventPublished'                   => $custom( 'entity', true, true, true, 'organizer' ),
			'FirstEventPublished'              => $custom( 'user', true, true, true, 'organizer' ),
			'VendorOpportunityPublished'       => $custom( 'entity', true, true, true, 'organizer' ),
			'VendorInvited'                    => $custom( 'entity', true, true, false, 'organizer' ),
			'FirstVendorInvited'               => $custom( 'user', true, true, false, 'organizer' ),
			'OrganizerFirstApplicationReceived' => $custom( 'user', false, true, false, 'organizer' ),
			'OrganizerFirstVendorConfirmed'    => $custom( 'user', false, true, false, 'organizer' ),
			'OrganizerFirstPaidBooking'        => $custom( 'user', false, true, false, 'organizer' ),
			'RepeatOrganizerEvent'             => $custom( 'entity', true, true, false, 'organizer' ),
			'OrganizerActivated'               => $custom( 'user', true, true, true, 'organizer' ),

			// Vendor.
			'VendorRegistrationStarted' => array_merge( $custom( 'none', true, true, false, 'vendor' ), array( 'mirror' => false ) ),
			'VendorRegistered'          => $custom( 'user', true, true, true, 'vendor' ),
			'VendorProfileCompleted'    => $custom( 'user', true, true, false, 'vendor' ),
			'FirstVendorApplication'    => $custom( 'user', true, true, true, 'vendor' ),
			'ApplicationApproved'       => $custom( 'entity', false, true, false, 'vendor' ),
			'FirstApplicationApproved'  => $custom( 'user', false, true, false, 'vendor' ),
			'FirstVendorBooking'        => $custom( 'user', true, true, true, 'vendor' ),
			'RepeatVendorBooking'       => $custom( 'entity', true, true, false, 'vendor' ),
			'VendorEventCompleted'      => $custom( 'entity', false, true, false, 'vendor' ),
			'VendorActivated'           => $custom( 'user', true, true, true, 'vendor' ),

			// Internal only.
			'PaymentRefunded' => array( 'standard' => false, 'scope' => 'entity', 'browser' => false, 'server' => false, 'mirror' => true, 'optimize' => false, 'role' => 'vendor' ),
		);
		return (array) apply_filters( 'lnkup_conversion_registry', $defs );
	}

	public static function get( string $name ): ?array {
		$defs = self::definitions();
		return isset( $defs[ $name ] ) && is_array( $defs[ $name ] ) ? $defs[ $name ] : null;
	}

	/**
	 * RFC 4122 v4 UUID.
	 */
	public static function uuid(): string {
		return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0x0fff ) | 0x4000,
			random_int( 0, 0x3fff ) | 0x8000,
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff ),
			random_int( 0, 0xffff )
		);
	}

	public static function is_uuid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	/**
	 * Deterministic idempotency key for a conversion.
	 *
	 * @param string $name        Event name.
	 * @param string $scope       user|entity|item|none.
	 * @param int    $user_id     User.
	 * @param string $entity_type Entity type.
	 * @param int    $entity_id   Entity ID.
	 * @param string $event_id    Event ID (used for scope none).
	 */
	public static function dedupe_key( string $name, string $scope, int $user_id, string $entity_type, int $entity_id, string $event_id ): string {
		switch ( $scope ) {
			case 'user':
				return $name . ':user:' . $user_id;
			case 'entity':
				return $name . ':' . $entity_type . ':' . $entity_id;
			case 'item':
				return $name . ':' . $entity_type . ':' . $entity_id . ':u' . $user_id;
			default:
				return $name . ':evt:' . $event_id;
		}
	}

	/**
	 * Keep only allow-listed, scalar (or list-of-scalar) custom data.
	 *
	 * @param array<string,mixed> $data Raw.
	 * @return array<string,mixed>
	 */
	public static function sanitize_custom_data( array $data ): array {
		$out = array();
		foreach ( self::CUSTOM_KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] || '' === $data[ $key ] ) {
				continue;
			}
			$value = $data[ $key ];
			if ( 'content_ids' === $key ) {
				$out[ $key ] = array_values( array_slice( array_map( 'strval', array_filter( array_map( 'absint', (array) $value ) ) ), 0, 20 ) );
			} elseif ( 'contents' === $key ) {
				$rows = array();
				foreach ( array_slice( (array) $value, 0, 20 ) as $row ) {
					if ( is_array( $row ) && ! empty( $row['id'] ) ) {
						$rows[] = array( 'id' => (string) absint( $row['id'] ), 'quantity' => max( 1, absint( $row['quantity'] ?? 1 ) ) );
					}
				}
				$out[ $key ] = $rows;
			} elseif ( 'value' === $key ) {
				$out[ $key ] = round( (float) $value, 2 );
			} elseif ( 'num_items' === $key ) {
				$out[ $key ] = absint( $value );
			} elseif ( 'currency' === $key ) {
				$currency = strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', (string) $value ), 0, 3 ) );
				if ( 3 === strlen( $currency ) ) {
					$out[ $key ] = $currency;
				}
			} elseif ( is_scalar( $value ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 120 );
			}
		}
		return array_filter(
			$out,
			static function ( $v ) {
				return array() !== $v && '' !== $v;
			}
		);
	}

	/**
	 * Whether the current request comes from the native app (Bearer token or app REST auth).
	 */
	public static function is_app_request(): bool {
		if ( ! class_exists( 'LNKup_Mobile_Auth' ) || ! method_exists( 'LNKup_Mobile_Auth', 'get_bearer_token_from_request' ) ) {
			return false;
		}
		if ( null !== LNKup_Mobile_Auth::get_bearer_token_from_request() ) {
			return true;
		}
		$route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
		return 0 === strpos( $route, '/lnkup/v1/auth/' );
	}

	/**
	 * Page the action happened on: the REST caller's page, else the current URL.
	 */
	private static function current_source_url(): string {
		$url = '';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST || wp_doing_ajax() ) {
			$url = wp_get_referer() ? (string) wp_get_referer() : '';
		} elseif ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$url = home_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return self::clean_source_url( $url );
	}

	/**
	 * Same-site URL without query string (never leak tokens or emails in query args to Meta).
	 *
	 * @param string $url URL.
	 */
	public static function clean_source_url( string $url ): string {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_array( $home ) || strtolower( (string) $parts['host'] ) !== strtolower( (string) ( $home['host'] ?? '' ) ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
		return substr( esc_url_raw( $scheme . '://' . $parts['host'] . ( $parts['path'] ?? '/' ) ), 0, 500 );
	}

	/**
	 * Record a marketplace conversion. Returns the stored row, or null when it was a duplicate,
	 * unknown, or could not be stored. Never throws.
	 *
	 * Args: user_id, role, entity_type, entity_id, custom_data, event_id, event_time, dedupe_key,
	 * value_internal, context (ip/ua/fbp/fbc), source_url, capture_request (bool), browser_fired (bool).
	 *
	 * @param string              $name Event name.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>|null
	 */
	public static function record( string $name, array $args = array() ): ?array {
		try {
			return self::record_unsafe( $name, $args );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'LNKup conversion record failed (' . $name . '): ' . LNKup_Meta_Redactor::redact( $e->getMessage() ) );
			return null;
		}
	}

	/**
	 * @param string              $name Event name.
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>|null
	 */
	private static function record_unsafe( string $name, array $args ): ?array {
		global $wpdb;
		$def = self::get( $name );
		if ( null === $def ) {
			return null;
		}

		$user_id     = absint( $args['user_id'] ?? 0 );
		$entity_type = sanitize_key( (string) ( $args['entity_type'] ?? '' ) );
		$entity_id   = absint( $args['entity_id'] ?? 0 );
		$event_id    = isset( $args['event_id'] ) && self::is_uuid( (string) $args['event_id'] ) ? strtolower( (string) $args['event_id'] ) : self::uuid();
		$scope       = (string) $def['scope'];

		if ( in_array( $scope, array( 'user', 'item' ), true ) && $user_id < 1 ) {
			return null;
		}
		if ( in_array( $scope, array( 'entity', 'item' ), true ) && $entity_id < 1 ) {
			return null;
		}

		$dedupe = isset( $args['dedupe_key'] ) && '' !== (string) $args['dedupe_key']
			? substr( (string) $args['dedupe_key'], 0, 191 )
			: self::dedupe_key( $name, $scope, $user_id, $entity_type, $entity_id, $event_id );

		$in_request = array_key_exists( 'capture_request', $args )
			? ! empty( $args['capture_request'] )
			: ( ! wp_doing_cron() && ( 0 === $user_id || get_current_user_id() === $user_id ) );

		$context = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : ( $in_request ? LNKup_Meta_Cookies::request_context() : array() );
		$source  = isset( $args['source_url'] ) ? self::clean_source_url( (string) $args['source_url'] ) : ( $in_request ? self::current_source_url() : '' );

		$consent  = LNKup_Tracking_Consent::state( $user_id, $in_request );
		$platform = $in_request && self::is_app_request() ? 'app' : ( $in_request ? 'web' : 'server' );
		$is_test  = LNKup_Meta_Config::test_mode();

		$status = 'pending';
		$reason = '';
		if ( empty( $def['server'] ) ) {
			$status = 'skipped';
			$reason = 'internal_only';
		} elseif ( LNKup_Meta_Config::is_excluded_user( $user_id ) ) {
			$status = 'skipped';
			$reason = 'excluded_user';
		} elseif ( 'denied' === $consent ) {
			$status = 'skipped';
			$reason = 'consent_denied';
		} elseif ( 'app' === $platform && ! LNKup_Meta_Config::app_events_enabled() ) {
			$status = 'skipped';
			$reason = 'app_events_disabled';
		} else {
			$reason = LNKup_Meta_Config::capi_block_reason();
			if ( '' !== $reason ) {
				$status = 'skipped';
			}
		}

		$custom    = self::sanitize_custom_data( isset( $args['custom_data'] ) && is_array( $args['custom_data'] ) ? $args['custom_data'] : array() );
		$role      = sanitize_key( (string) ( $args['role'] ?? ( $def['role'] ?? '' ) ) );
		$presence  = LNKup_Meta_User_Data::presence( LNKup_Meta_User_Data::build( $user_id, $context ) );
		$attr      = class_exists( 'LNKup_Acquisition_Attribution' ) ? LNKup_Acquisition_Attribution::snapshot( $user_id ) : array();
		$now       = current_time( 'mysql', true );
		$evt_time  = isset( $args['event_time'] ) ? absint( $args['event_time'] ) : time();
		$browser   = ! empty( $args['browser_fired'] ) ? 'fired' : 'none';

		$row = array(
			'event_id'         => $event_id,
			'dedupe_key'       => $dedupe,
			'event_name'       => $name,
			'schema_version'   => self::SCHEMA_VERSION,
			'user_id'          => $user_id,
			'role'             => substr( $role, 0, 20 ),
			'entity_type'      => substr( $entity_type, 0, 32 ),
			'entity_id'        => $entity_id,
			'source_platform'  => $platform,
			'action_source'    => 'app' === $platform ? 'app' : 'website',
			'event_source_url' => $source,
			'event_time'       => $evt_time,
			'custom_data'      => wp_json_encode( $custom ),
			'request_context'  => 'pending' === $status && array() !== $context ? wp_json_encode( $context ) : null,
			'attribution_json' => array() !== $attr ? wp_json_encode( $attr ) : '',
			'match_presence'   => $presence,
			'consent_state'    => $consent,
			'value_internal'   => isset( $args['value_internal'] ) ? round( (float) $args['value_internal'], 2 ) : null,
			'status'           => $status,
			'browser_status'   => $browser,
			'last_error_code'  => $reason,
			'is_test'          => $is_test ? 1 : 0,
			'created_at'       => $now,
		);

		$columns = array_keys( $row );
		$values  = array();
		foreach ( $row as $value ) {
			if ( null === $value ) {
				$values[] = 'NULL';
			} elseif ( is_int( $value ) ) {
				$values[] = (string) $value;
			} elseif ( is_float( $value ) ) {
				$values[] = $wpdb->prepare( '%f', $value );
			} else {
				$values[] = $wpdb->prepare( '%s', $value );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$inserted = $wpdb->query( 'INSERT IGNORE INTO ' . LNKup_Meta_Tables::conversions() . ' (' . implode( ',', $columns ) . ') VALUES (' . implode( ',', $values ) . ')' );
		if ( 1 !== (int) $inserted ) {
			return null;
		}
		$row['id'] = (int) $wpdb->insert_id;

		$browser_ok = ! empty( $def['browser'] ) && 'none' === $browser && 'granted' === $consent && $user_id > 0
			&& 'app' !== $platform && LNKup_Meta_Config::pixel_active() && ! LNKup_Meta_Config::is_excluded_user( $user_id );
		if ( $browser_ok ) {
			self::queue_browser_event( $user_id, $name, $event_id, $custom, ! empty( $def['standard'] ) );
		}

		if ( ! empty( $def['mirror'] ) && class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			LNKup_Marketplace_Analytics::track( 'conv_' . strtolower( $name ), array( 'event_id' => $event_id, 'entity' => $entity_type . ':' . $entity_id, 'role' => $role ), $user_id, 'opportunity' === $entity_type ? $entity_id : 0 );
		}

		do_action( 'lnkup_conversion_recorded', $row, $def );

		if ( 'pending' === $status && class_exists( 'LNKup_Meta_Dispatcher' ) ) {
			LNKup_Meta_Dispatcher::schedule();
		}
		return $row;
	}

	/**
	 * Hand a server-recorded event to the person's next page render (same event_id for dedup).
	 *
	 * @param int                 $user_id  User.
	 * @param string              $name     Event name.
	 * @param string              $event_id Event ID.
	 * @param array<string,mixed> $custom   Custom data.
	 * @param bool                $standard Standard Meta event.
	 */
	private static function queue_browser_event( int $user_id, string $name, string $event_id, array $custom, bool $standard ): void {
		$queue = get_user_meta( $user_id, self::PENDING_BROWSER_META, true );
		$queue = is_array( $queue ) ? $queue : array();
		$queue[] = array(
			'name'     => $name,
			'eventId'  => $event_id,
			'data'     => $custom,
			'standard' => $standard,
			'at'       => time(),
		);
		update_user_meta( $user_id, self::PENDING_BROWSER_META, array_slice( $queue, -self::MAX_PENDING_BROWSER ) );
	}

	/**
	 * Take (and clear) a user's pending browser events. Events older than 2 days are dropped
	 * because Meta only deduplicates browser/server pairs within 48 hours.
	 *
	 * @param int $user_id User.
	 * @return array<int,array<string,mixed>>
	 */
	public static function consume_browser_events( int $user_id ): array {
		$queue = get_user_meta( $user_id, self::PENDING_BROWSER_META, true );
		if ( ! is_array( $queue ) || array() === $queue ) {
			return array();
		}
		delete_user_meta( $user_id, self::PENDING_BROWSER_META );
		$fresh = array_values(
			array_filter(
				$queue,
				static function ( $item ) {
					return is_array( $item ) && (int) ( $item['at'] ?? 0 ) > time() - 2 * DAY_IN_SECONDS;
				}
			)
		);
		$ids = array_map(
			static function ( $item ) {
				return (string) $item['eventId'];
			},
			$fresh
		);
		if ( array() !== $ids ) {
			global $wpdb;
			$in = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . LNKup_Meta_Tables::conversions() . " SET browser_status = 'flushed' WHERE event_id IN ({$in})", $ids ) );
		}
		return $fresh;
	}
}
