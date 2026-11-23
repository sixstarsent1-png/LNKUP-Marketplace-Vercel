<?php
/**
 * One list of accounts whose activity never counts in production reporting: IDs configured in
 * `analytics_excluded_user_ids`, users flagged `_lnkup_test_account`, and Communications test
 * recipients. Funnel, organizer-segment and partner reporting all read this list.
 *
 * @package LNKup_Marketplace
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Analytics_Exclusions {

	const SETTING   = 'analytics_excluded_user_ids';
	const USER_META = '_lnkup_test_account';

	/** @var int[]|null */
	private static $cache = null;

	/**
	 * Excluded user IDs.
	 *
	 * @return int[]
	 */
	public static function user_ids(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$settings = lnkup_get_settings();
		$ids      = array_map( 'absint', preg_split( '/[\s,]+/', (string) ( $settings[ self::SETTING ] ?? '' ) ) ?: array() );
		$flagged  = get_users(
			array(
				'meta_key'   => self::USER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
				'number'     => 2000,
			)
		);
		$ids = array_merge( $ids, array_map( 'intval', (array) $flagged ) );
		if ( class_exists( 'LNKup_Comms_Settings' ) && method_exists( 'LNKup_Comms_Settings', 'test_user_ids' ) ) {
			$ids = array_merge( $ids, LNKup_Comms_Settings::test_user_ids() );
		}
		self::$cache = array_values( array_unique( array_filter( $ids ) ) );
		return self::$cache;
	}

	public static function is_excluded( int $user_id ): bool {
		return $user_id > 0 && in_array( $user_id, self::user_ids(), true );
	}

	/** Forget the cached list (tests and settings saves). */
	public static function flush(): void {
		self::$cache = null;
	}
}
