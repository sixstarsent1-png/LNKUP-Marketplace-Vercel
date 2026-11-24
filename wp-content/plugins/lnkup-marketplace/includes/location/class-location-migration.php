<?php
/**
 * Historical location repair: audit, dry-run, safe apply, review queue and rollback.
 *
 * Every proposal is a row in {prefix}lnkup_location_migrations with the original value, the
 * proposed canonical value, the confidence and the reason. Only deterministic corrections apply
 * automatically (formatting/state-name normalization, or an invalid state whose city and stored
 * coordinates agree). Everything else waits for an administrator. Nothing is ever deleted.
 *
 * Display/search location is corrected; contractual or financial market ownership
 * (_lnkup_referral_market, treasury market_slug, commissions) is never reassigned here.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Location_Migration {

	const ENTITIES = array( 'event', 'vendor_profile', 'organizer', 'market', 'external_event' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'lnkup_location_migrations';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(32) NOT NULL DEFAULT '',
			entity_type varchar(32) NOT NULL DEFAULT '',
			entity_id varchar(64) NOT NULL DEFAULT '',
			original longtext NULL,
			proposed longtext NULL,
			confidence varchar(20) NOT NULL DEFAULT '',
			reason varchar(64) NOT NULL DEFAULT '',
			related_count int(11) NOT NULL DEFAULT 0,
			needs_review tinyint(1) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'proposed',
			actor bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY entity (entity_type, entity_id),
			KEY status (status)
			) " . $wpdb->get_charset_collate() . ';'
		);
	}

	/**
	 * Audit records and produce one proposal per record whose location is not already canonical.
	 *
	 * @param array<string, mixed> $args entity (one of ENTITIES or ''), limit, offset.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( array $args = array() ): array {
		$entity = (string) ( $args['entity'] ?? '' );
		$out    = array();
		foreach ( self::ENTITIES as $type ) {
			if ( '' !== $entity && $entity !== $type ) {
				continue;
			}
			foreach ( self::records( $type, $args ) as $record ) {
				$proposal = self::propose( $type, $record );
				if ( null !== $proposal ) {
					$out[] = $proposal;
				}
			}
		}
		return $out;
	}

	/**
	 * Records of one entity type as [id, city, state, country, lat, lng, related].
	 *
	 * @param string               $type Entity type.
	 * @param array<string, mixed> $args limit/offset.
	 * @return array<int, array<string, mixed>>
	 */
	private static function records( string $type, array $args ): array {
		global $wpdb;
		$limit  = max( 1, min( 5000, (int) ( $args['limit'] ?? 5000 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$rows   = array();
		switch ( $type ) {
			case 'event':
			case 'vendor_profile':
				$post_type = 'event' === $type ? 'lnkup_event' : 'lnkup_vendor_profile';
				$ids       = get_posts(
					array(
						'post_type'      => $post_type,
						'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
						'posts_per_page' => $limit,
						'offset'         => $offset,
						'fields'         => 'ids',
						'orderby'        => 'ID',
						'order'          => 'ASC',
						'no_found_rows'  => true,
						'post__in'       => array_map( 'absint', (array) ( $args['ids'] ?? array() ) ),
					)
				);
				update_meta_cache( 'post', $ids );
				foreach ( $ids as $id ) {
					$rows[] = array(
						'id'      => (string) $id,
						'city'    => (string) get_post_meta( $id, '_lnkup_city', true ),
						'state'   => (string) get_post_meta( $id, '_lnkup_state', true ),
						'country' => (string) get_post_meta( $id, '_lnkup_country', true ),
						'lat'     => get_post_meta( $id, 'event' === $type ? '_lnkup_lat' : '_lnkup_geo_lat', true ),
						'lng'     => get_post_meta( $id, 'event' === $type ? '_lnkup_lng' : '_lnkup_geo_lng', true ),
						'title'   => lnkup_plain_title( $id ),
						'status_meta' => (string) get_post_meta( $id, '_lnkup_location_status', true ),
						'related' => 'event' === $type ? count( get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'meta_key' => '_lnkup_event_id', 'meta_value' => $id ) ) ) : 0, // phpcs:ignore WordPress.DB.SlowDBQuery
					);
				}
				break;
			case 'organizer':
				$users = get_users(
					array(
						'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
							'relation' => 'OR',
							array( 'key' => '_lnkup_organizer_city', 'compare' => 'EXISTS' ),
							array( 'key' => '_lnkup_organizer_state', 'compare' => 'EXISTS' ),
						),
						'number'     => $limit,
						'offset'     => $offset,
						'fields'     => array( 'ID', 'display_name' ),
					)
				);
				foreach ( $users as $user ) {
					$rows[] = array(
						'id'      => (string) $user->ID,
						'city'    => (string) get_user_meta( $user->ID, '_lnkup_organizer_city', true ),
						'state'   => (string) get_user_meta( $user->ID, '_lnkup_organizer_state', true ),
						'country' => '',
						'lat'     => '',
						'lng'     => '',
						'title'   => (string) $user->display_name,
						'related' => 0,
					);
				}
				break;
			case 'market':
				if ( class_exists( 'LNKup_Market_Pages' ) ) {
					foreach ( LNKup_Market_Pages::get_all_markets() as $slug => $market ) {
						$rows[] = array(
							'id'      => (string) $slug,
							'city'    => (string) ( $market['city'] ?? '' ),
							'state'   => (string) ( $market['state'] ?? '' ),
							'country' => '',
							'lat'     => '',
							'lng'     => '',
							'title'   => (string) ( $market['display_name'] ?? $slug ),
							'related' => 0,
						);
					}
				}
				break;
			case 'external_event':
				if ( class_exists( 'LNKup_External_Events_Tables' ) && method_exists( 'LNKup_External_Events_Tables', 'events_table' ) ) {
					$table = LNKup_External_Events_Tables::events_table();
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$found = $wpdb->get_results( $wpdb->prepare( "SELECT id, city, state, title FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
					foreach ( (array) $found as $row ) {
						$rows[] = array(
							'id'      => (string) $row['id'],
							'city'    => (string) $row['city'],
							'state'   => (string) $row['state'],
							'country' => '',
							'lat'     => '',
							'lng'     => '',
							'title'   => (string) $row['title'],
							'related' => 0,
						);
					}
				}
				break;
		}
		return $rows;
	}

	/**
	 * Proposal for one record, or null when it is already canonical (or has no location).
	 *
	 * @param string               $type   Entity type.
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>|null
	 */
	public static function propose( string $type, array $record ): ?array {
		$city  = trim( (string) $record['city'] );
		$state = trim( (string) $record['state'] );
		if ( '' === $city && '' === $state ) {
			return null;
		}
		$result = LNKup_Location_Service::resolve(
			$city,
			$state,
			(string) ( $record['country'] ?? '' ),
			array( 'lat' => $record['lat'] ?? null, 'lng' => $record['lng'] ?? null )
		);
		$status = (string) $result['status'];
		if ( LNKup_Location_Service::VERIFIED === $status ) {
			// Canonical already; events still need their status/market metadata recorded once.
			$needs_meta = 'event' === $type && '' === (string) ( $record['status_meta'] ?? '' );
			if ( ! $needs_meta ) {
				return null;
			}
		}
		$proposed = array();
		$review   = true;
		switch ( $status ) {
			case LNKup_Location_Service::VERIFIED:
			case LNKup_Location_Service::NORMALIZED:
				$proposed = array( 'city' => (string) $result['city'], 'state' => (string) $result['state_code'] );
				$review   = false;
				if ( 'city_unverified' === $result['reason'] && ! empty( $result['suggestions'] ) ) {
					// "Atlanta, NC": valid state, but the city is a major city elsewhere. Ask, never rewrite.
					return self::row(
						$type,
						$record,
						array( 'suggestions' => array_map(
							static function ( $s ) {
								return array( 'city' => $s['city'], 'state' => $s['state_code'] );
							},
							(array) $result['suggestions']
						) ),
						LNKup_Location_Service::SUGGESTED,
						'city_state_mismatch',
						true
					);
				}
				if ( 'city_unverified' === $result['reason'] ) {
					// Valid state, but the city is not in the reference list ("Pheonix", "Tuck", a street
					// address): an administrator confirms it; nothing is rewritten automatically.
					if ( $proposed['city'] === $city && $proposed['state'] === $state && '' !== (string) ( $record['status_meta'] ?? 'x' ) ) {
						return null;
					}
					return self::row( $type, $record, $proposed, $status, 'city_unverified', true );
				}
				break;
			case LNKup_Location_Service::SUGGESTED:
				$first    = $result['suggestions'][0] ?? null;
				$proposed = $first ? array( 'city' => (string) $first['city'], 'state' => (string) $first['state_code'] ) : array();
				// Only an invalid state corrected with agreeing stored coordinates is deterministic.
				$review = ! ( 'invalid_state' === $result['reason'] && ! empty( $result['evidence'] ) && $first );
				break;
			default:
				$proposed = array( 'suggestions' => array_map(
					static function ( $s ) {
						return array( 'city' => $s['city'], 'state' => $s['state_code'] );
					},
					(array) $result['suggestions']
				) );
		}
		$unchanged = isset( $proposed['city'] ) && $proposed['city'] === $city && $proposed['state'] === $state;
		$reason    = (string) ( $result['reason'] ?: ( $review ? 'needs_review' : ( $unchanged ? 'metadata_only' : 'formatting' ) ) );
		if ( ! empty( $result['evidence'] ) ) {
			$reason .= '+coordinates';
		}
		return self::row( $type, $record, $proposed, $status, $reason, $review );
	}

	/**
	 * @param string               $type     Entity type.
	 * @param array<string, mixed> $record   Record.
	 * @param array<string, mixed> $proposed Proposed value.
	 * @param string               $status   Confidence.
	 * @param string               $reason   Reason.
	 * @param bool                 $review   Needs admin review.
	 * @return array<string, mixed>
	 */
	private static function row( string $type, array $record, array $proposed, string $status, string $reason, bool $review ): array {
		return array(
			'entity_type'   => $type,
			'entity_id'     => (string) $record['id'],
			'title'         => (string) ( $record['title'] ?? '' ),
			'original'      => array( 'city' => (string) $record['city'], 'state' => (string) $record['state'] ),
			'proposed'      => $proposed,
			'confidence'    => $status,
			'reason'        => $reason,
			'related_count' => (int) ( $record['related'] ?? 0 ),
			'needs_review'  => $review,
		);
	}

	/**
	 * Store a run. Dry runs record proposals only; apply runs change safe rows and queue the rest.
	 *
	 * @param bool                 $apply Apply safe corrections.
	 * @param array<string, mixed> $args  scan() args.
	 * @param int                  $actor User ID (0 = CLI).
	 * @return array{run_id: string, total: int, applied: int, review: int, dry_run: bool}
	 */
	public static function run( bool $apply, array $args = array(), int $actor = 0 ): array {
		global $wpdb;
		self::create_table();
		$run_id    = ( $apply ? 'apply-' : 'dry-' ) . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 4, false, false );
		$proposals = self::scan( $args );
		$applied   = 0;
		$review    = 0;
		$now       = current_time( 'mysql' );
		foreach ( $proposals as $p ) {
			$status = $apply ? ( $p['needs_review'] ? 'pending_review' : 'applied' ) : 'dry_run';
			if ( $apply && ! $p['needs_review'] ) {
				if ( ! self::write( (string) $p['entity_type'], (string) $p['entity_id'], (array) $p['proposed'], (string) $p['confidence'] ) ) {
					$status = 'skipped';
				} else {
					++$applied;
				}
			}
			if ( $p['needs_review'] ) {
				++$review;
				if ( $apply && self::has_pending( (string) $p['entity_type'], (string) $p['entity_id'] ) ) {
					continue;
				}
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array(
					'run_id'        => $run_id,
					'entity_type'   => $p['entity_type'],
					'entity_id'     => $p['entity_id'],
					'original'      => wp_json_encode( $p['original'] + array( 'title' => $p['title'] ) ),
					'proposed'      => wp_json_encode( $p['proposed'] ),
					'confidence'    => $p['confidence'],
					'reason'        => substr( (string) $p['reason'], 0, 64 ),
					'related_count' => $p['related_count'],
					'needs_review'  => $p['needs_review'] ? 1 : 0,
					'status'        => $status,
					'actor'         => $actor,
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}
		if ( $apply && $applied ) {
			self::flush_caches();
		}
		if ( class_exists( 'LNKup_Audit_Log' ) && method_exists( 'LNKup_Audit_Log', 'log' ) ) {
			LNKup_Audit_Log::log( $apply ? 'location_migration_applied' : 'location_migration_dry_run', 'location', 0, array( 'run_id' => $run_id, 'total' => count( $proposals ), 'applied' => $applied, 'review' => $review ) );
		}
		return array( 'run_id' => $run_id, 'total' => count( $proposals ), 'applied' => $applied, 'review' => $review, 'dry_run' => ! $apply );
	}

	private static function has_pending( string $type, string $id ): bool {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE entity_type = %s AND entity_id = %s AND status = 'pending_review' LIMIT 1", $type, $id ) );
	}

	/**
	 * Write a canonical city/state to one record and refresh what depends on it.
	 *
	 * @param string               $type       Entity type.
	 * @param string               $id         Entity ID.
	 * @param array<string, mixed> $value      city, state.
	 * @param string               $confidence Status to record.
	 */
	public static function write( string $type, string $id, array $value, string $confidence ): bool {
		$city  = (string) ( $value['city'] ?? '' );
		$state = (string) ( $value['state'] ?? '' );
		if ( '' === $city ) {
			return false;
		}
		switch ( $type ) {
			case 'event':
				$event_id = (int) $id;
				if ( 'lnkup_event' !== get_post_type( $event_id ) ) {
					return false;
				}
				$resolved = LNKup_Location_Service::resolve( $city, $state );
				$resolved['status'] = LNKup_Location_Service::VERIFIED === $resolved['status'] || LNKup_Location_Service::NORMALIZED === $resolved['status'] ? $resolved['status'] : $confidence;
				$resolved['source'] = 'migration';
				LNKup_Location_Service::apply_to_event( $event_id, $resolved );
				self::reindex_event( $event_id );
				return true;
			case 'vendor_profile':
				$profile_id = (int) $id;
				if ( 'lnkup_vendor_profile' !== get_post_type( $profile_id ) ) {
					return false;
				}
				update_post_meta( $profile_id, '_lnkup_city', $city );
				update_post_meta( $profile_id, '_lnkup_state', $state );
				update_post_meta( $profile_id, '_lnkup_location_status', $confidence );
				if ( class_exists( 'LNKup_Match_Vendor_Index' ) ) {
					LNKup_Match_Vendor_Index::sync( $profile_id );
				}
				return true;
			case 'organizer':
				$user_id = (int) $id;
				if ( ! get_userdata( $user_id ) ) {
					return false;
				}
				update_user_meta( $user_id, '_lnkup_organizer_city', $city );
				update_user_meta( $user_id, '_lnkup_organizer_state', $state );
				return true;
			case 'market':
				if ( ! class_exists( 'LNKup_Market_Pages' ) ) {
					return false;
				}
				$markets = LNKup_Market_Pages::get_all_markets();
				if ( ! isset( $markets[ $id ] ) ) {
					return false;
				}
				$markets[ $id ]['city']  = $city;
				$markets[ $id ]['state'] = $state;
				LNKup_Market_Pages::save_markets( $markets );
				return true;
			case 'external_event':
				global $wpdb;
				if ( ! class_exists( 'LNKup_External_Events_Tables' ) || ! method_exists( 'LNKup_External_Events_Tables', 'events_table' ) ) {
					return false;
				}
				return false !== $wpdb->update( LNKup_External_Events_Tables::events_table(), array( 'city' => $city, 'state' => $state ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return false;
	}

	/** Resync marketplace index rows for an event's slots and re-geocode if coordinates were cleared. */
	private static function reindex_event( int $event_id ): void {
		if ( class_exists( 'LNKup_Geocoder' ) && '' === (string) get_post_meta( $event_id, '_lnkup_lat', true ) ) {
			LNKup_Geocoder::geocode_event( $event_id );
		}
		if ( ! class_exists( 'LNKup_Marketplace_Index' ) ) {
			return;
		}
		$opps = get_posts( array( 'post_type' => 'lnkup_opportunity', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true, 'meta_key' => '_lnkup_event_id', 'meta_value' => $event_id ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $opps as $opp_id ) {
			LNKup_Marketplace_Index::sync_opportunity( (int) $opp_id );
		}
	}

	/** Public discovery, market registry and index-policy caches reflect corrected cities. */
	public static function flush_caches(): void {
		foreach ( array( 'LNKup_Public_Discovery', 'LNKup_Market_Registry', 'LNKup_Index_Policy', 'LNKup_Org_Market_Need' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'flush' ) ) {
				$class::flush();
			}
		}
		if ( class_exists( 'LNKup_Match_Quality' ) && method_exists( 'LNKup_Match_Quality', 'flush' ) ) {
			LNKup_Match_Quality::flush();
		}
	}

	/**
	 * Get one migration row.
	 *
	 * @param int $row_id Row ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $row_id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Admin confirms a queued correction (optionally choosing a different suggestion).
	 *
	 * @param int    $row_id Row ID.
	 * @param string $city   Chosen city ('' = proposed).
	 * @param string $state  Chosen state ('' = proposed).
	 * @param int    $actor  Admin user ID.
	 * @return true|WP_Error
	 */
	public static function confirm( int $row_id, string $city, string $state, int $actor ) {
		$row = self::get_row( $row_id );
		if ( ! $row || ! in_array( $row['status'], array( 'pending_review', 'dry_run' ), true ) ) {
			return new WP_Error( 'lnkup_not_found', __( 'That correction is no longer pending.', 'lnkup-marketplace' ) );
		}
		$proposed = json_decode( (string) $row['proposed'], true ) ?: array();
		$city     = '' !== $city ? $city : (string) ( $proposed['city'] ?? '' );
		$state    = '' !== $state ? $state : (string) ( $proposed['state'] ?? '' );
		$check    = LNKup_Location_Service::validate_for_save( $city, $state, '', array( 'mode' => 'strict', 'confirmed' => true ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$value = array( 'city' => (string) $check['city'], 'state' => (string) $check['state_code'] );
		if ( ! self::write( (string) $row['entity_type'], (string) $row['entity_id'], $value, LNKup_Location_Service::VERIFIED ) ) {
			return new WP_Error( 'lnkup_write_failed', __( 'The record could not be updated.', 'lnkup-marketplace' ) );
		}
		self::set_status( $row_id, 'applied', $actor, $value );
		self::flush_caches();
		return true;
	}

	/**
	 * @param int $row_id Row ID.
	 * @param int $actor  Admin user ID.
	 */
	public static function reject( int $row_id, int $actor ): void {
		self::set_status( $row_id, 'rejected', $actor );
	}

	/**
	 * Undo every applied row of a run, but only where the record still holds the migrated value
	 * (later manual edits are never overwritten).
	 *
	 * @param string $run_id Run.
	 * @param int    $actor  User (0 = CLI).
	 * @return array{restored: int, skipped: int}
	 */
	public static function rollback( string $run_id, int $actor = 0 ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %s AND status = 'applied'", $run_id ), ARRAY_A );
		$restored = 0;
		$skipped  = 0;
		foreach ( (array) $rows as $row ) {
			$original = json_decode( (string) $row['original'], true ) ?: array();
			$proposed = json_decode( (string) $row['proposed'], true ) ?: array();
			$current  = self::current_value( (string) $row['entity_type'], (string) $row['entity_id'] );
			if ( null === $current || 0 !== strcasecmp( $current['city'], (string) ( $proposed['city'] ?? '' ) ) || 0 !== strcasecmp( $current['state'], (string) ( $proposed['state'] ?? '' ) ) ) {
				++$skipped;
				continue;
			}
			self::write_raw( (string) $row['entity_type'], (string) $row['entity_id'], (string) ( $original['city'] ?? '' ), (string) ( $original['state'] ?? '' ) );
			self::set_status( (int) $row['id'], 'rolled_back', $actor );
			++$restored;
		}
		if ( $restored ) {
			self::flush_caches();
		}
		if ( class_exists( 'LNKup_Audit_Log' ) && method_exists( 'LNKup_Audit_Log', 'log' ) ) {
			LNKup_Audit_Log::log( 'location_migration_rolled_back', 'location', 0, array( 'run_id' => $run_id, 'restored' => $restored, 'skipped' => $skipped ) );
		}
		return array( 'restored' => $restored, 'skipped' => $skipped );
	}

	/**
	 * @return array{city: string, state: string}|null
	 */
	private static function current_value( string $type, string $id ): ?array {
		global $wpdb;
		switch ( $type ) {
			case 'event':
			case 'vendor_profile':
				return array( 'city' => (string) get_post_meta( (int) $id, '_lnkup_city', true ), 'state' => (string) get_post_meta( (int) $id, '_lnkup_state', true ) );
			case 'organizer':
				return array( 'city' => (string) get_user_meta( (int) $id, '_lnkup_organizer_city', true ), 'state' => (string) get_user_meta( (int) $id, '_lnkup_organizer_state', true ) );
			case 'market':
				$markets = class_exists( 'LNKup_Market_Pages' ) ? LNKup_Market_Pages::get_all_markets() : array();
				return isset( $markets[ $id ] ) ? array( 'city' => (string) $markets[ $id ]['city'], 'state' => (string) $markets[ $id ]['state'] ) : null;
			case 'external_event':
				if ( ! class_exists( 'LNKup_External_Events_Tables' ) || ! method_exists( 'LNKup_External_Events_Tables', 'events_table' ) ) {
					return null;
				}
				$table = LNKup_External_Events_Tables::events_table();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT city, state FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
				return $row ? array( 'city' => (string) $row['city'], 'state' => (string) $row['state'] ) : null;
		}
		return null;
	}

	/** Restore an original value exactly as it was stored. */
	private static function write_raw( string $type, string $id, string $city, string $state ): void {
		global $wpdb;
		switch ( $type ) {
			case 'event':
			case 'vendor_profile':
				update_post_meta( (int) $id, '_lnkup_city', $city );
				update_post_meta( (int) $id, '_lnkup_state', $state );
				delete_post_meta( (int) $id, '_lnkup_location_status' );
				if ( 'event' === $type ) {
					self::reindex_event( (int) $id );
				}
				break;
			case 'organizer':
				update_user_meta( (int) $id, '_lnkup_organizer_city', $city );
				update_user_meta( (int) $id, '_lnkup_organizer_state', $state );
				break;
			case 'market':
				$markets = LNKup_Market_Pages::get_all_markets();
				if ( isset( $markets[ $id ] ) ) {
					$markets[ $id ]['city']  = $city;
					$markets[ $id ]['state'] = $state;
					LNKup_Market_Pages::save_markets( $markets );
				}
				break;
			case 'external_event':
				$wpdb->update( LNKup_External_Events_Tables::events_table(), array( 'city' => $city, 'state' => $state ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				break;
		}
	}

	/**
	 * @param int                       $row_id   Row ID.
	 * @param string                    $status   New status.
	 * @param int                       $actor    User ID.
	 * @param array<string, mixed>|null $proposed Final value (confirm).
	 */
	private static function set_status( int $row_id, string $status, int $actor, ?array $proposed = null ): void {
		global $wpdb;
		$data = array( 'status' => $status, 'actor' => $actor, 'updated_at' => current_time( 'mysql' ) );
		if ( null !== $proposed ) {
			$data['proposed'] = wp_json_encode( $proposed );
		}
		$wpdb->update( self::table(), $data, array( 'id' => $row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( class_exists( 'LNKup_Audit_Log' ) && method_exists( 'LNKup_Audit_Log', 'log' ) ) {
			LNKup_Audit_Log::log( 'location_correction_' . $status, 'location', $row_id, array( 'actor' => $actor ) );
		}
	}

	/**
	 * Counts for the Location Health page.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Rows for review/history tables.
	 *
	 * @param array<string, mixed> $filters status, entity_type, confidence, run_id, limit.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_rows( array $filters = array() ): array {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'status', 'entity_type', 'confidence', 'run_id' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "{$key} = %s";
				$params[] = (string) $filters[ $key ];
			}
		}
		$params[] = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d', $params ), ARRAY_A );
	}
}
