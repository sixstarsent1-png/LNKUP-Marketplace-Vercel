<?php
/**
 * External event sources: the official listings (ticketing pages, websites) linked to an LNKup event.
 *
 * One row per organizer + canonical URL. A public link is never proof of ownership: when the same
 * listing is already linked to another organizer's event, publishing is blocked until resolved
 * through the existing claim / support flow.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Event_Sources {

	const TRACKING_PARAMS = array( 'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', 'aff', 'affiliate', 'ref', 'ref_id', 'referrer', 'source', '_eboga', '_ebp', 'igshid', 'si', 'trk', 'cmp', 'discount', 'promo' );

	/**
	 * Canonical form for duplicate detection: https, lowercase host without www, no fragment,
	 * no tracking/referral parameters, no trailing slash.
	 */
	public static function canonical_url( string $url ): string {
		$p = wp_parse_url( trim( $url ) );
		if ( ! is_array( $p ) || empty( $p['host'] ) ) {
			return '';
		}
		$host  = preg_replace( '/^(www\.|m\.)/', '', strtolower( (string) $p['host'] ) );
		$path  = rtrim( (string) ( $p['path'] ?? '' ), '/' );
		$query = array();
		if ( ! empty( $p['query'] ) ) {
			wp_parse_str( (string) $p['query'], $query );
			foreach ( array_keys( $query ) as $k ) {
				if ( 0 === strpos( (string) $k, 'utm_' ) || in_array( strtolower( (string) $k ), self::TRACKING_PARAMS, true ) ) {
					unset( $query[ $k ] );
				}
			}
			ksort( $query );
		}
		return 'https://' . $host . $path . ( $query ? '?' . http_build_query( $query ) : '' );
	}

	public static function url_hash( string $url ): string {
		$canonical = self::canonical_url( $url );
		return '' === $canonical ? '' : sha1( $canonical );
	}

	/**
	 * Provider event ID from a URL when the provider encodes one publicly.
	 */
	public static function external_id( string $url, string $provider ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		switch ( $provider ) {
			case 'eventbrite':
				return preg_match( '/-(\d{6,})\/?$/', $path, $m ) || preg_match( '/\/e\/(\d{6,})/', $path, $m ) ? $m[1] : '';
			case 'posh':
				return preg_match( '#/e/([a-z0-9-]+)#i', $path, $m ) ? strtolower( $m[1] ) : '';
			case 'luma':
				return preg_match( '#^/([a-z0-9-]{4,})/?$#i', $path, $m ) ? strtolower( $m[1] ) : '';
			default:
				return '';
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_event( int $event_id ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::sources() . ' WHERE event_id = %d ORDER BY is_primary DESC, id ASC', $event_id ), ARRAY_A );
		return array_map( array( __CLASS__, 'format' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		if ( ! self::ready() ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . LNKup_Event_Import_Tables::sources() . ' WHERE id = %d', $id ), ARRAY_A );
		return $row ? self::format( $row ) : null;
	}

	/**
	 * Every live (non-trashed) source row matching the URL or the provider's event ID.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function matches( string $url, string $provider = '', string $external_id = '' ): array {
		global $wpdb;
		if ( ! self::ready() ) {
			return array();
		}
		$table = LNKup_Event_Import_Tables::sources();
		$hash  = self::url_hash( $url );
		if ( '' !== $provider && '' !== $external_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id > 0 AND ( url_hash = %s OR ( provider = %s AND external_event_id = %s ) ) LIMIT 20", $hash, $provider, $external_id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE event_id > 0 AND url_hash = %s LIMIT 20", $hash ), ARRAY_A );
		}
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$status = get_post_status( (int) $row['event_id'] );
			if ( $status && 'trash' !== $status ) {
				$out[] = self::format( $row );
			}
		}
		return $out;
	}

	/**
	 * How a listing URL relates to this organizer.
	 *
	 * @return array{state:string,event_id:int,message:string,external_id:int}
	 *   state: none | own (already linked to one of my events) | other (another organizer's event)
	 *          | claimed_other | unclaimed_listing | own_listing
	 */
	public static function relation( string $url, int $organizer_id, int $ignore_event = 0 ): array {
		$provider = class_exists( 'LNKup_Import_Provider_Registry' ) ? (string) LNKup_Import_Provider_Registry::detect( $url )['key'] : '';
		$ext_id   = self::external_id( $url, $provider );
		foreach ( self::matches( $url, $provider, $ext_id ) as $row ) {
			if ( $ignore_event && (int) $row['event_id'] === $ignore_event ) {
				continue;
			}
			$owner = (int) get_post_meta( (int) $row['event_id'], '_lnkup_organizer_user_id', true );
			if ( $owner === $organizer_id ) {
				return array( 'state' => 'own', 'event_id' => (int) $row['event_id'], 'message' => __( 'This listing is already linked to one of your LNKup events.', 'lnkup-marketplace' ), 'external_id' => 0 );
			}
			return array( 'state' => 'other', 'event_id' => 0, 'message' => __( 'This listing is already connected to another organizer\'s LNKup event. If you run this event, contact support to verify ownership.', 'lnkup-marketplace' ), 'external_id' => 0 );
		}
		if ( class_exists( 'LNKup_External_Events' ) ) {
			$dup = LNKup_External_Events::find_duplicate( self::canonical_url( $url ), $ext_id, $provider ?: 'other' );
			if ( ! $dup ) {
				$dup = LNKup_External_Events::find_duplicate( $url, $ext_id, $provider ?: 'other' );
			}
			if ( is_array( $dup ) && ! empty( $dup['id'] ) ) {
				$row     = LNKup_External_Events::get_row( (int) $dup['id'] );
				$claimed = (int) ( $row['claimed_event_id'] ?? 0 );
				if ( $row && 'claimed' === ( $row['claim_status'] ?? '' ) && $claimed && $claimed !== $ignore_event ) {
					$owner = (int) get_post_meta( $claimed, '_lnkup_organizer_user_id', true );
					return $owner === $organizer_id
						? array( 'state' => 'own_listing', 'event_id' => $claimed, 'message' => __( 'You already claimed this event on LNKup.', 'lnkup-marketplace' ), 'external_id' => (int) $dup['id'] )
						: array( 'state' => 'claimed_other', 'event_id' => 0, 'message' => __( 'Another organizer has already claimed this event on LNKup. If you run this event, contact support to verify ownership.', 'lnkup-marketplace' ), 'external_id' => (int) $dup['id'] );
				}
				if ( $row && 'unclaimed' === ( $row['claim_status'] ?? '' ) ) {
					return array( 'state' => 'unclaimed_listing', 'event_id' => 0, 'message' => __( 'Vendors may already be following this event on LNKup. You can publish your own listing; to take over existing vendor interest, use the claim link from the listing page.', 'lnkup-marketplace' ), 'external_id' => (int) $dup['id'] );
				}
			}
		}
		return array( 'state' => 'none', 'event_id' => 0, 'message' => '', 'external_id' => 0 );
	}

	/**
	 * Blocking ownership message for a draft state, recomputed server-side (never trusted from the client).
	 *
	 * @param array<string,mixed> $state Normalized builder state.
	 */
	public static function conflict_for_state( array $state, int $organizer_id, int $ignore_event = 0 ): string {
		$urls = wp_list_pluck( (array) $state['sources'], 'url' );
		if ( '' !== (string) ( $state['import']['original_url'] ?? '' ) ) {
			$urls[] = (string) $state['import']['original_url'];
		}
		foreach ( array_unique( $urls ) as $url ) {
			$rel = self::relation( (string) $url, $organizer_id, $ignore_event );
			if ( in_array( $rel['state'], array( 'other', 'claimed_other' ), true ) ) {
				return $rel['message'];
			}
		}
		return '';
	}

	/**
	 * Persist the draft's sources for the published event (replaces the event's previous set).
	 *
	 * @param array<string,mixed> $state Normalized builder state.
	 */
	public static function attach_from_state( int $event_id, int $draft_id, array $state, int $user_id ): void {
		global $wpdb;
		if ( ! self::ready() || $event_id <= 0 ) {
			return;
		}
		$table     = LNKup_Event_Import_Tables::sources();
		$organizer = (int) get_post_meta( $event_id, '_lnkup_organizer_user_id', true ) ?: $user_id;
		$now       = current_time( 'mysql', true );
		$keep      = array();
		$prov      = wp_json_encode( (array) ( $state['provenance'] ?? array() ) );
		foreach ( (array) $state['sources'] as $s ) {
			$hash = self::url_hash( (string) $s['url'] );
			if ( '' === $hash ) {
				continue;
			}
			$rel = self::relation( (string) $s['url'], $organizer, $event_id );
			if ( in_array( $rel['state'], array( 'other', 'claimed_other' ), true ) ) {
				continue;
			}
			$provider = $s['provider'] ?: ( class_exists( 'LNKup_Import_Provider_Registry' ) ? (string) LNKup_Import_Provider_Registry::detect( (string) $s['url'] )['key'] : '' );
			$imported = (string) ( $state['import']['original_url'] ?? '' ) !== '' && self::url_hash( (string) $state['import']['original_url'] ) === $hash;
			$row      = array(
				'event_id'          => $event_id,
				'draft_id'          => $draft_id,
				'organizer_id'      => $organizer,
				'provider'          => sanitize_key( $provider ),
				'external_event_id' => $imported && '' !== $state['import']['external_event_id'] ? (string) $state['import']['external_event_id'] : self::external_id( (string) $s['url'], $provider ),
				'url'               => esc_url_raw( (string) $s['url'] ),
				'url_hash'          => $hash,
				'is_primary'        => $s['primary'] ? 1 : 0,
				'mode'              => $imported ? 'import' : ( 'account' === $s['mode'] ? 'account' : 'link' ),
				'ownership_state'   => 'attested',
				'updated_at'        => $now,
			);
			if ( $imported ) {
				$row['provenance']   = $prov;
				$row['last_sync_at'] = $now;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE organizer_id = %d AND url_hash = %s", $organizer, $hash ) );
			if ( $existing ) {
				$wpdb->update( $table, $row, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$keep[] = $existing;
			} else {
				$row['created_at'] = $now;
				$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$keep[] = (int) $wpdb->insert_id;
			}
		}
		$keep = array_filter( array_map( 'intval', $keep ) );
		if ( $keep ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE event_id = %d AND id NOT IN (" . implode( ',', $keep ) . ')', $event_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE event_id = %d", $event_id ) );
		}
		do_action( 'lnkup_event_sources_attached', $event_id, $keep );
	}

	/**
	 * @param array<string,mixed> $fields Columns to update (sync bookkeeping only).
	 */
	public static function update( int $id, array $fields ): void {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'availability', 'last_sync_at', 'last_attempt_at', 'source_version', 'pending_changes', 'provenance' ) ) );
		if ( ! $allowed || ! self::ready() ) {
			return;
		}
		$allowed['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( LNKup_Event_Import_Tables::sources(), $allowed, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * @param array<string,mixed> $row DB row.
	 * @return array<string,mixed>
	 */
	public static function format( array $row ): array {
		$provider = (string) $row['provider'];
		$label    = class_exists( 'LNKup_Import_Provider_Registry' ) ? LNKup_Import_Provider_Registry::label_for_url( (string) $row['url'] ) : $provider;
		return array(
			'id'                => (int) $row['id'],
			'event_id'          => (int) $row['event_id'],
			'organizer_id'      => (int) $row['organizer_id'],
			'provider'          => $provider,
			'label'             => $label,
			'external_event_id' => (string) $row['external_event_id'],
			'url'               => (string) $row['url'],
			'is_primary'        => (bool) (int) $row['is_primary'],
			'mode'              => (string) $row['mode'],
			'ownership_state'   => (string) $row['ownership_state'],
			'availability'      => (string) $row['availability'],
			'last_sync_at'      => $row['last_sync_at'] ? mysql_to_rfc3339( (string) $row['last_sync_at'] ) : null,
			'pending_changes'   => $row['pending_changes'] ? json_decode( (string) $row['pending_changes'], true ) : null,
			'provenance'        => $row['provenance'] ? json_decode( (string) $row['provenance'], true ) : array(),
		);
	}

	private static function ready(): bool {
		return class_exists( 'LNKup_Event_Import_Tables' ) && get_option( LNKup_Event_Import_Tables::SCHEMA_OPTION ) === LNKup_Event_Import_Tables::SCHEMA_VERSION;
	}
}
