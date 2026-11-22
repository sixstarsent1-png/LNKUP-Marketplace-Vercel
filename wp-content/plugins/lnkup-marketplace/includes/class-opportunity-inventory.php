<?php
/**
 * Opportunity capacity derived from canonical application statuses.
 *
 * `_lnkup_spots_remaining` is a cache recomputed from the applications that hold a
 * spot, so it cannot drift from repeated increments/decrements. Approvals check
 * capacity under a per-opportunity lock so concurrent approvals cannot oversell.
 *
 * @package LNKup_Marketplace
 */

defined( 'ABSPATH' ) || exit;

class LNKup_Opportunity_Inventory {

	/** Application statuses that occupy a spot. */
	const HOLD_STATUSES = array( 'approved', 'payment_required', 'paid', 'completed' );

	const BACKFILL_OPTION = 'lnkup_inventory_backfill_v1';

	public static function init() {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'on_status_changed' ), 5, 3 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'on_application_submitted' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill' ) );
	}

	/**
	 * Applications holding a spot on an opportunity.
	 *
	 * @param int $opp_id         Opportunity ID.
	 * @param int $exclude_app_id Application to leave out of the count.
	 */
	public static function holds( $opp_id, $exclude_app_id = 0 ) {
		global $wpdb;
		$statuses     = self::HOLD_STATUSES;
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id' AND o.meta_value = %s
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status' AND s.meta_value IN ({$placeholders})
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft') AND p.ID <> %d",
				array_merge( array( (string) absint( $opp_id ) ), $statuses, array( absint( $exclude_app_id ) ) )
			)
		);
		return (int) $count;
	}

	/**
	 * Configured capacity; 0 means no limit was set.
	 *
	 * @param int $opp_id Opportunity ID.
	 */
	public static function capacity( $opp_id ) {
		return max( 0, (int) get_post_meta( $opp_id, '_lnkup_spots_total', true ) );
	}

	/**
	 * Summary for organizer inventory views.
	 *
	 * @param int $opp_id Opportunity ID.
	 * @return array{capacity:int,held:int,paid:int,available:int|null,waitlisted:int}
	 */
	public static function summary( $opp_id ) {
		$capacity = self::capacity( $opp_id );
		$held     = self::holds( $opp_id );
		return array(
			'capacity'   => $capacity,
			'held'       => $held,
			'paid'       => self::count_status( $opp_id, array( 'paid', 'completed' ) ),
			'available'  => $capacity ? max( 0, $capacity - $held ) : null,
			'waitlisted' => self::count_status( $opp_id, array( 'waitlisted' ) ),
		);
	}

	/**
	 * Recompute and store `_lnkup_spots_remaining` (no-op when no capacity is configured).
	 *
	 * @param int $opp_id Opportunity ID.
	 */
	public static function recompute( $opp_id ) {
		$opp_id   = absint( $opp_id );
		$capacity = self::capacity( $opp_id );
		if ( ! $opp_id || ! $capacity ) {
			return null;
		}
		$remaining = max( 0, $capacity - self::holds( $opp_id ) );
		if ( (int) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) !== $remaining || '' === (string) get_post_meta( $opp_id, '_lnkup_spots_remaining', true ) ) {
			update_post_meta( $opp_id, '_lnkup_spots_remaining', $remaining );
			if ( class_exists( 'LNKup_Marketplace_Index' ) ) {
				LNKup_Marketplace_Index::sync_opportunity( $opp_id );
			}
		}
		return $remaining;
	}

	/**
	 * Whether one more application can hold a spot (excluding the one being approved).
	 *
	 * @param int $opp_id Opportunity ID.
	 * @param int $app_id Application being approved.
	 */
	public static function has_room( $opp_id, $app_id = 0 ) {
		$capacity = self::capacity( $opp_id );
		return ! $capacity || self::holds( $opp_id, $app_id ) < $capacity;
	}

	/**
	 * Run a callback while holding the opportunity's inventory lock.
	 *
	 * @param int      $opp_id   Opportunity ID.
	 * @param callable $callback Work to do.
	 * @return mixed|WP_Error
	 */
	public static function with_lock( $opp_id, $callback ) {
		global $wpdb;
		$lock = 'lnkup_inv_' . absint( $opp_id );
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return new WP_Error( 'busy', __( 'Another change to this vendor slot is in progress. Try again.', 'lnkup-marketplace' ), array( 'status' => 429 ) );
		}
		try {
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Recompute after any application status change; flag waitlist promotion when a spot opens.
	 *
	 * @param int    $app_id Application ID.
	 * @param string $prev   Previous status.
	 * @param string $next   New status.
	 */
	public static function on_status_changed( $app_id, $prev, $next ) {
		$opp_id = (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true );
		if ( ! $opp_id ) {
			return;
		}
		$was_hold  = in_array( (string) $prev, self::HOLD_STATUSES, true );
		$is_hold   = in_array( (string) $next, self::HOLD_STATUSES, true );
		$remaining = self::recompute( $opp_id );
		if ( $was_hold && ! $is_hold && null !== $remaining && $remaining > 0 && self::count_status( $opp_id, array( 'waitlisted' ) ) > 0 ) {
			update_post_meta( $opp_id, '_lnkup_waitlist_spot_open', current_time( 'mysql' ) );
			do_action( 'lnkup_waitlist_spot_available', $opp_id, $remaining );
		} elseif ( $is_hold && 0 === $remaining ) {
			delete_post_meta( $opp_id, '_lnkup_waitlist_spot_open' );
		}
	}

	/**
	 * New submissions do not hold a spot, but keep the cache honest for legacy data.
	 *
	 * @param int $app_id Application ID.
	 */
	public static function on_application_submitted( $app_id ) {
		self::recompute( (int) get_post_meta( $app_id, '_lnkup_opportunity_id', true ) );
	}

	/**
	 * One-time recompute for existing opportunities (logged; safe to re-run).
	 */
	public static function maybe_backfill() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		$ids     = get_posts(
			array(
				'post_type'      => 'lnkup_opportunity',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$changed = 0;
		foreach ( $ids as $opp_id ) {
			$before = (string) get_post_meta( $opp_id, '_lnkup_spots_remaining', true );
			$after  = self::recompute( $opp_id );
			if ( null !== $after && (string) $after !== $before ) {
				++$changed;
			}
		}
		update_option( self::BACKFILL_OPTION, array( 'at' => current_time( 'mysql' ), 'opportunities' => count( $ids ), 'changed' => $changed ), false );
		error_log( sprintf( 'LNKup inventory backfill: %d opportunities, %d recomputed.', count( $ids ), $changed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Count applications on an opportunity in the given statuses.
	 *
	 * @param int      $opp_id   Opportunity ID.
	 * @param string[] $statuses Statuses.
	 */
	private static function count_status( $opp_id, $statuses ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.ID AND o.meta_key = '_lnkup_opportunity_id' AND o.meta_value = %s
				INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_lnkup_application_status' AND s.meta_value IN ({$placeholders})
				WHERE p.post_type = 'lnkup_application' AND p.post_status NOT IN ('trash','auto-draft','lnkup_app_draft')",
				array_merge( array( (string) absint( $opp_id ) ), $statuses )
			)
		);
	}
}
