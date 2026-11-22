<?php
/**
 * Frequency caps. Only promotional messages are capped; critical, high and normal
 * (transactional / operational) messages are never blocked by a cap.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Frequency {

	/**
	 * @return string '' when allowed, otherwise a suppression reason.
	 */
	public static function check( int $user_id, string $channel, string $priority ): string {
		if ( 'promotional' !== $priority ) {
			return '';
		}
		if ( 'push' === $channel ) {
			$cap    = LNKup_Comms_Settings::int( 'cap_promo_push_daily' );
			$window = DAY_IN_SECONDS;
		} elseif ( 'email' === $channel ) {
			$cap    = LNKup_Comms_Settings::int( 'cap_promo_email_weekly' );
			$window = WEEK_IN_SECONDS;
		} else {
			return '';
		}
		if ( $cap <= 0 ) {
			return 'frequency_cap';
		}
		return self::sent_count( $user_id, $channel, $window ) >= $cap ? 'frequency_cap' : '';
	}

	/**
	 * Promotional messages actually handed to a provider in the window (one per message, not per device).
	 */
	public static function sent_count( int $user_id, string $channel, int $window ): int {
		global $wpdb;
		$messages   = LNKup_Comms_Schema::table( 'messages' );
		$deliveries = LNKup_Comms_Schema::table( 'deliveries' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT m.id) FROM {$messages} m INNER JOIN {$deliveries} d ON d.message_id = m.id
				WHERE m.recipient_user_id = %d AND m.priority = 'promotional' AND d.channel = %s
				AND d.status IN ('queued','sent','provider_accepted','delivered') AND d.created_at >= %s",
				$user_id,
				$channel,
				LNKup_Comms_Schema::now( -$window )
			)
		);
	}
}
