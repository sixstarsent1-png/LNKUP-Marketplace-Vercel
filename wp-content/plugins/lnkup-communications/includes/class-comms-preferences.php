<?php
/**
 * Per-user notification preferences.
 *
 * Email: immediate | daily | weekly | off per category. Push: on | off per category.
 * Push choices are mirrored into the lnkup-marketplace push meta so core push
 * (for flows not yet claimed by this plugin) honours the same settings.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Preferences {

	const EMAIL_MODES = array( 'immediate', 'daily', 'weekly', 'off' );
	const META_QUIET  = 'lnkup_comms_quiet_hours';
	const META_TZ     = 'lnkup_comms_timezone';

	/**
	 * Default per category.
	 */
	public static function default_for( string $category ): array {
		$defaults = array(
			'opportunities' => array( 'email' => 'weekly', 'push' => 'on' ),
			'digests'       => array( 'email' => 'weekly', 'push' => 'off' ),
			'recruitment'   => array( 'email' => 'immediate', 'push' => 'off' ),
			'product_news'  => array( 'email' => 'immediate', 'push' => 'off' ),
		);
		return $defaults[ $category ] ?? array( 'email' => 'immediate', 'push' => 'on' );
	}

	/**
	 * Roles this user can receive messages for.
	 *
	 * @return string[]
	 */
	public static function user_roles( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$roles = array();
		$all   = (array) $user->roles;
		if ( array_intersect( $all, array( 'lnkup_vendor', 'administrator' ) ) ) {
			$roles[] = 'vendor';
		}
		if ( array_intersect( $all, array( 'lnkup_organizer', 'lnkup_admin', 'administrator' ) ) ) {
			$roles[] = 'organizer';
		}
		if ( self::partner_account_id( $user_id, $all ) ) {
			$roles[] = 'partner';
		}
		return $roles;
	}

	/**
	 * Active partner account (City Connector, affiliate or strategic contact) for a user, else 0.
	 * Suspended or closed accounts receive no partner messages.
	 *
	 * @param string[] $wp_roles WordPress roles when already loaded.
	 */
	public static function partner_account_id( int $user_id, array $wp_roles = array() ): int {
		if ( ! $wp_roles ) {
			$user     = get_userdata( $user_id );
			$wp_roles = $user ? (array) $user->roles : array();
		}
		if ( ! array_intersect( $wp_roles, array( 'lnkup_connector', 'lnkup_partner' ) ) || ! class_exists( 'LNKup_Connector' ) ) {
			return 0;
		}
		$account = LNKup_Connector::get_by_user_id( $user_id );
		return $account && 'active' === ( $account['status'] ?? '' ) ? (int) $account['id'] : 0;
	}

	/**
	 * Stored rows keyed category => channel => mode.
	 */
	private static function rows( int $user_id ): array {
		global $wpdb;
		$cached = wp_cache_get( 'prefs_' . $user_id, 'lnkup_comms' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$table = LNKup_Comms_Schema::table( 'preferences' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT category, channel, mode FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['category'] ][ (string) $row['channel'] ] = (string) $row['mode'];
		}
		wp_cache_set( 'prefs_' . $user_id, $out, 'lnkup_comms', HOUR_IN_SECONDS );
		return $out;
	}

	public static function email_mode( int $user_id, string $category ): string {
		$cat = LNKup_Comms_Registry::category( $category );
		if ( $cat && ! empty( $cat['locked'] ) ) {
			return 'immediate';
		}
		$rows = self::rows( $user_id );
		$mode = $rows[ $category ]['email'] ?? self::default_for( $category )['email'];
		return in_array( $mode, self::EMAIL_MODES, true ) ? $mode : 'immediate';
	}

	public static function push_on( int $user_id, string $category ): bool {
		$cat = LNKup_Comms_Registry::category( $category );
		if ( class_exists( 'LNKup_Push_Service' ) && ! LNKup_Push_Service::user_push_enabled( $user_id ) ) {
			return false;
		}
		if ( $cat && ! empty( $cat['locked'] ) ) {
			return true;
		}
		$rows = self::rows( $user_id );
		if ( isset( $rows[ $category ]['push'] ) ) {
			return 'on' === $rows[ $category ]['push'];
		}
		// No explicit choice yet: inherit the legacy core push category toggle.
		if ( $cat && class_exists( 'LNKup_Push_Service' ) ) {
			$legacy = LNKup_Push_Service::get_user_categories( $user_id );
			if ( isset( $legacy[ $cat['legacy'] ] ) && ! $legacy[ $cat['legacy'] ] ) {
				return false;
			}
		}
		return 'on' === self::default_for( $category )['push'];
	}

	/**
	 * Full preference document for REST clients.
	 */
	public static function get( int $user_id ): array {
		$roles = self::user_roles( $user_id );
		$out   = array();
		foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
			if ( ! array_intersect( $roles, (array) $cat['roles'] ) ) {
				continue;
			}
			$out[] = array(
				'key'         => $key,
				'label'       => (string) $cat['label'],
				'description' => (string) $cat['description'],
				'roles'       => array_values( array_intersect( $roles, (array) $cat['roles'] ) ),
				'promotional' => (bool) $cat['promotional'],
				'locked'      => (bool) $cat['locked'],
				'email'       => self::email_mode( $user_id, $key ),
				'push'        => self::push_on( $user_id, $key ) ? 'on' : 'off',
			);
		}
		return array(
			'categories'  => $out,
			'pushEnabled' => class_exists( 'LNKup_Push_Service' ) ? LNKup_Push_Service::user_push_enabled( $user_id ) : true,
			'quietHours'  => self::quiet_hours( $user_id ),
			'timezone'    => self::timezone( $user_id )->getName(),
			'emailModes'  => self::EMAIL_MODES,
			'roles'       => $roles,
		);
	}

	/**
	 * Apply a partial update. Locked categories and categories outside the user's
	 * roles are ignored.
	 *
	 * @param array $input { categories: [{key,email,push}], pushEnabled, quietHours:{enabled,start,end}, timezone }.
	 */
	public static function update( int $user_id, array $input ): array {
		global $wpdb;
		$roles = self::user_roles( $user_id );
		$table = LNKup_Comms_Schema::table( 'preferences' );
		$now   = LNKup_Comms_Schema::now();

		$legacy_updates = array();
		foreach ( (array) ( $input['categories'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $row['key'] ?? '' ) );
			$cat = LNKup_Comms_Registry::category( $key );
			if ( ! $cat || ! empty( $cat['locked'] ) || ! array_intersect( $roles, (array) $cat['roles'] ) ) {
				continue;
			}
			if ( isset( $row['email'] ) && in_array( (string) $row['email'], self::EMAIL_MODES, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->replace( $table, array( 'user_id' => $user_id, 'category' => $key, 'channel' => 'email', 'mode' => (string) $row['email'], 'updated_at' => $now ) );
			}
			if ( isset( $row['push'] ) ) {
				$push = ( true === $row['push'] || 'on' === $row['push'] ) ? 'on' : 'off';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->replace( $table, array( 'user_id' => $user_id, 'category' => $key, 'channel' => 'push', 'mode' => $push, 'updated_at' => $now ) );
				$legacy = (string) $cat['legacy'];
				// A legacy bucket stays on while any of our categories mapped to it is on.
				$legacy_updates[ $legacy ] = ( $legacy_updates[ $legacy ] ?? false ) || 'on' === $push;
			}
		}
		wp_cache_delete( 'prefs_' . $user_id, 'lnkup_comms' );

		if ( class_exists( 'LNKup_Push_Service' ) ) {
			if ( array_key_exists( 'pushEnabled', $input ) ) {
				LNKup_Push_Service::set_user_push_enabled( $user_id, (bool) $input['pushEnabled'] );
			}
			if ( $legacy_updates ) {
				$rows = self::rows( $user_id );
				foreach ( array_keys( $legacy_updates ) as $legacy ) {
					foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
						if ( $cat['legacy'] !== $legacy || ! array_intersect( $roles, (array) $cat['roles'] ) ) {
							continue;
						}
						$mode = $rows[ $key ]['push'] ?? self::default_for( $key )['push'];
						if ( ! empty( $cat['locked'] ) || 'on' === $mode ) {
							$legacy_updates[ $legacy ] = true;
						}
					}
				}
				LNKup_Comms_Listen_Account::$writing_legacy = true;
				LNKup_Push_Service::set_user_categories( $user_id, $legacy_updates );
				LNKup_Comms_Listen_Account::$writing_legacy = false;
			}
		}

		if ( isset( $input['quietHours'] ) && is_array( $input['quietHours'] ) ) {
			$q = $input['quietHours'];
			update_user_meta(
				$user_id,
				self::META_QUIET,
				array(
					'enabled' => ! empty( $q['enabled'] ),
					'start'   => self::clean_time( (string) ( $q['start'] ?? '22:00' ), '22:00' ),
					'end'     => self::clean_time( (string) ( $q['end'] ?? '08:00' ), '08:00' ),
				)
			);
		}
		if ( isset( $input['timezone'] ) ) {
			$tz = (string) $input['timezone'];
			if ( in_array( $tz, timezone_identifiers_list(), true ) ) {
				update_user_meta( $user_id, self::META_TZ, $tz );
			}
		}
		/**
		 * Fires after a user's communication preferences change (consent records listen here).
		 *
		 * @param int   $user_id User.
		 * @param array $input   Applied input.
		 */
		do_action( 'lnkup_comms_preferences_updated', $user_id, $input );
		return self::get( $user_id );
	}

	/**
	 * Mirror a legacy /push/preferences change into our rows (keeps both sides consistent).
	 *
	 * @param int   $user_id    User.
	 * @param array $categories Legacy bucket => bool.
	 */
	public static function sync_from_legacy( int $user_id, array $categories ): void {
		global $wpdb;
		$table = LNKup_Comms_Schema::table( 'preferences' );
		foreach ( LNKup_Comms_Registry::categories() as $key => $cat ) {
			if ( ! empty( $cat['locked'] ) || ! array_key_exists( (string) $cat['legacy'], $categories ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->replace( $table, array( 'user_id' => $user_id, 'category' => $key, 'channel' => 'push', 'mode' => $categories[ $cat['legacy'] ] ? 'on' : 'off', 'updated_at' => LNKup_Comms_Schema::now() ) );
		}
		wp_cache_delete( 'prefs_' . $user_id, 'lnkup_comms' );
	}

	private static function clean_time( string $value, string $fallback ): string {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : $fallback;
	}

	public static function quiet_hours( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_QUIET, true );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'enabled' => ! empty( $raw['enabled'] ),
			'start'   => self::clean_time( (string) ( $raw['start'] ?? '22:00' ), '22:00' ),
			'end'     => self::clean_time( (string) ( $raw['end'] ?? '08:00' ), '08:00' ),
		);
	}

	public static function timezone( int $user_id ): DateTimeZone {
		$tz = (string) get_user_meta( $user_id, self::META_TZ, true );
		if ( '' !== $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			return new DateTimeZone( $tz );
		}
		return wp_timezone();
	}

	/**
	 * Seconds until the recipient's quiet hours end; 0 when not in quiet hours.
	 */
	public static function quiet_seconds_remaining( int $user_id, ?int $now = null ): int {
		$q = self::quiet_hours( $user_id );
		if ( ! $q['enabled'] || $q['start'] === $q['end'] ) {
			return 0;
		}
		$tz    = self::timezone( $user_id );
		$local = ( new DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $tz );
		list( $sh, $sm ) = array_map( 'intval', explode( ':', $q['start'] ) );
		list( $eh, $em ) = array_map( 'intval', explode( ':', $q['end'] ) );
		$start = $local->setTime( $sh, $sm );
		$end   = $local->setTime( $eh, $em );
		if ( $start < $end ) {
			$inside = $local >= $start && $local < $end;
			$until  = $end;
		} else {
			// Overnight window, e.g. 22:00 → 08:00.
			$inside = $local >= $start || $local < $end;
			$until  = $local < $end ? $end : $end->modify( '+1 day' );
		}
		return $inside ? max( 0, $until->getTimestamp() - $local->getTimestamp() ) : 0;
	}

	public static function purge( int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( LNKup_Comms_Schema::table( 'preferences' ), array( 'user_id' => $user_id ) );
		delete_user_meta( $user_id, self::META_QUIET );
		delete_user_meta( $user_id, self::META_TZ );
		wp_cache_delete( 'prefs_' . $user_id, 'lnkup_comms' );
	}
}
