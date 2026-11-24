<?php
/**
 * Engagement state: what a user has actually done on the marketplace, read from
 * canonical records. Journeys, next-step cards and dashboard modules all read
 * this so a completed action disappears everywhere at once.
 *
 * Unknown stays unknown: nothing here infers availability, interest or travel.
 *
 * @package LNKup_Communications
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

class LNKup_Comms_Engagement {

	const CACHE_PREFIX = 'lnkup_comms_ns_';
	const CACHE_TTL    = 300;

	public static function init(): void {
		add_action( 'lnkup_application_status_changed', array( __CLASS__, 'flush_for_post' ), 30 );
		add_action( 'lnkup_application_submitted', array( __CLASS__, 'flush_for_post' ), 30 );
		add_action( 'lnkup_vendor_invite_created', array( __CLASS__, 'flush_for_post' ), 30 );
		add_action( 'lnkup_vendor_invite_status_changed', array( __CLASS__, 'flush_for_post' ), 30 );
		add_action( 'lnkup_payment_status_changed', array( __CLASS__, 'flush_for_post' ), 30 );
		add_action( 'lnkup_document_status_changed', array( __CLASS__, 'flush_for_post' ), 30 );
		foreach ( array( 'lnkup_application', 'lnkup_event', 'lnkup_opportunity', 'lnkup_vendor_profile', 'lnkup_payment' ) as $type ) {
			add_action( 'save_post_' . $type, array( __CLASS__, 'flush_for_post' ), 30 );
		}
		// Records are often inserted before their owner meta exists, so save_post cannot see the owner yet.
		add_action( 'added_post_meta', array( __CLASS__, 'on_owner_meta' ), 30, 3 );
	}

	/**
	 * @param int    $meta_id   Meta row.
	 * @param int    $post_id   Post.
	 * @param string $meta_key  Key.
	 */
	public static function on_owner_meta( $meta_id, $post_id, $meta_key ): void {
		unset( $meta_id );
		if ( in_array( $meta_key, array( '_lnkup_vendor_user_id', '_lnkup_organizer_user_id', '_lnkup_draft_vendor_user_id', '_lnkup_owner_user_id' ), true ) ) {
			self::flush_for_post( (int) $post_id );
		}
	}

	/**
	 * Ranked next actions for a user and role (cached briefly; flushed on every relevant write).
	 *
	 * @return array{steps:array,upcoming:array}
	 */
	public static function next_steps( int $user_id, string $role ): array {
		$key    = self::CACHE_PREFIX . ( 'organizer' === $role ? 'o' : 'v' ) . $user_id;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = 'organizer' === $role ? LNKup_Comms_Next_Steps::organizer( $user_id ) : LNKup_Comms_Next_Steps::vendor( $user_id );
		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}

	public static function flush_user( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_transient( self::CACHE_PREFIX . 'v' . $user_id );
			delete_transient( self::CACHE_PREFIX . 'o' . $user_id );
		}
	}

	/**
	 * Flush everyone a marketplace record belongs to (vendor, organizer, draft owner, profile owner).
	 *
	 * @param int|string $post_id Post ID (first argument of every hook above).
	 */
	public static function flush_for_post( $post_id ): void {
		$post_id = absint( $post_id );
		foreach ( array( '_lnkup_vendor_user_id', '_lnkup_organizer_user_id', '_lnkup_draft_vendor_user_id', '_lnkup_owner_user_id' ) as $meta_key ) {
			self::flush_user( (int) get_post_meta( $post_id, $meta_key, true ) );
		}
	}

	/**
	 * Latest meaningful vendor activity (UTC timestamp): registration, applications,
	 * application drafts, marketplace activity (searches, opportunity views, saves)
	 * and clicks on LNKup messages.
	 */
	public static function vendor_last_activity( int $vendor_id ): int {
		global $wpdb;
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return 0;
		}
		$latest = (int) strtotime( $user->user_registered . ' UTC' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$app = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key IN ('_lnkup_vendor_user_id','_lnkup_draft_vendor_user_id') AND v.meta_value = %s
				WHERE p.post_type = 'lnkup_application' AND p.post_status <> 'trash'",
				(string) $vendor_id
			)
		);
		$candidates = array( $app );
		if ( class_exists( 'LNKup_Marketplace_Analytics' ) ) {
			$local        = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . LNKup_Marketplace_Analytics::table_name() . ' WHERE user_id = %d', $vendor_id ) );
			$candidates[] = '' !== $local ? get_gmt_from_date( $local ) : '';
		}
		$candidates[] = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . LNKup_Comms_Schema::table( 'clicks' ) . ' WHERE user_id = %d', $vendor_id ) );
		// phpcs:enable
		foreach ( $candidates as $value ) {
			if ( '' !== (string) $value ) {
				$latest = max( $latest, (int) strtotime( $value . ' UTC' ) );
			}
		}
		return $latest;
	}

	/**
	 * At least one open opportunity the match engine recommends to this vendor right now.
	 */
	public static function vendor_has_recommendations( int $vendor_id ): bool {
		return array() !== self::vendor_recommendations( $vendor_id, 1 );
	}

	/**
	 * Live, revalidated recommendations (empty when the match engine is off).
	 *
	 * @return array<int, array>
	 */
	public static function vendor_recommendations( int $vendor_id, int $limit = 5 ): array {
		if ( ! LNKup_Comms_Matching::core_engine_ready() ) {
			return array();
		}
		$recs = LNKup_Match_Service::vendor_recommendations( $vendor_id, array( 'section' => 'recommended', 'per_page' => max( 1, $limit ) ) );
		return is_wp_error( $recs ) ? array() : array_values( (array) ( $recs['items'] ?? array() ) );
	}

	/**
	 * Short "4 Food, 2 Clothing" line of categories that still have open spots.
	 */
	public static function category_summary( int $event_id ): string {
		if ( ! class_exists( 'LNKup_Event_Fulfillment' ) ) {
			return '';
		}
		$parts = array();
		foreach ( LNKup_Event_Fulfillment::for_event( $event_id, true )['categories'] as $cat ) {
			if ( ! $cat['open'] || '_any' === $cat['key'] ) {
				continue;
			}
			if ( $cat['unlimited'] ) {
				$parts[] = $cat['label'];
			} elseif ( $cat['remaining'] > 0 ) {
				/* translators: 1: open spots, 2: category */
				$parts[] = sprintf( __( '%1$d %2$s', 'lnkup-communications' ), $cat['remaining'], $cat['label'] );
			}
		}
		return implode( ', ', array_slice( $parts, 0, 4 ) );
	}
}
