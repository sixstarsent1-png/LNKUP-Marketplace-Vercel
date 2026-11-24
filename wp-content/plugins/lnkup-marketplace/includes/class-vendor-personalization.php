<?php
/**
 * Vendor favorites, saved searches, recently viewed.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Vendor_Personalization {

	const MAX_RECENT   = 50;
	const MAX_HISTORY  = 20;

	/**
	 * Get favorites structure.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_favorites( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		$defaults = array(
			'events'        => array(),
			'organizers'    => array(),
			'opportunities' => array(),
		);
		$data = get_user_meta( $user_id, 'lnkup_favorites', true );
		return wp_parse_args( is_array( $data ) ? $data : array(), $defaults );
	}

	/**
	 * Toggle favorite.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    events|organizers|opportunities.
	 * @param int    $id      Entity ID.
	 */
	public static function toggle_favorite( $user_id, $type, $id ) {
		$favs = self::get_favorites( $user_id );
		if ( ! isset( $favs[ $type ] ) ) {
			$favs[ $type ] = array();
		}
		$id = (int) $id;
		$key = array_search( $id, $favs[ $type ], true );
		if ( false !== $key ) {
			unset( $favs[ $type ][ $key ] );
			$favs[ $type ] = array_values( $favs[ $type ] );
			$added = false;
		} else {
			array_unshift( $favs[ $type ], $id );
			$favs[ $type ] = array_slice( array_unique( $favs[ $type ] ), 0, 100 );
			$added = true;
		}
		update_user_meta( $user_id, 'lnkup_favorites', $favs );
		do_action( 'lnkup_favorite_toggled', (int) $user_id, (string) $type, $id, $added );
		return $added;
	}

	/**
	 * Check if favorited.
	 */
	public static function is_favorite( $user_id, $type, $id ) {
		$favs = self::get_favorites( $user_id );
		return in_array( (int) $id, (array) ( $favs[ $type ] ?? array() ), true );
	}

	/**
	 * Get saved searches.
	 *
	 * @param int $user_id User ID.
	 */
	public static function get_saved_searches( $user_id = 0 ) {
		if ( class_exists( 'LNKup_Saved_Searches' ) ) {
			return LNKup_Saved_Searches::get_for_user( $user_id );
		}
		$user_id = $user_id ?: get_current_user_id();
		$data    = get_user_meta( $user_id, 'lnkup_saved_searches', true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save a search.
	 *
	 * @param int    $user_id User ID.
	 * @param string $name    Label.
	 * @param array  $filters Filter snapshot.
	 * @param array  $args    Optional alert settings.
	 */
	public static function save_search( $user_id, $name, $filters, $args = array() ) {
		if ( class_exists( 'LNKup_Saved_Searches' ) ) {
			return LNKup_Saved_Searches::create( $user_id, $name, $filters, $args );
		}
		$searches   = self::get_saved_searches( $user_id );
		$searches[] = array(
			'id'      => wp_generate_uuid4(),
			'name'    => sanitize_text_field( $name ),
			'filters' => $filters,
			'created' => current_time( 'mysql' ),
		);
		update_user_meta( $user_id, 'lnkup_saved_searches', $searches );
		return end( $searches );
	}

	/**
	 * Delete saved search.
	 */
	public static function delete_saved_search( $user_id, $search_id ) {
		if ( class_exists( 'LNKup_Saved_Searches' ) ) {
			return LNKup_Saved_Searches::delete( $user_id, $search_id );
		}
		$searches = self::get_saved_searches( $user_id );
		$searches = array_values( array_filter( $searches, function ( $s ) use ( $search_id ) {
			return ( $s['id'] ?? '' ) !== $search_id;
		} ) );
		update_user_meta( $user_id, 'lnkup_saved_searches', $searches );
	}

	/**
	 * Record recently viewed item.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Type.
	 * @param int    $id      ID.
	 */
	public static function add_recently_viewed( $user_id, $type, $id ) {
		$recent = self::get_recently_viewed( $user_id );
		$entry  = array(
			'type' => sanitize_key( $type ),
			'id'   => (int) $id,
			'at'   => current_time( 'mysql' ),
		);
		$recent = array_values( array_filter( $recent, function ( $r ) use ( $entry ) {
			return ! ( $r['type'] === $entry['type'] && (int) $r['id'] === $entry['id'] );
		} ) );
		array_unshift( $recent, $entry );
		$recent = array_slice( $recent, 0, self::MAX_RECENT );
		if ( $user_id ) {
			update_user_meta( $user_id, 'lnkup_recently_viewed', $recent );
		}
		return $recent;
	}

	/**
	 * Get recently viewed.
	 */
	public static function get_recently_viewed( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$data = get_user_meta( $user_id, 'lnkup_recently_viewed', true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Add search to history.
	 *
	 * @param int    $user_id User ID.
	 * @param string $query   Query string.
	 * @param array  $filters Filter snapshot.
	 */
	public static function add_search_history( $user_id, $query, $filters = array() ) {
		$query = trim( (string) $query );
		if ( '' === $query && empty( $filters ) ) {
			return;
		}
		$history = self::get_search_history_entries( $user_id );
		$entry   = array(
			'q'       => sanitize_text_field( $query ),
			'filters' => is_array( $filters ) ? $filters : array(),
			'at'      => current_time( 'mysql' ),
		);
		$history = array_values(
			array_filter(
				$history,
				function ( $h ) use ( $entry ) {
					$hq = is_array( $h ) ? (string) ( $h['q'] ?? '' ) : (string) $h;
					return $hq !== $entry['q'];
				}
			)
		);
		array_unshift( $history, $entry );
		update_user_meta( $user_id, 'lnkup_search_history', array_slice( $history, 0, self::MAX_HISTORY ) );
	}

	/**
	 * Raw search history entries (arrays).
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{q:string,filters?:array,at?:string}|string>
	 */
	private static function get_search_history_entries( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$data = get_user_meta( $user_id, 'lnkup_search_history', true );
		if ( ! is_array( $data ) ) {
			return array();
		}
		$out = array();
		foreach ( $data as $h ) {
			if ( is_array( $h ) && isset( $h['q'] ) ) {
				$out[] = $h;
			} elseif ( is_string( $h ) && '' !== trim( $h ) ) {
				$out[] = array(
					'q'       => sanitize_text_field( $h ),
					'filters' => array(),
					'at'      => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Get search history queries (string labels for suggestions UI).
	 *
	 * @param int $user_id User ID.
	 * @return string[]
	 */
	public static function get_search_history( $user_id = 0 ) {
		$entries = self::get_search_history_entries( $user_id );
		return array_values(
			array_filter(
				array_map(
					function ( $h ) {
						return is_array( $h ) ? (string) ( $h['q'] ?? '' ) : (string) $h;
					},
					$entries
				)
			)
		);
	}

	/**
	 * Resolve recently viewed to opportunity items.
	 */
	public static function get_recent_opportunities( $user_id = 0, $limit = 8 ) {
		$recent = self::get_recently_viewed( $user_id );
		$items  = array();
		foreach ( $recent as $r ) {
			if ( 'opportunity' !== $r['type'] && 'event' !== $r['type'] ) {
				continue;
			}
			$id = (int) $r['id'];
			if ( 'event' === $r['type'] ) {
				$opps = get_posts( array(
					'post_type' => 'lnkup_opportunity', 'posts_per_page' => 1,
					'meta_key' => '_lnkup_event_id', 'meta_value' => $id, 'fields' => 'ids',
				) );
				$id = $opps[0] ?? 0;
			}
			if ( ! $id ) {
				continue;
			}
			$row = LNKup_Marketplace_Index::get_row( $id );
			if ( $row ) {
				$items[] = lnkup_format_index_row( $row );
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	/**
	 * Get favorite opportunities as formatted items.
	 */
	public static function get_favorite_opportunities( $user_id = 0, $limit = 12 ) {
		$favs  = self::get_favorites( $user_id );
		$items = array();
		foreach ( array_slice( (array) $favs['opportunities'], 0, $limit ) as $id ) {
			$row = LNKup_Marketplace_Index::get_row( (int) $id );
			if ( $row ) {
				$items[] = lnkup_format_index_row( $row );
			}
		}
		return $items;
	}
}
