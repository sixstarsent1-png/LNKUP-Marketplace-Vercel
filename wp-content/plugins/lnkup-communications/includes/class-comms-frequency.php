<?php
/**
 * Frequency caps and cross-channel cooldown. Only promotional messages are capped;
 * critical, high and normal (transactional / operational) messages are never blocked.
 *
 * Every limit is a setting: 0 disables a per-category cap or the cooldown, while a
 * global cap of 0 blocks promotional sends on that channel entirely.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Frequency {

	const DELIVERED = "'queued','sent','provider_accepted','delivered'";

	/**
	 * @param int    $user_id    Recipient.
	 * @param string $channel    email|push.
	 * @param string $priority   Message priority.
	 * @param string $category   Message category (per-category caps).
	 * @param int    $message_id Message being sent (excluded from the cooldown look-back).
	 * @return string '' when allowed, otherwise a suppression reason.
	 */
	public static function check( int $user_id, string $channel, string $priority, string $category = '', int $message_id = 0 ): string {
		if ( 'promotional' !== $priority || ! in_array( $channel, array( 'email', 'push' ), true ) ) {
			return '';
		}
		$limits = 'push' === $channel
			? array(
				array( LNKup_Comms_Settings::int( 'cap_promo_push_daily' ), DAY_IN_SECONDS ),
				array( LNKup_Comms_Settings::int( 'cap_promo_push_weekly' ), WEEK_IN_SECONDS ),
			)
			: array( array( LNKup_Comms_Settings::int( 'cap_promo_email_weekly' ), WEEK_IN_SECONDS ) );
		foreach ( $limits as $limit ) {
			if ( $limit[0] <= 0 || self::sent_count( $user_id, $channel, (int) $limit[1] ) >= $limit[0] ) {
				return 'frequency_cap';
			}
		}
		$category_cap = LNKup_Comms_Settings::int( 'push' === $channel ? 'cap_category_push_weekly' : 'cap_category_email_weekly' );
		if ( $category_cap > 0 && '' !== $category && self::sent_count( $user_id, $channel, WEEK_IN_SECONDS, $category ) >= $category_cap ) {
			return 'frequency_cap_category';
		}
		if ( self::in_cooldown( $user_id, $message_id ) ) {
			return 'cooldown_cross_channel';
		}
		return '';
	}

	/**
	 * Promotional messages actually handed to a provider in the window (one per message, not per device).
	 */
	public static function sent_count( int $user_id, string $channel, int $window, string $category = '' ): int {
		global $wpdb;
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		$sql        = "SELECT COUNT(DISTINCT m.id) FROM {$messages} m INNER JOIN {$deliveries} d ON d.message_id = m.id
			WHERE m.recipient_user_id = %d AND m.priority = 'promotional' AND d.channel = %s
			AND d.status IN (" . self::DELIVERED . ') AND d.created_at >= %s';
		$args       = array( $user_id, $channel, LNKup_Comms_Schema::now( -$window ) );
		if ( '' !== $category ) {
			$sql   .= ' AND m.category = %s';
			$args[] = $category;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * A different promotional, high or critical message reached this user by email or push
	 * within the cooldown. The lower-value promotional message then stays in the inbox only.
	 */
	public static function in_cooldown( int $user_id, int $message_id = 0 ): bool {
		global $wpdb;
		$hours = LNKup_Comms_Settings::int( 'cooldown_hours' );
		if ( $hours <= 0 ) {
			return false;
		}
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$messages} m INNER JOIN {$deliveries} d ON d.message_id = m.id
				WHERE m.recipient_user_id = %d AND m.id <> %d AND m.priority IN ('critical','high','promotional')
				AND d.channel IN ('email','push') AND d.status IN (" . self::DELIVERED . ') AND d.created_at >= %s LIMIT 1',
				$user_id,
				$message_id,
				LNKup_Comms_Schema::now( -$hours * HOUR_IN_SECONDS )
			)
		);
	}
}
